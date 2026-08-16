<?php
/**
 * api/lookmovie/index.php — LookMovie2 server
 *
 * GET /api/lookmovie?type=movie&id=TMDB_ID
 * GET /api/lookmovie?type=tv&id=TMDB_ID&season=N&episode=N
 *
 * Returns: {"ok":true,"source":{"m3u8":"...","type":"m3u8","quality":"...","qualities":[...],"subtitles":[]}}
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=600');

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

// ── cache ─────────────────────────────────────────────────────────────────────
$cache_dir  = __DIR__ . '/../../data/cache/lookmovie';
if (!is_dir($cache_dir)) @mkdir($cache_dir, 0777, true);
$cache_key  = "proxy-v2-{$type}-{$id}" . ($type === 'tv' ? "-{$season}-{$episode}" : '');
$cache_file = $cache_dir . '/' . $cache_key . '.json';

if (is_file($cache_file) && (time() - filemtime($cache_file)) < 600) {
    readfile($cache_file);
    exit;
}

// ── helpers ───────────────────────────────────────────────────────────────────
$BASE = 'https://www.lookmovie2.to';
if (!defined('LM_UA')) {
    define('LM_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
}
$UA   = LM_UA;

function lm_request(string $url, string $referer = ''): ?string {
    $UA = LM_UA;

    // 1) cURL إن كان متاحاً
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_CONNECTTIMEOUT  => 8,
            CURLOPT_TIMEOUT         => 20,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => false,
            CURLOPT_ENCODING        => '',
            CURLOPT_USERAGENT       => $UA,
            CURLOPT_HTTPHEADER      => ['Accept: */*', 'Accept-Language: en-US,en;q=0.9'],
        ];
        if ($referer) $opts[CURLOPT_REFERER] = $referer;
        curl_setopt_array($ch, $opts);
        $r = curl_exec($ch);
        curl_close($ch);
        if (is_string($r) && $r !== '') return $r;
    }

    // 2) بديل للاستضافات التي تعطّل cURL
    if (ini_get('allow_url_fopen')) {
        $hdr = "User-Agent: {$UA}\r\nAccept: */*\r\n" . ($referer ? "Referer: {$referer}\r\n" : '');
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'header' => $hdr, 'timeout' => 20, 'follow_location' => 1],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $r = @file_get_contents($url, false, $ctx);
        if (is_string($r) && $r !== '') return $r;
    }

    return null;
}

function lm_json(string $url, string $referer = ''): ?array {
    $r = lm_request($url, $referer);
    if (!$r) return null;
    $j = json_decode($r, true);
    return is_array($j) ? $j : null;
}

// ── TMDB: get title + year ────────────────────────────────────────────────────
function lm_tmdb_meta(string $type, int $id): ?array {
    $key = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
    if (!$key) return null;
    $body = lm_request("https://api.themoviedb.org/3/{$type}/{$id}?api_key={$key}");
    $j = json_decode((string)$body, true);
    if (!is_array($j)) return null;
    $title = $j['title'] ?? $j['name'] ?? '';
    $date  = $j['release_date'] ?? $j['first_air_date'] ?? '';
    $year  = $date ? (int)substr($date, 0, 4) : 0;
    return ['title' => $title, 'year' => $year];
}

// ── build quality list from streams array ─────────────────────────────────────
// Routed through this server's own proxy.php (not the shared hls-proxy.php).
function lm_proxy_base(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return '/api/lookmovie/proxy.php';
    return $scheme . '://' . $host . '/api/lookmovie/proxy.php';
}

function lm_hls_proxy(string $url): string {
    // Master always returns this project's own proxy URL.
    // The proxy rewrites the playlist and serves relative segments through itself.
    $u = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    $r = rtrim(strtr(base64_encode('https://www.lookmovie2.to/'), '+/', '-_'), '=');
    return lm_proxy_base() . '?u=' . rawurlencode($u) . '&r=' . rawurlencode($r);
}

function lm_build_qualities(array $streams): array {
    $order = ['2160' => 5, '1080' => 4, '720' => 3, '480' => 2, '360' => 1];
    $result = [];
    foreach ($streams as $q => $url) {
        if (!$url) continue;
        $clean = str_replace('p', '', (string)$q);
        $score = (int)($clean ?: 0);
        foreach ($order as $k => $s) {
            if (str_contains((string)$clean, $k)) { $score = $s * 1000 + (int)$clean; break; }
        }
        $result[] = ['label' => $clean . 'p', 'url' => (string)$url, 'score' => $score];
    }
    usort($result, fn($a, $b) => $b['score'] - $a['score']);
    return $result;
}

// ── main ──────────────────────────────────────────────────────────────────────
$meta = lm_tmdb_meta($type === 'tv' ? 'tv' : 'movie', $id);
if (!$meta || !$meta['title']) {
    echo json_encode(['ok' => false, 'error' => 'tmdb metadata unavailable']);
    exit;
}

$title = $meta['title'];
$year  = $meta['year'];

