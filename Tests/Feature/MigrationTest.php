<?php

namespace App\Apps\CmsproCoupon\Tests\Feature;

use App\Apps\CmsproCoupon\Tests\CouponTestCase;
use Illuminate\Support\Facades\Schema;

/**
 * 迁移测试：验证 5 张业务表结构正确创建
 */
class MigrationTest extends CouponTestCase
{
    public function test_迁移创建五张业务表(): void
    {
        $tables = [
            'app_cmspro_coupon_activities',
            'app_cmspro_coupon_user_coupons',
            'app_cmspro_coupon_redeem_codes',
            'app_cmspro_coupon_issue_batches',
            'app_cmspro_coupon_verify_logs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "表 {$table} 应该存在");
        }
    }

    public function test_券活动表包含核心规则字段(): void
    {
        $columns = [
            'code', 'title', 'coupon_type', 'threshold_amount', 'discount_amount',
            'discount_rate', 'max_discount_amount', 'total_stock', 'issued_count',
            'per_user_limit', 'valid_type', 'valid_start_time', 'valid_end_time',
            'valid_days', 'receive_start_time', 'receive_end_time', 'receive_type',
            'status', 'create_time', 'update_time',
        ];

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn('app_cmspro_coupon_activities', $column),
                "券活动表应包含字段 {$column}"
            );
        }
    }

    public function test_用户券表包含快照与状态字段(): void
    {
        $columns = [
            'coupon_no', 'activity_id', 'user_id', 'title', 'coupon_type',
            'threshold_amount', 'discount_amount', 'discount_rate', 'max_discount_amount',
            'valid_start_time', 'valid_end_time', 'source', 'status',
            'use_time', 'order_no', 'receive_time',
        ];

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn('app_cmspro_coupon_user_coupons', $column),
                "用户券表应包含字段 {$column}"
            );
        }
    }

    public function test_核销记录表包含审计字段(): void
    {
        $columns = [
            'user_coupon_id', 'coupon_no', 'user_id', 'order_no', 'order_amount',
            'discount_amount', 'caller_app_id', 'operator_type', 'operator_id',
            'status', 'verify_time', 'cancel_time', 'cancel_reason',
        ];

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn('app_cmspro_coupon_verify_logs', $column),
                "核销记录表应包含字段 {$column}"
            );
        }
    }
}
