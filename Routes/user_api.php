<?php

/**
 * 优惠券应用 · 用户中心端数据接口路由
 *
 * 挂载信息（由 ServiceProvider::registerRoutes() 指定）：
 * - 前缀：api/user/cmspro/coupon
 * - 中间件：['web','auth:web']
 *
 * 所有查询强制限定 user_id = auth('web')->id()，数据按用户隔离。
 */

use App\Apps\CmsproCoupon\Controllers\User\CenterController;
use App\Apps\CmsproCoupon\Controllers\User\MyCouponController;
use Illuminate\Support\Facades\Route;

// ── 领券中心 ──────────────────────────────────────────────────
Route::get('/activities', [CenterController::class, 'activities']);
Route::post('/receive/{id}', [CenterController::class, 'receive'])->whereNumber('id');
Route::post('/redeem', [CenterController::class, 'redeem']);

// ── 我的优惠券 ────────────────────────────────────────────────
Route::get('/my-coupons', [MyCouponController::class, 'list']);
Route::get('/my-coupons/stats', [MyCouponController::class, 'stats']);
Route::get('/my-coupons/{id}', [MyCouponController::class, 'show'])->whereNumber('id');
