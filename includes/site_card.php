<?php
/**
 * 卡片文案截断：DOM 会同时承载「完整描述」和「80 字摘要」两处，
 * 之前两处都写全文，单张卡片能撑到 2KB 以上（首页 200 张就是几十 KB 白流量）。
 */
if (!function_exists('navCardBrief')) {
    function navCardBrief($text, $limit = 120)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
            return mb_substr($text, 0, $limit, 'UTF-8') . '…';
        }

        return $text;
    }
}

/**
 * v2.18.5 · URL 归一化 key：协议与域名小写、去 www. 前缀、去结尾斜杠。
 * 首页 index.php / api/home_list.php / api/user_sites.php 三处共用同一规则，
 * 用于把「被展示用户的网址」映射到「当前浏览者自己的收藏状态」。
 * api/user_sites.php 里有一份同样实现（该接口不加载本模板），改动需同步。
 */
if (!function_exists('navUrlKey')) {
    function navUrlKey($url)
    {
        $raw = trim((string) $url);

        if ($raw === '') {
            return '';
        }

        $hasScheme = (bool) preg_match('#^https?://#i', $raw);
        $parts = parse_url($hasScheme ? $raw : 'http://' . $raw);

        if (!is_array($parts) || empty($parts['host'])) {
            return strtolower(rtrim(preg_replace('#^https?://#i', '', $raw), '/'));
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host = preg_replace('/^www\./i', '', strtolower((string) $parts['host']));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }
}
/**
 * 轩宇-导航 · 站点卡片渲染（v2.18.1）
 *
 * 首页首屏渲染和 api/home_list.php 的「加载更多」都走这里，
 * 保证两处产出的 DOM 完全一致（否则前端复用卡片模板会错位）。
 *
 * 体积约束（首屏 HTML < 100KB）：
 *   - svg 的 stroke / stroke-width / linecap / linejoin 统一交给 CSS，
 *     模板里只留 viewBox 和 fill（fill 要能被 JS 改成 currentColor，不能写进 CSS）；
 *   - 收藏星标走页面内 <symbol id="nav-i-star"> + <use>，每张卡省约 200 字节；
 *   - 空值属性（tags / alias / description / 图标兜底链）为空时整段不输出；
 *   - data-description 只在「浏览者就是主人」时输出 —— 它是编辑面板的预填值，
 *     访客永远用不到，首页访客视图因此再省 5KB。
 *
 * 入参：
 *   $link       navBuildCard() 整理过的单条记录
 *   $card_index 卡片序号，用于入场动画错峰（可缺省）
 *   $is_owner   当前浏览者是否就是这条数据的主人（决定是否输出编辑/删除按钮）
 *   $functions  Functions 实例
 */
if (!isset($link) || !is_array($link)) {
    return;
}

$card_index = isset($card_index) ? (int) $card_index : 0;
$is_owner = isset($is_owner) ? (bool) $is_owner : false;
$card_eager = !empty($card_eager);

$status = navStatusLabel($link['check_status'] ?? 'unknown');
$chain = (array) ($link['favicon_chain'] ?? []);
$clicks = (int) ($link['click_count'] ?? 0);
$card_target = (string) ($link['target'] ?? $defaultTarget ?? '_blank');

$cardDescFull = trim(preg_replace('/\s+/u', ' ', (string) ($link['description'] ?? '')));
$cardDescMeta = $is_owner ? navCardBrief($cardDescFull, 100) : '';
$cardDescText = navCardBrief($cardDescFull, 80);
$cardTags = trim((string) ($link['tags'] ?? ''));
$cardAlias = trim((string) ($link['alias'] ?? ''));
// v2.18.5 · $starOverride 由调用方传入：登录访客看他人导航时，星标必须按
// 「浏览者自己名下有没有收藏」算，而不是被展示用户那一行的 is_starred（否则刷新回弹）。
$starOverride = isset($starOverride) && $starOverride !== null ? (int) $starOverride : null;
$cardStarred = $starOverride !== null ? ($starOverride === 1) : !empty($link['is_starred']);
$cardStarLabel = $cardStarred ? '取消收藏' : '收藏';
$cardSpeedSkipped = ($link['check_status'] ?? '') === 'skipped';
$cardLatency = (int) ($link['latency_ms'] ?? 0);
$cardChain = array_values(array_filter(array_map('strval', $chain), function ($u) {
    return $u !== '';
}));
?>
<a class="site-card" href="<?php echo $functions->escape($link['safe_url']); ?>" target="<?php echo $functions->escape($card_target); ?>" rel="noopener noreferrer" style="--enter-index: <?php echo (int) ($card_index % 12); ?>" aria-label="访问 <?php echo $functions->escape($link['display_name']); ?>（<?php echo $functions->escape($link['display_domain']); ?>）" data-id="<?php echo (int) $link['id']; ?>" data-category="<?php echo (int) $link['category_id']; ?>" data-name="<?php echo $functions->escape($link['display_name']); ?>" data-domain="<?php echo $functions->escape($link['display_domain']); ?>" data-host="<?php echo $functions->escape($link['host']); ?>" data-clicks="<?php echo $clicks; ?>" data-starred="<?php echo $cardStarred ? '1' : '0'; ?>" data-check-status="<?php echo $functions->escape((string) $link['check_status']); ?>"<?php if (!$cardSpeedSkipped && $cardLatency > 0): ?> data-latency="<?php echo $cardLatency; ?>"<?php endif; ?><?php if ($cardDescMeta !== ''): ?> data-description="<?php echo $functions->escape($cardDescMeta); ?>"<?php endif; ?><?php if ($cardTags !== ''): ?> data-tags="<?php echo $functions->escape($cardTags); ?>"<?php endif; ?><?php if ($cardAlias !== ''): ?> data-alias="<?php echo $functions->escape($cardAlias); ?>"<?php endif; ?>><span class="site-status" data-state="<?php echo $functions->escape($status['state']); ?>" data-label="<?php echo $functions->escape($status['label']); ?>" role="img" aria-label="网站状态：<?php echo $functions->escape($status['label']); ?>"><span class="status-dot"></span></span><span class="site-card-main"><span class="site-icon" aria-hidden="true"><span class="site-icon-letter" style="--fallback-bg: <?php echo $functions->escape($link['fallback_bg']); ?>; --fallback-fg: <?php echo $functions->escape($link['fallback_fg']); ?>"><?php echo $functions->escape($link['fallback_initial']); ?></span><img class="site-favicon" alt="" width="44" height="44" <?php echo !empty($card_eager) ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"'; ?> referrerpolicy="no-referrer" data-src="<?php echo $functions->escape($cardChain[0] ?? ''); ?>"<?php if (isset($cardChain[1])): ?> data-fb1="<?php echo $functions->escape($cardChain[1]); ?>"<?php endif; ?><?php if (isset($cardChain[2])): ?> data-fb2="<?php echo $functions->escape($cardChain[2]); ?>"<?php endif; ?><?php if (isset($cardChain[3])): ?> data-fb3="<?php echo $functions->escape($cardChain[3]); ?>"<?php endif; ?><?php if (isset($cardChain[4])): ?> data-fb4="<?php echo $functions->escape($cardChain[4]); ?>"<?php endif; ?>></span><span class="site-info"><span class="site-name"><?php echo $functions->escape($link['display_name']); ?></span><?php if ($cardDescText !== ''): ?><span class="site-desc"><?php echo $functions->escape($cardDescText); ?></span><?php endif; ?></span></span><span class="site-meta"><span class="site-domain"><?php echo $functions->escape($link['display_domain']); ?></span><span class="site-metrics"><span class="site-clicks"><?php echo $clicks > 0 ? number_format($clicks) . ' 次' : '新收录'; ?></span><?php if ($monitorEnabled && $showSpeedLabel): ?><span class="site-speed" data-state="<?php echo $cardSpeedSkipped ? 'skipped' : ($link['check_status'] === 'unknown' ? 'pending' : ($link['check_status'] === 'down' ? 'error' : 'ok')); ?>"<?php if (!$cardSpeedSkipped && $cardLatency): ?> data-level="<?php echo $cardLatency < 300 ? 'fast' : ($cardLatency > 1000 ? 'slow' : 'mid'); ?>"<?php endif; ?>><?php if ($cardSpeedSkipped) { echo '内网'; } elseif ($link['check_status'] === 'down') { echo '无法访问'; } elseif ($cardLatency) { echo $cardLatency . ' 毫秒 · ' . ($cardLatency < 300 ? '快' : ($cardLatency > 1000 ? '慢' : '一般')); } else { echo '测速中'; } ?></span><?php endif; ?></span></span><span class="card-corner" role="toolbar" aria-label="卡片操作"><span class="card-star" role="button" tabindex="0" aria-label="<?php echo $cardStarLabel; ?>" title="<?php echo $cardStarLabel; ?>"><svg viewBox="0 0 24 24" fill="<?php echo $cardStarred ? 'currentColor' : 'none'; ?>" aria-hidden="true"><use href="#nav-i-star"></use></svg></span><?php if ($is_owner): ?><span class="card-actions"><span class="card-action" data-action="edit" role="button" tabindex="0" aria-label="编辑" title="编辑"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10-10-4-4L4 16v4z"></path><path d="M14 6l4 4"></path></svg></span><span class="card-action" data-action="copy" role="button" tabindex="0" aria-label="复制链接" title="复制链接"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></span><span class="card-action" data-action="qrcode" role="button" tabindex="0" aria-label="二维码" title="二维码"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><path d="M14 14h3v3M21 14v7M14 21h3M17 17h4"></path></svg></span><span class="card-action" data-action="move" role="button" tabindex="0" aria-label="移动分组" title="移动分组"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14M5 12h14M5 20h14"></path><path d="M9 8l3-3 3 3M9 16l3 3 3-3"></path></svg></span><span class="card-action card-action-danger" data-action="delete" role="button" tabindex="0" aria-label="删除" title="删除"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"></path></svg></span></span><?php endif; ?></span></a>
