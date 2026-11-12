<?php

/**
 * 优惠券应用 · 后台数据接口路由
 *
 * 挂载信息（由 ServiceProvider::registerRoutes() 指定）：
 * - 前缀：admin/cmspro/coupon/api
 * - 中间件：['web','auth:admin','permission:admin']
 *
 * RESTful 风格：资源名小写复数 + 连字符，HTTP 方法语义正确。
 * 业务权限码在控制器内校验（系统 CheckPermission 中间件仅覆盖 api/admin/ 前缀）。
 */

use App\Apps\CmsproCoupon\Controllers\Admin\ActivityController;
use App\Apps\CmsproCoupon\Controllers\Admin\RedeemCodeController;
use App\Apps\CmsproCoupon\Controllers\Admin\SettingController;
use App\Apps\CmsproCoupon\Controllers\Admin\UserCouponController;
use App\Apps\CmsproCoupon\Controllers\Admin\VerifyLogController;
use Illuminate\Support\Facades\Route;

// ── 券活动 ────────────────────────────────────────────────────
Route::prefix('activities')->controller(ActivityController::class)->group(function () {
    Route::get('/', 'list');
    Route::get('options', 'options');
    Route::post('/', 'store');
    Route::get('{id}', 'show')->whereNumber('id');
    Route::put('{id}', 'update')->whereNumber('id');
    Route::delete('{id}', 'destroy')->whereNumber('id');
    Route::put('{id}/status', 'updateStatus')->whereNumber('id');
    Route::post('issue', 'issue');
});

// ── 兑换码 ────────────────────────────────────────────────────
Route::prefix('codes')->controller(RedeemCodeController::class)->group(function () {
    Route::get('/', 'list');
    Route::post('generate', 'generate');
    Route::delete('{id}', 'destroy')->whereNumber('id');
    Route::delete('batch-void', 'batchVoid');
    Route::get('export', 'export');
});

// ── 用户优惠券 ────────────────────────────────────────────────
Route::prefix('user-coupons')->controller(UserCouponController::class)->group(function () {
    Route::get('/', 'list');
    Route::put('{id}/invalidate', 'invalidate')->whereNumber('id');
    Route::put('batch-invalidate', 'batchInvalidate');
});

// ── 核销记录 ──────────────────────────────────────────────────
Route::prefix('verify-logs')->controller(VerifyLogController::class)->group(function () {
    Route::get('/', 'list');
    Route::put('{id}/revoke', 'revoke')->whereNumber('id');
});

// ── 发放批次 ──────────────────────────────────────────────────
Route::prefix('issue-batches')->controller(VerifyLogController::class)->group(function () {
    Route::get('/', 'batchList');
    Route::get('overview', 'overview');
    Route::get('{id}', 'batchDetail')->whereNumber('id');
});

// ── 设置 ──────────────────────────────────────────────────────
Route::get('settings', [SettingController::class, 'getSettings']);
Route::put('settings', [SettingController::class, 'update']);
Route::get('settings/check-path', [SettingController::class, 'checkPath']);
