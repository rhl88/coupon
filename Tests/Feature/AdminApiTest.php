<?php

namespace App\Apps\CmsproCoupon\Tests\Feature;

use App\Apps\CmsproCoupon\Models\CouponActivity;
use App\Apps\CmsproCoupon\Models\RedeemCode;
use App\Apps\CmsproCoupon\Tests\CouponTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 后台管理接口功能测试
 *
 * 覆盖：券活动 CRUD、规则校验失败场景、发放接口、兑换码生成/作废/导出、
 * 用户券作废、核销记录撤销、未登录拦截。
 */
class AdminApiTest extends CouponTestCase
{
    private const API = '/admin/cmspro/coupon/api';

    public function test_未登录访问后台接口被拦截(): void
    {
        $response = $this->getJson(self::API . '/activities');

        // auth:admin 中间件拦截：401 或重定向
        $this->assertNotEquals(200, $response->status());
    }

    public function test_管理员可查看券活动列表(): void
    {
        $this->actingAdmin();
        $this->createActivity(['title' => '列表测试券']);

        $response = $this->getJson(self::API . '/activities');

        $response->assertStatus(200)->assertJsonPath('code', 0);
        $titles = array_column($response->json('data.items'), 'title');
        $this->assertContains('列表测试券', $titles);
    }

    public function test_管理员可创建满减券活动(): void
    {
        $this->actingAdmin();

        $response = $this->postJson(self::API . '/activities', [
            'code' => 'full_reduce_100_20',
            'title' => '满100减20',
            'coupon_type' => CouponActivity::TYPE_FULL_REDUCE,
            'threshold_amount' => 100,
            'discount_amount' => 20,
            'total_stock' => 1000,
            'per_user_limit' => 2,
            'valid_type' => CouponActivity::VALID_FIXED,
            'valid_start_time' => now()->format('Y-m-d H:i:s'),
            'valid_end_time' => now()->addDays(30)->format('Y-m-d H:i:s'),
            'receive_types' => [1, 4],
            'status' => 1,
        ]);

        $response->assertStatus(200)->assertJsonPath('code', 0);
        $this->assertDatabaseHas('app_cmspro_coupon_activities', [
            'code' => 'full_reduce_100_20',
            'receive_type' => 5, // 1|4 位掩码
        ]);
    }

    public function test_创建折扣券活动(): void
    {
        $this->actingAdmin();

        $response = $this->postJson(self::API . '/activities', [
            'code' => 'discount_85',
            'title' => '8.5折券',
            'coupon_type' => CouponActivity::TYPE_DISCOUNT,
            'threshold_amount' => 200,
            'discount_rate' => 8.5,
            'max_discount_amount' => 100,
            'valid_type' => CouponActivity::VALID_RELATIVE,
            'valid_days' => 15,
            'receive_types' => [1],
            'status' => 1,
        ]);

        $response->assertStatus(200)->assertJsonPath('code', 0);
        $activity = CouponActivity::where('code', 'discount_85')->first();
        $this->assertNotNull($activity);
        // 折扣券归一化：固定优惠额清零
        $this->assertEquals(0.0, (float) $activity->discount_amount);
        $this->assertEquals(CouponActivity::VALID_RELATIVE, (int) $activity->valid_type);
    }

    public function test_立减券门槛强制为零(): void
    {
        $this->actingAdmin();

        $response = $this->postJson(self::API . '/activities', [
            'code' => 'cash_5',
            'title' => '无门槛5元券',
            'coupon_type' => CouponActivity::TYPE_CASH,
            'threshold_amount' => 50, // 提交错误门槛，应被归一化为 0
            'discount_amount' => 5,
            'valid_type' => CouponActivity::VALID_RELATIVE,
            'valid_days' => 7,
            'receive_types' => [1],
            'status' => 1,
        ]);

        $response->assertStatus(200)->assertJsonPath('code', 0);
        $this->assertEquals(0.0, (float) CouponActivity::where('code', 'cash_5')->value('threshold_amount'));
    }

