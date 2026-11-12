<?php

namespace App\Apps\CmsproCoupon\Tests\Feature;

use App\Apps\CmsproCoupon\Models\CouponActivity;
use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Apps\CmsproCoupon\Services\IssueService;
use App\Apps\CmsproCoupon\Tests\CouponTestCase;

/**
 * 发放服务功能测试
 *
 * 覆盖：用户领取、库存耗尽、每人限领、下架活动、非领取时间窗、
 * 后台定向发放（批次统计）、兑换码兑换（一码一用、已用码、作废码）。
 */
class IssueServiceTest extends CouponTestCase
{
    protected IssueService $issueService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->issueService = $this->app->make(IssueService::class);
    }

    public function test_用户成功领取优惠券(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity();

        $result = $this->issueService->receive($user->id, $activity->id);

        $this->assertEquals(0, $result['code']);
        $this->assertDatabaseHas('app_cmspro_coupon_user_coupons', [
            'activity_id' => $activity->id,
            'user_id' => $user->id,
            'status' => UserCoupon::STATUS_UNUSED,
        ]);
        // 活动已发放数 +1
        $this->assertEquals(1, (int) $activity->fresh()->issued_count);
    }

    public function test_领取库存耗尽时失败(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity([
            'total_stock' => 1,
            'issued_count' => 1,
            'per_user_limit' => 0,
        ]);

        $result = $this->issueService->receive($user->id, $activity->id);

        $this->assertNotEquals(0, $result['code']);
        $this->assertStringContainsString('领完', $result['message']);
    }

    public function test_超出每人限领时失败(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity(['per_user_limit' => 1]);

        // 第一次成功
        $first = $this->issueService->receive($user->id, $activity->id);
        $this->assertEquals(0, $first['code']);

        // 第二次超出限领
        $second = $this->issueService->receive($user->id, $activity->id);
        $this->assertNotEquals(0, $second['code']);
        $this->assertStringContainsString('限领', $second['message']);
    }

    public function test_下架活动无法领取(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity(['status' => 0]);

        $result = $this->issueService->receive($user->id, $activity->id);

        $this->assertNotEquals(0, $result['code']);
        $this->assertStringContainsString('下架', $result['message']);
    }

    public function test_不在领取时间窗时失败(): void
    {
        $user = $this->createUser();
        // 领取窗口已结束
        $activity = $this->createActivity([
            'receive_start_time' => now()->subDays(10)->format('Y-m-d H:i:s'),
            'receive_end_time' => now()->subDays(5)->format('Y-m-d H:i:s'),
        ]);

        $result = $this->issueService->receive($user->id, $activity->id);

        $this->assertNotEquals(0, $result['code']);
        $this->assertStringContainsString('领取时间', $result['message']);
    }

    public function test_不支持主动领取的活动被拒绝(): void
    {
        $user = $this->createUser();
        // 仅支持后台发放
        $activity = $this->createActivity(['receive_type' => CouponActivity::RECEIVE_ADMIN]);

        $result = $this->issueService->receive($user->id, $activity->id);

        $this->assertNotEquals(0, $result['code']);
        $this->assertStringContainsString('不支持主动领取', $result['message']);
    }

    public function test_相对有效期券按领取时刻计算失效时间(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity([
            'valid_type' => CouponActivity::VALID_RELATIVE,
            'valid_days' => 7,
            'valid_start_time' => null,
            'valid_end_time' => null,
        ]);

        $result = $this->issueService->receive($user->id, $activity->id);
        $this->assertEquals(0, $result['code']);

        $coupon = UserCoupon::where('user_id', $user->id)->first();
        $this->assertNotNull($coupon->valid_end_time);
        // 失效时间约为 7 天后当天 23:59:59
        $expectedDay = now()->addDays(7)->format('Y-m-d');
        $this->assertStringStartsWith($expectedDay, $coupon->valid_end_time->format('Y-m-d'));
        $this->assertEquals('23:59:59', $coupon->valid_end_time->format('H:i:s'));
    }

    public function test_后台定向发放给指定用户(): void
    {
        $admin = $this->createUser();
        $u1 = $this->createUser();
        $u2 = $this->createUser();
        $activity = $this->createActivity(['per_user_limit' => 0]);

        $result = $this->issueService->issue($activity->id, [$u1->id, $u2->id], 1, $admin->id, '活动赠送');

        $this->assertEquals(0, $result['code']);
        $this->assertEquals(2, $result['data']['success_count']);
        $this->assertEquals(0, $result['data']['fail_count']);
        $this->assertDatabaseCount('app_cmspro_coupon_issue_batches', 1);
    }

    public function test_后台发放时超出限领的用户计入失败(): void
    {
        $admin = $this->createUser();
        $u1 = $this->createUser();
        $activity = $this->createActivity(['per_user_limit' => 1]);

        // u1 先自行领一张
        $this->issueService->receive($u1->id, $activity->id);

        // 后台再发，u1 已达上限 → 失败
        $result = $this->issueService->issue($activity->id, [$u1->id], 1, $admin->id, '');

        $this->assertEquals(0, $result['code']);
        $this->assertEquals(0, $result['data']['success_count']);
        $this->assertEquals(1, $result['data']['fail_count']);
    }

    public function test_兑换码兑换成功(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity(['per_user_limit' => 0]);

        // 生成一个兑换码
        $code = \App\Apps\CmsproCoupon\Models\RedeemCode::create([
            'code' => 'TESTCODE' . random_int(1000, 9999),
            'activity_id' => $activity->id,
            'status' => \App\Apps\CmsproCoupon\Models\RedeemCode::STATUS_UNUSED,
        ]);

        $result = $this->issueService->redeem($user->id, $code->code);

        $this->assertEquals(0, $result['code']);
        $this->assertEquals(
            \App\Apps\CmsproCoupon\Models\RedeemCode::STATUS_USED,
            (int) $code->fresh()->status
        );
    }

    public function test_兑换码不存在时失败(): void
    {
        $user = $this->createUser();

        $result = $this->issueService->redeem($user->id, 'NOTEXIST999');

        $this->assertNotEquals(0, $result['code']);
        $this->assertStringContainsString('不存在', $result['message']);
    }

    public function test_已使用兑换码不可重复兑换(): void
    {
        $u1 = $this->createUser();
        $u2 = $this->createUser();
        $activity = $this->createActivity(['per_user_limit' => 0]);

        $code = \App\Apps\CmsproCoupon\Models\RedeemCode::create([
            'code' => 'USED' . random_int(1000, 9999),
            'activity_id' => $activity->id,
            'status' => \App\Apps\CmsproCoupon\Models\RedeemCode::STATUS_UNUSED,
        ]);

        // u1 先兑换
        $this->issueService->redeem($u1->id, $code->code);

        // u2 再兑换同一码 → 失败
        $result = $this->issueService->redeem($u2->id, $code->code);
        $this->assertNotEquals(0, $result['code']);
        $this->assertStringContainsString('已被使用', $result['message']);
    }

    public function test_作废兑换码不可兑换(): void
    {
        $user = $this->createUser();
        $activity = $this->createActivity();

        $code = \App\Apps\CmsproCoupon\Models\RedeemCode::create([
            'code' => 'VOID' . random_int(1000, 9999),
            'activity_id' => $activity->id,
            'status' => \App\Apps\CmsproCoupon\Models\RedeemCode::STATUS_VOID,
        ]);

        $result = $this->issueService->redeem($user->id, $code->code);

        $this->assertNotEquals(0, $result['code']);
        $this->assertStringContainsString('作废', $result['message']);
    }
}
