<?php

namespace App\Apps\CmsproCoupon\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 券活动（券模板）模型
 *
 * 定义一类优惠券的完整规则。发放时对规则做快照写入用户券表，
 * 因此活动规则调整不影响已发放的券。
 */
class CouponActivity extends BaseModel
{
    protected $table = 'app_cmspro_coupon_activities';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    /** 券类型 */
    public const TYPE_FULL_REDUCE = 1;  // 满减券
    public const TYPE_DISCOUNT = 2;     // 折扣券
    public const TYPE_CASH = 3;         // 无门槛立减券

    /** 有效期类型 */
    public const VALID_FIXED = 1;       // 固定时间段
    public const VALID_RELATIVE = 2;    // 领取后 N 天内有效

    /** 发放方式（位掩码，可叠加） */
    public const RECEIVE_USER = 1;      // 用户主动领取
    public const RECEIVE_ADMIN = 2;     // 后台定向发放
    public const RECEIVE_CODE = 4;      // 兑换码兑换

    protected $fillable = [
        'code',
        'title',
        'coupon_type',
        'threshold_amount',
        'discount_amount',
        'discount_rate',
        'max_discount_amount',
        'total_stock',
        'issued_count',
        'per_user_limit',
        'valid_type',
        'valid_start_time',
        'valid_end_time',
        'valid_days',
        'receive_start_time',
        'receive_end_time',
        'receive_type',
        'receive_need_login',
        'description',
        'sort',
        'status',
    ];

    protected $casts = [
        'coupon_type' => 'integer',
        'threshold_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'total_stock' => 'integer',
        'issued_count' => 'integer',
        'per_user_limit' => 'integer',
        'valid_type' => 'integer',
        'valid_start_time' => 'datetime',
        'valid_end_time' => 'datetime',
        'valid_days' => 'integer',
        'receive_start_time' => 'datetime',
        'receive_end_time' => 'datetime',
        'receive_type' => 'integer',
        'receive_need_login' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];

    /**
     * 券类型映射
     *
     * @return array<int,string>
     */
    public static function couponTypeMap(): array
    {
        return [
            self::TYPE_FULL_REDUCE => '满减券',
            self::TYPE_DISCOUNT => '折扣券',
            self::TYPE_CASH => '无门槛立减券',
        ];
    }

    /**
     * 获取券类型名称
     */
    public function getCouponTypeNameAttribute(): string
    {
        return self::couponTypeMap()[$this->coupon_type] ?? '未知';
    }

    /**
     * 优惠力度文案（用于列表与前端展示）
     *
     * 满减券/立减券：减 X 元；折扣券：X 折（可含封顶说明）
     */
    public function getDiscountTextAttribute(): string
    {
        if ($this->coupon_type === self::TYPE_DISCOUNT) {
            $rate = rtrim(rtrim(number_format((float) $this->discount_rate, 2, '.', ''), '0'), '.');
            $text = $rate . '折';

            if ((float) $this->max_discount_amount > 0) {
                $text .= '（最高减' . $this->formatAmount($this->max_discount_amount) . '元）';
            }

            return $text;
        }

        return '减' . $this->formatAmount($this->discount_amount) . '元';
    }

    /**
     * 使用门槛文案
     */
    public function getThresholdTextAttribute(): string
    {
        if ((float) $this->threshold_amount <= 0) {
            return '无门槛';
        }

        return '满' . $this->formatAmount($this->threshold_amount) . '元可用';
    }

    /**
     * 是否支持指定发放方式
     *
     * @param int $flag RECEIVE_USER / RECEIVE_ADMIN / RECEIVE_CODE
     */
    public function supportReceive(int $flag): bool
    {
        return ((int) $this->receive_type & $flag) === $flag;
    }

    /**
     * 是否为上架状态
     */
    public function isOnShelf(): bool
    {
        return (int) $this->status === 1;
    }

    /**
     * 剩余库存（total_stock=0 表示不限量，返回 -1）
     */
    public function getRemainStock(): int
    {
        if ((int) $this->total_stock <= 0) {
            return -1;
        }

        return max(0, (int) $this->total_stock - (int) $this->issued_count);
    }

    /**
     * 当前是否处于可领取时间窗内
     *
     * receive_start_time / receive_end_time 为空表示该侧不限制。
     */
    public function isInReceiveWindow(?string $now = null): bool
    {
        $now = $now ?? now()->format('Y-m-d H:i:s');

        if ($this->receive_start_time !== null && $now < $this->receive_start_time->format('Y-m-d H:i:s')) {
            return false;
        }

        if ($this->receive_end_time !== null && $now > $this->receive_end_time->format('Y-m-d H:i:s')) {
            return false;
        }

        return true;
    }

    /**
     * 上架且在领取时间窗内的活动
     */
    public function scopeReceivable(Builder $query): Builder
    {
        $now = now()->format('Y-m-d H:i:s');

        return $query->where('status', 1)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('receive_start_time')->orWhere('receive_start_time', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('receive_end_time')->orWhere('receive_end_time', '>=', $now);
            });
    }

    /**
     * 关联的用户优惠券
     */
    public function userCoupons(): HasMany
    {
        return $this->hasMany(UserCoupon::class, 'activity_id');
    }

    /**
     * 关联的兑换码
     */
    public function redeemCodes(): HasMany
    {
        return $this->hasMany(RedeemCode::class, 'activity_id');
    }

    /**
     * 金额格式化：去掉多余的小数零（10.00 → 10，10.50 → 10.5）
     */
    protected function formatAmount(mixed $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }
}
