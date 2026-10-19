<?php
/**
 * 轩宇-导航 · 网站详情
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once 'includes/functions.php';

$functions = new Functions();
$functions->recordVisit();

$siteTitle = $functions->getSetting('site_title');
$siteDescription = $functions->getSetting('site_description');
$siteLogo = $functions->getSetting('site_logo');
$siteLogoUrl = $functions->getLogoUrl($siteLogo);

$showICP = $functions->getSetting('show_icp');
$icpNumber = $functions->getSetting('icp_number');
$showGongan = $functions->getSetting('show_gongan');
$gonganNumber = $functions->getSetting('gongan_number');

$db = Database::getInstance()->getConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit;
}

$stmt = $db->prepare("
    SELECT n.*, c.name as category_name 
    FROM nav_links n 
    LEFT JOIN categories c ON n.category_id = c.id 
    WHERE n.id = ? AND n.is_active = 1
");
$stmt->execute([$id]);
$link = $stmt->fetch();

if (!$link) {
    header('Location: index.php');
    exit;
}

$updateStmt = $db->prepare("UPDATE nav_links SET clicks = COALESCE(clicks, 0) + 1 WHERE id = ?");
$updateStmt->execute([$id]);
$link['clicks'] = ($link['clicks'] ?? 0) + 1;

$relatedStmt = $db->prepare("
    SELECT * FROM nav_links 
    WHERE category_id = ? AND id != ? AND is_active = 1 
    ORDER BY COALESCE(clicks, 0) DESC 
    LIMIT 4
");
$relatedStmt->execute([$link['category_id'], $id]);
$relatedLinks = $relatedStmt->fetchAll();

$urlParts = parse_url($link['url']);
$domain = isset($urlParts['host']) ? $urlParts['host'] : $link['url'];

$pageTitle = $link['name'] . ' - ' . $siteTitle;
$pageDescription = $link['description'] ?: $link['name'] . ' - 优质网站导航推荐';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?php echo $functions->escape($pageTitle); ?></title>
    <meta name="description" content="<?php echo $functions->escape($pageDescription); ?>">
    <meta name="keywords" content="<?php echo $functions->escape($link['name']); ?>,网站导航,网址推荐">
    <meta name="author" content="<?php echo $functions->escape($siteTitle); ?>">
    <meta name="robots" content="index, follow">
    
    <script src="assets/js/tailwind.min.js"></script>
    <link href="assets/css/font-awesome.min.css" rel="stylesheet">
    
    <script src="assets/js/jquery3.7.1.js"></script>
    <?php require_once __DIR__ . '/includes/asset_fallbacks.php'; ?>
    
    <script src="assets/js/qrcode.min.js"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            height: 100%;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            display: flex;
            flex-direction: column;
            background: white;
        }
        
        .wrapper {
            flex: 1;
        }
        
        .breadcrumb-nav {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            padding: 16px 0;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .breadcrumb a {
            color: white;
            transition: all 0.3s ease;
        }
        
        .breadcrumb a:hover {
            color: #fbbf24;
        }
        
        .logo-img {
            max-height: 48px;
            width: auto;
            object-fit: contain;
            transition: transform 0.3s ease;
        }
        
        .logo-img:hover {
            transform: scale(1.05);
        }
        
        .site-logo-large {
            width: 100px;
            height: 100px;
            object-fit: contain;
            border-radius: 20px;
            background: white;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .site-logo-large:hover {
            transform: scale(1.05);
        }
        
        .default-logo-large {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .detail-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .visit-btn {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            transition: all 0.3s ease;
        }
        
        .visit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.4);
        }
        
        .action-btn {
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .related-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }
        
        .related-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.12), 0 8px 16px rgba(0,0,0,0.08);
        }
        
        .related-logo {
            width: 48px;
            height: 48px;
            object-fit: contain;
            border-radius: 12px;
            transition: transform 0.3s ease;
        }
        
        .related-logo:hover {
            transform: scale(1.1);
        }
        
        .default-related-logo {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .tag {
            display: inline-block;
            padding: 6px 14px;
            background: #f3f4f6;
            border-radius: 30px;
            font-size: 14px;
            color: #4b5563;
            transition: all 0.3s ease;
        }
        
        .tag:hover {
            background: #e5e7eb;
        }
        
        .qrcode-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .qrcode-content {
            background: white;
            padding: 30px;
            border-radius: 20px;
            text-align: center;
            max-width: 320px;
            animation: slideUp 0.3s ease;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .toast-message {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            z-index: 9999;
            display: none;
            animation: fadeInOut 2s ease;
        }
        
        @keyframes fadeInOut {
            0%, 100% { opacity: 0; }
            10%, 90% { opacity: 1; }
        }
        
        footer a {
            transition: color 0.3s ease;
        }
        
        footer a:hover {
            color: #93c5fd !important;
        }
        
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #93c5fd;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #3b82f6;
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @media (max-width: 768px) {
            .site-logo-large,
            .default-logo-large {
                width: 80px;
                height: 80px;
                font-size: 32px;
            }
            
            .detail-card {
                border-radius: 16px;
            }
        }
    </style>
</head>
<body class="bg-white">
    <div id="toast" class="toast-message"></div>
    
    <div class="wrapper">
        <div class="breadcrumb-nav">
            <div class="container mx-auto px-4">
                <div class="flex items-center justify-between">
                    <a href="index.php" class="flex items-center space-x-3">
                        <?php if ($siteLogoUrl): ?>
                            <img src="<?php echo $functions->escape($siteLogoUrl); ?>" 
                                 alt="<?php echo $functions->escape($siteTitle); ?>" 
                                 class="logo-img"
                                 style="max-height: 40px; width: auto;">
                        <?php endif; ?>
                        <span class="text-white text-xl font-bold">
                            <?php echo $functions->escape($siteTitle); ?>
                        </span>
                    </a>
                    
                    <a href="index.php" class="text-white hover:text-yellow-200 transition">
                        <i class="fas fa-home mr-2"></i>返回首页
                    </a>
                </div>
                
                <div class="breadcrumb mt-3">
                    <a href="index.php"><i class="fas fa-home"></i> 首页</a>
                    <i class="fas fa-chevron-right mx-2 text-xs"></i>
                    <a href="index.php">
                        <?php echo $functions->escape($link['category_name'] ?: '全部分类'); ?>
                    </a>
                    <i class="fas fa-chevron-right mx-2 text-xs"></i>
                    <span class="text-white"><?php echo $functions->escape($link['name']); ?></span>
                </div>
            </div>
        </div>

        <main class="container mx-auto px-4 py-8">
            <div class="detail-card fade-in">
                <div class="p-6 md:p-8">
                    <div class="flex flex-col md:flex-row items-start gap-6">
                        <div class="flex-shrink-0">
                            <?php 
                                $logoUrl = $functions->getLogoDisplayUrl($link['logo']);
                                if (!empty($logoUrl)): 
                            ?>
                                <img src="<?php echo $functions->escape($logoUrl); ?>" 
                                     alt="<?php echo $functions->escape($link['name']); ?>"
                                     class="site-logo-large"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <?php endif; ?>
                            
                            <?php if (empty($logoUrl)): ?>
                                <div class="default-logo-large" style="<?php echo !empty($logoUrl) ? 'display:none;' : ''; ?>">
                                    <i class="fas fa-globe"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex-1">
                            <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                                <div>
                                    <h1 class="text-2xl md:text-3xl font-bold text-gray-800 mb-2">
                                        <?php echo $functions->escape($link['name']); ?>
                                    </h1>
                                    <div class="flex flex-wrap items-center gap-3 text-gray-500 text-sm">
                                        <span class="flex items-center">
                                            <i class="fas fa-link mr-1"></i>
                                            <?php echo $functions->escape($domain); ?>
                                        </span>
                                        <span class="flex items-center">
                                            <i class="fas fa-eye mr-1"></i>
                                            <?php echo number_format($link['clicks'] ?? 0); ?> 次浏览
                                        </span>
                                        <span class="flex items-center">
                                            <i class="fas fa-calendar mr-1"></i>
                                            <?php echo date('Y-m-d', strtotime($link['created_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($link['description'])): ?>
                            <div class="bg-gray-50 rounded-xl p-5 mb-5">
                                <p class="text-gray-700 leading-relaxed">
                                    <?php echo nl2br($functions->escape($link['description'])); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="flex flex-wrap gap-2 mb-6">
                                <?php if ($link['category_name']): ?>
                                <span class="tag">
                                    <i class="fas fa-folder mr-1 text-blue-600"></i>
                                    <?php echo $functions->escape($link['category_name']); ?>
                                </span>
                                <?php endif; ?>
                                <?php 
                                    $scheme = parse_url($link['url'], PHP_URL_SCHEME);
                                    if ($scheme): 
                                ?>
                                <span class="tag">
                                    <i class="fas fa-shield-alt mr-1 text-green-600"></i>
                                    <?php echo strtoupper($scheme); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex flex-wrap gap-3">
                                <a href="<?php echo $functions->escape($link['url']); ?>" 
                                   target="_blank" 
                                   rel="noopener noreferrer"
                                   class="visit-btn inline-flex items-center px-8 py-3 text-white rounded-full text-base font-medium">
                                    <i class="fas fa-external-link-alt mr-2"></i>
                                    访问网站
                                </a>
                                
                                <button onclick="shareWebsite()" 
                                        class="action-btn inline-flex items-center px-5 py-3 bg-gray-100 text-gray-700 rounded-full hover:bg-gray-200">
                                    <i class="fas fa-share-alt mr-2"></i>
                                    分享
                                </button>
                                
                                <button onclick="showQRCode()" 
                                        class="action-btn inline-flex items-center px-5 py-3 bg-gray-100 text-gray-700 rounded-full hover:bg-gray-200">
                                    <i class="fas fa-qrcode mr-2"></i>
                                    手机访问
                                </button>
                                
                                <button onclick="copyUrl()" 
                                        class="action-btn inline-flex items-center px-5 py-3 bg-gray-100 text-gray-700 rounded-full hover:bg-gray-200">
                                    <i class="far fa-copy mr-2"></i>
                                    复制链接
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="border-t border-gray-100">
                    <div class="p-6 md:p-8 bg-gray-50">
                        <div class="flex items-start space-x-3">
                            <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-globe text-blue-600"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm text-gray-500 mb-1">网站地址</p>
                                <a href="<?php echo $functions->escape($link['url']); ?>" 
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="text-blue-600 hover:text-blue-700 break-all font-medium">
                                    <?php echo $functions->escape($link['url']); ?>
                                    <i class="fas fa-external-link-alt ml-2 text-xs"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($relatedLinks)): ?>
            <div class="mt-12 fade-in" style="animation-delay: 0.2s;">
                <div class="flex items-center mb-6 pb-2 border-b-2 border-blue-100">
                    <div class="w-1 h-8 bg-blue-600 rounded-full mr-3"></div>
                    <h2 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-thumbs-up mr-2 text-blue-600"></i>
                        相关推荐
                    </h2>
                    <span class="ml-3 text-sm text-gray-500">(<?php echo count($relatedLinks); ?>个网站)</span>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <?php foreach ($relatedLinks as $related): ?>
                        <div onclick="window.location.href='detail.php?id=<?php echo $related['id']; ?>'"
                             class="related-card bg-white rounded-xl shadow-md overflow-hidden group">
                            <div class="p-5">
                                <div class="flex items-start space-x-4">
                                    <?php 
                                        $relLogo = $functions->getLogoDisplayUrl($related['logo']);
                                        if (!empty($relLogo)): 
                                    ?>
                                        <img src="<?php echo $functions->escape($relLogo); ?>" 
                                             alt="<?php echo $functions->escape($related['name']); ?>"
                                             class="related-logo flex-shrink-0"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <?php endif; ?>
                                    
                                    <?php if (empty($relLogo)): ?>
                                        <div class="default-related-logo flex-shrink-0">
                                            <i class="fas fa-globe"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-bold text-gray-800 mb-1 group-hover:text-blue-600 transition-colors truncate">
                                            <?php echo $functions->escape($related['name']); ?>
                                        </h3>
                                        <p class="text-gray-500 text-sm line-clamp-2 mb-2">
                                            <?php echo $functions->escape($related['description'] ?: '暂无描述'); ?>
                                        </p>
                                        <div class="flex items-center justify-between">
                                            <span class="text-gray-400 text-xs">
                                                <i class="fas fa-eye mr-1"></i>
                                                <?php echo number_format($related['clicks'] ?? 0); ?>
                                            </span>
                                            <span class="opacity-0 group-hover:opacity-100 transition-opacity">
                                                <i class="fas fa-arrow-right text-blue-600 text-xs"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <footer class="bg-gray-900 text-white py-6 mt-8">
        <div class="container mx-auto px-4 text-center">
            
            <?php if ($showICP == '1' && $icpNumber): ?>
                <p class="mb-2">
                    <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-white transition">
                        <i class="fas fa-certificate mr-1"></i> <?php echo $functions->escape($icpNumber); ?>
                    </a>
                </p>
            <?php endif; ?>
            
            <?php if ($showGongan == '1' && $gonganNumber): ?>
                <p class="mb-2">
                    <a href="http://www.beian.gov.cn/" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-white transition">
                        <i class="fas fa-shield-alt mr-1"></i> <?php echo $functions->escape($gonganNumber); ?>
                    </a>
                </p>
            <?php endif; ?>
            
            <p class="text-gray-500 text-sm">
                &copy; <?php echo date('Y'); ?> <?php echo $functions->escape($siteTitle); ?>.
            </p>
        </div>
    </footer>
    
    <button id="backToTop" 
            class="fixed bottom-8 right-8 bg-blue-600 text-white w-12 h-12 rounded-full shadow-lg hover:shadow-xl transition-all duration-300 hidden z-50"
            onclick="scrollToTop()">
        <i class="fas fa-arrow-up"></i>
    </button>

    <div class="qrcode-modal" id="qrcodeModal" onclick="closeQRCode(event)">
        <div class="qrcode-content" onclick="event.stopPropagation()">
            <h3 class="text-xl font-bold text-gray-800 mb-4">手机扫码访问</h3>
            <div class="bg-gray-50 p-4 rounded-xl inline-block mb-4">
                <div id="qrcode"></div>
            </div>
            <p class="text-gray-600 text-sm mb-4">使用手机扫描二维码即可访问</p>
            <button onclick="closeQRCode()" class="px-6 py-2 bg-blue-600 text-white rounded-full hover:bg-blue-700 transition">
                关闭
            </button>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            new QRCode(document.getElementById("qrcode"), {
                text: "<?php echo $functions->escape($link['url']); ?>",
                width: 200,
                height: 200,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
            
            $(window).scroll(function() {
                if ($(this).scrollTop() > 300) {
                    $('#backToTop').fadeIn();
                } else {
                    $('#backToTop').fadeOut();
                }
            });
        });
        
        function showToast(message) {
            $('#toast').text(message).fadeIn();
            setTimeout(function() {
                $('#toast').fadeOut();
            }, 2000);
        }
        
        function shareWebsite() {
            if (navigator.share) {
                navigator.share({
                    title: '<?php echo $functions->escape($link['name']); ?>',
                    text: '<?php echo $functions->escape($link['description'] ?: $link['name']); ?>',
                    url: '<?php echo $functions->escape($link['url']); ?>',
                }).catch(() => {
                    copyUrl();
                });
            } else {
                copyUrl();
                showToast('链接已复制，可以分享给朋友啦！');
            }
        }
        
        function copyUrl() {
            const url = '<?php echo $functions->escape($link['url']); ?>';
            navigator.clipboard?.writeText(url).then(() => {
                showToast('链接已复制到剪贴板');
            }).catch(() => {
                const textarea = document.createElement('textarea');
                textarea.value = url;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                showToast('链接已复制到剪贴板');
            });
        }
        
        function showQRCode() {
            $('#qrcodeModal').css('display', 'flex');
        }
        
        function closeQRCode(event) {
            if (!event || event.target === event.currentTarget) {
                $('#qrcodeModal').fadeOut();
            }
        }
        
        function scrollToTop() {
            $('html, body').animate({ scrollTop: 0 }, 500);
        }
        
        $(document).keydown(function(e) {
            if (e.keyCode === 27) {
                $('#qrcodeModal').fadeOut();
            }
        });
        
        window.addEventListener('load', function() {
            console.log('详情页加载完成');
            $('.detail-card').addClass('fade-in');
        });
    </script>
</body>
</html>