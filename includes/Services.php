<?php
/**
 * 轩宇-导航 · 服务层（v2.0.0）
 *
 * 多类合一，避免为每个功能单独建文件：
 *   UserService          前台用户注册 / 登录 / 资料 / 默认访客视图
 *   SiteInfoService      站点信息抓取（标题 / 描述 / 图标），带 SSRF 防护与缓存
 *   AiService            OpenAI 兼容接口的智能分类推荐（可降级为本地关键词）
 *   CategoryEngine       分类树读取 + 关键词自动归类
 *   HealthCheckService   链接健康检测（可用性 + 延迟）
 *   BookmarkService      浏览器书签 HTML（NETSCAPE 格式）解析与导入
 *   ShareService         多选导航生成分享链接、浏览计数、一键导入
 *   BackupService        数据导出 / 导入（JSON，无需 mysqldump）
 *
 * 使用方式（调用方需先引入 bootstrap.php 与 functions.php）：
 *   $services = new UserService($functions);
 */

// 调用方通常已引入 bootstrap / functions；此处兜底，避免重复定义 Database 类。
if (!class_exists('Database')) {
    require_once __DIR__ . '/../config/database.php';
}

/**
 * 所有服务的基类：持有 PDO 与 Functions。
 */
abstract class BaseService
{
    /** @var PDO */
    protected $db;

    /** @var Functions */
    protected $functions;

    public function __construct($functions = null)
    {
        $database = Database::getInstance();
        $this->db = $database->getConnection();
        $this->functions = $functions;
    }

    /**
     * 取整型设置项。
     */
    protected function settingInt($key, $default = 0)
    {
        if (!$this->functions) {
            return (int) $default;
        }

        $value = $this->functions->getSetting($key);

        return ($value === '' || $value === null) ? (int) $default : (int) $value;
    }

    /**
     * 取字符串设置项。
     */
    protected function setting($key, $default = '')
    {
        if (!$this->functions) {
            return (string) $default;
        }

        $value = $this->functions->getSetting($key);

        return ($value === '' || $value === null) ? (string) $default : (string) $value;
    }

    /**
     * 小写化。部分主机未启用 mbstring，这里统一兜底，避免致命报错。
     */
    protected function textLower($text)
    {
        $text = (string) $text;

        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    /**
     * 按字符截断（无 mbstring 时按字节近似，中文约 3 倍长度）。
     */
    protected function textCut($text, $limit)
    {
        $text = (string) $text;
        $limit = (int) $limit;

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit, 'UTF-8');
        }

        return substr($text, 0, $limit * 3);
    }

    /**
     * 子串存在判断。
     */
    protected function textHas($haystack, $needle, $ignoreCase = false)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;

        if ($needle === '') {
            return false;
        }

        if ($ignoreCase) {
            return function_exists('mb_stripos')
                ? mb_stripos($haystack, $needle, 0, 'UTF-8') !== false
                : stripos($haystack, $needle) !== false;
        }

        return function_exists('mb_strpos')
            ? mb_strpos($haystack, $needle, 0, 'UTF-8') !== false
            : strpos($haystack, $needle) !== false;
    }
}

/* ==========================================================================
 * 1. 用户服务
 * ========================================================================== */

class UserService extends BaseService
{
    /** 用户状态：正常 */
    const STATUS_ACTIVE = 'active';

    /** 用户状态：封禁 */
    const STATUS_BANNED = 'banned';

    /** 未执行 v2 升级脚本时的统一提示 */
    const SCHEMA_HINT = '数据库尚未升级到 v2 结构，用户功能不可用，请先执行 upgrade_v2.0.0.sql';

    /** @var bool|null users 表可用性缓存（每个请求只探测一次） */
    private $usersTableReady = null;

    /**
     * users 表是否已就绪（v2 升级脚本是否执行过）。
     * 未就绪时所有用户相关功能统一降级，避免前台因缺表而 500。
     */
    protected function usersTableExists()
    {
        if ($this->usersTableReady !== null) {
            return $this->usersTableReady;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS total FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute(['users']);
            $this->usersTableReady = (int) ($stmt->fetch()['total'] ?? 0) > 0;
        } catch (Throwable $error) {
            $this->usersTableReady = false;
        }

        return $this->usersTableReady;
    }

    /**
     * 注册新用户。
     *
     * @return array{ok:bool,error?:string,user?:array}
     */
    public function register($email, $username, $password, $passwordConfirm = null)
    {
        $email = trim((string) $email);
        $username = trim((string) $username);
        $password = (string) $password;

        if (!$this->usersTableExists()) {
            return ['ok' => false, 'error' => self::SCHEMA_HINT];
        }

        if ($this->settingInt('register_enabled', 1) !== 1) {
            return ['ok' => false, 'error' => '当前站点已关闭注册'];
        }

        if (!$this->functions || !$this->functions->validateEmail($email)) {
            return ['ok' => false, 'error' => '邮箱格式不正确'];
        }

        if (strlen($email) > 190) {
            return ['ok' => false, 'error' => '邮箱过长'];
        }

        $length = function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username);

        if ($length < 2 || $length > 20) {
            return ['ok' => false, 'error' => '用户名需为 2-20 个字符'];
        }

