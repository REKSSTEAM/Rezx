<?php
/*
 * =====================================================================
 *   Streaming API — PHP
 *   يجلب روابط تشغيل الأفلام/المسلسلات من كل المصادر ويوفر بروكسي HLS
 *   وترجمات موحّدة من جميع الخدمات
 *
 *   Endpoints:
 *     GET /?action=providers                    -> قائمة المصادر المتاحة
 *     GET /?action=meta&id={tmdb}&type=movie    -> بيانات الفيلم/المسلسل + ترجمة
 *     GET /?action=streams&id={tmdb}&type=movie [&s=season&e=episode&src=all]
 *                                             -> روابط تشغيل من كل/مصدر معين
 *     GET /?action=translate&id={tmdb}&type=movie [&s=&e=&lang=]
 *                                             -> روابط ترجمة من كل المصادر
 *     GET /proxy/hls.php?url=...                -> بروكسي م3u8 وسيجمات HLS
 *     GET /proxy/redirect.php?url=...           -> إعادة توجيه لمصدر الفيديو مباشرة
 *
 *   مثال: /?action=streams&id=634649&type=movie&src=all
 * =====================================================================
 */

// ---------- إعدادات ----------
error_reporting(0);
@ini_set('display_errors', '0');
set_time_limit(120);

define('SECRET_KEY', 'my-secret-key-2026'); // مفتاح اختياري لحماية API
define('CACHE_TTL', 300);                    // ثواني تخزين الاستجابات (5 دقائق)
define('UA', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');

// ---------- أدوات ----------
function http_get($url, $headers = [], $timeout = 30) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => 'gzip, deflate, br',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => UA,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'ct' => $ct];
}

function http_post($url, $json, $headers = []) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => is_array($json) ? json_encode($json) : $json,
        CURLOPT_USERAGENT      => UA,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

function cache_get($key) {
    $file = __DIR__ . '/cache/' . md5($key) . '.json';
    if (file_exists($file) && (time() - filemtime($file)) < CACHE_TTL) {
        return json_decode(file_get_contents($file), true);
    }
    return null;
}
function cache_set($key, $data) {
    if (!is_dir(__DIR__ . '/cache')) @mkdir(__DIR__ . '/cache', 0777, true);
    file_put_contents(__DIR__ . '/cache/' . md5($key) . '.json', json_encode($data, JSON_UNESCAPED_SLASHES));
}

function send_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function die_error($msg, $code = 400) { send_json(['success' => false, 'error' => $msg], $code); }

// ---------- التحقق من المفتاح (اختياري) ----------
if (isset($_SERVER['HTTP_X_API_KEY']) && $_SERVER['HTTP_X_API_KEY'] !== SECRET_KEY) {
    die_error('Invalid API key', 403);
}

// ---------- القراءة ----------
$action = trim($_GET['action'] ?? '');
$player_format = ($_GET['format'] ?? '') === 'player';
$proxy_prefix = trim($_GET['proxy_prefix'] ?? '/api/animecurx', '/');
$tmdbId = (int)($_GET['id'] ?? 0);
$type   = in_array($_GET['type'] ?? 'movie', ['movie', 'tv']) ? ($_GET['type'] ?? 'movie') : 'movie';
$season = max(1, (int)($_GET['s'] ?? 0));
$ep     = max(1, (int)($_GET['e'] ?? 0));
$src    = trim($_GET['src'] ?? 'all');          // اسم مصدر معين أو all
$lang   = strtolower(trim($_GET['lang'] ?? '')); // فلتر لغة ترجمة

if ($action === '') $action = 'providers';

// =====================================================================
// 1) قائمة المصادر
// =====================================================================
if ($action === 'providers') {
    send_json([
        'success' => true,
        'providers' => [
            ['id' => 'animecurx', 'name' => 'AnimeCurX / Earth', 'type' => 'hls', 'note' => 'يعتمد على token من 7movies'],
            ['id' => 'moviebox',   'name' => 'MovieBox',          'type' => 'mp4'],
            ['id' => 'vidapi',     'name' => 'VidAPI',            'type' => 'hls'],
            ['id' => 'ipcloud',    'name' => 'IPCloud',           'type' => 'mp4', 'note' => 'استخدم sw=1'],
            ['id' => 'tcloud',     'name' => 'TCloud',            'type' => 'mp4', 'note' => 'استخدم sw=1'],
            ['id' => 'xpass',      'name' => 'XPass',             'type' => 'hls'],
            ['id' => 'vidrift',    'name' => 'VidRift',           'type' => 'hls'],
            ['id' => 'lookmovie',  'name' => 'LookMovie',         'type' => 'hls'],
            ['id' => 'vidnest',    'name' => 'VidNest',           'type' => 'hls'],
            ['id' => '1embed',     'name' => '1Embed',            'type' => 'hls'],
            ['id' => 'vidsrc',     'name' => 'VidSrc',            'type' => 'hls'],
            ['id' => 'vidfast',    'name' => 'VidFast (ترجمة)',   'type' => 'subtitle'],
        ],
    ]);
    exit;
}

