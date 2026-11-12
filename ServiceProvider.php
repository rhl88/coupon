<?php

namespace App\Apps\CmsproCoupon;

use App\Apps\CmsproCoupon\Console\CouponExpireCommand;
use App\Apps\CmsproCoupon\Http\Middleware\ForceJsonResponse;
use App\Models\ConfigItem;
use App\Services\HookManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * 优惠券应用 · 服务提供者
 *
 * 应用运行时入口，负责：
 * 1. 加载应用级 helpers.php（cmspro_coupon_config / cmspro_coupon_url 等）
 * 2. 合并应用默认配置到 config('apps.cmspro.coupon')
 * 3. 注册后台 / 用户端 / 前端三终端路由（前端按访问模式动态注册）
 * 4. 注册视图命名空间 cmspro.coupon::
 * 5. 注册钩子（ServiceProvider 通道，保证进程重启后钩子仍生效）
 * 6. 注册 Artisan 命令（coupon:expire 过期扫描）
 */
class ServiceProvider extends BaseServiceProvider
{
    /**
     * 应用根目录相对路径（基于 base_path()）
     */
    private const APP_PATH = 'app/Apps/CmsproCoupon';

    /**
     * 应用 ID（钩子归属标记、配置 code 前缀推导）
     */
    private const APP_ID = 'cmspro.coupon';

    /**
     * 应用配置命名空间
     */
    private const CONFIG_KEY = 'apps.cmspro.coupon';

    /**
     * 视图命名空间
     */
    private const VIEW_NAMESPACE = 'cmspro.coupon';

    /**
     * 配置项 code 前缀
     */
    private const CONFIG_CODE_PREFIX = 'app_cmspro_coupon_';

    /**
     * 注册阶段：只做绑定不做执行（此时数据库可能尚未就绪）
     */
    public function register(): void
    {
        $helpers = base_path(self::APP_PATH . '/helpers.php');
        if (file_exists($helpers)) {
            require_once $helpers;
        }

        $this->mergeConfigFrom(
            base_path(self::APP_PATH . '/Config/coupon.php'),
            self::CONFIG_KEY
        );

        $this->commands([
            CouponExpireCommand::class,
        ]);
    }

    /**
     * 启动阶段：由系统在 booted() 回调中调用，数据库已就绪
     */
    public function boot(): void
    {
        $this->registerRoutes();
        $this->loadViews();
        $this->registerHooks();
    }

    /**
     * 注册三终端路由
     *
     * 中间件对照：
     * - admin 后台页面/接口：['web','auth:admin','permission:admin']，前缀 admin/cmspro/coupon
     * - user 用户端页面/接口：['web','auth:web']，前缀 user/cmspro/coupon
     * - home 前端页面/API：按访问模式动态注册（none/path/domain）
     *
     * 所有 API 路由组额外挂载 ForceJsonResponse 中间件：
     * 前端 jQuery/fetch 调用不带 JSON Accept 头时，校验失败会被框架
     * 转成 302 重定向（常见问题第 1 章），强制 Accept 后统一返回 422 JSON。
     */
    protected function registerRoutes(): void
    {
        // ── 后台页面路由 ──
        Route::prefix('admin/cmspro/coupon')
            ->namespace('App\Apps\CmsproCoupon\Controllers\Admin')
            ->middleware(['web', 'auth:admin', 'permission:admin'])
            ->group(base_path(self::APP_PATH . '/Routes/admin.php'));

        // ── 后台数据接口 ──
        Route::prefix('admin/cmspro/coupon/api')
            ->namespace('App\Apps\CmsproCoupon\Controllers\Admin')
            ->middleware(['web', 'auth:admin', 'permission:admin', ForceJsonResponse::class])
            ->group(base_path(self::APP_PATH . '/Routes/admin_api.php'));

        // ── 用户端页面路由 ──
        Route::prefix('user/cmspro/coupon')
            ->namespace('App\Apps\CmsproCoupon\Controllers\User')
            ->middleware(['web', 'auth:web'])
            ->group(base_path(self::APP_PATH . '/Routes/user.php'));

        // ── 用户端数据接口 ──
        Route::prefix('api/user/cmspro/coupon')
            ->namespace('App\Apps\CmsproCoupon\Controllers\User')
            ->middleware(['web', 'auth:web', ForceJsonResponse::class])
            ->group(base_path(self::APP_PATH . '/Routes/user_api.php'));

        // ── 前端页面 + 前端 API：按访问模式动态注册 ──
        $this->registerHomeRoutes();
    }

