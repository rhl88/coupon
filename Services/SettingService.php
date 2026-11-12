<?php

namespace App\Apps\CmsproCoupon\Services;

use App\Models\ConfigItem;
use Illuminate\Support\Facades\Artisan;

/**
 * 优惠券设置服务
 *
 * 配置项结构（group_id/name/type/tips）由 Install::seedDefaultConfigs() 按
 * manifest.json 三向同步维护；本服务只负责「读值」与「写值」，
 * 写入时仅更新 value 字段，不覆盖结构，与三向同步规则不冲突。
 */
class SettingService
{
    /**
     * 配置项 code 前缀（app_ + 应用id点转下划线 + _）
     */
    private const CONFIG_PREFIX = 'app_cmspro_coupon_';

    /**
     * 允许通过后台设置页更新的配置项白名单
     *
     * 与 manifest.json config_groups 声明保持一致，防止任意配置被篡改。
     */
    public const ALLOWED_KEYS = [
        'access_mode',
        'access_path',
        'access_domain',
        'receive_enabled',
        'coupon_no_prefix',
        'code_prefix',
        'page_size',
        'multi_use_enabled',
        'revoke_enabled',
        'expire_scan_enabled',
        'expire_notify_days',
        'discount_round_mode',
    ];

    /**
     * 访问模式白名单
     */
    public const ACCESS_MODES = ['none', 'path', 'domain'];

    /**
     * 系统保留的顶级路径前缀，路径访问模式不可占用
     */
    public const RESERVED_PATHS = ['admin', 'user', 'api', 'install'];

    /**
     * 读取全部设置值（白名单内，DB 无记录时回退应用默认配置）
     *
     * @return array<string,string>
     */
    public function loadSettings(): array
    {
        $dbValues = ConfigItem::whereIn(
            'code',
            array_map(fn (string $key) => self::CONFIG_PREFIX . $key, self::ALLOWED_KEYS)
        )->pluck('value', 'code');

        $settings = [];

        foreach (self::ALLOWED_KEYS as $key) {
            $value = $dbValues[self::CONFIG_PREFIX . $key] ?? null;
            $settings[$key] = ($value === null)
                ? (string) config('apps.cmspro.coupon.' . $key, '')
                : (string) $value;
        }

        return $settings;
    }

    /**
     * 校验并归一化访问设置
     *
     * 访问模式切换不属于安装/启用时机，框架 home_routes 冲突检测不覆盖，
     * 因此这里做应用级校验（必填/格式/保留路径），校验不通过直接拒绝保存。
     *
     * @param array<string,mixed> $data 引用传递：内部归一化 access_path/access_domain 存储值
     * @return array{code:int,msg:string}|null 错误信息；null 表示校验通过
     */
    public function validateAccessSettings(array &$data): ?array
    {
        $mode = trim((string) ($data['access_mode'] ?? 'path'));

        if (! in_array($mode, self::ACCESS_MODES, true)) {
            return ['code' => 40001, 'msg' => '访问模式取值无效'];
        }

        if ($mode === 'path') {
            $path = strtolower(trim((string) ($data['access_path'] ?? '')));

            if ($path === '') {
                return ['code' => 40001, 'msg' => '路径前缀不能为空'];
            }

            if (! preg_match('/^[a-z0-9][a-z0-9\-]*$/', $path)) {
                return ['code' => 40001, 'msg' => '路径前缀仅限小写字母、数字和短横线，且不能以短横线开头'];
            }

            if (in_array($path, self::RESERVED_PATHS, true)) {
                return ['code' => 40001, 'msg' => '路径前缀「' . $path . '」为系统保留路径，请更换'];
            }

            $data['access_path'] = $path;
        }

        if ($mode === 'domain') {
            $domainConfig = trim((string) ($data['access_domain'] ?? ''));

            if ($domainConfig === '') {
                return ['code' => 40001, 'msg' => '子域名模式必须填写绑定域名'];
            }

            $domains = array_filter(array_map('trim', explode(',', $domainConfig)));
            foreach ($domains as $domain) {
                if (! preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/i', $domain)) {
                    return ['code' => 40001, 'msg' => '域名「' . $domain . '」格式不正确'];
                }
            }

            $data['access_domain'] = implode(',', $domains);
        }

        return null;
    }

    /**
     * 校验业务规则类设置项取值
     *
     * @param array<string,mixed> $data 引用传递：数字型配置归一化为字符串
     * @return array{code:int,msg:string}|null 错误信息；null 表示校验通过
     */
    public function validateRuleSettings(array &$data): ?array
    {
        if (isset($data['coupon_no_prefix'])) {
            $prefix = strtoupper(trim((string) $data['coupon_no_prefix']));

            if ($prefix === '' || ! preg_match('/^[A-Z]{2,8}$/', $prefix)) {
                return ['code' => 40001, 'msg' => '券号前缀必须为2-8位大写字母'];
            }

            $data['coupon_no_prefix'] = $prefix;
        }

        if (isset($data['code_prefix'])) {
            $prefix = strtoupper(trim((string) $data['code_prefix']));

            if ($prefix !== '' && ! preg_match('/^[A-Z0-9]{1,8}$/', $prefix)) {
                return ['code' => 40001, 'msg' => '兑换码前缀仅限1-8位大写字母或数字'];
            }

            $data['code_prefix'] = $prefix;
        }

        if (isset($data['page_size'])) {
            $pageSize = (int) $data['page_size'];

            if ($pageSize < 10 || $pageSize > 100) {
                return ['code' => 40001, 'msg' => '每页条数必须在10-100之间'];
            }

            $data['page_size'] = (string) $pageSize;
        }

        if (isset($data['expire_notify_days'])) {
            $days = (int) $data['expire_notify_days'];

            if ($days < 0 || $days > 30) {
                return ['code' => 40001, 'msg' => '到期提醒天数必须在0-30之间'];
            }

            $data['expire_notify_days'] = (string) $days;
        }

        if (isset($data['discount_round_mode'])
            && ! in_array((string) $data['discount_round_mode'], ['floor', 'round'], true)) {
            return ['code' => 40001, 'msg' => '优惠额取整方式取值无效'];
        }

        return null;
    }

    /**
     * 保存设置（仅更新白名单内配置项的 value）
     *
     * @param array<string,mixed> $data 已通过校验的设置项
     * @return int 实际写入的配置项数量
     */
    public function saveSettings(array $data): int
    {
        $saved = 0;

        foreach ($data as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                continue; // 白名单外的键直接忽略
            }

            $item = ConfigItem::where('code', self::CONFIG_PREFIX . $key)->first();

            if ($item === null) {
                continue; // 结构由 Install 维护，不存在则跳过（升级后会补齐）
            }

            if ((string) $item->value !== (string) $value) {
                $item->update(['value' => (string) $value]);
            }

            $saved++;
        }

        // 清配置缓存 + 路由缓存（访问模式变更需重建路由表）
        cmspro_coupon_forget_config_cache();
        $this->clearRouteCache();

        return $saved;
    }

    /**
     * 清除路由缓存（访问模式切换后路由注册策略变化，必须重建）
     */
    protected function clearRouteCache(): void
    {
        try {
            Artisan::call('route:clear');
        } catch (\Throwable $e) {
            // 路由缓存清理失败不阻断保存（未缓存路由时无影响）
        }
    }
}
