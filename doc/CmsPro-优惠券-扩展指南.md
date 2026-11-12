# CmsPro 优惠券扩展指南

> 版本：1.0.0 | 更新日期：2026-11-11
> 适用应用：CmsproCoupon v1.0.0+

---

## 一、概述

本文档说明其他应用如何集成「优惠券」能力：在下单流程中试算优惠、核销优惠券、退款时撤销核销，以及通过钩子点扩展本应用行为。

集成方式两种（推荐 PHP 层直接调用）：

| 方式 | 适用场景 | 开销 |
|------|---------|------|
| **PHP 层注入 Service**（推荐） | 同站应用（如积分商城）下单/退款流程内调用 | 无 HTTP 往返，与调用方同事务可组合 |
| HTTP API | 独立前端页面、跨站调用 | 需用户态登录 + 限流 60 次/分钟 |

## 二、前置检查

调用前必须确认优惠券应用已安装并启用，弱依赖检测模板：

```php
use Illuminate\Support\Facades\DB;

function coupon_app_enabled(): bool
{
    try {
        if (! class_exists(\App\Apps\CmsproCoupon\ServiceProvider::class)) {
            return false;
        }

        return DB::table('apps')->where('app_id', 'cmspro.coupon')->where('status', 1)->exists();
    } catch (\Throwable $e) {
        return false;
    }
}
```

未安装/未启用时，调用方应降级为「无优惠」继续原流程，禁止硬依赖导致报错。

## 三、Service 层调用

### 3.1 核销服务 VerifyService（核心）

命名空间：`App\Apps\CmsproCoupon\Services\VerifyService`

| 方法 | 签名 | 说明 |
|------|------|------|
| availableCoupons | `(int $userId, float $orderAmount): array` | 返回用户可用券列表（含每张券实际 `discount_amount`），按优惠额降序，第一项即最优券 |
| preview | `(int $userId, float $orderAmount, array $couponIds = []): array` | 试算；`$couponIds` 为空自动选最优（多券开关开启时选最优组合）。不改变券状态 |
| verify | `(int $userId, array $couponIds, string $orderNo, float $orderAmount, array $context = []): array` | 核销；成功返回 `total_discount` / `pay_amount` |
| verifyByCouponNo | `(int $userId, string $couponNo, string $orderNo, float $orderAmount, array $context = []): array` | 按券号核销（扫码收银场景） |
| revoke | `(int $logId, string $reason = '', ?int $adminId = null): array` | 撤销核销；`$logId` 为核销记录ID（verify 流水表主键） |

`verify()` 的 `$context` 参数：

| 键 | 类型 | 说明 |
|----|------|------|
| caller_app_id | string | 调用方应用 ID（如 `cmspro.pointshop`），写入核销流水便于对账 |
| operator_type | int | `VerifyLog::OPERATOR_USER`(1) / `OPERATOR_ADMIN`(2) / `OPERATOR_SYSTEM`(3) |
| operator_id | int\|null | 操作人 ID |
| remark | string | 备注（≤255 字符） |

返回结构统一为 `ApiResponse` 数组：`['code' => 0, 'message' => ..., 'data' => ..., 'timestamp' => ...]`，`code=0` 成功；失败常见 `40001` 参数错误、`40003` 业务拒绝、`40401` 不存在。

### 3.2 调用示例：下单时试算并核销

```php
use App\Apps\CmsproCoupon\Services\VerifyService;

// 在调用方应用的 OrderService 中注入（构造器注入即可，容器自动解析）
public function __construct(protected VerifyService $couponVerify)
{
}

public function createOrder(int $userId, float $orderAmount, array $couponIds): array
{
    $payAmount = $orderAmount;
    $verifyResult = null;

    if (! empty($couponIds) && coupon_app_enabled()) {
        // 1. 试算（可选，用于下单确认页展示优惠明细）
        $preview = $this->couponVerify->preview($userId, $orderAmount, $couponIds);
        if ($preview['code'] !== 0) {
            return ['code' => 40003, 'message' => $preview['message']];
        }

        // 2. 生成订单号后核销（verify 内部有事务与条件更新，防重复核销）
        $orderNo = $this->generateOrderNo();
        $verifyResult = $this->couponVerify->verify(
            $userId,
            $couponIds,
            $orderNo,
            $orderAmount,
            ['caller_app_id' => 'cmspro.yourapp', 'operator_type' => 1, 'operator_id' => $userId]
        );

        if ($verifyResult['code'] !== 0) {
            return ['code' => 40003, 'message' => $verifyResult['message']];
        }

        $payAmount = (float) $verifyResult['data']['pay_amount'];
    }

    // 3. 以 $payAmount 继续创建订单 …
    // 4. 订单取消/退款时，按核销流水撤销：
    //    $this->couponVerify->revoke($logId, '订单退款');
    //    $logId 可通过查询 app_cmspro_coupon_verify_logs 表
    //    （order_no = 本方订单号 AND status = 1）获得，建议下单成功后把 logId 存入本方订单表。
}
```

