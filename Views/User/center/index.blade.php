<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>领券中心</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    <style>
        /* 色彩取自 CMSPRO 语义色板（警告橙/危险红），禁止色板外颜色 */
        .coupon-list { display: flex; flex-wrap: wrap; gap: 15px; padding: 5px 0; }
        .coupon-card {
            width: 320px; border: 1px solid #e6e6e6; border-radius: 8px; overflow: hidden;
            display: flex; background: #fff; transition: box-shadow .2s;
        }
        .coupon-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.1); }
        .coupon-left {
            width: 110px; flex-shrink: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center; color: #fff; padding: 20px 5px;
            background: linear-gradient(135deg, #ffb800, #ff5722);
        }
        .coupon-left .amount { font-size: 26px; font-weight: 700; line-height: 1.2; }
        .coupon-left .amount small { font-size: 14px; font-weight: 400; }
        .coupon-left .threshold { font-size: 12px; margin-top: 5px; opacity: .9; }
        .coupon-right { flex: 1; padding: 15px; display: flex; flex-direction: column; min-width: 0; }
        .coupon-right .title {
            font-size: 14px; font-weight: 600; color: #333; margin-bottom: 5px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .coupon-right .meta { font-size: 12px; color: #999; line-height: 20px; }
        .coupon-right .btn-row { margin-top: auto; padding-top: 10px; }
        /* 空状态：48px 弱化大图标 + 提示文字 */
        .empty-tip { text-align: center; color: #999; padding: 50px 0; font-size: 14px; }
        .empty-tip .empty-icon { font-size: 48px; color: #999; display: block; margin-bottom: 10px; }
        /* 骨架屏（首屏加载占位） */
        .skeleton { width: 320px; border: 1px solid #e6e6e6; border-radius: 8px; display: flex; overflow: hidden; }
        .skeleton .sk-left { width: 110px; flex-shrink: 0; height: 96px; background: #eee; animation: sk-pulse 1.2s ease-in-out infinite; }
        .skeleton .sk-right { flex: 1; padding: 15px; }
        .skeleton .sk-line { height: 14px; border-radius: 5px; background: #eee; margin-bottom: 10px; animation: sk-pulse 1.2s ease-in-out infinite; }
        .skeleton .sk-line.w60 { width: 60%; }
        .skeleton .sk-line.w40 { width: 40%; }
        @keyframes sk-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
        .redeem-bar { display: flex; gap: 10px; max-width: 480px; }
        .redeem-bar .layui-input { flex: 1; }
        /* 减弱动效偏好 */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>
<div class="pear-container">
    {{-- 兑换码兑换卡 --}}
    <div class="layui-card">
        <div class="layui-card-header">兑换码兑换</div>
        <div class="layui-card-body">
            <div class="redeem-bar">
                <input type="text" id="redeem-code" placeholder="请输入兑换码" maxlength="50" class="layui-input" autocomplete="off">
                <button type="button" class="layui-btn layui-btn-normal" id="btn-redeem">
                    <i class="fa fa-key"></i> 立即兑换
                </button>
            </div>
        </div>
    </div>

    {{-- 可领取券列表卡 --}}
    <div class="layui-card">
        <div class="layui-card-header">可领取的优惠券</div>
        <div class="layui-card-body">
            <div class="coupon-list" id="coupon-list">
                <div class="skeleton"><div class="sk-left"></div><div class="sk-right"><div class="sk-line w60"></div><div class="sk-line w40"></div><div class="sk-line w60"></div></div></div>
                <div class="skeleton"><div class="sk-left"></div><div class="sk-right"><div class="sk-line w60"></div><div class="sk-line w40"></div><div class="sk-line w60"></div></div></div>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
var API_BASE = '/api/user/cmspro/coupon';

layui.use(['layer', 'jquery'], function () {
    var layer = layui.layer;
    var $ = layui.jquery;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        statusCode: {
            401: function () {
                layer.msg('登录已过期，请重新登录', { icon: 2, time: 1500 }, function () {
                    top.location.href = '/user/login';
                });
            }
        }
    });

    // HTML 转义，防止活动标题/说明中的特殊字符破坏结构（XSS 防护）
    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // 空状态：48px 弱化大图标 + 提示文字
    function emptyHtml(text) {
        return '<div class="empty-tip"><i class="fa fa-ticket empty-icon" aria-hidden="true"></i>' + esc(text) + '</div>';
    }

    // 统一解析 AJAX 错误：优先取后端 message（401 已由 statusCode 处理）
    function apiError(xhr, fallback) {
        if (xhr && xhr.status === 401) { return; }
        var res = xhr && xhr.responseJSON;
        return layer.msg((res && res.message) || (fallback || '请求失败'), { icon: 2 });
    }

    function loadActivities() {
        $.get(API_BASE + '/activities', function (res) {
            var $list = $('#coupon-list');
            if (res.code !== 0) {
                $list.html(emptyHtml(res.message || '加载失败'));
                return;
            }
            if (!res.data || res.data.length === 0) {
                $list.html(emptyHtml('暂无可领取的优惠券'));
                return;
            }
            var html = '';
            res.data.forEach(function (a) {
                var amountHtml;
                if (a.coupon_type === 2) {
                    amountHtml = '<span class="amount">' + a.discount_rate + '<small>折</small></span>';
                } else {
                    amountHtml = '<span class="amount"><small>¥</small>' + a.discount_amount + '</span>';
                }
                var btnHtml = a.can_receive
                    ? '<button class="layui-btn layui-btn-sm layui-btn-danger btn-receive" data-id="' + a.id + '">立即领取</button>'
                    : '<button class="layui-btn layui-btn-sm layui-btn-disabled" disabled>' + esc(a.disabled_reason || '不可领取') + '</button>';

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
        }).fail(function () {
            $('#coupon-list').html('<div class="empty-tip">加载失败，请刷新重试</div>');
        });
    }

    // 领取（事件委托）
    $('#coupon-list').on('click', '.btn-receive', function () {
        var id = $(this).data('id');
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({
            url: API_BASE + '/receive/' + id,
            type: 'POST',
            success: function (res) {
                if (res.code === 0) {
                    layer.msg('领取成功', { icon: 1, time: 1500 });
                    loadActivities();
                } else {
                    layer.msg(res.message || '领取失败', { icon: 2 });
                    $btn.prop('disabled', false);
                }
            },
            error: function (xhr) {
                apiError(xhr);
                $btn.prop('disabled', false);
            }
        });
    });

    // 兑换码兑换
    $('#btn-redeem').on('click', function () {
        var code = $('#redeem-code').val().trim();
        if (!code) {
            layer.msg('请输入兑换码', { icon: 0, time: 1200 });
            return;
        }
        var loading = layer.load();
        $.ajax({
            url: API_BASE + '/redeem',
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify({ code: code }),
            success: function (res) {
                layer.close(loading);
                if (res.code === 0) {
                    layer.msg('兑换成功，请前往「我的优惠券」查看', { icon: 1, time: 2000 });
                    $('#redeem-code').val('');
                    loadActivities();
                } else {
                    layer.msg(res.message || '兑换失败', { icon: 2 });
                }
            },
            error: function (xhr) {
                layer.close(loading);
                apiError(xhr);
            }
        });
    });

    loadActivities();
});
</script>
</body>
</html>
