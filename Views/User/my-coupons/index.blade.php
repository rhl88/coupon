<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>我的优惠券</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    <style>
        /* 色彩取自 CMSPRO 语义色板：警告橙 #ffb800 / 危险红 #ff5722 / 信息蓝 #1e9fff / 成功绿 #16b777 / 中性灰 #5f5f5f / 边框灰 #e6e6e6 */
        .stat-bar { display: flex; gap: 30px; padding: 5px 0 15px; }
        .stat-item { text-align: center; }
        .stat-item .num { font-size: 24px; font-weight: 700; color: #1e9fff; }
        .stat-item .label { font-size: 12px; color: #999; margin-top: 5px; }
        .coupon-card {
            border: 1px solid #e6e6e6; border-radius: 8px; margin-bottom: 10px;
            display: flex; overflow: hidden; background: #fff;
        }
        .coupon-card.is-disabled { opacity: .6; }
        .coupon-card .left {
            width: 120px; flex-shrink: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center; color: #fff; padding: 20px 5px;
            background: linear-gradient(135deg, #ffb800, #ff5722);
        }
        .coupon-card.is-disabled .left { background: #5f5f5f; }
        .coupon-card .left .amount { font-size: 26px; font-weight: 700; }
        .coupon-card .left .amount small { font-size: 14px; font-weight: 400; }
        .coupon-card .left .threshold { font-size: 12px; margin-top: 5px; opacity: .9; }
        .coupon-card .right { flex: 1; padding: 15px; min-width: 0; }
        .coupon-card .right .title { font-size: 14px; font-weight: 600; color: #333; margin-bottom: 5px; }
        .coupon-card .right .meta { font-size: 12px; color: #999; line-height: 20px; }
        .badge-expiring { background: #ff5722; color: #fff; font-size: 12px; padding: 1px 5px; border-radius: 4px; margin-left: 5px; }
        .status-tag { float: right; font-size: 12px; padding: 2px 10px; border-radius: 4px; }
        .status-tag.unused { background: #16b777; color: #fff; }
        .status-tag.used { background: #1e9fff; color: #fff; }
        .status-tag.expired, .status-tag.void { background: #5f5f5f; color: #fff; }
        .pager { text-align: center; padding: 15px 0; }
        .empty-tip { text-align: center; color: #999; padding: 50px 0; font-size: 14px; }
        .empty-tip .empty-icon { font-size: 48px; color: #999; display: block; margin-bottom: 10px; }
        .tab-bar { margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="pear-container">
    <div class="layui-card">
        <div class="layui-card-body">
            {{-- 统计卡 --}}
            <div class="stat-bar" id="stat-bar">
                <div class="stat-item"><div class="num" id="stat-usable">0</div><div class="label">可使用</div></div>
                <div class="stat-item"><div class="num" id="stat-expiring">0</div><div class="label">即将过期</div></div>
                <div class="stat-item"><div class="num" id="stat-used">0</div><div class="label">已使用</div></div>
                <div class="stat-item"><div class="num" id="stat-expired">0</div><div class="label">已过期</div></div>
            </div>

            {{-- 状态筛选 Tab --}}
            <div class="tab-bar">
                <div class="layui-btn-group">
                    <button class="layui-btn layui-btn-sm tab-btn" data-status="usable">可使用</button>
                    <button class="layui-btn layui-btn-sm layui-btn-primary tab-btn" data-status="used">已使用</button>
                    <button class="layui-btn layui-btn-sm layui-btn-primary tab-btn" data-status="expired">已过期</button>
                    <button class="layui-btn layui-btn-sm layui-btn-primary tab-btn" data-status="">全部</button>
                </div>
            </div>

            {{-- 券列表 --}}
            <div id="coupon-list"><div class="empty-tip">加载中...</div></div>
            <div class="pager"><div id="pager"></div></div>
        </div>
    </div>
</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
var API_BASE = '/api/user/cmspro/coupon';

layui.use(['layer', 'laypage', 'jquery'], function () {
    var layer = layui.layer;
    var laypage = layui.laypage;
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

    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    var currentStatus = 'usable';
    var currentPage = 1;
    var PER_PAGE = 10;

    function loadStats() {
        $.get(API_BASE + '/my-coupons/stats', function (res) {
            if (res.code === 0 && res.data) {
                $('#stat-usable').text(res.data.usable);
                $('#stat-expiring').text(res.data.expiring_soon);
                $('#stat-used').text(res.data.used);
                $('#stat-expired').text(res.data.expired);
            }
        });
    }

    function statusClass(status) {
        return { 0: 'unused', 1: 'used', 2: 'expired', 3: 'void' }[status] || 'unused';
    }

    // 空状态：48px 弱化大图标 + 提示文字
    function emptyHtml(text) {
        return '<div class="empty-tip"><i class="fa fa-ticket empty-icon" aria-hidden="true"></i>' + esc(text) + '</div>';
    }

    function loadList(page) {
        currentPage = page || 1;
        $.get(API_BASE + '/my-coupons', { status: currentStatus, page: currentPage, limit: PER_PAGE }, function (res) {
            var $list = $('#coupon-list');
            if (res.code !== 0 || !res.data) {
                $list.html(emptyHtml(res.message || '加载失败'));
                return;
            }
            var items = res.data.items || [];
            var total = res.data.pagination ? res.data.pagination.total : 0;

            if (items.length === 0) {
                $list.html(emptyHtml('暂无优惠券' + (currentStatus === 'usable' ? '，去领券中心看看吧' : '')));
                $('#pager').empty();
                return;
            }

            var html = '';
            items.forEach(function (c) {
                var amountHtml;
                if (c.coupon_type === 2) {
                    amountHtml = '<span class="amount">' + c.discount_rate + '<small>折</small></span>';
                } else {
                    amountHtml = '<span class="amount"><small>¥</small>' + c.discount_amount + '</span>';
                }
                var expiringBadge = c.expiring_soon ? '<span class="badge-expiring">' + c.remain_days + '天后过期</span>' : '';
                var usedInfo = c.status === 1 && c.order_no ? '<div class="meta">核销订单：' + esc(c.order_no) + '</div>' : '';

                html += '<div class="coupon-card' + (c.can_use ? '' : ' is-disabled') + '">' +
                    '<div class="left">' + amountHtml +
                    '<div class="threshold">' + esc(c.threshold_text) + '</div></div>' +
                    '<div class="right">' +
                    '<span class="status-tag ' + statusClass(c.status) + '">' + esc(c.status_name) + '</span>' +
                    '<div class="title">' + esc(c.title) + expiringBadge + '</div>' +
                    '<div class="meta">' + esc(c.valid_text) + '</div>' +
                    '<div class="meta">券号：' + esc(c.coupon_no) + '　来源：' + esc(c.source_name) + '</div>' +
                    usedInfo +
                    '</div></div>';
            });
            $list.html(html);

            laypage.render({
                elem: 'pager',
                count: total,
                limit: PER_PAGE,
                curr: currentPage,
                layout: ['prev', 'page', 'next'],
                jump: function (obj, first) {
                    if (!first) { loadList(obj.curr); }
                }
            });
        }).fail(function () {
            $('#coupon-list').html(emptyHtml('加载失败，请刷新重试'));
        });
    }

    // Tab 切换
    $('.tab-btn').on('click', function () {
        $('.tab-btn').addClass('layui-btn-primary');
        $(this).removeClass('layui-btn-primary');
        currentStatus = $(this).data('status').toString();
        loadList(1);
    });

    loadStats();
    loadList(1);
});
</script>
</body>
</html>
