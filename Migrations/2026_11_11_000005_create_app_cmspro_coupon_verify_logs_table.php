<?php

/**
 * 优惠券应用 · 核销记录表
 *
 * 每次核销写一条流水，记录订单号、订单金额与实际优惠金额，
 * 并标记调用方应用（caller_app_id）便于跨应用抵扣场景对账。
 * 撤销核销不删除记录，而是置 status=2 并记录撤销时间与原因，保留审计痕迹。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_coupon_verify_logs')) {
            return;
        }

        Schema::create('app_cmspro_coupon_verify_logs', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->unsignedBigInteger('user_coupon_id')->comment('用户优惠券ID');
            $table->string('coupon_no', 50)->comment('券号快照');
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->string('order_no', 64)->comment('核销关联的业务订单号');
            $table->decimal('order_amount', 10, 2)->default(0)->comment('订单原始金额');
            $table->decimal('discount_amount', 10, 2)->default(0)->comment('本张券实际优惠金额');
            $table->string('caller_app_id', 50)->default('')->comment('调用方应用ID，如cmspro.pointshop；空表示前端API核销');
            $table->unsignedTinyInteger('operator_type')->default(1)->comment('操作人类型：1-用户自助 2-后台管理员 3-系统');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人ID（用户ID或管理员ID）');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态：1-已核销 2-已撤销');
            $table->timestamp('verify_time')->nullable()->comment('核销时间');
            $table->timestamp('cancel_time')->nullable()->comment('撤销时间');
            $table->string('cancel_reason', 255)->nullable()->comment('撤销原因');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->index('user_coupon_id', 'idx_vl_user_coupon');
            $table->index(['order_no', 'status'], 'idx_vl_order_status');
            $table->index(['user_id', 'status'], 'idx_vl_user_status');
            $table->index('verify_time', 'idx_vl_verify_time');
            $table->index('caller_app_id', 'idx_vl_caller_app');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `app_cmspro_coupon_verify_logs` COMMENT '优惠券-核销记录表'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_coupon_verify_logs')) {
            return;
        }

        Schema::dropIfExists('app_cmspro_coupon_verify_logs');
    }
};
