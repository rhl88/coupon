# 优惠券 · 特性清单

> 版本：1.0.0 | 更新日期：2026-11-11
> 应用版本：1.0.0 | 应用 ID：`cmspro.coupon`

> 本文档系统整理本应用覆盖的 CmsPro v5 应用开发框架特性，并标注对应实现位置，作为应用开发参考。

---

## 一、应用体系架构

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 1 | 目录隔离 | 应用代码统一存放在 `app/Apps/CmsproCoupon/` 下 | `app/Apps/CmsproCoupon/` |
| 2 | 命名空间隔离 | 命名空间为 `App\Apps\CmsproCoupon` | 全部 PHP 文件 |
| 3 | 数据库隔离 | 5 张表统一以 `app_cmspro_coupon_` 为前缀 | `Migrations/*.php` |
| 4 | 视图隔离 | 视图命名空间为 `cmspro.coupon::` | `ServiceProvider::loadViews()` |
| 5 | 配置隔离 | 配置项 code 以 `app_cmspro_coupon_` 为前缀，配置键 `apps.cmspro.coupon` | `Config/coupon.php` / `Install::seedDefaultConfigs()` |
| 6 | manifest.json | 应用元数据唯一声明源（菜单/配置/权限/hooks/install） | `manifest.json` |

## 二、三终端全覆盖

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 7 | 后台管理端（admin） | 中间件 `['web','auth:admin','permission:admin']`，前缀 `admin/cmspro/coupon` | `Routes/admin.php` / `Routes/admin_api.php` / `Controllers/Admin/` |
| 8 | 用户中心端（user） | 中间件 `['web','auth:web']`，数据按 `user_id` 隔离 | `Routes/user.php` / `Routes/user_api.php` / `Controllers/User/` |
| 9 | 前端展示端（home） | 中间件 `['web']`，游客可浏览领券中心 | `Routes/home.php` / `Controllers/Home/` |
| 10 | 前端 API（home-api） | 中间件 `['web','throttle:60,1',ForceJsonResponse]`，固定前缀 `api/cmspro/coupon`，核销/试算接口追加 `auth:web` | `Routes/home-api.php` / `Controllers/HomeApi/` |

## 三、路由系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 11 | 页面/接口路由分离 | 页面路由（admin.php/user.php）与数据接口（admin_api.php/user_api.php）分文件注册 | `Routes/` |
| 12 | RESTful 风格 | 资源名小写复数+连字符，HTTP 方法语义正确（GET/POST/PUT/DELETE） | `Routes/admin_api.php` |
| 13 | 路由参数约束 | 数字主键统一 `whereNumber('id')`，券号参数 `{couponNo}` | `Routes/*.php` |
| 14 | 访问模式动态注册 | none（不注册前端页面）/ path（路径前缀）/ domain（子域名）三模式 | `ServiceProvider::registerHomeRoutes()` |
| 15 | 子域名兼容 | 检测框架 `domain_routes_registered.{app_id}` 标记，避免重复注册；前端 API 注册为全局 `api/` 前缀不带 domain 约束，由框架 catch-all 负向前瞻 `^(?!api/).*` 通用放行，子域名下天然可访问（常见问题第12章通用修复） | `ServiceProvider::registerHomeRoutes()` |
| 15a | 强制 JSON 响应中间件 | 全部 API 路由组挂载 `Http/Middleware/ForceJsonResponse`，前端 jQuery/fetch 不带 JSON Accept 头时校验失败也返回 422 JSON 而非 302（常见问题第1章） | `Http/Middleware/ForceJsonResponse.php` / `ServiceProvider::registerRoutes()` |

