<?php
/**
 * 轩宇-导航 · 公共函数库
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ConfigManager.php';

class Functions {
    private $db;
    private $config;
    
    public function __construct() {
        $database = Database::getInstance();
        $this->db = $database->getConnection();
        $this->config = ConfigManager::getInstance();
    }
    
    // ==================== 配置相关方法（代理） ====================
    
    public function getConfig($key = null, $default = null) {
        return $this->config->get($key, $default);
    }
    
    public function updateConfig($key, $value) {
        return $this->config->set($key, $value);
    }
    
    public function getSystemConfig() {
        return $this->config->getSystemConfig();
    }
    
    public function getSecurityConfig() {
        return $this->config->getSecurityConfig();
    }
    
    // ==================== 数据库设置方法 ====================
    
    public function getSetting($key) {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : '';
    }
    
    public function updateSetting($key, $value) {
        $stmt = $this->db->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?)
                                   ON DUPLICATE KEY UPDATE value = ?");
        return $stmt->execute([$key, $value, $value]);
    }

    /**
     * 生成页脚版权文字（v2.3.0 新增）。
     *
     * 优先级：用户自定义（$userCopyright）> 后台全局设置 footer_copyright > 默认「© 年份 站点名」。
     * 自定义与全局设置均支持 {year}（当前年份）与 {site}（站点名称）占位符；
     * 不含占位符时按原样输出（不再自动补 © 前缀）。
     *
     * @param string $userCopyright 当前视图用户的自定义版权（无则传空串）
     * @param string $brandName     站点名称
     * @return string 已替换占位符后的纯文本（调用方负责 escape）
     */
    public function footerCopyrightText($userCopyright, $brandName) {
        $template = trim((string) $userCopyright);

        if ($template === '') {
            $template = trim((string) $this->getSetting('footer_copyright'));
        }

        if ($template === '') {
            $template = '© {year} {site}';
        }

        /* 兼容早期保存的紧凑模板「{year}{site}」：它会渲染成「2026轩宇-导航」，
           年份和站名黏在一起，容易被误认为代码里写死了版权。
           这里统一补上版权符号与空格，用户仍可在账号设置里完全自定义。 */
        if (preg_replace('/\s+/', '', $template) === '{year}{site}') {
            $template = '© {year} {site}';
        }

        return str_replace(
            ['{year}', '{site}'],
            [date('Y'), trim((string) $brandName)],
            $template
        );
    }
    
    // ==================== 访问统计方法 ====================
    
    public function recordVisit() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $visitDate = date('Y-m-d');
        
        $checkStmt = $this->db->prepare(
            "SELECT id FROM visitor_stats WHERE ip_address = ? AND visit_date = ? LIMIT 1"
        );
        $checkStmt->execute([$ip, $visitDate]);
        
        if (!$checkStmt->fetch()) {
            $stmt = $this->db->prepare(
                "INSERT INTO visitor_stats (ip_address, user_agent, visit_date) VALUES (?, ?, ?)"
            );
            return $stmt->execute([$ip, $userAgent, $visitDate]);
        }
        return true;
    }
    
    public function getTodayVisits() {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM visitor_stats WHERE visit_date = ?"
        );
        $stmt->execute([$today]);
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }
    
    public function getTodayVisitors() {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT ip_address) as count FROM visitor_stats WHERE visit_date = ?"
        );
        $stmt->execute([$today]);
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }
    
    // ==================== 管理员管理方法 ====================
    
    /**
     * 旧库的 password 列为 varchar(50)，不足以存放 password_hash 结果。
     * 首次迁移时自动扩宽；失败则回退为原明文逻辑并记录日志。
     */
    private function ensureAdminPasswordColumn() {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM admins LIKE 'password'");
            $column = $stmt ? $stmt->fetch() : null;
            $type = strtolower((string) ($column['Type'] ?? ''));
            
            if ($type !== '' && strpos($type, 'varchar(255)') === false) {
                $this->db->exec("ALTER TABLE admins MODIFY password VARCHAR(255) NOT NULL");
            }
        } catch (Throwable $error) {
            $this->logMessage('密码列升级失败: ' . $error->getMessage(), 'ERROR');
        }
    }
    
    public function verifyAdmin($username, $password) {
        $stmt = $this->db->prepare("SELECT id, username, password FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            return false;
        }
        
        $stored = (string) $admin['password'];
        $info = password_get_info($stored);
        
        if (($info['algo'] ?? 0) !== 0 && password_verify($password, $stored)) {
            return $admin;
        }
        
        // 兼容旧版明文或 32 位 md5 密码：登录成功后立即迁移为 password_hash。
        if (($info['algo'] ?? 0) === 0) {
            $legacyOk = hash_equals($stored, (string) $password)
                || (strlen($stored) === 32 && hash_equals(strtolower($stored), md5((string) $password)));
            
            if (!$legacyOk) {
                return false;
            }
            
            $this->ensureAdminPasswordColumn();
            $hash = password_hash((string) $password, PASSWORD_DEFAULT);
            
            if (is_string($hash) && $hash !== '') {
                try {
                    $update = $this->db->prepare("UPDATE admins SET password = ? WHERE id = ?");
                    $update->execute([$hash, $admin['id']]);
                } catch (Throwable $error) {
                    $this->logMessage('管理员密码迁移失败: ' . $error->getMessage(), 'ERROR');
                }
            }
            
            return $admin;
        }
        
        return false;
    }
    
    public function updateAdminPassword($adminId, $newPassword) {
        $this->ensureAdminPasswordColumn();
        $hash = password_hash((string) $newPassword, PASSWORD_DEFAULT);
        
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        
        $stmt = $this->db->prepare("UPDATE admins SET password = ? WHERE id = ?");
        return $stmt->execute([$hash, $adminId]);
    }
    
    public function checkUsernameExists($username, $excludeId = null) {
        if ($excludeId) {
            $stmt = $this->db->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
            $stmt->execute([$username, $excludeId]);
        } else {
            $stmt = $this->db->prepare("SELECT id FROM admins WHERE username = ?");
            $stmt->execute([$username]);
        }
        return $stmt->fetch() ? true : false;
    }
    
    public function createAdmin($username, $password) {
        $this->ensureAdminPasswordColumn();
        $hash = password_hash((string) $password, PASSWORD_DEFAULT);
        
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        
        $stmt = $this->db->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
        return $stmt->execute([$username, $hash]);
    }
    
    public function getAllAdmins() {
        $stmt = $this->db->prepare("SELECT * FROM admins ORDER BY id");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getAdminInfo($adminId) {
        $stmt = $this->db->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        return $stmt->fetch();
    }
    
    public function updateAdminInfo($adminId, $username, $password = null) {
        if ($password) {
            $this->ensureAdminPasswordColumn();
            $hash = password_hash((string) $password, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE admins SET username = ?, password = ? WHERE id = ?");
            return $stmt->execute([$username, $hash, $adminId]);
        } else {
            $stmt = $this->db->prepare("UPDATE admins SET username = ? WHERE id = ?");
            return $stmt->execute([$username, $adminId]);
        }
    }
    
    public function deleteAdmin($adminId) {
        $stmt = $this->db->prepare("DELETE FROM admins WHERE id = ?");
        return $stmt->execute([$adminId]);
    }
    
    // ==================== 认证和权限方法 ====================
    
    public function isLoggedIn() {
        if (isset($_SESSION['admin_id'])) {
            $lifetime = (int) $this->getConfig('security.session_lifetime', 7200);

            if (isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $lifetime) {
                $_SESSION = [];
                return false;
            }

            $_SESSION['last_activity'] = time();
            return true;
        }

        // v2.16.0 · 管理员登录态打通：
        // 前台用同一个管理员账号登录后（users.is_admin = 1），直接进后台无需二次登录。
        // 安全边界：
        //   1) 只认数据库里 is_admin = 1 的账号，不看会话里的 user_is_admin 标记，
        //      避免会话被篡改后提权；
        //   2) 账号被禁用（status = banned）时一律不认；
        //   3) 同时补齐 admin_id / admin_username，让后续后台页面按正常登录态工作。
        if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0) {
            $lifetime = (int) $this->getConfig('security.session_lifetime', 7200);

            if (isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $lifetime) {
                // 前台会话过期时只清前台身份，保留其它会话数据
                unset($_SESSION['user_id'], $_SESSION['user_username'], $_SESSION['user_is_admin']);
                return false;
            }

            $adminUser = $this->findAdminUserById((int) $_SESSION['user_id']);

            if ($adminUser !== null) {
                $_SESSION['admin_id'] = (int) $adminUser['id'];
                $_SESSION['admin_username'] = (string) $adminUser['username'];
                $_SESSION['last_activity'] = time();

                return true;
            }
        }

        return false;
    }

    /**
     * v2.16.0 · 按 id 取「可登录后台」的前台用户（is_admin = 1 且未被禁用）。
     *
     * @return array|null
     */
    private function findAdminUserById($id) {
        $id = (int) $id;

        if ($id <= 0) {
            return null;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, username, is_admin, status FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            // 老库没有 users 表时不打通，保持原有「必须后台登录」的行为
            return null;
        }

        if (!$row) {
            return null;
        }

        if ((int) ($row['is_admin'] ?? 0) !== 1) {
            return null;
        }

        if (isset($row['status']) && (string) $row['status'] === 'banned') {
            return null;
        }

        return $row;
    }
    
    /**
     * 同源校验：配合 SameSite=Lax 降低 CSRF 风险。
     * 老浏览器没有 Sec-Fetch-Site 时，退回 Origin 主机校验。
     */
    public function isSameOriginRequest() {
        $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
        
        // 现代浏览器会发送 Sec-Fetch-Site；同源/同站直接放行，避免反向代理改写 Host 导致误判。
        if ($fetchSite === 'cross-site') {
            return false;
        }
        
        if (in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
            return true;
        }
        
        // 老浏览器没有 Sec-Fetch-Site 时，退回 Origin/Referer 主机校验。
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        
        if ($origin !== '') {
            $originHost = parse_url($origin, PHP_URL_HOST);
            $hosts = [
                preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')),
                (string) ($_SERVER['SERVER_NAME'] ?? ''),
                (string) (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0] ?? ''),
            ];
            $hosts = array_values(array_filter(array_map('strtolower', $hosts)));
            
            if (!$originHost || !in_array(strtolower((string) $originHost), $hosts, true)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function requireSameOrigin() {
        if (!$this->isSameOriginRequest()) {
            http_response_code(403);
            exit('非法请求');
        }
    }

    /**
     * 统一解析 API 请求体（v2.13.0）。
     *
     * PHP 只会把 application/x-www-form-urlencoded / multipart 填进 $_POST，
     * 声明 application/json 时 $_POST 是空的。此前 user_sites.php、contact.php、
     * sites.php 各写了一份兼容代码，其余接口则完全不支持 JSON，行为不一致。
     *
     * 这里直接把 JSON 键合并进 $_POST，调用方无需改动任何业务代码：
     *   true  = 解析成功或不是 JSON 请求
     *   false = 声明为 JSON 但内容非法，调用方应返回 400 invalid_json
     */
    public function mergeJsonBody() {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

        if (strpos($contentType, 'application/json') === false) {
            return true;
        }

        $raw = @file_get_contents('php://input');

        if (!is_string($raw) || trim($raw) === '') {
            return true;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return false;
        }

        // 只接受 JSON 对象；把 [1,2,3] 合并进 $_POST 没有意义，还会让错误原因变得难查
        $isList = $decoded === [] ? false : (
            function_exists('array_is_list')
                ? array_is_list($decoded)
                : array_keys($decoded) === range(0, count($decoded) - 1)
        );

        if ($isList) {
            return false;
        }

        foreach ($decoded as $key => $value) {
            // 表单里已有同名参数时以表单为准，避免 JSON 覆盖 URL 上的 action
            if (!array_key_exists($key, $_POST)) {
                $_POST[$key] = $value;
            }
        }

        return true;
    }
    
    public function redirect($url) {
        header('Location: ' . $url);
        exit;
    }
    
    public function escape($string) {
        return htmlspecialchars((string) ($string ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    
    /**
     * 按字符截断文本。部分主机未启用 mbstring，这里统一兜底，
     * 保证「取首字母」这类场景在两种环境下都能正常工作。
     */
    public function textCut($text, $limit) {
        $text = (string) ($text ?? '');
        $limit = (int) $limit;
        
        if ($limit <= 0) {
            return '';
        }
        
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit, 'UTF-8');
        }
        
        // 无 mbstring 时按字节近似（UTF-8 中文约 3 字节/字）。
        return substr($text, 0, $limit * 3);
    }
    
    // ==================== 统计和报表方法 ====================
    
    public function getCategoryStats() {
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, COUNT(nl.id) as link_count
            FROM categories c
            LEFT JOIN nav_links nl ON c.id = nl.category_id AND nl.is_active = 1
            GROUP BY c.id, c.name, c.sort_order
            ORDER BY c.sort_order, c.id
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getSystemStats() {
        $stats = [];
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM categories");
        $stmt->execute();
        $stats['total_categories'] = $stmt->fetch()['count'];
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM nav_links");
        $stmt->execute();
        $stats['total_links'] = $stmt->fetch()['count'];
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM nav_links WHERE is_active = 1");
        $stmt->execute();
        $stats['active_links'] = $stmt->fetch()['count'];
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'");
        $stmt->execute();
        $stats['pending_applications'] = $stmt->fetch()['count'];
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM applications WHERE status = 'approved'");
        $stmt->execute();
        $stats['approved_applications'] = $stmt->fetch()['count'];
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM applications");
        $stmt->execute();
        $stats['total_applications'] = $stmt->fetch()['count'];
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM visitor_stats");
        $stmt->execute();
        $stats['total_visits'] = $stmt->fetch()['count'];
        
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT ip_address) as count FROM visitor_stats");
        $stmt->execute();
        $stats['unique_visitors'] = $stmt->fetch()['count'];
        
        return $stats;
    }
    
    public function getLatestApplications($limit = 5) {
        $stmt = $this->db->prepare("
            SELECT a.*, c.name as category_name 
            FROM applications a
            LEFT JOIN categories c ON a.category_id = c.id
            ORDER BY a.created_at DESC 
            LIMIT ?
        ");
        $stmt->bindValue(1, max(1, (int) $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getPopularCategories($limit = 5) {
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, COUNT(nl.id) as link_count
            FROM categories c
            LEFT JOIN nav_links nl ON c.id = nl.category_id AND nl.is_active = 1
            GROUP BY c.id, c.name, c.sort_order
            ORDER BY link_count DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, max(1, (int) $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    // ==================== 搜索方法 ====================
    
    public function searchNavLinks($keyword, $categoryId = null, $limit = 20) {
        $sql = "SELECT nl.*, c.name as category_name 
                FROM nav_links nl
                LEFT JOIN categories c ON nl.category_id = c.id
                WHERE (nl.name LIKE ? OR nl.description LIKE ? OR nl.url LIKE ?)";
        
        $params = ["%$keyword%", "%$keyword%", "%$keyword%"];
        
        if ($categoryId) {
            $sql .= " AND nl.category_id = ?";
            $params[] = (int) $categoryId;
        }
        
        $sql .= " ORDER BY nl.sort_order, nl.id LIMIT ?";
        $params[] = max(1, (int) $limit);
        
        $stmt = $this->db->prepare($sql);
        
        foreach ($params as $index => $param) {
            $stmt->bindValue(
                $index + 1,
                $param,
                is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    // ==================== 文件上传方法 ====================
    
    public function uploadLogo($file) {
        $maxSize = (int) $this->getConfig('security.max_upload_size', 5 * 1024 * 1024);
        $allowedMime = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
        ];
        
        if (!isset($file['error'], $file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => '上传失败'];
        }
        
        if ((int) ($file['size'] ?? 0) > $maxSize) {
            return ['success' => false, 'error' => '文件大小超过限制'];
        }
        
        $tmpPath = $file['tmp_name'];
        if (!is_uploaded_file($tmpPath)) {
            return ['success' => false, 'error' => '非法上传'];
        }
        
        $mime = '';
        
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($tmpPath);
        }
        
        if ($mime === '') {
            $imageInfo = @getimagesize($tmpPath);
            if (is_array($imageInfo) && !empty($imageInfo['mime'])) {
                $mime = (string) $imageInfo['mime'];
            }
        }
        
        if (!isset($allowedMime[$mime])) {
            return ['success' => false, 'error' => '仅支持 JPG、PNG、GIF、WEBP、ICO 图片'];
        }
        
        $fileExt = $allowedMime[$mime];
        
        if ($fileExt !== 'ico' && @getimagesize($tmpPath) === false) {
            return ['success' => false, 'error' => '图片文件无效'];
        }
        
        $uploadDir = __DIR__ . '/../uploads/logos/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return ['success' => false, 'error' => '上传目录不可写'];
        }
        
        try {
            $safeFileName = bin2hex(random_bytes(8)) . '.' . $fileExt;
        } catch (Throwable $error) {
            $safeFileName = uniqid('', true) . '.' . $fileExt;
        }
        
        $filePath = $uploadDir . $safeFileName;
        
        if (move_uploaded_file($tmpPath, $filePath)) {
            if (function_exists('chmod')) {
                @chmod($filePath, 0644);
            }
            return [
                'success' => true,
                'filePath' => $filePath,
                'relativePath' => 'uploads/logos/' . $safeFileName,
                'fileName' => $safeFileName
            ];
        }
        
        return ['success' => false, 'error' => '文件保存失败'];
    }
    
    public function sanitizeLogoPath($logoPath) {
        $logoPath = trim((string) ($logoPath ?? ''));
        
        if ($logoPath === '') {
            return '';
        }
        
        if (preg_match('#^https?://#i', $logoPath)) {
            return $logoPath;
        }
        
        $logoPath = ltrim(str_replace('\\', '/', $logoPath), '/');

        // v2.18.1 · 本地化图标缓存在 uploads/icons/，与上传目录同等对待
        if (strpos($logoPath, 'uploads/logos/') === 0 || strpos($logoPath, 'uploads/icons/') === 0) {
            return $logoPath;
        }

        return '';
    }
    
    public function deleteLogoFile($logoPath) {
        if (trim((string) ($logoPath ?? '')) === '') {
            return true;
        }
        
        $logoPath = $this->sanitizeLogoPath($logoPath);
        
        if ($logoPath === '' || preg_match('#^https?://#i', $logoPath)) {
            return false;
        }
        
        $uploadRoot = realpath(__DIR__ . '/../uploads');
        $filePath = realpath(__DIR__ . '/../' . $logoPath);
        
        if (
            $uploadRoot === false ||
            $filePath === false ||
            strpos($filePath, $uploadRoot . DIRECTORY_SEPARATOR) !== 0 ||
            !is_file($filePath)
        ) {
            return false;
        }
        
        return @unlink($filePath);
    }
    
    public function getLogoUrl($logoPath) {
        if (empty($logoPath)) {
            return '';
        }
        
        if (filter_var($logoPath, FILTER_VALIDATE_URL)) {
            return $logoPath;
        }
        
        if (strpos($logoPath, '/') !== 0) {
            $logoPath = '/' . $logoPath;
        }
        
        $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $protocol = $isHttps ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        $port = $_SERVER['SERVER_PORT'] ?? '';
        if ($port && !in_array((int) $port, [80, 443], true) && strpos($host, ':') === false) {
            $host .= ':' . $port;
        }
        
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = dirname($scriptName);
        
        if ($basePath === '/' || $basePath === '.' || $basePath === '\\') {
            $basePath = '';
        }
        
        // 从 admin/、api/ 等子目录访问时，Logo 仍按站点根目录解析。
        $basePath = preg_replace('#/(admin|api|install)$#', '', $basePath);
        
        return $protocol . $host . $basePath . $logoPath;
    }
    
    public function getLogoDisplayUrl($logoPath) {
        return $this->getLogoUrl($logoPath);
    }

    /**
     * 把上传的头像居中裁成正方形。
     *
     * 前端头像框是 26×26 的圆角方块，配合 object-fit: cover 使用。
     * 如果原图是 600×150 这类横幅，cover 只保留正中间一小块，
     * 主体（人脸 / 字母 / logo）很容易被裁到框外，看起来就像"头像位置不对"。
     * 这里在存盘前先按短边居中裁成正方形，任何比例的图都能正确落到框里。
     *
     * 返回 true 表示已处理，false 表示跳过（GD 不可用 / 动图 / 处理失败），
     * 调用方无需因返回值中断上传流程。
     *
     * @param string $path     已落盘的图片绝对路径（会被原地覆盖）
     * @param string $mimeType 图片 MIME
     * @param int    $maxSide  正方形边长上限
     * @return bool
     */
    public function normalizeAvatarFile($path, $mimeType, $maxSide = 640)
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            return false;
        }

        // 动图不处理，避免丢掉动画帧
        if ($mimeType === 'image/gif') {
            return false;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return false;
        }

        $image = @imagecreatefromstring($raw);
        if (!$image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 1 || $height < 1) {
            imagedestroy($image);
            return false;
        }

        // 已经是够小的正方形，不用动
        if ($width === $height && $width <= $maxSide) {
            imagedestroy($image);
            return true;
        }

        $side = min(min($width, $height), $maxSide);
        $srcX = (int) floor(($width - $side) / 2);
        $srcY = (int) floor(($height - $side) / 2);

        $canvas = imagecreatetruecolor($side, $side);
        if (!$canvas) {
            imagedestroy($image);
            return false;
        }

        // PNG / WebP 保留透明通道
        if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
            imagealphablending($canvas, true);
        }

        imagecopyresampled($canvas, $image, 0, 0, $srcX, $srcY, $side, $side, $side, $side);

        $ok = false;
        switch ($mimeType) {
            case 'image/jpeg':
                $ok = @imagejpeg($canvas, $path, 92);
                break;
            case 'image/png':
                $ok = @imagepng($canvas, $path, 6);
                break;
            case 'image/webp':
                $ok = function_exists('imagewebp') ? @imagewebp($canvas, $path, 92) : false;
                break;
        }

        imagedestroy($canvas);
        imagedestroy($image);

        return (bool) $ok;
    }
    
    // ==================== 工具方法 ====================
    
    public function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }
    
    public function validateUrl($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    public function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    public function getClientIp() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    public function formatTime($timestamp, $format = 'Y-m-d H:i:s') {
        return date($format, strtotime($timestamp));
    }
    
    public function getRelativeTime($timestamp) {
        $time = strtotime($timestamp);
        $diff = time() - $time;
        
        if ($diff < 60) return '刚刚';
        if ($diff < 3600) return floor($diff / 60) . '分钟前';
        if ($diff < 86400) return floor($diff / 3600) . '小时前';
        if ($diff < 2592000) return floor($diff / 86400) . '天前';
        return date('Y-m-d', $time);
    }
    
    public function cleanInput($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->cleanInput($value);
            }
        } else {
            $data = trim((string) ($data ?? ''));
            $data = stripslashes($data);
            $data = htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return $data;
    }
    
    // ==================== 日志方法 ====================
    
    public function logMessage($message, $type = 'INFO', $file = 'system.log') {
        $logDir = __DIR__ . '/../logs/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . $file;
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$type] $message" . PHP_EOL;
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
    // ==================== JSON响应方法 ====================
    
    public function isAjaxRequest() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    public function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    
    // ==================== CSRF保护方法 ====================
    
    public function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public function verifyCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    // ==================== 系统状态方法 ====================
    
    public function checkSystemStatus() {
        $status = [];
        
        $status['config_file'] = [
            'exists' => file_exists(__DIR__ . '/../config/config.php'),
            'writable' => is_writable(__DIR__ . '/../config/')
        ];
        
        $status['cache_dir'] = [
            'exists' => is_dir(__DIR__ . '/../cache/'),
            'writable' => is_writable(__DIR__ . '/../cache/')
        ];
        
        $status['logs_dir'] = [
            'exists' => is_dir(__DIR__ . '/../logs/'),
            'writable' => is_writable(__DIR__ . '/../logs/')
        ];
        
        $status['backups_dir'] = [
            'exists' => is_dir(__DIR__ . '/../backups/'),
            'writable' => is_writable(__DIR__ . '/../backups/')
        ];
        
        $status['php_extensions'] = [
            'curl' => extension_loaded('curl'),
            'gd' => extension_loaded('gd'),
            'json' => extension_loaded('json'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'zip' => class_exists('ZipArchive'),
        ];
        
        $status['php_version'] = [
            'version' => PHP_VERSION,
            'satisfied' => version_compare(PHP_VERSION, '8.1.0', '>=')
        ];
        
        $status['allow_url_fopen'] = ini_get('allow_url_fopen');
        $status['memory_limit'] = ini_get('memory_limit');
        $status['upload_max_filesize'] = ini_get('upload_max_filesize');
        
        try {
            $this->db->query("SELECT 1");
            $status['database'] = ['connected' => true];
        } catch (Exception $e) {
            $status['database'] = ['connected' => false, 'error' => $e->getMessage()];
        }
        
        return $status;
    }
}