        if (!preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_.-]+$/u', $username)) {
            return ['ok' => false, 'error' => '用户名仅支持中英文、数字、下划线、点和短横线'];
        }

        if (strlen($password) < 8 || strlen($password) > 72) {
            return ['ok' => false, 'error' => '密码长度需为 8-72 位'];
        }

        if ($passwordConfirm !== null && !hash_equals((string) $passwordConfirm, $password)) {
            return ['ok' => false, 'error' => '两次输入的密码不一致'];
        }

        $stmt = $this->db->prepare('SELECT id, email, username FROM users WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$email, $username]);
        $exists = $stmt->fetch();

        if ($exists) {
            $conflict = strcasecmp((string) $exists['email'], $email) === 0 ? '邮箱' : '用户名';

            return ['ok' => false, 'error' => $conflict . '已被注册'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        if (!is_string($hash) || $hash === '') {
            return ['ok' => false, 'error' => '密码加密失败，请联系管理员'];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO users (email, username, password_hash, status, created_at) VALUES (?, ?, ?, ?, NOW())'
        );

        try {
            $stmt->execute([$email, $username, $hash, self::STATUS_ACTIVE]);
        } catch (Throwable $error) {
            $this->log('注册失败: ' . $error->getMessage(), 'ERROR');

            return ['ok' => false, 'error' => '注册失败，请稍后重试'];
        }

        $id = (int) $this->db->lastInsertId();

        return ['ok' => true, 'user' => $this->findById($id)];
    }

    /**
     * 登录：支持邮箱或用户名。
     *
     * @return array{ok:bool,error?:string,user?:array}
     */
    public function login($account, $password)
    {
        $account = trim((string) $account);
        $password = (string) $password;

        if ($account === '' || $password === '') {
            return ['ok' => false, 'error' => '请输入账号和密码'];
        }

        if (!$this->usersTableExists()) {
            return ['ok' => false, 'error' => self::SCHEMA_HINT];
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1'
        );
        $stmt->execute([$account, $account]);
        $user = $stmt->fetch();

        // 老版本安装程序只写了 admins 表，users 表里没有对应记录。
        // 这里做一次自动补建：管理员用同一套账号密码即可在前台登录，并接管默认导航视图。
        if (!$user) {
            $user = $this->adoptAdminAccount($account, $password);
        }

        // 兜底：users 里存在同名记录但密码为空或非哈希（历史升级脚本的遗留脏数据），
        // 用 admins 的密码验证通过后回填，避免「账号明明存在却永远登录不上」。
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $adopted = $this->adoptAdminAccount($account, $password);

            if ($adopted) {
                $user = $adopted;
            }
        }

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return ['ok' => false, 'error' => '账号或密码错误'];
        }

        if (($user['status'] ?? self::STATUS_ACTIVE) === self::STATUS_BANNED) {
            return ['ok' => false, 'error' => '账号已被封禁，请联系管理员'];
        }

        $stmt = $this->db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $stmt->execute([(int) $user['id']]);

        $this->startSession($user);

        unset($user['password_hash']);

        return ['ok' => true, 'user' => $user];
    }

    /**
     * 为「只在 admins 表里存在」的管理员补建前台用户记录。
     * 仅当账号密码验证通过、且 users 表还没有该用户时才会写入。
     */
    protected function adoptAdminAccount($account, $password)
    {
        try {
            $stmt = $this->db->prepare('SELECT id, username, password FROM admins WHERE username = ? LIMIT 1');
            $stmt->execute([$account]);
            $admin = $stmt->fetch();
        } catch (Throwable $error) {
            return null;
        }

        if (!$admin) {
            return null;
        }

        // 老站 admins.password 可能是明文或 32 位 md5，不能直接 password_verify。
        $stored = (string) $admin['password'];
        $info = password_get_info($stored);
        $isHash = ($info['algo'] ?? 0) !== 0;

        if ($isHash) {
            $verified = password_verify($password, $stored);
        } else {
            $verified = hash_equals($stored, (string) $password)
                || (strlen($stored) === 32 && hash_equals(strtolower($stored), md5((string) $password)));
        }

        if (!$verified) {
            return null;
        }

        // 无论老密码是什么格式，落到 users.password_hash 的都必须是新哈希。
        $hash = $isHash && $stored !== '' ? $stored : (string) password_hash((string) $password, PASSWORD_DEFAULT);

        if ($hash === '') {
            return null;
        }

        // 同步把 admins 的密码迁移为哈希，避免每次登录都走一遍明文比对。
        if (!$isHash) {
            try {
                $column = $this->db->query("SHOW COLUMNS FROM admins LIKE 'password'")->fetch();
                $type = strtolower((string) ($column['Type'] ?? ''));

                if ($type !== '' && strpos($type, 'varchar(255)') === false) {
                    $this->db->exec('ALTER TABLE admins MODIFY password VARCHAR(255) NOT NULL');
                }

                $update = $this->db->prepare('UPDATE admins SET password = ? WHERE id = ?');
                $update->execute([$hash, (int) $admin['id']]);
            } catch (Throwable $error) {
                $this->log('管理员密码迁移失败: ' . $error->getMessage(), 'ERROR');
            }
        }

        $username = (string) $admin['username'];

        try {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
            $stmt->execute([$username, $username . '@local.invalid']);
            $user = $stmt->fetch();

            if ($user) {
                // 记录存在但密码为空/非哈希：回填新哈希并补上管理员标记
                $existing = (string) ($user['password_hash'] ?? '');

                if ($existing === '' || (password_get_info($existing)['algo'] ?? 0) === 0) {
                    $fix = $this->db->prepare('UPDATE users SET password_hash = ?, is_admin = 1 WHERE id = ?');
                    $fix->execute([$hash, (int) $user['id']]);

                    $user['password_hash'] = $hash;
                    $user['is_admin'] = 1;
                }

                return $user;
            }

            $email = $username . '@local.invalid';
            $stmt = $this->db->prepare(
                'INSERT INTO users (email, username, password_hash, is_admin, status, created_at) VALUES (?, ?, ?, 1, ?, NOW())'
            );
            $stmt->execute([$email, $username, $hash, self::STATUS_ACTIVE]);

            $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $this->db->lastInsertId()]);

            return $stmt->fetch() ?: null;
        } catch (Throwable $error) {
            $this->log('补建管理员前台账号失败: ' . $error->getMessage(), 'ERROR');

            return null;
        }
    }

    /**
     * 写入前台用户会话。
     */
    public function startSession(array $user)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_username'] = (string) $user['username'];
        $_SESSION['user_is_admin'] = (int) ($user['is_admin'] ?? 0);
    }

    public function logout()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['user_id'], $_SESSION['user_username'], $_SESSION['user_is_admin']);
        }
    }

    /**
     * 当前登录用户 id，未登录返回 0。
     */
    public function currentUserId()
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    }

    public function isLoggedIn()
    {
        return $this->currentUserId() > 0;
    }

    /**
     * 当前登录用户（含封禁态校验）。
     */
    public function currentUser()
    {
        $id = $this->currentUserId();

        if ($id <= 0) {
            return null;
        }

        $user = $this->findById($id);

        if (!$user || ($user['status'] ?? '') === self::STATUS_BANNED) {
            $this->logout();

            return null;
        }

        return $user;
    }

    public function findById($id)
    {
        if (!$this->usersTableExists()) {
            return null;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, email, username, is_admin, is_default_user, status, created_at, last_login, avatar, footer_copyright
                 FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([(int) $id]);
            $user = $stmt->fetch();
        } catch (Throwable $error) {
            // 老库无 footer_copyright / avatar 列时退回旧查询
            $stmt = $this->db->prepare(
                'SELECT id, email, username, is_admin, is_default_user, status, created_at, last_login
                 FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([(int) $id]);
            $user = $stmt->fetch();
        }

        return $user ?: null;
    }

    /**
     * 更新用户自定义的底部版权文字。
     *
     * @param int $id 用户 id
     * @param string $copyright 版权文字（支持 {year} / {site} 占位符），空字符串表示恢复默认
     * @return bool 列不存在（未执行 v2.3.0 升级脚本）时返回 false
     */
    public function updateFooterCopyright($id, $copyright)
    {
        $id = (int) $id;
        $copyright = trim((string) $copyright);

        if (function_exists('mb_substr')) {
            $copyright = mb_substr($copyright, 0, 120, 'UTF-8');
        } else {
            $copyright = substr($copyright, 0, 360);
        }

        if ($id <= 0 || !$this->usersTableExists()) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('UPDATE users SET footer_copyright = ? WHERE id = ?');
            $stmt->execute([$copyright !== '' ? $copyright : null, $id]);

            return true;
        } catch (Throwable $error) {
            return false;
        }
    }

    /**
     * 取指定用户的自定义版权文字；列不存在或无设置时返回空字符串。
     */
    public function footerCopyrightOf($id)
    {
        $id = (int) $id;

        if ($id <= 0 || !$this->usersTableExists()) {
            return '';
        }

        try {
            $stmt = $this->db->prepare('SELECT footer_copyright FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            return $row ? trim((string) ($row['footer_copyright'] ?? '')) : '';
        } catch (Throwable $error) {
            return '';
        }
    }

    /**
     * 修改昵称。当前版本昵称即登录用户名，这样不需要新增数据库列，
     * 改完顶栏、头像下拉与后台归属都能立即同步；代价是登录名也一起变，
     * 界面上已明确提示用户。
     *
     * @return array{ok:bool,error:string}
     */
    public function updateUsername($id, $name)
    {
        $id = (int) $id;
        $name = trim((string) $name);

        if ($id <= 0 || !$this->usersTableExists()) {
            return ['ok' => false, 'error' => '用户状态异常，请重新登录'];
        }

        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

        if ($length < 2 || $length > 30) {
            return ['ok' => false, 'error' => '昵称长度需为 2 ~ 30 个字符'];
        }

        if (preg_match('/[\x00-\x1f]/', $name) || preg_match('/[<>]/u', $name)) {
            return ['ok' => false, 'error' => '昵称不能包含控制字符或 < >'];
        }

        try {
            $dup = $this->db->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
            $dup->execute([$name, $id]);

            if ($dup->fetch()) {
                return ['ok' => false, 'error' => '该昵称已被其他用户使用'];
            }

            $stmt = $this->db->prepare('UPDATE users SET username = ? WHERE id = ?');
            $stmt->execute([$name, $id]);

            if (isset($_SESSION['username'])) {
                $_SESSION['username'] = $name;
            }

            return ['ok' => true, 'error' => ''];
        } catch (Throwable $error) {
            return ['ok' => false, 'error' => '保存失败，请稍后重试'];
        }
    }

    /**
     * 更新用户头像。
     *
     * @param int $id 用户 id
     * @param string $avatar 头像路径或 URL，空字符串表示清除头像
     * @return bool
     */
    public function updateAvatar($id, $avatar)
    {
        $id = (int) $id;
        $avatar = trim((string) $avatar);

        if ($id <= 0 || !$this->usersTableExists()) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('UPDATE users SET avatar = ? WHERE id = ?');
            $stmt->execute([$avatar === '' ? null : $avatar, $id]);

            return $stmt->rowCount() >= 0;
        } catch (Throwable $error) {
            // 老库无 avatar 列时忽略
            return false;
        }
    }

    public function findByEmail($email)
    {
        if (!$this->usersTableExists()) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([trim((string) $email)]);

        return $stmt->fetch() ?: null;
    }

    /**
     * 用户列表（后台用）。
     */
    public function paginate($keyword = '', $page = 1, $size = 20)
    {
        $page = max(1, (int) $page);
        $size = min(100, max(1, (int) $size));

        if (!$this->usersTableExists()) {
            return ['total' => 0, 'page' => $page, 'size' => $size, 'pages' => 0, 'items' => []];
        }

        $offset = ($page - 1) * $size;
        $where = '';
        $params = [];

        $keyword = trim((string) $keyword);

        if ($keyword !== '') {
            $where = ' WHERE username LIKE ? OR email LIKE ?';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }

        $countStmt = $this->db->prepare('SELECT COUNT(*) AS total FROM users' . $where);
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        $sql = 'SELECT u.id, u.email, u.username, u.is_admin, u.is_default_user, u.status,
                       u.created_at, u.last_login,
                       (SELECT COUNT(*) FROM nav_links nl WHERE nl.user_id = u.id AND nl.deleted_at IS NULL) AS link_count
                FROM users u' . $where . ' ORDER BY u.id DESC LIMIT ?, ?';

        $stmt = $this->db->prepare($sql);

        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
        }

        $stmt->bindValue(count($params) + 1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $size, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'pages' => (int) ceil($total / $size),
            'items' => $stmt->fetchAll(),
        ];
    }

    public function countAll()
    {
        if (!$this->usersTableExists()) {
            return 0;
        }

        $stmt = $this->db->query('SELECT COUNT(*) AS total FROM users');

        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /**
     * 按日统计新增用户，用于统计中心图表。
     */
    public function growthByDay($days = 30)
    {
        $days = max(1, min(180, (int) $days));

        if (!$this->usersTableExists()) {
            $rows = [];

            for ($i = $days; $i >= 0; $i--) {
                $rows[] = ['day' => date('Y-m-d', strtotime('-' . $i . ' day')), 'total' => 0];
            }

            return $rows;
        }

        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM users
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at) ORDER BY day"
        );
        $stmt->bindValue(1, $days, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        $found = [];

        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['day']] = (int) $row['total'];
        }

        for ($i = $days; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' day'));
            $rows[] = ['day' => $day, 'total' => $found[$day] ?? 0];
        }

        return $rows;
    }

    public function setStatus($id, $status)
    {
        $status = $status === self::STATUS_BANNED ? self::STATUS_BANNED : self::STATUS_ACTIVE;
        $stmt = $this->db->prepare('UPDATE users SET status = ? WHERE id = ?');

        return $stmt->execute([$status, (int) $id]);
    }

    public function setAdmin($id, $isAdmin)
    {
        $stmt = $this->db->prepare('UPDATE users SET is_admin = ? WHERE id = ?');

        return $stmt->execute([$isAdmin ? 1 : 0, (int) $id]);
    }

    /**
     * 设为「默认访客视图」的唯一用户，并同步 settings.default_user_id。
     */
    public function setDefaultUser($id)
    {
        $id = (int) $id;

        $this->db->prepare('UPDATE users SET is_default_user = 0')->execute();
        $this->db->prepare('UPDATE users SET is_default_user = 1 WHERE id = ?')->execute([$id]);

        if ($this->functions) {
            $this->functions->updateSetting('default_user_id', $id > 0 ? (string) $id : '0');
        }

        return true;
    }

    /**
     * 默认访客视图的 user_id。
     *
     * settings.default_user_id 为权威来源：
     *   - 明确写入且大于 0 → 使用该用户（需存在且未封禁）；
     *   - 明确写入 0        → 公共导航（user_id = 0）；
     *   - 键不存在（老版本升级未写入时）→ 退回 users.is_default_user 标记。
     *
     * 老库可能还没有 users 表，任何异常都退化为公共导航，避免前台 500。
     */
    public function defaultViewUserId()
    {
        try {
            $raw = $this->setting('default_user_id', '');

            if ($raw !== '') {
                $configured = (int) $raw;

                if ($configured > 0) {
                    $stmt = $this->db->prepare('SELECT id FROM users WHERE id = ? AND status = ? LIMIT 1');
                    $stmt->execute([$configured, self::STATUS_ACTIVE]);

                    if ($stmt->fetch()) {
                        return $configured;
                    }
                }

                return 0;
            }

            $stmt = $this->db->prepare('SELECT id FROM users WHERE is_default_user = 1 AND status = ? LIMIT 1');
            $stmt->execute([self::STATUS_ACTIVE]);
            $row = $stmt->fetch();

            return $row ? (int) $row['id'] : 0;
        } catch (Throwable $error) {
            return 0;
        }
    }

    public function resetPassword($id, $newPassword)
    {
        if (strlen((string) $newPassword) < 8) {
            return ['ok' => false, 'error' => '密码长度需为 8-72 位'];
        }

        $hash = password_hash((string) $newPassword, PASSWORD_DEFAULT);

        if (!is_string($hash) || $hash === '') {
            return ['ok' => false, 'error' => '密码加密失败'];
        }

        $stmt = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, (int) $id]);

        return ['ok' => true];
    }

    /**
     * 前台用户自行改密。
     */
    public function changePassword($id, $oldPassword, $newPassword)
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();

        if (!$row || !password_verify((string) $oldPassword, (string) $row['password_hash'])) {
            return ['ok' => false, 'error' => '原密码不正确'];
        }

        return $this->resetPassword($id, $newPassword);
    }

    /**
     * 创建密码重置令牌（忘记密码）。
     *
     * 数据库只存令牌的 SHA-256 哈希与过期时间，令牌明文只出现在邮件链接里。
     * 出于防枚举考虑，邮箱不存在时返回 ok=true 但 token 为空（调用方不发邮件）。
     *
     * @return array{ok:bool, token?:string, user?:array, error?:string}
     */
    public function createPasswordReset($email)
    {
        if (!$this->usersTableExists()) {
            return ['ok' => false, 'error' => '用户系统不可用'];
        }

        $user = $this->findByEmail($email);

        if (!$user || ($user['status'] ?? self::STATUS_ACTIVE) === self::STATUS_BANNED) {
            // 统一提示，避免暴露邮箱是否注册
            return ['ok' => true, 'token' => null, 'user' => null];
        }

        // 老库未升级 reset_token_hash / reset_expires 列时提示先升级
        try {
            $this->db->query('SELECT reset_token_hash, reset_expires FROM users LIMIT 1');
        } catch (Throwable $error) {
            return ['ok' => false, 'error' => '站点数据库尚未升级，暂不支持找回密码'];
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $error) {
            return ['ok' => false, 'error' => '令牌生成失败，请重试'];
        }

        $hash = hash('sha256', $token);
        $expires = time() + 3600; // 1 小时有效

        $stmt = $this->db->prepare('UPDATE users SET reset_token_hash = ?, reset_expires = ? WHERE id = ?');
        $stmt->execute([$hash, $expires, (int) $user['id']]);

        return ['ok' => true, 'token' => $token, 'user' => $user];
    }

    /**
     * 按重置令牌查找有效用户；无效或过期返回 null。
     */
    public function findByResetToken($token)
    {
        $token = trim((string) $token);

        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        try {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE reset_token_hash = ? LIMIT 1');
            $stmt->execute([hash('sha256', $token)]);
            $user = $stmt->fetch();
        } catch (Throwable $error) {
            return null;
        }

        if (!$user) {
            return null;
        }

        if ((int) ($user['reset_expires'] ?? 0) < time()) {
            return null;
        }

        return $user;
    }

    /**
     * 通过重置令牌重设密码，成功后清除令牌。
     */
    public function resetPasswordByToken($token, $newPassword)
    {
        $user = $this->findByResetToken($token);

        if (!$user) {
            return ['ok' => false, 'error' => '重置链接无效或已过期，请重新申请'];
        }

        $newPassword = (string) $newPassword;

        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            return ['ok' => false, 'error' => '密码长度需为 8-72 位'];
        }

        $stmt = $this->db->prepare('UPDATE users SET password_hash = ?, reset_token_hash = NULL, reset_expires = NULL WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);

        return ['ok' => true, 'user' => $user];
    }

    /**
     * 删除用户，同时清理其私有导航与分享。
     */
    public function delete($id)
    {
        $id = (int) $id;

        try {
            $this->db->beginTransaction();
            $this->db->prepare('DELETE FROM nav_links WHERE user_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM shares WHERE user_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->log('删除用户失败: ' . $error->getMessage(), 'ERROR');

            return false;
        }

        if ($this->settingInt('default_user_id', 0) === $id && $this->functions) {
            $this->functions->updateSetting('default_user_id', '0');
        }

        return true;
    }

    /**
     * 登录用户 id 或回落到默认访客视图。
     */
    public function viewUserIdForRequest()
    {
        $current = $this->currentUserId();

        return $current > 0 ? $current : $this->defaultViewUserId();
    }

    protected function log($message, $type = 'INFO')
    {
        if ($this->functions) {
            $this->functions->logMessage('[UserService] ' . $message, $type);
        }
    }
}

