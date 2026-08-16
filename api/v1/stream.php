<?php
/**
 * AFVeo Stream API — v2
 * ─────────────────────────────────────────────────────────────────
 * نظام خطوتين:
 *
 * الخطوة 1 — قائمة المصادر (سريع):
 *   GET ?action=providers&type=movie&id=550&token=TOKEN
 *   → { providers: [{id, name}] }
 *
 * الخطوة 2 — روابط مصدر محدد:
 *   GET ?action=sources&type=movie&id=550&provider=lookmovie&token=TOKEN
 *   للمسلسلات: أضف &season=1&episode=1
 *   → { sources:[{qualities,subtitles}] }
 *
 * التوكن:
 *   GET ?action=token&type=movie&id=550
 *   → { token, expires_at, refresh_at }
 */

if (function_exists('opcache_reset')) opcache_reset();
if (function_exists('opcache_invalidate')) opcache_invalidate(__FILE__, true);

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('X-AFVeo-Version: 3.0');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Helpers ───────────────────────────────────────────────────────────────────
function api_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg, 'code' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}
function api_ok(array $data): never {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── قائمة Providers ───────────────────────────────────────────────────────────
function get_providers(): array {
    return [
        [
            'id'   => 'lookmovie',
            'name' => 'LookMovie',
            'file' => __DIR__ . '/../lookmovie/index.php',
        ],
        [
            'id'   => 'animecurx',
            'name' => 'AnimeCurX',
            'file' => __DIR__ . '/../animecurx/index.php',
        ],
    ];
}

// ── توليد توكن (24 ساعة) ─────────────────────────────────────────────────────
function api_make_token(string $type, int $id): array {
    $secret  = APP_SECRET;
    $exp     = time() + 86400;
    $payload = "{$type}|{$id}|{$exp}|apiv1";
    $sig     = hash_hmac('sha256', $payload, $secret);
    return [
        'token'      => rtrim(base64_encode("{$payload}|{$sig}"), '='),
        'expires_at' => $exp,
        'refresh_at' => $exp - 3600,
    ];
}

// ── التحقق من التوكن ──────────────────────────────────────────────────────────
function api_verify_token(string $token, string $type, int $id): void {
    if (!$token) api_err('token مطلوب', 401);
    $secret  = APP_SECRET;
    $pad     = strlen($token) % 4;
    $decoded = base64_decode($pad ? $token . str_repeat('=', 4 - $pad) : $token, true);
    if (!$decoded) api_err('token غير صالح', 401);
    $parts = explode('|', $decoded);
    if (count($parts) !== 5) api_err('token تالف', 401);
    [$tok_type, $tok_id, $tok_exp, $marker, $sig] = $parts;
    if ($marker !== 'apiv1') api_err('token غير صالح', 401);
    if ((int)$tok_exp < time()) api_err('token منتهي — اطلب توكناً جديداً', 401);
    if ($tok_type !== $type || (int)$tok_id !== $id) api_err('token لا يطابق الطلب', 401);
    $expected = hash_hmac('sha256', "{$tok_type}|{$tok_id}|{$tok_exp}|{$marker}", $secret);
    if (!hash_equals($expected, $sig)) api_err('توقيع token خاطئ', 401);
}

// ── Subtitle language detector ─────────────────────────────────────────────────
function detect_lang(string $label, string $url = ''): array {
    static $map = [
        'arabic'=>'ar','عربي'=>'ar','عربية'=>'ar','english'=>'en','french'=>'fr',
        'spanish'=>'es','german'=>'de','turkish'=>'tr','persian'=>'fa','russian'=>'ru',
        'italian'=>'it','portuguese'=>'pt','dutch'=>'nl','polish'=>'pl','czech'=>'cs',
        'slovak'=>'sk','romanian'=>'ro','greek'=>'el','hebrew'=>'he','japanese'=>'ja',
        'korean'=>'ko','chinese'=>'zh','vietnamese'=>'vi','indonesian'=>'id','malay'=>'ms',
        'thai'=>'th','hindi'=>'hi','urdu'=>'ur','tamil'=>'ta','ukrainian'=>'uk',
        'norwegian'=>'no','danish'=>'da','finnish'=>'fi','swedish'=>'sv','bosnian'=>'bs',
        'serbian'=>'sr','uzbek'=>'uz',
    ];
    static $labels = [
        'ar'=>'Arabic','en'=>'English','fr'=>'French','es'=>'Spanish','de'=>'German',
        'tr'=>'Turkish','fa'=>'Persian','ru'=>'Russian','it'=>'Italian','pt'=>'Portuguese',
        'nl'=>'Dutch','pl'=>'Polish','cs'=>'Czech','sk'=>'Slovak','ro'=>'Romanian',
        'el'=>'Greek','he'=>'Hebrew','ja'=>'Japanese','ko'=>'Korean','zh'=>'Chinese',
        'vi'=>'Vietnamese','id'=>'Indonesian','ms'=>'Malay','th'=>'Thai','hi'=>'Hindi',
        'ur'=>'Urdu','ta'=>'Tamil','uk'=>'Ukrainian','no'=>'Norwegian','da'=>'Danish',
        'fi'=>'Finnish','sv'=>'Swedish','bs'=>'Bosnian','sr'=>'Serbian','uz'=>'Uzbek',
    ];
    $lower = strtolower($label);
    foreach ($map as $word => $code)
        if (str_contains($lower, $word))
            return ['language' => $code, 'label' => $labels[$code] ?? ucfirst($word)];
    if (preg_match('#[/_]([a-z]{2})[-_][a-f0-9]{8,}\.vtt#i', $url, $m)) {
        $code = strtolower($m[1]);
        if (isset($labels[$code])) return ['language' => $code, 'label' => $labels[$code]];
    }
    if (str_starts_with($lower, 'zh')) return ['language' => 'zh', 'label' => 'Chinese'];
    if (str_starts_with($lower, 'pt')) return ['language' => 'pt', 'label' => 'Portuguese'];
    return ['language' => 'unknown', 'label' => ucwords($label)];
}

// ── Normalize provider output ─────────────────────────────────────────────────
function normalize_provider(array $provider, ?array $payload): array {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $unify  = function(string $url) use ($origin): string {
        if ($url === '') return '';
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) return $url;
        return $origin . '/' . ltrim($url, '/');
    };

    $qualities = []; $raw_subs = [];

    if (is_array($payload) && !empty($payload['ok'])) {
        if (isset($payload['source']) && is_array($payload['source'])) {
            $src = $payload['source'];
            if (!empty($src['qualities']))
                foreach ($src['qualities'] as $q)
                    if (!empty($q['url'])) $qualities[] = ['label' => $q['label'] ?? 'HD', 'url' => $unify($q['url'])];
            elseif (!empty($src['m3u8']))
                $qualities[] = ['label' => $src['quality'] ?? 'HD', 'url' => $unify($src['m3u8'])];
            $raw_subs = is_array($src['subtitles'] ?? null) ? $src['subtitles'] : [];
        } elseif (!empty($payload['servers'])) {
            foreach ($payload['servers'] as $srv)
                foreach ($srv['streams'] ?? [] as $st)
                    if (!empty($st['url'])) $qualities[] = ['label' => $st['label'] ?? ($srv['name'] ?? 'HD'), 'url' => $unify($st['url'])];
            $raw_subs = $payload['subtitles'] ?? [];
        } elseif (!empty($payload['sources'])) {
            foreach ($payload['sources'] as $s)
                if (!empty($s['url'])) $qualities[] = ['label' => $s['label'] ?? 'HD', 'url' => $unify($s['url'])];
            $raw_subs = $payload['subtitles'] ?? [];
        }
    }

    // normalize subtitles
    $subtitles = []; $seen = [];
    foreach ($raw_subs as $sub) {
        if (empty($sub['url'])) continue;
        $url  = $unify($sub['url']);
        $lang = detect_lang($sub['label'] ?? $sub['language'] ?? '', $url);
        $key  = $lang['language'] . '|' . md5($url);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $subtitles[] = ['language' => $lang['language'], 'label' => $lang['label'], 'url' => $url, 'format' => $sub['format'] ?? 'vtt'];
    }
    usort($subtitles, function($a, $b) {
        $o = ['ar' => 0, 'en' => 1];
        $oa = $o[$a['language']] ?? 99;
        $ob = $o[$b['language']] ?? 99;
        return $oa !== $ob ? $oa - $ob : strcmp($a['label'], $b['label']);
    });

    return [
        'id'            => $provider['id'],
        'name'          => $provider['name'],
        'ok'            => !empty($qualities),
        'type'          => 'hls',
        'qualities'     => array_values($qualities),
        'subtitles'     => array_values($subtitles),
        'subtitle_count'=> count($subtitles),
        'error'         => empty($qualities) ? ($payload['error'] ?? 'No sources') : null,
    ];
}

