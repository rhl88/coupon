<?php

/**
 * 优惠券应用 · 前端页面路由
 *
 * 挂载信息（由 ServiceProvider::registerHomeRoutes() 按访问模式动态指定）：
 * - path 模式：前缀 {access_path}，如 /coupon
 * - domain 模式：Route::domain({access_domain})，子域名根路径直达
 * - 中间件：['web']（无认证，游客可浏览，领取动作在接口层校验登录）
 */

use App\Apps\CmsproCoupon\Controllers\Home\CenterController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CenterController::class, 'index']);
Route::get('/center', [CenterController::class, 'index']);