/* ==========================================================================
 * 2. 站点信息抓取
 * ========================================================================== */

class SiteInfoService extends BaseService
{
    const CACHE_OK_TTL = 604800;   // 抓取成功缓存 7 天
    const CACHE_FAIL_TTL = 1800;   // 失败缓存 30 分钟
    const MAX_BYTES = 524288;

    /** @var string 最近一次请求拿到的 Location 头（3xx 时） */
    protected $lastRedirectUrl = '';

    /**
     * 抓取前的公共校验：URL 合法性、协议白名单、SSRF 防护，以及缓存读取。
     *
     * 单条 fetch() 与批量 fetchMany() 共用这一段 —— 安全规则只写一份，
     * 将来补 SSRF 规则时不会出现「改了一处漏了另一处」的情况。
     *
     * @return array{ok:bool, error:string, host:string, url:string, cacheFile:string, cached:?array}
     */
    protected function prepareFetch($url, $useCache = true)
    {
        $blank = ['ok' => false, 'error' => 'invalid_url', 'host' => '', 'url' => $url, 'cacheFile' => '', 'cached' => null];

        $url = trim((string) $url);

        if ($url === '' || strlen($url) > 2048) {
            return $blank;
        }

        $parts = parse_url($url);

        if (!$parts || empty($parts['scheme']) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) {
            return $blank;
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return array_merge($blank, ['error' => 'invalid_scheme']);
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '' || in_array($host, ['localhost'], true)
            || substr($host, -6) === '.local'
            || substr($host, -9) === '.internal'
            || substr($host, -4) === '.lan'
        ) {
            return array_merge($blank, ['host' => $host, 'error' => 'blocked_host']);
        }

        foreach ($this->resolveIps($host) as $ip) {
            if (!$this->isPublicIp($ip)) {
                return array_merge($blank, ['host' => $host, 'error' => 'blocked_ip']);
            }
        }

        $cacheFile = $this->cachePath($url);

        return [
            'ok' => true,
            'error' => '',
            'host' => $host,
            'url' => $url,
            'cacheFile' => $cacheFile,
            'cached' => $useCache ? $this->readCache($cacheFile) : null,
        ];
    }

    /**
     * 抓取单个站点的标题 / 描述 / 图标候选。
     *
     * @return array{ok:bool,error?:string,title?:string,description?:string,host?:string,icons?:array}
     */
    public function fetch($url, $useCache = true)
    {
        $prepared = $this->prepareFetch($url, $useCache);

        if (!$prepared['ok']) {
            return [
                'ok' => false,
                'error' => $prepared['error'],
                'host' => $prepared['host'],
            ];
        }

        if ($prepared['cached']) {
            return $prepared['cached'];
        }

        $url = $prepared['url'];
        $host = $prepared['host'];
        $cacheFile = $prepared['cacheFile'];

        $html = $this->download($url);

        // 3xx 重定向：最多安全跟随 2 次（重定向目标也走 SSRF 校验）
        $redirectsLeft = 2;

        while ($redirectsLeft-- > 0 && $html === '') {
            $location = $this->lastRedirectUrl;

            if ($location === '') {
                break;
            }

            $next = $this->absoluteUrl($location, $url);

            if ($next === '') {
                break;
            }

            $parts = parse_url($next);

            if (!$parts || empty($parts['host'])) {
                break;
            }

            $nextHost = strtolower(trim($parts['host'], '[]'));

            if ($nextHost === '' || in_array($nextHost, ['localhost'], true)
                || substr($nextHost, -6) === '.local'
                || substr($nextHost, -9) === '.internal'
                || substr($nextHost, -4) === '.lan'
            ) {
                break;
            }

            $allowed = true;

            foreach ($this->resolveIps($nextHost) as $ip) {
                if (!$this->isPublicIp($ip)) {
                    $allowed = false;
                    break;
                }
            }

            if (!$allowed) {
                break;
            }

            $url = $next;
            $host = $nextHost;
            $html = $this->download($url);
        }

        return $this->finishFetch($prepared, $html, $url, $host);
    }

    /**
     * 抓取收尾：解析 HTML、构造结果、写缓存。单条与批量共用。
     *
     * @param array $prepared prepareFetch() 的返回值
     * @param string $html 正文（空串表示下载失败或命中重定向）
     * @param string $finalUrl 最终实际抓取的地址（跟随重定向后会变）
     * @param string $finalHost 最终主机名
     */
    protected function finishFetch(array $prepared, $html, $finalUrl = null, $finalHost = null)
    {
        $url = $finalUrl !== null ? $finalUrl : $prepared['url'];
        $host = $finalHost !== null ? $finalHost : $prepared['host'];

        $payload = [
            'ok' => false,
            'error' => 'fetch_failed',
            'host' => $host,
        ];

        if (is_string($html) && $html !== '') {
            if (strlen($html) > self::MAX_BYTES) {
                $html = substr($html, 0, self::MAX_BYTES);
            }

            $info = $this->parseHtml($html, $url, $host);

            $payload = [
                'ok' => $info['title'] !== '',
                'title' => $info['title'],
                'description' => $info['description'],
                'host' => $host,
                'icons' => $info['icons'],
            ];

            if ($info['title'] === '') {
                $payload['error'] = 'no_title';
            }
        }

        if (!empty($prepared['cacheFile'])) {
            $this->writeCache($prepared['cacheFile'], $payload);
        }

        return $payload;
    }

    /**
     * 批量抓取：缓存命中直接返回，未命中的用 curl_multi 并发下载。
     *
     * 与 fetch() 的差异只在「下载」这一步并发化，校验、解析、缓存全部复用，
     * 因此单条收录与批量导入拿到的标题 / 图标 / 描述结果完全一致。
     *
     * 批量场景不跟随重定向：控制台类站点 302 多半跳到登录页，抓回来也是错的，
     * 直接标记 fetch_ok=false，让前端降级为「手动填写名称」。
     *
     * @param array $urls 网址列表
     * @param int $perRequest 单条总超时（秒）
     * @param int $budget 总时间预算（秒），超出后剩余条目直接判为失败
     * @return array url => payload
     */
    public function fetchMany(array $urls, $perRequest = 8, $budget = 18)
    {
        $result = [];
        $pending = [];
        $start = microtime(true);

        foreach ($urls as $raw) {
            $key = trim((string) $raw);
            $prepared = $this->prepareFetch($key);

            if (!$prepared['ok']) {
                $result[$key] = ['ok' => false, 'error' => $prepared['error'], 'host' => $prepared['host']];
                continue;
            }

            if ($prepared['cached']) {
                $result[$key] = $prepared['cached'];
                continue;
            }

            $result[$key] = ['ok' => false, 'error' => 'pending', 'host' => $prepared['host']];
            $pending[$key] = $prepared;
        }

        if (!$pending) {
            return $result;
        }

        // 没有 curl_multi 就退回串行，行为与单条抓取一致（只是慢）
        if (!function_exists('curl_multi_init')) {
            foreach ($pending as $key => $prepared) {
                if (microtime(true) - $start > $budget) {
                    break;
                }

                $result[$key] = $this->finishFetch($prepared, $this->download($prepared['url']));
            }

            return $result;
        }

        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

        $deadline = $start + $budget;
        $responses = $this->multiDownload($pending, $userAgent, $perRequest, $deadline);

        // 第一轮：拿到正文的直接落地；3xx 的收集起来再跟一轮
        $follow = [];

        foreach ($pending as $key => $prepared) {
            $resp = $responses[$key] ?? ['html' => '', 'location' => ''];

            if ($resp['html'] !== '') {
                $result[$key] = $this->finishFetch($prepared, $resp['html']);
                continue;
            }

            if ($resp['location'] === '') {
                $result[$key] = ['ok' => false, 'error' => 'fetch_failed', 'host' => $prepared['host']];
                continue;
            }

            // 重定向目标同样要过 SSRF 校验（顺带可能命中缓存）
            $target = $this->absoluteUrl($resp['location'], $prepared['url']);
            $nextPrepared = $target !== '' ? $this->prepareFetch($target) : null;

            if ($nextPrepared === null || !$nextPrepared['ok']) {
                $result[$key] = ['ok' => false, 'error' => 'fetch_failed', 'host' => $prepared['host']];
                continue;
            }

            if ($nextPrepared['cached']) {
                $result[$key] = $nextPrepared['cached'];
                continue;
            }

            $follow[$key] = $nextPrepared;
        }

        if (!$follow || microtime(true) > $deadline) {
            return $result;
        }

        // 第二轮：只抓重定向目标，仍拿不到正文就判失败（不再继续跟随）
        $responses2 = $this->multiDownload($follow, $userAgent, $perRequest, $deadline);

        foreach ($follow as $key => $prepared) {
            $resp = $responses2[$key] ?? ['html' => '', 'location' => ''];

            $result[$key] = $resp['html'] !== ''
                ? $this->finishFetch($prepared, $resp['html'])
                : ['ok' => false, 'error' => 'fetch_failed', 'host' => $prepared['host']];
        }

        return $result;
    }

    /**
     * 用 curl_multi 并发下载一批地址。
     *
     * 只负责「把字节取回来」，不解析、不写缓存 —— 解析与落地交给 finishFetch()，
     * 保证单条与批量两条路径的结果结构一致。
     *
     * @param array $queue key => prepareFetch() 结果
     * @param float $deadline 绝对截止时间戳，超时后未完成的判为失败
     * @return array key => array{html:string, location:string}
     */
    protected function multiDownload(array $queue, $userAgent, $perRequest, $deadline)
    {
        $out = [];

        foreach ($queue as $key => $prepared) {
            $out[$key] = ['html' => '', 'location' => ''];
        }

        // 没有 curl_multi 就串行降级，语义与并发版一致（只是慢）
        if (!function_exists('curl_multi_init')) {
            foreach ($queue as $key => $prepared) {
                if (microtime(true) > $deadline) {
                    break;
                }

                $html = $this->download($prepared['url']);
                $out[$key] = ['html' => $html, 'location' => $html === '' ? $this->lastRedirectUrl : ''];
            }

            return $out;
        }

        foreach (array_chunk($queue, 8, true) as $chunk) {
            if (microtime(true) > $deadline) {
                break;
            }

            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $key => $prepared) {
                $ch = curl_init($prepared['url']);
                $options = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_MAXREDIRS => 0,
                    CURLOPT_MAXFILESIZE => self::MAX_BYTES,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_TIMEOUT => (int) $perRequest,
                    CURLOPT_USERAGENT => $userAgent,
                    CURLOPT_ENCODING => '',
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_HEADER => true,
                    CURLOPT_HTTPHEADER => [
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                        'Cache-Control: no-cache',
                    ],
                ];

                if (defined('CURLOPT_REDIR_PROTOCOLS')) {
                    $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                }

                curl_setopt_array($ch, $options);
                curl_multi_add_handle($mh, $ch);
                $handles[$key] = $ch;
            }

            do {
                $status = curl_multi_exec($mh, $running);

                if ($running > 0) {
                    curl_multi_select($mh, 0.3);
                }
            } while ($running > 0 && $status === CURLM_OK && microtime(true) < $deadline);

            foreach ($handles as $key => $ch) {
                $raw = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                $out[$key] = $this->parseResponse(is_string($raw) ? $raw : '');
            }

