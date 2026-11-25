<?php
/**
 * 轩宇-导航 · 站点图标兜底配置
 *
 * NAV_ICON_PROXY：第三方图标服务，用 sprintf 占位 %s 表示站点 host。
 * ⚠️ 该服务（api.iowen.cn）在 v2.19.1 曾证书过期导致全站图标变字母块（P0 事故）。
 *    它只是图标兜底链的【最后一道】，不得当作唯一来源；置空字符串即完全关闭。
 *    主链是本地缓存图标（uploads/icons/）与原站 favicon，见 includes/site_card_build.php。
 */
if (!defined('NAV_ICON_PROXY')) {
    define('NAV_ICON_PROXY', 'https://api.iowen.cn/favicon/%s.png');
}