if ($tmdbId < 1) die_error('Missing or invalid id');

// =====================================================================
// 2) بيانات + ترجمة مختصرة
// =====================================================================
if ($action === 'meta') {
    $key = "meta:$type:$tmdbId";
    $out = cache_get($key);
    if (!$out) {
        $r = http_get("https://api.themoviedb.org/3/$type/$tmdbId?api_key=demo");
        // fallback مجاني بدون مفتاح
        $r = http_get("https://api.tmdb.org/api/v3/movie/$tmdbId");
        if ($r['code'] !== 200) {
            $r = http_get("https://api.themoviedb.org/3/$type/$tmdbId?api_key=demo");
        }
        $m = $r['code'] === 200 ? json_decode($r['body'], true) : null;
        $out = ['success' => true, 'tmdbId' => $tmdbId, 'type' => $type, 'info' => $m];
        cache_set($key, $out);
    }
    send_json($out);
    exit;
}

// =====================================================================
// 3) جلب روابط التشغيل من كل المصادر
// =====================================================================
if ($action === 'streams') {
    $cacheKey = "streams:$type:$tmdbId:$season:$ep:$src:" . ($player_format ? 'player' : 'api');
    $cached = cache_get($cacheKey);
    if ($cached) send_json($cached);

    $streams = [];
    $errors  = [];

    // --- animecurx / earth ---
    if ($src === 'all' || $src === 'animecurx') {
        $tok = get_animecurx_token($tmdbId, $type, $season, $ep);
        if ($tok) {
            $srcs = get_animecurx_sources($tmdbId, $type, $season, $ep, $tok);
            foreach ($srcs as $i => $s) {
                $streams[] = [
                    'source' => 'animecurx', 'label' => 'Earth #' . ($i + 1),
                    'type'   => $s['type'] ?? 'hls',
                    'url'    => resolve_proxy_url($s['proxyUrl'] ?? ''),
                    'provider' => $s['provider'] ?? 'Earth',
                ];
            }
            // الترجمات المضمنة
            $subs = get_animecurx_subtitles($tmdbId, $type, $tok, $season, $ep);
            foreach ($subs as $sub) {
                $sub['url'] = provider_proxy_url('redirect', $sub['url']);
                $streams[] = ['source' => 'animecurx', 'type' => 'subtitle',
                              'label' => 'TR: ' . ($sub['label'] ?? $sub['code'] ?? ''),
                              'lang' => $sub['code'] ?? '', 'url' => $sub['url'], 'format' => 'vtt'];
            }
        } else {
            $errors[] = 'animecurx: unable to get token';
        }
    }

    // --- ballerinacappuccina sources ---
    $bcSources = ['moviebox', 'vidapi', 'ipcloud', 'tcloud', 'xpass', 'vidrift', 'lookmovie', 'vidnest', '1embed', 'vidsrc'];
    foreach ($bcSources as $bcs) {
        if ($src !== 'all' && $src !== $bcs) continue;
        $extra = in_array($bcs, ['ipcloud', 'tcloud']) ? '&sw=1' : (in_array($bcs, ['moviebox']) ? '&hevc=1' : '');
        $data  = get_bc_source($tmdbId, $bcs, $extra);
        if (!$data) { $errors[] = "$bcs: no response"; continue; }
        if (isset($data['source']) && is_array($data['source'])) {
            $s = $data['source'];
            if (isset($s['url'])) {
                $streams[] = ['source' => $bcs, 'label' => $s['label'] ?? ucfirst($bcs),
                              'type' => 'hls', 'url' => provider_proxy_url('hls', $s['url']),
                              'provider' => $s['source'] ?? $bcs];
            }
            if (!empty($s['qualities'])) {
                foreach ($s['qualities'] as $q) {
                    $streams[] = ['source' => $bcs, 'label' => ($s['label'] ?? ucfirst($bcs)) . ' ' . ($q['quality'] ?? ''),
                                  'type' => ($q['type'] ?? 'mp4') === 'mp4' ? 'mp4' : 'hls',
                                  'url' => provider_proxy_url('redirect', $q['url']),
                                  'quality' => $q['quality'] ?? '', 'codec' => $q['codec'] ?? ''];
                }
            }
        }
        // الترجمات
        if (!empty($data['subtitles'])) {
            foreach ($data['subtitles'] as $sub) {
                if (($sub['source'] ?? '') === 'easter-egg') continue;
                $streams[] = ['source' => $bcs, 'type' => 'subtitle',
                              'label' => 'TR: ' . ($sub['label'] ?? ''), 'lang' => strtolower($sub['label'] ?? ''),
                              'url' => provider_proxy_url('redirect', $sub['file'] ?? ''), 'format' => $sub['type'] ?? 'vtt'];
            }
        }
    }

    if ($player_format) {
        $sources = [];
        $subtitles = [];
        foreach ($streams as $stream) {
            if (($stream['type'] ?? '') === 'subtitle') {
                $subtitles[] = [
                    'url'      => $stream['url'] ?? '',
                    'label'    => $stream['label'] ?? $stream['lang'] ?? 'Subtitle',
                    'language' => $stream['lang'] ?? '',
                    'format'   => $stream['format'] ?? 'vtt',
                ];
                continue;
            }
            if (!empty($stream['url'])) {
                $sources[] = [
                    'url'      => $stream['url'],
                    'label'    => $stream['label'] ?? ucfirst($stream['source'] ?? 'Source'),
                    'type'     => $stream['type'] ?? 'hls',
                    'quality'  => $stream['quality'] ?? '',
                    'provider' => $stream['provider'] ?? ($stream['source'] ?? ''),
                ];
            }
        }
        send_json([
            'ok'         => !empty($sources),
            'sources'    => $sources,
            'subtitles'  => $subtitles,
            'error'      => empty($sources) ? implode('; ', $errors) : null,
        ]);
    }

    $out = ['success' => true, 'tmdbId' => $tmdbId, 'type' => $type,
            'season' => $type === 'tv' ? $season : null, 'episode' => $type === 'tv' ? $ep : null,
            'streams' => $streams, 'notes' => $errors];
    cache_set($cacheKey, $out);
    send_json($out);
    exit;
}

