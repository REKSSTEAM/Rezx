<?php
/** Easy central HLS proxy. Accepts URL-safe base64 in ?u=. */
declare(strict_types=1);
error_reporting(0);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Expose-Headers: Content-Range, Content-Length, Accept-Ranges');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
function b64d(string $s): string { $s = strtr($s, '-_', '+/'); $p = strlen($s) % 4; if ($p) $s .= str_repeat('=', 4 - $p); return (string)base64_decode($s, true); }
function bad(string $m, int $c = 400): never { http_response_code($c); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function proxy_self(string $url): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $base = 'https://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . '/api/easy/proxy.php?u=';
    return $base . rawurlencode(rtrim(strtr(base64_encode($url), '+/', '-_'), '='));
}
$encoded = isset($_GET['u']) ? (string)$_GET['u'] : '';
$url = b64d($encoded);
if (stripos($url, 'http://') === 0) $url = 'https://' . substr($url, 7);
$p = parse_url($url);
$host = isset($p['host']) ? strtolower($p['host']) : '';
$path = isset($p['path']) ? $p['path'] : '';
if (!$p || !isset($p['scheme']) || strtolower($p['scheme']) !== 'https' || $host === '') bad('invalid target');
$local = strtolower($_SERVER['HTTP_HOST'] ?? '');
$allowed = ($host === $local && (str_starts_with($path, '/api/lookmovie/proxy.php') || str_starts_with($path, '/api/animecurx/proxy.php')))
    || $host === 'www.lookmovie2.to' || str_ends_with($host, '.lookmovie2.to')
    || str_ends_with($host, '.egnardess.store') || str_ends_with($host, '.scracting.store')
    || str_ends_with($host, '.proarderly.store') || $host === 'embed.animecurx.tech';
if (!$allowed) bad('target host is not allowed', 403);
$isPlaylist = str_ends_with(strtolower($path), '.m3u8');
$isSegment = preg_match('/\.(ts|aac|mp4|vtt)$/i', $path) === 1;
$ch = curl_init($url);
$headers = ['Accept: */*']; if (!empty($_SERVER['HTTP_RANGE'])) $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $isSegment ? 60 : 25, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'AFVeo-Easy-Proxy/1.0', CURLOPT_REFERER => 'https://www.lookmovie2.to/', CURLOPT_HTTPHEADER => $headers]);
$body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
if (!is_string($body) || $code < 200 || $code >= 400) bad('upstream failed', 502);
if (stripos($body, 'WRONG HASH') !== false) bad('upstream returned WRONG HASH', 502);
if ($isPlaylist || stripos($ctype, 'mpegurl') !== false || str_starts_with(ltrim($body), '#EXTM3U')) {
    $base = preg_replace('#[^/]+$#', '', $url); $out = [];
    foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '#')) {
            if (preg_match('/URI="([^"]+)"/', $line, $m)) { $abs = preg_match('#^https?://#i', $m[1]) ? $m[1] : $base . $m[1]; $line = str_replace($m[1], proxy_self($abs), $line); }
            $out[] = $line; continue;
        }
        $abs = preg_match('#^https?://#i', $trim) ? $trim : $base . $trim; $out[] = proxy_self($abs);
    }
    header('Content-Type: application/vnd.apple.mpegurl'); header('Cache-Control: no-store'); echo implode("\n", $out); exit;
}
if ($code === 206) { http_response_code(206); header('Accept-Ranges: bytes'); }
header('Content-Type: ' . ($ctype ?: ($isSegment ? 'video/mp2t' : 'application/octet-stream'))); header('Cache-Control: no-store'); header('Content-Length: ' . strlen($body)); echo $body;
