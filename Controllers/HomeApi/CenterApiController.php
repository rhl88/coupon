<?php

namespace App\Apps\CmsproCoupon\Controllers\HomeApi;

use App\Apps\CmsproCoupon\Services\UserCouponService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 前端 · 领券中心公开 API 控制器
 *
 * 无需登录即可浏览可领取的券活动（中间件仅 ['web','throttle:60,1']）。
 * 已登录用户会附带个人领取状态（已领数量 / 是否可领 / 不可领原因）。
 */
class CenterApiController extends Controller
{
    public function __construct(protected UserCouponService $couponService)
    {
    }

    /**
     * 可领取的券活动列表
     *
     * GET /api/cmspro/coupon/center/activities
     */
    public function activities(): JsonResponse
    {
        $userId = auth('web')->id();

        return response()->json(ApiResponse::success([
            'receive_enabled' => cmspro_coupon_config_bool('receive_enabled', true),
            'is_login' => $userId !== null,
            'login_url' => '/user/login',
            'activities' => $this->couponService->receivableActivities($userId === null ? null : (int) $userId),
        ]));
    }}