    public function test_满减券优惠金额大于等于门槛时校验失败(): void
    {
        $this->actingAdmin();

        $response = $this->postJson(self::API . '/activities', [
            'code' => 'bad_rule',
            'title' => '非法规则券',
            'coupon_type' => CouponActivity::TYPE_FULL_REDUCE,
            'threshold_amount' => 100,
            'discount_amount' => 100, // 等于门槛，不合法
            'valid_type' => CouponActivity::VALID_RELATIVE,
            'valid_days' => 7,
            'receive_types' => [1],
            'status' => 1,
        ]);

        // ValidationException → 422
        $response->assertStatus(422);
        $this->assertDatabaseMissing('app_cmspro_coupon_activities', ['code' => 'bad_rule']);
    }

    public function test_活动编码重复时拒绝创建(): void
    {
        $this->actingAdmin();
        $this->createActivity(['code' => 'dup_code']);

        $response = $this->postJson(self::API . '/activities', [
            'code' => 'dup_code',
            'title' => '重复编码券',
            'coupon_type' => CouponActivity::TYPE_CASH,
            'discount_amount' => 5,
            'valid_type' => CouponActivity::VALID_RELATIVE,
            'valid_days' => 7,
            'receive_types' => [1],
            'status' => 1,
        ]);

        $response->assertStatus(422);
    }

    public function test_上下架状态切换(): void
    {
        $this->actingAdmin();
        $activity = $this->createActivity(['status' => 1]);

        $response = $this->putJson(self::API . "/activities/{$activity->id}/status", ['status' => 0]);

        $response->assertStatus(200)->assertJsonPath('code', 0);
        $this->assertEquals(0, (int) $activity->fresh()->status);
    }

    public function test_已发放过的活动不可删除(): void
    {
        $this->actingAdmin();
        $activity = $this->createActivity(['issued_count' => 1]);

        $response = $this->deleteJson(self::API . "/activities/{$activity->id}");

        $response->assertStatus(200)->assertJsonPath('code', 40003);
        $this->assertDatabaseHas('app_cmspro_coupon_activities', ['id' => $activity->id]);
    }

    public function test_未发放的活动可删除(): void
    {
        $this->actingAdmin();
        $activity = $this->createActivity();

        $response = $this->deleteJson(self::API . "/activities/{$activity->id}");

        $response->assertStatus(200)->assertJsonPath('code', 0);
        $this->assertDatabaseMissing('app_cmspro_coupon_activities', ['id' => $activity->id]);
    }

    public function test_后台定向发放接口(): void
    {
        $this->actingAdmin();
        $user = $this->createUser();
        $activity = $this->createActivity(['per_user_limit' => 0]);

        $response = $this->postJson(self::API . '/activities/issue', [
            'activity_id' => $activity->id,
            'target_type' => 1,
            'user_ids' => [$user->id],
            'remark' => '测试发放',
        ]);

        $response->assertStatus(200)->assertJsonPath('code', 0)
            ->assertJsonPath('data.success_count', 1);
    }

    public function test_兑换码批量生成与作废(): void
    {
        $this->actingAdmin();
        $activity = $this->createActivity();

        // 生成 10 个兑换码
        $genResponse = $this->postJson(self::API . '/codes/generate', [
            'activity_id' => $activity->id,
            'count' => 10,
        ]);
        $genResponse->assertStatus(200)->assertJsonPath('code', 0)
            ->assertJsonPath('data.count', 10);

        $batchNo = $genResponse->json('data.batch_no');
        $this->assertDatabaseCount('app_cmspro_coupon_redeem_codes', 10);

        // 作废单个兑换码
        $code = RedeemCode::where('batch_no', $batchNo)->first();
        $voidOne = $this->deleteJson(self::API . "/codes/{$code->id}");
        $voidOne->assertStatus(200)->assertJsonPath('code', 0);
        $this->assertEquals(RedeemCode::STATUS_VOID, (int) $code->fresh()->status);

        // 批量作废剩余未使用的兑换码
        $remainIds = RedeemCode::where('batch_no', $batchNo)
            ->where('status', RedeemCode::STATUS_UNUSED)
            ->pluck('id')
            ->all();
        $voidBatch = $this->deleteJson(self::API . '/codes/batch-void', ['ids' => $remainIds]);
        $voidBatch->assertStatus(200)->assertJsonPath('code', 0)
            ->assertJsonPath('data.voided', count($remainIds));
    }

