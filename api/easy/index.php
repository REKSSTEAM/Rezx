<?php
/* AFVeo Easy Gateway - PHP 7 compatible */
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

const EASY_KEY = '7';

function easy_fail($message, $code) {
    http_response_code((int)$code);
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function easy_json($data) {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function easy_origin() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443');
    return ($https ? 'https' : 'http') . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
}
function easy_url($path) { return easy_origin() . $path; }
function easy_proxy_url($url) {
    $encoded = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    return easy_url('/api/easy/proxy.php?u=' . rawurlencode($encoded));
}
function easy_fetch($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 70,
        CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'AFVeo-Easy-Gateway/1.0',
        CURLOPT_HTTPHEADER => array('Accept: application/json, text/plain, */*')
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => is_string($body) ? $body : '');
}
function easy_auth() {
    $provided = isset($_SERVER['HTTP_X_EASY_KEY']) ? $_SERVER['HTTP_X_EASY_KEY'] : (isset($_GET['key']) ? $_GET['key'] : '');
    if (EASY_KEY !== '' && !hash_equals(EASY_KEY, (string)$provided)) easy_fail('unauthorized', 401);
}
function easy_provider_url($provider, $type, $id, $season, $episode) {
    $query = http_build_query(array('type' => $type, 'id' => $id, 'season' => $season, 'episode' => $episode));
    if ($provider === 'lookmovie') return easy_url('/api/lookmovie/index.php?' . $query);
    if ($provider === 'animecurx') return easy_url('/api/animecurx/index.php?action=streams&format=player&src=animecurx&' . http_build_query(array('type' => $type, 'id' => $id, 's' => $season, 'e' => $episode)));
    easy_fail('unknown provider', 400);
}
function easy_normalize($provider, $data) {
    $sources = array(); $subtitles = array();
    if ($provider === 'lookmovie') {
        $source = isset($data['source']) && is_array($data['source']) ? $data['source'] : array();
        $qualities = isset($source['qualities']) && is_array($source['qualities']) ? $source['qualities'] : array();
        foreach ($qualities as $q) {
            $url = isset($q['url']) ? (string)$q['url'] : '';
            if ($url === '') continue;
            $sources[] = array('url' => easy_proxy_url($url), 'label' => isset($q['label']) ? $q['label'] : 'Source', 'type' => 'hls', 'provider' => 'lookmovie');
        }
        if (empty($sources) && !empty($source['m3u8'])) $sources[] = array('url' => easy_proxy_url((string)$source['m3u8']), 'label' => 'LookMovie', 'type' => 'hls', 'provider' => 'lookmovie');
    } else {
        $items = isset($data['sources']) && is_array($data['sources']) ? $data['sources'] : array();
        foreach ($items as $s) {
            if (empty($s['url'])) continue;
            $sources[] = array('url' => easy_proxy_url((string)$s['url']), 'label' => isset($s['label']) ? $s['label'] : 'AnimeCurX', 'type' => isset($s['type']) ? $s['type'] : 'hls', 'provider' => 'animecurx', 'quality' => isset($s['quality']) ? $s['quality'] : '');
        }
    }
    return array($sources, $subtitles);
}

