<?php

/**
 * 优惠券应用 · 券活动表
 *
 * 券活动（券模板）定义一类优惠券的规则：券型、门槛、优惠力度、发放库存、
 * 领取限次、有效期策略、领取时间窗与发放方式。用户领到的每张券都会
 * 对活动规则做一次快照，保证活动后期调整不影响已发放的券。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_coupon_activities')) {
            return;
        }

        Schema::create('app_cmspro_coupon_activities', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('code', 50)->comment('活动编码，唯一标识，创建后不建议修改');
            $table->string('title', 100)->comment('活动名称，展示给用户');
            $table->unsignedTinyInteger('coupon_type')->default(1)->comment('券类型：1-满减券 2-折扣券 3-无门槛立减券');
            $table->decimal('threshold_amount', 10, 2)->default(0)->comment('使用门槛金额，订单满此金额可用；0表示无门槛');
            $table->decimal('discount_amount', 10, 2)->default(0)->comment('优惠金额（满减券/立减券使用）');
            $table->decimal('discount_rate', 5, 2)->default(0)->comment('折扣率（折扣券使用），如8.50表示8.5折');
            $table->decimal('max_discount_amount', 10, 2)->default(0)->comment('折扣券最高优惠金额，0表示不封顶');
            $table->integer('total_stock')->default(0)->comment('发放总量，0表示不限量');
            $table->integer('issued_count')->default(0)->comment('已发放数量');
            $table->integer('per_user_limit')->default(1)->comment('每人限领数量，0表示不限');
            $table->unsignedTinyInteger('valid_type')->default(1)->comment('有效期类型：1-固定时间段 2-领取后N天内有效');
            $table->timestamp('valid_start_time')->nullable()->comment('固定有效期开始时间（valid_type=1）');
            $table->timestamp('valid_end_time')->nullable()->comment('固定有效期结束时间（valid_type=1）');
            $table->unsignedSmallInteger('valid_days')->default(0)->comment('领取后有效天数（valid_type=2）');
            $table->timestamp('receive_start_time')->nullable()->comment('可领取开始时间，空表示不限');
            $table->timestamp('receive_end_time')->nullable()->comment('可领取结束时间，空表示不限');
            $table->unsignedTinyInteger('receive_type')->default(1)->comment('发放方式位掩码：1-用户领取 2-后台发放 4-兑换码，可叠加');
            $table->unsignedTinyInteger('receive_need_login')->default(1)->comment('领取是否必须登录：0-否 1-是');
            $table->string('description', 500)->nullable()->comment('使用说明，展示给用户');
            $table->integer('sort')->default(0)->comment('排序值，越小越靠前');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态：0-下架 1-上架');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->unique('code');
            $table->index('status');
            $table->index('coupon_type');
            $table->index('sort');
        });

        // 表备注为 MySQL 专有语法，SQLite 测试环境不支持，仅 MySQL 执行
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `app_cmspro_coupon_activities` COMMENT '优惠券-券活动表'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_cmspro_coupon_activities')) {
            return;
        }

        Schema::dropIfExists('app_cmspro_coupon_activities');
    }
};
