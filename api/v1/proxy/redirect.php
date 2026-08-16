<?php
/*
 * بروكسي إعادة التوجيه — يعيد توجيه العميل مباشرة لمصدر الفيديو
 * (يعمل فقط عندما لا يمنع المصدر CORS أو hotlink).
 * عند الفشل يمكن تحويله لبروكسي كامل بإزالة header Location.
 */
error_reporting(0);
define('UA', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');

$url = $_GET['url'] ?? '';
if ($url === '') { http_response_code(400); echo 'Missing url'; exit; }
$url = rawurldecode($url);
if (strpos($url, 'http') !== 0) { http_response_code(400); echo 'Invalid url'; exit; }

// ---------- وضع 1: إعادة توجيه مباشرة (الأخف على السيرفر) ----------
$mode = $_GET['mode'] ?? 'redirect';

if ($mode === 'redirect') {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY           => true,
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_MAXREDIRS        => 5,
        CURLOPT_TIMEOUT          => 20,
        CURLOPT_SSL_VERIFYPEER   => false,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_USERAGENT        => UA,
    ]);
    curl_exec($ch);
    $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $ct    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    header('Access-Control-Allow-Origin: *');
    if ($final && $final !== $url) {
        header('Location: ' . $final);
        exit;
    }
    header('Content-Type: ' . ($ct ?: 'application/octet-stream'));
    header('Location: ' . $url);
    exit;
}

// ---------- وضع 2: بروكسي كامل (stream عبر السيرفر) ----------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Range');
header('Access-Control-Expose-Headers: Content-Range, Accept-Ranges, Content-Length, Content-Type');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 0, // بلا حد زمني للبث
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_ENCODING       => 'gzip, deflate, br',
    CURLOPT_HTTPHEADER     => [
        'User-Agent: ' . UA,
        'Referer: ' . (parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST)),
        'Range: ' . ($_SERVER['HTTP_RANGE'] ?? ''),
    ],
]);

// بث مباشر chunk-by-chunk
header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK');
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) {
    $h = trim($header);
    $low = strtolower($h);
    if ($h === '' || strpos($low, 'transfer-encoding') === 0 || strpos($low, 'content-encoding') === 0) return strlen($header);
    if (strpos($low, 'content-length') === 0 || strpos($low, 'content-range') === 0 || strpos($low, 'content-type') === 0 || strpos($low, 'accept-ranges') === 0 || strpos($low, 'cache-control') === 0) {
        header($h);
    }
    return strlen($header);
});
curl_exec($ch);
curl_close($ch);
