<?php
/**
 * 卡片 data-description 只保留前 120 字：完整描述已经在 .site-desc 里，
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
 * 轩宇-导航 · 站点卡片渲染（v2.18.1）
 *
 * 首页首屏渲染和 api/home_list.php 的「加载更多」都走这里，
 * 保证两处产出的 DOM 完全一致（否则前端复用卡片模板会错位）。
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

$status = navStatusLabel($link['check_status'] ?? 'unknown');
$chain = (array) ($link['favicon_chain'] ?? []);
$clicks = (int) ($link['click_count'] ?? 0);
$card_target = (string) ($link['target'] ?? $defaultTarget ?? '_blank');
?>
<a class="site-card" href="<?php echo $functions->escape($link['safe_url']); ?>" target="<?php echo $functions->escape($card_target); ?>" rel="noopener noreferrer" style="--enter-index: <?php echo (int) ($card_index % 12); ?>;" aria-label="访问 <?php echo $functions->escape($link['display_name']); ?>（<?php echo $functions->escape($link['display_domain']); ?>）" data-id="<?php echo (int) $link['id']; ?>" data-category="<?php echo (int) $link['category_id']; ?>" data-name="<?php echo $functions->escape($link['display_name']); ?>" data-domain="<?php echo $functions->escape($link['display_domain']); ?>" data-host="<?php echo $functions->escape($link['host']); ?>" data-clicks="<?php echo $clicks; ?>" data-description="<?php echo $functions->escape(navCardBrief((string) ($link['description'] ?? ''))); ?>" data-tags="<?php echo $functions->escape((string) ($link['tags'] ?? '')); ?>" data-alias="<?php echo $functions->escape((string) ($link['alias'] ?? '')); ?>" data-starred="<?php echo !empty($link['is_starred']) ? '1' : '0'; ?>" data-check-status="<?php echo $functions->escape((string) $link['check_status']); ?>"><span class="site-status" data-state="<?php echo $functions->escape($status['state']); ?>" data-label="<?php echo $functions->escape($status['label']); ?>" role="img" aria-label="网站状态：<?php echo $functions->escape($status['label']); ?>"><span class="status-dot"></span></span><span class="site-card-main"><span class="site-icon" aria-hidden="true"><span class="site-icon-letter" style="--fallback-bg: <?php echo $functions->escape($link['fallback_bg']); ?>; --fallback-fg: <?php echo $functions->escape($link['fallback_fg']); ?>"><?php echo $functions->escape($link['fallback_initial']); ?></span><img class="site-favicon" alt="" width="44" height="44" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-src="<?php echo $functions->escape($chain[0] ?? ''); ?>" data-fb1="<?php echo $functions->escape($chain[1] ?? ''); ?>" data-fb2="<?php echo $functions->escape($chain[2] ?? ''); ?>" data-fb3="<?php echo $functions->escape($chain[3] ?? ''); ?>" data-fb4="<?php echo $functions->escape($chain[4] ?? ''); ?>"></span><span class="site-info"><span class="site-name"><?php echo $functions->escape($link['display_name']); ?></span><span class="site-desc"><?php echo $functions->escape(navCardBrief((string) ($link['description'] ?? ''), 80)); ?></span></span></span><span class="site-meta"><span class="site-domain"><?php echo $functions->escape($link['display_domain']); ?></span><span class="site-metrics"><span class="site-clicks" title="累计点击 <?php echo number_format($clicks); ?> 次"><?php echo $clicks > 0 ? number_format($clicks) . ' 次' : '新收录'; ?></span><?php if ($monitorEnabled && $showSpeedLabel): ?><?php $skippedLink = $link['check_status'] === 'skipped'; ?><span class="site-speed" data-state="<?php echo $skippedLink ? 'skipped' : ($link['check_status'] === 'unknown' ? 'pending' : 'ok'); ?>" <?php if (!$skippedLink && $link['latency_ms']): ?>data-level="<?php echo $link['latency_ms'] < 300 ? 'fast' : ($link['latency_ms'] > 1000 ? 'slow' : 'mid'); ?>"<?php endif; ?>><?php if ($skippedLink) { echo '内网'; } elseif ($link['latency_ms']) { echo (int) $link['latency_ms'] . ' 毫秒'; } else { echo '检测中'; } ?></span><?php endif; ?></span></span><span class="card-corner" role="toolbar" aria-label="卡片操作"><span class="card-star" role="button" tabindex="0" aria-label="<?php echo !empty($link['is_starred']) ? '取消收藏' : '收藏'; ?>" title="<?php echo !empty($link['is_starred']) ? '取消收藏' : '收藏'; ?>"><svg viewBox="0 0 24 24" fill="<?php echo !empty($link['is_starred']) ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></span><?php if ($is_owner): ?><span class="card-actions"><span class="card-action" data-action="edit" role="button" tabindex="0" aria-label="编辑" title="编辑"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4l10-10-4-4L4 16v4z"></path><path d="M14 6l4 4"></path></svg></span><span class="card-action" data-action="copy" role="button" tabindex="0" aria-label="复制链接" title="复制链接"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></span><span class="card-action" data-action="qrcode" role="button" tabindex="0" aria-label="二维码" title="二维码"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><path d="M14 14h3v3M21 14v7M14 21h3M17 17h4"></path></svg></span><span class="card-action" data-action="move" role="button" tabindex="0" aria-label="移动分组" title="移动分组"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h14M5 12h14M5 20h14"></path><path d="M9 8l3-3 3 3M9 16l3 3 3-3"></path></svg></span><span class="card-action card-action-danger" data-action="delete" role="button" tabindex="0" aria-label="删除" title="删除"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"></path></svg></span></span><?php endif; ?></span></a>
