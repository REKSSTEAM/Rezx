<?php
/**
 * AFVeo Stream API — v1
 * ─────────────────────────────────────────────────────────────────
 * الحماية: توكن HMAC موقّع بـ APP_SECRET مال الموقع
 *
 * الخطوة 1 — احصل على توكن:
 *   GET ?action=token&type=movie&id=550
 *   → { token, expires_at, refresh_at }
 *
 * الخطوة 2 — اطلب الروابط:
 *   GET ?action=sources&type=movie&id=550&token=TOKEN
 *   للمسلسلات: أضف &season=1&episode=1
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
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

// ── توليد توكن API (يدوم 24 ساعة) ───────────────────────────────────────────
function api_make_token(string $type, int $id): array {
    $secret  = APP_SECRET;
    $exp     = time() + 86400; // 24 ساعة
    $marker  = 'apiv1';
    $payload = "{$type}|{$id}|{$exp}|{$marker}";
    $sig     = hash_hmac('sha256', $payload, $secret);
    $token   = rtrim(base64_encode("{$payload}|{$sig}"), '=');
    return [
        'token'      => $token,
        'expires_at' => $exp,
        'refresh_at' => $exp - 3600, // جدد قبل ساعة
    ];
}

// ── التحقق من توكن API ────────────────────────────────────────────────────────
function api_verify_token(string $token, string $type, int $id): void {
    if (!$token) api_err('token مطلوب', 401);

    $secret = APP_SECRET;
    $pad    = strlen($token) % 4;
    $padded = $pad ? $token . str_repeat('=', 4 - $pad) : $token;
    $decoded = base64_decode($padded, true);
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
        'arabic'=>'ar','عربي'=>'ar','عربية'=>'ar',
        'english'=>'en','french'=>'fr','spanish'=>'es',
        'german'=>'de','turkish'=>'tr','persian'=>'fa',
        'russian'=>'ru','italian'=>'it','portuguese'=>'pt',
        'dutch'=>'nl','polish'=>'pl','czech'=>'cs',
        'slovak'=>'sk','romanian'=>'ro','greek'=>'el',
        'hebrew'=>'he','japanese'=>'ja','korean'=>'ko',
        'chinese'=>'zh','vietnamese'=>'vi','indonesian'=>'id',
        'malay'=>'ms','thai'=>'th','hindi'=>'hi',
        'urdu'=>'ur','tamil'=>'ta','ukrainian'=>'uk',
        'norwegian'=>'no','danish'=>'da','finnish'=>'fi',
        'swedish'=>'sv','bosnian'=>'bs','serbian'=>'sr','uzbek'=>'uz',
    ];
    static $labels = [
        'ar'=>'Arabic','en'=>'English','fr'=>'French','es'=>'Spanish',
        'de'=>'German','tr'=>'Turkish','fa'=>'Persian','ru'=>'Russian',
        'it'=>'Italian','pt'=>'Portuguese','nl'=>'Dutch','pl'=>'Polish',
        'cs'=>'Czech','sk'=>'Slovak','ro'=>'Romanian','el'=>'Greek',
        'he'=>'Hebrew','ja'=>'Japanese','ko'=>'Korean','zh'=>'Chinese',
        'vi'=>'Vietnamese','id'=>'Indonesian','ms'=>'Malay','th'=>'Thai',
        'hi'=>'Hindi','ur'=>'Urdu','ta'=>'Tamil','uk'=>'Ukrainian',
        'no'=>'Norwegian','da'=>'Danish','fi'=>'Finnish','sv'=>'Swedish',
        'bs'=>'Bosnian','sr'=>'Serbian','uz'=>'Uzbek',
    ];

    $lower = strtolower($label);
    foreach ($map as $word => $code)
        if (str_contains($lower, $word))
            return ['language' => $code, 'label' => $labels[$code] ?? ucfirst($word)];

    // كود اللغة من URL مثل ar_xxx.vtt
    if (preg_match('#[/_]([a-z]{2})[-_][a-f0-9]{8,}\.vtt#i', $url, $m)) {
        $code = strtolower($m[1]);
        if (isset($labels[$code])) return ['language' => $code, 'label' => $labels[$code]];
    }
    if (preg_match('/^zh/i', $lower)) return ['language' => 'zh', 'label' => 'Chinese'];
    if (preg_match('/^pt/i', $lower)) return ['language' => 'pt', 'label' => 'Portuguese'];

    return ['language' => 'unknown', 'label' => ucwords($label)];
}

// ── Normalize provider output → UnifiedSource ─────────────────────────────────
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

    // Normalize subtitles
    $subtitles = []; $seen = [];
    foreach ($raw_subs as $sub) {
        if (empty($sub['url'])) continue;
        $url  = $unify($sub['url']);
        $lang = detect_lang($sub['label'] ?? $sub['language'] ?? '', $url);
        $key  = $lang['language'] . '|' . md5($url);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $subtitles[] = [
            'language' => $lang['language'],
            'label'    => $lang['label'],
            'url'      => $url,
            'format'   => $sub['format'] ?? 'vtt',
        ];
    }

    // العربية أولاً، الإنجليزية ثانياً
    usort($subtitles, function($a, $b) {
        $o = ['ar' => 0, 'en' => 1];
        $oa = $o[$a['language']] ?? 99;
        $ob = $o[$b['language']] ?? 99;
        return $oa !== $ob ? $oa - $ob : strcmp($a['label'], $b['label']);
    });

    $ok = !empty($qualities);
    return [
        'id'            => $provider['id'],
        'name'          => $provider['name'],
        'ok'            => $ok,
        'type'          => 'hls',
        'qualities'     => array_values($qualities),
        'subtitles'     => array_values($subtitles),
        'subtitle_count'=> count($subtitles),
        'error'         => $ok ? null : ($payload['error'] ?? 'No sources'),
    ];
}

// ── استدعاء provider داخلياً ──────────────────────────────────────────────────
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
    try { set_time_limit(20); include $provider['file']; }
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

// ── token ─────────────────────────────────────────────────────────────────────
if ($action === 'token') {
    if ($id < 1) api_err('id مطلوب');
    api_ok(api_make_token($type, $id));
}

// ── sources ───────────────────────────────────────────────────────────────────
if ($action === 'sources') {
    if ($id < 1) api_err('id مطلوب');
    $season  = max(1, (int)($_GET['season']  ?? 1));
    $episode = max(1, (int)($_GET['episode'] ?? 1));
    $token   = $_GET['token'] ?? '';

    api_verify_token($token, $type, $id);

    set_time_limit(45);

    // ── أضف providers هنا ──
    $providers = [
        ['id' => 'lookmovie', 'name' => 'LookMovie', 'file' => __DIR__ . '/../lookmovie/index.php'],
        ['id' => 'animecurx', 'name' => 'AnimeCurX', 'file' => __DIR__ . '/../animecurx/index.php'],
    ];

    $sources = [];
    foreach ($providers as $p) {
        $r = call_provider($p, $type, $id, $season, $episode);
        if ($r['ok']) $sources[] = $r;
    }

    api_ok([
        'tmdb_id' => $id,
        'type'    => $type,
        'title'   => fetch_title($type, $id),
        'season'  => $type === 'tv' ? $season  : null,
        'episode' => $type === 'tv' ? $episode : null,
        'sources' => $sources,
        'any_ok'  => !empty($sources),
    ]);
}

// ── docs ──────────────────────────────────────────────────────────────────────
if ($action === 'docs' || $action === '') {
    api_ok([
        'api'     => 'AFVeo Stream API v1',
        'base'    => ((!empty($_SERVER['HTTPS']))?'https':'http') . '://' . $_SERVER['HTTP_HOST'] . '/api/v1/stream.php',
        'usage'   => [
            'step1_token'   => '?action=token&type=movie&id=550',
            'step2_sources' => '?action=sources&type=movie&id=550&token=TOKEN',
            'tv_example'    => '?action=sources&type=tv&id=1396&season=1&episode=1&token=TOKEN',
        ],
        'note' => 'التوكن مرتبط بـ id+type، يدوم 24 ساعة، جدده قبل expires_at',
    ]);
}

api_err('action غير معروف', 400);
