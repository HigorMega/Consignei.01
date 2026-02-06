<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

echo json_encode([
    'ok' => true,
    'time' => date('c'),
], JSON_UNESCAPED_UNICODE);
