<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>核销记录</title>
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
                        <input type="text" name="keyword" placeholder="券号 / 订单号" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <select name="status">
                            <option value="">全部状态</option>
                            <option value="1">已核销</option>
                            <option value="2">已撤销</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <input type="text" name="start_time" id="start-time" placeholder="核销开始日期" class="layui-input" autocomplete="off" readonly>
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <input type="text" name="end_time" id="end-time" placeholder="核销结束日期" class="layui-input" autocomplete="off" readonly>
                    </div>
                </div>
                <div class="layui-inline">
                    <button class="layui-btn layui-btn-md" lay-submit lay-filter="vl-query">
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
            <table id="vl-table" lay-filter="vl-table"></table>
        </div>
    </div>

    @verbatim
    <script type="text/html" id="vl-status-tpl">
        {{# if(d.status === 1){ }}
        <span class="layui-badge layui-bg-green">已核销</span>
        {{# } else { }}
        <span class="layui-badge layui-bg-gray">已撤销</span>
        {{# } }}
    </script>
    <script type="text/html" id="vl-bar">
        {{# if(d.status === 1){ }}
        <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="revoke" data-permission="cmspro.coupon.verify.revoke">撤销核销</a>
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

layui.use(['table', 'form', 'layer', 'laydate', 'jquery'], function () {
    var table = layui.table;
    var form = layui.form;
    var layer = layui.layer;
    var laydate = layui.laydate;
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
        table.reload('vl-table');
    }

    laydate.render({ elem: '#start-time', type: 'date' });
    laydate.render({ elem: '#end-time', type: 'date' });

    table.render({
        elem: '#vl-table',
        url: API_BASE + '/verify-logs',
        request: { pageName: 'page', limitName: 'per_page' },
        page: { layout: ['count', 'prev', 'page', 'next', 'limit'], groups: 5, limit: 20, limits: [10, 20, 50, 100] },
        cols: [[
            { title: 'ID', field: 'id', align: 'center', width: 80 },
            { title: '券号', field: 'coupon_no', align: 'center', width: 200 },
            { title: '用户', align: 'center', width: 120, templet: function (d) { return d.username + (d.nickname ? '(' + d.nickname + ')' : ''); } },
            { title: '订单号', field: 'order_no', align: 'center', minWidth: 180 },
            { title: '订单金额', field: 'order_amount', align: 'center', width: 100 },
            { title: '优惠金额', field: 'discount_amount', align: 'center', width: 100 },
            { title: '核销来源', field: 'caller_text', align: 'center', width: 140 },
            { title: '操作人', field: 'operator_type_name', align: 'center', width: 100 },
            { title: '状态', field: 'status', align: 'center', width: 100, templet: '#vl-status-tpl' },
            { title: '核销时间', field: 'verify_time', align: 'center', width: 170 },
            { title: '撤销时间', field: 'cancel_time', align: 'center', width: 170, templet: function (d) { return d.cancel_time || '-'; } },
            { title: '操作', toolbar: '#vl-bar', align: 'center', width: 110, fixed: 'right' }
        ]],
        skin: false,
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

    form.on('submit(vl-query)', function (data) {
        table.reload('vl-table', { where: data.field, page: { curr: 1 } });
        return false;
    });

    table.on('toolbar(vl-table)', function (obj) {
        if (obj.event === 'refresh') { table.reload('vl-table'); }
    });

    table.on('tool(vl-table)', function (obj) {
        if (obj.event === 'revoke') {
            revokeVerify(obj.data.id, obj.data.coupon_no);
        }
    });

    // 撤销核销：原因可选，layer.prompt 强制非空，故改用自定义弹窗
    function revokeVerify(id, couponNo) {
        layer.open({
            type: 1,
            title: '撤销核销：' + couponNo,
            shade: [0.5, '#000'],
            shadeClose: 0,
            closeBtn: 1,
            area: ['440px', '260px'],
            end: function () { reloadTable(); },
            content: '<div style="padding:20px">' +
                '<div class="layui-form-item">' +
                '<label class="layui-form-label">撤销原因</label>' +
                '<div class="layui-input-block">' +
                '<textarea id="revoke-reason" placeholder="可选，最多255字" maxlength="255" class="layui-textarea" style="min-height:90px"></textarea>' +
                '</div></div>' +
                '<div style="text-align:center;margin-top:15px">' +
                '<button type="button" class="layui-btn layui-btn-danger" id="btn-do-revoke">确认撤销</button></div>' +
                '</div>',
            success: function () {
                $('#btn-do-revoke').on('click', function () {
                    var loading = layer.load();
                    $.ajax({
                        url: API_BASE + '/verify-logs/' + id + '/revoke',
                        type: 'PUT',
                        dataType: 'json',
                        contentType: 'application/json',
                        data: JSON.stringify({ reason: $('#revoke-reason').val() || '' }),
                        success: function (res) {
                            layer.close(loading);
                            if (res.code === 0) {
                                layer.closeAll('page');
                                layer.msg(res.message || '撤销成功', { icon: 1, time: 1000 });
                            } else {
                                layer.msg(res.message || '撤销失败', { icon: 2 });
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
    }
});
</script>
</body>
</html>
