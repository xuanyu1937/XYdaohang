<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo '请使用网站根目录的 install.php 安装向导。';
exit;
