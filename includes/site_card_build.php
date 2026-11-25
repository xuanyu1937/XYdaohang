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
 * 两条措施：
 *   ① 渲染层（对所有图标生效）：≤960px 时统一加 0.35px 亚像素模糊。
 *      24px 下小字只有 1px 宽的笔画，一道亚像素模糊就把它抹成色块，
 *      但图形标（猫头、字母块、山形）的轮廓只掉一点点锐度，观感反而更整。
 *      这一层不需要认识图标，所以不会误伤、也不会漏。
 *   ② 判定层（只对本地缓存图标）：命中后模板输出 class="...is-wordmark"，
 *      CSS 再叠 1.6px 高斯模糊，把成串小字彻底糊平。
 *
 * 判定只看像素，不看站点名。三段证据同时成立才算字标：
 *   ① 每行平均明暗翻转 ≥ 5.0：一行小字里笔画频繁进出；
 *   ② 平均笔画段宽 ≤ 3.0px、且「含 ≥2 段的行」占七成以上：小字由大量窄笔画并排；
 *   ③ 墨水量 60–620px：太少（小圆点）或太多（满幅色块）都不算。
 * 门槛比初版（3.5 / 3.6 / 0.5）紧得多——初版会把机器人插画、单个大字母
 * 一起判进来，24px 下把它们糊成一团比不糊更难看。
 *
 * 判定对象限定为「本地缓存图标」（uploads/icons/，服务端可读）：
 * 外链图标要判定就得在渲染时联网抓图，实测服务器出口对第三方图标域名
 * 成功率不到一半，冷缓存一次首屏要赔上几秒，不值当——这类图标交给①的
 * 统一软化即可。结果按「文件大小 + mtime」记，同一请求内重复卡片零开销。
 */
if (!function_exists('navWordmarkDecodeImage')) {
    /**
     * 把一段图片字节解成 GD 资源。
     *
     * GD 的 imagecreatefromstring 只认 PNG 封装的 ICO，而站标里大量是
     * 「多帧 BMP」的老式 ICO（16/32/48/64 多尺寸叠在一个文件里），直接读会失败。
     * 这里补一层 ICO 解析：取面积最大的帧，PNG 帧交给 GD，
     * BMP 帧按 32/24/8/4/1bpp 手工铺像素，并用 AND 掩码还原透明。
     */
    function navWordmarkDecodeImage($raw)
    {
        $img = @imagecreatefromstring($raw);

        if ($img) {
            return $img;
        }

        if (!is_string($raw) || strlen($raw) < 22) {
            return false;
        }

        $hdr = unpack('vres/vtype/vcount', substr($raw, 0, 6));

        if ((int) $hdr['res'] !== 0 || (int) $hdr['type'] !== 1 || (int) $hdr['count'] < 1) {
            return false;
        }

        $best = null;
        $bestArea = 0;

        for ($i = 0; $i < (int) $hdr['count']; $i++) {
            $o = 6 + $i * 16;

            if (strlen($raw) < $o + 16) {
                break;
            }

            $e = unpack('Cw/Ch/Ccolors/Cres/vplanes/vbits/Vsize/Voffset', substr($raw, $o, 16));
            $ew = (int) $e['w'] === 0 ? 256 : (int) $e['w'];
            $eh = (int) $e['h'] === 0 ? 256 : (int) $e['h'];

            if ((int) $e['size'] < 8 || (int) $e['offset'] + (int) $e['size'] > strlen($raw)) {
                continue;
            }

            if ($ew * $eh > $bestArea) {
                $bestArea = $ew * $eh;
                $best = ['w' => $ew, 'h' => $eh, 'bits' => (int) $e['bits'],
                         'size' => (int) $e['size'], 'offset' => (int) $e['offset']];
            }
        }

        if ($best === null) {
            return false;
        }

        $frame = substr($raw, $best['offset'], $best['size']);
        $png = @imagecreatefromstring($frame);

        if ($png) {
            return $png;
        }

        return navWordmarkDecodeIcoBmp($frame, $best['w'], $best['h'], $best['bits']);
    }
}