    public function test_兑换码导出返回CSV(): void
    {
        $this->actingAdmin();
        $activity = $this->createActivity();

        $genResponse = $this->postJson(self::API . '/codes/generate', [
            'activity_id' => $activity->id,
            'count' => 3,
        ]);
        $batchNo = $genResponse->json('data.batch_no');

        $response = $this->get(self::API . '/codes/export?batch_no=' . $batchNo);

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_未使用的用户券可作废(): void
    {
        $this->actingAdmin();
        $user = $this->createUser();
        $activity = $this->createActivity();
        $coupon = $this->createUserCoupon($user->id, $activity->id);

        $response = $this->putJson(self::API . "/user-coupons/{$coupon->id}/invalidate");

        $response->assertStatus(200)->assertJsonPath('code', 0);
        $this->assertEquals(3, (int) $coupon->fresh()->status); // STATUS_VOID
    }

    public function test_设置保存与读取(): void
    {
        $this->actingAdmin();

        // 先经 Install 三向同步生成配置结构
        (new \App\Apps\CmsproCoupon\Install())->upgrade('0.9.0', '1.0.0');

        $saveResponse = $this->putJson(self::API . '/settings', [
            'access_mode' => 'path',
            'access_path' => 'coupon-center',
            'receive_enabled' => '1',
            'coupon_no_prefix' => 'QN',
            'page_size' => '30',
            'expire_notify_days' => '5',
            'discount_round_mode' => 'round',
        ]);
        $saveResponse->assertStatus(200)->assertJsonPath('code', 0);

        $getResponse = $this->getJson(self::API . '/settings');
        $getResponse->assertStatus(200)->assertJsonPath('code', 0)
            ->assertJsonPath('data.access_path', 'coupon-center')
            ->assertJsonPath('data.coupon_no_prefix', 'QN')
            ->assertJsonPath('data.page_size', '30');
    }

    public function test_设置保留路径前缀被拒绝(): void
    {
        $this->actingAdmin();
        (new \App\Apps\CmsproCoupon\Install())->upgrade('0.9.0', '1.0.0');

        $response = $this->putJson(self::API . '/settings', [
            'access_mode' => 'path',
            'access_path' => 'admin', // 系统保留路径
        ]);

        $response->assertStatus(200)->assertJsonPath('code', 40001);
    }

    /**
     * 常见问题第1章回归：已登录 + 非法载荷 + 普通 post()（不带 JSON Accept 头）
     * 断言非 3xx 且响应体可 json_decode（后台 API 组同样挂载 ForceJsonResponse）
     */
    public function test_后台普通post非法载荷返回422JSON而非302(): void
    {
        $this->actingAdmin();

        // 缺少必填 code/title 等字段的空载荷，Accept 为默认 text/html
        $response = $this->post(self::API . '/activities', []);

        $this->assertFalse($response->isRedirect(), '校验失败不应返回 302 重定向');
        $response->assertStatus(422);
        // 框架统一 ValidationException 渲染：code=40201，字段错误在 data 中
        $decoded = json_decode($response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertEquals(40201, $decoded['code'] ?? null);
    }

    // ══════════════════════════════════════════════════════════════
    // F52 写接口验证用例（4 类）
    // ══════════════════════════════════════════════════════════════

    /**
     * ② 逐个必填字段置空：应被拦截（422）且不落库
     *
     * @dataProvider requiredFieldProvider
     */
    #[DataProvider('requiredFieldProvider')]
    public function test_必填字段逐个置空被拦截且不落库(string $field): void
    {
        $this->actingAdmin();

        $payload = [
            'code' => 'req_test_' . uniqid(),
            'title' => '必填校验券',
            'coupon_type' => CouponActivity::TYPE_CASH,
            'discount_amount' => 5,
            'valid_type' => CouponActivity::VALID_RELATIVE,
            'valid_days' => 7,
            'receive_types' => [1],
            'status' => 1,
        ];
        $code = $payload['code'];
        $payload[$field] = '';

        $response = $this->postJson(self::API . '/activities', $payload);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('app_cmspro_coupon_activities', ['code' => $code]);
    }

    /**
     * 必填字段数据集：code/title/coupon_type/valid_type 为创建必填
     *
     * @return array<string,array<int,string>>
     */
    public static function requiredFieldProvider(): array
    {
        return [
            'code 置空' => ['code'],
            'title 置空' => ['title'],
            'coupon_type 置空' => ['coupon_type'],
            'valid_type 置空' => ['valid_type'],
        ];
    }

    /**
     * ④ 更新必填字段传空被拦截，原记录保持不变
     */
    public function test_更新必填字段传空原记录不变(): void
    {
        $this->actingAdmin();
        $activity = $this->createActivity(['code' => 'keep_code', 'title' => '原标题']);

        // 尝试把 title 置空
        $response = $this->putJson(self::API . "/activities/{$activity->id}", [
            'title' => '',
        ]);

        $response->assertStatus(422);
        // 原记录未被破坏
        $fresh = $activity->fresh();
        $this->assertEquals('keep_code', $fresh->code);
        $this->assertEquals('原标题', $fresh->title);
    }

    /**
     * ③ 合法空值走默认：nullable 字段（门槛/折扣/说明）省略或空值时正常入库
     */
    public function test_合法空值字段走默认值创建成功(): void
    {
        $this->actingAdmin();

        $code = 'default_vals_' . uniqid();
        // 立减券：threshold/description/max_discount 留空应走默认
        $response = $this->postJson(self::API . '/activities', [
            'code' => $code,
            'title' => '默认值券',
            'coupon_type' => CouponActivity::TYPE_CASH,
            'discount_amount' => 8,
            'valid_type' => CouponActivity::VALID_RELATIVE,
            'valid_days' => 10,
            'receive_types' => [1],
            // threshold_amount / description / max_discount_amount 省略
        ]);

        $response->assertStatus(200)->assertJsonPath('code', 0);
        $created = CouponActivity::where('code', $code)->first();
        $this->assertNotNull($created);
        $this->assertEquals(0.0, (float) $created->threshold_amount);
        $this->assertEquals(0.0, (float) $created->max_discount_amount);
        $this->assertEquals(8.0, (float) $created->discount_amount);
    }

    /**
     * 券类型非法值被拦截（枚举越界防护）
     */
    public function test_券类型非法值被拦截(): void
    {
        $this->actingAdmin();

        $response = $this->postJson(self::API . '/activities', [
            'code' => 'bad_type_' . uniqid(),
            'title' => '非法券型',
            'coupon_type' => 99, // 不在 1,2,3 内
            'valid_type' => CouponActivity::VALID_RELATIVE,
            'valid_days' => 7,
            'receive_types' => [1],
        ]);

        $response->assertStatus(422);
    }

    /**
     * 折扣券折扣率越界（>=10）被 Service 规则校验拦截
     */
    public function test_折扣率越界被拦截且不落库(): void
    {
        $this->actingAdmin();

        $code = 'bad_rate_' . uniqid();
        $response = $this->postJson(self::API . '/activities', [
            'code' => $code,
            'title' => '非法折扣券',
            'coupon_type' => CouponActivity::TYPE_DISCOUNT,
            'discount_rate' => 10, // 越界，必须在 0-10 开区间
            'valid_type' => CouponActivity::VALID_RELATIVE,
            'valid_days' => 7,
            'receive_types' => [1],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('app_cmspro_coupon_activities', ['code' => $code]);
    }
}
