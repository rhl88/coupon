<?php

namespace App\Apps\CmsproCoupon\Services;

use App\Apps\CmsproCoupon\Models\CouponActivity;
use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * 券活动管理服务（后台）
 *
 * 职责：券活动的增删改查、上下架、规则校验与列表格式化。
 * 规则合法性校验集中在校验方法中，保证「不合法的活动进不了库」。
 */
class ActivityService
{
    /**
     * 券活动分页列表
     *
     * @param array<string,mixed> $params keyword/status/coupon_type/per_page/page
     * @return array<string,mixed> ApiResponse::paginate 结构
     */
    public function getList(array $params): array
    {
        $query = CouponActivity::query()
            ->withCount(['userCoupons as used_count' => function (Builder $q) {
                $q->where('status', UserCoupon::STATUS_USED);
            }]);

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }

        if (isset($params['coupon_type']) && $params['coupon_type'] !== '') {
            $query->where('coupon_type', (int) $params['coupon_type']);
        }

        $perPage = (int) ($params['per_page'] ?? cmspro_coupon_config('page_size', 20));
        $items = $query->orderBy('sort', 'asc')->orderBy('id', 'desc')
            ->paginate(max(1, min(100, $perPage)));

        $items->through(fn (CouponActivity $item) => $this->formatActivity($item));