// ── استدعاء provider واحد ────────────────────────────────────────────────────
function call_provider(array $provider, string $type, int $id, int $season, int $episode): array {
    if (!file_exists($provider['file'])) return normalize_provider($provider, null);
    $internal = sec_internal_api_token($type, $id);
    $saved    = $_GET;
    $_GET = ['type' => $type, 'id' => $id, '_st' => $internal, '_ts' => time(), '_nonce' => bin2hex(random_bytes(8))];
    if ($type === 'tv') { $_GET['season'] = $season; $_GET['episode'] = $episode; }
    if ($provider['id'] === 'animecurx') {
        $_GET['action'] = 'streams'; $_GET['format'] = 'player';
        if ($type === 'tv') { $_GET['s'] = $season; $_GET['e'] = $episode; }
    }
    ob_start();
    try { set_time_limit(45); include $provider['file']; }
    catch (Throwable $ex) { ob_clean(); echo json_encode(['ok' => false, 'error' => $ex->getMessage()]); }
    $body = trim(ob_get_clean());
    $_GET = $saved;
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        preg_match('/(\{.+\})/s', $body, $m);
        $payload = !empty($m[1]) ? json_decode($m[1], true) : null;
    }
    return normalize_provider($provider, is_array($payload) ? $payload : null);
}

