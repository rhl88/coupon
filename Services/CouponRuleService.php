<?php

namespace App\Apps\CmsproCoupon\Services;

use App\Apps\CmsproCoupon\Models\CouponActivity;
use App\Apps\CmsproCoupon\Models\UserCoupon;

/**
 * 优惠券规则计算服务
 *
 * 负责纯粹的「优惠金额计算」，不涉及数据库写入，可被核销、试算、
 * 可用券排序等多个场景复用。所有金额统一以元为单位、保留两位小数。
 *
 * 计算口径（三类券统一）：
 * - 满减券：订单额 >= 门槛 时优惠 discount_amount
 * - 折扣券：订单额 >= 门槛 时优惠 订单额 × (1 − 折数/10)，受 max_discount_amount 封顶
 * - 立减券：门槛恒为 0，优惠 discount_amount
 *
 * 兜底规则：优惠额取整到分、不超过订单额、不小于 0。
 */
class CouponRuleService
{
    /**
     * 判断某张券对指定订单金额是否可用（满足门槛）
     *
     * @param UserCoupon $coupon     用户优惠券（含规则快照）
     * @param float      $orderAmount 订单金额
     * @return bool true=满足门槛可使用
     */
    public function isApplicable(UserCoupon $coupon, float $orderAmount): bool
    {
        $threshold = (float) $coupon->threshold_amount;

        // 浮点比较容差：避免 99.99999 与 100.00 的边界误判
        return $orderAmount + 0.00001 >= $threshold;
    }

    /**
     * 计算单张券对订单金额的优惠额
     *
     * @param UserCoupon $coupon      用户优惠券
     * @param float      $orderAmount 订单金额
     * @return float 优惠金额（已取整到分，且不超过订单额）；不可用时返回 0.00
     */
    public function calculateDiscount(UserCoupon $coupon, float $orderAmount): float
    {
        if ($orderAmount <= 0 || ! $this->isApplicable($coupon, $orderAmount)) {
            return 0.00;
        }

        // 折扣券按比例计算，满减券与立减券直接取快照的固定优惠额
        $couponType = (int) $coupon->coupon_type;
        $rawDiscount = $couponType === CouponActivity::TYPE_DISCOUNT
            ? $this->calculateDiscountRate($coupon, $orderAmount)
            : (float) $coupon->discount_amount;

        return $this->clamp($rawDiscount, $orderAmount);
    }

    /**
     * 折扣券优惠额：订单额 × (1 − 折数/10)，受封顶限制
     */
    protected function calculateDiscountRate(UserCoupon $coupon, float $orderAmount): float
    {
        $rate = (float) $coupon->discount_rate;

        // 折数必须在 (0,10) 开区间，非法配置视为无优惠
        if ($rate <= 0 || $rate >= 10) {
            return 0.00;
        }

        $discount = $orderAmount * (10 - $rate) / 10;
        $maxDiscount = (float) $coupon->max_discount_amount;

        if ($maxDiscount > 0 && $discount > $maxDiscount) {
            $discount = $maxDiscount;
        }

        return $discount;
    }

    /**
     * 优惠额收口：按配置取整到分，且不超过订单额、不小于 0
     */
    protected function clamp(float $discount, float $orderAmount): float
    {
        $discount = $this->roundToCent($discount);

        if ($discount > $orderAmount) {
            $discount = $this->roundToCent($orderAmount);
        }

        return max(0.00, $discount);
    }

    /**
     * 按配置的取整方式保留两位小数
     *
     * floor：向下取整到分（平台不超发，默认）
     * round：四舍五入到分（更直观）
     */
    protected function roundToCent(float $amount): float
    {
        $mode = (string) cmspro_coupon_config('discount_round_mode', 'floor');

        if ($mode === 'round') {
            return round($amount + 0.00001, 2);
        }

        return floor($amount * 100 + 0.00001) / 100;
    }
}
