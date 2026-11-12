<?php

namespace App\Apps\CmsproCoupon\Tests;

use App\Apps\CmsproCoupon\Models\CouponActivity;
use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Enums\Status;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CmsproCoupon 优惠券应用 · 测试基类
 *
 * 说明：
 * 1. 应用 ServiceProvider 仅对已安装应用注册，测试环境需手动注册以加载路由与视图命名空间
 * 2. 内存库中 RefreshDatabase 仅迁移框架表，应用 5 张业务表需手动执行迁移 up()
 * 3. 日期断言遵循 CMSPRO 规范（Y-m-d H:i:s），禁止 ISO 8601
 */
abstract class CouponTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerCouponProvider();
        $this->createCouponTables();
    }

    /**
     * 手动注册应用 ServiceProvider（加载路由 / 视图命名空间 / helpers）
     */
    protected function registerCouponProvider(): void
    {
        $provider = $this->app->register(\App\Apps\CmsproCoupon\ServiceProvider::class);
        if ($provider && method_exists($provider, 'boot')) {
            $this->app->call([$provider, 'boot']);
        }
    }

    /**
     * 按依赖顺序执行优惠券应用全部迁移建表（与 Install::runMigrations 同构，幂等）
     */
    protected function createCouponTables(): void
    {
        $migrations = [
            '2026_11_11_000001_create_app_cmspro_coupon_activities_table',
            '2026_11_11_000002_create_app_cmspro_coupon_user_coupons_table',
            '2026_11_11_000003_create_app_cmspro_coupon_redeem_codes_table',
            '2026_11_11_000004_create_app_cmspro_coupon_issue_batches_table',
            '2026_11_11_000005_create_app_cmspro_coupon_verify_logs_table',
        ];

        foreach ($migrations as $migration) {
            (require base_path('app/Apps/CmsproCoupon/Migrations/' . $migration . '.php'))->up();
        }
    }

    /**
     * 创建并登录一个管理员（super_admin 角色，权限校验直接放行）
     */
    protected function actingAdmin(): AdminUser
    {
        $admin = AdminUser::create([
            'username' => 'coupon_admin_' . uniqid(),
            'password' => bcrypt('123456'),
            'name' => '测试管理员',
            'status' => 1,
        ]);

        $role = AdminRole::firstOrCreate(
            ['code' => 'super_admin'],
            [
                'name' => '超级管理员',
                'description' => '系统内置超级管理员',
                'parent_id' => 0,
                'sort' => 0,
                'status' => Status::ENABLED,
            ]
        );
        $admin->roles()->attach($role->id);

        $this->actingAs($admin, 'admin');

        return $admin;
    }

    /**
     * 创建并登录一个前台用户（不登录场景直接返回不 actingAs）
     */
    protected function actingUser(array $overrides = []): User
    {
        $user = User::create(array_merge([
            'username' => 'coupon_user_' . uniqid(),
            'password' => bcrypt('123456'),
            'status' => 1,
        ], $overrides));

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * 创建一个前台用户（不登录）
     */
    protected function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'coupon_user_' . uniqid(),
            'password' => bcrypt('123456'),
            'status' => 1,
        ], $overrides));
    }

    /**
     * 创建券活动（默认：满100减20，上架，支持用户领取，固定有效期）
     */
    protected function createActivity(array $overrides = []): CouponActivity
    {
        return CouponActivity::create(array_merge([
            'code' => 'test_' . uniqid(),
            'title' => '测试满减券',
            'coupon_type' => CouponActivity::TYPE_FULL_REDUCE,
            'threshold_amount' => 100,
            'discount_amount' => 20,
            'discount_rate' => 0,
            'max_discount_amount' => 0,
            'total_stock' => 0,
            'issued_count' => 0,
            'per_user_limit' => 1,
            'valid_type' => CouponActivity::VALID_FIXED,
            'valid_start_time' => now()->subDay()->format('Y-m-d H:i:s'),
            'valid_end_time' => now()->addDays(30)->format('Y-m-d H:i:s'),
            'receive_type' => CouponActivity::RECEIVE_USER | CouponActivity::RECEIVE_ADMIN | CouponActivity::RECEIVE_CODE,
            'receive_need_login' => 1,
            'sort' => 0,
            'status' => 1,
        ], $overrides));
    }

    /**
     * 直接给用户发一张券（绕过领取流程，构造特定状态的券）
     */
    protected function createUserCoupon(int $userId, int $activityId, array $overrides = []): UserCoupon
    {
        $activity = CouponActivity::find($activityId);

        return UserCoupon::create(array_merge([
            'coupon_no' => 'CPTEST' . uniqid() . random_int(100, 999),
            'activity_id' => $activityId,
            'user_id' => $userId,
            'title' => $activity->title,
            'coupon_type' => $activity->coupon_type,
            'threshold_amount' => $activity->threshold_amount,
            'discount_amount' => $activity->discount_amount,
            'discount_rate' => $activity->discount_rate,
            'max_discount_amount' => $activity->max_discount_amount,
            'description' => $activity->description,
            'valid_start_time' => $activity->valid_start_time?->format('Y-m-d H:i:s'),
            'valid_end_time' => $activity->valid_end_time?->format('Y-m-d H:i:s'),
            'source' => UserCoupon::SOURCE_RECEIVE,
            'status' => UserCoupon::STATUS_UNUSED,
            'receive_time' => now()->format('Y-m-d H:i:s'),
        ], $overrides));
    }
}
