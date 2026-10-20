<?php
/**
 * 轩宇-导航 · 配置管理
 * 仅保留系统、路径、安全、缓存配置；远程更新配置已移除。
 */

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

class ConfigManager {
    private static $instance = null;
    private $config = null;
    private $configFile;
    
    private function __construct() {
        $this->configFile = __DIR__ . '/../config/config.php';
        $this->loadConfig();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function loadConfig() {
        if (is_file($this->configFile)) {
            $config = include $this->configFile;
            if (is_array($config)) {
                $this->config = $config;
                return;
            }
        }
        
        $this->config = $this->getDefaultConfig();
        $this->saveConfig();
    }
    
    private function getDefaultConfig() {
        return [
            'system' => [
                'name' => '轩宇-导航',
                'version' => '2.4.0',
                'debug' => false,
                'maintenance' => false,
                'timezone' => 'Asia/Shanghai',
                'language' => 'zh-CN',
            ],
            'paths' => [
                'uploads' => 'uploads/',
                'logos' => 'uploads/logos/',
                'cache' => 'cache/',
                'logs' => 'logs/',
                'temp' => 'temp/',
                'backup' => 'backups/',
            ],
            'security' => [
                'max_upload_size' => 5 * 1024 * 1024,
                'allowed_file_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'],
                'session_lifetime' => 7200,
                'csrf_protection' => true,
            ],
            'cache' => [
                'enable_cache' => true,
            ],
        ];
    }
    
    private function saveConfig() {
        $configDir = dirname($this->configFile);
        if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
            return false;
        }
        
        $content = "<?php\n"
            . "// 轩宇-导航配置文件\n"
            . "// 自动生成，请勿手动编辑\n"
            . "// 生成时间: " . date('Y-m-d H:i:s') . "\n\n"
            . "return " . var_export($this->config, true) . ";\n";
        
        return file_put_contents($this->configFile, $content, LOCK_EX) !== false;
    }
    
    public function get($key = null, $default = null) {
        if ($key === null) {
            return $this->config;
        }
        
        $keys = explode('.', (string) $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (is_array($value) && array_key_exists($k, $value)) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }
        
        return $value;
    }
    
    public function set($key, $value) {
        $keys = explode('.', (string) $key);
        $temp = &$this->config;
        
        foreach ($keys as $k) {
            if (!isset($temp[$k]) || !is_array($temp[$k])) {
                $temp[$k] = [];
            }
            $temp = &$temp[$k];
        }
        
        $temp = $value;
        
        return $this->saveConfig();
    }
    
    public function getSystemConfig() {
        return $this->get('system', []);
    }
    
    public function getSecurityConfig() {
        return $this->get('security', []);
    }
    
    public function getPathConfig() {
        return $this->get('paths', []);
    }
    
    public function getCacheConfig() {
        return $this->get('cache', []);
    }
}