            curl_multi_close($mh);
        }

        return $out;
    }

    /**
     * 解析 HTML，抽出标题、描述、图标候选。
     */
    public function parseHtml($html, $url = '', $host = '')
    {
        $title = '';
        $description = '';
        $icons = [];

        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match)) {
            $title = $match[1];
        } elseif (preg_match('/<meta[^>]+(?:property|name)\s*=\s*["\'](?:og:title|twitter:title)["\'][^>]*>/i', $html, $tag)
            && preg_match('/content\s*=\s*["\'](.*?)["\']/is', $tag[0], $content)
        ) {
            $title = $content[1];
        }

        if (preg_match('/<meta[^>]+(?:property|name)\s*=\s*["\'](?:description|og:description)["\'][^>]*>/i', $html, $tag)
            && preg_match('/content\s*=\s*["\'](.*?)["\']/is', $tag[0], $content)
        ) {
            $description = $content[1];
        }

        if (preg_match_all('/<link\b[^>]+rel\s*=\s*["\'][^"\']*icon[^"\']*["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                if (preg_match('/href\s*=\s*["\'](.*?)["\']/is', $tag, $href)) {
                    $icons[] = $this->absoluteUrl($href[1], $url);
                }
            }
        }

        // og:image / twitter:image / msapplication-TileImage 作为兜底图标候选
        if (preg_match_all('/<meta[^>]+(?:property|name)\s*=\s*["\'](?:og:image|twitter:image|msapplication-TileImage)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                if (preg_match('/content\s*=\s*["\'](.*?)["\']/is', $tag, $content)) {
                    $icons[] = $this->absoluteUrl($content[1], $url);
                }
            }
        }

        if ($host !== '') {
            $icons[] = (strpos($url, 'https://') === 0 ? 'https://' : 'http://') . $host . '/favicon.ico';
        }

        $title = $this->cleanText($title, 80);
        $description = $this->cleanText($description, 200);

        $icons = array_values(array_unique(array_filter(array_map('trim', $icons))));

        return [
            'title' => $title,
            'description' => $description,
            'icons' => array_slice($icons, 0, 5),
        ];
    }

    protected function cleanText($text, $limit)
    {
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $text);
        $text = trim(is_string($normalized) ? $normalized : $text);

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit, 'UTF-8');
        }

        return substr($text, 0, $limit * 3);
    }

    protected function absoluteUrl($href, $baseUrl)
    {
        $href = trim((string) $href);

        if ($href === '' || preg_match('#^data:#i', $href)) {
            return '';
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        if (strpos($href, '//') === 0) {
            $scheme = strpos($baseUrl, 'https://') === 0 ? 'https:' : 'http:';

            return $scheme . $href;
        }

        $parts = parse_url($baseUrl);

        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $root = $parts['scheme'] . '://' . $parts['host'];

        if (strpos($href, '/') === 0) {
            return $root . $href;
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $root . $dir . $href;
    }

    protected function resolveIps($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        if (strpos($host, '.') === false) {
            return [];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if (!$ips) {
            $ipv4 = @gethostbynamel($host);

            if (is_array($ipv4)) {
                $ips = $ipv4;
            }
        }

        return array_values(array_unique($ips));
    }

    protected function isPublicIp($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * 拆开带 header 的响应，返回正文与 3xx 的 Location。
     *
     * 无副作用：不写 $lastRedirectUrl，因此并发抓取时多条结果互不干扰。
     *
     * @return array{html:string, location:string}
     */
    protected function parseResponse($raw)
    {
        if (!is_string($raw) || $raw === '') {
            return ['html' => '', 'location' => ''];
        }

        $html = $raw;
        $lastHeader = '';

        while (preg_match("/\r?\n\r?\n/", $html, $m, PREG_OFFSET_CAPTURE)) {
            $split = $m[0][1];
            $lastHeader = substr($html, 0, $split);
            $rest = substr($html, $split + strlen($m[0][0]));

            // 若 body 仍以 HTTP/ 开头，说明还有 header 段（如 100-continue）
            if (preg_match('#^HTTP/\d#i', ltrim($rest))) {
                $html = $rest;
                continue;
            }

            $html = $rest;
            break;
        }

        if (preg_match('/^Location:\s*(\S+)/im', $lastHeader, $m)) {
            $status = 0;

            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $lastHeader, $sm)) {
                $status = (int) $sm[1];
            }

            if ($status >= 300 && $status < 400) {
                return ['html' => '', 'location' => trim($m[1])];
            }
        }

        return ['html' => $html, 'location' => ''];
    }

    /**
     * 拆出正文；命中 3xx 时返回空串并把 Location 记到 lastRedirectUrl。
     * 单条 download() 使用，批量路径请用 parseResponse()。
     */
    protected function splitResponse($raw)
    {
        $parsed = $this->parseResponse($raw);

        if ($parsed['location'] !== '') {
            $this->lastRedirectUrl = $parsed['location'];
            return '';
        }

        return $parsed['html'];
    }

    protected function download($url)
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

        $this->lastRedirectUrl = '';

        if (function_exists('curl_init')) {
            // 服务器环境未配置 CA 证书包，统一关闭 SSL 证书校验，避免 TLS 报错。
            // 仅做超时重试：首次较短超时，失败再用更长超时重试一次。
            $attempts = [
                ['connect' => 5, 'total' => 10, 'verify' => false],
                ['connect' => 8, 'total' => 15, 'verify' => false],
            ];

            $html = '';

            foreach ($attempts as $attempt) {
                $ch = curl_init($url);
                $options = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_MAXREDIRS => 0,
                    CURLOPT_MAXFILESIZE => self::MAX_BYTES,
                    CURLOPT_CONNECTTIMEOUT => $attempt['connect'],
                    CURLOPT_TIMEOUT => $attempt['total'],
                    CURLOPT_USERAGENT => $userAgent,
                    CURLOPT_ENCODING => '',
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_HEADER => true,
                    CURLOPT_HTTPHEADER => [
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                        'Cache-Control: no-cache',
                    ],
                ];

                if (defined('CURLOPT_REDIR_PROTOCOLS')) {
                    $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                }

                curl_setopt_array($ch, $options);
                $raw = curl_exec($ch);
                curl_close($ch);

                if (is_string($raw) && $raw !== '') {
                    $html = $this->splitResponse($raw);

                    // 拿到内容就停止重试
                    if ($html !== '') {
                        break;
                    }
                }
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'user_agent' => $userAgent,
                    'ignore_errors' => true,
                    'header' => "Accept: text/html,application/xhtml+xml\r\nAccept-Language: zh-CN,zh;q=0.9\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $html = @file_get_contents($url, false, $context);

            // 从 $http_response_header 提取 Location
            if (isset($http_response_header) && is_array($http_response_header)) {
                $status = 0;
                $location = '';

                foreach ($http_response_header as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $sm)) {
                        $status = (int) $sm[1];
                    } elseif (preg_match('/^Location:\s*(\S+)/i', $line, $lm)) {
                        $location = trim($lm[1]);
                    }
                }

                if ($status >= 300 && $status < 400 && $location !== '') {
                    $this->lastRedirectUrl = $location;
                    return '';
                }
            }
        }

        if (!is_string($html)) {
            return '';
        }

        if (strlen($html) > self::MAX_BYTES) {
            $html = substr($html, 0, self::MAX_BYTES);
        }

        // 非 UTF-8 页面（如 GBK）做一次转换，避免标题乱码。
        $charset = '';

        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([a-zA-Z0-9_\-]+)/i', $html, $match)) {
            $charset = strtoupper($match[1]);
        } elseif (preg_match('/<meta[^>]+content\s*=\s*["\'][^"\']*charset=([a-zA-Z0-9_\-]+)/i', $html, $match)) {
            $charset = strtoupper($match[1]);
        }

        $supported = ['GBK', 'GB2312', 'GB18030', 'BIG5', 'ISO-8859-1', 'WINDOWS-1252'];

        if ($charset !== '' && $charset !== 'UTF-8' && in_array($charset, $supported, true) && function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $charset);

            if (is_string($converted) && $converted !== '') {
                $html = $converted;
            }
        }

        return $html;
    }

    protected function cachePath($url)
    {
        $dir = dirname(__DIR__) . '/cache/site_meta';

        return $dir . '/' . sha1($url) . '.json';
    }

    protected function readCache($cacheFile)
    {
        if (!is_file($cacheFile)) {
            return null;
        }

        $age = time() - (int) filemtime($cacheFile);
        $raw = @file_get_contents($cacheFile);
        $data = $raw ? json_decode($raw, true) : null;

        if (!is_array($data) || !array_key_exists('ok', $data)) {
            return null;
        }

        $ttl = $data['ok'] ? self::CACHE_OK_TTL : self::CACHE_FAIL_TTL;

        return $age < $ttl ? $data : null;
    }

    protected function writeCache($cacheFile, array $payload)
    {
        $dir = dirname($cacheFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents(
                $cacheFile,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                LOCK_EX
            );
        }
    }
}

/* ==========================================================================
 * 3. AI 服务
 * ========================================================================== */

class AiService extends BaseService
{
    public function enabled()
    {
        return $this->settingInt('ai_enabled', 0) === 1
            && $this->setting('ai_api_url') !== ''
            && $this->setting('ai_api_key') !== '';
    }

    /**
     * 推荐分类名。AI 不可用时回落到本地关键词引擎。
     *
     * @param array $categories 分类名列表
     * @return array{ok:bool,source:string,category?:string,reason?:string,error?:string}
     */
    public function suggestCategory($name, $description, array $categories)
    {
        $categories = array_values(array_filter(array_map('strval', $categories)));

        if (!$categories) {
            return ['ok' => false, 'source' => 'none', 'error' => 'no_categories'];
        }

        if ($this->enabled()) {
            $result = $this->askModel($name, $description, $categories);

            if ($result['ok']) {
                return $result;
            }
        }

        $engine = new CategoryEngine($this->functions);
        $matched = $engine->matchName($name . ' ' . $description, $categories);

        if ($matched !== null) {
            return ['ok' => true, 'source' => 'rules', 'category' => $matched, 'reason' => '关键词匹配'];
        }

        return ['ok' => false, 'source' => 'none', 'error' => 'no_match'];
    }

