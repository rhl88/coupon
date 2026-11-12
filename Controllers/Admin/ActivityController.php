<?php

namespace App\Apps\CmsproCoupon\Controllers\Admin;

use App\Apps\CmsproCoupon\Controllers\AdminBaseController;
use App\Apps\CmsproCoupon\Services\ActivityService;
use App\Apps\CmsproCoupon\Services\IssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 后台 · 券活动管理控制器
 *
 * 页面方法只返回视图，数据由前端 AJAX 拉取；业务逻辑全部下沉到 Service 层
 * （券活动 CRUD → ActivityService，定向发放 → IssueService）。
 */
class ActivityController extends AdminBaseController
{
    public function __construct(
        protected ActivityService $service,
        protected IssueService $issueService,
    ) {
    }

    // ══════════════════════════════════════════════════════════════
    // 一、页面路由
    // ══════════════════════════════════════════════════════════════

    /**
     * 券活动列表页
     */
    public function index(): View
    {
        return view('cmspro.coupon::Admin.activities.index');
    }

    /**
     * 券活动表单页（新增/编辑共用，iframe 弹窗）
     *
     * @param Request $request id 为 0 表示新增
     */
    public function form(Request $request): View
    {
        return view('cmspro.coupon::Admin.activities.form', [
            'id' => (int) $request->input('id', 0),
        ]);
    }

    /**
     * 发放弹窗页（按用户ID / 全部用户定向发放）
     */
    public function issueForm(Request $request): View
    {
        return view('cmspro.coupon::Admin.activities.issue', [
            'id' => (int) $request->input('id', 0),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // 二、数据接口
    // ══════════════════════════════════════════════════════════════

    /**
     * 券活动列表数据
     */
    public function list(Request $request): JsonResponse
    {
        return $this->respond($this->service->getList([
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
            'coupon_type' => $request->input('coupon_type'),
            'per_page' => $request->input('per_page'),
            'page' => $request->input('page'),
        ]));
    }

    /**
     * 券活动下拉选项（发放弹窗、兑换码生成页用）
     */
    public function options(): JsonResponse
    {
        return $this->respond($this->service->getOptions());
    }

    /**
     * 券活动详情（表单回填）
     */
    public function show(int $id): JsonResponse
    {
        return $this->respond($this->service->show($id));
    }

    /**
     * 新增券活动
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.activity.create')) {
            return $this->forbidden('无权新增券活动');
        }

        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['receive_type'] = $this->resolveReceiveType($request);

        return $this->respond($this->service->store($data));
    }

    /**
     * 更新券活动
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.activity.update')) {
            return $this->forbidden('无权编辑券活动');
        }

        $data = $request->validate($this->rules(true), [], $this->attributes());
        $data['receive_type'] = $this->resolveReceiveType($request);

        return $this->respond($this->service->update($id, $data));
    }

    /**
     * 删除券活动
     */
    public function destroy(int $id): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.activity.delete')) {
            return $this->forbidden('无权删除券活动');
        }

        return $this->respond($this->service->destroy($id));
    }

    /**
     * 上架 / 下架
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.activity.update')) {
            return $this->forbidden('无权修改券活动状态');
        }

        $data = $request->validate(['status' => 'required|integer|in:0,1']);

        return $this->respond($this->service->updateStatus($id, (int) $data['status']));
    }

    /**
     * 定向发放
     */
    public function issue(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.issue.manage')) {
            return $this->forbidden('无权执行定向发放');
        }

        $data = $request->validate([
            'activity_id' => 'required|integer|min:1',
            'target_type' => 'required|integer|in:1,2',
            'user_ids' => 'nullable|array|max:5000',
            'user_ids.*' => 'integer|min:1',
            'remark' => 'nullable|string|max:255',
        ], [], [
            'activity_id' => '券活动',
            'target_type' => '发放对象',
            'user_ids' => '用户列表',
            'remark' => '备注',
        ]);

        $adminId = auth('admin')->id();

        return $this->respond($this->issueService->issue(
            (int) $data['activity_id'],
            array_map('intval', $data['user_ids'] ?? []),
            (int) $data['target_type'],
            $adminId === null ? null : (int) $adminId,
            (string) ($data['remark'] ?? '')
        ));
    }

    /**
     * 校验规则
     *
     * 局部更新语义（常见问题第4章）：更新场景对必填字段用 sometimes|required——
     * 字段不出现则跳过校验（PATCH 局部更新），字段一旦出现则必须非空，
     * 杜绝「传空字符串清空既有值」的漏洞。
     *
     * @param bool $isUpdate 更新场景：必填字段改为「出现即校验」
     */
    protected function rules(bool $isUpdate = false): array
    {
        // 创建：required（缺失即报错）；更新：sometimes|required（出现即不可为空）
        $required = $isUpdate ? 'sometimes|required' : 'required';

        return [
            'code' => "{$required}|string|max:50|regex:/^[a-z0-9_]+$/",
            'title' => "{$required}|string|max:100",
            'coupon_type' => "{$required}|integer|in:1,2,3",
            'threshold_amount' => 'nullable|numeric|min:0|max:99999999',
            'discount_amount' => 'nullable|numeric|min:0|max:99999999',
            'discount_rate' => 'nullable|numeric|min:0|max:9.99',
            'max_discount_amount' => 'nullable|numeric|min:0|max:99999999',
            'total_stock' => 'nullable|integer|min:0|max:10000000',
            'per_user_limit' => 'nullable|integer|min:0|max:100000',
            'valid_type' => "{$required}|integer|in:1,2",
            'valid_start_time' => 'nullable|date',
            'valid_end_time' => 'nullable|date|after_or_equal:valid_start_time',
            'valid_days' => 'nullable|integer|min:0|max:3650',
            'receive_start_time' => 'nullable|date',
            'receive_end_time' => 'nullable|date|after_or_equal:receive_start_time',
            'receive_need_login' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string|max:500',
            'sort' => 'nullable|integer|min:0|max:99999',
            'status' => 'nullable|integer|in:0,1',
        ];
    }

    /**
     * 校验字段的中文别名（错误提示直接回显给后台表单）
     *
     * @return array<string,string>
     */
    protected function attributes(): array
    {
        return [
            'code' => '活动编码',
            'title' => '活动名称',
            'coupon_type' => '券类型',
            'threshold_amount' => '使用门槛',
            'discount_amount' => '优惠金额',
            'discount_rate' => '折扣率',
            'max_discount_amount' => '最高优惠金额',
            'total_stock' => '发放总量',
            'per_user_limit' => '每人限领',
            'valid_type' => '有效期类型',
            'valid_start_time' => '有效期开始时间',
            'valid_end_time' => '有效期结束时间',
            'valid_days' => '有效天数',
            'receive_start_time' => '领取开始时间',
            'receive_end_time' => '领取结束时间',
            'description' => '使用说明',
            'sort' => '排序',
        ];
    }

    /**
     * 解析发放方式位掩码
     *
     * 前端以 receive_types[] 复选框提交，此处归一化为位或值；
     * 未提交时保留原值（更新场景由 Service 合并旧值）。
     */
    protected function resolveReceiveType(Request $request): int
    {
        $types = $request->input('receive_types');

        if (! is_array($types)) {
            $single = $request->input('receive_type');

            return $single === null ? 0 : (int) $single;
        }

        $mask = 0;
        foreach ($types as $flag) {
            $mask |= (int) $flag;
        }

        return $mask;
    }
}
