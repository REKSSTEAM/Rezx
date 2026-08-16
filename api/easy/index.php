<?php
/**
 * Easy Gateway — one public API for AFVeo.
 * The mobile app must call this file only.
 *
 * GET ?action=providers
 * GET ?action=sources&provider=lookmovie&type=movie&id=550
 * GET ?action=sources&provider=animecurx&type=tv&id=1396&season=1&episode=1
 */
declare(strict_types=1);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

const EASY_KEY = '7';
const EASY_TTL = 180;

function easy_fail(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function easy_json(array $data): never {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function easy_origin(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function easy_url(string $path): string { return easy_origin() . $path; }
function easy_proxy_url(string $url): string {
    $u = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    return easy_url('/api/easy/proxy.php?u=' . rawurlencode($u));
}
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
