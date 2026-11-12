<?php

/**
 * 优惠券应用 · 用户优惠券表
 *
 * 记录用户持有的每一张券。发放时从券活动快照规则字段，
 * 后续活动规则调整不影响已发放的券（券的既有承诺不可变）。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_coupon_user_coupons')) {
            return;
        }

        Schema::create('app_cmspro_coupon_user_coupons', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('coupon_no', 50)->comment('券号，全局唯一，核销时以此定位');
            $table->unsignedBigInteger('activity_id')->comment('券活动ID，关联activities表');
            $table->unsignedBigInteger('user_id')->comment('归属前台用户ID');
            $table->string('title', 100)->comment('券名称快照');
            $table->unsignedTinyInteger('coupon_type')->comment('券类型快照：1-满减券 2-折扣券 3-无门槛立减券');
            $table->decimal('threshold_amount', 10, 2)->default(0)->comment('使用门槛金额快照');
            $table->decimal('discount_amount', 10, 2)->default(0)->comment('优惠金额快照');
            $table->decimal('discount_rate', 5, 2)->default(0)->comment('折扣率快照');
            $table->decimal('max_discount_amount', 10, 2)->default(0)->comment('最高优惠金额快照');
            $table->string('description', 500)->nullable()->comment('使用说明快照');
            $table->timestamp('valid_start_time')->nullable()->comment('生效时间');
            $table->timestamp('valid_end_time')->nullable()->comment('失效时间');
            $table->unsignedTinyInteger('source')->default(1)->comment('来源：1-用户领取 2-后台发放 3-兑换码兑换');
            $table->unsignedBigInteger('source_id')->nullable()->comment('来源关联ID：批次ID或兑换码ID');
            $table->unsignedTinyInteger('status')->default(0)->comment('状态：0-未使用 1-已使用 2-已过期 3-已作废');
            $table->timestamp('use_time')->nullable()->comment('核销时间');
            $table->string('order_no', 64)->nullable()->comment('核销关联的订单号');
            $table->timestamp('receive_time')->nullable()->comment('领取/发放时间');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->unique('coupon_no');
            $table->index(['user_id', 'status'], 'idx_uc_user_status');
            $table->index(['activity_id', 'user_id'], 'idx_uc_activity_user');
            $table->index('valid_end_time', 'idx_uc_valid_end');
            $table->index('order_no', 'idx_uc_order_no');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `app_cmspro_coupon_user_coupons` COMMENT '优惠券-用户优惠券表'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_coupon_user_coupons')) {
            return;
        }

        Schema::dropIfExists('app_cmspro_coupon_user_coupons');
    }
};
