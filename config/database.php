<?php
/**
 * 轩宇-导航 · 数据库连接
 * 支持环境变量覆盖，便于轮换数据库口令而不改源码：
 *   NAV_DB_HOST / NAV_DB_NAME / NAV_DB_USER / NAV_DB_PASS
 */

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        $localConfig = [];
        $localFile = __DIR__ . '/database.local.php';
        
        if (is_file($localFile)) {
            $loaded = require $localFile;
            if (is_array($loaded)) {
                $localConfig = $loaded;
            }
        }
        
        $host = $localConfig['host'] ?? (getenv('NAV_DB_HOST') ?: '127.0.0.1');
        $dbname = $localConfig['dbname'] ?? (getenv('NAV_DB_NAME') ?: 'admin');
        $username = $localConfig['username'] ?? (getenv('NAV_DB_USER') ?: 'admin');
        $password = $localConfig['password'] ?? (getenv('NAV_DB_PASS') ?: 'admin');
        $port = (int) ($localConfig['port'] ?? 3306);
        
        try {
            $this->connection = new PDO(
                "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                ]
            );
        } catch (PDOException $e) {
            error_log('数据库连接失败: ' . $e->getMessage());
            http_response_code(503);
            die('数据库连接失败，请检查数据库配置。');
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}
