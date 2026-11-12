<?php

namespace App\Apps\CmsproCoupon\Controllers\HomeApi;

use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Apps\CmsproCoupon\Models\VerifyLog;
use App\Apps\CmsproCoupon\Services\VerifyService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 前端 · 核销 / 查询 API 控制器
 *
 * 面向其他应用与前端页面的用户态核销入口（中间件 auth:web + throttle:60,1）。
 * 业务逻辑全部下沉到 VerifyService，控制器只做参数校验、用户态注入与响应封装。
 *
 * 其他应用服务端集成建议：直接在 PHP 层注入 VerifyService 调用 preview()/verify()，
 * 省去 HTTP 往返与鉴权开销（详见扩展指南）。
 */
class VerifyApiController extends Controller
{
    public function __construct(protected VerifyService $verifyService)
    {
    }

    /**
     * 查询当前用户在指定订单金额下的可用券
     *
     * GET /api/cmspro/coupon/available?order_amount=199.00
     */
    public function available(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_amount' => 'required|numeric|min:0.01|max:99999999',
        ], [], ['order_amount' => '订单金额']);

        $coupons = $this->verifyService->availableCoupons(
            $this->userId(),
            (float) $data['order_amount']
        );

        return response()->json(ApiResponse::success($coupons));
    }

    /**
     * 核销前试算
     *
     * POST /api/cmspro/coupon/preview
     * body: { order_amount: 199.00, coupon_ids: [12, 13] }
     * coupon_ids 为空时自动选最优券（或多券模式下的最优组合）。
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_amount' => 'required|numeric|min:0.01|max:99999999',
            'coupon_ids' => 'nullable|array',
            'coupon_ids.*' => 'integer|min:1',
        ], [], [
            'order_amount' => '订单金额',
            'coupon_ids' => '优惠券',
        ]);

        return response()->json($this->verifyService->preview(
            $this->userId(),
            (float) $data['order_amount'],
            array_map('intval', $data['coupon_ids'] ?? [])
        ));
    }

    /**
     * 核销优惠券
     *
     * POST /api/cmspro/coupon/verify
     * body: { order_amount: 199.00, order_no: 'PS2026...', coupon_ids: [12] }
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_no' => 'required|string|max:64',
            'order_amount' => 'required|numeric|min:0.01|max:99999999',
            'coupon_ids' => 'required|array|min:1',
            'coupon_ids.*' => 'integer|min:1',
        ], [], [
            'order_no' => '订单号',
            'order_amount' => '订单金额',
            'coupon_ids' => '优惠券',
        ]);

        $userId = $this->userId();

        return response()->json($this->verifyService->verify(
            $userId,
            array_map('intval', $data['coupon_ids']),
            (string) $data['order_no'],
            (float) $data['order_amount'],
            [
                // 前端 API 核销，caller_app_id 留空；操作人为用户本人
                'operator_type' => VerifyLog::OPERATOR_USER,
                'operator_id' => $userId,
            ]
        ));
    }

    /**
     * 按券号查询单券状态（收银台扫码核销场景）
     *
     * GET /api/cmspro/coupon/coupon/{couponNo}
     */
    public function showByNo(string $couponNo): JsonResponse
    {
        $couponNo = trim($couponNo);

        if ($couponNo === '') {
            return response()->json(ApiResponse::error(40001, '券号不能为空'));
        }

        $coupon = UserCoupon::where('coupon_no', $couponNo)
            ->where('user_id', $this->userId())
            ->first();

        if ($coupon === null) {
            return response()->json(ApiResponse::error(40401, '优惠券不存在'));
        }

        return response()->json(ApiResponse::success($this->verifyService->formatCoupon($coupon)));
    }

    /**
     * 当前登录用户ID（auth:web 中间件已保证登录）
     */
    protected function userId(): int
    {
        return (int) auth('web')->id();
    }
}
