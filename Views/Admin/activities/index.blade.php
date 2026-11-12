<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>券活动管理</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
</head>
<body>
<div class="pear-container">
    {{-- 搜索条件卡 --}}
    <div class="layui-card">
        <div class="layui-card-body">
            <form class="layui-form" action="">
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <input type="text" name="keyword" placeholder="活动名称 / 编码" class="layui-input" autocomplete="off">
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
                    <div class="layui-input-inline">
                        <select name="status">
                            <option value="">全部状态</option>
                            <option value="1">上架</option>
                            <option value="0">下架</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <button class="layui-btn layui-btn-md" lay-submit lay-filter="activity-query">
                        <i class="layui-icon layui-icon-search"></i> 查询
                    </button>
                    <button type="reset" class="layui-btn layui-btn-md layui-btn-primary">
                        <i class="layui-icon layui-icon-refresh"></i> 重置
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- 表格卡 --}}
    <div class="layui-card">
        <div class="layui-card-body">
            <table id="activity-table" lay-filter="activity-table"></table>
        </div>
    </div>

    {{-- 工具栏 --}}
    <script type="text/html" id="activity-toolbar">
        <button class="layui-btn layui-btn-sm" lay-event="add" data-permission="cmspro.coupon.activity.create">
            <i class="layui-icon layui-icon-add-1"></i> 新增券活动
        </button>
    </script>

    {{-- 状态开关模板 --}}
    @verbatim
    <script type="text/html" id="activity-status-tpl">
        <input type="checkbox" name="status" value="{{ d.id }}" lay-skin="switch" lay-text="上架|下架"
               lay-filter="activity-status" data-id="{{ d.id }}" {{ d.status == 1 ? 'checked' : '' }}>
    </script>
    <script type="text/html" id="activity-stock-tpl">
        {{ d.total_stock == 0 ? '不限量' : (d.issued_count + ' / ' + d.total_stock) }}
    </script>
    <script type="text/html" id="activity-bar">
        <div class="layui-btn-container">
            <a class="layui-btn layui-btn-xs" lay-event="more">操作 <i class="layui-icon layui-icon-down"></i></a>
        </div>
    </script>
    @endverbatim
</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
var API_BASE = '/admin/cmspro/coupon/api';

