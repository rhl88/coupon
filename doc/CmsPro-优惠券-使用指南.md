# 优惠券 · 使用指南

> 版本：1.0.0 | 更新日期：2026-11-11
> 应用版本：1.0.0 | 应用 ID：`cmspro.coupon`

> 本指南帮助使用者快速安装、配置、运行和验证「优惠券」应用。

---

## 一、环境要求

| 项目 | 要求 |
|------|------|
| PHP | >= 8.3 |
| CmsPro | >= 5.0.0 |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| Web 服务器 | Nginx / Apache（需支持 URL 重写） |
| 依赖应用 | 无强依赖，可独立安装 |

## 二、安装

### 2.1 安装步骤

1. 将应用包上传/解压到 `code/app/Apps/CmsproCoupon/`
2. 进入后台「应用管理」，找到「优惠券」，点击安装并启用
3. 安装流程自动执行：建 5 张业务表 → 同步 12 个配置项 → 注册菜单与 12 个权限码

### 2.2 验证安装

| 检查项 | 预期结果 |
|--------|---------|
| 数据表 | `app_cmspro_coupon_activities` / `_user_coupons` / `_redeem_codes` / `_issue_batches` / `_verify_logs` 共 5 张表存在 |
| 配置项 | `config_items` 中存在 12 个 `app_cmspro_coupon_*` 配置项，分属 basic / rule 两个配置组 |
| 后台菜单 | 左侧出现「优惠券」菜单组，含 6 个子菜单 |
| 用户中心菜单 | 用户中心出现「优惠券」菜单组（领券中心 / 我的优惠券） |
| 权限 | 角色管理的权限列表出现「优惠券-券活动查看」等 12 个中文权限 |
| apps 表 path | `apps` 表中该应用 `path` 字段为相对路径 `app/Apps/CmsproCoupon` |

## 三、功能说明

### 3.1 券类型（三类）

| 券类型 | 规则 | 示例 |
|--------|------|------|
| 满减券 | 订单满 `threshold_amount` 元减 `discount_amount` 元；优惠额必须小于门槛 | 满 100 减 20 |
| 折扣券 | 订单满门槛打 `discount_rate` 折（0-10 开区间），优惠 = 订单额 ×(1−折数/10)，可设 `max_discount_amount` 封顶 | 满 200 打 8.5 折，最高减 100 |
| 无门槛立减券 | 门槛强制为 0，直接减 `discount_amount` 元；优惠不超过订单金额 | 立减 5 元 |

金额口径：优惠额默认**向下取整到分**（floor，可在设置中改为四舍五入），任何情况下不超过订单金额，应付最低为 0。

### 3.2 有效期（两类）

| 类型 | 说明 |
|------|------|
| 固定时间段 | 所有券统一在 `valid_start_time` ~ `valid_end_time` 内有效 |
| 领取后 N 天 | 每张券从领取时刻起算，第 N 天 23:59:59 失效 |

过期处理为**双保险**：查询侧实时惰性判定（状态未刷新也不会被误用）+ `php artisan coupon:expire` 定时命令批量刷新状态（建议由「任务管理」应用按每日一次调度）。

### 3.3 发放方式（三类，按位掩码可叠加）

| 方式 | 入口 | 说明 |
|------|------|------|
| 用户主动领取 | 用户中心「领券中心」/ 前端 `/coupon` 页 | 受总开关、库存、每人限领、领取时间窗控制 |
| 后台定向发放 | 券活动列表 →「定向发放」 | 指定用户ID列表（单次 ≤5000）或全部启用用户；逐用户独立事务，单用户失败不影响整批；结果记入「发放批次」 |
| 兑换码兑换 | 领券中心兑换框 / 用户中心 | 后台批量生成（单批 ≤5000），一码一券，支持按批次导出 CSV（带 BOM，Excel 直接打开不乱码）、单个/批量作废 |

### 3.4 后台页面

