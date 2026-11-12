<?php

namespace App\Apps\CmsproCoupon\Controllers\Home;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * 前端 · 领券中心页面控制器
 *
 * 游客可浏览（中间件仅 ['web']），领取动作由用户端接口层校验登录。
 * 页面为服务端渲染 + 相对链接，path/domain 两种访问模式通用。
 */
class CenterController extends Controller
{
    /**
     * 领券中心页面
     */
    public function index(): View
    {
        return view('cmspro.coupon::Home.index');
    }
}
