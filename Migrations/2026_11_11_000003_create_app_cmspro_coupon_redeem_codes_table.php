<?php

/**
 * 优惠券应用 · 兑换码表
 *
 * 一码一券：每个兑换码只能被一个用户兑换一次。
 * code 字段建唯一索引，配合兑换时的行锁，杜绝并发下一码多兑。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_coupon_redeem_codes')) {
            return;
        }

        Schema::create('app_cmspro_coupon_redeem_codes', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('code', 50)->comment('兑换码，全局唯一');
            $table->unsignedBigInteger('activity_id')->comment('券活动ID，关联activities表');
            $table->string('batch_no', 50)->nullable()->comment('生成批次号，同批生成的兑换码共用');
            $table->unsignedTinyInteger('status')->default(0)->comment('状态：0-未使用 1-已使用 2-已作废');
            $table->unsignedBigInteger('user_id')->nullable()->comment('兑换用户ID');
            $table->unsignedBigInteger('user_coupon_id')->nullable()->comment('兑换生成的用户券ID');
            $table->timestamp('redeem_time')->nullable()->comment('兑换时间');
            $table->unsignedBigInteger('admin_id')->nullable()->comment('生成该码的管理员ID');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->unique('code');
            $table->index(['activity_id', 'status'], 'idx_rc_activity_status');
            $table->index('batch_no', 'idx_rc_batch_no');
            $table->index('user_id', 'idx_rc_user_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `app_cmspro_coupon_redeem_codes` COMMENT '优惠券-兑换码表'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_coupon_redeem_codes')) {
            return;
        }

        Schema::dropIfExists('app_cmspro_coupon_redeem_codes');
    }
};
