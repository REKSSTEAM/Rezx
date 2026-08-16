<?php

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => true,
    'service' => 'AFVeo API',
    'status' => 'online',
    'php' => PHP_VERSION,
    'curl' => function_exists('curl_init')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
