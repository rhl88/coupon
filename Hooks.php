<?php

namespace App\Apps\CmsproCoupon;

use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Apps\CmsproCoupon\Models\VerifyLog;
use App\Services\HookManager;
use Illuminate\Support\Facades\Log;

/**
 * 优惠券应用 · 钩子注册器
 *
 * 分两类职责：
 *
 * ┌── 监听系统钩子（Filter）：向系统输出本应用数据
 * │     dashboard.stats      后台仪表盘注入「已发放/待使用/今日核销」统计
 * │     admin.notifications.todos  通知中心注入「即将过期优惠券」待办
 * ├── 监听系统钩子（Action）：感知应用生命周期
 * │     app.installed / app.enabled / app.disabled
 * └── 暴露自定义钩子点（供其他应用扩展本应用）
 *       Action：cmspro.coupon.received  用户领取成功
 *               cmspro.coupon.issued    后台定向发放成功
 *               cmspro.coupon.redeemed  兑换码兑换成功
 *               cmspro.coupon.verified  券被核销
 *               cmspro.coupon.revoked   核销被撤销
 *               cmspro.coupon.expired   过期扫描置为已过期
 *       Filter：cmspro.coupon.available.list     改写「订单可用券列表」
 *               cmspro.coupon.discount.preview   改写「核销试算结果」
 *
 * 注册必须传入第 4 个参数 app_id，否则应用禁用/卸载时钩子无法被系统摘除。
 */
class Hooks
{
    /**
     * 应用 ID —— 钩子归属标记
     */
    public const APP_ID = 'cmspro.coupon';

    /**
     * 日志标记
     */
    private const LOG_TAG = '[cmspro.coupon][hooks] ';

    /**
     * 注册全部钩子
     *
     * @param HookManager $hooks 系统钩子管理器（单例）
     */
    public function register(HookManager $hooks): void
    {
        // ══ 一、监听系统 Filter 钩子 ══════════════════════════════
        $hooks->registerFilter('dashboard.stats', [$this, 'filterDashboardStats'], 10, self::APP_ID);
        $hooks->registerFilter('admin.notifications.todos', [$this, 'filterNotificationTodos'], 10, self::APP_ID);

        // ══ 二、监听系统 Action 钩子（生命周期感知）═══════════════
        $hooks->registerAction('app.installed', [$this, 'onAppInstalled'], 10, self::APP_ID);
        $hooks->registerAction('app.enabled', [$this, 'onAppEnabled'], 10, self::APP_ID);
        $hooks->registerAction('app.disabled', [$this, 'onAppDisabled'], 10, self::APP_ID);
    }

    /**
     * 后台仪表盘统计注入
     *
     * 应用表未安装（如刚创建目录尚未安装）时降级为不注入，保持系统统计可用。
     *
     * @param array<string,mixed> $stats 系统统计数组
     * @return array<string,mixed>
     */
    public function filterDashboardStats(array $stats): array
    {
        try {
            $stats['coupon_issued_count'] = UserCoupon::count();
            $stats['coupon_usable_count'] = UserCoupon::usable()->count();
            $stats['coupon_verified_today'] = VerifyLog::where('status', VerifyLog::STATUS_VERIFIED)
                ->where('verify_time', '>=', now()->startOfDay()->format('Y-m-d H:i:s'))
                ->count();
        } catch (\Throwable $e) {
            Log::warning(self::LOG_TAG . '仪表盘统计注入失败', ['error' => $e->getMessage()]);
        }

        return $stats;
    }

    /**
     * 通知中心待办注入：即将过期的未使用优惠券数量
     *
     * 契约字段：key（全局唯一）/ title / count（实时 int，归零自动消失）/ link / permission_code。
     *
     * @param array<int,array<string,mixed>> $todos 系统待办列表
     * @return array<int,array<string,mixed>>
     */
    public function filterNotificationTodos(array $todos): array
    {
        try {
            $days = max(0, (int) cmspro_coupon_config('expire_notify_days', 3));

            if ($days <= 0) {
                return $todos; // 未开启到期提醒则不注入待办
            }

            $now = now()->format('Y-m-d H:i:s');
            $deadline = now()->addDays($days)->endOfDay()->format('Y-m-d H:i:s');

            $count = UserCoupon::where('status', UserCoupon::STATUS_UNUSED)
                ->whereNotNull('valid_end_time')
                ->whereBetween('valid_end_time', [$now, $deadline])
                ->count();

            $todos[] = [
                'key' => self::APP_ID . '.coupons.expiring',
                'title' => '即将过期优惠券',
                'count' => $count,
                'link' => '/admin/cmspro/coupon/user-coupons',
                'permission_code' => 'cmspro.coupon.usercoupon.view',
            ];
        } catch (\Throwable $e) {
            Log::warning(self::LOG_TAG . '通知待办注入失败', ['error' => $e->getMessage()]);
        }

        return $todos;
    }

    /**
     * 应用安装完成回调
     *
     * @param string|null $appId 被安装的应用 ID（系统传入）
     */
    public function onAppInstalled(?string $appId = null): void
    {
        if ($appId !== null && $appId !== self::APP_ID) {
            return;
        }

        Log::info(self::LOG_TAG . '优惠券应用安装完成');
    }

    /**
     * 应用启用回调
     *
     * @param string|null $appId 被启用的应用 ID
     */
    public function onAppEnabled(?string $appId = null): void
    {
        if ($appId !== null && $appId !== self::APP_ID) {
            return;
        }

        // 启用后清配置缓存，避免读到禁用期间的旧值
        cmspro_coupon_forget_config_cache();
    }

    /**
     * 应用禁用回调
     *
     * @param string|null $appId 被禁用的应用 ID
     */
    public function onAppDisabled(?string $appId = null): void
    {
        if ($appId !== null && $appId !== self::APP_ID) {
            return;
        }

        cmspro_coupon_forget_config_cache();
    }
}