// =====================================================================
// 4) الترجمات من كل المصادر (vidfast + المصادر الأخرى)
// =====================================================================
if ($action === 'translate') {
    $cacheKey = "translate:$type:$tmdbId:$season:$ep:$lang";
    $cached = cache_get($cacheKey);
    if ($cached) send_json($cached);

    $subs = [];
    $errors = [];

    // --- vidfast.vc ---
    $vf = get_vidfast_subs($tmdbId, $type, $season, $ep);
    if ($vf) {
        foreach ($vf as $s) {
            $sub = [
                'source'   => 'vidfast',
                'language' => $s['language'] ?? '',
                'label'    => $s['display'] ?? $s['language'] ?? '',
                'encoding' => $s['encoding'] ?? 'UTF-8',
                'url'      => ABS_URL('/proxy/redirect.php?url=' . rawurlencode($s['url'] ?? '')),
                'format'   => 'vtt',
            ];
            if ($lang !== '' && strtolower($sub['language']) !== $lang && strtolower($sub['label']) !== $lang) continue;
            $subs[] = $sub;
        }
    } else {
        $errors[] = 'vidfast: no response';
    }

    // --- animecurx subtitles ---
    $tok = get_animecurx_token($tmdbId, $type, $season, $ep);
    if ($tok) {
        $asubs = get_animecurx_subtitles($tmdbId, $type, $tok, $season, $ep);
        foreach ($asubs as $s) {
            $sub = ['source' => 'animecurx', 'language' => $s['code'] ?? '',
                    'label' => $s['label'] ?? '', 'encoding' => 'UTF-8',
                    'url' => provider_proxy_url('redirect', $s['url'] ?? ''),
                    'format' => 'vtt'];
            if ($lang !== '' && strtolower($sub['language']) !== $lang && strtolower($sub['label']) !== $lang) continue;
            $subs[] = $sub;
        }
    }

    // --- ballerinacappuccina subtitles ---
    foreach (['moviebox', 'vidapi'] as $bcs) {
        $data = get_bc_source($tmdbId, $bcs, $bcs === 'moviebox' ? '&hevc=1' : '');
        if ($data && !empty($data['subtitles'])) {
            foreach ($data['subtitles'] as $subd) {
                if (($subd['source'] ?? '') === 'easter-egg') continue;
                $sub = ['source' => $bcs, 'language' => strtolower($subd['label'] ?? ''),
                        'label' => $subd['label'] ?? '', 'encoding' => 'UTF-8',
                    'url' => provider_proxy_url('redirect', $subd['file'] ?? ''),
                        'format' => $subd['type'] ?? 'vtt'];
                if ($lang !== '' && $sub['language'] !== $lang && strtolower($sub['label']) !== $lang) continue;
                $subs[] = $sub;
            }
        }
    }

    send_json(['success' => true, 'tmdbId' => $tmdbId, 'type' => $type,
               'season' => $type === 'tv' ? $season : null, 'episode' => $type === 'tv' ? $ep : null,
               'subtitles' => $subs, 'notes' => $errors]);
    exit;
}

