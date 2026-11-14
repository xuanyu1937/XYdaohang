<?php
/**
 * 轩宇-导航 · 全站统一快捷入口（v2.12.0）
 *
 * 此前「关于 / 联系管理员 / 申请收录」只写在首页页脚，用户进到我的导航、
 * 子页面或后台就找不到；API 文档页 /api/ 也没有任何前台入口。
 * 这里集中生成同一组链接，前台页脚与后台侧栏共用，避免各处文案/顺序漂移。
 *
 * 样式随组件一次性内联输出：不依赖页面是否已引入 nav.css / admin.css，
 * 也不受各页相对路径影响（根目录页、admin/ 下的后台页、Tailwind 版申请页都适用）。
 *
 * 用法：
 *   require_once __DIR__ . '/../includes/site_links.php';   // 根目录页面
 *   echo siteQuickLinksHtml();                              // 前台
 *   echo siteQuickLinksHtml('..', 'admin');                 // admin/ 下页面
 */

if (!function_exists('siteQuickLinksHtml')) {
    /**
     * @param string $base    相对前缀：根目录页面传 ''，admin/ 下传 '..'
     * @param string $variant 'front' | 'admin'
     */
    function siteQuickLinksHtml($base = '', $variant = 'front')
    {
        static $cssPrinted = false;

        $base = rtrim((string) $base, '/');
        $prefix = $base === '' ? '' : $base . '/';
        $isAdmin = $variant === 'admin';

        $links = [
            ['全部站点', $prefix . 'sites.php'],
            ['关于本站', $prefix . 'about.php'],
            ['联系管理员', $prefix . 'contact.php'],
            ['申请收录', $prefix . 'apply.php'],
            // 后台侧栏用更明确的「查看 API 文档」，前台保持简短的「API 说明」
            [$isAdmin ? '查看 API 文档' : 'API 说明', $prefix . 'api/'],
        ];

        $class = $isAdmin ? 'site-quick-links is-admin' : 'site-quick-links';
        $html = '';

        if (!$cssPrinted) {
            $cssPrinted = true;
            $html .= '<style>' . siteQuickLinksCss() . '</style>';
        }

        $html .= '<nav class="' . $class . '" aria-label="快捷入口">';

        foreach ($links as $index => $link) {
            if ($index > 0) {
                $html .= '<span class="footer-sep" aria-hidden="true">·</span>';
            }

            $isApi = $link[0] === 'API 说明' || $link[0] === '查看 API 文档';
            $html .= '<a href="' . htmlspecialchars($link[1], ENT_QUOTES, 'UTF-8') . '"'
                . ($isApi ? ' title="查看开放 API 使用文档"' : '')
                . '>' . htmlspecialchars($link[0], ENT_QUOTES, 'UTF-8') . '</a>';
        }

        return $html . '</nav>';
    }
}

if (!function_exists('siteQuickLinksCss')) {
    /**
     * 组件自带样式：所有选择器以 .site-quick-links 为前缀，不污染宿主页面。
     * 移动端保持 display:flex 且居中，不会被各页的隐藏规则抹掉。
     */
    function siteQuickLinksCss()
    {
        return '
.site-quick-links {
    display: flex !important;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 4px 0;
    margin: 8px auto 0;
    padding: 0;
    font-size: 11.5px;
    line-height: 1.8;
    color: var(--muted-2, inherit);
    text-align: center;
}
.site-quick-links a {
    color: inherit;
    text-decoration: none;
    padding: 2px 3px;
    border-radius: 4px;
    white-space: nowrap;
}
.site-quick-links a:hover,
.site-quick-links a:focus-visible { color: var(--primary, #4f6bed); text-decoration: underline; }
.site-quick-links a:focus-visible { outline: 2px solid var(--primary, #4f6bed); outline-offset: 2px; }
.site-quick-links .footer-sep { margin: 0 3px; opacity: .55; }
.site-quick-links.is-admin {
    margin: 10px 0 12px;
    gap: 2px 0;
    font-size: 11px;
    padding: 10px 8px 0;
    border-top: 1px solid rgba(127, 127, 127, .22);
}
.site-quick-links.is-admin a { padding: 2px 4px; }
@media (max-width: 767px) {
    .site-quick-links {
        display: flex !important;
        visibility: visible !important;
        opacity: 1 !important;
        width: 100%;
        justify-content: center;
        font-size: 11px;
        margin-top: 10px;
        padding-bottom: 4px;
    }
    .site-quick-links.is-admin { font-size: 10.5px; }
}
';
    }
}