easy_auth();
$action = isset($_GET['action']) ? $_GET['action'] : 'providers';
if ($action === 'providers') {
    easy_json(array('ok' => true, 'providers' => array(
        array('id' => 'lookmovie', 'name' => 'LookMovie', 'type' => 'hls'),
        array('id' => 'animecurx', 'name' => 'AnimeCurX', 'type' => 'hls')
    ), 'gateway' => 'easy-v1'));
}
if ($action !== 'sources') easy_fail('Use action=providers or action=sources', 400);
$provider = strtolower(isset($_GET['provider']) ? $_GET['provider'] : '');
$type = (isset($_GET['type']) && $_GET['type'] === 'tv') ? 'tv' : 'movie';
$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$season = max(1, (int)(isset($_GET['season']) ? $_GET['season'] : (isset($_GET['s']) ? $_GET['s'] : 1)));
$episode = max(1, (int)(isset($_GET['episode']) ? $_GET['episode'] : (isset($_GET['e']) ? $_GET['e'] : 1)));
if (!in_array($provider, array('lookmovie', 'animecurx'), true) || $id < 1) easy_fail('Invalid provider or id', 400);
$r = easy_fetch(easy_provider_url($provider, $type, $id, $season, $episode));
$data = json_decode($r['body'], true);
if ($r['code'] < 200 || $r['code'] >= 300 || !is_array($data)) easy_fail('Provider unavailable', 502);
$normalized = easy_normalize($provider, $data);
easy_json(array('ok' => !empty($normalized[0]), 'provider' => $provider, 'type' => $type, 'id' => $id, 'season' => $type === 'tv' ? $season : null, 'episode' => $type === 'tv' ? $episode : null, 'sources' => $normalized[0], 'subtitles' => $normalized[1], 'gateway' => 'easy-v1'));
function easy_fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 70,
        CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'AFVeo-Easy-Gateway/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*'],
    ]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($body) ? $body : ''];
}
function easy_auth(): void {
    $provided = (string)($_SERVER['HTTP_X_EASY_KEY'] ?? $_GET['key'] ?? '');
    // Replace EASY_KEY before deployment. The key is an additional barrier, not DRM.
    if (EASY_KEY !== 'CHANGE_THIS_EASY_KEY' && !hash_equals(EASY_KEY, $provided)) easy_fail('unauthorized', 401);
}
function easy_provider_url(string $provider, string $type, int $id, int $season, int $episode): string {
    $q = http_build_query(['type' => $type, 'id' => $id, 'season' => $season, 'episode' => $episode]);
    if ($provider === 'lookmovie') return easy_url('/api/lookmovie/index.php?' . $q);
    if ($provider === 'animecurx') {
        return easy_url('/api/animecurx/index.php?action=streams&format=player&src=animecurx&' .
            http_build_query(['type' => $type, 'id' => $id, 's' => $season, 'e' => $episode]));
    }
    easy_fail('unknown provider');
}
function easy_normalize(string $provider, array $data): array {
    $sources = []; $subtitles = [];
    if ($provider === 'lookmovie') {
        $source = is_array($data['source'] ?? null) ? $data['source'] : [];
        $qualities = is_array($source['qualities'] ?? null) ? $source['qualities'] : [];
        foreach ($qualities as $q) {
            $url = (string)($q['url'] ?? ''); if ($url === '') continue;
            $sources[] = ['url' => easy_proxy_url($url), 'label' => (string)($q['label'] ?? 'Source'), 'type' => 'hls', 'provider' => 'lookmovie'];
        }
        if (!$sources && !empty($source['m3u8'])) $sources[] = ['url' => easy_proxy_url((string)$source['m3u8']), 'label' => 'LookMovie', 'type' => 'hls', 'provider' => 'lookmovie'];
        foreach (($source['subtitles'] ?? []) as $s) if (!empty($s['url'])) $subtitles[] = ['url' => easy_proxy_url((string)$s['url']), 'label' => $s['label'] ?? 'Subtitle', 'language' => $s['language'] ?? ''];
    } else {
        foreach (($data['sources'] ?? []) as $s) {
            if (empty($s['url'])) continue;
            $sources[] = ['url' => easy_proxy_url((string)$s['url']), 'label' => $s['label'] ?? 'AnimeCurX', 'type' => $s['type'] ?? 'hls', 'provider' => 'animecurx', 'quality' => $s['quality'] ?? ''];
        }
        foreach (($data['subtitles'] ?? []) as $s) if (!empty($s['url'])) $subtitles[] = ['url' => easy_proxy_url((string)$s['url']), 'label' => $s['label'] ?? 'Subtitle', 'language' => $s['language'] ?? ''];
    }
    return [$sources, $subtitles];
}

