<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>发放批次</title>
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
                        <input type="text" name="keyword" placeholder="批次号 / 活动名称" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <select name="target_type">
                            <option value="">全部发放对象</option>
                            <option value="1">指定用户</option>
                            <option value="2">全部用户</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <button class="layui-btn layui-btn-md" lay-submit lay-filter="ib-query">
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
            <table id="ib-table" lay-filter="ib-table"></table>
        </div>
    </div>

    <script type="text/html" id="ib-bar">
        <a class="layui-btn layui-btn-xs layui-btn-normal" lay-event="detail">详情</a>
    </script>
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

    table.render({
        elem: '#ib-table',
        url: API_BASE + '/issue-batches',
        request: { pageName: 'page', limitName: 'per_page' },
        page: { layout: ['count', 'prev', 'page', 'next', 'limit'], groups: 5, limit: 20, limits: [10, 20, 50, 100] },
        cols: [[
            { title: 'ID', field: 'id', align: 'center', width: 80 },
            { title: '批次号', field: 'batch_no', align: 'center', width: 200 },
            { title: '券活动', field: 'activity_title', align: 'left', minWidth: 160 },
            { title: '发放对象', field: 'target_type_name', align: 'center', width: 110 },
            { title: '目标用户数', field: 'total_count', align: 'center', width: 110 },
            { title: '成功', field: 'success_count', align: 'center', width: 90 },
            { title: '失败', field: 'fail_count', align: 'center', width: 90 },
            { title: '发放时间', field: 'create_time', align: 'center', width: 170 },
            { title: '操作', toolbar: '#ib-bar', align: 'center', width: 90, fixed: 'right' }
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
        }
    });

    form.on('submit(ib-query)', function (data) {
        table.reload('ib-table', { where: data.field, page: { curr: 1 } });
        return false;
    });

    table.on('toolbar(ib-table)', function (obj) {
        if (obj.event === 'refresh') { table.reload('ib-table'); }
    });

    table.on('tool(ib-table)', function (obj) {
        if (obj.event === 'detail') {
            showDetail(obj.data.id);
        }
    });

    function showDetail(id) {
        $.get(API_BASE + '/issue-batches/' + id, function (res) {
            if (res.code !== 0 || !res.data) {
                layer.msg(res.message || '批次不存在', { icon: 2 });
                return;
            }
            var d = res.data;
            var html = '<div style="padding:20px;line-height:28px">' +
                '<p><b>批次号：</b>' + d.batch_no + '</p>' +
                '<p><b>券活动：</b>' + d.activity_title + '</p>' +
                '<p><b>发放对象：</b>' + d.target_type_name + '</p>' +
                '<p><b>目标用户：</b>' + d.total_count + '　<b>成功：</b>' + d.success_count + '　<b>失败：</b>' + d.fail_count + '</p>' +
                '<p><b>备注：</b>' + (d.remark || '-') + '</p>' +
                '<p><b>发放时间：</b>' + d.create_time + '</p>';

            var fails = d.fail_detail || [];
            if (fails.length > 0) {
                html += '<p style="margin-top:8px"><b>失败明细（最多展示100条）：</b></p>';
                html += '<div style="max-height:180px;overflow-y:auto;border:1px solid #eee;padding:8px;font-size:12px">';
                fails.forEach(function (f) {
                    html += '<div>用户ID ' + f.user_id + '：' + f.reason + '</div>';
                });
                html += '</div>';
            }

            html += '</div>';
            layer.open({ type: 1, title: '批次详情', area: ['560px', '520px'], content: html });
        });
    }
});
</script>
</body>
</html>
