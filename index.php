<?php

header('Content-Type: application/json; charset=utf-8');

$url = 'https://api.themoviedb.org/3/configuration';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'AFVeo/1.0'
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

echo json_encode([
    'ok' => $error === '',
    'http_code' => $httpCode,
    'curl_error' => $error,
    'response_length' => strlen((string)$response)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
