<?php

namespace App\Apps\CmsproCoupon\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 发放批次模型
 *
 * 后台定向发放的批次记录，含成功/失败计数与失败明细。
 */
class IssueBatch extends BaseModel
{
    protected $table = 'app_cmspro_coupon_issue_batches';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    /** 发放对象 */
    public const TARGET_SPECIFIED = 1;  // 指定用户
    public const TARGET_ALL = 2;        // 全部用户

    /** 批次状态 */
    public const STATUS_PROCESSING = 0; // 处理中
    public const STATUS_FINISHED = 1;   // 已完成

    /**
     * 失败明细最多保留条数（避免 text 字段无限膨胀）
     */
    public const FAIL_DETAIL_LIMIT = 100;

    protected $fillable = [
        'batch_no',
        'activity_id',
        'activity_title',
        'target_type',
        'total_count',
        'success_count',
        'fail_count',
        'fail_detail',
        'admin_id',
        'remark',
        'status',
    ];

    protected $casts = [
        'activity_id' => 'integer',
        'target_type' => 'integer',
        'total_count' => 'integer',
        'success_count' => 'integer',
        'fail_count' => 'integer',
        'fail_detail' => 'array',
        'admin_id' => 'integer',
        'status' => 'integer',
    ];

    /**
     * 发放对象映射
     *
     * @return array<int,string>
     */
    public static function targetTypeMap(): array
    {
        return [
            self::TARGET_SPECIFIED => '指定用户',
            self::TARGET_ALL => '全部用户',
        ];
    }

    /**
     * 关联券活动
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(CouponActivity::class, 'activity_id');
    }
}
