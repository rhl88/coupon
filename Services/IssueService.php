<?php

namespace App\Apps\CmsproCoupon\Services;

use App\Apps\CmsproCoupon\Models\CouponActivity;
use App\Apps\CmsproCoupon\Models\IssueBatch;
use App\Apps\CmsproCoupon\Models\RedeemCode;
use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 优惠券发放服务
 *
 * 三条发放链路共用同一套「发券原子操作」：
 * - receive()  用户在领券中心主动领取
 * - issue()    后台定向发放（指定用户 / 全部用户，按批次）
 * - redeem()   用户输入兑换码兑换
 *
 * 并发安全设计：
 * - 活动行 lockForUpdate + issued_count 条件原子自增，杜绝超发
 * - 限领校验在同一事务、同一把锁内进行，杜绝同人并发多领
 * - 兑换码 code 唯一索引 + 行锁，杜绝一码多兑
 */
class IssueService
{
    /**
     * 日志标记
     */
    private const LOG_TAG = '[cmspro.coupon] ';

    /**
     * 单次后台批量发放的用户数上限
     *
     * 防止误操作全量发放导致长时间占用请求、拖垮数据库。
     */
    public const ISSUE_BATCH_LIMIT = 5000;

    public function __construct(
        protected CouponRuleService $ruleService,
        protected HookManager $hooks,
    ) {
    }

    /**
     * 用户主动领取优惠券
     *
     * @param int $userId     前台用户ID
     * @param int $activityId 券活动ID
     * @return array<string,mixed> ApiResponse 结构
     */
    public function receive(int $userId, int $activityId): array
    {
        // 总开关校验
        if (! cmspro_coupon_config_bool('receive_enabled', true)) {
            return ApiResponse::error(40003, '当前未开放领取');
        }

        try {
            return DB::transaction(function () use ($userId, $activityId) {
                /** @var CouponActivity|null $activity */
                $activity = CouponActivity::where('id', $activityId)->lockForUpdate()->first();

                if ($activity === null) {
                    return ApiResponse::error(40401, '优惠券活动不存在');
                }

                if (! $activity->isOnShelf()) {
                    return ApiResponse::error(40003, '该优惠券活动已下架');
                }

                if (! $activity->supportReceive(CouponActivity::RECEIVE_USER)) {
                    return ApiResponse::error(40003, '该优惠券不支持主动领取');
                }

                if (! $activity->isInReceiveWindow()) {
                    return ApiResponse::error(40003, '不在领取时间范围内');
                }

                $result = $this->issueOne($activity, $userId, UserCoupon::SOURCE_RECEIVE, null);

                if ($result['success']) {
                    $this->fireHook('cmspro.coupon.received', $result['coupon'], $userId, $activity);
                }

                return $result['success']
                    ? ApiResponse::success($this->formatCoupon($result['coupon']), '领取成功')
                    : ApiResponse::error($result['code'], $result['message']);
            });
        } catch (\RuntimeException $e) {
            // 并发兜底异常（如条件扣库存失败）转为友好提示，事务已回滚
            return ApiResponse::error(40003, $e->getMessage() ?: '领取失败，请重试');
        }
    }

    /**
     * 后台定向发放
     *
     * @param int                 $activityId 券活动ID
     * @param array<int,int>      $userIds    指定用户ID列表（target_type=1 时使用）
     * @param int                 $targetType IssueBatch::TARGET_SPECIFIED / TARGET_ALL
     * @param int|null            $adminId    操作管理员ID
     * @param string              $remark     备注
     * @return array<string,mixed> ApiResponse 结构，data 含批次统计
     */
    public function issue(int $activityId, array $userIds, int $targetType, ?int $adminId, string $remark = ''): array
    {
        $activity = CouponActivity::find($activityId);

        if ($activity === null) {
            return ApiResponse::error(40401, '券活动不存在');
        }

        if (! $activity->supportReceive(CouponActivity::RECEIVE_ADMIN)) {
            return ApiResponse::error(40003, '该优惠券活动未开启后台发放方式');
        }

        // 解析目标用户（全部启用用户 / 过滤脏ID后的指定用户）
        $userIds = $this->resolveTargetUsers($userIds, $targetType);

        if (empty($userIds)) {
            return ApiResponse::error(40003, '没有有效的目标用户');
        }

        // 单次批量发放上限，防止误操作全量发放拖垮数据库
        if (count($userIds) > self::ISSUE_BATCH_LIMIT) {
            return ApiResponse::error(40003, '单次发放用户数不能超过' . self::ISSUE_BATCH_LIMIT . '，请分批操作');
        }

        $batch = $this->createIssueBatch($activity, $targetType, count($userIds), $adminId, $remark);
        [$successCount, $failDetails] = $this->dispatchBatch($activity, $userIds);

        $batch->update([
            'success_count' => $successCount,
            'fail_count' => count($userIds) - $successCount,
            'fail_detail' => $failDetails,
            'status' => IssueBatch::STATUS_FINISHED,
        ]);

        return ApiResponse::success([
            'batch_no' => $batch->batch_no,
            'total_count' => count($userIds),
            'success_count' => $successCount,
            'fail_count' => count($userIds) - $successCount,
        ], "发放完成：成功 {$successCount} 张，失败 " . (count($userIds) - $successCount) . ' 张');
    }