    /**
     * 按访问模式注册前端路由
     *
     * - none：不注册前端页面路由（领券仅在用户中心与后台）
     * - path：Route::prefix('{access_path}')，如 /coupon
     * - domain：Route::domain('{access_domain}')，框架提前注册时跳过避免重复
     *
     * 前端核销/查询 API 固定挂在 api/cmspro/coupon，且**不带 domain 约束**：
     * 框架 catch-all 路由用 `where('any','^(?!api/).*')` 通用放行 api/ 前缀
     * （常见问题第 12 章修复），全局 API 在子域名下天然可访问，无需在
     * Route::domain() 内重复注册。
     */
    protected function registerHomeRoutes(): void
    {
        $accessMode = $this->getConfigValue('access_mode', 'path');

        // 前端 API 始终注册为全局前缀（不带 domain），挂载限流 + 强制 JSON 响应。
        // 三种访问模式共用同一份 API 路由，子域名模式下由框架 catch-all 放行 api/ 前缀。
        Route::prefix('api/cmspro/coupon')
            ->namespace('App\Apps\CmsproCoupon\Controllers\HomeApi')
            ->middleware(['web', 'throttle:60,1', ForceJsonResponse::class])
            ->group(base_path(self::APP_PATH . '/Routes/home-api.php'));

        if ($accessMode === 'none') {
            return;
        }

        if ($accessMode === 'domain') {
            $domainConfig = $this->getConfigValue('access_domain', '');
            if (trim($domainConfig) === '') {
                return; // 未填域名：不注册，避免污染主域名
            }

            // 框架 AppServiceProvider::registerDomainRoutesEarly() 已提前注册时跳过
            if ($this->app->bound('domain_routes_registered.' . self::APP_ID)) {
                return;
            }

            $domains = array_filter(array_map('trim', explode(',', $domainConfig)));

            foreach ($domains as $domain) {
                Route::domain($domain)
                    ->middleware(['web'])
                    ->namespace('App\Apps\CmsproCoupon\Controllers\Home')
                    ->group(base_path(self::APP_PATH . '/Routes/home.php'));
            }

            return;
        }

        // path 模式（默认）
        $path = trim(trim($this->getConfigValue('access_path', 'coupon')), '/');
        if ($path === '') {
            return; // 路径为空时不注册，避免污染根路径
        }

        Route::prefix($path)
            ->middleware(['web'])
            ->namespace('App\Apps\CmsproCoupon\Controllers\Home')
            ->group(base_path(self::APP_PATH . '/Routes/home.php'));
    }

    /**
     * 注册视图命名空间
     */
    protected function loadViews(): void
    {
        $this->loadViewsFrom(
            base_path(self::APP_PATH . '/Views'),
            self::VIEW_NAMESPACE
        );
    }

    /**
     * 注册钩子（ServiceProvider 通道）
     *
     * 系统通道（AppManagerService::registerAppHooks）在应用启用时已注册过，
     * 此处按 app_id 去重，避免回调重复执行。
     */
    protected function registerHooks(): void
    {
        try {
            /** @var HookManager $hookManager */
            $hookManager = $this->app->make(HookManager::class);

            $registered = $hookManager->getRegisteredHooks(self::APP_ID);
            if (! empty($registered['actions']) || ! empty($registered['filters'])) {
                return; // 系统通道已注册，跳过
            }

            (new Hooks())->register($hookManager);
        } catch (\Throwable $e) {
            // 钩子注册失败不应阻断应用启动
        }
    }

    /**
     * 读取应用配置项（路由注册阶段专用）
     *
     * 直接查库而非走缓存：路由注册必须读取实时值，配置保存后立即反映到路由表。
     *
     * @param string $name    配置项 name（不含前缀）
     * @param string $default 读取失败或无记录时的默认值
     */
    protected function getConfigValue(string $name, string $default = ''): string
    {
        try {
            $value = ConfigItem::where('code', self::CONFIG_CODE_PREFIX . $name)->value('value');

            return ($value === null || $value === '') ? $default : (string) $value;
        } catch (\Throwable $e) {
            // 安装前 config_items 表可能不存在，静默降级到默认值
            return $default;
        }
    }
}
