<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>优惠券设置</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
    <style>
        .setting-card { max-width: 860px; }
        .setting-card .layui-form-label { width: 140px; }
        .setting-card .layui-input-block { margin-left: 170px; }
        .tips-text { color: #999; font-size: 12px; margin-top: 4px; }
        .section-title { font-weight: 600; padding: 10px 0; border-bottom: 1px solid #eee; margin-bottom: 15px; }
    </style>
</head>
<body>
<div class="pear-container">
    <div class="layui-card setting-card">
        <div class="layui-card-header">优惠券设置</div>
        <div class="layui-card-body" style="padding:20px">
            <form class="layui-form" lay-filter="settingForm">

                <div class="section-title">基础设置</div>

                <div class="layui-form-item">
                    <label class="layui-form-label">前端访问模式</label>
                    <div class="layui-input-block">
                        <select name="access_mode" lay-filter="accessMode">
                            <option value="none" {{ ($configs['access_mode'] ?? '') === 'none' ? 'selected' : '' }}>无前端页面</option>
                            <option value="path" {{ ($configs['access_mode'] ?? '') === 'path' ? 'selected' : '' }}>路径前缀</option>
                            <option value="domain" {{ ($configs['access_mode'] ?? '') === 'domain' ? 'selected' : '' }}>子域名绑定</option>
                        </select>
                        <div class="tips-text">无前端：领券仅在用户中心与后台进行；路径前缀：通过 /{前缀} 访问领券中心；子域名：通过绑定域名访问</div>
                    </div>
                </div>

                <div class="layui-form-item" id="item-access-path">
                    <label class="layui-form-label">路径前缀</label>
                    <div class="layui-input-block">
                        <input type="text" name="access_path" value="{{ $configs['access_path'] ?? 'coupon' }}" placeholder="如 coupon" class="layui-input" autocomplete="off">
                        <div class="tips-text">仅限小写字母、数字和短横线，不能占用 admin/user/api/install 等系统保留路径</div>
                    </div>
                </div>

                <div class="layui-form-item" id="item-access-domain" style="display:none">
                    <label class="layui-form-label">绑定域名</label>
                    <div class="layui-input-block">
                        <input type="text" name="access_domain" value="{{ $configs['access_domain'] ?? '' }}" placeholder="如 coupon.example.com，多个用英文逗号分隔" class="layui-input" autocomplete="off">
                        <div class="tips-text">需在 DNS 中添加解析并在 Web 服务器绑定该域名</div>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">开启用户领取</label>
                    <div class="layui-input-block">
                        <input type="checkbox" name="receive_enabled" lay-skin="switch" lay-text="开启|关闭" value="1" {{ ($configs['receive_enabled'] ?? '1') === '1' ? 'checked' : '' }}>
                        <div class="tips-text">关闭后领券中心不再接受用户主动领取（后台发放与兑换码兑换不受影响）</div>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">券号前缀</label>
                    <div class="layui-input-block">
                        <input type="text" name="coupon_no_prefix" value="{{ $configs['coupon_no_prefix'] ?? 'CP' }}" placeholder="2-8位大写字母，如 CP" class="layui-input" autocomplete="off">
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">兑换码前缀</label>
                    <div class="layui-input-block">
                        <input type="text" name="code_prefix" value="{{ $configs['code_prefix'] ?? '' }}" placeholder="可为空，1-8位大写字母或数字" class="layui-input" autocomplete="off">
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">列表每页条数</label>
                    <div class="layui-input-block">
                        <input type="number" name="page_size" value="{{ $configs['page_size'] ?? '20' }}" min="10" max="100" class="layui-input" autocomplete="off">
                    </div>
                </div>

                <div class="section-title" style="margin-top:25px">规则设置</div>

                <div class="layui-form-item">
                    <label class="layui-form-label">允许同单多券</label>
                    <div class="layui-input-block">
                        <input type="checkbox" name="multi_use_enabled" lay-skin="switch" lay-text="允许|禁止" value="1" {{ ($configs['multi_use_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                        <div class="tips-text">关闭后单个订单仅允许核销一张优惠券</div>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">允许撤销核销</label>
                    <div class="layui-input-block">
                        <input type="checkbox" name="revoke_enabled" lay-skin="switch" lay-text="允许|禁止" value="1" {{ ($configs['revoke_enabled'] ?? '1') === '1' ? 'checked' : '' }}>
                        <div class="tips-text">关闭后核销记录不可撤销，用于强对账场景</div>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">开启过期扫描</label>
                    <div class="layui-input-block">
                        <input type="checkbox" name="expire_scan_enabled" lay-skin="switch" lay-text="开启|关闭" value="1" {{ ($configs['expire_scan_enabled'] ?? '1') === '1' ? 'checked' : '' }}>
                        <div class="tips-text">开启后 coupon:expire 定时命令会把过期未使用的券批量置为已过期</div>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">到期提醒天数</label>
                    <div class="layui-input-block">
                        <input type="number" name="expire_notify_days" value="{{ $configs['expire_notify_days'] ?? '3' }}" min="0" max="30" class="layui-input" autocomplete="off">
                        <div class="tips-text">对 N 天内到期的未使用券标记「即将过期」，0 表示不提醒</div>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">优惠额取整方式</label>
                    <div class="layui-input-block">
                        <select name="discount_round_mode">
                            <option value="floor" {{ ($configs['discount_round_mode'] ?? 'floor') === 'floor' ? 'selected' : '' }}>向下取整到分（floor）</option>
                            <option value="round" {{ ($configs['discount_round_mode'] ?? 'floor') === 'round' ? 'selected' : '' }}>四舍五入到分（round）</option>
                        </select>
                        <div class="tips-text">折扣券优惠金额取整方式，向下取整可保证平台不超发</div>
                    </div>
                </div>

                <div class="layui-form-item" style="margin-top:30px">
                    <div class="layui-input-block">
                        <button type="button" class="layui-btn" id="btn-save" data-permission="cmspro.coupon.setting.manage">
                            <i class="layui-icon layui-icon-ok"></i> 保存设置
                        </button>
                        <button type="reset" class="layui-btn layui-btn-primary">
                            <i class="layui-icon layui-icon-refresh"></i> 重置
                        </button>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>

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

    // 访问模式切换：控制路径/域名输入项显隐
    function renderAccessMode(mode) {
        $('#item-access-path').toggle(mode === 'path');
        $('#item-access-domain').toggle(mode === 'domain');
    }
    renderAccessMode($('select[name="access_mode"]').val());
    form.on('select(accessMode)', function (data) { renderAccessMode(data.value); });

    // 无权限时隐藏保存按钮
    if (window.hasPermission && !window.hasPermission('cmspro.coupon.setting.manage')) {
        $('#btn-save').hide();
    }

    $('#btn-save').on('click', function () {
        if (window.hasPermission && !window.hasPermission('cmspro.coupon.setting.manage')) {
            layer.msg('无操作权限', { icon: 2, time: 1000 });
            return;
        }

        var payload = {
            access_mode: $('select[name="access_mode"]').val(),
            access_path: $('input[name="access_path"]').val(),
            access_domain: $('input[name="access_domain"]').val(),
            receive_enabled: $('input[name="receive_enabled"]').prop('checked') ? '1' : '0',
            coupon_no_prefix: $('input[name="coupon_no_prefix"]').val(),
            code_prefix: $('input[name="code_prefix"]').val(),
            page_size: $('input[name="page_size"]').val(),
            multi_use_enabled: $('input[name="multi_use_enabled"]').prop('checked') ? '1' : '0',
            revoke_enabled: $('input[name="revoke_enabled"]').prop('checked') ? '1' : '0',
            expire_scan_enabled: $('input[name="expire_scan_enabled"]').prop('checked') ? '1' : '0',
            expire_notify_days: $('input[name="expire_notify_days"]').val(),
            discount_round_mode: $('select[name="discount_round_mode"]').val()
        };

        var loading = layer.load();
        $.ajax({
            url: API_BASE + '/settings',
            type: 'PUT',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function (res) {
                layer.close(loading);
                if (res.code === 0) {
                    layer.msg(res.message || '保存成功', { icon: 1, time: 1500 });
                } else {
                    layer.msg(res.message || '保存失败', { icon: 2 });
                }
            },
            error: function (xhr) {
                layer.close(loading);
                // 优先解析后端 message（框架统一 ApiResponse 结构），401 已由 statusCode 处理
                if (xhr.status === 401) { return; }
                var res = xhr.responseJSON || {};
                layer.msg(res.message || ('请求失败（' + xhr.status + '）'), { icon: 2 });
            }
        });
    });
});
</script>
</body>
</html>
