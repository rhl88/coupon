<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>兑换码管理</title>
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
                        <input type="text" name="keyword" placeholder="兑换码" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <select name="activity_id" id="search-activity">
                            <option value="">全部券活动</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <select name="status">
                            <option value="">全部状态</option>
                            <option value="0">未使用</option>
                            <option value="1">已使用</option>
                            <option value="2">已作废</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <input type="text" name="batch_no" placeholder="批次号" class="layui-input" autocomplete="off">
                    </div>
                </div>
                <div class="layui-inline">
                    <button class="layui-btn layui-btn-md" lay-submit lay-filter="code-query">
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
            <table id="code-table" lay-filter="code-table"></table>
        </div>
    </div>

    <script type="text/html" id="code-toolbar">
        <button class="layui-btn layui-btn-sm" lay-event="generate" data-permission="cmspro.coupon.code.generate">
            <i class="layui-icon layui-icon-add-1"></i> 批量生成
        </button>
        <button class="layui-btn layui-btn-danger layui-btn-sm" lay-event="batchVoid" data-permission="cmspro.coupon.code.destroy">
            <i class="layui-icon layui-icon-close"></i> 批量作废
        </button>
        <button class="layui-btn layui-btn-normal layui-btn-sm" lay-event="export" data-permission="cmspro.coupon.code.view">
            <i class="layui-icon layui-icon-export"></i> 导出当前批次
        </button>
    </script>

    @verbatim
    <script type="text/html" id="code-status-tpl">
        {{# if(d.status === 0){ }}
        <span class="layui-badge layui-bg-green">未使用</span>
        {{# } else if(d.status === 1){ }}
        <span class="layui-badge layui-bg-blue">已使用</span>
        {{# } else { }}
        <span class="layui-badge layui-bg-gray">已作废</span>
        {{# } }}
    </script>
    <script type="text/html" id="code-bar">
        {{# if(d.status === 0){ }}
        <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="void" data-permission="cmspro.coupon.code.destroy">作废</a>
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
        table.reload('code-table');
    }

    // 券活动下拉（仅显示支持兑换码方式的活动）
    var activityCache = [];
    $.get(API_BASE + '/activities/options', function (res) {
        if (res.code === 0 && res.data) {
            activityCache = res.data;
            var html = '<option value="">全部券活动</option>';
            res.data.forEach(function (item) {
                html += '<option value="' + item.id + '">' + item.title + '</option>';
            });
            $('#search-activity').html(html);
            form.render('select');
        }
    });

    var inst = table.render({
        elem: '#code-table',
        url: API_BASE + '/codes',
        request: { pageName: 'page', limitName: 'per_page' },
        page: { layout: ['count', 'prev', 'page', 'next', 'limit'], groups: 5, limit: 20, limits: [10, 20, 50, 100] },
        cols: [[
            { type: 'checkbox', fixed: 'left' },
            { title: 'ID', field: 'id', align: 'center', width: 80 },
            { title: '兑换码', field: 'code', align: 'center', minWidth: 160 },
            { title: '券活动', field: 'activity_title', align: 'center', minWidth: 140 },
            { title: '批次号', field: 'batch_no', align: 'center', width: 180 },
            { title: '状态', field: 'status', align: 'center', width: 100, templet: '#code-status-tpl' },
            { title: '兑换用户', field: 'user_id', align: 'center', width: 100, templet: function (d) { return d.user_id || '-'; } },
            { title: '兑换时间', field: 'redeem_time', align: 'center', width: 170, templet: function (d) { return d.redeem_time || '-'; } },
            { title: '生成时间', field: 'create_time', align: 'center', width: 170 },
            { title: '操作', toolbar: '#code-bar', align: 'center', width: 90, fixed: 'right' }
        ]],
        skin: false,
        toolbar: '#code-toolbar',
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

    var hidePermButtons = function () {
        $('button[data-permission]').each(function () {
            if (!window.hasPermission || !window.hasPermission($(this).data('permission'))) {
                $(this).hide();
            }
        });
    };
    hidePermButtons();

    form.on('submit(code-query)', function (data) {
        table.reload('code-table', { where: data.field, page: { curr: 1 } });
        return false;
    });

    table.on('toolbar(code-table)', function (obj) {
        if (obj.event === 'generate') {
            openGenerate();
        } else if (obj.event === 'refresh') {
            table.reload('code-table');
        } else if (obj.event === 'batchVoid') {
            batchVoid();
        } else if (obj.event === 'export') {
            exportBatch();
        }
    });

    table.on('tool(code-table)', function (obj) {
        if (obj.event === 'void') {
            voidCode(obj.data.id, obj.data.code);
        }
    });

    // 批量生成弹窗（页面内表单，layer type 1）
    function openGenerate() {
        var supportList = activityCache.filter(function (a) { return a.support_code; });
        if (supportList.length === 0) {
            layer.msg('暂无支持兑换码方式的上架券活动，请先在券活动中勾选「兑换码兑换」并上架', { icon: 0, time: 3000 });
            return;
        }
        var optionsHtml = supportList.map(function (a) {
            return '<option value="' + a.id + '">' + a.title + '（' + a.code + '）</option>';
        }).join('');

        layer.open({
            type: 1,
            title: '批量生成兑换码',
            shade: [0.5, '#000'],
            shadeClose: 0,
            closeBtn: 1,
            area: ['460px', '320px'],
            end: function () { reloadTable(); },
            content: '<div style="padding:20px">' +
                '<form class="layui-form" lay-filter="genForm">' +
                '<div class="layui-form-item"><label class="layui-form-label">券活动</label>' +
                '<div class="layui-input-block"><select name="activity_id" lay-verify="required">' + optionsHtml + '</select></div></div>' +
                '<div class="layui-form-item"><label class="layui-form-label">生成数量</label>' +
                '<div class="layui-input-block"><input type="number" name="count" min="1" max="5000" value="100" class="layui-input"></div></div>' +
                '<div class="layui-form-item" style="text-align:center;margin-top:25px">' +
                '<button type="button" class="layui-btn" id="btn-do-generate">确认生成</button></div>' +
                '</form></div>',
            success: function () {
                form.render('select');
                $('#btn-do-generate').on('click', function () {
                    var activityId = $('select[name="activity_id"]').val();
                    var count = parseInt($('input[name="count"]').val(), 10);
                    if (!activityId || isNaN(count) || count < 1 || count > 5000) {
                        layer.msg('请选择券活动并输入1-5000之间的数量', { icon: 2 });
                        return;
                    }
                    var loading = layer.load();
                    $.ajax({
                        url: API_BASE + '/codes/generate',
                        type: 'POST',
                        dataType: 'json',
                        contentType: 'application/json',
                        data: JSON.stringify({ activity_id: parseInt(activityId, 10), count: count }),
                        success: function (res) {
                            layer.close(loading);
                            if (res.code === 0) {
                                layer.msg(res.message + '，批次号：' + res.data.batch_no, { icon: 1, time: 3000 }, function () {
                                    layer.closeAll('page');
                                    table.reload('code-table', { where: { batch_no: res.data.batch_no }, page: { curr: 1 } });
                                });
                            } else {
                                layer.msg(res.message || '生成失败', { icon: 2 });
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

    function voidCode(id, code) {
        layer.confirm('确定要作废兑换码「' + code + '」？', { icon: 3, title: '操作提示' }, function (index) {
            layer.close(index);
            $.ajax({
                url: API_BASE + '/codes/' + id,
                type: 'DELETE',
                success: function (res) {
                    if (res.code === 0) {
                        layer.msg('已作废', { icon: 1, time: 1000 }, function () { reloadTable(); });
                    } else {
                        layer.msg(res.message || '作废失败', { icon: 2 });
                    }
                },
                error: function (xhr) { apiError(xhr); }
            });
        });
    }

    function batchVoid() {
        var checkData = table.checkStatus('code-table').data;
        if (checkData.length === 0) {
            layer.msg('未选中数据', { icon: 0, time: 1000 });
            return;
        }
        var ids = checkData.filter(function (c) { return c.status === 0; }).map(function (c) { return c.id; });
        if (ids.length === 0) {
            layer.msg('选中的兑换码中没有可作废的（仅未使用状态可作废）', { icon: 0, time: 2000 });
            return;
        }
        layer.confirm('确定要作废选中的 ' + ids.length + ' 个兑换码？', { icon: 3, title: '操作提示' }, function (index) {
            layer.close(index);
            var loading = layer.load();
            $.ajax({
                url: API_BASE + '/codes/batch-void',
                type: 'DELETE',
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

    function exportBatch() {
        var batchNo = $('input[name="batch_no"]').val();
        if (!batchNo) {
            layer.msg('请先在搜索栏输入批次号再导出', { icon: 0, time: 2000 });
            return;
        }
        window.location.href = API_BASE + '/codes/export?batch_no=' + encodeURIComponent(batchNo.trim());
    }
});
</script>
</body>
</html>
