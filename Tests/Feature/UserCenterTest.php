<?php

namespace App\Apps\CmsproCoupon\Tests\Feature;

use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Apps\CmsproCoupon\Tests\CouponTestCase;

/**
 * 用户端领券中心功能测试
 *
 * 覆盖：可领活动列表、未登录 401 拦截、领取接口、兑换码兑换接口、数据隔离。
 */
class UserCenterTest extends CouponTestCase
{
    private const API = '/api/user/cmspro/coupon';

    public function test_未登录访问用户端接口被拦截(): void
    {
        $activity = $this->createActivity();

        // 未登录：auth:web 中间件应拦截（401 或跳转）
        $response = $this->postJson(self::API . '/receive/' . $activity->id);

        $this->assertNotEquals(200, $response->status());
    }

    public function test_可领活动列表返回上架活动(): void
    {
        $user = $this->actingUser();
        $onShelf = $this->createActivity(['title' => '上架券', 'status' => 1]);
        $offShelf = $this->createActivity(['title' => '下架券', 'status' => 0]);

        $response = $this->actingAs($user, 'web')->getJson(self::API . '/activities');

        $response->assertStatus(200)->assertJsonPath('code', 0);

        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('上架券', $titles);
        $this->assertNotContains('下架券', $titles);
    }

    public function test_用户领取接口成功(): void
    {
        $user = $this->actingUser();
        $activity = $this->createActivity();

        $response = $this->actingAs($user, 'web')->postJson(self::API . '/receive/' . $activity->id);

        $response->assertStatus(200)->assertJsonPath('code', 0);
        $this->assertDatabaseHas('app_cmspro_coupon_user_coupons', [
            'user_id' => $user->id,
            'activity_id' => $activity->id,
        ]);
    }

    public function test_领取不存在的活动返回错误码(): void
    {
        $user = $this->actingUser();

        $response = $this->actingAs($user, 'web')->postJson(self::API . '/receive/999999');

        $response->assertStatus(200)->assertJsonPath('code', 40401);
    }

    public function test_兑换码兑换接口成功(): void
    {
        $user = $this->actingUser();
        $activity = $this->createActivity(['per_user_limit' => 0]);

        $code = \App\Apps\CmsproCoupon\Models\RedeemCode::create([
            'code' => 'API' . random_int(100000, 999999),
            'activity_id' => $activity->id,
            'status' => \App\Apps\CmsproCoupon\Models\RedeemCode::STATUS_UNUSED,
        ]);

        $response = $this->actingAs($user, 'web')->postJson(self::API . '/redeem', ['code' => $code->code]);

        $response->assertStatus(200)->assertJsonPath('code', 0);
    }

    public function test_兑换码为空时校验失败(): void
    {
        $user = $this->actingUser();

        $response = $this->actingAs($user, 'web')->postJson(self::API . '/redeem', ['code' => '']);

        $response->assertStatus(422);
    }

    public function test_我的优惠券列表仅返回本人数据(): void
    {
        $me = $this->actingUser();
        $other = $this->createUser();

        $activity = $this->createActivity(['per_user_limit' => 0]);
        $this->createUserCoupon($me->id, $activity->id, ['title' => '我的券']);
        $this->createUserCoupon($other->id, $activity->id, ['title' => '别人的券']);

        $response = $this->actingAs($me, 'web')->getJson(self::API . '/my-coupons?status=usable');

        $response->assertStatus(200)->assertJsonPath('code', 0);
        $titles = array_column($response->json('data.items'), 'title');
        $this->assertContains('我的券', $titles);
        $this->assertNotContains('别人的券', $titles);
    }

    public function test_我的优惠券统计接口(): void
    {
        $user = $this->actingUser();
        $activity = $this->createActivity(['per_user_limit' => 0]);

        $this->createUserCoupon($user->id, $activity->id);
        $this->createUserCoupon($user->id, $activity->id, [
            'status' => UserCoupon::STATUS_USED,
            'use_time' => now()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->actingAs($user, 'web')->getJson(self::API . '/my-coupons/stats');

        $response->assertStatus(200)->assertJsonPath('code', 0)
            ->assertJsonPath('data.usable', 1)
            ->assertJsonPath('data.used', 1);
    }

    public function test_查看他人券详情返回不存在(): void
    {
        $me = $this->actingUser();
        $other = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($other->id, $activity->id);

        $response = $this->actingAs($me, 'web')->getJson(self::API . '/my-coupons/' . $coupon->id);

        $response->assertStatus(200)->assertJsonPath('code', 40401);
    }

    /**
     * 常见问题第1章回归：已登录 + 空载荷 + 普通 post()（不带 JSON Accept 头）
     *
     * 未挂载强制 JSON 中间件时，validate() 失败会被框架转成 302 重定向；
     * 挂载 ForceJsonResponse 后应返回 422 JSON，且响应体可 json_decode。
     */
    public function test_普通post空载荷返回422JSON而非302重定向(): void
    {
        $user = $this->actingUser();

        // 普通 post()：Accept 头为默认的 text/html（非 application/json）
        $response = $this->actingAs($user, 'web')->post(self::API . '/redeem', ['code' => '']);

        // 不得是 3xx 重定向
        $this->assertFalse($response->isRedirect(), '校验失败不应返回 302 重定向');
        $response->assertStatus(422);

        // 响应体必须是可解析的 JSON，且符合系统统一 ApiResponse 结构
        // （框架 bootstrap/app.php 将 ValidationException 渲染为 code=40201 + data=errors）
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded, '响应体应为合法 JSON');
        $this->assertEquals(40201, $decoded['code'] ?? null);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('code', $decoded['data']);
    }

    /**
     * 常见问题第1章回归：领取接口同样保证普通 post 返回 JSON 而非 302
     */
    public function test_普通post领取接口不返回302重定向(): void
    {
        $user = $this->actingUser();
        $activity = $this->createActivity();

        $response = $this->actingAs($user, 'web')->post(self::API . '/receive/' . $activity->id, [], [
            'Accept' => 'text/html',
        ]);

        // 领取成功或业务失败都应是 200 JSON，绝不能是 302
        $this->assertFalse($response->isRedirect(), '领取接口不应返回 302 重定向');
        $response->assertStatus(200);
        $this->assertIsArray(json_decode($response->getContent(), true));
    }
}
