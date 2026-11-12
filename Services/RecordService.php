<?php

namespace App\Apps\CmsproCoupon\Services;

use App\Apps\CmsproCoupon\Models\IssueBatch;
use App\Apps\CmsproCoupon\Models\VerifyLog;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;

/**
 * 记录查询服务
 *
 * 面向后台的发放批次与核销记录只读查询、统计。
 */
class RecordService
{
    /**
     * 核销记录分页列表
     *
     * @param array<string,mixed> $params keyword/status/caller_app_id/start_time/end_time/per_page
     * @return array<string,mixed>
     */
    public function getVerifyLogs(array $params): array
    {
        $query = VerifyLog::query()->with('user:id,username,nickname');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('coupon_no', 'like', "%{$keyword}%")
                    ->orWhere('order_no', 'like', "%{$keyword}%");
            });
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }

        if (isset($params['caller_app_id']) && $params['caller_app_id'] !== '') {
            $query->where('caller_app_id', trim((string) $params['caller_app_id']));
        }

        $startTime = trim((string) ($params['start_time'] ?? ''));
        if ($startTime !== '') {
            $query->where('verify_time', '>=', $startTime . ' 00:00:00');
        }

        $endTime = trim((string) ($params['end_time'] ?? ''));
        if ($endTime !== '') {
            $query->where('verify_time', '<=', $endTime . ' 23:59:59');
        }

        $perPage = (int) ($params['per_page'] ?? cmspro_coupon_config('page_size', 20));
        $items = $query->orderBy('id', 'desc')->paginate(max(1, min(100, $perPage)));

        $items->through(fn (VerifyLog $item) => $this->formatVerifyLog($item));

        return ApiResponse::paginate($items);
    }

    /**
     * 发放批次分页列表
     *
     * @param array<string,mixed> $params keyword/target_type/per_page
     * @return array<string,mixed>
     */
    public function getIssueBatches(array $params): array
    {
        $query = IssueBatch::query();

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('batch_no', 'like', "%{$keyword}%")
                    ->orWhere('activity_title', 'like', "%{$keyword}%");
            });
        }

        if (isset($params['target_type']) && $params['target_type'] !== '') {
            $query->where('target_type', (int) $params['target_type']);
        }

        $perPage = (int) ($params['per_page'] ?? cmspro_coupon_config('page_size', 20));
        $items = $query->orderBy('id', 'desc')->paginate(max(1, min(100, $perPage)));

        $items->through(fn (IssueBatch $item) => $this->formatBatch($item));

        return ApiResponse::paginate($items);
    }

    /**
     * 发放批次详情（含失败明细）
     *
     * @return array<string,mixed>
     */
    public function getBatchDetail(int $id): array
    {
        $batch = IssueBatch::find($id);

        if ($batch === null) {
            return ApiResponse::error(40401, '发放批次不存在');
        }

        $formatted = $this->formatBatch($batch);
        $formatted['fail_detail'] = $batch->fail_detail ?? [];
        $formatted['remark'] = (string) $batch->remark;

        return ApiResponse::success($formatted);
    }

    /**
     * 后台首页概览统计
     *
     * @return array<string,mixed>
     */
    public function getOverview(): array
    {
        $todayStart = now()->startOfDay()->format('Y-m-d H:i:s');

        return ApiResponse::success([
            'verify_total' => VerifyLog::where('status', VerifyLog::STATUS_VERIFIED)->count(),
            'verify_today' => VerifyLog::where('status', VerifyLog::STATUS_VERIFIED)
                ->where('verify_time', '>=', $todayStart)
                ->count(),
            'discount_total' => (float) VerifyLog::where('status', VerifyLog::STATUS_VERIFIED)->sum('discount_amount'),
            'discount_today' => (float) VerifyLog::where('status', VerifyLog::STATUS_VERIFIED)
                ->where('verify_time', '>=', $todayStart)
                ->sum('discount_amount'),
            'batch_total' => IssueBatch::count(),
        ]);
    }

    /**
     * 格式化核销记录
     *
     * @return array<string,mixed>
     */
    protected function formatVerifyLog(VerifyLog $log): array
    {
        return [
            'id' => $log->id,
            'coupon_no' => $log->coupon_no,
            'user_id' => $log->user_id,
            'username' => $log->user?->username ?? '-',
            'nickname' => $log->user?->nickname ?? '',
            'order_no' => $log->order_no,
            'order_amount' => (float) $log->order_amount,
            'discount_amount' => (float) $log->discount_amount,
            'caller_app_id' => (string) $log->caller_app_id,
            'caller_text' => $log->caller_app_id !== '' && $log->caller_app_id !== null
                ? $log->caller_app_id
                : '前端核销',
            'operator_type' => (int) $log->operator_type,
            'operator_type_name' => VerifyLog::operatorTypeMap()[(int) $log->operator_type] ?? '未知',
            'status' => (int) $log->status,
            'status_name' => VerifyLog::statusMap()[(int) $log->status] ?? '未知',
            'verify_time' => $log->verify_time?->format('Y-m-d H:i:s'),
            'cancel_time' => $log->cancel_time?->format('Y-m-d H:i:s'),
            'cancel_reason' => (string) $log->cancel_reason,
            'remark' => (string) $log->remark,
        ];
    }

    /**
     * 格式化发放批次
     *
     * @return array<string,mixed>
     */
    protected function formatBatch(IssueBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'batch_no' => $batch->batch_no,
            'activity_id' => $batch->activity_id,
            'activity_title' => $batch->activity_title,
            'target_type' => (int) $batch->target_type,
            'target_type_name' => IssueBatch::targetTypeMap()[(int) $batch->target_type] ?? '未知',
            'total_count' => (int) $batch->total_count,
            'success_count' => (int) $batch->success_count,
            'fail_count' => (int) $batch->fail_count,
            'admin_id' => $batch->admin_id,
            'status' => (int) $batch->status,
            'create_time' => $batch->create_time?->format('Y-m-d H:i:s'),
        ];
    }
}
