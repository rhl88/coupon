<?php

namespace App\Apps\CmsproCoupon\Services;

use App\Apps\CmsproCoupon\Models\CouponActivity;
use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 用户优惠券查询服务
 *
 * 面向后台（全量查询 + 作废）与用户端（按 user_id 隔离查询）。
 * 所有列表查询都做惰性过期判定，保证「已过期但状态未刷新」的券不会被误认为可用。
 */
class UserCouponService
{
    public function __construct(protected CouponRuleService $ruleService)
    {
    }

    /**
     * 后台 · 用户优惠券分页列表
     *
     * @param array<string,mixed> $params keyword/status/coupon_type/activity_id/user_id/per_page
     * @return array<string,mixed>
     */
    public function getList(array $params): array
    {
        $query = UserCoupon::query()->with('user:id,username,nickname');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('coupon_no', 'like', "%{$keyword}%")
                    ->orWhere('title', 'like', "%{$keyword}%")
                    ->orWhere('order_no', 'like', "%{$keyword}%");
            });
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }

        if (isset($params['coupon_type']) && $params['coupon_type'] !== '') {
            $query->where('coupon_type', (int) $params['coupon_type']);
        }

        if (isset($params['activity_id']) && $params['activity_id'] !== '') {
            $query->where('activity_id', (int) $params['activity_id']);
        }

        if (isset($params['user_id']) && $params['user_id'] !== '') {
            $query->where('user_id', (int) $params['user_id']);
        }

        $perPage = (int) ($params['per_page'] ?? cmspro_coupon_config('page_size', 20));
        $items = $query->orderBy('id', 'desc')->paginate(max(1, min(100, $perPage)));

        $items->through(fn (UserCoupon $item) => $this->formatForAdmin($item));

        return ApiResponse::paginate($items);
    }

    /**
     * 用户端 · 我的优惠券分页列表
     *
     * @param int                 $userId 当前登录用户ID（强制隔离）
     * @param array<string,mixed> $params status/expiring/per_page
     * @return array<string,mixed>
     */
    public function getUserList(int $userId, array $params): array
    {
        $query = UserCoupon::query()->where('user_id', $userId);

        $status = $params['status'] ?? '';

        if ($status === 'usable') {
            // 未使用且未过期（惰性判定）
            $query->usable();
        } elseif ($status === 'unused') {
            $query->where('status', UserCoupon::STATUS_UNUSED);
        } elseif ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }

        // 即将过期筛选
        if (isset($params['expiring']) && $params['expiring'] === '1') {
            $days = max(0, (int) cmspro_coupon_config('expire_notify_days', 3));
            if ($days > 0) {
                $deadline = now()->addDays($days)->endOfDay()->format('Y-m-d H:i:s');
                $query->where('status', UserCoupon::STATUS_UNUSED)
                    ->whereNotNull('valid_end_time')
                    ->whereBetween('valid_end_time', [now()->format('Y-m-d H:i:s'), $deadline]);
            }
        }

        $perPage = (int) ($params['per_page'] ?? 10);
        $items = $query->orderByRaw('CASE WHEN status = 0 THEN 0 ELSE 1 END')
            ->orderBy('valid_end_time', 'asc')
            ->orderBy('id', 'desc')
            ->paginate(max(1, min(100, $perPage)));

        $items->through(fn (UserCoupon $item) => $this->formatForUser($item));

        return ApiResponse::paginate($items);
    }

    /**
     * 用户端 · 我的优惠券各状态计数
     *
     * @return array<string,mixed>
     */
    public function getUserStats(int $userId): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $days = max(0, (int) cmspro_coupon_config('expire_notify_days', 3));

        $unusedQuery = fn () => UserCoupon::where('user_id', $userId)->where('status', UserCoupon::STATUS_UNUSED);

        $usable = (clone $unusedQuery())->where(function (Builder $q) use ($now) {
            $q->whereNull('valid_start_time')->orWhere('valid_start_time', '<=', $now);
        })->where(function (Builder $q) use ($now) {
            $q->whereNull('valid_end_time')->orWhere('valid_end_time', '>=', $now);
        })->count();

        $expiring = 0;
        if ($days > 0) {
            $deadline = now()->addDays($days)->endOfDay()->format('Y-m-d H:i:s');
            $expiring = (clone $unusedQuery())->whereNotNull('valid_end_time')
                ->whereBetween('valid_end_time', [$now, $deadline])
                ->count();
        }

        return ApiResponse::success([
            'usable' => $usable,
            'unused' => (clone $unusedQuery())->count(),
            'used' => UserCoupon::where('user_id', $userId)->where('status', UserCoupon::STATUS_USED)->count(),
            'expired' => UserCoupon::where('user_id', $userId)->where('status', UserCoupon::STATUS_EXPIRED)->count(),
            'expiring_soon' => $expiring,
        ]);
    }

    /**
     * 用户端 · 单券详情
     *
     * 强制校验券归属，越权访问返回 40401。
     *
     * @return array<string,mixed>
     */
    public function getUserCouponDetail(int $userId, int $id): array
    {
        $coupon = UserCoupon::where('id', $id)->where('user_id', $userId)->first();

        if ($coupon === null) {
            return ApiResponse::error(40401, '优惠券不存在');
        }

        return ApiResponse::success($this->formatForUser($coupon));
    }

    /**
     * 后台 · 作废用户优惠券
     *
     * 仅未使用的券可作废；已使用的券应走撤销核销流程。
     *
     * @return array<string,mixed>
     */
    public function invalidate(int $id): array
    {
        $coupon = UserCoupon::find($id);

        if ($coupon === null) {
            return ApiResponse::error(40401, '优惠券不存在');
        }

        if ((int) $coupon->status !== UserCoupon::STATUS_UNUSED) {
            return ApiResponse::error(40003, '仅未使用的优惠券可以作废');
        }

        $coupon->update(['status' => UserCoupon::STATUS_VOID]);

        return ApiResponse::success(null, '已作废');
    }

    /**
     * 后台 · 批量作废
     *
     * @param array<int,int> $ids
     * @return array<string,mixed>
     */
    public function batchInvalidate(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            return ApiResponse::error(40001, '请选择要作废的优惠券');
        }

        $affected = UserCoupon::whereIn('id', $ids)
            ->where('status', UserCoupon::STATUS_UNUSED)
            ->update(['status' => UserCoupon::STATUS_VOID, 'update_time' => now()->format('Y-m-d H:i:s')]);

        return ApiResponse::success(['invalidated' => $affected], "已作废 {$affected} 张优惠券");
    }

    /**
     * 领券中心 · 可领取的券活动列表（含当前用户领取状态）
     *
     * @param int|null $userId 前台用户ID，null 表示未登录（前端页可游客浏览）
     * @return array<int,array<string,mixed>>
     */
    public function receivableActivities(?int $userId): array
    {
        $activities = CouponActivity::receivable()
            ->orderBy('sort', 'asc')
            ->orderBy('id', 'desc')
            ->get();

        if ($activities->isEmpty()) {
            return [];
        }

        // 一次性取出当前用户在这些活动下的已领数量，避免 N+1 查询
        $receivedCounts = $this->loadReceivedCounts($userId, $activities->pluck('id')->all());

        $list = [];

        foreach ($activities as $activity) {
            $received = $receivedCounts[(int) $activity->id] ?? 0;
            $list[] = $this->formatActivityForCenter($activity, $received, $userId);
        }

        return $list;
    }

    /**
     * 统计用户在指定活动下的已领数量（作废券不计入）
     *
     * @param array<int,int> $activityIds
     * @return array<int,int> [activity_id => 已领数量]
     */
    protected function loadReceivedCounts(?int $userId, array $activityIds): array
    {
        if ($userId === null || empty($activityIds)) {
            return [];
        }

        /** @var Collection<int,object> $rows */
        $rows = UserCoupon::where('user_id', $userId)
            ->whereIn('activity_id', $activityIds)
            ->whereIn('status', [UserCoupon::STATUS_UNUSED, UserCoupon::STATUS_USED])
            ->selectRaw('activity_id, COUNT(*) as total')
            ->groupBy('activity_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->activity_id] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * 格式化领券中心的活动数据
     *
     * @return array<string,mixed>
     */
    protected function formatActivityForCenter(CouponActivity $activity, int $received, ?int $userId): array
    {
        $remain = $activity->getRemainStock();
        $perLimit = (int) $activity->per_user_limit;
        $supportUserReceive = $activity->supportReceive(CouponActivity::RECEIVE_USER);
        $receiveOpen = cmspro_coupon_config_bool('receive_enabled', true);

        // 不可领原因（前端按钮文案用，为空表示可领）
        $disabledReason = '';

        if (! $supportUserReceive) {
            $disabledReason = '不支持主动领取';
        } elseif (! $receiveOpen) {
            $disabledReason = '领取功能已关闭';
        } elseif ($userId === null) {
            $disabledReason = (int) $activity->receive_need_login === 1 ? '请先登录' : '';
        } elseif ($remain === 0) {
            $disabledReason = '已领完';
        } elseif ($perLimit > 0 && $received >= $perLimit) {
            $disabledReason = '已达领取上限';
        }

        return [
            'id' => $activity->id,
            'code' => $activity->code,
            'title' => $activity->title,
            'coupon_type' => (int) $activity->coupon_type,
            'coupon_type_name' => $activity->coupon_type_name,
            'threshold_amount' => (float) $activity->threshold_amount,
            'threshold_text' => $activity->threshold_text,
            'discount_amount' => (float) $activity->discount_amount,
            'discount_rate' => (float) $activity->discount_rate,
            'discount_text' => $activity->discount_text,
            'max_discount_amount' => (float) $activity->max_discount_amount,
            'description' => (string) $activity->description,
            'valid_type' => (int) $activity->valid_type,
            'valid_days' => (int) $activity->valid_days,
            'valid_text' => (int) $activity->valid_type === CouponActivity::VALID_RELATIVE
                ? '领取后 ' . $activity->valid_days . ' 天内有效'
                : trim(($activity->valid_start_time?->format('Y-m-d') ?? '') . ' 至 ' . ($activity->valid_end_time?->format('Y-m-d') ?? '')),
            'remain_stock' => $remain,
            'remain_stock_text' => $remain < 0 ? '不限量' : (string) $remain,
            'per_user_limit' => $perLimit,
            'received_count' => $received,
            'can_receive' => $disabledReason === '',
            'disabled_reason' => $disabledReason,
        ];
    }

    /**
     * 格式化后台列表输出
     *
     * @return array<string,mixed>
     */
    protected function formatForAdmin(UserCoupon $coupon): array
    {
        $effectiveStatus = $this->effectiveStatus($coupon);

        return [
            'id' => $coupon->id,
            'coupon_no' => $coupon->coupon_no,
            'activity_id' => $coupon->activity_id,
            'user_id' => $coupon->user_id,
            'username' => $coupon->user?->username ?? '-',
            'nickname' => $coupon->user?->nickname ?? '',
            'title' => $coupon->title,
            'coupon_type' => (int) $coupon->coupon_type,
            'coupon_type_name' => CouponActivity::couponTypeMap()[(int) $coupon->coupon_type] ?? '未知',
            'threshold_amount' => (float) $coupon->threshold_amount,
            'threshold_text' => (float) $coupon->threshold_amount <= 0 ? '无门槛' : '满' . cmspro_coupon_amount_format($coupon->threshold_amount) . '元可用',
            'discount_amount' => (float) $coupon->discount_amount,
            'discount_rate' => (float) $coupon->discount_rate,
            'max_discount_amount' => (float) $coupon->max_discount_amount,
            'valid_start_time' => $coupon->valid_start_time?->format('Y-m-d H:i:s'),
            'valid_end_time' => $coupon->valid_end_time?->format('Y-m-d H:i:s'),
            'source' => (int) $coupon->source,
            'source_name' => UserCoupon::sourceMap()[(int) $coupon->source] ?? '未知',
            'status' => (int) $coupon->status,
            'effective_status' => $effectiveStatus,
            'status_name' => UserCoupon::statusMap()[$effectiveStatus] ?? '未知',
            'use_time' => $coupon->use_time?->format('Y-m-d H:i:s'),
            'order_no' => (string) $coupon->order_no,
            'receive_time' => $coupon->receive_time?->format('Y-m-d H:i:s'),
            'create_time' => $coupon->create_time?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 格式化用户端输出（含试算提示文案）
     *
     * @return array<string,mixed>
     */
    protected function formatForUser(UserCoupon $coupon): array
    {
        $effectiveStatus = $this->effectiveStatus($coupon);
        $days = max(0, (int) cmspro_coupon_config('expire_notify_days', 3));

        $expiringSoon = false;
        $remainDays = null;

        if ($effectiveStatus === UserCoupon::STATUS_UNUSED && $coupon->valid_end_time !== null) {
            // 用时间戳相减，避免 Carbon 2/3 在 diffInSeconds 符号语义上的差异
            $remainSeconds = $coupon->valid_end_time->getTimestamp() - now()->getTimestamp();
            $remainDays = (int) floor($remainSeconds / 86400);
            $expiringSoon = $days > 0 && $remainSeconds > 0 && $remainSeconds <= $days * 86400;
        }

        return [
            'id' => $coupon->id,
            'coupon_no' => $coupon->coupon_no,
            'title' => $coupon->title,
            'coupon_type' => (int) $coupon->coupon_type,
            'coupon_type_name' => CouponActivity::couponTypeMap()[(int) $coupon->coupon_type] ?? '未知',
            'threshold_amount' => (float) $coupon->threshold_amount,
            'threshold_text' => (float) $coupon->threshold_amount <= 0 ? '无门槛' : '满' . cmspro_coupon_amount_format($coupon->threshold_amount) . '元可用',
            'discount_amount' => (float) $coupon->discount_amount,
            'discount_rate' => (float) $coupon->discount_rate,
            'discount_text' => $this->buildDiscountText($coupon),
            'max_discount_amount' => (float) $coupon->max_discount_amount,
            'description' => (string) $coupon->description,
            'valid_start_time' => $coupon->valid_start_time?->format('Y-m-d H:i:s'),
            'valid_end_time' => $coupon->valid_end_time?->format('Y-m-d H:i:s'),
            'valid_text' => $this->buildValidText($coupon),
            'source' => (int) $coupon->source,
            'source_name' => UserCoupon::sourceMap()[(int) $coupon->source] ?? '未知',
            'status' => $effectiveStatus,
            'status_name' => UserCoupon::statusMap()[$effectiveStatus] ?? '未知',
            'can_use' => $effectiveStatus === UserCoupon::STATUS_UNUSED,
            'expiring_soon' => $expiringSoon,
            'remain_days' => $remainDays,
            'use_time' => $coupon->use_time?->format('Y-m-d H:i:s'),
            'order_no' => (string) $coupon->order_no,
            'receive_time' => $coupon->receive_time?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 有效状态：未使用但已过期时惰性返回「已过期」
     */
    protected function effectiveStatus(UserCoupon $coupon): int
    {
        if ((int) $coupon->status === UserCoupon::STATUS_UNUSED && ! $coupon->isUsable()) {
            return UserCoupon::STATUS_EXPIRED;
        }

        return (int) $coupon->status;
    }

    /**
     * 优惠力度文案
     */
    protected function buildDiscountText(UserCoupon $coupon): string
    {
        if ((int) $coupon->coupon_type === CouponActivity::TYPE_DISCOUNT) {
            $rate = cmspro_coupon_amount_format($coupon->discount_rate);
            $text = $rate . '折';

            if ((float) $coupon->max_discount_amount > 0) {
                $text .= '（最高减' . cmspro_coupon_amount_format($coupon->max_discount_amount) . '元）';
            }

            return $text;
        }

        return '减' . cmspro_coupon_amount_format($coupon->discount_amount) . '元';
    }

    /**
     * 有效期文案
     */
    protected function buildValidText(UserCoupon $coupon): string
    {
        $start = $coupon->valid_start_time?->format('Y-m-d') ?? '';
        $end = $coupon->valid_end_time?->format('Y-m-d') ?? '';

        if ($end === '') {
            return '长期有效';
        }

        return $start !== '' ? "{$start} 至 {$end}" : "有效期至 {$end}";
    }
}