    /**
     * v2.16.0 · 推荐分类名，允许 AI 给出「列表里没有的新分类」。
     *
     * 与 suggestCategory() 的区别：suggestCategory 只在已有分类里挑，
     * AI 答不上来就回落到本地关键词规则，用于「不得新增分类」的场景。
     * 这里则明确允许 AI 建议一个新分类名（is_new = true），
     * 由调用方决定是否真的创建，供开放 API 的 ai_recommend / 前台快捷收录使用。
     *
     * @param array $categories 当前用户可见的分类名列表
     * @return array{ok:bool,source:string,category?:string,is_new?:bool,error?:string}
     */
    public function recommendCategory($name, $description, array $categories)
    {
        $categories = array_values(array_filter(array_map('strval', $categories)));
        $name = trim((string) $name);
        $description = trim((string) $description);

        if ($name === '' && $description === '') {
            return ['ok' => false, 'source' => 'none', 'error' => 'empty_input'];
        }

        if (!$this->enabled()) {
            return ['ok' => false, 'source' => 'none', 'error' => 'ai_disabled'];
        }

        $url = $this->setting('ai_api_url');
        $key = $this->setting('ai_api_key');
        $model = $this->setting('ai_model', 'gpt-4o-mini');

        if (strpos($url, 'http') !== 0) {
            return ['ok' => false, 'source' => 'ai', 'error' => 'invalid_api_url'];
        }

        $url = $this->chatEndpoint($url);
        $list = $categories ? implode('、', $categories) : '（暂无分类）';

        $prompt = "站点名称：{$name}\n站点描述：{$description}\n\n"
            . "现有分类：{$list}\n\n"
            . "请为该站点选一个最合适的分类，规则：\n"
            . "1. 现有分类够用就只回复该分类名，不要任何解释；\n"
            . "2. 都不合适时，回复一个新的分类名（2-6 个汉字，如「AI 工具」「设计素材」）；\n"
            . "3. 只输出分类名本身，不要标点、不要引号。";

        $body = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => '你是网站分类助手，只输出一个分类名。'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
            'max_tokens' => 32,
        ], JSON_UNESCAPED_UNICODE);

        $response = $this->postJson($url, $body, $key);

        if (!$response['ok']) {
            $this->log('AI 分类推荐失败: ' . ($response['error'] ?? 'unknown'), 'WARNING');

            return ['ok' => false, 'source' => 'ai', 'error' => $response['error']];
        }

        $data = json_decode((string) $response['body'], true);
        $content = '';

        if (is_array($data) && isset($data['choices'][0]['message']['content'])) {
            $content = (string) $data['choices'][0]['message']['content'];
        }

        // 清掉模型常见的包装：引号、书名号、句号、换行、前缀说明
        $content = trim(str_replace(['"', "'", '「', '」', '《', '》', '。', '：', ':', "\n", "\r"], '', $content));
        $content = preg_replace('/^(分类名|分类|建议)[:：]?/u', '', $content);
        $content = trim((string) $content);

        if ($content === '' || $content === '无') {
            return ['ok' => false, 'source' => 'ai', 'error' => 'no_match'];
        }

        $content = $this->textCut($content, 50);
        $isNew = !in_array($content, $categories, true);

        return [
            'ok' => true,
            'source' => 'ai',
            'category' => $content,
            'is_new' => $isNew,
        ];
    }

    /**
     * 调用 OpenAI 兼容的 chat completions 接口。
     */
    protected function askModel($name, $description, array $categories)
    {
        $url = $this->setting('ai_api_url');
        $key = $this->setting('ai_api_key');
        $model = $this->setting('ai_model', 'gpt-4o-mini');

        if (strpos($url, 'http') !== 0) {
            return ['ok' => false, 'source' => 'ai', 'error' => 'invalid_api_url'];
        }

        // 用户可能只填了 base URL，这里统一补全成 .../chat/completions 再请求
        $url = $this->chatEndpoint($url);

        $list = implode('、', $categories);
        $prompt = "站点名称：{$name}\n站点描述：{$description}\n\n"
            . "请从下面的分类中选出最合适的一个，只回复分类名本身，不要任何解释：\n{$list}\n\n"
            . "如果都不合适，只回复：无";

        $body = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => '你是网站分类助手，只输出一个分类名或“无”。'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
            'max_tokens' => 32,
        ], JSON_UNESCAPED_UNICODE);

        $response = $this->postJson($url, $body, $key);

        if (!$response['ok']) {
            $this->log('AI 接口调用失败: ' . ($response['error'] ?? 'unknown'), 'WARNING');

            return ['ok' => false, 'source' => 'ai', 'error' => $response['error']];
        }

        $data = json_decode($response['body'], true);
        $content = '';

        if (is_array($data)) {
            if (isset($data['choices'][0]['message']['content'])) {
                $content = (string) $data['choices'][0]['message']['content'];
            } elseif (isset($data['choices'][0]['text'])) {
                $content = (string) $data['choices'][0]['text'];
            } elseif (isset($data['reply'])) {
                $content = (string) $data['reply'];
            }
        }

        $content = trim(str_replace(['"', '「', '」', '。'], '', $content));

        if ($content === '' || $content === '无') {
            return ['ok' => false, 'source' => 'ai', 'error' => 'no_match'];
        }

        foreach ($categories as $category) {
            if ($category === $content
                || $this->textHas($category, $content, true)
                || $this->textHas($content, $category, true)
            ) {
                return ['ok' => true, 'source' => 'ai', 'category' => $category];
            }
        }

        return ['ok' => false, 'source' => 'ai', 'error' => 'unexpected_answer'];
    }

    /**
     * 把用户填写的接口地址规范成完整的 chat/completions 请求地址。
     *
     * 用户经常只填 base URL（如 https://api.openai.com/v1/、
     * https://open.bigmodel.cn/api/paas/v4/），直接 POST 这个地址必然 404。
     * 规则：
     *   1) 已以 /chat/completions 结尾 → 原样使用（大小写、多余斜杠做归一）；
     *   2) 其余（以 / 结尾或干脆不带斜杠）→ 去掉尾部斜杠后拼接 /chat/completions；
     *   3) 合并路径中出现的重复斜杠，避免 https://host//v1//chat/completions；
     *   4) 查询串与锚点原样保留在末尾（如 ?api-version=xxx），不参与拼接。
     *
     * @param string $url 用户填写的接口地址
     * @return string 实际应当请求的完整地址
     */
    public function chatEndpoint($url)
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        // 拆出 scheme://host 之后的部分，只对路径做拼接与归一，
        // 避免正则的「重复斜杠」误伤 https:// 中的双斜杠。
        $suffix = '';   // 查询串 + 锚点
        $fragmentPos = strpos($url, '#');

        if ($fragmentPos !== false) {
            $suffix = substr($url, $fragmentPos);
            $url = substr($url, 0, $fragmentPos);
        }

        $queryPos = strpos($url, '?');

        if ($queryPos !== false) {
            $suffix = substr($url, $queryPos) . $suffix;
            $url = substr($url, 0, $queryPos);
        }

        if (!preg_match('#^([a-z][a-z0-9+.\-]*://[^/]*)(/.*)?$#i', $url, $match)) {
            // 不是标准 http(s) 地址时不猜测，交由调用方做合法性校验
            return $url . $suffix;
        }

        $origin = $match[1];
        $path = isset($match[2]) ? $match[2] : '';

        // 合并路径中的重复斜杠：/v1//chat → /v1/chat
        $path = preg_replace('#/{2,}#', '/', $path);
        // 去掉尾部斜杠，统一再拼接
        $path = rtrim($path, '/');

        if ($path === '' || strtolower($path) === '/') {
            $path = '';
        }

        if (!preg_match('#/chat/completions$#i', $path)) {
            $path .= '/chat/completions';
        }

        // 归一后确保只有单个前导斜杠
        return $origin . '/' . ltrim($path, '/') . $suffix;
    }

    /**
     * 通用 JSON POST（供 AI 与 SMTP 测试复用）。
     */
    public function postJson($url, $body, $bearer = '', $timeout = 15)
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl_missing'];
        }

        $ch = curl_init($url);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        if ($bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $result = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            return ['ok' => false, 'error' => $error !== '' ? $error : 'request_failed'];
        }

        if ($code >= 400) {
            return ['ok' => false, 'error' => 'http_' . $code, 'body' => $result];
        }

        return ['ok' => true, 'body' => (string) $result, 'code' => $code];
    }

    protected function log($message, $type = 'INFO')
    {
        if ($this->functions) {
            $this->functions->logMessage('[AiService] ' . $message, $type);
        }
    }
}

/* ==========================================================================
 * 4. 分类引擎
 * ========================================================================== */

class CategoryEngine extends BaseService
{
    /** @var int 当前用户 id（0 = 访客），用于分组隔离与私密可见性判断 */
    protected $userId = 0;

    /**
     * 设置当前用户 id，影响 all() / nameToId() / ensure() 的可见范围。
     */
    public function setUserId($userId)
    {
        $this->userId = (int) $userId;
    }

    /**
     * 后台管理专用：返回全部分类（含所有用户的私密分组），附带所属用户名。
     */
    public function allAdmin($withCounts = false)
    {
        $countCounts = $withCounts ? ', (SELECT COUNT(*) FROM nav_links nl
                          WHERE nl.category_id = c.id AND nl.is_active = 1 AND nl.deleted_at IS NULL) AS link_count' : '';

