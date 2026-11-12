<?php

namespace App\Apps\CmsproCoupon\Controllers\Admin;

use App\Apps\CmsproCoupon\Controllers\AdminBaseController;
use App\Apps\CmsproCoupon\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 后台 · 优惠券设置控制器
 *
 * 职责：渲染设置页、读取配置、保存配置（白名单 + 校验）。
 * 配置值读写全部走 SettingService，控制器只做权限校验与响应封装。
 */
class SettingController extends AdminBaseController
{
    public function __construct(protected SettingService $service)
    {
    }

    /**
     * 优惠券设置页
     */
    public function index(): View
    {
        return view('cmspro.coupon::Admin.settings.index', [
            'configs' => $this->service->loadSettings(),
        ]);
    }

    /**
     * 获取设置（API）
     */
    public function getSettings(): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.setting.manage')) {
            return $this->forbidden('无权查看优惠券设置');
        }

        return $this->success($this->service->loadSettings());
    }

    /**
     * 保存设置（API）
     *
     * 仅接受白名单内配置项；访问设置与规则设置分别校验，
     * 任一校验不通过直接拒绝保存并回显中文错误。
     */
    public function update(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.setting.manage')) {
            return $this->forbidden('无权修改优惠券设置');
        }

        $data = $request->only(SettingService::ALLOWED_KEYS);

        // 开关型配置归一化：checkbox 未勾选时不提交，需显式补 0
        foreach (['receive_enabled', 'multi_use_enabled', 'revoke_enabled', 'expire_scan_enabled'] as $switch) {
            if (array_key_exists($switch, $data)) {
                $data[$switch] = in_array((string) $data[$switch], ['1', 'true', 'on'], true) ? '1' : '0';
            }
        }

        // 访问设置校验（引用传递，内部归一化 path/domain 存储值）
        $accessError = $this->service->validateAccessSettings($data);
        if ($accessError !== null) {
            return $this->error($accessError['code'], $accessError['msg']);
        }

        // 规则设置校验
        $ruleError = $this->service->validateRuleSettings($data);
        if ($ruleError !== null) {
            return $this->error($ruleError['code'], $ruleError['msg']);
        }

        $this->service->saveSettings($data);

        return $this->success(null, '设置已保存');
    }

    /**
     * 校验路径前缀是否可用（前端实时校验用）
     */
    public function checkPath(Request $request): JsonResponse
    {
        $data = ['access_mode' => 'path', 'access_path' => (string) $request->input('access_path', '')];
        $error = $this->service->validateAccessSettings($data);

        if ($error === null) {
            return $this->success(['available' => true], '路径可用');
        }

        return $this->success(['available' => false, 'reason' => $error['msg']], $error['msg']);
    }
}
