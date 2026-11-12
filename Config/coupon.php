<?php

/**
 * 优惠券应用 · 默认配置
 *
 * 由 ServiceProvider::register() 通过 mergeConfigFrom 合并到
 * config('apps.cmspro.coupon')，作为数据库 config_items 无记录时的兜底值。
 */

return [
    'name' => '优惠券',

    // ── 基础设置 ────────────────────────────────────────────────
    // 前端访问模式：none=无前端页面 / path=路径访问 / domain=子域名绑定
    'access_mode' => 'path',
    // 路径访问模式的前缀，如 coupon 则通过 /coupon 访问
    'access_path' => 'coupon',
    // 子域名模式绑定的域名，支持逗号分隔多个
    'access_domain' => '',
    // 用户主动领取总开关
    'receive_enabled' => '1',
    // 用户券号前缀
    'coupon_no_prefix' => 'CP',
    // 批量生成兑换码的统一前缀
    'code_prefix' => '',
    // 列表分页大小
    'page_size' => 20,

    // ── 规则设置 ────────────────────────────────────────────────
    // 是否允许同一订单核销多张券
    'multi_use_enabled' => '0',
    // 是否允许撤销核销
    'revoke_enabled' => '1',
    // 是否启用过期扫描定时命令
    'expire_scan_enabled' => '1',
    // 到期提醒天数
    'expire_notify_days' => 3,
    // 优惠额取整方式：floor / round
    'discount_round_mode' => 'floor',
];
