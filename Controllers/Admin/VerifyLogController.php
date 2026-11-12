<?php

namespace App\Apps\CmsproCoupon\Controllers\Admin;

use App\Apps\CmsproCoupon\Controllers\AdminBaseController;
use App\Apps\CmsproCoupon\Services\RecordService;
use App\Apps\CmsproCoupon\Services\VerifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 后台 · 核销记录与发放批次控制器
 *
 * 职责：核销记录查询、撤销核销、发放批次查询与详情。
 */
class VerifyLogController extends AdminBaseController
{
    public function __construct(
        protected RecordService $recordService,
        protected VerifyService $verifyService,
    ) {
    }

    /**
     * 核销记录列表页
     */
    public function index(): View
    {
        return view('cmspro.coupon::Admin.verify-logs.index');
    }

    /**
     * 发放批次列表页
     */
    public function batches(): View
    {
        return view('cmspro.coupon::Admin.issue-batches.index');
    }

    /**
     * 核销记录列表数据
     */
    public function list(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.verify.view')) {
            return $this->forbidden('无权查看核销记录');
        }

        return $this->respond($this->recordService->getVerifyLogs([
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
            'caller_app_id' => $request->input('caller_app_id'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            'per_page' => $request->input('per_page'),
        ]));
    }

    /**
     * 撤销核销
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.verify.revoke')) {
            return $this->forbidden('无权撤销核销');
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
        ], [], ['reason' => '撤销原因']);

        $adminId = auth('admin')->id();

        return $this->respond($this->verifyService->revoke(
            $id,
            (string) ($data['reason'] ?? ''),
            $adminId === null ? null : (int) $adminId
        ));
    }

    /**
     * 发放批次列表数据
     */
    public function batchList(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.issue.manage')) {
            return $this->forbidden('无权查看发放批次');
        }

        return $this->respond($this->recordService->getIssueBatches([
            'keyword' => $request->input('keyword'),
            'target_type' => $request->input('target_type'),
            'per_page' => $request->input('per_page'),
        ]));
    }

    /**
     * 发放批次详情（含失败明细）
     */
    public function batchDetail(int $id): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.issue.manage')) {
            return $this->forbidden('无权查看发放批次详情');
        }

        return $this->respond($this->recordService->getBatchDetail($id));
    }

    /**
     * 概览统计（后台设置页 / 仪表盘卡片用）
     */
    public function overview(): JsonResponse
    {
        return $this->respond($this->recordService->getOverview());
    }
}
