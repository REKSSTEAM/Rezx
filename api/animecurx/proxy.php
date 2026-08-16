<?php
/** AnimeCurX unified proxy: HLS playlists, media files, and subtitles. */
declare(strict_types=1);
error_reporting(0);
set_time_limit(120);
const UA = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';

$mode = strtolower((string)($_GET['mode'] ?? 'hls'));
$url = rawurldecode((string)($_GET['url'] ?? $_GET['u'] ?? ''));
if ($url === '' || !preg_match('#^https?://#i', $url)) { http_response_code(400); echo 'Invalid url'; exit; }

function proxy_self(string $mode, string $url): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/api/animecurx/proxy.php?mode=' . rawurlencode($mode) . '&url=' . rawurlencode($url);
}
function fetch_data(string $url, string $referer = ''): array {
    $p = parse_url($url); $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    $h = ['User-Agent: ' . UA, 'Accept: */*', 'Origin: ' . $origin];
    if ($referer !== '') $h[] = 'Referer: ' . $referer;
    if (!empty($_SERVER['HTTP_RANGE'])) $h[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5, CURLOPT_TIMEOUT=>60, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_ENCODING=>'gzip, deflate, br', CURLOPT_USERAGENT=>UA, CURLOPT_HTTPHEADER=>$h]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); curl_close($ch);
    return ['body' => is_string($body) ? $body : '', 'code' => $code, 'type' => $ct, 'url' => $effective ?: $url];
}
function absolute_url(string $value, string $base): string {
    if (preg_match('#^https?://#i', $value)) return $value;
    $p = parse_url($base); $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    if (str_starts_with($value, '/')) return $root . $value;
    return substr($base, 0, strrpos($base, '/') + 1) . $value;
}
function rewrite_playlist(string $body, string $base): string {
    $out = [];
    foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '#')) {
            $line = preg_replace_callback('/(URI|URL)="([^"]+)"/i', function ($m) use ($base) { return $m[1] . '="' . proxy_self('hls', absolute_url($m[2], $base)) . '"'; }, $line);
            $out[] = $line; continue;
        }
        $out[] = proxy_self('hls', absolute_url($trim, $base));
    }
    return implode("\n", $out);
}

if ($mode === 'subtitle') {
    if (str_starts_with($url, 'data:')) { header('Content-Type: text/vtt; charset=utf-8'); echo urldecode(explode(',', $url, 2)[1] ?? ''); exit; }
    $r = fetch_data($url); if ($r['body'] === '') { http_response_code(404); exit; }
    $body = ltrim($r['body'], "\xEF\xBB\xBF"); if (stripos($body, 'WEBVTT') === false && stripos($r['type'], 'vtt') !== false) $body = "WEBVTT\n\n" . $body;
    header('Content-Type: text/vtt; charset=utf-8'); header('Access-Control-Allow-Origin: *'); echo $body; exit;
}

$r = fetch_data($url, (($p = parse_url($url)) ? (($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '')) : ''));
if ($r['body'] === '' || $r['code'] < 200 || $r['code'] >= 400) { http_response_code(502); echo 'Upstream failed'; exit; }
if (stripos($r['body'], 'WRONG HASH') !== false) { http_response_code(502); echo 'WRONG HASH'; exit; }
$isPlaylist = $mode === 'hls' || stripos($r['type'], 'mpegurl') !== false || str_starts_with(ltrim($r['body']), '#EXTM3U');
if ($isPlaylist) {
    header('Content-Type: application/vnd.apple.mpegurl'); header('Access-Control-Allow-Origin: *'); header('Cache-Control: no-store');
    echo rewrite_playlist($r['body'], $r['url']); exit;
}
if ($r['code'] === 206) { http_response_code(206); header('Accept-Ranges: bytes'); }
header('Content-Type: ' . ($r['type'] ?: 'application/octet-stream')); header('Access-Control-Allow-Origin: *'); header('Cache-Control: no-store'); header('Content-Length: ' . strlen($r['body'])); echo $r['body'];
