<?php

/**
 * 优惠券应用 · 用户中心端页面路由
 *
 * 挂载信息（由 ServiceProvider::registerRoutes() 指定）：
 * - 前缀：user/cmspro/coupon
 * - 中间件：['web','auth:web']
 *
 * 用户端为 PearAdmin iframe 多标签页模式，视图必须是独立完整 HTML 页面，
 * 禁止 @extends('layouts.user')（会导致布局嵌套，见开发文档注意事项第 17 条）。
 */

use App\Apps\CmsproCoupon\Controllers\User\CenterController;
use App\Apps\CmsproCoupon\Controllers\User\MyCouponController;
use Illuminate\Support\Facades\Route;

// 领券中心
Route::get('/center', [CenterController::class, 'index']);

// 我的优惠券
Route::get('/my-coupons', [MyCouponController::class, 'index']);