if ($type === 'movie') {
    // 1. Search
    $search  = lm_json("{$BASE}/api/v1/movies/do-search/?q=" . urlencode($title));
    $movieId = null;
    $slug    = null;
    if ($search && !empty($search['result'])) {
        foreach ($search['result'] as $item) {
            if (strcasecmp((string)($item['title'] ?? ''), $title) === 0
                && (int)($item['year'] ?? 0) === $year) {
                $movieId = $item['id_movie'] ?? null;
                $slug    = $item['slug']     ?? null;
                break;
            }
        }
        if (!$movieId) {
            $movieId = $search['result'][0]['id_movie'] ?? null;
            $slug    = $search['result'][0]['slug']     ?? null;
        }
    }
    if (!$movieId || !$slug) {
        echo json_encode(['ok' => false, 'error' => 'movie not found on LookMovie2: ' . $title]);
        exit;
    }

    // 2. Play page → hash + expires
    $playUrl  = "{$BASE}/movies/play/{$slug}";
    $playHtml = lm_request($playUrl);
    preg_match("/hash:\s*[\"']([^\"']+)[\"']/", (string)$playHtml, $hm);
    preg_match("/expires:\s*(\d+)/",            (string)$playHtml, $em);
    $hash    = $hm[1] ?? null;
    $expires = $em[1] ?? null;

    if (!$hash || !$expires) {
        echo json_encode(['ok' => false, 'error' => 'could not extract security token']);
        exit;
    }

    // 3. Stream access
    $accessUrl  = "{$BASE}/api/v1/security/movie-access?id_movie={$movieId}&hash={$hash}&expires={$expires}";
    $accessData = lm_json($accessUrl, $playUrl);

    if (!$accessData || empty($accessData['success']) || empty($accessData['streams'])) {
        echo json_encode(['ok' => false, 'error' => 'stream access denied']);
        exit;
    }

    $qualities = lm_build_qualities($accessData['streams']);

} else {
    // TV
    // 1. Search
    $search = lm_json("{$BASE}/api/v1/shows/do-search/?q=" . urlencode($title));
    $showId = null;
    $slug   = null;
    if ($search && !empty($search['result'])) {
        foreach ($search['result'] as $item) {
            if (strcasecmp((string)($item['title'] ?? ''), $title) === 0
                && (int)($item['year'] ?? 0) === $year) {
                $showId = $item['id_show'] ?? null;
                $slug   = $item['slug']    ?? null;
                break;
            }
        }
        if (!$showId) {
            $showId = $search['result'][0]['id_show'] ?? null;
            $slug   = $search['result'][0]['slug']    ?? null;
        }
    }
    if (!$showId || !$slug) {
        echo json_encode(['ok' => false, 'error' => 'show not found on LookMovie2: ' . $title]);
        exit;
    }

    // 2. Play page → hash + expires
    $playUrl  = "{$BASE}/shows/play/{$slug}";
    $playHtml = lm_request($playUrl);
    preg_match("/hash:\s*'([^']+)'/", (string)$playHtml, $hm);
    preg_match("/expires:\s*(\d+)/",  (string)$playHtml, $em);
    $hash    = $hm[1] ?? null;
    $expires = $em[1] ?? null;

    // 3. Episode list → id_episode
    $epList = lm_json("{$BASE}/api/v2/download/episode/list?id={$showId}", $playUrl);
    $idEp   = $epList['list'][$season][$episode]['id_episode'] ?? null;

    if (!$idEp || !$hash || !$expires) {
        echo json_encode(['ok' => false, 'error' => 'episode details unavailable']);
        exit;
    }

    // 4. Stream access
    $accessUrl  = "{$BASE}/api/v1/security/episode-access?id_episode={$idEp}&hash={$hash}&expires={$expires}";
    $accessData = lm_json($accessUrl, $playUrl);

    if (!$accessData || empty($accessData['success']) || empty($accessData['streams'])) {
        echo json_encode(['ok' => false, 'error' => 'stream access denied']);
        exit;
    }

    $qualities = lm_build_qualities($accessData['streams']);
}

if (empty($qualities)) {
    echo json_encode(['ok' => false, 'error' => 'no playable streams']);
    exit;
}

$best         = $qualities[0];
$quality_list = [];
foreach ($qualities as $i => $q) {
    $quality_list[] = ['label' => $q['label'], 'url' => lm_hls_proxy($q['url']), 'default' => ($i === 0)];
}

// ── Subtitles ─────────────────────────────────────────────────────────────────
$subtitles   = [];
$lang_count  = [];
$raw_subs    = $accessData['subtitles'] ?? [];
foreach ($raw_subs as $s) {
    $lang = trim((string)($s['language'] ?? ''));
    $file = trim((string)($s['file']     ?? ''));
    if (!$lang || !$file) continue;

    // build full URL — subtitle files live on the main domain
    $url = strpos($file, 'http') === 0 ? $file : $BASE . $file;

    // deduplicate label if multiple subs for same language
    if (!isset($lang_count[$lang])) {
        $lang_count[$lang] = 0;
    }
    $lang_count[$lang]++;
    $label = $lang_count[$lang] > 1 ? $lang . ' ' . $lang_count[$lang] : $lang;

    $subtitles[] = ['label' => $label, 'url' => lm_hls_proxy($url)];
}

$out = json_encode([
    'ok'    => true,
    'title' => $title,
    'source' => [
        'provider'  => 'lookmovie',
        'name'      => 'LookMovie',
        'quality'   => $best['label'],
        'm3u8'      => lm_hls_proxy($best['url']),
        'type'      => 'm3u8',
        'qualities' => $quality_list,
        'subtitles' => $subtitles,
    ],
], JSON_UNESCAPED_SLASHES);

@file_put_contents($cache_file, $out);
echo $out;
