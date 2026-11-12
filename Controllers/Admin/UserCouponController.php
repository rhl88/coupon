<?php

namespace App\Apps\CmsproCoupon\Controllers\Admin;

use App\Apps\CmsproCoupon\Controllers\AdminBaseController;
use App\Apps\CmsproCoupon\Services\UserCouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 后台 · 用户优惠券管理控制器
 *
 * 职责：全量用户券查询、作废（单张 / 批量）。
 * 所有查询走 UserCouponService，控制器保持轻量。
 */
class UserCouponController extends AdminBaseController
{
    public function __construct(protected UserCouponService $service)
    {
    }

    /**
     * 用户优惠券列表页
     */
    public function index(): View
    {
        return view('cmspro.coupon::Admin.user-coupons.index');
    }

    /**
     * 用户优惠券列表数据
     */
    public function list(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.usercoupon.view')) {
            return $this->forbidden('无权查看用户优惠券');
        }

        return $this->respond($this->service->getList([
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
            'coupon_type' => $request->input('coupon_type'),
            'activity_id' => $request->input('activity_id'),
            'user_id' => $request->input('user_id'),
            'per_page' => $request->input('per_page'),
        ]));
    }

    /**
     * 作废单张用户优惠券
     */
    public function invalidate(int $id): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.usercoupon.view')) {
            return $this->forbidden('无权作废用户优惠券');
        }

        return $this->respond($this->service->invalidate($id));
    }

    /**
     * 批量作废用户优惠券
     */
    public function batchInvalidate(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.usercoupon.view')) {
            return $this->forbidden('无权作废用户优惠券');
        }

        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|min:1',
        ], [], ['ids' => '优惠券列表']);

        return $this->respond($this->service->batchInvalidate(array_map('intval', $data['ids'])));
    }
}