if (!function_exists('navWordmarkDecodeIcoBmp')) {
    /** ICO 里的 BMP 帧：像素从下往上存，颜色表 BGRX，尾部跟 1bpp 的 AND 掩码。 */
    function navWordmarkDecodeIcoBmp($data, $w, $h, $bits)
    {
        if (!in_array($bits, [32, 24, 8, 4, 1], true) || strlen($data) < 40) {
            return false;
        }

        $bi = unpack('Vsize/Vw/Vh/vplanes/vbits/Vcomp/VimgSize/Vxppm/Vyppm/VclrUsed/VclrImportant',
            substr($data, 0, 40));

        if ((int) $bi['comp'] !== 0) {
            return false;
        }

        $bottomUp = (int) $bi['h'] > 0;
        $paletteCount = (int) $bi['clrUsed'] > 0 ? (int) $bi['clrUsed']
            : ($bits <= 8 ? (1 << $bits) : 0);

        $offset = 40 + $paletteCount * 4;
        $palette = [];

        for ($i = 0; $i < $paletteCount; $i++) {
            $p = 40 + $i * 4;

            if ($p + 3 > strlen($data)) {
                break;
            }

            $palette[$i] = [ord($data[$p + 2]), ord($data[$p + 1]), ord($data[$p])];
        }

        $bytesPerRow = (int) (ceil($w * $bits / 32) * 4);
        $maskStride = (int) (ceil($w / 32) * 4);
        $maskOffset = $offset + $bytesPerRow * $h;
        $maskAvail = strlen($data) >= $maskOffset + $maskStride * $h;

        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 255, 255, 255, 127));

        for ($y = 0; $y < $h; $y++) {
            $srcRow = $bottomUp ? ($h - 1 - $y) : $y;
            $row = $offset + $srcRow * $bytesPerRow;

            if ($row + $bytesPerRow > strlen($data)) {
                break;
            }

            for ($x = 0; $x < $w; $x++) {
                $a = 255;

                if ($bits === 32) {
                    $p = $row + $x * 4;
                    $b = ord($data[$p]);
                    $g = ord($data[$p + 1]);
                    $r = ord($data[$p + 2]);
                    $a = ord($data[$p + 3]);
                } elseif ($bits === 24) {
                    $p = $row + $x * 3;
                    $b = ord($data[$p]);
                    $g = ord($data[$p + 1]);
                    $r = ord($data[$p + 2]);
                } elseif ($bits === 8) {
                    [$r, $g, $b] = $palette[ord($data[$row + $x])] ?? [0, 0, 0];
                } elseif ($bits === 4) {
                    $byte = ord($data[$row + (int) ($x / 2)]);
                    [$r, $g, $b] = $palette[($x % 2 === 0) ? ($byte >> 4) : ($byte & 0x0F)] ?? [0, 0, 0];
                } else {
                    $byte = ord($data[$row + (int) ($x / 8)]);
                    [$r, $g, $b] = $palette[($byte >> (7 - ($x % 8))) & 1] ?? [0, 0, 0];
                }

                /* AND 掩码为 1 表示全透明：32bpp 的 alpha 常被厂商填 0，靠掩码兜底 */
                if ($maskAvail) {
                    $mByte = ord($data[$maskOffset + $srcRow * $maskStride + (int) ($x / 8)]);

                    if ((($mByte >> (7 - ($x % 8))) & 1) === 1) {
                        $a = 0;
                    }
                }

                $alpha = 127 - (int) round($a / 255 * 127);
                imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, $alpha));
            }
        }

        return $img;
    }
}

if (!function_exists('navWordmarkAnalyze')) {
    /** 只做像素判定：给一段图片字节，返回是否「字标型」。 */
    function navWordmarkAnalyze($raw)
    {
        if (!is_string($raw) || $raw === '' || !function_exists('imagecreatetruecolor')) {
            return false;
        }

        $is = false;

        try {
            $img = navWordmarkDecodeImage($raw);

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
                        /* 半透明像素按白底合成：透明底不会变成「深墨」 */
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

                /* 是否「多行并排的窄笔画」：小字每行至少 2 段且段宽很窄；
                   单笔图形标（星芒、单字母、插画）每行 1 段或段宽很粗。 */
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
                    $narrowAvg = $narrowRuns > 0 ? $narrowWidth / $narrowRuns : 99;
                    $lineFlip = $flips / $size;

                    $is = $lineFlip >= 5.0
                        && $narrowAvg <= 3.0
                        && ($rowsWithTwo / $size) >= 0.75
                        && $inkPixels >= 60
                        && $inkPixels <= 620;
                }
            }
        } catch (Throwable $ignored) {
            $is = false;
        }

        return $is;
    }
}

if (!function_exists('navIsWordmarkIcon')) {
    function navIsWordmarkIcon($url)
    {
        static $cache = [];

        $url = (string) $url;

        if ($url === '' || !function_exists('imagecreatetruecolor')) {
            return false;
        }

        $pathPart = (string) parse_url($url, PHP_URL_PATH);
        $pos = strpos($pathPart, 'uploads/icons/');

        if ($pos === false) {
            return false;
        }

        $abs = __DIR__ . '/../' . ltrim(substr($pathPart, $pos), '/');

        if (!is_file($abs)) {
            return false;
        }

        $stamp = (string) @filesize($abs) . '-' . (string) @filemtime($abs);

        if (isset($cache[$abs]) && $cache[$abs]['stamp'] === $stamp) {
            return $cache[$abs]['is'];
        }

        $raw = @file_get_contents($abs, false, null, 0, 2 * 1024 * 1024);
        $is = navWordmarkAnalyze($raw);
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

        /* v2.19.2 · 图标兜底链（顺序即优先级，见 nav.js 文件头硬规则）：
           ① 本地缓存图标（后台「批量补齐本地图标」产出的 uploads/icons/…，命中则只留这一条）；
           ② 原站 https；③ 原站 http（仅当站点本身是 http）；
           ④ 第三方兜底：域名写在配置 NAV_ICON_PROXY 里，置空即表示关闭。
           v2.19.1 曾把全部图标收敛到 api.iowen.cn 单点，证书过期致全站变字母块（P0 回归），
           故第三方降级为最后一道可关闭兜底，绝不再当唯一来源。 */
        $isLocalLogo = $rawLogo !== ''
            && stripos(ltrim($rawLogo, '/'), IconCacheService::DIRECTORY . '/') === 0;

        if ($isLocalLogo) {
            $chain = [$logoUrl];
        } else {
            if ($logoUrl !== '') { $chain[] = $logoUrl; }
            // ② 原站 https，③ 原站 http（仅当站点本身是 http）
            $chain[] = 'https://' . $host . '/favicon.ico';
            if ($scheme === 'http') { $chain[] = 'http://' . $host . '/favicon.ico'; }
        }

        // ④ 第三方兜底：域名写在配置里，置空即表示关闭
        $iconProxy = defined('NAV_ICON_PROXY') ? NAV_ICON_PROXY : '';
        if (!$isLocalLogo && $iconProxy !== '') {
            $chain[] = sprintf($iconProxy, $host);
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
        $row['icon_is_wordmark'] = navIsWordmarkIcon($chain);

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
