<?php

namespace App\Apps\CmsproCoupon\Tests\Unit;

use App\Apps\CmsproCoupon\Models\CouponActivity;
use App\Apps\CmsproCoupon\Models\UserCoupon;
use App\Apps\CmsproCoupon\Services\CouponRuleService;
use App\Apps\CmsproCoupon\Tests\CouponTestCase;

/**
 * 优惠规则计算单元测试
 *
 * 覆盖三类券的优惠额计算、门槛判定、折扣封顶、取整方式与边界值。
 * 券实体通过 createUserCoupon 构造（含规则快照），不污染真实数据库。
 */
class CouponRuleServiceTest extends CouponTestCase
{
    protected CouponRuleService $ruleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ruleService = new CouponRuleService();
    }

    /**
     * 构造一张券实体（仅内存对象即可参与计算，无需落库）
     */
    protected function makeCoupon(array $attributes): UserCoupon
    {
        $coupon = new UserCoupon();
        $coupon->forceFill(array_merge([
            'coupon_type' => CouponActivity::TYPE_FULL_REDUCE,
            'threshold_amount' => 0,
            'discount_amount' => 0,
            'discount_rate' => 0,
            'max_discount_amount' => 0,
        ], $attributes));

        return $coupon;
    }

    public function test_满减券达到门槛时优惠固定金额(): void
    {
        $coupon = $this->makeCoupon([
            'coupon_type' => CouponActivity::TYPE_FULL_REDUCE,
            'threshold_amount' => 100,
            'discount_amount' => 20,
        ]);

        $this->assertEquals(20.0, $this->ruleService->calculateDiscount($coupon, 150.0));
    }

    public function test_满减券恰好达到门槛时可用(): void
    {
        $coupon = $this->makeCoupon([
            'coupon_type' => CouponActivity::TYPE_FULL_REDUCE,
            'threshold_amount' => 100,
            'discount_amount' => 20,
        ]);

        $this->assertEquals(20.0, $this->ruleService->calculateDiscount($coupon, 100.0));
    }

    public function test_满减券未达到门槛时优惠为零(): void
    {
        $coupon = $this->makeCoupon([
            'coupon_type' => CouponActivity::TYPE_FULL_REDUCE,
            'threshold_amount' => 100,
            'discount_amount' => 20,
        ]);

        $this->assertEquals(0.0, $this->ruleService->calculateDiscount($coupon, 99.99));
        $this->assertFalse($this->ruleService->isApplicable($coupon, 99.99));
    }

    public function test_无门槛立减券任意金额均可用(): void
    {
        $coupon = $this->makeCoupon([
            'coupon_type' => CouponActivity::TYPE_CASH,
            'threshold_amount' => 0,
            'discount_amount' => 5,
        ]);

        $this->assertEquals(5.0, $this->ruleService->calculateDiscount($coupon, 6.0));
        $this->assertEquals(5.0, $this->ruleService->calculateDiscount($coupon, 1000.0));
    }

    public function test_立减券优惠额不超过订单金额(): void
    {
        $coupon = $this->makeCoupon([
            'coupon_type' => CouponActivity::TYPE_CASH,
            'threshold_amount' => 0,
            'discount_amount' => 50,
        ]);

        // 订单 30 元，券面 50 元：优惠封顶为订单金额
        $this->assertEquals(30.0, $this->ruleService->calculateDiscount($coupon, 30.0));
    }

    public function test_折扣券按比例计算优惠(): void
    {
        $coupon = $this->makeCoupon([
            'coupon_type' => CouponActivity::TYPE_DISCOUNT,
            'threshold_amount' => 100,
            'discount_rate' => 8.50,   // 8.5 折
            'max_discount_amount' => 0,
        ]);

        // 200 元打 8.5 折：优惠 200 × 0.15 = 30
        $this->assertEquals(30.0, $this->ruleService->calculateDiscount($coupon, 200.0));
    }

    public function test_折扣券受最高优惠封顶(): void
    {
        $coupon = $this->makeCoupon([
            'coupon_type' => CouponActivity::TYPE_DISCOUNT,
            'threshold_amount' => 100,
            'discount_rate' => 5.00,   // 5 折
            'max_discount_amount' => 30,
        ]);

        // 200 元打 5 折理论优惠 100，封顶 30
        $this->assertEquals(30.0, $this->ruleService->calculateDiscount($coupon, 200.0));
    }

    public function test_折扣券折数非法时优惠为零(): void
    {
        foreach ([0, 10, 12, -1] as $badRate) {
            $coupon = $this->makeCoupon([
                'coupon_type' => CouponActivity::TYPE_DISCOUNT,
                'threshold_amount' => 0,
                'discount_rate' => $badRate,
            ]);

            $this->assertEquals(0.0, $this->ruleService->calculateDiscount($coupon, 100.0), "折数 {$badRate} 应无优惠");
        }
    }

    public function test_订单金额为零或负数时优惠为零(): void
    {
        $coupon = $this->makeCoupon([
            'coupon_type' => CouponActivity::TYPE_CASH,
            'discount_amount' => 10,
        ]);

        $this->assertEquals(0.0, $this->ruleService->calculateDiscount($coupon, 0.0));
        $this->assertEquals(0.0, $this->ruleService->calculateDiscount($coupon, -50.0));
    }

    public function test_折扣优惠默认向下取整到分(): void
    {
        // 默认 discount_round_mode = floor
        $coupon = $this->makeCoupon([
            'coupon_type' => CouponActivity::TYPE_DISCOUNT,
            'threshold_amount' => 0,
            'discount_rate' => 8.50,
            'max_discount_amount' => 0,
        ]);

        // 99.99 × 0.15 = 14.9985 → floor 到分 = 14.99
        $this->assertEquals(14.99, $this->ruleService->calculateDiscount($coupon, 99.99));
    }

    public function test_折扣优惠按配置四舍五入到分(): void
    {
        config(['apps.cmspro.coupon.discount_round_mode' => 'round']);

        $coupon = $this->makeCoupon([
            'coupon_type' => CouponActivity::TYPE_DISCOUNT,
            'threshold_amount' => 0,
            'discount_rate' => 8.50,
            'max_discount_amount' => 0,
        ]);

        // 99.99 × 0.15 = 14.9985 → round 到分 = 15.00
        $this->assertEquals(15.0, $this->ruleService->calculateDiscount($coupon, 99.99));
    }
}