layui.use(['table', 'form', 'layer', 'jquery', 'dropdown'], function () {
    var table = layui.table;
    var form = layui.form;
    var layer = layui.layer;
    var $ = layui.jquery;
    var dropdown = layui.dropdown;

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

    // 无权限时禁用状态开关（后端 hasPermission 兜底，前端为 UX 优化）
    var canUpdateStatus = !window.hasPermission || window.hasPermission('cmspro.coupon.activity.update');

    var cols = [[
        { title: 'ID', field: 'id', align: 'center', width: 70, sort: true },
        { title: '活动名称', field: 'title', align: 'left', minWidth: 160 },
        { title: '编码', field: 'code', align: 'center', width: 120 },
        { title: '券类型', field: 'coupon_type_name', align: 'center', width: 110 },
        { title: '优惠力度', field: 'discount_text', align: 'center', width: 130 },
        { title: '使用门槛', field: 'threshold_text', align: 'center', width: 120 },
        { title: '已发/总量', align: 'center', width: 110, templet: '#activity-stock-tpl' },
        { title: '已用', field: 'used_count', align: 'center', width: 70 },
        { title: '状态', field: 'status', align: 'center', width: 110, templet: '#activity-status-tpl' },
        { title: '操作', toolbar: '#activity-bar', align: 'center', width: 90, fixed: 'right' }
    ]];

    var inst = table.render({
        elem: '#activity-table',
        url: API_BASE + '/activities',
        request: { pageName: 'page', limitName: 'per_page' },
        page: { layout: ['count', 'prev', 'page', 'next', 'limit'], groups: 5, limit: 20, limits: [10, 20, 50, 100] },
        cols: cols,
        skin: false,
        toolbar: '#activity-toolbar',
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

    // 工具栏按钮权限控制
    var hidePermButtons = function () {
        $('button[data-permission]').each(function () {
            if (!window.hasPermission || !window.hasPermission($(this).data('permission'))) {
                $(this).hide();
            }
        });
    };
    hidePermButtons();

    form.on('submit(activity-query)', function (data) {
        table.reload('activity-table', { where: data.field, page: { curr: 1 } });
        return false;
    });

    // 状态开关：无感知更新，仅刷新当前行
    form.on('switch(activity-status)', function (obj) {
        var id = $(this).data('id');
        var newStatus = obj.elem.checked ? 1 : 0;
        var $tr = $(obj.elem).closest('tr');
        // 无权限拦截：回滚开关状态并提示（I26）
        if (!canUpdateStatus) {
            obj.elem.checked = !obj.elem.checked;
            form.render('switch');
            layer.msg('无操作权限', { icon: 2, time: 1000 });
            return;
        }
        $.ajax({
            url: API_BASE + '/activities/' + id + '/status',
            type: 'PUT',
            data: { status: newStatus },
            success: function (res) {
                if (res.code === 0) {
                    layer.msg(newStatus === 1 ? '已上架' : '已下架', { icon: 1, time: 1000 });
                    var rowData = table.cache['activity-table'].filter(function (item) { return item.id == id; })[0];
                    if (rowData) {
                        rowData.status = newStatus;
                        table.updateRow('activity-table', { index: $tr.data('index'), data: rowData });
                    }
                } else {
                    layer.msg(res.message || '操作失败', { icon: 2 });
                    obj.elem.checked = !obj.elem.checked;
                    form.render('switch');
                }
            },
            error: function (xhr) {
                apiError(xhr);
                obj.elem.checked = !obj.elem.checked;
                form.render('switch');
            }
        });
    });

    table.on('toolbar(activity-table)', function (obj) {
        if (obj.event === 'add') {
            openForm(0);
        } else if (obj.event === 'refresh') {
            table.reload('activity-table');
        }
    });

    table.on('tool(activity-table)', function (obj) {
        if (obj.event === 'more') {
            dropdown.render({
                elem: this,
                show: true,
                align: 'right',
                data: [
                    { title: '编辑', id: 'edit', permission: 'cmspro.coupon.activity.update' },
                    { title: '定向发放', id: 'issue', permission: 'cmspro.coupon.issue.manage' },
                    { title: '删除', id: 'del', permission: 'cmspro.coupon.activity.delete' }
                ].filter(function (item) {
                    return !item.permission || !window.hasPermission || window.hasPermission(item.permission);
                }),
                click: function (menuData) {
                    if (menuData.id === 'edit') {
                        openForm(obj.data.id);
                    } else if (menuData.id === 'issue') {
                        openIssue(obj.data.id, obj.data.title);
                    } else if (menuData.id === 'del') {
                        removeActivity(obj.data.id);
                    }
                }
            });
        }
    });

    function openForm(id) {
        layer.open({
            type: 2,
            title: id ? '编辑券活动' : '新增券活动',
            shade: [0.5, '#000'],
            shadeClose: 0,
            closeBtn: 1,
            area: ['760px', '640px'],
            content: '/admin/cmspro/coupon/activities/form?id=' + id,
            end: function () { reloadTable(); }
        });
    }

    function openIssue(id, title) {
        layer.open({
            type: 2,
            title: '定向发放：' + title,
            shade: [0.5, '#000'],
            shadeClose: 0,
            closeBtn: 1,
            area: ['560px', '460px'],
            content: '/admin/cmspro/coupon/activities/issue?id=' + id,
            end: function () { reloadTable(); }
        });
    }

    function removeActivity(id) {
        layer.confirm('确定要删除该券活动？删除后无法恢复！', { icon: 3, title: '操作提示' }, function (index) {
            layer.close(index);
            var loading = layer.load();
            $.ajax({
                url: API_BASE + '/activities/' + id,
                type: 'DELETE',
                success: function (res) {
                    layer.close(loading);
                    if (res.code === 0) {
                        layer.msg('删除成功', { icon: 1, time: 1000 }, function () {
                            reloadTable();
                        });
                    } else {
                        layer.msg(res.message || '删除失败', { icon: 2 });
                    }
                },
                error: function (xhr) {
                    layer.close(loading);
                    apiError(xhr);
                }
            });
        });
    }

    // 列表页刷新：表格实例属于当前 iframe，直接刷新，禁止 parent 前缀（视图规范 4.2）
    function reloadTable() {
        table.reload('activity-table');
    }

    // 供表单 iframe 提交成功后回调刷新列表
    window.reloadActivityTable = reloadTable;
});
</script>
</body>
</html>
