# 优惠券 应用文档

> 应用ID：`cmspro.coupon` | 文档版本：1.0.0 | 应用版本：1.0.0 | 依赖：PHP ≥ 8.3, CmsPro ≥ 5.0.0

- 官方地址：https://www.cmspro.cn/apps/cmspro.coupon
- 本应用提供满减/折扣/立减三类优惠券的发放、领取、兑换与核销全链路能力，基于 CMSPRO 运行，无强依赖应用，可独立安装使用，请先安装 [CMSPRO](https://www.cmspro.cn/help/2)

## 1. 应用概述

优惠券营销应用，支持「满减券 / 折扣券 / 无门槛立减券」三种券型与「用户主动领取 / 后台定向发放 / 兑换码兑换」三种发放方式，覆盖后台管理、用户中心、前端领券页与核销 API 的完整业务闭环：

- **后台管理**：券活动管理（CRUD/上下架/定向发放）、兑换码管理（批量生成/导出CSV/作废）、用户优惠券（查询/作废）、发放批次（成功失败统计与明细）、核销记录（查询/撤销核销）、优惠券设置（访问模式/规则开关）
- **用户中心**：领券中心（浏览/领取/兑换码兑换）、我的优惠券（状态统计/即将过期提醒/分页浏览）
- **前端领券页**：`/coupon` 公开页（游客可浏览，领取需登录），支持 none/path/domain 三种访问模式
- **核销能力**：前端 API（用户态核销/试算/可用券查询）+ PHP 层 `VerifyService`（供其他应用如下单流程直接调用抵扣），支持撤销核销与跨应用对账（caller_app_id）
- **规则口径**：优惠额取整到分（floor/round 可配）、不超过订单金额、多券开关、每人限领、库存条件原子扣减防超发
- **扩展点**：6 个 Action 钩子（received/issued/redeemed/verified/revoked/expired）+ 2 个 Filter 钩子（available.list/discount.preview）
- **定时命令**：`php artisan coupon:expire`（过期扫描，支持 --dry-run），配合查询侧惰性过期判定双保险

## 2. 应用截图

> 截图来源：[官方应用页 · cmspro.coupon](https://www.cmspro.cn/apps/cmspro.coupon)（官网标注 v1.0.0）。每次发布与更新必须核对本节截图与官网「应用截图」是否一致，不一致时以官网为准同步更新。

![应用截图 1](https://www.cmspro.cn/uploads/appstore/2026/11/12/FpIGQzs17kbWK9zXWSYlJslxhq4fd6H4cxj4HumJ.png)

![应用截图 2](https://www.cmspro.cn/uploads/appstore/2026/11/12/msVOlfFoXKhBZjvTI5jzGyUPF1iJpFuoAta3q3TE.png)

![应用截图 3](https://www.cmspro.cn/uploads/appstore/2026/11/12/2IiAzHG4OCx2ohlqKaOjcXuzM79WdNVGHpFgsQrM.png)

![应用截图 4](https://www.cmspro.cn/uploads/appstore/2026/11/12/9UOSo65jg7i9aRqUEQ82BGdPEWuvNJ1BuQoE0aAh.png)

![应用截图 5](https://www.cmspro.cn/uploads/appstore/2026/11/12/Qul4CiZ8CuSCbxZt6HeJQPnTipxqDt3aDxsXCkAy.png)

![应用截图 6](https://www.cmspro.cn/uploads/appstore/2026/11/12/LP6vwhDc6IvmvDkdLY2hkQWVwVhWtIm6yLTNM3gu.png)

![应用截图 7](https://www.cmspro.cn/uploads/appstore/2026/11/12/frAXyYNumsURgNZqxuoNHsmmowvKANoIDbzQnx8W.png)

![应用截图 8](https://www.cmspro.cn/uploads/appstore/2026/11/12/5UGoXu0y8TmgmFDKG7IzQIkT3HhBQdem8mUS7shw.png)

![应用截图 9](https://www.cmspro.cn/uploads/appstore/2026/11/12/0vuzGTtHSaLEIhzisBzAzZEQ0KJs8YjnLrMy5uY2.png)

![应用截图 10](https://www.cmspro.cn/uploads/appstore/2026/11/12/EncjQ4nMPC19xRievaO5DLntvC0SGJqWA6CravSK.png)

![应用截图 11](https://www.cmspro.cn/uploads/appstore/2026/11/12/f0Dwupub65OAkgodba3Cx5LaL1U4QSxUcxzViCad.png)

## 3. 目录结构

```
CmsproCoupon/
├── Config/coupon.php                  # 默认配置（访问模式/前缀/规则开关）
├── Console/CouponExpireCommand.php    # coupon:expire 过期扫描命令
├── Http/Middleware/ForceJsonResponse.php # API 强制 JSON 响应中间件
├── Routes/
│   ├── admin.php / admin_api.php      # 后台页面 / 数据接口
│   ├── user.php / user_api.php        # 用户端页面 / 数据接口
│   └── home.php / home-api.php        # 前端页面 / 核销 API
├── Controllers/
│   ├── AdminBaseController.php        # 后台基类（权限校验 + 响应封装）
│   ├── Admin/                         # 活动/兑换码/用户券/核销记录/设置控制器
│   ├── User/                          # 领券中心 / 我的优惠券控制器
│   ├── Home/                          # 前端领券页控制器
│   └── HomeApi/                       # 核销 API / 公开活动 API 控制器
├── Models/                            # 券活动、用户券、兑换码、发放批次、核销记录
├── Services/                          # Activity/Issue/RedeemCode/UserCoupon/Verify/Record/Setting/CouponRule
├── Migrations/                        # 5 张业务表迁移（app_cmspro_coupon_ 前缀，幂等+全备注）
├── Tests/                             # 80 个测试（迁移/规则单元/发放/核销/用户端/后台API/JSON响应回归）
├── Views/
│   ├── Admin/                         # 后台视图（activities/codes/user-coupons/verify-logs/issue-batches/settings）
│   ├── User/                          # 用户中心视图（center/my-coupons）
│   └── Home/                          # 前端领券页视图
├── Hooks.php                          # 钩子注册器
├── Install.php                        # 安装/卸载/升级逻辑（迁移兜底 + 配置三向同步）
├── ServiceProvider.php                # 应用服务提供者
├── helpers.php                        # 应用级辅助函数
├── manifest.json                      # 应用元数据唯一声明源
├── icon.svg                           # 应用图标（120×120）
├── README.md                          # 本文件
└── doc/                               # 应用文档（特性清单/使用指南/扩展指南）
```

## 4. 应用文档

| 文档 | 说明 |
|------|------|
| [CmsPro-优惠券-使用指南.md](doc/CmsPro-优惠券-使用指南.md) | 安装、配置、后台/用户端/前端功能使用与验证 |
| [CmsPro-优惠券-特性清单.md](doc/CmsPro-优惠券-特性清单.md) | 框架特性覆盖清单与实现位置对照 |
| [CmsPro-优惠券-扩展指南.md](doc/CmsPro-优惠券-扩展指南.md) | 其他应用调用核销 Service、钩子点、辅助函数说明 |

## 5. 快速验证

```bash
# 在 code 目录下执行应用测试
php artisan test app/Apps/CmsproCoupon/Tests

# 过期扫描命令（应用安装后）
php artisan coupon:expire --dry-run
```
