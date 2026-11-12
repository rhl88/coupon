<?php

namespace App\Apps\CmsproCoupon\Console;

use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Services\HookManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * 优惠券过期扫描命令
 *
 * 把「状态仍为未使用、但 valid_end_time 已过」的券批量置为已过期，
 * 并触发 cmspro.coupon.expired 钩子供其他应用感知。
 *
 * 双保险设计：查询侧已做惰性过期判定（UserCoupon::scopeUsable），
 * 即使命令未按时执行，用户与核销接口也不会把过期券当作可用；
 * 本命令的作用是让列表状态、统计口径与真实情况保持一致。
 *
 * 使用方式：
 *   php artisan coupon:expire            # 执行过期扫描
 *   php artisan coupon:expire --dry-run  # 仅统计待过期数量，不写库
 *
 * 建议由「任务管理」应用按每日一次调度（如每天 00:05）。
 */
class CouponExpireCommand extends Command
{
    protected $signature = 'coupon:expire
                            {--dry-run : 仅统计待过期数量，不实际更新}';

    protected $description = '扫描并标记已过期的优惠券（未使用且超过有效期）';

    /**
     * 单批处理数量（分批更新避免长事务与锁表）
     */
    private const CHUNK_SIZE = 500;

    public function handle(HookManager $hooks): int
    {
        if (! cmspro_coupon_config_bool('expire_scan_enabled', true)) {
            $this->info('过期扫描已在设置中关闭，本次跳过。');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('app_cmspro_coupon_user_coupons')) {
            $this->warn('优惠券数据表不存在，请确认应用已正确安装。');

            return self::FAILURE;
        }

        $total = UserCoupon::expiredPending()->count();

        if ($total === 0) {
            $this->info('没有需要标记过期的优惠券。');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("待过期优惠券共 {$total} 张（dry-run 模式，未写库）。");

            return self::SUCCESS;
        }

        $updated = $this->markExpired($hooks);

        $this->info("过期扫描完成：共标记 {$updated} 张优惠券为已过期。");

        return self::SUCCESS;
    }

    /**
     * 分批标记过期并触发钩子
     *
     * 按主键游标分批，避免一次性加载大数据集撑爆内存。
     */
    protected function markExpired(HookManager $hooks): int
    {
        $updated = 0;
        $lastId = 0;

        do {
            $coupons = UserCoupon::expiredPending()
                ->where('id', '>', $lastId)
                ->orderBy('id', 'asc')
                ->limit(self::CHUNK_SIZE)
                ->get();

            if ($coupons->isEmpty()) {
                break;
            }

            $lastId = (int) $coupons->last()->id;

            foreach ($coupons as $coupon) {
                // 条件更新：仅未使用状态可被置为过期，避免与核销并发冲突
                $affected = UserCoupon::where('id', $coupon->id)
                    ->where('status', UserCoupon::STATUS_UNUSED)
                    ->update([
                        'status' => UserCoupon::STATUS_EXPIRED,
                        'update_time' => now()->format('Y-m-d H:i:s'),
                    ]);

                if ($affected === 0) {
                    continue;
                }

                $updated++;
                $coupon->refresh();
                $this->fireExpiredHook($hooks, $coupon);
            }
        } while ($coupons->count() === self::CHUNK_SIZE);

        return $updated;
    }

    /**
     * 触发过期钩子（单张券失败不影响整批扫描）
     */
    protected function fireExpiredHook(HookManager $hooks, UserCoupon $coupon): void
    {
        try {
            $hooks->doAction('cmspro.coupon.expired', $coupon);
        } catch (\Throwable $e) {
            // 钩子异常已在 HookManager 内部记录，此处仅保证命令不中断
        }
    }
}
