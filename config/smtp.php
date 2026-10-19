<?php
/**
 * 轩宇-导航 · 邮件配置
 * 默认关闭；填写 SMTP 账号、发件地址和收件邮箱后，将 enabled 改为 true。
 */

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

return [
    'enabled' => false,
    'admin_email' => '',

    'smtp' => [
        'host' => 'smtp.qiye.aliyun.com',
        'port' => 465,
        'username' => '',
        'password' => '',
        'encryption' => 'ssl',
        'timeout' => 30,
        'auth' => true,
        'debug' => false,
    ],

    'from' => [
        'address' => '',
        'name' => '轩宇-导航',
    ],

    'subject_prefix' => '【轩宇-导航】',
    'charset' => 'UTF-8',

    'templates' => [
        'path' => 'template/mail/',
        'application_notification' => 'application_notification',
    ],

    'backup' => [
        'enabled' => false,
        'method' => 'mail',
        'sendmail_path' => '/usr/sbin/sendmail',
    ],
];
