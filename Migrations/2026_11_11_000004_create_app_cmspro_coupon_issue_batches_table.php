<?php

/**
 * 优惠券应用 · 发放批次表
 *
 * 后台定向发放（按指定用户 / 全部用户）以批次为单位记录，
 * 便于追溯「谁在什么时候给哪些人发了多少张券、成功几张失败几张」。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_coupon_issue_batches')) {
            return;
        }

        Schema::create('app_cmspro_coupon_issue_batches', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('batch_no', 50)->comment('批次号，唯一');
            $table->unsignedBigInteger('activity_id')->comment('券活动ID，关联activities表');
            $table->string('activity_title', 100)->default('')->comment('活动名称快照');
            $table->unsignedTinyInteger('target_type')->default(1)->comment('发放对象：1-指定用户 2-全部用户');
            $table->integer('total_count')->default(0)->comment('目标用户总数');
            $table->integer('success_count')->default(0)->comment('发放成功数量');
            $table->integer('fail_count')->default(0)->comment('发放失败数量（如超出限领、库存不足）');
            $table->text('fail_detail')->nullable()->comment('失败明细，JSON数组，最多保留前100条');
            $table->unsignedBigInteger('admin_id')->nullable()->comment('操作管理员ID');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态：0-处理中 1-已完成');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->unique('batch_no');
            $table->index('activity_id', 'idx_ib_activity_id');
            $table->index('create_time', 'idx_ib_create_time');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `app_cmspro_coupon_issue_batches` COMMENT '优惠券-发放批次表'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_coupon_issue_batches')) {
            return;
        }

        Schema::dropIfExists('app_cmspro_coupon_issue_batches');
    }
};
