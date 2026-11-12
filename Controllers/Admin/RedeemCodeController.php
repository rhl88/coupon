<?php

namespace App\Apps\CmsproCoupon\Controllers\Admin;

use App\Apps\CmsproCoupon\Controllers\AdminBaseController;
use App\Apps\CmsproCoupon\Services\RedeemCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 后台 · 兑换码管理控制器
 *
 * 职责：兑换码列表、批量生成、作废、按批次导出 CSV。
 */
class RedeemCodeController extends AdminBaseController
{
    public function __construct(protected RedeemCodeService $service)
    {
    }

    /**
     * 兑换码列表页
     */
    public function index(): View
    {
        return view('cmspro.coupon::Admin.codes.index');
    }

    /**
     * 兑换码列表数据
     */
    public function list(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.code.view')) {
            return $this->forbidden('无权查看兑换码');
        }

        return $this->respond($this->service->getList([
            'keyword' => $request->input('keyword'),
            'activity_id' => $request->input('activity_id'),
            'status' => $request->input('status'),
            'batch_no' => $request->input('batch_no'),
            'per_page' => $request->input('per_page'),
        ]));
    }

    /**
     * 批量生成兑换码
     */
    public function generate(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.code.generate')) {
            return $this->forbidden('无权生成兑换码');
        }

        $data = $request->validate([
            'activity_id' => 'required|integer|min:1',
            'count' => 'required|integer|min:1|max:' . RedeemCodeService::BATCH_MAX,
        ], [], [
            'activity_id' => '券活动',
            'count' => '生成数量',
        ]);

        $adminId = auth('admin')->id();

        return $this->respond($this->service->generate(
            (int) $data['activity_id'],
            (int) $data['count'],
            $adminId === null ? null : (int) $adminId
        ));
    }

    /**
     * 作废单个兑换码
     */
    public function destroy(int $id): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.code.destroy')) {
            return $this->forbidden('无权作废兑换码');
        }

        return $this->respond($this->service->voidCode($id));
    }

    /**
     * 批量作废兑换码
     */
    public function batchVoid(Request $request): JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.code.destroy')) {
            return $this->forbidden('无权作废兑换码');
        }

        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|min:1',
        ], [], ['ids' => '兑换码列表']);

        return $this->respond($this->service->batchVoid(array_map('intval', $data['ids'])));
    }

    /**
     * 按批次导出兑换码 CSV
     *
     * 使用流式响应避免大批量兑换码一次性占用内存；
     * 输出 UTF-8 BOM 头，保证 Excel 直接打开不乱码。
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        if (! $this->hasPermission('cmspro.coupon.code.view')) {
            return $this->forbidden('无权导出兑换码');
        }

        $batchNo = trim((string) $request->input('batch_no', ''));

        if ($batchNo === '') {
            return $this->error(40001, '请指定要导出的批次号');
        }

        $rows = $this->service->exportRows($batchNo);

        if (empty($rows)) {
            return $this->error(40401, '该批次没有兑换码记录');
        }

        $filename = 'coupon_codes_' . $batchNo . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM，避免 Excel 打开中文乱码
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['兑换码', '券活动', '状态', '兑换用户ID', '兑换时间', '生成时间']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['code'],
                    $row['activity_title'],
                    $row['status_name'],
                    $row['user_id'] ?? '',
                    $row['redeem_time'] ?? '',
                    $row['create_time'] ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
