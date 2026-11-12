<?php

namespace App\Apps\CmsproCoupon\Models;

use App\Models\AdminUser;
use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 核销记录模型
 *
 * 每次核销写一条流水；撤销核销置 status=2 并记录撤销信息，保留审计痕迹。
 */
class VerifyLog extends BaseModel
{
    protected $table = 'app_cmspro_coupon_verify_logs';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    /** 记录状态 */
    public const STATUS_VERIFIED = 1;   // 已核销
    public const STATUS_REVOKED = 2;    // 已撤销

    /** 操作人类型 */
    public const OPERATOR_USER = 1;     // 用户自助
    public const OPERATOR_ADMIN = 2;    // 后台管理员
    public const OPERATOR_SYSTEM = 3;   // 系统

    protected $fillable = [
        'user_coupon_id',
        'coupon_no',
        'user_id',
        'order_no',
        'order_amount',
        'discount_amount',
        'caller_app_id',
        'operator_type',
        'operator_id',
        'status',
        'verify_time',
        'cancel_time',
        'cancel_reason',
        'remark',
    ];

    protected $casts = [
        'user_coupon_id' => 'integer',
        'user_id' => 'integer',
        'order_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'operator_type' => 'integer',
        'operator_id' => 'integer',
        'status' => 'integer',
        'verify_time' => 'datetime',
        'cancel_time' => 'datetime',
    ];

    /**
     * 状态映射
     *
     * @return array<int,string>
     */
    public static function statusMap(): array
    {
        return [
            self::STATUS_VERIFIED => '已核销',
            self::STATUS_REVOKED => '已撤销',
        ];
    }

    /**
     * 操作人类型映射
     *
     * @return array<int,string>
     */
    public static function operatorTypeMap(): array
    {
        return [
            self::OPERATOR_USER => '用户自助',
            self::OPERATOR_ADMIN => '后台管理员',
            self::OPERATOR_SYSTEM => '系统',
        ];
    }

    /**
     * 关联用户优惠券
     */
    public function userCoupon(): BelongsTo
    {
        return $this->belongsTo(UserCoupon::class, 'user_coupon_id');
    }

    /**
     * 关联前台用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 关联操作管理员（operator_type=2 时有值）
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'operator_id');
    }
}