| 页面 | URL | 功能 |
|------|-----|------|
| 券活动管理 | `/admin/cmspro/coupon/activities` | 券活动 CRUD、上下架、定向发放入口；已发放过的活动锁定券型与优惠力度、禁止删除（只能下架） |
| 兑换码管理 | `/admin/cmspro/coupon/codes` | 批量生成、按状态/活动/批次筛选、作废、导出 CSV |
| 用户优惠券 | `/admin/cmspro/coupon/user-coupons` | 全量用户券查询（券号/用户/状态/券型）、单张与批量作废 |
| 发放批次 | `/admin/cmspro/coupon/issue-batches` | 批次列表与详情（成功/失败计数、失败明细前 100 条） |
| 核销记录 | `/admin/cmspro/coupon/verify-logs` | 核销流水查询（订单号/券号/时间区间/状态）、撤销核销（券退回未使用，已过期则置为已过期） |
| 优惠券设置 | `/admin/cmspro/coupon/settings` | 访问模式、领取开关、前缀、分页、多券/撤销/过期扫描开关、取整方式 |

### 3.5 用户中心

| 页面 | URL | 功能 |
|------|-----|------|
| 领券中心 | `/user/cmspro/coupon/center` | 浏览可领券（含剩余量/限领数/可领状态）、一键领取、兑换码兑换 |
| 我的优惠券 | `/user/cmspro/coupon/my-coupons` | 状态统计卡、按状态 Tab 切换、即将过期标记、分页浏览 |

### 3.6 前端领券页与核销 API

- 前端领券中心：默认 `path` 模式，访问 `/coupon`；`access_mode` 可切换为 `none`（关闭前端页）或 `domain`（子域名绑定，支持逗号分隔多域名）。游客可浏览，领取/兑换需登录（自动跳转 `/user/login`）。
- 核销 API（用户态 `auth:web`，限流 60 次/分钟）：

| 方法 | URL | 参数 | 说明 |
|------|-----|------|------|
| GET | `/api/cmspro/coupon/center/activities` | - | 领券中心公开数据（游客可访问） |
| GET | `/api/cmspro/coupon/available` | `order_amount` | 当前用户可用券列表（按优惠额降序） |
| POST | `/api/cmspro/coupon/preview` | `order_amount`, `coupon_ids[]`(可空) | 试算优惠额，不改券状态；coupon_ids 为空自动选最优 |
| POST | `/api/cmspro/coupon/verify` | `order_no`, `order_amount`, `coupon_ids[]` | 核销，返回 `total_discount` / `pay_amount` |
| GET | `/api/cmspro/coupon/coupon/{couponNo}` | - | 按券号查询本人单券状态（扫码核销场景） |

响应统一为 `{ code, message, data, timestamp }`，`code=0` 为成功；常见错误码：40001 参数错误、40003 业务拒绝（已领完/超限领/不可用/重复核销）、40401 不存在。

## 四、配置说明

### 4.1 基础设置（basic）

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| access_mode | path | 前端访问模式：none / path / domain。保存后自动清路由缓存立即生效 |
| access_path | coupon | path 模式前缀；仅限小写字母数字短横线；不可占用 admin/user/api/install 保留路径 |
| access_domain | 空 | domain 模式绑定域名，逗号分隔多个 |
| receive_enabled | 开 | 用户主动领取总开关；关闭后领券中心与前端页拒绝领取（后台发放、兑换码不受影响） |
| coupon_no_prefix | CP | 券号前缀，2-8 位大写字母 |
| code_prefix | 空 | 兑换码统一前缀，0-8 位大写字母数字 |
| page_size | 20 | 后台列表分页大小（10-100） |

### 4.2 规则设置（rule）

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| multi_use_enabled | 关 | 允许同一订单核销多张券；关闭时单订单仅限一张，且同一订单号禁止二次核销 |
| revoke_enabled | 开 | 允许后台撤销核销；关闭用于强对账场景 |
| expire_scan_enabled | 开 | `coupon:expire` 命令的开关 |
| expire_notify_days | 3 | N 天内到期的未使用券标记「即将过期」，并驱动通知中心待办计数；0 关闭 |
| discount_round_mode | floor | 优惠额取整：floor 向下到分（平台不超发）/ round 四舍五入 |

### 4.3 注意事项

- 券活动**编码创建后不要修改**；已发放过券的活动，券型/门槛/优惠力度被锁定（防止与已发券快照冲突），需要调整请新建活动
- 每人限领统计口径为「未使用 + 已使用」，作废券不占用额度；设为 0 表示不限
- `total_stock=0` 表示不限量；库存扣减为条件原子自增，并发下不会超发
- 兑换码字符集已排除易混淆字符（0/O、1/I/L）
- 修改「前端访问模式」后如启用了路由缓存，系统会自动执行 `route:clear`