**事务组合注意**：`verify()` 内部使用 `DB::transaction`。若调用方在**自己的事务内**调用，Laravel 会以 savepoint 嵌套执行——核销失败抛出的异常已被 verify 捕获并转为错误码返回，不会意外回滚调用方事务；调用方拿到 `code != 0` 后应**主动放弃或回滚**自己的订单写入。

**核销时点建议**：在调用方订单号生成之后、订单落库之前核销；核销失败则订单不创建。若订单创建后支付失败/取消，调用 `revoke()` 退回券。

### 3.3 规则计算服务 CouponRuleService（只读计算）

仅需「算一张券能优惠多少」而不核销时使用：

```php
use App\Apps\CmsproCoupon\Services\CouponRuleService;

$ruleService = app(CouponRuleService::class);
$discount = $ruleService->calculateDiscount($userCouponModel, 199.00); // float，已按配置取整、封顶订单额
$applicable = $ruleService->isApplicable($userCouponModel, 199.00);    // bool，是否满足门槛
```

### 3.4 发放服务 IssueService

其他应用可触发发券（如签到奖励、活动赠送）：

```php
use App\Apps\CmsproCoupon\Services\IssueService;

$issue = app(IssueService::class);
// 定向发放给指定用户（活动须开启「后台发放」方式位）
$result = $issue->issue($activityId, [$userId], 1, null, '签到奖励');
// $result['data']['success_count'] === 1 即发放成功
```

## 四、钩子扩展点

本应用通过 manifest `"hooks": "Hooks"` + ServiceProvider 双通道注册钩子，其他应用在**自己的 Hooks.php** 中监听以下钩子点（注册必须传自己的 app_id 作为第 4 参数）。

### 4.1 自定义 Action 钩子点

| 钩子名 | 触发时机 | 回调参数 |
|--------|---------|---------|
| `cmspro.coupon.received` | 用户主动领取成功（事务内） | `(UserCoupon $coupon, int $userId, CouponActivity $activity)` |
| `cmspro.coupon.issued` | 后台定向发放单张成功（事务内） | 同上 |
| `cmspro.coupon.redeemed` | 兑换码兑换成功（事务内） | 同上 |
| `cmspro.coupon.verified` | 券核销成功（**事务提交后**） | `(UserCoupon $coupon, VerifyLog $log, int $userId, string $orderNo)` |
| `cmspro.coupon.revoked` | 核销撤销成功（**事务提交后**） | `(UserCoupon $coupon, VerifyLog $log)` |
| `cmspro.coupon.expired` | 过期命令标记单张券过期 | `(UserCoupon $coupon)` |

> received/issued/redeemed 在事务内触发：监听器抛异常不会中断发放（HookManager 内部捕获），但**不要在监听器中做长耗时操作**；verified/revoked 在 `DB::afterCommit` 中触发，监听器读到的数据一定已提交。

### 4.2 自定义 Filter 钩子点

| 钩子名 | 加工对象 | 附加参数 |
|--------|---------|---------|
| `cmspro.coupon.available.list` | 可用券列表 `array`（每项含 discount_amount） | `(int $userId, float $orderAmount)` |
| `cmspro.coupon.discount.preview` | 试算结果 `array`（含 total_discount/pay_amount/coupons） | `(float $orderAmount)` |

Filter 必须返回加工后的值，例如按商品类目过滤可用券：

```php
$hooks->registerFilter('cmspro.coupon.available.list', function (array $coupons, int $userId, float $orderAmount) {
    // 剔除标题含「生鲜」的券（示例）
    return array_values(array_filter($coupons, fn ($c) => !str_contains($c['title'], '生鲜')));
}, 10, 'cmspro.yourapp');
```

### 4.3 监听示例（在其他应用 Hooks.php 中）

```php
public function register(\App\Services\HookManager $hooks): void
{
    // 用户领券成功后给调用方应用记积分（示意）
    $hooks->registerAction('cmspro.coupon.received', function ($coupon, $userId, $activity) {
        \Illuminate\Support\Facades\Log::info('用户领券', [
            'user' => $userId, 'coupon_no' => $coupon->coupon_no,
        ]);
    }, 10, 'cmspro.yourapp'); // 第 4 参数必须传自己的 app_id

    // 核销成功后同步核销事件到调用方业务表
    $hooks->registerAction('cmspro.coupon.verified', function ($coupon, $log, $userId, $orderNo) {
        if ($log->caller_app_id === 'cmspro.yourapp') {
            // 按 orderNo 更新本方订单的优惠金额字段
        }
    }, 10, 'cmspro.yourapp');
}
```

## 五、辅助函数

