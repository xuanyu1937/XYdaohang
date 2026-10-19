<?php
/**
 * 轩宇-导航
 * 远程更新接口已移除。
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'update_removed'], JSON_UNESCAPED_UNICODE);
exit;
