<?php
/**
 * 轩宇-导航 · 邮件模板解析
 */
class TemplateParser {
    /**
     * 模板文件基础路径
     */
    private $templatePath;
    
    /**
     * 构造函数
     */
    public function __construct($templatePath = null) {
        if ($templatePath) {
            $this->templatePath = rtrim($templatePath, '/') . '/';
        } else {
            $this->templatePath = '../template/mail/';
        }
    }
    
    /**
     * 设置模板路径
     */
    public function setTemplatePath($path) {
        $this->templatePath = rtrim($path, '/') . '/';
        return $this;
    }
    
    /**
     * 解析邮件模板
     * @param string $templateName 模板名称（不带扩展名）
     * @param array $data 模板数据
     * @param string $extension 文件扩展名（html或txt）
     * @return string 解析后的内容
     */
    public function parse($templateName, $data, $extension = 'html') {
        // 构建完整文件路径
        $templateFile = $this->templatePath . $templateName . '.' . $extension;
        
        // 检查模板文件是否存在
        if (!file_exists($templateFile)) {
            throw new Exception("模板文件不存在: " . $templateFile);
        }
        
        // 读取模板内容
        $content = file_get_contents($templateFile);
        
        // 处理模板
        $content = $this->replaceVariables($content, $data, $extension === 'html');
        $content = $this->processConditions($content, $data);
        $content = $this->processLoops($content, $data);
        
        return $content;
    }
    
    /**
     * 替换模板变量
     * HTML 模板对用户可控数据做转义，纯文本模板保持原样。
     */
    private function replaceVariables($content, $data, $escapeHtml = false) {
        foreach ($data as $key => $value) {
            // 只处理标量值
            if (is_scalar($value) || $value === null) {
                $placeholder = '{{' . $key . '}}';
                $replacement = (string) $value;
                
                if ($escapeHtml) {
                    $replacement = htmlspecialchars($replacement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                
                $content = str_replace($placeholder, $replacement, $content);
            }
        }
        
        // 替换所有剩余的未定义变量为空
        $content = preg_replace('/\{\{(\w+)\}\}/', '', $content);
        
        return $content;
    }
    
    /**
     * 处理条件语句
     */
    private function processConditions($content, $data) {
        // 处理 {{#if var}} ... {{/if}}
        $pattern = '/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s';
        
        while (preg_match($pattern, $content, $matches)) {
            $varName = $matches[1];
            $innerContent = $matches[2];
            
            if (!empty($data[$varName])) {
                // 变量存在且不为空，保留内容
                $content = str_replace($matches[0], $innerContent, $content);
            } else {
                // 变量不存在或为空，移除内容
                $content = str_replace($matches[0], '', $content);
            }
        }
        
        // 处理 {{#unless var}} ... {{/unless}}
        $pattern = '/\{\{#unless\s+(\w+)\}\}(.*?)\{\{\/unless\}\}/s';
        
        while (preg_match($pattern, $content, $matches)) {
            $varName = $matches[1];
            $innerContent = $matches[2];
            
            if (empty($data[$varName])) {
                // 变量不存在或为空，保留内容
                $content = str_replace($matches[0], $innerContent, $content);
            } else {
                // 变量存在且不为空，移除内容
                $content = str_replace($matches[0], '', $content);
            }
        }
        
        return $content;
    }
    
    /**
     * 处理循环语句（简单实现）
     */
    private function processLoops($content, $data) {
        // 处理 {{#each items}} ... {{/each}}
        $pattern = '/\{\{#each\s+(\w+)\}\}(.*?)\{\{\/each\}\}/s';
        
        while (preg_match($pattern, $content, $matches)) {
            $varName = $matches[1];
            $innerContent = $matches[2];
            
            if (isset($data[$varName]) && is_array($data[$varName])) {
                $replacement = '';
                foreach ($data[$varName] as $item) {
                    if (is_array($item)) {
                        $itemContent = $innerContent;
                        foreach ($item as $key => $value) {
                            if (is_scalar($value) || $value === null) {
                                $itemContent = str_replace('{{this.' . $key . '}}', (string)$value, $itemContent);
                            }
                        }
                        $itemContent = str_replace('{{@index}}', '', $itemContent);
                        $replacement .= $itemContent;
                    } elseif (is_scalar($item)) {
                        $itemContent = str_replace('{{this}}', (string)$item, $innerContent);
                        $replacement .= $itemContent;
                    }
                }
                $content = str_replace($matches[0], $replacement, $content);
            } else {
                $content = str_replace($matches[0], '', $content);
            }
        }
        
        return $content;
    }
    
    /**
     * 解析所有支持的模板格式
     * @param string $templateName 模板名称
     * @param array $data 模板数据
     * @return array [html, text] 包含HTML和纯文本版本
     */
    public function parseAll($templateName, $data) {
        $result = [
            'html' => '',
            'text' => ''
        ];
        
        try {
            $result['html'] = $this->parse($templateName, $data, 'html');
        } catch (Exception $e) {
            error_log("HTML模板解析失败: " . $e->getMessage());
        }
        
        try {
            $result['text'] = $this->parse($templateName, $data, 'txt');
        } catch (Exception $e) {
            error_log("纯文本模板解析失败: " . $e->getMessage());         
            $result['text'] = strip_tags($result['html']);
            $result['text'] = preg_replace('/\s+/', ' ', $result['text']);
            $result['text'] = trim($result['text']);
        }
        
        return $result;
    }
    
    /**
     * 检查模板是否存在
     */
    public function templateExists($templateName, $extension = 'html') {
        $templateFile = $this->templatePath . $templateName . '.' . $extension;
        return file_exists($templateFile);
    }
    
    /**
     * 获取可用的模板列表
     */
    public function getAvailableTemplates() {
        $templates = [];
        
        if (!is_dir($this->templatePath)) {
            return $templates;
        }
        
        $files = scandir($this->templatePath);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $pathInfo = pathinfo($file);
                $extension = strtolower((string) ($pathInfo['extension'] ?? ''));
                if (in_array($extension, ['html', 'txt', 'htm'], true)) {
                    $templates[$pathInfo['filename']][] = $extension;
                }
            }
        }
        
        return $templates;
    }
}