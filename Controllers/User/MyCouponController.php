<?php

namespace App\Apps\CmsproCoupon\Controllers\User;

use App\Apps\CmsproCoupon\Services\UserCouponService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 用户端 · 我的优惠券控制器
 *
 * 职责：我的券页面、分页列表、状态统计、单券详情。
 * 所有查询强制限定当前登录用户，杜绝越权查看他人券。
 */
class MyCouponController extends Controller
{
    public function __construct(protected UserCouponService $couponService)
    {
    }

    /**
     * 我的优惠券页面
     */
    public function index(): View
    {
        return view('cmspro.coupon::User.my-coupons.index');
    }

    /**
     * 我的优惠券列表数据
     */
    public function list(Request $request): JsonResponse
    {
        return response()->json($this->couponService->getUserList($this->userId(), [
            'status' => $request->input('status'),
            'expiring' => $request->input('expiring'),
            'per_page' => $request->input('limit'),
        ]));
    }

    /**
     * 我的优惠券各状态计数（页面顶部统计卡）
     */
    public function stats(): JsonResponse
    {
        return response()->json($this->couponService->getUserStats($this->userId()));
    }

    /**
     * 单券详情
     *
     * @param int $id 用户券ID
     */
    public function show(int $id): JsonResponse
    {
        return response()->json($this->couponService->getUserCouponDetail($this->userId(), $id));
    }

    /**
     * 当前登录用户ID
     */
    protected function userId(): int
    {
        return (int) auth('web')->id();
    }
}
