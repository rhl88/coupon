<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>定向发放</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; }
        *, *::before, *::after { box-sizing: border-box; }
        .layui-form { height: 100%; }
        .layui-form > .layui-row { height: calc(100vh - 52px); overflow-y: auto; padding: 15px; margin: 0; }
        .bottom { height: 52px; background: #fff; border-top: 1px solid #e6e6e6; text-align: right; }
        .bottom .button-container { display: inline-block; padding: 10px 15px; }
        .bottom .button-container button { margin-left: 10px; }
        .form-tips { color: #999; font-size: 12px; padding-left: 10px; }
    </style>
</head>
<body>
<form class="layui-form" lay-filter="issueForm">
    <input type="hidden" name="activity_id" value="{{ $id }}">

    <div class="layui-row">
        <div class="layui-col-md12">
            <div class="layui-card-body layui-row">
                <div class="layui-col-md12">

                    <div class="layui-form-item">
                        <label class="layui-form-label">发放对象</label>
                        <div class="layui-input-block">
                            <input type="radio" name="target_type" value="1" title="指定用户" lay-filter="targetType" checked>
                            <input type="radio" name="target_type" value="2" title="全部启用用户" lay-filter="targetType">
                        </div>
                    </div>

                    <div class="layui-form-item" id="item-user-ids">
                        <label class="layui-form-label">用户ID列表</label>
                        <div class="layui-input-block">
                            <textarea name="user_ids_text" placeholder="每行一个用户ID，或用英文逗号分隔，单次最多5000个" class="layui-textarea" style="min-height:120px"></textarea>
                            <span class="form-tips">仅对状态为「启用」的用户生效，无效ID自动跳过</span>
                        </div>
                    </div>

                    <div class="layui-form-item">
                        <label class="layui-form-label">备注</label>
                        <div class="layui-input-block">
                            <input type="text" name="remark" maxlength="255" placeholder="本次发放的备注说明（可选）" class="layui-input" autocomplete="off">
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <div class="bottom">
        <div class="button-container">
            <button type="submit" class="layui-btn layui-btn-normal layui-btn-sm" lay-submit lay-filter="doIssue">
                <i class="layui-icon layui-icon-release"></i> 开始发放
            </button>
            <button type="button" class="layui-btn layui-btn-primary layui-btn-sm" id="btn-close">
                <i class="layui-icon layui-icon-close"></i> 取消
            </button>
        </div>
    </div>
</form>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
var API_BASE = '/admin/cmspro/coupon/api';

layui.use(['form', 'layer', 'jquery'], function () {
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

    form.on('radio(targetType)', function (data) {
        if (data.value === '2') {
            $('#item-user-ids').hide();
        } else {
            $('#item-user-ids').show();
        }
    });

    $('#btn-close').on('click', function () {
        parent.layer.closeAll('iframe');
    });

    form.on('submit(doIssue)', function (data) {
        var targetType = parseInt(data.field.target_type, 10);
        var payload = {
            activity_id: parseInt(data.field.activity_id, 10),
            target_type: targetType,
            remark: data.field.remark || ''
        };

        if (targetType === 1) {
            // 解析用户ID：支持换行、逗号、空格分隔
            var ids = (data.field.user_ids_text || '')
                .split(/[\s,，;；]+/)
                .map(function (s) { return parseInt(s, 10); })
                .filter(function (n) { return !isNaN(n) && n > 0; });

            if (ids.length === 0) {
                layer.msg('请输入至少一个有效的用户ID', { icon: 2 });
                return false;
            }
            if (ids.length > 5000) {
                layer.msg('单次发放用户数不能超过5000', { icon: 2 });
                return false;
            }
            // 去重
            payload.user_ids = ids.filter(function (v, i) { return ids.indexOf(v) === i; });
        } else {
            layer.msg('将向全部启用用户发放，请确认！', { icon: 3, time: 2000 });
        }

        var loading = layer.load();
        $.ajax({
            url: API_BASE + '/activities/issue',
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function (res) {
                layer.close(loading);
                if (res.code === 0) {
                    var d = res.data || {};
                    layer.alert(
                        '批次号：' + (d.batch_no || '-') + '<br>目标用户：' + (d.total_count || 0) +
                        '<br>成功：' + (d.success_count || 0) + '　失败：' + (d.fail_count || 0),
                        { icon: 1, title: '发放完成' },
                        function () {
                            if (parent && parent.reloadActivityTable) { parent.reloadActivityTable(); }
                            parent.layer.closeAll('iframe');
                        }
                    );
                } else {
                    layer.msg(res.message || '发放失败', { icon: 2 });
                }
            },
            error: function (xhr) {
                layer.close(loading);
                // 401 由 $.ajaxSetup 的 statusCode 统一处理，避免重复弹窗
                if (xhr.status === 401) {
                    return;
                }
                var res = xhr.responseJSON || {};
                if (xhr.status === 422) {
                    // 框架统一 ValidationException 结构：{ code:40201, message, data:{字段:[消息]} }
                    var errors = res.data && typeof res.data === 'object' ? res.data : null;
                    if (errors) {
                        var firstKey = Object.keys(errors)[0];
                        if (firstKey && Array.isArray(errors[firstKey])) {
                            layer.msg(errors[firstKey][0], { icon: 2 });
                            return;
                        }
                    }
                }
                layer.msg(res.message || ('请求失败（' + xhr.status + '）'), { icon: 2 });
            }
        });
        return false;
    });
});
</script>
</body>
</html>