    /**
     * 解析发放目标用户ID列表
     *
     * - TARGET_ALL：取全部启用用户
     * - TARGET_SPECIFIED：过滤出真实存在且启用的用户，防止传入脏ID产生孤儿券
     *
     * @param array<int,int> $userIds    指定的用户ID（TARGET_ALL 时忽略）
     * @param int            $targetType IssueBatch::TARGET_ALL / TARGET_SPECIFIED
     * @return array<int,int> 有效且启用的用户ID列表
     */
    protected function resolveTargetUsers(array $userIds, int $targetType): array
    {
        if ($targetType === IssueBatch::TARGET_ALL) {
            return User::where('status', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $normalized = array_values(array_unique(array_map('intval', $userIds)));

        if (empty($normalized)) {
            return [];
        }

        return User::where('status', 1)
            ->whereIn('id', $normalized)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * 创建发放批次记录（初始为处理中，发放完成后回填统计）
     */
    protected function createIssueBatch(CouponActivity $activity, int $targetType, int $totalCount, ?int $adminId, string $remark): IssueBatch
    {
        return IssueBatch::create([
            'batch_no' => $this->generateBatchNo(),
            'activity_id' => $activity->id,
            'activity_title' => $activity->title,
            'target_type' => $targetType,
            'total_count' => $totalCount,
            'success_count' => 0,
            'fail_count' => 0,
            'admin_id' => $adminId,
            'remark' => $remark !== '' ? mb_substr($remark, 0, 255) : null,
            'status' => IssueBatch::STATUS_PROCESSING,
        ]);
    }

    /**
     * 逐用户发放并汇总结果
     *
     * 每个用户独立事务发放，单个用户失败（如超出限领）不影响其他用户；
     * 失败明细最多保留 IssueBatch::FAIL_DETAIL_LIMIT 条。
     *
     * @param array<int,int> $userIds
     * @return array{0:int,1:array<int,array<string,mixed>>} [成功数, 失败明细]
     */
    protected function dispatchBatch(CouponActivity $activity, array $userIds): array
    {
        $successCount = 0;
        $failDetails = [];

        foreach ($userIds as $userId) {
            $result = $this->issueInTransaction($activity->id, (int) $userId);

            if ($result['success']) {
                $successCount++;
                $this->fireHook('cmspro.coupon.issued', $result['coupon'], (int) $userId, $activity);
                continue;
            }

            if (count($failDetails) < IssueBatch::FAIL_DETAIL_LIMIT) {
                $failDetails[] = ['user_id' => (int) $userId, 'reason' => $result['message']];
            }
        }

        return [$successCount, $failDetails];
    }

    /**
     * 兑换码兑换
     *
     * @param int    $userId 前台用户ID
     * @param string $code   兑换码
     * @return array<string,mixed> ApiResponse 结构
     */
    public function redeem(int $userId, string $code): array
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return ApiResponse::error(40001, '请输入兑换码');
        }

        if (mb_strlen($code) > 50) {
            return ApiResponse::error(40001, '兑换码格式不正确');
        }

        try {
            return DB::transaction(function () use ($userId, $code) {
                /** @var RedeemCode|null $redeemCode */
                $redeemCode = RedeemCode::where('code', $code)->lockForUpdate()->first();

                if ($redeemCode === null) {
                    return ApiResponse::error(40401, '兑换码不存在');
                }

                if ((int) $redeemCode->status === RedeemCode::STATUS_USED) {
                    return ApiResponse::error(40003, '该兑换码已被使用');
                }

                if ((int) $redeemCode->status === RedeemCode::STATUS_VOID) {
                    return ApiResponse::error(40003, '该兑换码已作废');
                }

                /** @var CouponActivity|null $activity */
                $activity = CouponActivity::where('id', $redeemCode->activity_id)->lockForUpdate()->first();

                if ($activity === null) {
                    return ApiResponse::error(40401, '兑换码关联的券活动不存在');
                }

                if (! $activity->isOnShelf()) {
                    return ApiResponse::error(40003, '该优惠券活动已下架，无法兑换');
                }

                $result = $this->issueOne($activity, $userId, UserCoupon::SOURCE_REDEEM, $redeemCode->id);

                if (! $result['success']) {
                    return ApiResponse::error($result['code'], $result['message']);
                }

                // 标记兑换码已使用（行锁保护下，与发券同一事务，要么都成功要么都回滚）
                $redeemCode->update([
                    'status' => RedeemCode::STATUS_USED,
                    'user_id' => $userId,
                    'user_coupon_id' => $result['coupon']->id,
                    'redeem_time' => now()->format('Y-m-d H:i:s'),
                ]);

                $this->fireHook('cmspro.coupon.redeemed', $result['coupon'], $userId, $activity);

                return ApiResponse::success($this->formatCoupon($result['coupon']), '兑换成功');
            });
        } catch (\RuntimeException $e) {
            // 并发兜底异常（如条件扣库存失败）转为友好提示，事务已回滚
            return ApiResponse::error(40003, $e->getMessage() ?: '兑换失败，请重试');
        }
    }

    /**
     * 发券原子操作（必须在已持有活动行锁的事务内调用）
     *
     * 校验顺序：库存 → 限领 → 生成券 → 原子扣库存。
     * 库存扣减使用「条件更新」而非读改写，即使锁失效也不会超发。
     *
     * @return array{success:bool,code?:int,message?:string,coupon?:UserCoupon}
     */
    protected function issueOne(CouponActivity $activity, int $userId, int $source, ?int $sourceId): array
    {
        // 库存校验
        $remain = $activity->getRemainStock();
        if ($remain === 0) {
            return ['success' => false, 'code' => 40003, 'message' => '优惠券已领完'];
        }

        // 限领校验（同一把锁内统计，杜绝并发多领）
        $perUserLimit = (int) $activity->per_user_limit;
        if ($perUserLimit > 0) {
            $received = UserCoupon::where('activity_id', $activity->id)
                ->where('user_id', $userId)
                ->whereIn('status', [UserCoupon::STATUS_UNUSED, UserCoupon::STATUS_USED])
                ->count();

            if ($received >= $perUserLimit) {
                return ['success' => false, 'code' => 40003, 'message' => "每人限领 {$perUserLimit} 张，您已领取"];
            }
        }

        // 计算券有效期
        [$validStart, $validEnd] = $this->resolveValidity($activity);

        if ($validEnd !== null && $validStart !== null && $validEnd <= $validStart) {
            return ['success' => false, 'code' => 40003, 'message' => '券活动有效期配置有误，请联系管理员'];
        }

        $now = now()->format('Y-m-d H:i:s');

        $coupon = UserCoupon::create([
            'coupon_no' => $this->generateCouponNo(),
            'activity_id' => $activity->id,
            'user_id' => $userId,
            'title' => $activity->title,
            'coupon_type' => $activity->coupon_type,
            'threshold_amount' => $activity->threshold_amount,
            'discount_amount' => $activity->discount_amount,
            'discount_rate' => $activity->discount_rate,
            'max_discount_amount' => $activity->max_discount_amount,
            'description' => $activity->description,
            'valid_start_time' => $validStart,
            'valid_end_time' => $validEnd,
            'source' => $source,
            'source_id' => $sourceId,
            'status' => UserCoupon::STATUS_UNUSED,
            'receive_time' => $now,
        ]);

        // 条件原子自增：仅在库存充足时才 +1，防止锁失效场景超发
        if ((int) $activity->total_stock > 0) {
            $affected = CouponActivity::where('id', $activity->id)
                ->where('issued_count', '<', $activity->total_stock)
                ->increment('issued_count');

            if ($affected === 0) {
                // 库存已被并发抢完：回滚本张券（抛异常终止事务）
                throw new \RuntimeException('优惠券已领完');
            }
        } else {
            CouponActivity::where('id', $activity->id)->increment('issued_count');
        }

        return ['success' => true, 'coupon' => $coupon];
    }

    /**
     * 单用户发放（独立事务，供批量发放逐个调用）
     *
     * 批量发放时单个用户失败不应回滚整批，因此每个用户单独开事务。
     *
     * @return array{success:bool,code?:int,message?:string,coupon?:UserCoupon}
     */
    protected function issueInTransaction(int $activityId, int $userId): array
    {
        try {
            return DB::transaction(function () use ($activityId, $userId) {
                /** @var CouponActivity|null $activity */
                $activity = CouponActivity::where('id', $activityId)->lockForUpdate()->first();

                if ($activity === null) {
                    return ['success' => false, 'code' => 40401, 'message' => '券活动不存在'];
                }

                return $this->issueOne($activity, $userId, UserCoupon::SOURCE_ISSUE, null);
            });
        } catch (\Throwable $e) {
            Log::warning(self::LOG_TAG . '发放失败', [
                'activity_id' => $activityId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'code' => 50001, 'message' => $e->getMessage() ?: '发放失败'];
        }
    }

    /**
     * 计算券的有效期
     *
     * 固定期：直接取活动配置；相对期：领取时刻起 N 天（当天 23:59:59 截止，符合用户直觉）。
     *
     * @return array{0:string|null,1:string|null} [生效时间, 失效时间]
     */
    protected function resolveValidity(CouponActivity $activity): array
    {
        if ((int) $activity->valid_type === CouponActivity::VALID_RELATIVE) {
            $days = max(1, (int) $activity->valid_days);
            $now = now();

            return [
                $now->format('Y-m-d H:i:s'),
                $now->copy()->addDays($days)->endOfDay()->format('Y-m-d H:i:s'),
            ];
        }

        return [
            $activity->valid_start_time?->format('Y-m-d H:i:s'),
            $activity->valid_end_time?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 生成券号：前缀 + 日期 + 毫秒 + 随机数
     *
     * 唯一索引兜底：极小概率碰撞时重试生成。
     */
    protected function generateCouponNo(): string
    {
        $prefix = strtoupper(trim((string) cmspro_coupon_config('coupon_no_prefix', 'CP')));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $couponNo = $prefix . now()->format('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            if (! UserCoupon::where('coupon_no', $couponNo)->exists()) {
                return $couponNo;
            }
        }

        // 兜底：加入毫秒与更强随机，碰撞概率可忽略
        return $prefix . now()->format('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
    }

    /**
     * 生成发放批次号：IB + 日期时间 + 随机
     */
    protected function generateBatchNo(): string
    {
        return 'IB' . now()->format('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * 触发自定义 Action 钩子（供其他应用监听）
     *
     * 钩子异常不影响发放主流程，捕获后记录日志。
     */
    protected function fireHook(string $hookName, UserCoupon $coupon, int $userId, CouponActivity $activity): void
    {
        try {
            $this->hooks->doAction($hookName, $coupon, $userId, $activity);
        } catch (\Throwable $e) {
            Log::warning(self::LOG_TAG . '钩子触发失败', [
                'hook' => $hookName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 格式化单张券输出（领取/兑换成功后的返回体）
     *
     * @return array<string,mixed>
     */
    public function formatCoupon(UserCoupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'coupon_no' => $coupon->coupon_no,
            'title' => $coupon->title,
            'coupon_type' => (int) $coupon->coupon_type,
            'threshold_amount' => (float) $coupon->threshold_amount,
            'discount_amount' => (float) $coupon->discount_amount,
            'discount_rate' => (float) $coupon->discount_rate,
            'max_discount_amount' => (float) $coupon->max_discount_amount,
            'valid_start_time' => $coupon->valid_start_time?->format('Y-m-d H:i:s'),
            'valid_end_time' => $coupon->valid_end_time?->format('Y-m-d H:i:s'),
            'status' => (int) $coupon->status,
            'source' => (int) $coupon->source,
        ];
    }
}
