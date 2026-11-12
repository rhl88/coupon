<?php

namespace App\Apps\CmsproCoupon\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 用户优惠券模型
 *
 * 券规则字段均为发放时从活动快照而来，保证「券一旦到手规则不变」。
 */
class UserCoupon extends BaseModel
{
    protected $table = 'app_cmspro_coupon_user_coupons';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    /** 券状态 */
    public const STATUS_UNUSED = 0;     // 未使用
    public const STATUS_USED = 1;       // 已使用
    public const STATUS_EXPIRED = 2;    // 已过期
    public const STATUS_VOID = 3;       // 已作废

    /** 券来源 */
    public const SOURCE_RECEIVE = 1;    // 用户主动领取
    public const SOURCE_ISSUE = 2;      // 后台定向发放
    public const SOURCE_REDEEM = 3;     // 兑换码兑换

    protected $fillable = [
        'coupon_no',
        'activity_id',
        'user_id',
        'title',
        'coupon_type',
        'threshold_amount',
        'discount_amount',
        'discount_rate',
        'max_discount_amount',
        'description',
        'valid_start_time',
        'valid_end_time',
        'source',
        'source_id',
        'status',
        'use_time',
        'order_no',
        'receive_time',
    ];

    protected $casts = [
        'activity_id' => 'integer',
        'user_id' => 'integer',
        'coupon_type' => 'integer',
        'threshold_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'valid_start_time' => 'datetime',
        'valid_end_time' => 'datetime',
        'source' => 'integer',
        'status' => 'integer',
        'use_time' => 'datetime',
        'receive_time' => 'datetime',
    ];

    /**
     * 状态映射
     *
     * @return array<int,string>
     */
    public static function statusMap(): array
    {
        return [
            self::STATUS_UNUSED => '未使用',
            self::STATUS_USED => '已使用',
            self::STATUS_EXPIRED => '已过期',
            self::STATUS_VOID => '已作废',
        ];
    }

    /**
     * 来源映射
     *
     * @return array<int,string>
     */
    public static function sourceMap(): array
    {
        return [
            self::SOURCE_RECEIVE => '用户领取',
            self::SOURCE_ISSUE => '后台发放',
            self::SOURCE_REDEEM => '兑换码兑换',
        ];
    }

    /**
     * 当前是否真实可用（未使用 + 未过期 + 未作废）
     *
     * 惰性过期判定：status 仍为 0 但 valid_end_time 已过时返回 false，
     * 避免因定时任务尚未执行而误判可用。
     */
    public function isUsable(?string $now = null): bool
    {
        if ((int) $this->status !== self::STATUS_UNUSED) {
            return false;
        }

        $now = $now ?? now()->format('Y-m-d H:i:s');

        if ($this->valid_start_time !== null && $now < $this->valid_start_time->format('Y-m-d H:i:s')) {
            return false;
        }

        if ($this->valid_end_time !== null && $now > $this->valid_end_time->format('Y-m-d H:i:s')) {
            return false;
        }

        return true;
    }

    /**
     * 真实可用的券（惰性过期判定，供可用券查询统一使用）
     */
    public function scopeUsable(Builder $query): Builder
    {
        $now = now()->format('Y-m-d H:i:s');

        return $query->where('status', self::STATUS_UNUSED)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('valid_start_time')->orWhere('valid_start_time', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('valid_end_time')->orWhere('valid_end_time', '>=', $now);
            });
    }

    /**
     * 已过期待清理的券（定时命令用）
     */
    public function scopeExpiredPending(Builder $query): Builder
    {
        $now = now()->format('Y-m-d H:i:s');

        return $query->where('status', self::STATUS_UNUSED)
            ->whereNotNull('valid_end_time')
            ->where('valid_end_time', '<', $now);
    }

    /**
     * 关联券活动
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(CouponActivity::class, 'activity_id');
    }

    /**
     * 关联前台用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 关联核销记录
     */
    public function verifyLogs(): HasMany
    {
        return $this->hasMany(VerifyLog::class, 'user_coupon_id');
    }
}