## 四、数据层

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 16 | 迁移幂等 | `up()` 建表前 `Schema::hasTable()`，`down()` 删表前同样检查 | `Migrations/*.php` |
| 17 | 字段与表备注 | 全字段 `->comment()`，MySQL 下 `ALTER TABLE ... COMMENT`（SQLite 跳过） | `Migrations/*.php` |
| 18 | create_time/update_time | 模型重写 `CREATED_AT`/`UPDATED_AT` 常量 | `Models/*.php` |
| 19 | 日期格式 Y-m-d H:i:s | 继承 `BaseModel::serializeDate()`，输出处显式 `format('Y-m-d H:i:s')` | `Models/*.php` / `Services/*.php` |
| 20 | 模型常量与映射 | 券类型/状态/来源/发放方式全部定义常量并提供中文映射 | `Models/CouponActivity.php` 等 |
| 21 | 查询作用域 | `receivable()`（可领活动）、`usable()`（惰性过期可用券）、`expiredPending()`（待过期清理） | `Models/CouponActivity.php` / `Models/UserCoupon.php` |
| 22 | 关联关系 | 活动↔用户券↔核销记录↔兑换码↔用户/管理员 | `Models/*.php` |
| 23 | 规则快照 | 发放时将活动规则快照写入用户券，活动后续调整不影响已发券 | `Services/IssueService::issueOne()` |
| 24 | 事务与行锁 | 领取/兑换/核销/撤销全程 `DB::transaction` + `lockForUpdate` | `Services/IssueService.php` / `Services/VerifyService.php` |
| 25 | 条件原子更新 | 库存扣减 `where issued_count < total_stock` 条件自增防超发；核销 `where status=0` 条件更新防重复核销 | `Services/IssueService.php` / `Services/VerifyService.php` |
| 26 | Service 分层 | Activity/Issue/RedeemCode/UserCoupon/Verify/Record/Setting/CouponRule 八个服务，控制器保持轻量 | `Services/` |
| 27 | 统一响应结构 | 全部接口走 `ApiResponse::success/error/paginate`（code/message/data/timestamp） | `Services/*.php` |

## 五、钩子系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 28 | Hooks 声明 | manifest `"hooks": "Hooks"`，系统通道启用时注册 | `manifest.json` / `Hooks.php` |
| 29 | ServiceProvider 通道补注册 | 按 app_id 去重，进程重启后钩子仍生效 | `ServiceProvider::registerHooks()` |
| 30 | 注册传 app_id | 全部 `registerAction/registerFilter` 第 4 参数传 `cmspro.coupon`，禁用/卸载可自动摘除 | `Hooks::register()` |
| 31 | 监听 dashboard.stats | 仪表盘注入已发放/待使用/今日核销统计 | `Hooks::filterDashboardStats()` |
| 32 | 监听 admin.notifications.todos | 通知中心注入「即将过期优惠券」待办（带权限码） | `Hooks::filterNotificationTodos()` |
| 33 | 监听应用生命周期 | app.installed / app.enabled / app.disabled | `Hooks.php` |
| 34 | 暴露自定义 Action | `cmspro.coupon.received/issued/redeemed/verified/revoked/expired` | `Services/IssueService.php` / `Services/VerifyService.php` / `Console/CouponExpireCommand.php` |
| 35 | 暴露自定义 Filter | `cmspro.coupon.available.list` / `cmspro.coupon.discount.preview` | `Services/VerifyService.php` |
| 36 | 钩子触发异常安全 | 钩子失败仅记日志，不影响发放/核销主流程；核销钩子在 `DB::afterCommit` 中触发 | `Services/*.php` |

## 六、配置系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 37 | config_groups 声明 | basic（访问模式/券号前缀等 7 项）+ rule（多券/撤销/过期扫描等 5 项） | `manifest.json` |
| 38 | 三向同步 | 以 manifest 为基准：新增写默认值、已有保留用户 value、移除清残留；install/upgrade 均调用 | `Install::seedDefaultConfigs()` |
| 39 | select 类型 options | options 直接传数组（模型 `$casts['options'=>'array']`），与框架 AppInstallerService 行为一致 | `Install::upsertConfigItem()` |
| 40 | 应用级 helpers | `cmspro_coupon_config()`（缓存读取）、`cmspro_coupon_config_bool()`、`cmspro_coupon_url()`、`cmspro_coupon_main_url()`、`cmspro_coupon_amount_format()`、`cmspro_coupon_forget_config_cache()` | `helpers.php` |
| 41 | 默认配置兜底 | `Config/coupon.php` 经 `mergeConfigFrom` 合并，DB 无记录时回退 | `ServiceProvider::register()` |
| 42 | 路由阶段实时读配置 | 路由注册直接查库（不走缓存），保存设置后清路由缓存立即生效 | `ServiceProvider::getConfigValue()` / `SettingService::clearRouteCache()` |
| 43 | 设置页白名单 | 仅 `ALLOWED_KEYS` 内配置可保存，防止任意配置被篡改 | `Services/SettingService.php` |
| 44 | 设置应用级校验 | 访问模式/保留路径/域名格式/前缀格式/数值范围校验 | `SettingService::validateAccessSettings()` / `validateRuleSettings()` |

