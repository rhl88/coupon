<?php

/**
 * 优惠券应用 · 前端核销 / 查询 API 路由
 *
 * 挂载信息（由 ServiceProvider::registerHomeRoutes() 指定）：
 * - 前缀：api/cmspro/coupon（全局固定，不随 access_mode 变化）
 * - 中间件：['web','throttle:60,1']（限流 60 次/分钟防刷）
 *
 * 鉴权方式（已与用户确认）：
 * - 核销 / 试算 / 可用券查询走 auth:web 用户态，谁登录核销谁的券
 * - 其他应用服务端集成推荐直接在 PHP 层注入 VerifyService 调用，无 HTTP 开销
 *
 * 响应统一为 App\Http\Responses\ApiResponse 结构：
 * { code: 0, message: 'success', data: {...}, timestamp: 1700000000 }
 */

use App\Apps\CmsproCoupon\Controllers\HomeApi\CenterApiController;
use App\Apps\CmsproCoupon\Controllers\HomeApi\VerifyApiController;
use Illuminate\Support\Facades\Route;

// 领券中心公开数据：游客可浏览活动列表（已登录时附带个人领取状态）
Route::get('/center/activities', [CenterApiController::class, 'activities']);

// 查询当前用户在指定订单金额下的可用券（需登录）
Route::get('/available', [VerifyApiController::class, 'available'])
    ->middleware('auth:web');

// 核销前试算：返回可用券与预计优惠额，不改变券状态（需登录）
Route::post('/preview', [VerifyApiController::class, 'preview'])
    ->middleware('auth:web');

// 核销优惠券：将券置为已使用并写核销流水（需登录）
Route::post('/verify', [VerifyApiController::class, 'verify'])
    ->middleware('auth:web');

// 按券号查询单券状态（供收银台扫码核销场景，需登录）
Route::get('/coupon/{couponNo}', [VerifyApiController::class, 'showByNo'])
    ->middleware('auth:web');
