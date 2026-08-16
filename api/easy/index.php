<?php
/**
 * AFVeo Easy Gateway
 * نفس أسلوب stream.php القديم: طلب providers ثم sources.
 */
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Easy-Key');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('EASY_KEY', '7');

function easy_error($message, $code) {
    http_response_code((int)$code);
    echo json_encode(array('ok' => false, 'error' => $message, 'code' => (int)$code));
    exit;
}

function easy_ok($data) {
    $out = array('ok' => true);
    foreach ($data as $key => $value) $out[$key] = $value;
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function easy_auth() {
    $key = '';
    if (isset($_SERVER['HTTP_X_EASY_KEY'])) $key = (string)$_SERVER['HTTP_X_EASY_KEY'];
    elseif (isset($_GET['key'])) $key = (string)$_GET['key'];
    if (EASY_KEY !== '' && $key !== EASY_KEY) easy_error('unauthorized', 401);
}

function easy_base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    return $scheme . '://' . $host;
}

function easy_url($path) { return easy_base_url() . $path; }

function easy_proxy_url($url) {
    $encoded = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    return easy_url('/api/easy/proxy.php?u=' . rawurlencode($encoded));
}

function easy_http_get($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 70);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_USERAGENT, 'AFVeo-Easy-Gateway/1.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json, text/plain, */*'));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => ($body === false ? '' : $body));
}

function easy_provider_url($provider, $type, $id, $season, $episode) {
    if ($provider === 'lookmovie') {
        return easy_url('/api/lookmovie/index.php?type=' . rawurlencode($type) . '&id=' . (int)$id . '&season=' . (int)$season . '&episode=' . (int)$episode);
    }
    if ($provider === 'animecurx') {
        return easy_url('/api/animecurx/index.php?action=streams&format=player&src=animecurx&type=' . rawurlencode($type) . '&id=' . (int)$id . '&s=' . (int)$season . '&e=' . (int)$episode);
    }
    easy_error('unknown provider', 400);
}

function easy_sources($provider, $payload) {
    $sources = array();
    $subtitles = array();

    if ($provider === 'lookmovie') {
        $source = (isset($payload['source']) && is_array($payload['source'])) ? $payload['source'] : array();
        if (isset($source['qualities']) && is_array($source['qualities'])) {
            foreach ($source['qualities'] as $quality) {
                if (empty($quality['url'])) continue;
                $sources[] = array(
                    'url' => easy_proxy_url((string)$quality['url']),
                    'label' => isset($quality['label']) ? $quality['label'] : 'HD',
                    'type' => 'hls',
                    'provider' => 'lookmovie'
                );
            }
        }
        if (count($sources) === 0 && !empty($source['m3u8'])) {
            $sources[] = array('url' => easy_proxy_url((string)$source['m3u8']), 'label' => 'LookMovie', 'type' => 'hls', 'provider' => 'lookmovie');
        }
        if (isset($source['subtitles']) && is_array($source['subtitles'])) {
            foreach ($source['subtitles'] as $subtitle) {
                if (empty($subtitle['url'])) continue;
                $subtitles[] = array('url' => easy_proxy_url((string)$subtitle['url']), 'label' => isset($subtitle['label']) ? $subtitle['label'] : 'Subtitle', 'language' => isset($subtitle['language']) ? $subtitle['language'] : '');
            }
        }
    } else {
        $items = (isset($payload['sources']) && is_array($payload['sources'])) ? $payload['sources'] : array();
        foreach ($items as $item) {
            if (empty($item['url'])) continue;
            $sources[] = array(
                'url' => easy_proxy_url((string)$item['url']),
                'label' => isset($item['label']) ? $item['label'] : 'AnimeCurX',
                'type' => isset($item['type']) ? $item['type'] : 'hls',
                'provider' => 'animecurx',
                'quality' => isset($item['quality']) ? $item['quality'] : ''
            );
        }
        if (isset($payload['subtitles']) && is_array($payload['subtitles'])) {
            foreach ($payload['subtitles'] as $subtitle) {
                if (empty($subtitle['url'])) continue;
                $subtitles[] = array('url' => easy_proxy_url((string)$subtitle['url']), 'label' => isset($subtitle['label']) ? $subtitle['label'] : 'Subtitle', 'language' => isset($subtitle['language']) ? $subtitle['language'] : '');
            }
        }
    }
    return array('sources' => $sources, 'subtitles' => $subtitles);
}

easy_auth();
$action = isset($_GET['action']) ? $_GET['action'] : 'providers';

if ($action === 'providers') {
    easy_ok(array('providers' => array(
        array('id' => 'lookmovie', 'name' => 'LookMovie', 'type' => 'hls'),
        array('id' => 'animecurx', 'name' => 'AnimeCurX', 'type' => 'hls')
    )));
}

if ($action !== 'sources') easy_error('action غير معروف', 400);
$provider = isset($_GET['provider']) ? strtolower($_GET['provider']) : '';
$type = (isset($_GET['type']) && $_GET['type'] === 'tv') ? 'tv' : 'movie';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$season = isset($_GET['season']) ? max(1, (int)$_GET['season']) : 1;
$episode = isset($_GET['episode']) ? max(1, (int)$_GET['episode']) : 1;
if ($id < 1 || ($provider !== 'lookmovie' && $provider !== 'animecurx')) easy_error('provider أو id غير صالح', 400);

$response = easy_http_get(easy_provider_url($provider, $type, $id, $season, $episode));
$payload = json_decode($response['body'], true);
if ($response['code'] < 200 || $response['code'] >= 300 || !is_array($payload)) easy_error('Provider unavailable', 502);

$normalized = easy_sources($provider, $payload);
easy_ok(array('provider' => $provider, 'type' => $type, 'id' => $id, 'season' => ($type === 'tv' ? $season : null), 'episode' => ($type === 'tv' ? $episode : null), 'sources' => $normalized['sources'], 'subtitles' => $normalized['subtitles']));

easy_error('action غير معروف', 400);
