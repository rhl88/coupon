<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>用户优惠券</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
</head>
<body>
<div class="pear-container">
    <div class="layui-card">
        <div class="layui-card-body">
            <form class="layui-form" action="">
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <input type="text" name="keyword" placeholder="券号 / 券名 / 订单号" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <input type="text" name="user_id" placeholder="用户ID" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <select name="status">
                            <option value="">全部状态</option>
                            <option value="0">未使用</option>
                            <option value="1">已使用</option>
                            <option value="2">已过期</option>
                            <option value="3">已作废</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <select name="coupon_type">
                            <option value="">全部券类型</option>
                            <option value="1">满减券</option>
                            <option value="2">折扣券</option>
                            <option value="3">无门槛立减券</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <button class="layui-btn layui-btn-md" lay-submit lay-filter="uc-query">
                        <i class="layui-icon layui-icon-search"></i> 查询
                    </button>
                    <button type="reset" class="layui-btn layui-btn-md layui-btn-primary">
                        <i class="layui-icon layui-icon-refresh"></i> 重置
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="layui-card">
        <div class="layui-card-body">
            <table id="uc-table" lay-filter="uc-table"></table>
        </div>
    </div>

    <script type="text/html" id="uc-toolbar">
        <button class="layui-btn layui-btn-danger layui-btn-sm" lay-event="batchInvalidate" data-permission="cmspro.coupon.usercoupon.view">
            <i class="layui-icon layui-icon-close"></i> 批量作废
        </button>
    </script>

    @verbatim
    <script type="text/html" id="uc-status-tpl">
        {{# if(d.effective_status === 0){ }}
        <span class="layui-badge layui-bg-green">未使用</span>
        {{# } else if(d.effective_status === 1){ }}
        <span class="layui-badge layui-bg-blue">已使用</span>
        {{# } else if(d.effective_status === 2){ }}
        <span class="layui-badge layui-bg-gray">已过期</span>
        {{# } else { }}
        <span class="layui-badge layui-bg-black">已作废</span>
        {{# } }}
    </script>
    <script type="text/html" id="uc-bar">
        {{# if(d.effective_status === 0){ }}
        <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="invalidate" data-permission="cmspro.coupon.usercoupon.view">作废</a>
        {{# } else { }}
        <span style="color:#999">-</span>
        {{# } }}
    </script>
    @endverbatim
</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
var API_BASE = '/admin/cmspro/coupon/api';

layui.use(['table', 'form', 'layer', 'jquery'], function () {
    var table = layui.table;
    var form = layui.form;
    var layer = layui.layer;
    var $ = layui.jquery;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        statusCode: {
            401: function () {
                layer.msg('登录已过期，请重新登录', { icon: 2, time: 1500 }, function () {
                    top.location.href = '/admin/login';
                });
            }
        }
    });

    // 统一解析 AJAX 错误：优先取后端 message，避免硬编码文案（401 已由 statusCode 统一处理）
    function apiError(xhr, fallback) {
        var res = xhr && xhr.responseJSON;
        return layer.msg((res && res.message) || (fallback || '请求失败'), { icon: 2 });
    }

    // 列表页刷新：表格实例属于当前 iframe，直接刷新（视图规范 4.2）
    function reloadTable() {
        table.reload('uc-table');
    }

    table.render({
        elem: '#uc-table',
        url: API_BASE + '/user-coupons',
        request: { pageName: 'page', limitName: 'per_page' },
        page: { layout: ['count', 'prev', 'page', 'next', 'limit'], groups: 5, limit: 20, limits: [10, 20, 50, 100] },
        cols: [[
            { type: 'checkbox', fixed: 'left' },
            { title: 'ID', field: 'id', align: 'center', width: 80 },
            { title: '券号', field: 'coupon_no', align: 'center', width: 200 },
            { title: '券名称', field: 'title', align: 'left', minWidth: 150 },
            { title: '用户', align: 'center', width: 120, templet: function (d) { return d.username + (d.nickname ? '(' + d.nickname + ')' : ''); } },
            { title: '券类型', field: 'coupon_type_name', align: 'center', width: 110 },
            { title: '门槛', field: 'threshold_text', align: 'center', width: 110 },
            { title: '来源', field: 'source_name', align: 'center', width: 100 },
            { title: '有效期', align: 'center', width: 190, templet: function (d) {
                return (d.valid_start_time ? d.valid_start_time.substring(0, 10) : '即时') + ' 至 ' + (d.valid_end_time ? d.valid_end_time.substring(0, 10) : '长期');
            } },
            { title: '状态', field: 'effective_status', align: 'center', width: 100, templet: '#uc-status-tpl' },
            { title: '核销订单', field: 'order_no', align: 'center', width: 160, templet: function (d) { return d.order_no || '-'; } },
            { title: '领取时间', field: 'receive_time', align: 'center', width: 170 },
            { title: '操作', toolbar: '#uc-bar', align: 'center', width: 90, fixed: 'right' }
        ]],
        skin: false,
        toolbar: '#uc-toolbar',
        defaultToolbar: [{ title: '刷新', layEvent: 'refresh', icon: 'layui-icon-refresh' }, 'filter'],
        parseData: function (res) {
            return {
                code: res.code === 0 ? 0 : 1,
                msg: res.message || '',
                count: res.data && res.data.pagination ? res.data.pagination.total : 0,
                data: res.data ? res.data.items : []
            };
        },
        done: function () {
            $('td [data-permission]').each(function () {
                if (!window.hasPermission || !window.hasPermission($(this).data('permission'))) {
                    $(this).hide();
                }
            });
        }
    });

    $('button[data-permission]').each(function () {
        if (!window.hasPermission || !window.hasPermission($(this).data('permission'))) {
            $(this).hide();
        }
    });

    form.on('submit(uc-query)', function (data) {
        table.reload('uc-table', { where: data.field, page: { curr: 1 } });
        return false;
    });

    table.on('toolbar(uc-table)', function (obj) {
        if (obj.event === 'refresh') {
            table.reload('uc-table');
        } else if (obj.event === 'batchInvalidate') {
            var checkData = table.checkStatus('uc-table').data;
            var ids = checkData.filter(function (c) { return c.effective_status === 0; }).map(function (c) { return c.id; });
            if (ids.length === 0) {
                layer.msg('选中的券中没有可作废的（仅未使用状态可作废）', { icon: 0, time: 2000 });
                return;
            }
            layer.confirm('确定要作废选中的 ' + ids.length + ' 张优惠券？', { icon: 3, title: '操作提示' }, function (index) {
                layer.close(index);
                var loading = layer.load();
                $.ajax({
                    url: API_BASE + '/user-coupons/batch-invalidate',
                    type: 'PUT',
                    dataType: 'json',
                    contentType: 'application/json',
                    data: JSON.stringify({ ids: ids }),
                    success: function (res) {
                        layer.close(loading);
                        if (res.code === 0) {
                            layer.msg(res.message || '已作废', { icon: 1, time: 1000 }, function () { reloadTable(); });
                        } else {
                            layer.msg(res.message || '作废失败', { icon: 2 });
                        }
                    },
                    error: function (xhr) {
                        layer.close(loading);
                        apiError(xhr);
                    }
                });
            });
        }
    });

    table.on('tool(uc-table)', function (obj) {
        if (obj.event === 'invalidate') {
            layer.confirm('确定要作废券「' + obj.data.coupon_no + '」？', { icon: 3, title: '操作提示' }, function (index) {
                layer.close(index);
                var loading = layer.load();
                $.ajax({
                    url: API_BASE + '/user-coupons/' + obj.data.id + '/invalidate',
                    type: 'PUT',
                    success: function (res) {
                        layer.close(loading);
                        if (res.code === 0) {
                            layer.msg('已作废', { icon: 1, time: 1000 }, function () { reloadTable(); });
                        } else {
                            layer.msg(res.message || '作废失败', { icon: 2 });
                        }
                    },
                    error: function (xhr) {
                        layer.close(loading);
                        apiError(xhr);
                    }
                });
            });
        }
    });
});
</script>
</body>
</html>
