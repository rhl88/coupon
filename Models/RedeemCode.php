<?php

namespace App\Apps\CmsproCoupon\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 兑换码模型
 *
 * 一码一券。code 唯一索引 + 兑换时行锁，保证并发安全。
 */
class RedeemCode extends BaseModel
{
    protected $table = 'app_cmspro_coupon_redeem_codes';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    /** 兑换码状态 */
    public const STATUS_UNUSED = 0;     // 未使用
    public const STATUS_USED = 1;       // 已使用
    public const STATUS_VOID = 2;       // 已作废

    /**
     * 兑换码字符集
     *
     * 刻意排除易混淆字符（0/O、1/I/L），避免用户抄录出错。
     */
    public const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $fillable = [
        'code',
        'activity_id',
        'batch_no',
        'status',
        'user_id',
        'user_coupon_id',
        'redeem_time',
        'admin_id',
    ];

    protected $casts = [
        'activity_id' => 'integer',
        'status' => 'integer',
        'user_id' => 'integer',
        'user_coupon_id' => 'integer',
        'admin_id' => 'integer',
        'redeem_time' => 'datetime',
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
            self::STATUS_VOID => '已作废',
        ];
    }

    /**
     * 生成随机兑换码
     *
     * @param int    $length 码体长度（不含前缀），建议 8-16
     * @param string $prefix 统一前缀，可为空
     * @return string 兑换码
     */
    public static function generateCode(int $length = 10, string $prefix = ''): string
    {
        $length = max(6, min(20, $length));
        $max = strlen(self::CODE_ALPHABET) - 1;
        $body = '';

        for ($i = 0; $i < $length; $i++) {
            $body .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        $code = strtoupper(trim($prefix)) . $body;

        // 兑换码整体长度不得超过字段上限
        return mb_substr($code, 0, 50);
    }

    /**
     * 是否可被兑换（未使用状态）
     */
    public function isRedeemable(): bool
    {
        return (int) $this->status === self::STATUS_UNUSED;
    }

    /**
     * 关联券活动
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(CouponActivity::class, 'activity_id');
    }

    /**
     * 关联兑换用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 关联兑换生成的用户券
     */
    public function userCoupon(): BelongsTo
    {
        return $this->belongsTo(UserCoupon::class, 'user_coupon_id');
    }
}