        try {
            $sql = 'SELECT c.id, c.parent_id, c.name, c.icon, c.sort_order, c.remark, c.user_id, c.is_private,
                           u.username AS owner_name'
                . $countCounts
                . ' FROM categories c'
                . ' LEFT JOIN users u ON c.user_id = u.id'
                . ' ORDER BY c.user_id, c.sort_order, c.id';

            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (Throwable $error) {
            // 老库无 user_id / is_private 列时退回旧结构
            $legacyCounts = $withCounts ? ', (SELECT COUNT(*) FROM nav_links nl
                          WHERE nl.category_id = c.id AND nl.is_active = 1) AS link_count' : '';

            $sql = 'SELECT c.id, NULL AS parent_id, c.name, NULL AS icon, c.sort_order, NULL AS remark,
                           0 AS user_id, 0 AS is_private, NULL AS owner_name'
                . $legacyCounts
                . ' FROM categories c ORDER BY c.sort_order, c.id';

            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            return $stmt->fetchAll();
        }
    }

    /**
    /**
     * 关键词 → 分类名 的默认规则（仅用于自动归类建议，命中优先级从上到下）。
     */
    protected $rules = [
        '开发' => ['github', 'gitlab', 'gitee', 'stackoverflow', 'npm', 'composer', 'php', 'python', 'java',
            'docker', 'kubernetes', '代码', '编程', 'api', '开发者', 'csdn', '掘金', '开源', 'sdk', 'ide',
            'vscode', '掘金', 'segmentfault'],
        '设计' => ['设计', 'dribbble', 'behance', 'figma', 'sketch', 'ui', 'ux', '配色', '图标', 'icon',
            '字体', 'font', '素材', 'png', 'unsplash', '视觉'],
        'AI工具' => ['ai', '人工智能', 'chatgpt', 'openai', 'claude', 'midjourney', 'stable diffusion',
            '大模型', '智能', 'aigc', 'prompt', '豆包', '文心'],
        '影音娱乐' => ['视频', '电影', '音乐', 'bilibili', '哔哩哔哩', 'youtube', 'netflix', '优酷', '爱奇艺',
            '腾讯视频', 'spotify', '动漫', '直播', '弹幕'],
        '学习教育' => ['学习', '教程', '课程', '文档', '百科', '维基', 'wiki', '知乎', 'mooc', '大学',
            '考试', '题库', '翻译', '词典', '教育'],
        '购物' => ['购物', '淘宝', '天猫', '京东', '拼多多', '亚马逊', 'amazon', '商城', '电商', '优惠',
            '比价', '闲鱼'],
        '资讯' => ['新闻', '资讯', '科技', '媒体', '博客', '专栏', '头条', '日报', '周刊', '论坛'],
        '社交' => ['社交', '微博', 'twitter', 'facebook', 'instagram', '小红书', '抖音', '贴吧', '聊天'],
        '效率工具' => ['工具', '效率', '在线', '转换', '压缩', '格式化', '生成器', '工具箱', '网盘',
            '有道', '印象笔记', 'notion', '办公'],
        '金融财经' => ['股票', '基金', '财经', '金融', '银行', '支付', '汇率', '理财', '证券'],
        '生活服务' => ['生活', '外卖', '美团', '饿了么', '旅游', '酒店', '机票', '地图', '天气', '快递'],
        '游戏' => ['游戏', 'steam', '游戏攻略', '电竞', '手游', '主机'],
    ];

    /**
     * 全部分类（含层级信息）。
     *
     * @param int $userId 当前用户 id（0 = 访客）。
     *   - 访客：仅可见公共分组（user_id = 0）且非私密（is_private = 0）
     *   - 登录用户：可见公共分组 + 自己创建的分组（含私密）
     * @param bool $withCounts 是否附带链接数量
     * @return array
     */
    public function all($userId = null, $withCounts = false)
    {
        $userId = $userId === null ? (int) $this->userId : (int) $userId;
        $countCounts = $withCounts ? ', (SELECT COUNT(*) FROM nav_links nl
                          WHERE nl.category_id = c.id AND nl.is_active = 1 AND nl.deleted_at IS NULL) AS link_count' : '';

        // 可见性条件：
        //   访客：user_id = 0 AND is_private = 0
        //   登录用户：(user_id = 0 AND is_private = 0) OR user_id = ?
        $where = $userId > 0
            ? ' WHERE (c.user_id = 0 AND c.is_private = 0) OR c.user_id = ?'
            : ' WHERE c.user_id = 0 AND c.is_private = 0';

        try {
            $sql = 'SELECT c.id, c.parent_id, c.name, c.icon, c.sort_order, c.remark, c.user_id, c.is_private'
                . $countCounts
                . ' FROM categories c'
                . $where
                . ' ORDER BY c.sort_order, c.id';

            $stmt = $this->db->prepare($sql);

            if ($userId > 0) {
                $stmt->execute([$userId]);
            } else {
                $stmt->execute();
            }

            return $stmt->fetchAll();
        } catch (Throwable $error) {
            // 老库尚无 user_id / is_private 列时退回旧结构（全部分类）
            $legacyCounts = $withCounts ? ', (SELECT COUNT(*) FROM nav_links nl
                          WHERE nl.category_id = c.id AND nl.is_active = 1) AS link_count' : '';

            $sql = 'SELECT c.id, NULL AS parent_id, c.name, NULL AS icon, c.sort_order, NULL AS remark, 0 AS user_id, 0 AS is_private'
                . $legacyCounts
                . ' FROM categories c ORDER BY c.sort_order, c.id';

            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            return $stmt->fetchAll();
        }
    }

    public function nameToId($userId = null)
    {
        $map = [];

        foreach ($this->all($userId) as $row) {
            $map[(string) $row['name']] = (int) $row['id'];
        }

        return $map;
    }

    public function findByName($name, $userId = null)
    {
        $userId = $userId === null ? (int) $this->userId : (int) $userId;
        $stmt = $this->db->prepare('SELECT id, name FROM categories WHERE name = ? AND user_id = ? LIMIT 1');
        $stmt->execute([trim((string) $name), $userId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * 分类不存在时创建（用于书签文件夹导入）。
     * 分类归属当前用户（$this->userId），仅在该用户的分组内查重。
     */
    public function ensure($name, $icon = '', $parentId = null, $userId = null)
    {
        $userId = $userId === null ? (int) $this->userId : (int) $userId;
        $name = trim((string) $name);

        if ($name === '') {
            $name = '未分类';
        }

        $name = $this->textCut($name, 50);

        $existing = $this->findByName($name, $userId);

        if ($existing) {
            return (int) $existing['id'];
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO categories (parent_id, name, icon, sort_order, user_id, is_private, created_at) 
                 VALUES (?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM (SELECT sort_order FROM categories) t), ?, 0, NOW())'
            );

            $stmt->execute([$parentId ? (int) $parentId : null, $name, (string) $icon, $userId]);
        } catch (Throwable $error) {
            // 老库无 user_id / is_private 列时退回旧写法
            $stmt = $this->db->prepare(
                'INSERT INTO categories (parent_id, name, icon, sort_order, created_at) 
                 VALUES (?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM (SELECT sort_order FROM categories) t), NOW())'
            );
            $stmt->execute([$parentId ? (int) $parentId : null, $name, (string) $icon]);
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * 在候选分类名里做关键词匹配，返回命中的分类名。
     */
    public function matchName($text, array $candidates = [])
    {
        $text = $this->textLower($text);

        if ($text === '') {
            return null;
        }

        $names = $candidates ?: array_values($this->nameToId());

        foreach ($this->rules as $category => $keywords) {
            if ($candidates && !in_array($category, $names, true)) {
                continue;
            }

            foreach ($keywords as $keyword) {
                if ($this->textHas($text, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * 模糊匹配：返回最可能属于的候选分类名，找不到返回 null。
     *
     * 与 matchName 的差别在于「不要求规则键与分类名精确相等」。
     * 站内实际分类名常带后缀或空格差异（开发编程 / AI 工具 / 学习成长），
     * 而规则表里写的是 开发 / AI工具 / 学习，精确相等会全部落空，
     * 于是推荐永远返回 null。这里改成双向包含判定：
     *   规则键「包含于」分类名，或分类名「包含于」规则键，都算同一类。
     *
     * 优先级：
     *   1. 分类名本身就出现在待匹配文本里（最可靠）
     *   2. 规则键与分类名同源，且该类的关键词命中文本
     *
     * @param string $text       待匹配文本（标题 + 描述 + 域名）
     * @param array  $candidates 候选分类名列表
     * @return string|null
     */
    public function smartMatch($text, array $candidates = [])
    {
        $names = $candidates ?: array_values($this->nameToId());
        $names = array_values(array_filter($names, static function ($name) {
            return trim((string) $name) !== '';
        }));

        if (!$names) {
            return null;
        }

        $haystack = $this->textLower($text);

        if ($haystack === '') {
            return null;
        }

        // 域名里的 . - _ / 换成空格，让 this-domain-example.com 变成
        // this domain example com 这样的词序列，便于按「词」判断短关键词。
        $haystack = (string) preg_replace('/[.\-_\/:@]+/u', ' ', $haystack);
        $haystack = (string) preg_replace('/\s{2,}/u', ' ', $haystack);

        // 归一化：去空白 + 转小写，让「AI 工具」与「AI工具」视为同一个词
        $normalize = static function ($value) {
            $value = (string) $value;
            $value = preg_replace('/\s+/u', '', $value);

            return function_exists('mb_strtolower') ? mb_strtolower((string) $value, 'UTF-8') : strtolower((string) $value);
        };

        /**
         * 关键词是否命中。
         *
         * 短英文词（≤4 个字符）必须按「词」而非裸子串判断：
         * 裸子串会让 ai 命中 dom-ai-n、ui 命中 b-ui-ld、ide 命中 ide-a，
         * 于是随便一个域名都能被塞进「AI 工具」。
         * 规则是：前后不能紧邻字母数字，或者这个词正好是某个 token 的开头
         * （域名 npmjs.com 拆出的 npmjs 以 npm 开头，应当算命中）。
         */
        $keywordHit = function ($haystack, $keyword) {
            $keyword = (string) $keyword;

            if ($keyword === '') {
                return false;
            }

            if (!preg_match('/^[a-z0-9]{1,4}$/', $keyword)) {
                return $this->textHas($haystack, $keyword);
            }

            if (preg_match('/(?<![a-z0-9])' . preg_quote($keyword, '/') . '(?![a-z0-9])/', $haystack)) {
                return true;
            }

            // 允许作为 token 前缀（npm → npmjs）
            foreach (explode(' ', $haystack) as $token) {
                if ($token !== '' && strpos($token, $keyword) === 0) {
                    return true;
                }
            }

            return false;
        };

        $haystackKey = $normalize($haystack);

        // 第 1 轮：分类名原样出现在文本里
        foreach ($names as $name) {
            $key = $normalize($name);

            // 单字分类名太容易误命中（如「设计」只占 2 字尚可，「图」就过宽），
            // 这里要求有效长度 >= 2 才参与直接匹配
            if ($key !== '' && mb_strlen($key, 'UTF-8') >= 2 && mb_strpos($haystackKey, $key, 0, 'UTF-8') !== false) {
                return (string) $name;
            }
        }

        // 第 2 轮：规则键与分类名同源，再看关键词
        //
        // 这里用「命中计数」而不是「首个命中即返回」：
        // 规则表是按顺序遍历的，先到的类别会把后到的全压掉。
        // 比如 Figma 的描述里既有 code 也有 design，按顺序「开发」在前，
        // 结果被归进开发编程；按命中次数算，design 更多，就能正确落到设计素材。
        $bestName = null;
        $bestScore = 0;

        foreach ($this->rules as $ruleKey => $keywords) {
            $ruleNorm = $normalize($ruleKey);

            $matchedName = null;

            foreach ($names as $name) {
                $nameNorm = $normalize($name);

                if ($nameNorm === '') {
                    continue;
                }

                $sameFamily = $nameNorm === $ruleNorm
                    || mb_strpos($nameNorm, $ruleNorm, 0, 'UTF-8') !== false
                    || mb_strpos($ruleNorm, $nameNorm, 0, 'UTF-8') !== false;

                if ($sameFamily) {
                    $matchedName = (string) $name;
                    break;
                }
            }

            if ($matchedName === null) {
                continue;
            }

            $score = 0;

            foreach ($keywords as $keyword) {
                if ($keywordHit($haystack, $keyword)) {
                    $score++;
                }
            }

            // 严格大于：分数相同时保留规则表里靠前的类别，结果稳定可预期
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestName = $matchedName;
            }
        }

        return $bestName;
    }
}

/* ==========================================================================
 * 5. 健康检测
 * ========================================================================== */

class HealthCheckService extends BaseService
{
    /** 延迟超过该值记为 slow */
    const SLOW_THRESHOLD_MS = 3000;

    /**
     * 检测单个 URL。
     *
     * @return array{status:string,latency_ms:int,http_code:int}
     */
    public function checkUrl($url, $timeout = 6)
    {
        $url = trim((string) $url);

        if ($url === '' || !$this->functions->validateUrl($url)) {
            return ['status' => 'down', 'latency_ms' => 0, 'http_code' => 0, 'error' => 'invalid_url'];
        }

        if (!function_exists('curl_init')) {
            return ['status' => 'unknown', 'latency_ms' => 0, 'http_code' => 0, 'error' => 'curl_missing'];
        }

        $start = microtime(true);
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => (int) $timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; XuanyuNav/2.0; +health-check)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // 部分站点拒绝 HEAD 请求，退回 GET 再试一次。
        if ($code === 0 || $code === 405 || $code === 403) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => (int) $timeout,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; XuanyuNav/2.0; +health-check)',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_RANGE => '0-2048',
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
        }

        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($code >= 200 && $code < 400) {
            return [
                'status' => $latency > self::SLOW_THRESHOLD_MS ? 'slow' : 'ok',
                'latency_ms' => $latency,
                'http_code' => $code,
            ];
        }

        return [
            'status' => 'down',
            'latency_ms' => $latency,
            'http_code' => $code,
            'error' => $error,
        ];
    }

    /**
     * 检测单条记录并落库。
     */
    public function checkLink($id)
    {
        $id = (int) $id;
        $stmt = $this->db->prepare('SELECT id, name, url FROM nav_links WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $link = $stmt->fetch();

        if (!$link) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $result = $this->checkUrl($link['url']);
        $this->saveResult($id, $result);

        return array_merge(['ok' => true, 'id' => $id, 'name' => $link['name'], 'url' => $link['url']], $result);
    }

    /**
     * 全量检测（$userId = 0 表示公共导航）。
     *
     * @return array{ok:bool,total:int,ok_count:int,slow:int,down:int,items:array}
     */
    public function checkAll($userId = 0, $limit = 0)
    {
        $userId = (int) $userId;
        $sql = 'SELECT id, name, url FROM nav_links WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY id';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $links = $stmt->fetchAll();

        $summary = ['ok' => true, 'total' => 0, 'ok_count' => 0, 'slow' => 0, 'down' => 0, 'items' => []];

        foreach ($links as $link) {
            $result = $this->checkUrl($link['url']);
            $this->saveResult((int) $link['id'], $result);

            $summary['total']++;

            if ($result['status'] === 'ok') {
                $summary['ok_count']++;
            } elseif ($result['status'] === 'slow') {
                $summary['slow']++;
            } else {
                $summary['down']++;
            }

            $summary['items'][] = array_merge([
                'id' => (int) $link['id'],
                'name' => $link['name'],
                'url' => $link['url'],
            ], $result);
        }

        return $summary;
    }

    public function saveResult($id, array $result)
    {
        $stmt = $this->db->prepare(
            'UPDATE nav_links SET check_status = ?, latency_ms = ?, last_checked_at = NOW() WHERE id = ?'
        );

        return $stmt->execute([
            in_array($result['status'], ['ok', 'slow', 'down'], true) ? $result['status'] : 'unknown',
            isset($result['latency_ms']) ? (int) $result['latency_ms'] : null,
            (int) $id,
        ]);
    }

    /**
     * 健康状态四类计数（统计中心用）。
     */
    public function summary($userId = 0)
    {
        $stmt = $this->db->prepare(
            "SELECT check_status, COUNT(*) AS total FROM nav_links
             WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL
             GROUP BY check_status"
        );
        $stmt->execute([(int) $userId]);

        $result = ['ok' => 0, 'slow' => 0, 'down' => 0, 'unknown' => 0];

        foreach ($stmt->fetchAll() as $row) {
            $status = (string) $row['check_status'];

            if (isset($result[$status])) {
                $result[$status] = (int) $row['total'];
            }
        }

        return $result;
    }

    /**
     * 失效链接列表（告警邮件用）。
     */
    public function downLinks($userId = 0, $limit = 50)
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, url, latency_ms, last_checked_at FROM nav_links
             WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL AND check_status = 'down'
             ORDER BY id LIMIT ?"
        );
        $stmt->bindValue(1, (int) $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, (int) $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}

/* ==========================================================================
 * 6. 书签解析与导入
 * ========================================================================== */

class BookmarkService extends BaseService
{
    /**
     * 解析浏览器导出的书签 HTML（NETSCAPE-Bookmark 格式）。
     *
     * @return array{items:array,folders:array}
     */
    public function parse($html)
    {
        $html = (string) $html;
        $items = [];
        $folders = [];

        if ($html === '' || stripos($html, '<') === false) {
            return ['items' => $items, 'folders' => $folders];
        }

        // 按标签出现顺序扫描，而不是按行解析：
        // 不同导出器会把 <DL>、<H3>、<A> 挤在同一行（甚至整份文件不换行），
        // 逐行处理会漏掉与 <DL> 同行的书签，因此这里直接对标签序列做匹配。
        $pattern = '/<H3[^>]*>(.*?)<\/H3>'                    // 捕获组 1：文件夹名
            . '|<DL\b[^>]*>'                                  // 进入目录
            . '|<\/DL\s*>'                                    // 退出目录
            . '|<A\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/A>/is'; // 捕获组 2/3：链接与标题

        if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            return ['items' => $items, 'folders' => $folders];
        }

        $stack = [];
        $pendingFolderName = null;

        foreach ($matches as $match) {
            $token = (string) $match[0];

            // 1) 文件夹标题：先记住，等紧邻的 <DL> 出现后再入栈。
            if (isset($match[1]) && $match[1] !== '') {
                $pendingFolderName = $this->decode($match[1]);

                continue;
            }

            // 2) 进入子目录。
            if (strncasecmp($token, '<DL', 3) === 0) {
                if ($pendingFolderName !== null) {
                    $stack[] = $pendingFolderName;
                    $folders[$pendingFolderName] = true;
                    $pendingFolderName = null;
                }

                continue;
            }

            // 3) 退出目录。
            if (strncasecmp($token, '</DL', 4) === 0) {
                array_pop($stack);
                $pendingFolderName = null;

                continue;
            }

            // 4) 书签条目。
            $url = html_entity_decode((string) ($match[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = $this->decode((string) ($match[3] ?? ''));

            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                continue;
            }

            $folder = $stack ? end($stack) : '';

            if ($folder !== '') {
                $folders[$folder] = true;
            }

            $item = [
                'name' => $title !== '' ? $title : $url,
                'url' => $url,
                'folder' => $folder,
            ];

            // 部分导出器会附带 ICON 属性，可作为初始图标。
            if (preg_match('/\bICON\s*=\s*["\']([^"\']+)["\']/i', $token, $iconMatch)) {
                $icon = html_entity_decode($iconMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // data: URI 体积大且无实际展示价值，直接忽略。
                if (stripos($icon, 'data:') !== 0) {
                    $item['logo'] = $icon;
                }
            }

            $items[] = $item;
        }

        return ['items' => $items, 'folders' => array_keys($folders)];
    }

    protected function decode($text)
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $normalized = preg_replace('/\s+/u', ' ', $text);

        return trim(is_string($normalized) ? $normalized : $text);
    }

    /**
     * 导入书签到指定用户的私有导航。
     *
     * @param int   $userId
     * @param array $items   parse() 返回的 items
     * @return array{ok:bool,imported:int,skipped:int,categories:int}
     */
    public function import($userId, array $items)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'login_required', 'imported' => 0, 'skipped' => 0, 'categories' => 0];
        }

        $engine = new CategoryEngine($this->functions);
        // v2.14.0：导入书签 / 导入分享时自动创建的分组必须归属导入者
        $engine->setUserId($userId);
        $nameToId = $engine->nameToId();

        // 已有 URL 去重
        $stmt = $this->db->prepare('SELECT url FROM nav_links WHERE user_id = ?');
        $stmt->execute([$userId]);
        $existing = [];

        foreach ($stmt->fetchAll() as $row) {
            $existing[rtrim(strtolower((string) $row['url']), '/')] = true;
        }

        $imported = 0;
        $skipped = 0;
        $createdCategories = 0;

        $sortStmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM nav_links WHERE user_id = ?');
        $sortStmt->execute([$userId]);
        $sort = (int) ($sortStmt->fetch()['max_sort'] ?? 0);

        $insert = $this->db->prepare(
            'INSERT INTO nav_links (name, url, logo, description, category_id, user_id, sort_order, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())'
        );

        foreach ($items as $item) {
            $url = trim((string) ($item['url'] ?? ''));
            $key = rtrim(strtolower($url), '/');

            if ($url === '' || isset($existing[$key])) {
                $skipped++;

                continue;
            }

            $folder = trim((string) ($item['folder'] ?? ''));
            $categoryId = 0;

            if ($folder !== '') {
                if (isset($nameToId[$folder])) {
                    $categoryId = $nameToId[$folder];
                } else {
                    $categoryId = $engine->ensure($folder, 'bi-folder');
                    $nameToId[$folder] = $categoryId;
                    $createdCategories++;
                }
            } else {
                // 没有文件夹时按站点信息做一次关键词猜测。
                $guess = $engine->matchName((string) ($item['name'] ?? '') . ' ' . $url, array_keys($nameToId));

                if ($guess !== null && isset($nameToId[$guess])) {
                    $categoryId = $nameToId[$guess];
                }
            }

            if ($categoryId <= 0) {
                $categoryId = $engine->ensure('未分类', 'bi-collection');
                $nameToId['未分类'] = $categoryId;
            }

            $sort++;
            $insert->execute([
                $this->textCut((string) ($item['name'] ?? $url), 100),
                $this->textCut($url, 500),
                (string) ($item['logo'] ?? ''),
                '',
                $categoryId,
                $userId,
                $sort,
            ]);

            $existing[$key] = true;
            $imported++;
        }

        return [
            'ok' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'categories' => $createdCategories,
        ];
    }
}

/* ==========================================================================
 * 7. 分享服务
 * ========================================================================== */

class ShareService extends BaseService
{
    /**
     * 创建分享链接。
     *
     * @return array{ok:bool,token?:string,url?:string,count?:int,error?:string}
     */
    public function create($userId, array $siteIds, $title = '')
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'login_required'];
        }

        $ids = [];

        foreach ($siteIds as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if (!$ids) {
            return ['ok' => false, 'error' => 'empty_selection'];
        }

        if (count($ids) > 500) {
            return ['ok' => false, 'error' => 'too_many'];
        }

        // 只允许分享自己可见的条目（自己私有 或 公共）。
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id FROM nav_links
             WHERE id IN ($placeholders) AND deleted_at IS NULL AND (user_id = ? OR user_id = 0)"
        );
        $stmt->execute(array_merge($ids, [$userId]));
        $allowed = [];

        foreach ($stmt->fetchAll() as $row) {
            $allowed[] = (int) $row['id'];
        }

        if (!$allowed) {
            return ['ok' => false, 'error' => 'no_permission'];
        }

        $token = bin2hex(random_bytes(16));
        $title = trim((string) $title);

        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 200, 'UTF-8');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO shares (token, user_id, title, site_ids, views, created_at) VALUES (?, ?, ?, ?, 0, NOW())'
        );
        $stmt->execute([$token, $userId, $title, json_encode($allowed)]);

        return [
            'ok' => true,
            'token' => $token,
            'url' => 'share.php?token=' . $token,
            'count' => count($allowed),
        ];
    }

    /**
     * 按 token 取分享记录。
     * 老库可能还没有 shares 表（未执行 v2 升级脚本），此时返回 null 而不是抛异常。
     */
    public function findByToken($token)
    {
        $token = trim((string) $token);

        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        try {
            $stmt = $this->db->prepare('SELECT * FROM shares WHERE token = ? LIMIT 1');
            $stmt->execute([$token]);
            $share = $stmt->fetch();

            return $share ?: null;
        } catch (Throwable $error) {
            return null;
        }
    }

    /**
     * 取分享包含的站点详情。
     */
    public function sitesOf(array $share)
    {
        $ids = json_decode((string) $share['site_ids'], true);

        if (!is_array($ids) || !$ids) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT nl.id, nl.name, nl.url, nl.logo, nl.description, nl.clicks,
                    nl.check_status, nl.latency_ms, c.name AS category_name
             FROM nav_links nl
             LEFT JOIN categories c ON nl.category_id = c.id
             WHERE nl.id IN ($placeholders) AND nl.deleted_at IS NULL
             ORDER BY nl.sort_order, nl.id"
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    public function incrementViews($shareId)
    {
        $stmt = $this->db->prepare('UPDATE shares SET views = views + 1 WHERE id = ?');

        return $stmt->execute([(int) $shareId]);
    }

    /**
     * 把分享内容导入到当前用户导航（按 URL 去重）。
     *
     * @return array{ok:bool,imported?:int,skipped?:int,error?:string}
     */
    public function import($userId, $token)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'login_required'];
        }

        $share = $this->findByToken($token);

        if (!$share) {
            return ['ok' => false, 'error' => 'share_not_found'];
        }

        $sites = $this->sitesOf($share);

        if (!$sites) {
            return ['ok' => true, 'imported' => 0, 'skipped' => 0];
        }

        $engine = new CategoryEngine($this->functions);
        // v2.14.0：导入书签 / 导入分享时自动创建的分组必须归属导入者
        $engine->setUserId($userId);
        $nameToId = $engine->nameToId();

        $stmt = $this->db->prepare('SELECT url FROM nav_links WHERE user_id = ?');
        $stmt->execute([$userId]);
        $existing = [];

        foreach ($stmt->fetchAll() as $row) {
            $existing[rtrim(strtolower((string) $row['url']), '/')] = true;
        }

        $sortStmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM nav_links WHERE user_id = ?');
        $sortStmt->execute([$userId]);
        $sort = (int) ($sortStmt->fetch()['max_sort'] ?? 0);

        $insert = $this->db->prepare(
            'INSERT INTO nav_links (name, url, logo, description, category_id, user_id, sort_order, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())'
        );

        $imported = 0;
        $skipped = 0;

        foreach ($sites as $site) {
            $url = (string) $site['url'];
            $key = rtrim(strtolower($url), '/');

            if (isset($existing[$key])) {
                $skipped++;

                continue;
            }

            $categoryName = (string) ($site['category_name'] ?? '');
            $categoryId = 0;

            if ($categoryName !== '') {
                if (isset($nameToId[$categoryName])) {
                    $categoryId = $nameToId[$categoryName];
                } else {
                    $categoryId = $engine->ensure($categoryName, 'bi-folder');
                    $nameToId[$categoryName] = $categoryId;
                }
            }

            if ($categoryId <= 0) {
                $categoryId = $engine->ensure('未分类', 'bi-collection');
                $nameToId['未分类'] = $categoryId;
            }

            $sort++;
            $insert->execute([
                (string) $site['name'],
                $url,
                (string) $site['logo'],
                (string) ($site['description'] ?? ''),
                $categoryId,
                $userId,
                $sort,
            ]);

            $existing[$key] = true;
            $imported++;
        }

        return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * 用户在后台查看自己创建的分享。
     */
    public function listByUser($userId, $limit = 50)
    {
        $stmt = $this->db->prepare(
            'SELECT id, token, title, site_ids, views, created_at FROM shares
             WHERE user_id = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, (int) $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, (int) $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function delete($id, $userId = 0)
    {
        $id = (int) $id;

        if ($userId > 0) {
            $stmt = $this->db->prepare('DELETE FROM shares WHERE id = ? AND user_id = ?');

            return $stmt->execute([$id, (int) $userId]);
        }

        $stmt = $this->db->prepare('DELETE FROM shares WHERE id = ?');

        return $stmt->execute([$id]);
    }
}

/* ==========================================================================
 * 9. 邮件服务
 *    配置沿用项目既有约定：项目根目录 config/smtp.php 返回配置数组。
 *    未配置或 enabled=false 时静默跳过，不影响主流程。
 * ========================================================================== */

class MailService extends BaseService
{
    /** @var array|null */
    protected $config = null;

    public function config()
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $file = dirname(__DIR__) . '/config/smtp.php';
        $config = [];

        if (is_file($file)) {
            $loaded = require $file;

            if (is_array($loaded)) {
                $config = $loaded;
            }
        }

        $this->config = $config;

        return $config;
    }

    /**
     * 配置齐全且显式开启时才认为可用。
     */
    public function enabled()
    {
        $config = $this->config();

        return !empty($config['enabled'])
            && !empty($config['smtp']['host'])
            && !empty($config['from']['address']);
    }

    public function adminEmail()
    {
        $config = $this->config();

        return trim((string) ($config['admin_email'] ?? ''));
    }

    /**
     * 发送 HTML 邮件。
     *
     * @return array{ok:bool,error?:string}
     */
    public function send($to, $subject, $html, $text = '')
    {
        $to = trim((string) $to);

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'invalid_recipient'];
        }

        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'mail_disabled'];
        }

        $root = dirname(__DIR__);
        $src = $root . '/vendor/PHPMailer/src';

        if (is_file($src . '/PHPMailer.php')) {
            require_once $src . '/Exception.php';
            require_once $src . '/PHPMailer.php';
            require_once $src . '/SMTP.php';
        }

        $class = 'PHPMailer\\PHPMailer\\PHPMailer';

        if (!class_exists($class)) {
            $this->log('PHPMailer 不可用，邮件未发送', 'WARNING');

            return ['ok' => false, 'error' => 'phpmailer_missing'];
        }

        $config = $this->config();
        $smtp = $config['smtp'];
        $from = $config['from'];

        try {
            /** @var object $mail */
            $mail = new $class(true);
            $mail->isSMTP();
            $mail->Host = (string) $smtp['host'];
            $mail->Port = (int) $smtp['port'];
            $mail->SMTPAuth = !empty($smtp['auth']);
            $mail->Username = (string) ($smtp['username'] ?? '');
            $mail->Password = (string) ($smtp['password'] ?? '');
            $mail->SMTPSecure = (string) ($smtp['encryption'] ?? '');
            $mail->Timeout = (int) ($smtp['timeout'] ?? 30);
            $mail->CharSet = (string) ($config['charset'] ?? 'UTF-8');

            if (!empty($smtp['debug'])) {
                $mail->SMTPDebug = (int) $smtp['debug'];
            }

            $mail->setFrom((string) $from['address'], (string) ($from['name'] ?? ''));
            $mail->addAddress($to);
            $mail->Subject = (string) ($config['subject_prefix'] ?? '') . (string) $subject;
            $mail->isHTML(true);
            $mail->Body = (string) $html;
            $mail->AltBody = $text !== '' ? (string) $text : strip_tags((string) $html);

            $mail->send();

            return ['ok' => true];
        } catch (Throwable $error) {
            $this->log('邮件发送失败: ' . $error->getMessage(), 'ERROR');

            return ['ok' => false, 'error' => 'send_failed'];
        }
    }

    /**
     * 发送纯文本通知（内部封装，失败仅记录日志）。
     */
    public function notifyAdmin($subject, $body)
    {
        if (!$this->enabled()) {
            return false;
        }

        $to = $this->adminEmail();

        if ($to === '') {
            return false;
        }

        return $this->send($to, $subject, nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), $body)['ok'];
    }

    protected function log($message, $type = 'INFO')
    {
        if ($this->functions) {
            $this->functions->logMessage('[MailService] ' . $message, $type);
        }
    }
}