| 函数 | 签名 | 说明 |
|------|------|------|
| `cmspro_coupon_config` | `(string $key, mixed $default = null): mixed` | 读配置（DB 优先，缓存 1h，回退 Config/coupon.php） |
| `cmspro_coupon_config_bool` | `(string $key, bool $default = false): bool` | 开关型配置转布尔 |
| `cmspro_coupon_url` | `(string $path = ''): string` | 按访问模式生成前端 URL |
| `cmspro_coupon_main_url` | `(string $path = ''): string` | 生成主站 URL（子域名模式下为绝对地址） |
| `cmspro_coupon_amount_format` | `(mixed $amount): string` | 金额展示格式化（去小数零） |

> 调用方读取本应用配置仅用于展示类场景；业务判定（如 multi_use_enabled）请以 Service 返回结果为准，避免绕过内部规则。

## 六、模型引用

| 模型 | 表 | 常用字段/常量 |
|------|-----|--------------|
| `CouponActivity` | app_cmspro_coupon_activities | `TYPE_FULL_REDUCE/TYPE_DISCOUNT/TYPE_CASH`、`RECEIVE_USER/RECEIVE_ADMIN/RECEIVE_CODE`（位掩码）、`supportReceive()`、`getRemainStock()` |
| `UserCoupon` | app_cmspro_coupon_user_coupons | `STATUS_UNUSED/USED/EXPIRED/VOID`、`SOURCE_RECEIVE/ISSUE/REDEEM`、`isUsable()`、scope `usable()` |
| `VerifyLog` | app_cmspro_coupon_verify_logs | `STATUS_VERIFIED/REVOKED`、`OPERATOR_USER/ADMIN/SYSTEM`、order_no/discount_amount/caller_app_id |
| `RedeemCode` | app_cmspro_coupon_redeem_codes | `STATUS_UNUSED/USED/VOID` |
| `IssueBatch` | app_cmspro_coupon_issue_batches | `TARGET_SPECIFIED/TARGET_ALL` |

日期字段序列化统一为 `Y-m-d H:i:s`（BaseModel::serializeDate），调用方**禁止对取出的日期二次 format 为 ISO 8601**。

## 七、数据表对账查询

调用方可按 `caller_app_id` + `order_no` 直查核销流水做对账（只读）：

```php
use App\Apps\CmsproCoupon\Models\VerifyLog;

$logs = VerifyLog::where('caller_app_id', 'cmspro.yourapp')
    ->where('order_no', $orderNo)
    ->where('status', VerifyLog::STATUS_VERIFIED)
    ->get();
```

## 八、注意事项

1. **数据隔离**：本应用所有用户数据以 `user_id`（前台 users 表主键）隔离；调用方传入的 userId 必须是券归属人，verify 内部会二次校验，越权调用返回 40003/40401。
2. **幂等性**：`verify()` 对「同一订单号重复核销」默认拒绝（multi_use_enabled=0 时）；调用方重试下单流程时，应先查流水判断是否已核销，避免重复扣券。
3. **事务边界**：`verify()`/`revoke()` 自带事务；不要在其外再包一层长事务做外部 HTTP 调用，避免锁持有时间过长。
4. **钩子归属**：调用方注册监听必须传自己的 app_id；本应用被禁用/卸载时，系统仅摘除 `cmspro.coupon` 归属的钩子，不影响调用方注册。
5. **依赖声明**：调用方应用应在自己的 `manifest.json` 的 `dependencies.optional_app_ids` 中声明 `cmspro.coupon`（弱依赖），并在运行时用第二章的检测模板做降级。
6. **配置缓存**：调用方若修改过本应用配置项（不推荐），需调用 `cmspro_coupon_forget_config_cache()` 清缓存。
7. **HTTP API 错误响应**：本应用全部 API 路由组挂载了 `ForceJsonResponse` 中间件，参数校验失败统一返回 **422 JSON**（`{code:40201, message:'请求参数不合法', data:{字段:[消息]}}`），不会返回 302 重定向。前端以 jQuery/fetch 调用（不带 `Accept: application/json`）时同样得到 JSON，调用方按 `code != 0` 判断失败即可，无需处理重定向。

## 九、版本与更新日志

| 版本 | 日期 | 更新人 | 说明 |
|------|------|--------|------|
| 1.0.0 | 2026-11-11 | 开发团队 | 初始版本：VerifyService/IssueService/CouponRuleService 公开接口、6 个 Action 与 2 个 Filter 钩子点、5 个辅助函数 |
| 1.0.0 | 2026-11-12 | 开发团队 | 验收整改：公开方法签名不变；核销/发放长方法按职责拆分（新增内部方法 applyCouponsInTransaction/markCouponsUsed/resolveTargetUsers 等）；API 组挂载 ForceJsonResponse，校验失败统一返回 422 JSON |
