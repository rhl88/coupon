<?php

namespace App\Apps\CmsproCoupon\Services;

use App\Apps\CmsproCoupon\Models\CouponActivity;
use App\Apps\CmsproCoupon\Models\RedeemCode;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\DB;

/**
 * 兑换码管理服务（后台）
 *
 * 职责：批量生成兑换码、列表查询、作废、CSV 导出。
 * 生成时以数据库唯一索引为最终防线，单批内先本地去重再查库去重。
 */
class RedeemCodeService
{
    /**
     * 单批生成数量上限（防止一次生成过多拖垮请求）
     */
    public const BATCH_MAX = 5000;

    /**
     * 兑换码分页列表
     *
     * @param array<string,mixed> $params keyword/activity_id/status/batch_no/per_page
     * @return array<string,mixed>
     */
    public function getList(array $params): array
    {
        $query = RedeemCode::query()->with('activity:id,title');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('code', 'like', "%{$keyword}%");
        }

        if (isset($params['activity_id']) && $params['activity_id'] !== '') {
            $query->where('activity_id', (int) $params['activity_id']);
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }

        $batchNo = trim((string) ($params['batch_no'] ?? ''));
        if ($batchNo !== '') {
            $query->where('batch_no', $batchNo);
        }

        $perPage = (int) ($params['per_page'] ?? cmspro_coupon_config('page_size', 20));
        $items = $query->orderBy('id', 'desc')->paginate(max(1, min(100, $perPage)));

        $items->through(fn (RedeemCode $item) => $this->formatCode($item));

        return ApiResponse::paginate($items);
    }

    /**
     * 批量生成兑换码
     *
     * @param int         $activityId 券活动ID
     * @param int         $count      生成数量（1-5000）
     * @param int|null    $adminId    操作管理员ID
     * @return array<string,mixed> ApiResponse 结构，data 含 batch_no 与生成数量
     */
    public function generate(int $activityId, int $count, ?int $adminId): array
    {
        $activity = CouponActivity::find($activityId);

        if ($activity === null) {
            return ApiResponse::error(40401, '券活动不存在');
        }

        if (! $activity->supportReceive(CouponActivity::RECEIVE_CODE)) {
            return ApiResponse::error(40003, '该券活动未开启兑换码发放方式');
        }

        if (! $activity->isOnShelf()) {
            return ApiResponse::error(40003, '券活动已下架，请先上架后再生成兑换码');
        }

        if ($count < 1 || $count > self::BATCH_MAX) {
            return ApiResponse::error(40001, '生成数量必须在1-' . self::BATCH_MAX . '之间');
        }

        $prefix = strtoupper(trim((string) cmspro_coupon_config('code_prefix', '')));
        $batchNo = 'RC' . now()->format('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $now = now()->format('Y-m-d H:i:s');

        $rows = $this->buildCodeRows($activityId, $count, $prefix, $batchNo, $adminId, $now);

        if (empty($rows)) {
            return ApiResponse::error(50001, '兑换码生成失败，请重试');
        }

        DB::transaction(function () use ($rows) {
            // 分片插入，避免单条 SQL 过大
            foreach (array_chunk($rows, 500) as $chunk) {
                RedeemCode::insert($chunk);
            }
        });

        return ApiResponse::success([
            'batch_no' => $batchNo,
            'count' => count($rows),
        ], '成功生成 ' . count($rows) . ' 个兑换码');
    }

    /**
     * 构建兑换码数据行（含批内去重与查库去重）
     *
     * @return array<int,array<string,mixed>>
     */
    protected function buildCodeRows(int $activityId, int $count, string $prefix, string $batchNo, ?int $adminId, string $now): array
    {
        $rows = [];
        $seen = [];
        // 唯一索引冲突时允许的重试次数（碰撞概率极低，10 次足够）
        $maxAttempts = $count * 10 + 100;
        $attempts = 0;

        while (count($rows) < $count && $attempts < $maxAttempts) {
            $attempts++;
            $code = RedeemCode::generateCode(10, $prefix);

            if (isset($seen[$code])) {
                continue; // 批内重复
            }

            if (RedeemCode::where('code', $code)->exists()) {
                continue; // 库内重复
            }

            $seen[$code] = true;
            $rows[] = [
                'code' => $code,
                'activity_id' => $activityId,
                'batch_no' => $batchNo,
                'status' => RedeemCode::STATUS_UNUSED,
                'admin_id' => $adminId,
                'create_time' => $now,
                'update_time' => $now,
            ];
        }

        return $rows;
    }

    /**
     * 作废兑换码（单个）
     *
     * 已使用的兑换码不可作废（券已发出，应走撤销核销流程）。
     *
     * @return array<string,mixed>
     */
    public function voidCode(int $id): array
    {
        $code = RedeemCode::find($id);

        if ($code === null) {
            return ApiResponse::error(40401, '兑换码不存在');
        }

        if ((int) $code->status === RedeemCode::STATUS_USED) {
            return ApiResponse::error(40003, '该兑换码已被使用，无法作废');
        }

        $code->update(['status' => RedeemCode::STATUS_VOID]);

        return ApiResponse::success(null, '已作废');
    }

    /**
     * 按批次作废未使用的兑换码
     *
     * @return array<string,mixed> data 含作废数量
     */
    public function voidBatch(string $batchNo): array
    {
        if (trim($batchNo) === '') {
            return ApiResponse::error(40001, '批次号不能为空');
        }

        $affected = RedeemCode::where('batch_no', trim($batchNo))
            ->where('status', RedeemCode::STATUS_UNUSED)
            ->update(['status' => RedeemCode::STATUS_VOID, 'update_time' => now()->format('Y-m-d H:i:s')]);

        return ApiResponse::success(['voided' => $affected], "已作废 {$affected} 个兑换码");
    }

    /**
     * 批量作废（按ID）
     *
     * @param array<int,int> $ids
     * @return array<string,mixed>
     */
    public function batchVoid(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            return ApiResponse::error(40001, '请选择要作废的兑换码');
        }

        $affected = RedeemCode::whereIn('id', $ids)
            ->where('status', RedeemCode::STATUS_UNUSED)
            ->update(['status' => RedeemCode::STATUS_VOID, 'update_time' => now()->format('Y-m-d H:i:s')]);

        return ApiResponse::success(['voided' => $affected], "已作废 {$affected} 个兑换码");
    }

    /**
     * 查询指定批次全部兑换码（CSV 导出用）
     *
     * @return array<int,array<string,mixed>>
     */
    public function exportRows(string $batchNo): array
    {
        return RedeemCode::with('activity:id,title')
            ->where('batch_no', trim($batchNo))
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (RedeemCode $item) => $this->formatCode($item))
            ->all();
    }

    /**
     * 格式化兑换码输出
     *
     * @return array<string,mixed>
     */
    protected function formatCode(RedeemCode $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'activity_id' => $item->activity_id,
            'activity_title' => $item->activity?->title ?? '-',
            'batch_no' => (string) $item->batch_no,
            'status' => (int) $item->status,
            'status_name' => RedeemCode::statusMap()[(int) $item->status] ?? '未知',
            'user_id' => $item->user_id,
            'redeem_time' => $item->redeem_time?->format('Y-m-d H:i:s'),
            'create_time' => $item->create_time?->format('Y-m-d H:i:s'),
        ];
    }
}
