<?php
require_once(__DIR__ . '/../../config.php');

require_login();
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'error' => 'boards_retired',
    'message' => 'Доски DJGMS выведены из продукта. Используйте /local/ustar/tasks.php.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
