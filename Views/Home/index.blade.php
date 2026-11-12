<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>领券中心</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <style>
        /* ── Design Token（色彩取自 CMSPRO 语义色板，禁止色板外颜色）── */
        :root {
            --coupon-orange: #ffb800;      /* 警告橙 */
            --coupon-red: #ff5722;         /* 危险红 */
            --coupon-blue: #1e9fff;        /* 信息蓝 */
            --coupon-green: #16b777;       /* 成功绿 */
            --coupon-border: #e6e6e6;      /* 边框灰 */
            --coupon-bg: whitesmoke;       /* 背景灰 */
            --coupon-text: #333;           /* 正文 */
            --coupon-text-sub: #666;       /* 次要文字 */
            --coupon-text-mute: #999;      /* 弱化文字 */
            --header-height: 96px;
        }
        body { margin: 0; background: var(--coupon-bg); }
        .page-header {
            background: linear-gradient(135deg, var(--coupon-orange), var(--coupon-red)); color: #fff;
            padding: 30px 20px; text-align: center;
        }
        .page-header h1 { margin: 0; font-size: 20px; }
        .page-header p { margin: 5px 0 0; opacity: .9; font-size: 12px; }
        .container { max-width: 960px; margin: -20px auto 30px; padding: 0 15px; }
        .card { background: #fff; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
        .card + .card { margin-top: 15px; }
        .card-title { font-size: 16px; font-weight: 600; color: var(--coupon-text); margin-bottom: 15px; display: flex; align-items: center; }
        .card-title i { color: var(--coupon-red); margin-right: 5px; }
        .coupon-list { display: grid; grid-template-columns: 1fr; gap: 15px; }
        .coupon-card { border: 1px solid var(--coupon-border); border-radius: 8px; display: flex; overflow: hidden; }
        .coupon-left {
            width: 120px; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: #fff; padding: 20px 5px; background: linear-gradient(135deg, var(--coupon-orange), var(--coupon-red));
        }
        .coupon-left .amount { font-size: 28px; font-weight: 700; line-height: 1.2; }
        .coupon-left .amount small { font-size: 14px; font-weight: 400; }
        .coupon-left .threshold { font-size: 12px; margin-top: 5px; opacity: .9; }
        .coupon-right { flex: 1; padding: 15px; display: flex; flex-direction: column; min-width: 0; }
        .coupon-right .title { font-size: 14px; font-weight: 600; color: var(--coupon-text); margin-bottom: 5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .coupon-right .meta { font-size: 12px; color: var(--coupon-text-mute); line-height: 20px; }
        .coupon-right .btn-row { margin-top: auto; padding-top: 10px; }
        .btn {
            display: inline-block; padding: 10px 20px; border: none; border-radius: 20px; cursor: pointer;
            font-size: 14px; color: #fff; background: linear-gradient(135deg, var(--coupon-orange), var(--coupon-red));
            transition: opacity .25s, background .25s; min-height: 44px;
        }
        .btn:active { opacity: .8; }
        .btn:disabled { background: #ccc; cursor: not-allowed; }
        .redeem-row { display: flex; gap: 10px; max-width: 480px; }
        .redeem-row input {
            flex: 1; padding: 10px 15px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; outline: none;
            min-height: 44px; box-sizing: border-box;
        }
        /* 输入框字号 ≥16px 防 iOS 聚焦缩放；焦点态提供可见描边 */
        .redeem-row input:focus { border-color: var(--coupon-blue); box-shadow: 0 0 0 2px rgba(30,159,255,.2); }
        /* 空状态：48px 弱化大图标 + 提示文字 */
        .empty-tip { text-align: center; color: var(--coupon-text-mute); padding: 50px 0; font-size: 14px; }
        .empty-tip .empty-icon { font-size: 48px; color: var(--coupon-text-mute); display: block; margin-bottom: 10px; }
        /* 骨架屏（首屏加载占位） */
        .skeleton { border: 1px solid var(--coupon-border); border-radius: 8px; display: flex; overflow: hidden; }
        .skeleton .sk-left { width: 120px; flex-shrink: 0; height: 96px; background: #eee; animation: sk-pulse 1.2s ease-in-out infinite; }
        .skeleton .sk-right { flex: 1; padding: 15px; }
        .skeleton .sk-line { height: 14px; border-radius: 4px; background: #eee; margin-bottom: 10px; animation: sk-pulse 1.2s ease-in-out infinite; }
        .skeleton .sk-line.w60 { width: 60%; }
        .skeleton .sk-line.w40 { width: 40%; }
        @keyframes sk-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        .login-bar { text-align: center; margin-top: 10px; font-size: 12px; color: var(--coupon-text-sub); }
        .login-bar a { color: var(--coupon-blue); text-decoration: none; padding: 10px 5px; display: inline-block; }
        /* 轻提示 */
        #toast {
            position: fixed; top: 30px; left: 50%; transform: translateX(-50%); z-index: 999;
            background: rgba(0,0,0,.75); color: #fff; padding: 10px 20px; border-radius: 5px;
            font-size: 14px; display: none; max-width: 80%;
        }
        /* 响应式断点（UI 规范 10.3） */
        @media (min-width: 768px) {
            .coupon-list { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 359px) {
            .container { padding: 0 10px; }
            .card { padding: 15px; }
            .coupon-left { width: 100px; }
        }
        /* 减弱动效偏好（UI 规范 12.3） */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>
<div class="page-header">
    <h1><i class="fa fa-ticket"></i> 领券中心</h1>
    <p>优惠券限时领取，先到先得</p>
</div>

<div class="container">
    <div class="card">
        <div class="card-title"><i class="fa fa-gift"></i>可领取的优惠券</div>
        <div class="coupon-list" id="coupon-list">
            <div class="skeleton"><div class="sk-left"></div><div class="sk-right"><div class="sk-line w60"></div><div class="sk-line w40"></div><div class="sk-line w60"></div></div></div>
            <div class="skeleton"><div class="sk-left"></div><div class="sk-right"><div class="sk-line w60"></div><div class="sk-line w40"></div><div class="sk-line w60"></div></div></div>
        </div>
        <div class="login-bar" id="login-bar" style="display:none">
            登录后可领取优惠券　<a href="{{ cmspro_coupon_main_url('user/login') }}">立即登录</a>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><i class="fa fa-key"></i>兑换码兑换</div>
        <div class="redeem-row">
            <input type="text" id="redeem-code" placeholder="请输入兑换码" maxlength="50" autocomplete="off">
            <button type="button" class="btn" id="btn-redeem">立即兑换</button>
        </div>
        <div class="login-bar" style="margin-top:12px">
            已领取的优惠券可在　<a href="{{ cmspro_coupon_main_url('user/cmspro/coupon/my-coupons') }}">我的优惠券</a>　中查看
        </div>
    </div>
</div>

<div id="toast"></div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script>
// 前端 API 为全局注册（api/cmspro/coupon，不带 domain），子域名下由框架 catch-all 放行 api/ 前缀；
// 用户端领取/兑换接口与登录页属主域名，domain 模式下必须回主域名调用（cmspro_coupon_main_url 生成）。
var API_BASE = '{{ cmspro_coupon_main_url('api/cmspro/coupon') }}';
var USER_API_BASE = '{{ cmspro_coupon_main_url('api/user/cmspro/coupon') }}';
var LOGIN_URL = '{{ cmspro_coupon_main_url('user/login') }}';

layui.use(['jquery'], function () {
    var $ = layui.jquery;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        statusCode: {
            401: function () {
                window.location.href = LOGIN_URL;
            }
        }
    });

    var isLogin = false;
    var receiveEnabled = true;

    function toast(msg, ok) {
        var $t = $('#toast');
        $t.text(msg).css('background', ok === false ? 'rgba(255,87,34,.9)' : 'rgba(0,0,0,.75)').fadeIn(150);
        clearTimeout(window.__toastTimer);
        window.__toastTimer = setTimeout(function () { $t.fadeOut(250); }, 2000);
    }

    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // 统一错误提示：优先解析后端返回的 message，401 跳转登录
    function showError(xhr, fallback) {
        if (xhr.status === 401) {
            window.location.href = LOGIN_URL;
            return;
        }
        var msg = fallback;
        if (xhr.responseJSON && xhr.responseJSON.message) {
            msg = xhr.responseJSON.message;
        } else if (xhr.responseJSON && xhr.responseJSON.errors) {
            var keys = Object.keys(xhr.responseJSON.errors);
            if (keys.length) { msg = xhr.responseJSON.errors[keys[0]][0]; }
        }
        toast(msg, false);
    }

    function emptyHtml(text) {
        return '<div class="empty-tip"><i class="fa fa-ticket empty-icon" aria-hidden="true"></i>' + esc(text) + '</div>';
    }

    function loadActivities() {
        $.get(API_BASE + '/center/activities', function (res) {
            var $list = $('#coupon-list');
            if (res.code !== 0 || !res.data) {
                $list.html(emptyHtml(res.message || '加载失败'));
                return;
            }
            isLogin = res.data.is_login;
            receiveEnabled = res.data.receive_enabled;

            var activities = res.data.activities || [];
            if (activities.length === 0) {
                $list.html(emptyHtml('暂无可领取的优惠券'));
                $('#login-bar').hide();
                return;
            }

            var html = '';
            activities.forEach(function (a) {
                var amountHtml;
                if (a.coupon_type === 2) {
                    amountHtml = '<span class="amount">' + a.discount_rate + '<small>折</small></span>';
                } else {
                    amountHtml = '<span class="amount"><small>¥</small>' + a.discount_amount + '</span>';
                }
                var btnHtml = a.can_receive
                    ? '<button class="btn btn-receive" data-id="' + a.id + '">立即领取</button>'
                    : '<button class="btn" disabled>' + esc(a.disabled_reason || '不可领取') + '</button>';

                html += '<div class="coupon-card">' +
                    '<div class="coupon-left">' + amountHtml +
                    '<div class="threshold">' + esc(a.threshold_text) + '</div></div>' +
                    '<div class="coupon-right">' +
                    '<div class="title">' + esc(a.title) + '</div>' +
                    '<div class="meta">' + esc(a.valid_text) + '</div>' +
                    '<div class="meta">剩余：' + esc(a.remain_stock_text) + (a.per_user_limit > 0 ? '　限领 ' + a.per_user_limit + ' 张' : '') + '</div>' +
                    '<div class="btn-row">' + btnHtml + '</div>' +
                    '</div></div>';
            });
            $list.html(html);

            // 未登录时展示登录引导
            $('#login-bar').toggle(!isLogin);
        }).fail(function () {
            $('#coupon-list').html(emptyHtml('加载失败，请刷新重试'));
        });
    }

    $('#coupon-list').on('click', '.btn-receive', function () {
        if (!receiveEnabled) {
            toast('当前未开放领取', false);
            return;
        }
        if (!isLogin) {
            window.location.href = LOGIN_URL;
            return;
        }
        var id = $(this).data('id');
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: USER_API_BASE + '/receive/' + id,
            type: 'POST',
            success: function (res) {
                if (res.code === 0) {
                    toast('领取成功，可在「我的优惠券」查看');
                    loadActivities();
                } else {
                    toast(res.message || '领取失败', false);
                    $btn.prop('disabled', false);
                }
            },
            error: function (xhr) {
                showError(xhr, '请求失败');
                $btn.prop('disabled', false);
            }
        });
    });

    $('#btn-redeem').on('click', function () {
        if (!isLogin) {
            window.location.href = LOGIN_URL;
            return;
        }
        var code = $('#redeem-code').val().trim();
        if (!code) {
            toast('请输入兑换码', false);
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: USER_API_BASE + '/redeem',
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify({ code: code }),
            success: function (res) {
                $btn.prop('disabled', false);
                if (res.code === 0) {
                    toast('兑换成功，可在「我的优惠券」查看');
                    $('#redeem-code').val('');
                    loadActivities();
                } else {
                    toast(res.message || '兑换失败', false);
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false);
                showError(xhr, '请求失败');
            }
        });
    });

    loadActivities();
});
</script>
</body>
</html>
