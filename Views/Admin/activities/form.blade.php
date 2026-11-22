<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>券活动编辑</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; }
        *, *::before, *::after { box-sizing: border-box; }
        .layui-form { height: 100%; }
        /* 内容区：总高度减去底部按钮栏高度，内容超出时自身滚动 */
        .layui-form > .layui-row { height: calc(100vh - 52px); overflow-y: auto; padding: 15px; margin: 0; }
        /* 底部按钮栏：固定 52px 高度，始终贴底不参与滚动 */
        .bottom { height: 52px; background: #fff; border-top: 1px solid #e6e6e6; text-align: right; }
        .bottom .button-container { display: inline-block; line-height: 52px; padding-right: 12px; }
        .bottom .button-container button { margin-left: 10px; }
        .layui-form-label {width: 100px;}
        .form-tips { color: #999; font-size: 12px; padding-left: 10px; }
    </style>
</head>
<body>
<form class="layui-form" lay-filter="activityForm">
    <input type="hidden" name="id" id="activity-id" value="{{ $id }}">

    <div class="layui-row">
        <div class="layui-col-md12">
            <div class="layui-card-body layui-row">
                <div class="layui-col-md12">

                    <div class="layui-form-item">
                        <label class="layui-form-label">活动名称</label>
                        <div class="layui-input-block">
                            <input type="text" name="title" lay-verify="required" maxlength="100"
                                   placeholder="如：新用户满100减20" class="layui-input" autocomplete="off">
                        </div>
                    </div>

                    <div class="layui-form-item">
                        <label class="layui-form-label">活动编码</label>
                        <div class="layui-input-block">
                            <input type="text" name="code" lay-verify="required" maxlength="50"
                                   placeholder="仅限小写字母、数字和下划线，如 new_user_20" class="layui-input" autocomplete="off">
                            <span class="form-tips">唯一标识，创建后不建议修改</span>
                        </div>
                    </div>

                    <div class="layui-form-item">
                        <label class="layui-form-label">券类型</label>
                        <div class="layui-input-block">
                            <input type="radio" name="coupon_type" value="1" title="满减券" lay-filter="couponType" checked>
                            <input type="radio" name="coupon_type" value="2" title="折扣券" lay-filter="couponType">
                            <input type="radio" name="coupon_type" value="3" title="无门槛立减券" lay-filter="couponType">
                        </div>
                    </div>

                    <div class="layui-form-item" id="item-threshold">
                        <label class="layui-form-label">使用门槛(元)</label>
                        <div class="layui-input-block">
                            <input type="number" name="threshold_amount" step="0.01" min="0" value="0"
                                   placeholder="订单满此金额可用，0表示无门槛" class="layui-input" autocomplete="off">
                        </div>
                    </div>

                    <div class="layui-form-item" id="item-discount-amount">
                        <label class="layui-form-label">优惠金额(元)</label>
                        <div class="layui-input-block">
                            <input type="number" name="discount_amount" step="0.01" min="0" value="0"
                                   placeholder="满减券/立减券的固定优惠金额" class="layui-input" autocomplete="off">
                        </div>
                    </div>

                    <div class="layui-form-item" id="item-discount-rate" style="display:none">
                        <label class="layui-form-label">折扣率</label>
                        <div class="layui-input-block">
                            <input type="number" name="discount_rate" step="0.01" min="0.01" max="9.99" value="0"
                                   placeholder="如 8.50 表示 8.5 折" class="layui-input" autocomplete="off">
                            <span class="form-tips">取值 0-10（不含），数值越小折扣越大</span>
                        </div>
                    </div>

                    <div class="layui-form-item" id="item-max-discount" style="display:none">
                        <label class="layui-form-label">最高优惠(元)</label>
                        <div class="layui-input-block">
                            <input type="number" name="max_discount_amount" step="0.01" min="0" value="0"
                                   placeholder="折扣券封顶金额，0表示不封顶" class="layui-input" autocomplete="off">
                        </div>
                    </div>

                    <div class="layui-form-item">
                        <label class="layui-form-label">发放总量</label>
                        <div class="layui-input-block">
                            <input type="number" name="total_stock" min="0" step="1" value="0" class="layui-input" autocomplete="off">
                            <span class="form-tips">0 表示不限量</span>
                        </div>
                    </div>

                    <div class="layui-form-item">
                        <label class="layui-form-label">每人限领</label>
                        <div class="layui-input-block">
                            <input type="number" name="per_user_limit" min="0" step="1" value="1" class="layui-input" autocomplete="off">
                            <span class="form-tips">0 表示不限领取张数</span>
                        </div>
                    </div>

                    <div class="layui-form-item">
                        <label class="layui-form-label">有效期类型</label>
                        <div class="layui-input-block">
                            <input type="radio" name="valid_type" value="1" title="固定时间段" lay-filter="validType" checked>
                            <input type="radio" name="valid_type" value="2" title="领取后N天有效" lay-filter="validType">
                        </div>
                    </div>

                    <div class="layui-form-item" id="item-fixed-valid">
                        <label class="layui-form-label">有效期</label>
                        <div class="layui-input-inline">
                            <input type="text" name="valid_start_time" id="valid-start" placeholder="开始时间" class="layui-input" autocomplete="off" readonly>
                        </div>
                        <div class="layui-form-mid">至</div>
                        <div class="layui-input-inline">
                            <input type="text" name="valid_end_time" id="valid-end" placeholder="结束时间" class="layui-input" autocomplete="off" readonly>
                        </div>
                    </div>

                    <div class="layui-form-item" id="item-relative-valid" style="display:none">
                        <label class="layui-form-label">有效天数</label>
                        <div class="layui-input-block">
                            <input type="number" name="valid_days" min="0" max="3650" step="1" value="0" class="layui-input" autocomplete="off">
                            <span class="form-tips">领取当日起算，第 N 天 23:59:59 失效</span>
                        </div>
                    </div>

                    <div class="layui-form-item">
                        <label class="layui-form-label">领取时间窗</label>
                        <div class="layui-input-inline">
                            <input type="text" name="receive_start_time" id="receive-start" placeholder="开始时间（留空不限）" class="layui-input" autocomplete="off" readonly>
                        </div>
                        <div class="layui-form-mid">至</div>
                        <div class="layui-input-inline">
                            <input type="text" name="receive_end_time" id="receive-end" placeholder="结束时间（留空不限）" class="layui-input" autocomplete="off" readonly>
                        </div>
                    </div>

                    <div class="layui-form-item">
                        <label class="layui-form-label">发放方式</label>
                        <div class="layui-input-block">
                            <input type="checkbox" name="receive_types[]" value="1" title="用户主动领取" lay-skin="primary" checked>
                            <input type="checkbox" name="receive_types[]" value="2" title="后台定向发放" lay-skin="primary">
                            <input type="checkbox" name="receive_types[]" value="4" title="兑换码兑换" lay-skin="primary">
                        </div>
                    </div>

                    <div class="layui-form-item">
                        <label class="layui-form-label">使用说明</label>
                        <div class="layui-input-block">
                            <textarea name="description" maxlength="500" placeholder="展示给用户的券使用说明" class="layui-textarea"></textarea>
                        </div>
                    </div>

                    <div class="layui-form-item">
                        <label class="layui-form-label">排序</label>
                        <div class="layui-input-inline">
                            <input type="number" name="sort" min="0" max="99999" step="1" value="0" class="layui-input" autocomplete="off">
                        </div>
                        <label class="layui-form-label">状态</label>
                        <div class="layui-input-inline">
                            <input type="checkbox" name="status" lay-skin="switch" lay-text="上架|下架" value="1" checked>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    {{-- 固定底部按钮栏：与滚动内容区 .layui-row 同级 --}}
    <div class="bottom">
        <div class="button-container">
            <button type="submit" class="layui-btn layui-btn-normal layui-btn-sm" lay-submit lay-filter="save">
                <i class="layui-icon layui-icon-ok"></i> 提交
            </button>
            <button type="reset" class="layui-btn layui-btn-primary layui-btn-sm">
                <i class="layui-icon layui-icon-refresh"></i> 清空
            </button>
        </div>
    </div>
</form>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
var API_BASE = '/admin/cmspro/coupon/api';
var editId = {{ $id }};

layui.use(['form', 'layer', 'laydate', 'jquery'], function () {
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

    // 日期时间选择器（统一 Y-m-d H:i:s 格式，禁止 ISO 8601）
    ['valid-start', 'valid-end', 'receive-start', 'receive-end'].forEach(function (id) {
        laydate.render({ elem: '#' + id, type: 'datetime', trigger: 'click' });
    });

    // 券类型切换：控制优惠字段的显隐
    function renderCouponType(type) {
        type = parseInt(type, 10);
        if (type === 2) {
            $('#item-discount-amount').hide();
            $('#item-discount-rate').show();
            $('#item-max-discount').show();
        } else {
            $('#item-discount-amount').show();
            $('#item-discount-rate').hide();
            $('#item-max-discount').hide();
        }
        // 无门槛立减券：门槛固定为 0 且禁用输入
        if (type === 3) {
            $('input[name="threshold_amount"]').val(0).prop('disabled', true);
        } else {
            $('input[name="threshold_amount"]').prop('disabled', false);
        }
    }

    // 有效期类型切换
    function renderValidType(type) {
        type = parseInt(type, 10);
        if (type === 2) {
            $('#item-fixed-valid').hide();
            $('#item-relative-valid').show();
        } else {
            $('#item-fixed-valid').show();
            $('#item-relative-valid').hide();
        }
    }

    form.on('radio(couponType)', function (data) { renderCouponType(data.value); });
    form.on('radio(validType)', function (data) { renderValidType(data.value); });

    // 编辑态：回填数据
    if (editId) {
        $.get(API_BASE + '/activities/' + editId, function (res) {
            if (res.code !== 0 || !res.data) {
                layer.msg(res.message || '券活动不存在', { icon: 2 });
                return;
            }
            var d = res.data;
            form.val('activityForm', {
                id: d.id,
                title: d.title,
                code: d.code,
                coupon_type: String(d.coupon_type),
                threshold_amount: d.threshold_amount,
                discount_amount: d.discount_amount,
                discount_rate: d.discount_rate,
                max_discount_amount: d.max_discount_amount,
                total_stock: d.total_stock,
                per_user_limit: d.per_user_limit,
                valid_type: String(d.valid_type),
                valid_start_time: d.valid_start_time || '',
                valid_end_time: d.valid_end_time || '',
                valid_days: d.valid_days,
                receive_start_time: d.receive_start_time || '',
                receive_end_time: d.receive_end_time || '',
                description: d.description || '',
                sort: d.sort,
                status: d.status
            });
            // 发放方式复选框回显
            $('input[name="receive_types[]"]').each(function () {
                var val = parseInt($(this).val(), 10);
                $(this).prop('checked', d.receive_types.indexOf(val) !== -1);
            });
            renderCouponType(d.coupon_type);
            renderValidType(d.valid_type);
            form.render();
        });
    } else {
        renderCouponType(1);
        renderValidType(1);
    }

    form.on('submit(save)', function (data) {
        var field = data.field;

        // checkbox 提交值归一化：未勾选的开关不会出现在 field 中
        field.status = field.status === '1' || field.status === true ? 1 : 0;

        // 发放方式收集为位掩码数组
        var receiveTypes = [];
        $('input[name="receive_types[]"]:checked').each(function () {
            receiveTypes.push(parseInt($(this).val(), 10));
        });
        if (receiveTypes.length === 0) {
            layer.msg('请至少选择一种发放方式', { icon: 2 });
            return false;
        }

        var payload = $.extend({}, field, { receive_types: receiveTypes });
        delete payload.id;

        var url = editId ? API_BASE + '/activities/' + editId : API_BASE + '/activities';
        var method = editId ? 'PUT' : 'POST';

        var loading = layer.load();
        $.ajax({
            url: url,
            type: method,
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function (res) {
                layer.close(loading);
                if (res.code === 0) {
                    layer.msg(res.message || '保存成功', { icon: 1 });
                    setTimeout(function () {
                        if (parent && parent.reloadActivityTable) { parent.reloadActivityTable(); }
                        parent.layer.closeAll('iframe');
                    }, 800);
                } else {
                    showErrors(res);
                }
            },
            error: function (xhr) {
                layer.close(loading);
                handleAjaxError(xhr, layer);
            }
        });
        return false;
    });

    // 校验失败：框架统一渲染为 422 { code:40201, message, data: {字段:[消息]} }
    function showErrors(res) {
        var errors = res.errors || (res.data && typeof res.data === 'object' ? res.data : null);
        if (errors) {
            var firstKey = Object.keys(errors)[0];
            if (firstKey && Array.isArray(errors[firstKey])) {
                layer.msg(errors[firstKey][0], { icon: 2 });
                return;
            }
        }
        layer.msg(res.message || '保存失败', { icon: 2 });
    }

    function handleAjaxError(xhr, layer) {
        // 401 由 $.ajaxSetup 的 statusCode 统一处理，这里提前返回避免重复弹窗（I5）
        if (xhr.status === 401) {
            return;
        }
        if (xhr.status === 422 && xhr.responseJSON) {
            showErrors(xhr.responseJSON);
            return;
        }
        // 优先解析后端 message，避免硬编码文案（I4）
        var msg = (xhr.responseJSON && xhr.responseJSON.message) || ('请求失败（' + xhr.status + '）');
        layer.msg(msg, { icon: 2 });
    }
});
</script>
</body>
</html>
