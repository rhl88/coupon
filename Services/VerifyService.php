<?php

namespace App\Apps\CmsproCoupon\Services;

use App\Apps\CmsproCoupon\Models\CouponActivity;
use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Apps\CmsproCoupon\Models\VerifyLog;
use App\Http\Responses\ApiResponse;
use App\Services\HookManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 优惠券核销服务
 *
 * 本应用对外（其他应用 / 前端 API）暴露的核心能力，两类调用方式：
 * - PHP 层直接注入调用（推荐）：其他应用下单时调用 preview() 试算、verify() 核销
 * - 前端 HTTP API：POST /api/cmspro/coupon/verify（用户态 auth:web）
 *
 * 核销安全性：
 * - 券状态流转使用条件更新（where status=0 → 1），天然防止并发重复核销
 * - 同一订单多券时校验 multi_use_enabled 开关，优惠总额不超过订单金额
 * - 撤销核销保留流水（status=2），券回到未使用状态
 */
class VerifyService
{
    /**
     * 日志标记
     */
    private const LOG_TAG = '[cmspro.coupon] ';

    public function __construct(
        protected CouponRuleService $ruleService,
        protected HookManager $hooks,
    ) {
    }

    /**
     * 查询用户在指定订单金额下可用的券
     *
     * 惰性过期判定 + 门槛过滤 + 按优惠额降序（第一张即最优券）。
     *
     * @param int   $userId      前台用户ID
     * @param float $orderAmount 订单金额
     * @return array<int,array<string,mixed>> 可用券列表（含 discount_amount 实际优惠额）
     */
    public function availableCoupons(int $userId, float $orderAmount): array
    {
        if ($orderAmount <= 0) {
            return [];
        }

        /** @var Collection<int,UserCoupon> $coupons */
        $coupons = UserCoupon::usable()
            ->where('user_id', $userId)
            ->orderBy('valid_end_time', 'asc')
            ->get();

        $available = [];

        foreach ($coupons as $coupon) {
            $discount = $this->ruleService->calculateDiscount($coupon, $orderAmount);

            if ($discount <= 0) {
                continue; // 未达门槛
            }

            $item = $this->formatCoupon($coupon);
            $item['discount_amount'] = $discount;
            $item['pay_amount'] = round($orderAmount - $discount, 2);
            $available[] = $item;
        }

        // 优惠额降序：调用方取第一个即为最优选择
        usort($available, fn (array $a, array $b) => $b['discount_amount'] <=> $a['discount_amount']);

        return $this->applyFilter('cmspro.coupon.available.list', $available, $userId, $orderAmount);
    }

    /**
     * 核销前试算
     *
     * @param int                $userId      前台用户ID
     * @param float              $orderAmount 订单金额
     * @param array<int,int>     $couponIds   指定核销的券ID；为空时自动选最优券
     * @return array<string,mixed> ApiResponse 结构，data 含 total_discount / pay_amount / coupons
     */
    public function preview(int $userId, float $orderAmount, array $couponIds = []): array
    {
        if ($orderAmount <= 0) {
            return ApiResponse::error(40001, '订单金额必须大于0');
        }

        $multiUseEnabled = cmspro_coupon_config_bool('multi_use_enabled', false);

        if (empty($couponIds)) {
            // 未指定券：自动选最优
            if (! $multiUseEnabled) {
                $available = $this->availableCoupons($userId, $orderAmount);

                if (empty($available)) {
                    return ApiResponse::success($this->buildPreviewData([], $orderAmount, 0.0), '无可用优惠券');
                }

                return ApiResponse::success($this->buildPreviewData([$available[0]], $orderAmount, (float) $available[0]['discount_amount']), 'success');
            }

            // 允许多券：叠加全部可用券直到订单额抵扣完
            $available = $this->availableCoupons($userId, $orderAmount);
            [$picked, $totalDiscount] = $this->pickBestCombination($available, $orderAmount);

            return ApiResponse::success($this->buildPreviewData($picked, $orderAmount, $totalDiscount), 'success');
        }

        $coupons = $this->resolveCoupons($userId, $couponIds, $multiUseEnabled);

        if (isset($coupons['error'])) {
            return ApiResponse::error($coupons['code'], $coupons['message']);
        }

        [$totalDiscount, $items] = $this->sumDiscount($coupons['coupons'], $orderAmount);

        // 试算输出仅取展示明细（不含模型引用）
        $details = array_map(fn (array $item) => $item['detail'], $items);

        return ApiResponse::success($this->buildPreviewData($details, $orderAmount, $totalDiscount), 'success');
    }

