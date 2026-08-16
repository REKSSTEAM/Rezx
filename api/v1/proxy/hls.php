<?php
/*
 * بروكسي HLS — يجلب ملفات m3u8 ويعيد كتابة الروابط النسبية/المشتركة
 * لتمر عبر هذا البروكسي، ثم يجلب السيجمنتات عبر البروكسي أيضاً
 */
error_reporting(0);
set_time_limit(120);

define('UA', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');

$url = $_GET['url'] ?? '';
if ($url === '') { http_response_code(400); echo 'Missing url'; exit; }
if (strpos($url, 'http') !== 0) { http_response_code(400); echo 'Invalid url'; exit; }

// فك الترميز المزدوج إن وجد
$url = rawurldecode($url);

function fetch_body($url, $referer) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => 'gzip, deflate, br',
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . UA,
            'Referer: ' . $referer,
            'Accept: */*',
            'Origin: ' . parse_url($referer, PHP_URL_SCHEME) . '://' . parse_url($referer, PHP_URL_HOST),
        ],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body !== false ? $body : '';
}

function self_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Keep nested provider routes working when this file is loaded through
    // the front controller (for example /api/newserver/hls.php).
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $dir = dirname($requestPath);
    if ($dir === '.' || $dir === DIRECTORY_SEPARATOR) $dir = '';
    return $scheme . '://' . $host . rtrim(str_replace('\\', '/', $dir), '/');
}

function pass_through_url($u) {
    $self = self_url();
    // إن كان الرابط يعبر بالفعل بروكسي HLS آخر (مثل /api/proxy/hls)
    // فمرره كما هو لتتجنب التداخل المكرر — البروكسي الأصلي يتكفل بجلب المضمون
    if (preg_match('#/api/[^/?]+/hls\.php\?#i', $u)) {
        return $u;
    }
    return $self . '/hls.php?url=' . rawurlencode($u);
}

function rewrite_m3u8($content, $base) {
    $self = self_url();
    $lines = explode("\n", $content);
    $out = [];
    foreach ($lines as $line) {
        if (trim($line) === '' || $line[0] === '#') {
            // إعادة كتابة الروابط داخل ATTRIBUTES مثل URI="..." و URL=...
            $line = preg_replace_callback('/(URI|URL|IV)="([^"]+)"/', function ($m) use ($base) {
                $u = $m[2];
                if (strpos($u, 'http') !== 0) $u = $base . ltrim($u, '/');
                return $m[1] . '="' . pass_through_url($u) . '"';
            }, $line);
            $out[] = $line;
            continue;
        }
        // رابط سيجمنت
        $u = trim($line);
        if (strpos($u, 'http') !== 0) $u = $base . ltrim($u, '/');
        $out[] = pass_through_url($u);
    }
    return implode("\n", $out);
}

$body = fetch_body($url, parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST));
$base = substr($url, 0, strrpos($url, '/') + 1);

if ($body === '') {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Failed to fetch: ' . $url;
    exit;
}

// master playlist — يحتوي EXT-X-STREAM-INF
if (strpos($body, '#EXT-X-STREAM-INF') !== false) {
    header('Content-Type: application/vnd.apple.mpegurl');
    echo rewrite_m3u8($body, $base);
    exit;
}

// media playlist (سيجمنتات)
if (strpos($body, '#EXTINF') !== false || strpos($body, '#EXTM3U') !== false) {
    header('Content-Type: application/vnd.apple.mpegurl');
    echo rewrite_m3u8($body, $base);
    exit;
}

// إن لم يكن m3u8 — إرجاع البيانات الخام مع نوعها الحقيقي
$ct = strpos($body, 'G@') === 0 || ord($body[0]) < 0x20 ? 'application/octet-stream' : 'text/plain';
http_response_code(200);
header('Content-Type: ' . $ct);
header('Access-Control-Allow-Origin: *');
echo $body;
