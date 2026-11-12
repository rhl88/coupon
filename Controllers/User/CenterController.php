<?php

namespace App\Apps\CmsproCoupon\Controllers\User;

use App\Apps\CmsproCoupon\Services\IssueService;
use App\Apps\CmsproCoupon\Services\UserCouponService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 用户端 · 领券中心控制器
 *
 * 职责：领券中心页面、可领取活动列表、领取优惠券、兑换码兑换。
 * 用户端不走 admin_permissions 体系，按数据归属隔离（user_id 取自当前登录用户）。
 */
class CenterController extends Controller
{
    public function __construct(
        protected UserCouponService $couponService,
        protected IssueService $issueService,
    ) {
    }

    /**
     * 领券中心页面
     */
    public function index(): View
    {
        return view('cmspro.coupon::User.center.index');
    }

    /**
     * 可领取的券活动列表（含当前用户领取状态）
     */
    public function activities(): JsonResponse
    {
        return response()->json(ApiResponse::success(
            $this->couponService->receivableActivities($this->userId())
        ));
    }

    /**
     * 领取优惠券
     *
     * @param int $id 券活动ID
     */
    public function receive(int $id): JsonResponse
    {
        return response()->json($this->issueService->receive($this->userId(), $id));
    }

    /**
     * 兑换码兑换
     */
    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:50',
        ], [], ['code' => '兑换码']);

        return response()->json($this->issueService->redeem($this->userId(), (string) $data['code']));
    }

    /**
     * 当前登录用户ID（auth:web 中间件已保证登录）
     */
    protected function userId(): int
    {
        return (int) auth('web')->id();
    }
}
