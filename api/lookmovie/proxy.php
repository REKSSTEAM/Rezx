<?php
/**
 * api/lookmovie/proxy.php — HLS reverse proxy for LookMovie2 streams
 *
 * Proxies .m3u8 playlists and .ts segments through the server so
 * the browser doesn't hit CORS or hotlink-protection on the CDN.
 * Self-contained proxy for the lookmovie server (not shared with others).
 *
 * Query params:
 *   u — URL-safe base64-encoded target URL
 *   r — URL-safe base64-encoded referer (optional)
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type');
header('Access-Control-Expose-Headers: Content-Range, Content-Length, Accept-Ranges');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── decode helpers ────────────────────────────────────────────────────────────
function lm_b64url_decode(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return (string)base64_decode($s);
}

$rawU = $_GET['u'] ?? '';
$rawR = $_GET['r'] ?? '';

if (!$rawU) { http_response_code(400); echo json_encode(['error' => 'missing u']); exit; }

$targetUrl = lm_b64url_decode($rawU);
$referer   = $rawR ? lm_b64url_decode($rawR) : 'https://www.lookmovie2.to/';

// ── security: only allow lookmovie2 domains + known CDN hosts ─────────────────
$host = strtolower(parse_url($targetUrl, PHP_URL_HOST) ?? '');
$allowed = [
    'lookmovie2.to',
    'www.lookmovie2.to',
    'egnardess.store',      // stream CDN (e.g. srv319.egnardess.store)
    'scracting.store',      // stream CDN (e.g. srv323.scracting.store)
    'b-cdn.net',            // BunnyCDN edges
    'mydash.cc',            // API domain
];
$isAllowed = false;
foreach ($allowed as $d) {
    if ($host === $d || str_ends_with($host, '.' . $d)) { $isAllowed = true; break; }
}
// fallback: allow any public host (بعض الاستضافات تعطّل gethostbyname)
if (!$isAllowed && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
    $ip = function_exists('gethostbyname') ? @gethostbyname($host) : '';
    if (!$ip || $ip === $host) {
        $isAllowed = true; // تعذّر الفحص → نعتمد على أن المضيف اسم نطاق عام
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $isAllowed = true;
    }
}
if (!$isAllowed) {
    http_response_code(403);
    echo json_encode(['error' => 'domain not allowed']);
    exit;
}

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// ── detect if this is an m3u8 playlist or a media segment ────────────────────
$path   = parse_url($targetUrl, PHP_URL_PATH) ?? '';
$isM3u8 = str_ends_with(strtolower($path), '.m3u8');
$isTs   = str_ends_with(strtolower($path), '.ts')
       || str_ends_with(strtolower($path), '.aac')
       || str_ends_with(strtolower($path), '.mp4');

// ── fetch the remote content ──────────────────────────────────────────────────
$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 4,
    CURLOPT_TIMEOUT        => $isTs ? 60 : 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => $UA,
    CURLOPT_REFERER        => $referer,
    CURLOPT_HTTPHEADER     => [
        'Accept: */*',
        'Origin: ' . rtrim(preg_replace('#^(https?://[^/]+).*$#', '$1', $referer), '/'),
        'Sec-Fetch-Dest: ' . ($isTs ? 'video' : 'empty'),
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: cross-site',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);

// Range support for segments
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range && preg_match('/^bytes=\d*-\d*$/', $range)) {
    curl_setopt($ch, CURLOPT_RANGE, str_replace('bytes=', '', $range));
}

// ── TS segments: stream directly without buffering the whole file ─────────────
if ($isTs) {
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);   // تقليل من 60 إلى 20 ثانية
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);

    // إرسال الهيدرز قبل بدء الـ stream
    while (ob_get_level()) ob_end_clean();
    if ($range) {
        http_response_code(206);
        header('Accept-Ranges: bytes');
    }
    header('Content-Type: video/mp2t');
    header('Cache-Control: public, max-age=3600');

    $out = fopen('php://output', 'wb');
    curl_setopt($ch, CURLOPT_FILE, $out);
    curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($out);

    if ($httpCode >= 400) {
        // لا نستطيع إرسال error JSON بعد ما بدأنا الـ stream
        error_log("proxy.php: upstream TS error $httpCode for $targetUrl");
    }
    exit;
}

// ── m3u8 وغيره: احتجنا النص كاملاً لإعادة الكتابة ───────────────────────────
$body     = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if (!$body || $httpCode < 200 || $httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream returned ' . $httpCode]);
    exit;
}

// ── for m3u8: rewrite relative URLs to go through this proxy ─────────────────
if ($isM3u8 || strpos((string)$ctype, 'mpegurl') !== false) {
    $baseUrl = preg_replace('#[^/]+$#', '', $targetUrl); // directory of the m3u8
    $rRef    = rtrim(strtr(base64_encode('https://www.lookmovie2.to/'), '+/', '-_'), '=');

    $lines = explode("\n", $body);
    $out   = [];
    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '' || $line[0] === '#') {
            $out[] = $line;
            continue;
        }
        // Build absolute URL for this segment/playlist
        if (preg_match('#^https?://#', $line)) {
            $absUrl = $line;
        } else {
            $absUrl = $baseUrl . ltrim($line, '/');
        }
        $rU  = rtrim(strtr(base64_encode($absUrl), '+/', '-_'), '=');
        $out[] = '/api/lookmovie/proxy.php?u=' . $rU . '&r=' . $rRef;
    }

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    echo implode("\n", $out);
    exit;
}

// ── other content: pass through ───────────────────────────────────────────────
if ($range) {
    http_response_code(206);
    header('Accept-Ranges: bytes');
}
header('Content-Type: ' . ($ctype ?: 'application/octet-stream'));
header('Content-Length: ' . strlen($body));
header('Cache-Control: public, max-age=3600');
echo $body;