        return ApiResponse::paginate($items);
    }

    /**
     * 券活动下拉选项（发放弹窗、兑换码生成页用）
     *
     * 仅返回上架的活动，按 sort 升序，避免后台把券发到已下架活动。
     *
     * @return array<string,mixed>
     */
    public function getOptions(): array
    {
        $activities = CouponActivity::where('status', 1)
            ->orderBy('sort', 'asc')
            ->orderBy('id', 'desc')
            ->get(['id', 'code', 'title', 'coupon_type', 'receive_type']);

        $options = $activities->map(fn (CouponActivity $item) => [
            'id' => $item->id,
            'code' => $item->code,
            'title' => $item->title,
            'coupon_type' => (int) $item->coupon_type,
            'coupon_type_name' => CouponActivity::couponTypeMap()[(int) $item->coupon_type] ?? '未知',
            'receive_type' => (int) $item->receive_type,
            'support_code' => $item->supportReceive(CouponActivity::RECEIVE_CODE),
            'support_admin' => $item->supportReceive(CouponActivity::RECEIVE_ADMIN),
        ])->all();

        return ApiResponse::success($options);
    }

    /**
     * 券活动详情（表单回填用）
     *
     * @return array<string,mixed>
     */
    public function show(int $id): array
    {
        $activity = CouponActivity::find($id);

        if ($activity === null) {
            return ApiResponse::error(40401, '券活动不存在');
        }

        return ApiResponse::success($this->formatActivity($activity, true));
    }

    /**
     * 创建券活动
     *
     * @param array<string,mixed> $data 已通过控制器 validate 的数据
     * @return array<string,mixed>
     * @throws ValidationException 规则校验失败
     */
    public function store(array $data): array
    {
        $data = $this->normalizeData($data);
        $this->validateRules($data);

        // 活动编码唯一性校验（数据库唯一索引是最后防线，这里提前给出友好提示）
        if (CouponActivity::where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => '活动编码已存在，请更换']);
        }

        $activity = CouponActivity::create($data);

        return ApiResponse::success($this->formatActivity($activity), '券活动创建成功');
    }

    /**
     * 更新券活动
     *
     * 已发放过券的活动，核心规则字段（券型/门槛/优惠力度）不允许修改，
     * 避免与已发出的券快照产生规则冲突；库存、领取窗口、文案、排序、状态可调。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws ValidationException 规则校验失败
     */
    public function update(int $id, array $data): array
    {
        $activity = CouponActivity::find($id);

        if ($activity === null) {
            return ApiResponse::error(40401, '券活动不存在');
        }

        $merged = array_merge($this->formatForMerge($activity), $this->normalizeData($data, true));
        $this->validateRules($merged, true);

        // 已发放过券：禁止修改券型与优惠力度（快照冲突）
        if ((int) $activity->issued_count > 0) {
            $lockedFields = ['coupon_type', 'threshold_amount', 'discount_amount', 'discount_rate', 'max_discount_amount'];
            foreach ($lockedFields as $field) {
                if (isset($data[$field]) && (string) $data[$field] !== (string) $activity->{$field}) {
                    return ApiResponse::error(40003, '该活动已发放过优惠券，券类型与优惠力度不可修改，请新建活动');
                }
            }
        }

        // 编码唯一性校验（排除自身）
        $code = (string) ($data['code'] ?? $activity->code);
        if (CouponActivity::where('code', $code)->where('id', '!=', $id)->exists()) {
            throw ValidationException::withMessages(['code' => '活动编码已存在，请更换']);
        }

        $activity->update($data);

        return ApiResponse::success($this->formatActivity($activity->fresh()), '券活动更新成功');
    }

    /**
     * 删除券活动
     *
     * 已发放过券的活动禁止删除（历史券的快照溯源依赖活动记录），只能下架。
     *
     * @return array<string,mixed>
     */
    public function destroy(int $id): array
    {
        $activity = CouponActivity::find($id);

        if ($activity === null) {
            return ApiResponse::error(40401, '券活动不存在');
        }

        if ((int) $activity->issued_count > 0 || $activity->userCoupons()->exists()) {
            return ApiResponse::error(40003, '该活动已发放过优惠券，无法删除，可将其下架');
        }

        $activity->redeemCodes()->delete();
        $activity->delete();

        return ApiResponse::success(null, '删除成功');
    }

    /**
     * 上架 / 下架
     *
     * @return array<string,mixed>
     */
    public function updateStatus(int $id, int $status): array
    {
        $activity = CouponActivity::find($id);

        if ($activity === null) {
            return ApiResponse::error(40401, '券活动不存在');
        }

        $activity->update(['status' => $status]);

        return ApiResponse::success(null, $status === 1 ? '已上架' : '已下架');
    }

    /**
     * 规则校验（聚合入口）
     *
     * 按关注点拆分为：券类型与优惠力度、有效期、领取时间窗与库存限领三部分，
     * 任一子项产出错误即汇总抛出，消息为中文直接回显给后台表单。
     *
     * @param array<string,mixed> $data     归一化后的完整规则数据
     * @param bool                $isUpdate 更新场景放宽必填校验
     * @throws ValidationException 校验失败
     */
    protected function validateRules(array $data, bool $isUpdate = false): void
    {
        $errors = [];

        $errors += $this->validateCouponTypeAndDiscount($data);
        $errors += $this->validateValidity($data);
        $errors += $this->validateReceiveAndStock($data);

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * 校验券类型与优惠力度（满减/折扣/立减三类规则互斥）
     *
     * @param array<string,mixed> $data
     * @return array<string,string> 错误映射（field => message）
     */
    protected function validateCouponTypeAndDiscount(array $data): array
    {
        $errors = [];
        $couponType = (int) ($data['coupon_type'] ?? 0);

        if (! in_array($couponType, [
            CouponActivity::TYPE_FULL_REDUCE,
            CouponActivity::TYPE_DISCOUNT,
            CouponActivity::TYPE_CASH,
        ], true)) {
            $errors['coupon_type'] = '请选择有效的券类型';

            return $errors; // 券类型非法时后续力度校验无意义
        }

        $threshold = (float) ($data['threshold_amount'] ?? 0);
        $discountAmount = (float) ($data['discount_amount'] ?? 0);
        $discountRate = (float) ($data['discount_rate'] ?? 0);
        $maxDiscount = (float) ($data['max_discount_amount'] ?? 0);

        if ($couponType === CouponActivity::TYPE_FULL_REDUCE) {
            if ($threshold <= 0) {
                $errors['threshold_amount'] = '满减券必须设置大于0的使用门槛';
            }
            if ($discountAmount <= 0) {
                $errors['discount_amount'] = '满减券必须设置大于0的优惠金额';
            }
            if ($threshold > 0 && $discountAmount >= $threshold) {
                $errors['discount_amount'] = '优惠金额必须小于使用门槛';
            }
        } elseif ($couponType === CouponActivity::TYPE_DISCOUNT) {
            if ($discountRate <= 0 || $discountRate >= 10) {
                $errors['discount_rate'] = '折扣率必须在0到10之间（不含），如8.5表示8.5折';
            }
            if ($threshold < 0) {
                $errors['threshold_amount'] = '使用门槛不能为负数';
            }
            if ($maxDiscount < 0) {
                $errors['max_discount_amount'] = '最高优惠金额不能为负数';
            }
        } elseif ($couponType === CouponActivity::TYPE_CASH) {
            if ($discountAmount <= 0) {
                $errors['discount_amount'] = '立减券必须设置大于0的优惠金额';
            }
            // 立减券强制无门槛
            if ($threshold > 0) {
                $errors['threshold_amount'] = '无门槛立减券的使用门槛必须为0';
            }
        }

        return $errors;
    }

    /**
     * 校验有效期配置（固定时间段 / 领取后 N 天）
     *
     * @param array<string,mixed> $data
     * @return array<string,string> 错误映射
     */
    protected function validateValidity(array $data): array
    {
        $errors = [];
        $validType = (int) ($data['valid_type'] ?? CouponActivity::VALID_FIXED);

        if ($validType === CouponActivity::VALID_FIXED) {
            $start = (string) ($data['valid_start_time'] ?? '');
            $end = (string) ($data['valid_end_time'] ?? '');
            if ($start === '' || $end === '') {
                $errors['valid_end_time'] = '固定有效期必须同时设置开始与结束时间';
            } elseif ($end <= $start) {
                $errors['valid_end_time'] = '有效期结束时间必须晚于开始时间';
            }
        } elseif ($validType === CouponActivity::VALID_RELATIVE) {
            $validDays = (int) ($data['valid_days'] ?? 0);
            if ($validDays <= 0) {
                $errors['valid_days'] = '领取后有效天数必须大于0';
            }
        } else {
            $errors['valid_type'] = '请选择有效的有效期类型';
        }

        return $errors;
    }

    /**
     * 校验领取时间窗与库存限领
     *
     * @param array<string,mixed> $data
     * @return array<string,string> 错误映射
     */
    protected function validateReceiveAndStock(array $data): array
    {
        $errors = [];

        // 领取时间窗校验（均为空表示不限制）
        $receiveStart = (string) ($data['receive_start_time'] ?? '');
        $receiveEnd = (string) ($data['receive_end_time'] ?? '');
        if ($receiveStart !== '' && $receiveEnd !== '' && $receiveEnd <= $receiveStart) {
            $errors['receive_end_time'] = '领取结束时间必须晚于开始时间';
        }

        // 库存与限领
        if ((int) ($data['total_stock'] ?? 0) < 0) {
            $errors['total_stock'] = '发放总量不能为负数，0表示不限量';
        }
        if ((int) ($data['per_user_limit'] ?? 1) < 0) {
            $errors['per_user_limit'] = '每人限领数量不能为负数，0表示不限';
        }

        return $errors;
    }

    /**
     * 数据归一化：立减券强制门槛为0、非折扣券清零折扣字段等
     *
     * @param array<string,mixed> $data     原始数据
     * @param bool                $isUpdate 更新场景允许字段缺省
     * @return array<string,mixed>
     */
    protected function normalizeData(array $data, bool $isUpdate = false): array
    {
        $couponType = (int) ($data['coupon_type'] ?? 0);

        if ($couponType === CouponActivity::TYPE_CASH) {
            // 无门槛立减券：门槛恒为 0
            $data['threshold_amount'] = 0;
        }

        if ($couponType !== CouponActivity::TYPE_DISCOUNT) {
            // 非折扣券：清零折扣相关字段，避免脏数据影响展示
            $data['discount_rate'] = 0;
            $data['max_discount_amount'] = 0;
        }

        if ($couponType === CouponActivity::TYPE_DISCOUNT) {
            // 折扣券不使用固定优惠额
            $data['discount_amount'] = 0;
        }

        $validType = (int) ($data['valid_type'] ?? CouponActivity::VALID_FIXED);
        if ($validType === CouponActivity::VALID_RELATIVE) {
            // 相对有效期不使用固定时间
            $data['valid_start_time'] = null;
            $data['valid_end_time'] = null;
        } else {
            $data['valid_days'] = 0;
        }

        // 空字符串时间统一转 null，避免 MySQL 严格模式下写 '' 报错
        foreach (['valid_start_time', 'valid_end_time', 'receive_start_time', 'receive_end_time'] as $timeField) {
            if (array_key_exists($timeField, $data) && ($data[$timeField] === '' || $data[$timeField] === null)) {
                $data[$timeField] = null;
            }
        }

        // 发放方式位掩码归一化（数组勾选 → 位或叠加）
        if (isset($data['receive_types']) && is_array($data['receive_types'])) {
            $mask = 0;
            foreach ($data['receive_types'] as $flag) {
                $mask |= (int) $flag;
            }
            $data['receive_type'] = $mask;
            unset($data['receive_types']);
        }

        return $data;
    }

    /**
     * 从模型反推归一化数据（更新场景合并旧值用）
     *
     * @return array<string,mixed>
     */
    protected function formatForMerge(CouponActivity $activity): array
    {
        return [
            'coupon_type' => (int) $activity->coupon_type,
            'threshold_amount' => (float) $activity->threshold_amount,
            'discount_amount' => (float) $activity->discount_amount,
            'discount_rate' => (float) $activity->discount_rate,
            'max_discount_amount' => (float) $activity->max_discount_amount,
            'total_stock' => (int) $activity->total_stock,
            'per_user_limit' => (int) $activity->per_user_limit,
            'valid_type' => (int) $activity->valid_type,
            'valid_start_time' => $activity->valid_start_time?->format('Y-m-d H:i:s'),
            'valid_end_time' => $activity->valid_end_time?->format('Y-m-d H:i:s'),
            'valid_days' => (int) $activity->valid_days,
            'receive_start_time' => $activity->receive_start_time?->format('Y-m-d H:i:s'),
            'receive_end_time' => $activity->receive_end_time?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 格式化券活动输出
     *
     * @param bool $withRaw 是否附带原始字段（表单回填需要原始值而非展示文案）
     * @return array<string,mixed>
     */
    public function formatActivity(CouponActivity $activity, bool $withRaw = false): array
    {
        $remain = $activity->getRemainStock();

        $formatted = [
            'id' => $activity->id,
            'code' => $activity->code,
            'title' => $activity->title,
            'coupon_type' => (int) $activity->coupon_type,
            'coupon_type_name' => $activity->coupon_type_name,
            'threshold_amount' => (float) $activity->threshold_amount,
            'discount_amount' => (float) $activity->discount_amount,
            'discount_rate' => (float) $activity->discount_rate,
            'max_discount_amount' => (float) $activity->max_discount_amount,
            'discount_text' => $activity->discount_text,
            'threshold_text' => $activity->threshold_text,
            'total_stock' => (int) $activity->total_stock,
            'issued_count' => (int) $activity->issued_count,
            'remain_stock' => $remain,
            'remain_stock_text' => $remain < 0 ? '不限' : (string) $remain,
            'used_count' => (int) ($activity->used_count ?? 0),
            'per_user_limit' => (int) $activity->per_user_limit,
            'valid_type' => (int) $activity->valid_type,
            'valid_days' => (int) $activity->valid_days,
            'receive_type' => (int) $activity->receive_type,
            'receive_types' => $this->parseReceiveTypes((int) $activity->receive_type),
            'sort' => (int) $activity->sort,
            'status' => (int) $activity->status,
            'create_time' => $activity->create_time?->format('Y-m-d H:i:s'),
        ];

        if ($withRaw) {
            $formatted = array_merge($formatted, [
                'valid_start_time' => $activity->valid_start_time?->format('Y-m-d H:i:s'),
                'valid_end_time' => $activity->valid_end_time?->format('Y-m-d H:i:s'),
                'receive_start_time' => $activity->receive_start_time?->format('Y-m-d H:i:s'),
                'receive_end_time' => $activity->receive_end_time?->format('Y-m-d H:i:s'),
                'receive_need_login' => (int) $activity->receive_need_login,
                'description' => (string) $activity->description,
            ]);
        }

        return $formatted;
    }

    /**
     * 位掩码解析为发放方式数组（表单复选框回显用）
     *
     * @return array<int,int>
     */
    protected function parseReceiveTypes(int $mask): array
    {
        $types = [];
        foreach ([CouponActivity::RECEIVE_USER, CouponActivity::RECEIVE_ADMIN, CouponActivity::RECEIVE_CODE] as $flag) {
            if (($mask & $flag) === $flag) {
                $types[] = $flag;
            }
        }

        return $types;
    }
}
