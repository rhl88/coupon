<?php

namespace App\Apps\CmsproCoupon\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 优惠券应用 · 后台控制器基类
 *
 * 收敛后台控制器的公共能力：
 * - 权限校验（hasPermission）：系统 CheckPermission 中间件仅覆盖 api/admin/ 前缀的
 *   系统路由，应用自有权限码需在控制器内校验，与前端按钮权限形成双保险
 * - 统一响应封装（success / error / layuiTable）：避免各控制器重复拼装结构
 */
abstract class AdminBaseController extends Controller
{
    /**
     * 当前管理员是否拥有指定权限码
     *
     * @param string $code 权限码，如 cmspro.coupon.activity.create
     */
    protected function hasPermission(string $code): bool
    {
        $user = auth('admin')->user();

        if ($user === null) {
            return false;
        }

        return $user->hasPermission($code);
    }

    /**
     * 无权限响应（统一错误码 40301）
     *
     * @param string $message 面向管理员的中文提示
     */
    protected function forbidden(string $message = '无权执行该操作'): JsonResponse
    {
        return response()->json(ApiResponse::error(40301, $message));
    }

    /**
     * 成功响应
     *
     * @param mixed  $data    业务数据
     * @param string $message 提示文案
     */
    protected function success(mixed $data = null, string $message = 'success'): JsonResponse
    {
        return response()->json(ApiResponse::success($data, $message));
    }

    /**
     * 业务失败响应
     *
     * @param int    $code    业务错误码
     * @param string $message 提示文案
     */
    protected function error(int $code, string $message): JsonResponse
    {
        return response()->json(ApiResponse::error($code, $message));
    }

    /**
     * 透传 Service 返回的 ApiResponse 数组
     *
     * @param array<string,mixed> $result Service 层返回值
     */
    protected function respond(array $result): JsonResponse
    {
        return response()->json($result);
    }
}