    /**
     * 核销优惠券
     *
     * @param int            $userId      券归属用户ID
     * @param array<int,int> $couponIds   券ID列表
     * @param string         $orderNo     业务订单号
     * @param float          $orderAmount 订单金额
     * @param array{caller_app_id?:string,operator_type?:int,operator_id?:int|null,remark?:string} $context 核销上下文
     * @return array<string,mixed> ApiResponse 结构，data 含 total_discount / pay_amount
     */
    public function verify(int $userId, array $couponIds, string $orderNo, float $orderAmount, array $context = []): array
    {
        $orderNo = trim($orderNo);

        // ① 入参校验
        $paramError = $this->validateVerifyParams($orderNo, $orderAmount, $couponIds);
        if ($paramError !== null) {
            return $paramError;
        }

        // ② 券归属与可用性校验（事务外先粗筛，减少锁持有时间）
        $multiUseEnabled = cmspro_coupon_config_bool('multi_use_enabled', false);
        $resolved = $this->resolveCoupons($userId, $couponIds, $multiUseEnabled);

        if (isset($resolved['error'])) {
            return ApiResponse::error($resolved['code'], $resolved['message']);
        }

        // ③ 事务内核销（加锁重读 + 条件更新 + 写流水）
        return $this->applyCouponsInTransaction($resolved['coupons'], $userId, $orderNo, $orderAmount, $context);
    }

    /**
     * 核销入参校验
     *
     * @return array<string,mixed>|null 错误响应；null 表示校验通过
     */
    protected function validateVerifyParams(string $orderNo, float $orderAmount, array $couponIds): ?array
    {
        if ($orderNo === '') {
            return ApiResponse::error(40001, '订单号不能为空');
        }

        if (mb_strlen($orderNo) > 64) {
            return ApiResponse::error(40001, '订单号长度不能超过64个字符');
        }

        if ($orderAmount <= 0) {
            return ApiResponse::error(40001, '订单金额必须大于0');
        }

        if (empty($couponIds)) {
            return ApiResponse::error(40001, '请选择要使用的优惠券');
        }

        return null;
    }

    /**
     * 事务内执行核销
     *
     * 加锁重读券最新状态 → 校验可用性与同单重复核销 → 逐张条件更新为已使用 → 写核销流水；
     * 并发抢先核销抛 RuntimeException 回滚整批，转为友好错误码。
     *
     * @param Collection<int,UserCoupon> $coupons
     * @param array<string,mixed>        $context
     * @return array<string,mixed>
     */
    protected function applyCouponsInTransaction(Collection $coupons, int $userId, string $orderNo, float $orderAmount, array $context): array
    {
        try {
            return DB::transaction(function () use ($coupons, $userId, $orderNo, $orderAmount, $context) {
                // 同一订单已核销且未撤销的券，禁止重复核销
                $existingCount = VerifyLog::where('order_no', $orderNo)
                    ->where('status', VerifyLog::STATUS_VERIFIED)
                    ->count();

                if ($existingCount > 0 && ! cmspro_coupon_config_bool('multi_use_enabled', false)) {
                    return ApiResponse::error(40003, '该订单已使用优惠券，不可重复核销');
                }

                // 条件加锁重读：确保拿到的是事务内最新状态
                $couponIds = $coupons->pluck('id')->all();
                $lockedCoupons = UserCoupon::whereIn('id', $couponIds)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->get();

                if ($lockedCoupons->count() !== count($couponIds)) {
                    return ApiResponse::error(40401, '优惠券不存在或不属于当前用户');
                }

                foreach ($lockedCoupons as $coupon) {
                    if (! $coupon->isUsable()) {
                        return ApiResponse::error(40003, "优惠券「{$coupon->title}」不可用（已使用或已过期）");
                    }
                }

                [$totalDiscount, $items] = $this->sumDiscount($lockedCoupons, $orderAmount);

                if ($totalDiscount <= 0) {
                    return ApiResponse::error(40003, '所选优惠券不满足订单金额门槛');
                }

                $verifiedLogs = $this->markCouponsUsed($items, $userId, $orderNo, $orderAmount, $context);

                $resultData = [
                    'order_no' => $orderNo,
                    'order_amount' => round($orderAmount, 2),
                    'total_discount' => $totalDiscount,
                    'pay_amount' => round(max(0, $orderAmount - $totalDiscount), 2),
                    // 对外输出仅取展示明细（不含模型引用，避免序列化异常）
                    'coupons' => array_map(fn (array $item) => $item['detail'], $items),
                ];

                // 事务提交后再触发钩子，避免监听方读到未提交数据
                DB::afterCommit(function () use ($verifiedLogs, $userId, $orderNo) {
                    foreach ($verifiedLogs as $entry) {
                        $this->fireHook('cmspro.coupon.verified', $entry['coupon'], $entry['log'], $userId, $orderNo);
                    }
                });

                return ApiResponse::success($resultData, '核销成功');
            });
        } catch (\RuntimeException $e) {
            // 并发抢先核销等兜底异常：事务已回滚，转为友好提示
            return ApiResponse::error(40003, $e->getMessage() ?: '核销失败，请刷新后重试');
        }
    }