die_error('Unknown action: ' . $action);

// =====================================================================
// دوال المصادر
// =====================================================================

// ---------- animecurx ----------
function get_animecurx_token($tmdbId, $type, $season = 1, $ep = 1) {
    $r = http_post('https://7movies.in/api/playback-token', [
        'tmdbId'  => $tmdbId,
        'type'    => $type,
        'season'  => $type === 'tv' ? $season : null,
        'episode' => $type === 'tv' ? $ep : null,
    ]);
    if ($r['code'] !== 200) return null;
    $d = json_decode($r['body'], true);
    return $d['token'] ?? null;
}

function get_animecurx_sources($tmdbId, $type, $season, $ep, $token) {
    $path = $type === 'tv' ? "tv/$tmdbId/$season/$ep" : "movie/$tmdbId";
    $url  = 'https://embed.animecurx.tech/api/source/' . $path
          . '?token=' . rawurlencode($token) . '&provider=vaplayer';
    $r = http_get($url, ['Referer: https://embed.animecurx.tech/']);
    if ($r['code'] !== 200) return [];
    $d = json_decode($r['body'], true);
    return $d['streams'] ?? [];
}

function get_animecurx_subtitles($tmdbId, $type, $token, $season = 1, $ep = 1) {
    // قائمة الترجمات مضمنة في صفحة embed
    $path = $type === 'tv' ? "tv/$tmdbId/$season/$ep" : "movie/$tmdbId";
    $url  = 'https://embed.animecurx.tech/embed/' . $path
          . '?source=0&mobileSheets=true&title=&token=' . rawurlencode($token);
    $r = http_get($url, ['Referer: https://embed.animecurx.tech/']);
    if ($r['code'] !== 200) return [];
    $subs = [];
    // نمط JSON للترجمة داخل السكربت: {"code":"en","label":"English","url":"..."}
    if (preg_match_all('/\{"code"\s*:\s*"([^"]+)"\s*,\s*"label"\s*:\s*"([^"]+)"\s*,\s*"url"\s*:\s*"([^"]+)"\s*\}/', $r['body'], $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $subs[] = ['code' => $mm[1], 'label' => $mm[2], 'url' => $mm[3]];
        }
    }
    return $subs;
}

function provider_proxy_url($kind, $url) {
    global $proxy_prefix;
    return ABS_URL('/' . $proxy_prefix . '/proxy.php?mode=' . rawurlencode($kind) . '&url=' . rawurlencode($url));
}

function resolve_proxy_url($relativeUrl) {
    if ($relativeUrl === '') return '';
    if (strpos($relativeUrl, 'http') === 0) return provider_proxy_url('hls', $relativeUrl);
    return provider_proxy_url('hls', 'https://embed.animecurx.tech' . $relativeUrl);
}

// ---------- ballerinacappuccina ----------
function get_bc_source($tmdbId, $source, $extra = '') {
    $key = "bc:$source:$tmdbId:$extra";
    $c = cache_get($key);
    if ($c) return $c;
    $url = 'https://ballerinacappuccinalovestungtungtungsahur.com/movie?id=' . $tmdbId
         . '&mode=json&sources=' . rawurlencode($source) . $extra;
    $r = http_get($url, [
        'Accept: application/json, text/plain, */*',
        'Referer: https://ballerinacappuccinalovestungtungtungsahur.com/',
    ], 45);
    if ($r['code'] !== 200) return null;
    $d = json_decode($r['body'], true);
    if (!$d) return null;
    cache_set($key, $d);
    return $d;
}

// ---------- vidfast (ترجمة) ----------
function get_vidfast_subs($tmdbId, $type, $season = 1, $ep = 1) {
    $params = 'id=' . $tmdbId;
    if ($type === 'tv') $params .= '&type=tv&s=' . $season . '&e=' . $ep;
    $url = 'https://vidfast.vc/wyzie?' . $params;
    $r = http_get($url, [
        'Accept: application/json',
        'Referer: https://vidfast.vc/',
    ]);
    if ($r['code'] !== 200) return null;
    $d = json_decode($r['body'], true);
    if (!is_array($d)) return null;
    return $d;
}

function ABS_URL($path) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return $scheme . '://' . $host . $dir . $path;
}