// ── TMDB title ────────────────────────────────────────────────────────────────
function fetch_title(string $type, int $id): string {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents(TMDB_API_URL . "/{$type}/{$id}?api_key=" . TMDB_API_KEY . "&language=en-US", false, $ctx);
    if (!$raw) return '';
    $d = json_decode($raw, true);
    return $d['title'] ?? $d['name'] ?? '';
}

// ════════════════════════════════════════════════════════════════════════════════
//  ROUTER
// ════════════════════════════════════════════════════════════════════════════════
$action  = $_GET['action'] ?? '';
$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$token   = $_GET['token'] ?? '';

// ── action=token ──────────────────────────────────────────────────────────────
if ($action === 'token') {
    if ($id < 1) api_err('id مطلوب');
    api_ok(api_make_token($type, $id));
}

// ── action=providers — الخطوة 1: قائمة المصادر المتاحة (بدون جلب روابط) ─────
if ($action === 'providers') {
    if ($id < 1) api_err('id مطلوب');
    api_verify_token($token, $type, $id);

    $list = array_map(fn($p) => [
        'id'   => $p['id'],
        'name' => $p['name'],
    ], get_providers());

    api_ok([
        'tmdb_id'   => $id,
        'type'      => $type,
        'title'     => fetch_title($type, $id),
        'providers' => $list,
    ]);
}

// ── action=sources — الخطوة 2: روابط مصدر محدد ───────────────────────────────
if ($action === 'sources') {
    if ($id < 1) api_err('id مطلوب');
    api_verify_token($token, $type, $id);

    $provider_id = $_GET['provider'] ?? '';
    $season      = max(1, (int)($_GET['season']  ?? 1));
    $episode     = max(1, (int)($_GET['episode'] ?? 1));

    $providers = get_providers();

    // إذا طُلب provider محدد
    if ($provider_id) {
        $found = null;
        foreach ($providers as $p) {
            if ($p['id'] === $provider_id) { $found = $p; break; }
        }
        if (!$found) api_err("provider '{$provider_id}' غير موجود");

        set_time_limit(60);
        $result = call_provider($found, $type, $id, $season, $episode);
        api_ok([
            'tmdb_id'  => $id,
            'type'     => $type,
            'provider' => $result['id'],
            'name'     => $result['name'],
            'ok'       => $result['ok'],
            'qualities'     => $result['qualities'],
            'subtitles'     => $result['subtitles'],
            'subtitle_count'=> $result['subtitle_count'],
            'error'    => $result['error'],
        ]);
    }

    // إذا ما طُلب provider — رجع كل المصادر
    set_time_limit(90);
    $sources = [];
    foreach ($providers as $p) {
        $r = call_provider($p, $type, $id, $season, $episode);
        if ($r['ok']) $sources[] = $r;
    }
    api_ok([
        'tmdb_id' => $id,
        'type'    => $type,
        'sources' => $sources,
        'any_ok'  => !empty($sources),
    ]);
}

// ── docs ──────────────────────────────────────────────────────────────────────
if ($action === 'docs' || $action === '') {
    api_ok([
        'api'     => 'AFVeo Stream API v3',
        'version' => '3.0',
        'flow'    => [
            '1_token'     => '?action=token&type=movie&id=550',
            '2_providers' => '?action=providers&type=movie&id=550&token=TOKEN → قائمة المصادر',
            '3_sources'   => '?action=sources&type=movie&id=550&provider=lookmovie&token=TOKEN → روابط التشغيل',
        ],
        'providers' => array_map(fn($p) => $p['id'], get_providers()),
    ]);
}

api_err('action غير معروف — جرّب ?action=docs', 400);
