<?php

/**
 * 优惠券应用 · 后台页面路由
 *
 * 挂载信息（由 ServiceProvider::registerRoutes() 指定）：
 * - 前缀：admin/cmspro/coupon
 * - 中间件：['web','auth:admin','permission:admin']
 *
 * 仅返回视图，数据由 admin_api.php 中的接口异步提供。
 */

use App\Apps\CmsproCoupon\Controllers\Admin\ActivityController;
use App\Apps\CmsproCoupon\Controllers\Admin\RedeemCodeController;
use App\Apps\CmsproCoupon\Controllers\Admin\SettingController;
use App\Apps\CmsproCoupon\Controllers\Admin\UserCouponController;
use App\Apps\CmsproCoupon\Controllers\Admin\VerifyLogController;
use Illuminate\Support\Facades\Route;

// 券活动管理
Route::get('/activities', [ActivityController::class, 'index']);
Route::get('/activities/form', [ActivityController::class, 'form']);
Route::get('/activities/issue', [ActivityController::class, 'issueForm']);

// 兑换码管理
Route::get('/codes', [RedeemCodeController::class, 'index']);

// 用户优惠券
Route::get('/user-coupons', [UserCouponController::class, 'index']);

// 核销记录
Route::get('/verify-logs', [VerifyLogController::class, 'index']);

// 发放批次
Route::get('/issue-batches', [VerifyLogController::class, 'batches']);

// 优惠券设置
Route::get('/settings', [SettingController::class, 'index']);
