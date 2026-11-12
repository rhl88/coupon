<?php

/**
 * 优惠券应用 · 应用级辅助函数
 *
 * 由 ServiceProvider::register() 通过 require_once 加载。
 * 所有函数均用 function_exists 包裹保证重复加载幂等，
 * 函数名统一带 cmspro_coupon_ 前缀，避免与系统或其他应用冲突。
 */

use App\Models\ConfigItem;
use Illuminate\Support\Facades\Cache;

if (! function_exists('cmspro_coupon_config')) {
    /**
     * 读取本应用配置值（优先数据库 config_items，回退 Config/coupon.php）
     *
     * 应用配置项统一以 app_cmspro_coupon_ 为 code 前缀，
     * 后台设置页写库后即时生效；数据库无记录时回退到应用默认配置文件。
     *
     * @param string $key     配置键名（不含前缀），如 receive_enabled
     * @param mixed  $default 兜底默认值
     * @return mixed 配置值
     */
    function cmspro_coupon_config(string $key, mixed $default = null): mixed
    {
        $cacheKey = 'cmspro_coupon:config:' . $key;

        return Cache::remember($cacheKey, 3600, function () use ($key, $default) {
            try {
                $value = ConfigItem::where('code', 'app_cmspro_coupon_' . $key)->value('value');

                if ($value !== null && $value !== '') {
                    return $value;
                }
            } catch (\Throwable $e) {
                // 数据库不可用（安装前、迁移中）时静默降级到配置文件
            }

            return config('apps.cmspro.coupon.' . $key, $default);
        });
    }
}

if (! function_exists('cmspro_coupon_config_bool')) {
    /**
     * 读取开关型配置并转为布尔值
     *
     * 配置项以字符串存储（'1'/'0'），业务层需要布尔判断时统一走本函数。
     *
     * @param string $key     配置键名（不含前缀）
     * @param bool   $default 兜底默认值
     */
    function cmspro_coupon_config_bool(string $key, bool $default = false): bool
    {
        $value = cmspro_coupon_config($key, $default ? '1' : '0');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }
}

if (! function_exists('cmspro_coupon_forget_config_cache')) {
    /**
     * 清除本应用配置缓存
     *
     * 后台设置页保存配置后调用，确保 cmspro_coupon_config() 立即读到新值。
     *
     * @param array<int,string>|null $keys 需要清理的配置键名列表；null 表示清理全部已知键
     */
    function cmspro_coupon_forget_config_cache(?array $keys = null): void
    {
        $keys = $keys ?? [
            'access_mode', 'access_path', 'access_domain',
            'receive_enabled', 'coupon_no_prefix', 'code_prefix', 'page_size',
            'multi_use_enabled', 'revoke_enabled', 'expire_scan_enabled',
            'expire_notify_days', 'discount_round_mode',
        ];

        foreach ($keys as $key) {
            Cache::forget('cmspro_coupon:config:' . $key);
        }
    }
}

if (! function_exists('cmspro_coupon_url')) {
    /**
     * 生成本应用前端（home 终端）URL
     *
     * 视图中禁止硬编码前端前缀，必须通过本函数生成，
     * 以适配 none / path / domain 三种访问模式。
     *
     * @param string $path 相对路径（不含前缀），如 center
     * @return string 可直接用于 href 的 URL；none 模式返回用户中心地址兜底
     */
    function cmspro_coupon_url(string $path = ''): string
    {
        $accessMode = (string) cmspro_coupon_config('access_mode', 'path');

        if ($accessMode === 'none') {
            // 无前端页面模式：降级到用户中心领券页
            return '/user/cmspro/coupon/center';
        }

        if ($accessMode === 'domain') {
            $domainConfig = (string) cmspro_coupon_config('access_domain', '');
            $domains = array_values(array_filter(array_map('trim', explode(',', $domainConfig))));

            // 优先使用当前请求匹配的域名，否则取配置中的第一个
            $currentHost = request()?->getHost() ?? '';
            $domain = in_array($currentHost, $domains, true) ? $currentHost : ($domains[0] ?? '');

            if ($domain !== '') {
                $scheme = request()?->isSecure() ? 'https' : 'http';
                $base = rtrim($scheme . '://' . $domain, '/');

                return $path !== '' ? $base . '/' . ltrim($path, '/') : $base;
            }

            // 域名未配置时降级到 path 模式
        }

        $prefix = trim((string) cmspro_coupon_config('access_path', 'coupon'), '/');
        $base = '/' . $prefix;

        return $path !== '' ? $base . '/' . ltrim($path, '/') : $base;
    }
}

if (! function_exists('cmspro_coupon_main_url')) {
    /**
     * 生成主站（主域名）URL
     *
     * 子域名模式下前端页面跳转用户中心/后台时必须回到主域名，
     * 否则会被子域名 catch-all 路由拦截返回 404。
     *
     * @param string $path 主站相对路径，如 user/cmspro/coupon/my-coupons
     * @return string 完整 URL（子域名模式）或相对路径（其他模式）
     */
    function cmspro_coupon_main_url(string $path = ''): string
    {
        $accessMode = (string) cmspro_coupon_config('access_mode', 'path');
        $path = '/' . ltrim($path, '/');

        if ($accessMode !== 'domain') {
            return $path;
        }

        $mainUrl = rtrim((string) config('app.url', ''), '/');

        return $mainUrl !== '' ? $mainUrl . $path : $path;
    }
}

if (! function_exists('cmspro_coupon_amount_format')) {
    /**
     * 金额展示格式化：去掉多余小数零（10.00 → 10，10.50 → 10.5）
     *
     * 统一供 Service / 视图使用，避免各处 number_format 参数不一致。
     */
    function cmspro_coupon_amount_format(mixed $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }
}
