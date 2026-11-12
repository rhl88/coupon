<?php

namespace App\Apps\CmsproCoupon\Tests\Feature;

use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Apps\CmsproCoupon\Models\VerifyLog;
use App\Apps\CmsproCoupon\Services\VerifyService;
use App\Apps\CmsproCoupon\Tests\CouponTestCase;

/**
 * 核销服务功能测试
 *
 * 覆盖：可用券查询、试算、核销、重复核销拦截、撤销核销（券退回/过期退回）、
 * 门槛不满足、他人券拦截、多券开关、优惠总额封顶。
 */
class VerifyServiceTest extends CouponTestCase
{
    protected VerifyService $verifyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifyService = $this->app->make(VerifyService::class);
    }

    public function test_可用券查询按优惠额降序返回(): void
    {
        $user = $this->createUser();
        $small = $this->createActivity(['code' => 'small_' . uniqid(), 'threshold_amount' => 100, 'discount_amount' => 10, 'per_user_limit' => 0]);
        $big = $this->createActivity(['code' => 'big_' . uniqid(), 'threshold_amount' => 100, 'discount_amount' => 50, 'per_user_limit' => 0]);

        $this->createUserCoupon($user->id, $small->id);
        $this->createUserCoupon($user->id, $big->id);

        $available = $this->verifyService->availableCoupons($user->id, 200.0);

        $this->assertCount(2, $available);
        // 第一张为最优券（优惠 50）
        $this->assertEquals(50.0, $available[0]['discount_amount']);
        $this->assertEquals(150.0, $available[0]['pay_amount']);
    }

    public function test_未达门槛的券不出现在可用列表(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity(['threshold_amount' => 100, 'discount_amount' => 20]);
        $this->createUserCoupon($user->id, $activity->id);

        $available = $this->verifyService->availableCoupons($user->id, 50.0);

        $this->assertCount(0, $available);
    }

    public function test_试算返回优惠额且不改变券状态(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity(['threshold_amount' => 100, 'discount_amount' => 20]);
        $coupon = $this->createUserCoupon($user->id, $activity->id);

        $result = $this->verifyService->preview($user->id, 150.0, [$coupon->id]);

        $this->assertEquals(0, $result['code']);
        $this->assertEquals(20.0, $result['data']['total_discount']);
        $this->assertEquals(130.0, $result['data']['pay_amount']);
        // 券状态未变化
        $this->assertEquals(UserCoupon::STATUS_UNUSED, (int) $coupon->fresh()->status);
    }

    public function test_核销成功后券置为已使用并写流水(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity(['threshold_amount' => 100, 'discount_amount' => 20]);
        $coupon = $this->createUserCoupon($user->id, $activity->id);

        $result = $this->verifyService->verify(
            $user->id,
            [$coupon->id],
            'ORDER20261111001',
            150.0,
            ['caller_app_id' => 'cmspro.pointshop', 'operator_type' => VerifyLog::OPERATOR_USER, 'operator_id' => $user->id]
        );

        $this->assertEquals(0, $result['code']);
        $this->assertEquals(20.0, $result['data']['total_discount']);
        $this->assertEquals(130.0, $result['data']['pay_amount']);

        $fresh = $coupon->fresh();
        $this->assertEquals(UserCoupon::STATUS_USED, (int) $fresh->status);
        $this->assertEquals('ORDER20261111001', $fresh->order_no);
        $this->assertNotNull($fresh->use_time);

        $this->assertDatabaseHas('app_cmspro_coupon_verify_logs', [
            'user_coupon_id' => $coupon->id,
            'order_no' => 'ORDER20261111001',
            'discount_amount' => 20.00,
            'caller_app_id' => 'cmspro.pointshop',
            'status' => VerifyLog::STATUS_VERIFIED,
        ]);
    }

    public function test_已使用的券重复核销被拒绝(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($user->id, $activity->id);

        $this->verifyService->verify($user->id, [$coupon->id], 'ORDER_A', 150.0);

        // 第二单再用同一张券
        $result = $this->verifyService->verify($user->id, [$coupon->id], 'ORDER_B', 150.0);

        $this->assertNotEquals(0, $result['code']);
        $this->assertStringContainsString('不可用', $result['message']);
    }

    public function test_同一订单默认禁止重复核销(): void
    {
        $user = $this->createUser();
        $a1 = $this->createActivity(['code' => 'a1_' . uniqid(), 'per_user_limit' => 0]);
        $a2 = $this->createActivity(['code' => 'a2_' . uniqid(), 'per_user_limit' => 0]);
        $c1 = $this->createUserCoupon($user->id, $a1->id);
        $c2 = $this->createUserCoupon($user->id, $a2->id);

        $first = $this->verifyService->verify($user->id, [$c1->id], 'SAME_ORDER', 200.0);
        $this->assertEquals(0, $first['code']);

        // 默认 multi_use_enabled=0：同订单二次核销被拒
        $second = $this->verifyService->verify($user->id, [$c2->id], 'SAME_ORDER', 200.0);
        $this->assertNotEquals(0, $second['code']);
        $this->assertStringContainsString('重复核销', $second['message']);
    }

    public function test_默认不允许多券同时核销(): void
    {
        $user = $this->createUser();
        $a1 = $this->createActivity(['code' => 'm1_' . uniqid(), 'per_user_limit' => 0]);
        $a2 = $this->createActivity(['code' => 'm2_' . uniqid(), 'per_user_limit' => 0]);
        $c1 = $this->createUserCoupon($user->id, $a1->id);
        $c2 = $this->createUserCoupon($user->id, $a2->id);

        $result = $this->verifyService->verify($user->id, [$c1->id, $c2->id], 'ORDER_MULTI', 300.0);

        $this->assertNotEquals(0, $result['code']);
        $this->assertStringContainsString('仅可使用一张', $result['message']);
    }

    public function test_开启多券后优惠总额不超过订单金额(): void
    {
        config(['apps.cmspro.coupon.multi_use_enabled' => '1']);

        $user = $this->createUser();
        $a1 = $this->createActivity(['code' => 'x1_' . uniqid(), 'threshold_amount' => 0, 'discount_amount' => 80, 'coupon_type' => 3, 'per_user_limit' => 0]);
        $a2 = $this->createActivity(['code' => 'x2_' . uniqid(), 'threshold_amount' => 0, 'discount_amount' => 80, 'coupon_type' => 3, 'per_user_limit' => 0]);
        $c1 = $this->createUserCoupon($user->id, $a1->id);
        $c2 = $this->createUserCoupon($user->id, $a2->id);

        // 订单 100 元，两张 80 元无门槛券：总优惠封顶 100
        $result = $this->verifyService->verify($user->id, [$c1->id, $c2->id], 'ORDER_CAP', 100.0);

        $this->assertEquals(0, $result['code']);
        $this->assertEquals(100.0, $result['data']['total_discount']);
        $this->assertEquals(0.0, $result['data']['pay_amount']);
        // 第二张券优惠被压到剩余额度（20），两张都记流水
        $this->assertDatabaseCount('app_cmspro_coupon_verify_logs', 2);
    }

    public function test_他人优惠券核销被拒绝(): void
    {
        $owner = $this->createUser();
        $attacker = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($owner->id, $activity->id);

        $result = $this->verifyService->verify($attacker->id, [$coupon->id], 'ORDER_HACK', 150.0);

        $this->assertNotEquals(0, $result['code']);
        // 券未被核销
        $this->assertEquals(UserCoupon::STATUS_UNUSED, (int) $coupon->fresh()->status);
    }

    public function test_已过期券核销被拒绝(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($user->id, $activity->id, [
            'valid_end_time' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $result = $this->verifyService->verify($user->id, [$coupon->id], 'ORDER_EXPIRED', 150.0);

        $this->assertNotEquals(0, $result['code']);
    }

    public function test_订单号或金额非法时核销被拒绝(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($user->id, $activity->id);

        $emptyOrderNo = $this->verifyService->verify($user->id, [$coupon->id], '  ', 150.0);
        $this->assertNotEquals(0, $emptyOrderNo['code']);

        $zeroAmount = $this->verifyService->verify($user->id, [$coupon->id], 'ORDER_X', 0.0);
        $this->assertNotEquals(0, $zeroAmount['code']);

        $emptyCoupons = $this->verifyService->verify($user->id, [], 'ORDER_Y', 150.0);
        $this->assertNotEquals(0, $emptyCoupons['code']);
    }

    public function test_撤销核销后券退回未使用状态(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($user->id, $activity->id);

        $verify = $this->verifyService->verify($user->id, [$coupon->id], 'ORDER_R1', 150.0);
        $this->assertEquals(0, $verify['code']);

        $log = VerifyLog::where('order_no', 'ORDER_R1')->first();
        $revoke = $this->verifyService->revoke($log->id, '用户退款', null);

        $this->assertEquals(0, $revoke['code']);
        $fresh = $coupon->fresh();
        $this->assertEquals(UserCoupon::STATUS_UNUSED, (int) $fresh->status);
        $this->assertNull($fresh->order_no);
        $this->assertEquals(VerifyLog::STATUS_REVOKED, (int) $log->fresh()->status);
        $this->assertEquals('用户退款', $log->fresh()->cancel_reason);
    }

    public function test_撤销核销时已过有效期的券置为已过期(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($user->id, $activity->id, [
            'valid_end_time' => now()->addHour()->format('Y-m-d H:i:s'),
        ]);

        $this->verifyService->verify($user->id, [$coupon->id], 'ORDER_R2', 150.0);

        // 模拟券在核销后到期
        $coupon->forceFill(['valid_end_time' => now()->subMinute()->format('Y-m-d H:i:s')])->save();

        $log = VerifyLog::where('order_no', 'ORDER_R2')->first();
        $revoke = $this->verifyService->revoke($log->id, '', null);

        $this->assertEquals(0, $revoke['code']);
        $this->assertEquals(UserCoupon::STATUS_EXPIRED, (int) $coupon->fresh()->status);
    }

    public function test_关闭撤销开关后不可撤销(): void
    {
        config(['apps.cmspro.coupon.revoke_enabled' => '0']);

        $user = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($user->id, $activity->id);

        $this->verifyService->verify($user->id, [$coupon->id], 'ORDER_R3', 150.0);
        $log = VerifyLog::where('order_no', 'ORDER_R3')->first();

        $result = $this->verifyService->revoke($log->id, '', null);

        $this->assertNotEquals(0, $result['code']);
        $this->assertStringContainsString('关闭', $result['message']);
    }

    public function test_重复撤销同一记录被拒绝(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($user->id, $activity->id);

        $this->verifyService->verify($user->id, [$coupon->id], 'ORDER_R4', 150.0);
        $log = VerifyLog::where('order_no', 'ORDER_R4')->first();

        $this->assertEquals(0, $this->verifyService->revoke($log->id, '', null)['code']);

        $second = $this->verifyService->revoke($log->id, '', null);
        $this->assertNotEquals(0, $second['code']);
        $this->assertStringContainsString('已撤销', $second['message']);
    }

    public function test_按券号核销成功(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($user->id, $activity->id);

        $result = $this->verifyService->verifyByCouponNo($user->id, $coupon->coupon_no, 'ORDER_BY_NO', 150.0);

        $this->assertEquals(0, $result['code']);
        $this->assertEquals(UserCoupon::STATUS_USED, (int) $coupon->fresh()->status);
    }
}
