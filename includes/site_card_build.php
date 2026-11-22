<?php
/**
 * 轩宇-导航 · 卡片数据整形（v2.18.1）
 *
 * 原本写在 index.php 里，现在首页首屏和 api/home_list.php 都要用，
 * 抽出来保证两边算出来的字段、图标兜底链、状态文案完全一致。
 */

/**
 * v2.18.24 · 问题5：字标型站标在 24px 下只剩噪点。
 *
 * 判定对象只限「本地缓存图标」（uploads/icons/，服务端可读），不改数据源。
 * 命中后模板打 data-wordmark="1"，CSS 用高斯模糊把笔画并成色块，
 * 轮廓与主色仍在，但不会再出现"读不出来又很像字"的噪点。
 *
 * 证据（两段判定，先看有没有字，再看字多不多）：
 *   ① 二值化后按每行的「明暗翻转次数」看细碎程度：文字行内笔画频繁切换，
 *      平均翻转 ≥ 5 次；纯色底 + 单个大字母（K、C）只有 1–2 次；
 *   ② 平均笔画段宽 ≤ 3.3px 且「含 ≥2 段的行」占一半以上：
 *      文字由大量窄笔画并排组成；星芒、单字、折线这类单笔图形宽 4–7px。
 *   ③ 墨水量 60–700px：太少的（小圆点）与太多的（满幅色块）都不是字标。
 *
 * 已知判定结果（本地 32 个缓存图标实测）：
 *   Claude 星芒字标、深底字母串（AstrBot 一类）→ 命中；
 *   React 原子、Kimi 的 K、知乎「知」字块、PHP 圆角块、淘宝「淘」→ 不命中。
 * 结果按「文件大小 + mtime」缓存，同一请求内重复卡片零开销。
 */
if (!function_exists('navIsWordmarkIcon')) {
    function navIsWordmarkIcon($url)
    {
        static $cache = [];

        $url = (string) $url;

        if ($url === '') {
            return false;
        }

        $pathPart = (string) parse_url($url, PHP_URL_PATH);
        $pos = strpos($pathPart, 'uploads/icons/');

        if ($pos === false) {
            return false;
        }

        $abs = __DIR__ . '/../' . ltrim(substr($pathPart, $pos), '/');

        if (!is_file($abs) || !function_exists('imagecreatetruecolor')) {
            return false;
        }

        $stamp = (string) @filesize($abs) . '-' . (string) @filemtime($abs);

        if (isset($cache[$abs]) && $cache[$abs]['stamp'] === $stamp) {
            return $cache[$abs]['is'];
        }

        $is = false;

        try {
            $raw = @file_get_contents($abs, false, null, 0, 2 * 1024 * 1024);
            $img = $raw !== false && $raw !== '' ? @imagecreatefromstring($raw) : false;

            if ($img) {
                $size = 32;
                $canvas = imagecreatetruecolor($size, $size);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
                imagealphablending($canvas, true);
                imagecopyresampled($canvas, $img, 0, 0, 0, 0, $size, $size,
                    imagesx($img), imagesy($img));
                imagedestroy($img);

                $lum = [];
                $sum = 0;

                for ($y = 0; $y < $size; $y++) {
                    for ($x = 0; $x < $size; $x++) {
                        $rgb = imagecolorat($canvas, $x, $y);
                        $a = (($rgb >> 24) & 0x7F) / 127;
                        $v = 0.299 * (($rgb >> 16) & 0xFF)
                            + 0.587 * (($rgb >> 8) & 0xFF)
                            + 0.114 * ($rgb & 0xFF);
                        /* 半透明像素按白底合成：透明底不会变成"深墨" */
                        $v = $v * (1 - $a) + 255 * $a;
                        $lum[$y][$x] = $v;
                        $sum += $v;
                    }
                }

                imagedestroy($canvas);

                $mean = $sum / ($size * $size);
                $darkInk = $mean > 130;
                $thr = $darkInk ? max(120, $mean - 60) : min(200, $mean + 60);

                $runs = 0;
                $runWidth = 0;
                $inkPixels = 0;
                $flips = 0;
                $rowsWithTwo = 0;

                for ($y = 0; $y < $size; $y++) {
                    $run = 0;
                    $rowRuns = 0;
                    $prev = null;

                    for ($x = 0; $x <= $size; $x++) {
                        $on = $x < $size && ($darkInk ? $lum[$y][$x] < $thr : $lum[$y][$x] > $thr);

                        if ($on) {
                            $run++;
                            $inkPixels++;
                        } elseif ($run > 0) {
                            $runs++;
                            $runWidth += $run;
                            $rowRuns++;
                            $run = 0;
                        }

                        if ($x < $size) {
                            if ($prev !== null && $on !== $prev) {
                                $flips++;
                            }
                            $prev = $on;
                        }
                    }

                    if ($rowRuns >= 2) {
                        $rowsWithTwo++;
                    }
                }

                /* 是否"多行并排的窄笔画"：文字每行至少有 2 段、段宽窄；
                   单笔图形标（星芒、单字母）每行 1 段或段宽很粗。 */
                $narrowRuns = 0;
                $narrowWidth = 0;

                for ($y = 0; $y < $size; $y++) {
                    $run = 0;

                    for ($x = 0; $x <= $size; $x++) {
                        $on = $x < $size && ($darkInk ? $lum[$y][$x] < $thr : $lum[$y][$x] > $thr);

                        if ($on) {
                            $run++;
                        } elseif ($run > 0) {
                            if ($run <= 7) {
                                $narrowRuns++;
                                $narrowWidth += $run;
                            }
                            $run = 0;
                        }
                    }
                }

                if ($runs >= 12) {
                    $avgWidth = $runWidth / $runs;
                    $narrowAvg = $narrowRuns > 0 ? $narrowWidth / $narrowRuns : 99;
                    $lineFlip = $flips / $size;

                    /* 三段证据：细碎（每行翻转多）+ 并排（多段行占一半以上）+
                       窄笔画（平均段宽 ≤ 3.6）。本地实测：deep 底字母串 lineFlip 3.9、
                       bilibili 字标 4.4、Claude 星芒字标 5.2 全部命中；React 原子 2.2、
                       淘宝「淘」1.3、知乎「知」1.3 落在门槛外。 */
                    $is = $lineFlip >= 3.5
                        && $narrowAvg <= 3.6
                        && ($rowsWithTwo / $size) >= 0.5
                        && $inkPixels >= 60
                        && $inkPixels <= 620;
                }
            }
        } catch (Throwable $ignored) {
            $is = false;
        }

        $cache[$abs] = ['stamp' => $stamp, 'is' => $is];

        return $is;
    }
}

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
        /* v2.18.24 · 问题5：字标型图标在 24px 下不可辨，模板据此收小到 20px。
           只看本地缓存图标（首项即 uploads/icons 的才可能命中）。 */
        $row['icon_is_wordmark'] = navIsWordmarkIcon((string) ($chain[0] ?? ''));

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