    /**
     * 逐张券条件更新为已使用并写核销流水
     *
     * 条件更新（where status=UNUSED）保证并发下只有一个请求成功；
     * 若某张券已被抢先核销，抛 RuntimeException 触发整批事务回滚。
     *
     * @param array<int,array<string,mixed>> $items   sumDiscount 产出的明细（含 coupon/discount）
     * @param array<string,mixed>            $context 核销上下文
     * @return array<int,array{coupon:UserCoupon,log:VerifyLog}> 供事务提交后触发钩子
     * @throws \RuntimeException 券已被并发核销
     */
    protected function markCouponsUsed(array $items, int $userId, string $orderNo, float $orderAmount, array $context): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $callerAppId = mb_substr((string) ($context['caller_app_id'] ?? ''), 0, 50);
        $operatorType = (int) ($context['operator_type'] ?? VerifyLog::OPERATOR_USER);
        $operatorId = $context['operator_id'] ?? null;
        $remark = mb_substr((string) ($context['remark'] ?? ''), 0, 255);

        $verifiedLogs = [];

        foreach ($items as $item) {
            /** @var UserCoupon $coupon */
            $coupon = $item['coupon'];

            // 条件更新：仅 status=0 的券能被置为已使用，并发下只有一个请求成功
            $affected = UserCoupon::where('id', $coupon->id)
                ->where('status', UserCoupon::STATUS_UNUSED)
                ->update([
                    'status' => UserCoupon::STATUS_USED,
                    'use_time' => $now,
                    'order_no' => $orderNo,
                ]);

            if ($affected === 0) {
                // 被其他请求抢先核销：抛异常回滚整批，避免部分成功
                throw new \RuntimeException("优惠券「{$coupon->title}」已被核销，请刷新后重试");
            }

            $log = VerifyLog::create([
                'user_coupon_id' => $coupon->id,
                'coupon_no' => $coupon->coupon_no,
                'user_id' => $userId,
                'order_no' => $orderNo,
                'order_amount' => $orderAmount,
                'discount_amount' => $item['discount'],
                'caller_app_id' => $callerAppId,
                'operator_type' => $operatorType,
                'operator_id' => $operatorId,
                'status' => VerifyLog::STATUS_VERIFIED,
                'verify_time' => $now,
                'remark' => $remark !== '' ? $remark : null,
            ]);

            $coupon->refresh();
            $verifiedLogs[] = ['coupon' => $coupon, 'log' => $log];
        }