easy_auth();
$action = (string)($_GET['action'] ?? 'providers');
if ($action === 'providers') {
    easy_json(['ok' => true, 'providers' => [
        ['id' => 'lookmovie', 'name' => 'LookMovie', 'type' => 'hls'],
        ['id' => 'animecurx', 'name' => 'AnimeCurX', 'type' => 'hls'],
    ], 'gateway' => 'easy-v1']);
}
if ($action !== 'sources') easy_fail('Use action=providers or action=sources');
$provider = strtolower((string)($_GET['provider'] ?? ''));
$type = (($_GET['type'] ?? 'movie') === 'tv') ? 'tv' : 'movie';
$id = (int)($_GET['id'] ?? 0); $season = max(1, (int)($_GET['season'] ?? $_GET['s'] ?? 1)); $episode = max(1, (int)($_GET['episode'] ?? $_GET['e'] ?? 1));
if (!in_array($provider, ['lookmovie', 'animecurx'], true) || $id < 1) easy_fail('Invalid provider or id');
$url = easy_provider_url($provider, $type, $id, $season, $episode);
$r = easy_fetch($url); $data = json_decode($r['body'], true);
if ($r['code'] < 200 || $r['code'] >= 300 || !is_array($data)) easy_fail('Provider unavailable', 502);
[$sources, $subtitles] = easy_normalize($provider, $data);
easy_json(['ok' => !empty($sources), 'provider' => $provider, 'type' => $type, 'id' => $id, 'season' => $type === 'tv' ? $season : null, 'episode' => $type === 'tv' ? $episode : null, 'sources' => $sources, 'subtitles' => $subtitles, 'gateway' => 'easy-v1']);
        CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*'],
    ]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($body) ? $body : ''];
}
function easy_auth(): void {
    $provided = (string)($_SERVER['HTTP_X_EASY_KEY'] ?? $_GET['key'] ?? '');
    // Replace EASY_KEY before deployment. The key is an additional barrier, not DRM.
    if (EASY_KEY !== 'CHANGE_THIS_EASY_KEY' && !hash_equals(EASY_KEY, $provided)) easy_fail('unauthorized', 401);
}
function easy_provider_url(string $provider, string $type, int $id, int $season, int $episode): string {
    $q = http_build_query(['type' => $type, 'id' => $id, 'season' => $season, 'episode' => $episode]);
    if ($provider === 'lookmovie') return easy_url('/api/lookmovie/index.php?' . $q);
    if ($provider === 'animecurx') {
        return easy_url('/api/animecurx/index.php?action=streams&format=player&src=animecurx&' .
            http_build_query(['type' => $type, 'id' => $id, 's' => $season, 'e' => $episode]));
    }
    easy_fail('unknown provider');
}
function easy_normalize(string $provider, array $data): array {
    $sources = []; $subtitles = [];
    if ($provider === 'lookmovie') {
        $source = is_array($data['source'] ?? null) ? $data['source'] : [];
        $qualities = is_array($source['qualities'] ?? null) ? $source['qualities'] : [];
        foreach ($qualities as $q) {
            $url = (string)($q['url'] ?? ''); if ($url === '') continue;
            $sources[] = ['url' => easy_proxy_url($url), 'label' => (string)($q['label'] ?? 'Source'), 'type' => 'hls', 'provider' => 'lookmovie'];
        }
        if (!$sources && !empty($source['m3u8'])) $sources[] = ['url' => easy_proxy_url((string)$source['m3u8']), 'label' => 'LookMovie', 'type' => 'hls', 'provider' => 'lookmovie'];
        foreach (($source['subtitles'] ?? []) as $s) if (!empty($s['url'])) $subtitles[] = ['url' => easy_proxy_url((string)$s['url']), 'label' => $s['label'] ?? 'Subtitle', 'language' => $s['language'] ?? ''];
    } else {
        foreach (($data['sources'] ?? []) as $s) {
            if (empty($s['url'])) continue;
            $sources[] = ['url' => easy_proxy_url((string)$s['url']), 'label' => $s['label'] ?? 'AnimeCurX', 'type' => $s['type'] ?? 'hls', 'provider' => 'animecurx', 'quality' => $s['quality'] ?? ''];
        }
        foreach (($data['subtitles'] ?? []) as $s) if (!empty($s['url'])) $subtitles[] = ['url' => easy_proxy_url((string)$s['url']), 'label' => $s['label'] ?? 'Subtitle', 'language' => $s['language'] ?? ''];
    }
    return [$sources, $subtitles];
}

easy_auth();
$action = (string)($_GET['action'] ?? 'providers');
if ($action === 'providers') {
    easy_json(['ok' => true, 'providers' => [
        ['id' => 'lookmovie', 'name' => 'LookMovie', 'type' => 'hls'],
        ['id' => 'animecurx', 'name' => 'AnimeCurX', 'type' => 'hls'],
    ], 'gateway' => 'easy-v1']);
}
if ($action !== 'sources') easy_fail('Use action=providers or action=sources');
$provider = strtolower((string)($_GET['provider'] ?? ''));
$type = (($_GET['type'] ?? 'movie') === 'tv') ? 'tv' : 'movie';
$id = (int)($_GET['id'] ?? 0); $season = max(1, (int)($_GET['season'] ?? $_GET['s'] ?? 1)); $episode = max(1, (int)($_GET['episode'] ?? $_GET['e'] ?? 1));
if (!in_array($provider, ['lookmovie', 'animecurx'], true) || $id < 1) easy_fail('Invalid provider or id');
$url = easy_provider_url($provider, $type, $id, $season, $episode);
$r = easy_fetch($url); $data = json_decode($r['body'], true);
if ($r['code'] < 200 || $r['code'] >= 300 || !is_array($data)) easy_fail('Provider unavailable', 502);
[$sources, $subtitles] = easy_normalize($provider, $data);
easy_json(['ok' => !empty($sources), 'provider' => $provider, 'type' => $type, 'id' => $id, 'season' => $type === 'tv' ? $season : null, 'episode' => $type === 'tv' ? $episode : null, 'sources' => $sources, 'subtitles' => $subtitles, 'gateway' => 'easy-v1']);