## 七、权限系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 45 | 权限对象格式声明 | 12 个权限码，name 全部中文 | `manifest.json` permissions |
| 46 | 后端权限校验 | 全部写操作与敏感读操作在控制器内 `hasPermission()` 校验（中间件对应用路由不推导权限码） | `Controllers/AdminBaseController.php` / 各 Admin 控制器 |
| 47 | 前端按钮权限 | 后台列表页注入 `@include('admin.partials.permission-script')`，按钮 `data-permission` + dropdown `permission` 过滤 | `Views/Admin/*/index.blade.php` |

## 八、安装/卸载/升级

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 48 | 迁移兜底 runMigrations | glob 迁移文件手工 require+up()，写 migrations 表记录去重 | `Install::runMigrations()` |
| 49 | 卸载回滚兜底 | `rollbackMigrations()` 逆序执行 down() 并删 migrations 记录，不用 Schema::dropIfExists 手动删表 | `Install::rollbackMigrations()` |
| 50 | 卸载清理配置 | 先删配置项再删配置组，code 前缀兜底二次清理 | `Install::removeConfigs()` |
| 51 | upgrade 幂等 | 增量迁移自动补执行 + 配置三向同步 | `Install::upgrade()` |
| 52 | 不创建 public 目录 | install() 未触碰 `public/apps/{appId}/`，不阻断 Assets 发布机制 | `Install::install()` |

## 九、视图规范

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 53 | 完整 HTML 结构 | 后台/用户端每页含完整 `html/head/body`，引入 5 个公共 CSS + 2 个公共 JS | `Views/**/*.blade.php` |
| 54 | 表单固定底部按钮栏 | `.layui-row` 滚动内容区 + `.bottom` 52px 固定按钮栏 | `Views/Admin/activities/form.blade.php` |
| 55 | @verbatim 包裹 | 含 Layui `{{# }}`/`{{ d.x }}` 的 `<script type="text/html">` 全部包裹 | 各 index.blade.php |
| 56 | CSRF + 401 处理 | 每页 `$.ajaxSetup` 注入 X-CSRF-TOKEN 与 401 跳转 | 各视图 |
| 57 | 目录命名 | 端层 PascalCase（Admin/User/Home），模块层小写复数/连字符（activities/issue-batches/user-coupons） | `Views/` |
| 58 | 行内操作无感知更新 | 状态开关用 `table.updateRow` 单行更新，失败回滚开关状态 | `Views/Admin/activities/index.blade.php` |
| 59 | 用户端独立页面 | 用户端视图不继承 `layouts.user`（iframe 多标签页模式） | `Views/User/**` |
| 60 | XSS 防护 | 前端页所有动态文本经 `esc()` 转义后插入 DOM | `Views/User/center`、`Views/Home` |
| 61 | 表单两种模式统一 | 全应用统一采用模式 A（独立表单页 + `layer.open(type:2)` iframe） | `Views/Admin/activities/` |

## 十、菜单系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 62 | menus 后台菜单 | 「优惠券」目录 + 6 个子菜单，目录不声明 path，code 全局唯一且稳定 | `manifest.json` menus |
| 63 | user_menus 用户端菜单 | 「优惠券」目录 + 领券中心/我的优惠券 | `manifest.json` user_menus |
| 64 | home_menus + home_routes | 领券中心 `/coupon`，扁平结构每项声明 path | `manifest.json` |
| 65 | code 字段声明 | 全部菜单项声明 code，升级时按 (app_id, code, terminal_type) 匹配保留角色关联 | `manifest.json` |

## 十一、命令行与定时任务

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 66 | coupon:expire 命令 | 分批（500/批）游标扫描过期券，条件更新+触发 expired 钩子，支持 --dry-run | `Console/CouponExpireCommand.php` |
| 67 | 惰性过期双保险 | 查询侧 `usable()` 作用域实时判定过期，命令未执行也不会误用过期券 | `Models/UserCoupon.php` |

## 十二、版本历史

| 版本 | 日期 | 说明 |
|------|------|------|
| 1.0.0 | 2026-11-11 | 初始版本：三类券型、三种发放方式、三终端、核销 API 与 Service 扩展点 |
| 1.0.0 | 2026-11-12 | 验收整改：新增 ForceJsonResponse 中间件（API 组强制 JSON 响应）、domain 模式 API 全局放行说明、分页参数统一 per_page、Service 长函数拆分 |