        return $verifiedLogs;
    }

    /**
     * 撤销核销
     *
     * 按核销记录ID撤销：券回到未使用状态（若此时已过期则置为已过期），
     * 流水记录置为已撤销并记录撤销时间与原因，保留审计痕迹。
     *
     * @param int         $logId       核销记录ID
     * @param string      $reason      撤销原因
     * @param int|null    $adminId     操作管理员ID
     * @return array<string,mixed> ApiResponse 结构
     */
    public function revoke(int $logId, string $reason = '', ?int $adminId = null): array
    {
        if (! cmspro_coupon_config_bool('revoke_enabled', true)) {
            return ApiResponse::error(40003, '系统已关闭撤销核销功能');
        }

        return DB::transaction(function () use ($logId, $reason, $adminId) {
            /** @var VerifyLog|null $log */
            $log = VerifyLog::where('id', $logId)->lockForUpdate()->first();

            if ($log === null) {
                return ApiResponse::error(40401, '核销记录不存在');
            }

            if ((int) $log->status === VerifyLog::STATUS_REVOKED) {
                return ApiResponse::error(40003, '该核销记录已撤销');
            }

            /** @var UserCoupon|null $coupon */
            $coupon = UserCoupon::where('id', $log->user_coupon_id)->lockForUpdate()->first();

            if ($coupon === null) {
                return ApiResponse::error(40401, '关联的优惠券记录不存在');
            }

            $now = now()->format('Y-m-d H:i:s');

            // 券已过期则置为已过期，否则回到未使用
            $backStatus = $coupon->valid_end_time !== null && $now > $coupon->valid_end_time->format('Y-m-d H:i:s')
                ? UserCoupon::STATUS_EXPIRED
                : UserCoupon::STATUS_UNUSED;

            // 条件更新：仅「已使用且订单号匹配」的券可回退
            $affected = UserCoupon::where('id', $coupon->id)
                ->where('status', UserCoupon::STATUS_USED)
                ->where('order_no', $log->order_no)
                ->update([
                    'status' => $backStatus,
                    'use_time' => null,
                    'order_no' => null,
                ]);

            if ($affected === 0) {
                return ApiResponse::error(40003, '优惠券状态已变更，撤销失败，请刷新后重试');
            }

            $log->update([
                'status' => VerifyLog::STATUS_REVOKED,
                'cancel_time' => $now,
                'cancel_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'operator_id' => $adminId ?? $log->operator_id,
            ]);

            $coupon->refresh();

            DB::afterCommit(function () use ($coupon, $log) {
                $this->fireHook('cmspro.coupon.revoked', $coupon, $log);
            });

            return ApiResponse::success(null, $backStatus === UserCoupon::STATUS_EXPIRED ? '已撤销，该券已过期' : '撤销成功，优惠券已退回');
        });
    }

    /**
     * 按券号核销（供其他应用以券号而非券ID调用）
     *
     * @param int    $userId      券归属用户ID
     * @param string $couponNo    券号
     * @param string $orderNo     业务订单号
     * @param float  $orderAmount 订单金额
     * @param array<string,mixed> $context 核销上下文
     * @return array<string,mixed>
     */
    public function verifyByCouponNo(int $userId, string $couponNo, string $orderNo, float $orderAmount, array $context = []): array
    {
        $coupon = UserCoupon::where('coupon_no', trim($couponNo))->where('user_id', $userId)->first();

        if ($coupon === null) {
            return ApiResponse::error(40401, '优惠券不存在');
        }

        return $this->verify($userId, [(int) $coupon->id], $orderNo, $orderAmount, $context);
    }

    /**
     * 解析并校验待核销的券
     *
     * @param array<int,int> $couponIds
     * @return array{coupons:Collection<int,UserCoupon>}|array{error:true,code:int,message:string}
     */
    protected function resolveCoupons(int $userId, array $couponIds, bool $multiUseEnabled): array
    {
        $couponIds = array_values(array_unique(array_map('intval', $couponIds)));

        if (empty($couponIds)) {
            return ['error' => true, 'code' => 40001, 'message' => '请选择要使用的优惠券'];
        }

        if (! $multiUseEnabled && count($couponIds) > 1) {
            return ['error' => true, 'code' => 40003, 'message' => '当前设置下单个订单仅可使用一张优惠券'];
        }

        $coupons = UserCoupon::usable()
            ->where('user_id', $userId)
            ->whereIn('id', $couponIds)
            ->get();

        if ($coupons->count() !== count($couponIds)) {
            return ['error' => true, 'code' => 40003, 'message' => '存在不可用的优惠券（已使用、已过期或不属于当前用户）'];
        }

        return ['coupons' => $coupons];
    }

    /**
     * 累加多张券的优惠额（总额不超过订单金额）
     *
     * @param Collection<int,UserCoupon> $coupons
     * @return array{0:float,1:array<int,array<string,mixed>>} [总优惠额, 每张券的优惠明细]
     */
    protected function sumDiscount(Collection $coupons, float $orderAmount): array
    {
        $totalDiscount = 0.0;
        $items = [];

        foreach ($coupons as $coupon) {
            // 逐张按「剩余可抵扣金额」计算，保证累计不超过订单额
            $remaining = round($orderAmount - $totalDiscount, 2);

            if ($remaining <= 0) {
                break;
            }

            $discount = $this->ruleService->calculateDiscount($coupon, $remaining);

            if ($discount <= 0) {
                continue;
            }

            $totalDiscount = round($totalDiscount + $discount, 2);

            $item = $this->formatCoupon($coupon);
            $item['discount_amount'] = $discount;

            $items[] = ['coupon' => $coupon, 'discount' => $discount, 'detail' => $item];
        }

        // 收口：总优惠不得超过订单金额
        $totalDiscount = min($totalDiscount, round($orderAmount, 2));

        // 返回完整明细（含 coupon 模型引用，供核销流程写流水；detail 供对外输出）
        return [$totalDiscount, $items];
    }

    /**
     * 从可用券列表中贪心挑选组合（多券模式）
     *
     * availableCoupons 已按优惠额降序，依次累加直到订单额抵扣完。
     *
     * @param array<int,array<string,mixed>> $available
     * @return array{0:array<int,array<string,mixed>>,1:float}
     */
    protected function pickBestCombination(array $available, float $orderAmount): array
    {
        $picked = [];
        $totalDiscount = 0.0;

        foreach ($available as $item) {
            $remaining = round($orderAmount - $totalDiscount, 2);

            if ($remaining <= 0) {
                break;
            }

            $discount = min((float) $item['discount_amount'], $remaining);
            $totalDiscount = round($totalDiscount + $discount, 2);

            $entry = $item;
            $entry['discount_amount'] = $discount;
            $picked[] = $entry;
        }

        return [$picked, $totalDiscount];
    }

    /**
     * 构造试算返回体
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    protected function buildPreviewData(array $items, float $orderAmount, float $totalDiscount): array
    {
        $data = [
            'order_amount' => round($orderAmount, 2),
            'total_discount' => round($totalDiscount, 2),
            'pay_amount' => round(max(0, $orderAmount - $totalDiscount), 2),
            'coupons' => $items,
            'coupon_count' => count($items),
        ];

        return $this->applyFilter('cmspro.coupon.discount.preview', $data, $orderAmount);
    }

    /**
     * 格式化券数据（对外输出统一结构，日期遵循 Y-m-d H:i:s）
     *
     * @return array<string,mixed>
     */
    public function formatCoupon(UserCoupon $coupon): array
    {
        $threshold = (float) $coupon->threshold_amount;

        return [
            'id' => $coupon->id,
            'coupon_no' => $coupon->coupon_no,
            'activity_id' => $coupon->activity_id,
            'title' => $coupon->title,
            'coupon_type' => (int) $coupon->coupon_type,
            'coupon_type_name' => CouponActivity::couponTypeMap()[(int) $coupon->coupon_type] ?? '未知',
            'threshold_amount' => $threshold,
            'threshold_text' => $threshold <= 0 ? '无门槛' : '满' . cmspro_coupon_amount_format($threshold) . '元可用',
            'discount_amount' => (float) $coupon->discount_amount,
            'discount_rate' => (float) $coupon->discount_rate,
            'max_discount_amount' => (float) $coupon->max_discount_amount,
            'description' => (string) $coupon->description,
            'valid_start_time' => $coupon->valid_start_time?->format('Y-m-d H:i:s'),
            'valid_end_time' => $coupon->valid_end_time?->format('Y-m-d H:i:s'),
            'status' => (int) $coupon->status,
            'source' => (int) $coupon->source,
        ];
    }

    /**
     * 触发 Filter 钩子（异常安全，钩子未注册时原样返回）
     */
    protected function applyFilter(string $hookName, mixed $value, mixed ...$args): mixed
    {
        try {
            return $this->hooks->applyFilter($hookName, $value, ...$args);
        } catch (\Throwable $e) {
            Log::warning(self::LOG_TAG . 'Filter钩子触发失败', ['hook' => $hookName, 'error' => $e->getMessage()]);

            return $value;
        }
    }

    /**
     * 触发 Action 钩子（异常安全）
     */
    protected function fireHook(string $hookName, mixed ...$args): void
    {
        try {
            $this->hooks->doAction($hookName, ...$args);
        } catch (\Throwable $e) {
            Log::warning(self::LOG_TAG . '钩子触发失败', ['hook' => $hookName, 'error' => $e->getMessage()]);
        }
    }
}