/* ==========================================================================
 * 8. 备份服务
 * ========================================================================== */

class BackupService extends BaseService
{
    /** 参与备份的表 */
    protected $tables = [
        'categories', 'nav_links', 'settings', 'users',
        'applications', 'shares', 'api_tokens', 'contact_messages',
    ];

    /**
     * 导出为 JSON（结构 + 数据）。
     *
     * 用户密码哈希与 API 令牌按原值导出，这样「导入恢复」才能完整还原账号与令牌；
     * 备份文件因此含有敏感信息，请勿放在可公开访问的目录，也不要随意传播。
     * 仅 AI 接口密钥仍不导出，避免一份备份外泄即可直接调用付费接口。
     */
    public function exportJson($includeUsers = true)
    {
        $dump = [
            'meta' => [
                'version' => defined('APP_VERSION') ? APP_VERSION : '2.0.0',
                'exported_at' => date('Y-m-d H:i:s'),
                'site' => $_SERVER['HTTP_HOST'] ?? '',
                'contains_secrets' => true,
            ],
            'tables' => [],
        ];

        foreach ($this->tables as $table) {
            if ($table === 'users' && !$includeUsers) {
                continue;
            }

            if ($table === 'settings') {
                // settings 是键值表，需要按 key 过滤行（不是按列名）：
                // 这里的 ai_api_key 是 key 的取值，表中并没有同名的列。
                $dump['tables'][$table] = $this->rows($table, [], ['ai_api_key']);
                continue;
            }

            $dump['tables'][$table] = $this->rows($table, []);
        }

        return json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * 读取整表数据。
     *
     * @param array $excludeColumns 需要剔除的列名
     * @param array $excludeKeys    需要剔除的行（按 `key` 列取值匹配，仅 settings 这类键值表使用）
     */
    protected function rows($table, array $excludeColumns, array $excludeKeys = [])
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        $stmt = $this->db->query('SELECT * FROM `' . $table . '`');
        $rows = $stmt->fetchAll();

        if (!$excludeColumns && !$excludeKeys) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            if ($excludeKeys && array_key_exists('key', $row) && in_array((string) $row['key'], $excludeKeys, true)) {
                unset($rows[$index]);
                continue;
            }

            foreach ($excludeColumns as $column) {
                unset($rows[$index][$column]);
            }
        }

