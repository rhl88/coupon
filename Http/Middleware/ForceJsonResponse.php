<?php

namespace App\Apps\CmsproCoupon\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 应用级「强制 JSON 响应」中间件
 *
 * 保证 validate() 抛出的 ValidationException 按 expectsJson() 走 JSON 分支，
 * 避免前端以 jQuery/fetch 调用应用 API（默认 Accept 为通配类型）时
 * 校验失败被框架转成 302 重定向（常见问题第 1 章）。
 *
 * 仅挂载到 API 路由组（admin_api / user_api / home-api），
 * 不影响页面路由（admin / user / home）的 HTML 响应。
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