## 五、卸载验证

| 检查项 | 预期结果 |
|--------|---------|
| 数据表 | 5 张 `app_cmspro_coupon_*` 表已删除，`migrations` 表中对应记录已清理 |
| 配置 | `config_groups` / `config_items` 中本应用配置组与 12 个配置项已清理 |
| 菜单/权限 | 后台与用户中心菜单移除，`admin_permissions` 中 12 个权限码清理 |
| 钩子 | HookManager 中 `cmspro.coupon` 归属钩子全部摘除 |

> ⚠️ 卸载会**删除全部券数据**（含核销流水），不可恢复。如需保留历史数据，请先导出备份再卸载。

## 六、目录结构

```
CmsproCoupon/
├── manifest.json                  # 应用元数据唯一声明源
├── ServiceProvider.php            # 入口：helpers/配置/三终端路由/视图/钩子/命令
├── Hooks.php                      # 钩子注册器（监听系统钩子 + 暴露 6 Action/2 Filter）
├── Install.php                    # 安装/卸载/升级（迁移兜底 + 配置三向同步）
├── helpers.php                    # cmspro_coupon_config / url / amount_format 等
├── Config/coupon.php              # 默认配置兜底
├── Console/CouponExpireCommand.php # coupon:expire 过期扫描命令
├── Http/Middleware/ForceJsonResponse.php # API 强制 JSON 响应（防校验失败转 302）
├── Routes/
│   ├── admin.php / admin_api.php  # 后台页面 / 数据接口
│   ├── user.php / user_api.php    # 用户端页面 / 数据接口
│   └── home.php / home-api.php    # 前端页面 / 核销 API
├── Controllers/
│   ├── AdminBaseController.php    # 后台基类（权限校验 + 响应封装）
│   ├── Admin/                     # 活动/兑换码/用户券/核销记录/设置
│   ├── User/                      # 领券中心 / 我的优惠券
│   ├── Home/CenterController.php  # 前端领券页
│   └── HomeApi/                   # VerifyApiController / CenterApiController
├── Services/                      # 8 个业务服务（见扩展指南）
├── Models/                        # 5 个模型
├── Migrations/                    # 5 张表迁移（幂等 + 全备注）
├── Views/
│   ├── Admin/{activities,codes,user-coupons,verify-logs,issue-batches,settings}/
│   ├── User/{center,my-coupons}/
│   └── Home/index.blade.php
├── Tests/                         # 80 个测试（迁移/规则单元/发放/核销/用户端/后台API/JSON响应回归）
└── doc/                           # 三类应用文档
```

## 七、常见问题

| 问题 | 排查方向 |
|------|---------|
| 前端 `/coupon` 404 | 检查设置页 `access_mode` 是否为 path、`access_path` 是否被修改；改过配置后若手动启用过路由缓存需 `php artisan route:clear` |
| 用户领券提示「已达领取上限」 | 该活动 `per_user_limit` 限制；作废的券不占额度，已使用/未使用都计入 |
| 兑换码生成按钮提示无可用活动 | 券活动必须同时满足：上架 + 发放方式勾选「兑换码兑换」 |
| 后台按钮不显示 | 非超管账号需在「角色管理」中勾选对应「优惠券-××」权限 |
| 券显示未使用但实际已过期 | 列表为惰性判定会实时显示「已过期」；如需刷新库内状态，执行 `php artisan coupon:expire` |
| 其他应用下单想抵扣优惠券 | 参见《CmsPro-优惠券-扩展指南》，直接注入 `VerifyService` 调用 preview/verify |

## 八、版本历史

| 版本 | 日期 | 说明 |
|------|------|------|
| 1.0.0 | 2026-11-11 | 初始版本 |
| 1.0.0 | 2026-11-12 | 验收整改：全部 API 路由组挂载 ForceJsonResponse 中间件（校验失败返回 422 JSON 而非 302）；后台列表分页参数统一 `per_page`；前端页色彩收敛至语义色板、移除自定义字体、补响应式断点与骨架屏；主站链接改用 `cmspro_coupon_main_url()`；核销/发放/校验长函数按职责拆分；补充写接口验证与 JSON 响应回归测试（共 80 个测试） |