        // 剔除后重新索引，保证 JSON 里是数组而不是对象
        return array_values($rows);
    }

    public function tableExists($table)
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([(string) $table]);

        return (int) ($stmt->fetch()['total'] ?? 0) > 0;
    }

    /**
     * 导入 JSON 备份（合并模式：按主键覆盖，不删除现有数据）。
     *
     * @return array{ok:bool,error?:string,restored:array}
     */
    public function importJson($json, $onlyTables = [])
    {
        $data = json_decode((string) $json, true);

        if (!is_array($data) || !isset($data['tables']) || !is_array($data['tables'])) {
            return ['ok' => false, 'error' => 'invalid_format', 'restored' => []];
        }

        $restored = [];

        try {
            $this->db->beginTransaction();

            foreach ($data['tables'] as $table => $rows) {
                if (!in_array($table, $this->tables, true) || !is_array($rows)) {
                    continue;
                }

                if ($onlyTables && !in_array($table, $onlyTables, true)) {
                    continue;
                }

                if (!$this->tableExists($table)) {
                    continue;
                }

                $count = $this->restoreTable($table, $rows);
                $restored[$table] = $count;
            }

            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->log('备份导入失败: ' . $error->getMessage(), 'ERROR');

            return ['ok' => false, 'error' => 'restore_failed', 'restored' => $restored];
        }

        return ['ok' => true, 'restored' => $restored];
    }

    /**
     * 读取表的列元信息（列名 → 是否可空 / 默认值 / 类型 / 是否自增）。
     */
    protected function columnMeta($table)
    {
        $stmt = $this->db->prepare(
            'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([(string) $table]);

        $meta = [];

        foreach ($stmt->fetchAll() as $row) {
            // MySQL 返回大写键名，个别环境可能小写，两种都兼容
            $name = (string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? '');

            if ($name === '') {
                continue;
            }

            $meta[$name] = [
                'nullable' => strtoupper((string) ($row['IS_NULLABLE'] ?? $row['is_nullable'] ?? 'YES')) === 'YES',
                'default' => $row['COLUMN_DEFAULT'] ?? $row['column_default'] ?? null,
                'type' => strtolower((string) ($row['DATA_TYPE'] ?? $row['data_type'] ?? '')),
                'auto' => stripos((string) ($row['EXTRA'] ?? $row['extra'] ?? ''), 'auto_increment') !== false,
            ];
        }

        return $meta;
    }

    /**
     * 为备份中缺失的 NOT NULL 列生成安全占位值。
     *
     * 正常导出现在会带上 password_hash / token，所以这条分支主要作为兼容兜底：
     * 早期版本的备份文件里这些列是空的，若不补值，MySQL 严格模式会报 1364
     * 「doesn't have a default value」并让整次恢复回滚。
     */
    protected function placeholderFor($column, $type)
    {
        // 用永远无法通过 password_verify 的值占位，避免空密码变成可登录状态
        if ($column === 'password_hash') {
            return '!';
        }

        // 令牌重新随机生成：旧令牌不会因为恢复而凭空生效
        if ($column === 'token') {
            try {
                return bin2hex(random_bytes(32));
            } catch (Throwable $error) {
                return md5(uniqid('', true)) . md5(uniqid('', true));
            }
        }

        if (in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'float', 'double'], true)) {
            return 0;
        }

        return '';
    }

    protected function restoreTable($table, array $rows)
    {
        if (!$rows) {
            return 0;
        }

        $meta = $this->columnMeta($table);

        if (!$meta) {
            return 0;
        }

        $count = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $fields = [];
            $values = [];

            foreach ($meta as $column => $info) {
                if (array_key_exists($column, $row)) {
                    $fields[] = '`' . $column . '`';
                    $values[] = $row[$column];
                    continue;
                }

                // 自增主键缺失时交给数据库生成
                if ($info['auto']) {
                    continue;
                }

                // NOT NULL 且没有默认值的列（兼容早期不含该字段的备份文件）
                if (!$info['nullable'] && $info['default'] === null) {
                    $fields[] = '`' . $column . '`';
                    $values[] = $this->placeholderFor($column, $info['type']);
                }
            }

            if (!$fields) {
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';

            // 有主键时用 ON DUPLICATE KEY UPDATE 做覆盖式合并。
            if (array_key_exists('id', $row)) {
                $updates = [];

                foreach ($fields as $field) {
                    if ($field !== '`id`') {
                        $updates[] = $field . ' = VALUES(' . $field . ')';
                    }
                }

                if ($updates) {
                    $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
                }
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            $count++;
        }

        return $count;
    }

    public function writeFile($content)
    {
        $dir = dirname(__DIR__) . '/backups';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $dir . '/backup-' . date('Ymd-His') . '.json';

        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }

        return @file_put_contents($file, $content, LOCK_EX) !== false ? $file : null;
    }

    public function listFiles($limit = 20)
    {
        $dir = dirname(__DIR__) . '/backups';
        $files = [];

        if (!is_dir($dir)) {
            return $files;
        }

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $files[] = [
                'name' => basename($file),
                'size' => (int) filesize($file),
                'time' => date('Y-m-d H:i:s', (int) filemtime($file)),
            ];
        }

        usort($files, function ($a, $b) {
            return strcmp($b['time'], $a['time']);
        });

        return array_slice($files, 0, max(1, (int) $limit));
    }

    protected function log($message, $type = 'INFO')
    {
        if ($this->functions) {
            $this->functions->logMessage('[BackupService] ' . $message, $type);
        }
    }
}

/* ==========================================================================
 * 10. 联系留言服务
 *     前台联系页与 api/contact.php 共用，保证两条入口的校验规则一致。
 * ========================================================================== */

class ContactService extends BaseService
{
    /** 同一会话两次提交的最小间隔（秒） */
    const SESSION_INTERVAL = 60;

    /** 同一 IP 每日最多提交的条数 */
    const DAILY_LIMIT_PER_IP = 5;

    /** 未填主题时使用的兜底主题（调用方据此判断是否需要在通知标题里带上主题） */
    const DEFAULT_SUBJECT = '来自导航站的留言';

    /**
     * 提交留言。
     *
     * @return array{ok:bool,error?:string}
     */
    public function submit($name, $email, $subject, $message, $ip = '')
    {
        $name = $this->textCut(trim((string) $name), 50);
        $email = trim((string) $email);
        $subject = $this->textCut(trim((string) $subject), 100);
        $message = trim((string) $message);
        $ip = $this->textCut(trim((string) $ip), 45);

        if (!$this->functions || !$this->functions->validateEmail($email)) {
            return ['ok' => false, 'error' => '请填写有效的邮箱地址'];
        }

        if ($name === '') {
            $name = '访客';
        }

        if ($subject === '') {
            $subject = self::DEFAULT_SUBJECT;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);

        if ($length < 5) {
            return ['ok' => false, 'error' => '留言内容太短，请至少写 5 个字'];
        }

        if ($length > 2000) {
            return ['ok' => false, 'error' => '留言内容过长，请控制在 2000 字以内'];
        }

        // 同一会话 60 秒内只允许提交一次
        if (isset($_SESSION['contact_last_at']) && (time() - (int) $_SESSION['contact_last_at']) < self::SESSION_INTERVAL) {
            $wait = self::SESSION_INTERVAL - (time() - (int) $_SESSION['contact_last_at']);

            return ['ok' => false, 'error' => '提交过于频繁，请 ' . max(1, $wait) . ' 秒后再试'];
        }

        if ($ip !== '' && $this->dailyCountByIp($ip) >= self::DAILY_LIMIT_PER_IP) {
            return ['ok' => false, 'error' => '今日留言次数已达上限，请改天再试'];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO contact_messages (name, email, subject, message, ip, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())'
        );
        $stmt->execute([$name, $email, $subject, $message, $ip]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['contact_last_at'] = time();
        }

        // 回传落库后的规范化内容：空主题已兜底、超长已截断。
        // 调用方用它拼通知邮件，避免拿被清空或未净化的原始表单值。
        return [
            'ok' => true,
            'id' => (int) $this->db->lastInsertId(),
            'data' => [
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'message' => $message,
            ],
        ];
    }

    /**
     * 通知邮件标题：用户填了主题就带上，方便收件箱里直接分辨；
     * 兜底主题（未填）不再重复，避免出现「收到新的联系留言：来自导航站的留言」。
     */
    public static function mailSubject(array $data)
    {
        $subject = trim((string) ($data['subject'] ?? ''));

        if ($subject === '' || $subject === self::DEFAULT_SUBJECT) {
            return '收到新的联系留言';
        }

        // 换行会破坏邮件头，先压平再截断
        $flat = str_replace(["\r\n", "\r", "\n"], ' ', $subject);
        $length = function_exists('mb_strlen') ? mb_strlen($flat, 'UTF-8') : strlen($flat);
        $short = function_exists('mb_substr') ? mb_substr($flat, 0, 40, 'UTF-8') : substr($flat, 0, 40);

        return '收到新的联系留言：' . $short . ($length > 40 ? '…' : '');
    }

    /**
     * 通知邮件正文（纯文本，MailService 会转成 HTML）。
     */
    public static function mailBody(array $data)
    {
        return '姓名：' . (string) ($data['name'] ?? '访客') . "\n"
            . '邮箱：' . (string) ($data['email'] ?? '') . "\n"
            . '主题：' . (string) ($data['subject'] ?? '') . "\n"
            . "留言：\n" . (string) ($data['message'] ?? '');
    }

    protected function dailyCountByIp($ip)
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total FROM contact_messages
             WHERE ip = ? AND created_at >= CURDATE()'
        );
        $stmt->execute([$ip]);

        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /**
     * 后台留言列表（分页）。
     */
    public function paginate($page = 1, $size = 20, $unreadOnly = false)
    {
        $page = max(1, (int) $page);
        $size = min(100, max(1, (int) $size));
        $offset = ($page - 1) * $size;
        $where = $unreadOnly ? ' WHERE is_read = 0' : '';

        $countStmt = $this->db->query('SELECT COUNT(*) AS total FROM contact_messages' . $where);
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        $stmt = $this->db->prepare(
            'SELECT id, name, email, subject, message, ip, is_read, created_at
             FROM contact_messages' . $where . ' ORDER BY id DESC LIMIT ?, ?'
        );
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $size, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'pages' => (int) ceil($total / $size),
            'items' => $stmt->fetchAll(),
        ];
    }

    public function countUnread()
    {
        $stmt = $this->db->query('SELECT COUNT(*) AS total FROM contact_messages WHERE is_read = 0');

        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    public function markRead($id, $read = true)
    {
        $stmt = $this->db->prepare('UPDATE contact_messages SET is_read = ? WHERE id = ?');

        return $stmt->execute([$read ? 1 : 0, (int) $id]);
    }

    public function delete($id)
    {
        $stmt = $this->db->prepare('DELETE FROM contact_messages WHERE id = ?');

        return $stmt->execute([(int) $id]);
    }
}
