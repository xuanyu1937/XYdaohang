<?php
/**
 * 轩宇-导航 · 卡片数据整形（v2.18.1）
 *
 * 原本写在 index.php 里，现在首页首屏和 api/home_list.php 都要用，
 * 抽出来保证两边算出来的字段、图标兜底链、状态文案完全一致。
 */

if (!function_exists('navBuildCard')) {
/**
 * 把一条记录整理成卡片所需字段。
 */
function navBuildCard(array $row, Functions $functions)
{
    $rawUrl = trim((string) ($row['url'] ?? ''));

        if ($rawUrl === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $rawUrl)) {
            $rawUrl = 'https://' . ltrim($rawUrl, '/');
        }

        $parts = parse_url($rawUrl);

        if (!$parts || empty($parts['host'])) {
            return null;
        }

        $host = $parts['host'];
        $scheme = strtolower($parts['scheme'] ?? 'https');

        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $displayName = trim((string) ($row['name'] ?? ''));

        if ($displayName === '') {
            $displayName = $host;
        }

        $rawLogo = trim((string) ($row['logo'] ?? ''));
        $logoUrl = $functions->getLogoDisplayUrl($rawLogo);
        $chain = [];

        /* v2.18.1 · 图标兜底链收敛：
           ① 本地缓存图标（uploads/icons/，由后台「批量补齐本地图标」生成）；
           ② 站点自身 favicon；
           ③ 第三方只保留一个（iowen 国内可达，google/duckduckgo 已移除）。
           只有「本地缓存生效」时才砍掉第③层：本地图标是下载后校验过的，
           缺了就是真缺；外链 logo 仍可能 404，需要第三方兜底。 */
        $isLocalLogo = $rawLogo !== ''
            && stripos(ltrim($rawLogo, '/'), IconCacheService::DIRECTORY . '/') === 0;

        if ($logoUrl !== '') {
            $chain[] = $logoUrl;
        }

        $chain[] = $scheme . '://' . $host . '/favicon.ico';

        if (!$isLocalLogo) {
            $chain[] = 'https://api.iowen.cn/favicon/' . $host . '.png';
        }

        $chain = array_values(array_unique(array_filter($chain, function ($url) {
            return is_string($url) && $url !== '';
        })));

        $initial = function_exists('mb_substr')
            ? mb_substr($displayName, 0, 1, 'UTF-8')
            : strtoupper(substr($displayName, 0, 1));

        if ($initial === '' || $initial === false) {
            $initial = '#';
        } elseif (preg_match('/^[a-z]$/', $initial)) {
            $initial = strtoupper($initial);
        }

        $row['safe_url'] = $rawUrl;
        $row['host'] = $host;
        $row['display_domain'] = preg_replace('/^www\./i', '', $host);
        $row['display_name'] = $displayName;
        $row['initial'] = $initial;

        /* favicon 全部失败时的兜底：取域名首字母 + 由域名哈希决定的稳定底色。
           以前只用网站名首字且没有底色，图标服务返回透明图时看起来就是空白卡片。 */
        $domainForInitial = preg_replace('/^www\./i', '', (string) $host);
        $fallbackInitial = function_exists('mb_substr')
            ? mb_substr($domainForInitial, 0, 1, 'UTF-8')
            : substr($domainForInitial, 0, 1);
        $fallbackInitial = strtoupper((string) $fallbackInitial);

        if ($fallbackInitial === '') {
            $fallbackInitial = '#';
        }

        $fallbackPalette = [
            ['#e8efff', '#3457c5'], ['#e7f6ee', '#1e7a52'],
            ['#fff0e4', '#b6601c'], ['#f2eaff', '#7040b5'],
            ['#ffecef', '#b83a54'], ['#e4f5f8', '#1b7189'],
            ['#fbf1d8', '#8a6816'], ['#ecf2e5', '#4a7030'],
        ];
        $fallbackPair = $fallbackPalette[hexdec(substr(md5((string) $host), 0, 2)) % count($fallbackPalette)];

        $row['fallback_initial'] = $fallbackInitial;
        $row['fallback_bg'] = $fallbackPair[0];
        $row['fallback_fg'] = $fallbackPair[1];
        $row['favicon_chain'] = $chain;
        $row['click_count'] = max(0, (int) ($row['clicks'] ?? 0));
        $row['check_status'] = (string) ($row['check_status'] ?? 'unknown');
        $row['latency_ms'] = isset($row['latency_ms']) ? (int) $row['latency_ms'] : null;

        return $row;
    }

    /** 把状态枚举映射为前端既有 class / 文案，保证与 nav.js 的探测结果一致。 */
    function navStatusLabel($status)
    {
        switch ($status) {
            case 'ok':
                return ['state' => 'online', 'label' => '在线'];
            case 'slow':
                return ['state' => 'online', 'label' => '在线（较慢）'];
            case 'down':
                return ['state' => 'offline', 'label' => '离线'];
            case 'skipped':
                // v2.18.1 · 内网 / 跳过检测：灰色「内网」，不显示延迟与红点
                return ['state' => 'skipped', 'label' => '内网'];
            default:
                return ['state' => 'pending', 'label' => '检测中'];
        }
    }
}
