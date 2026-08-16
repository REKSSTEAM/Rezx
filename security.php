<?php
/**
 * includes/security.php
 * نظام الحماية المركزي — يُحمَّل من config.php قبل أي شيء آخر.
 *
 * يوفر:
 *   • Security HTTP headers (CSP, HSTS, X-Frame-Options, …)
 *   • IP resolution (Cloudflare-aware)
 *   • Rate limiting قائم على الملفات مع Progressive Backoff
 *   • Block management (مؤقت مع تصاعد تلقائي)
 *   • Session security (session fixation, expiry, fingerprint)
 *   • CSRF tokens للنماذج
 *   • Stream-access tokens مربوطة بالجلسة (HMAC-SHA256، قصيرة الصلاحية)
 *   • Nonce system لمنع Replay Attacks
 *   • Timestamp validation لرفض الطلبات القديمة
 *   • Bot / Abuse detection
 *   • CORS origin validation
 *   • Security event logging (بلا بيانات حساسة)
 *   • Input helpers
 */

if (!defined('DATA_DIR')) define('DATA_DIR', __DIR__ . '/../data');

// ══════════════════════════════════════════════════════════════════════════════
//  1. Security HTTP Headers
// ══════════════════════════════════════════════════════════════════════════════

function sec_send_headers(): void {
    if (headers_sent()) return;

    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('X-Request-ID: ' . sec_request_id());

    // HSTS — only over HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    // CSP — مُعدَّل ليشمل cdnjs.cloudflare.com للخطوط مع الحماية الكاملة
    $csp = "default-src 'self'; "
         . "script-src 'self' 'unsafe-inline' 'unsafe-eval' "
             . "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com "
             . "https://kit.fontawesome.com https://ka-f.fontawesome.com; "
         . "style-src 'self' 'unsafe-inline' "
             . "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com "
             . "https://fonts.googleapis.com https://ka-f.fontawesome.com; "
         . "font-src 'self' "
             . "https://fonts.gstatic.com https://ka-f.fontawesome.com "
             . "https://cdnjs.cloudflare.com data:; "
         . "img-src 'self' data: blob: "
             . "https://image.tmdb.org https://www.themoviedb.org "
             . "https://i.ytimg.com https://img.youtube.com "
             . "https://s4.anilist.co https://s1.anilist.co https://media.kitsu.app "
             . "https://img.anili.st https://static.aniwaves.ru; "
         . "media-src 'self' blob: *; "
         . "connect-src 'self' https://api.themoviedb.org https://image.tmdb.org *; "
         . "frame-src *; "
         . "worker-src 'self' blob:; "
         . "object-src 'none';";
    header("Content-Security-Policy: $csp");
}

// ══════════════════════════════════════════════════════════════════════════════
//  2. Client IP — Cloudflare-aware
//  ملاحظة: لا نعتمد على IP وحدها لتحديد المستخدم (NAT/VPN) لكنها مفيدة للـ Rate Limiting
// ══════════════════════════════════════════════════════════════════════════════

function get_client_ip(): string {
    static $ip = null;
    if ($ip !== null) return $ip;

    // CF-Connecting-IP is set by Cloudflare with the real visitor IP
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $candidate = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
                return $ip;
            }
        }
    }
    $ip = '0.0.0.0';
    return $ip;
}

// ══════════════════════════════════════════════════════════════════════════════
//  3. Request ID
// ══════════════════════════════════════════════════════════════════════════════

function sec_request_id(): string {
    static $rid = null;
    if ($rid === null) $rid = bin2hex(random_bytes(8));
    return $rid;
}

// ══════════════════════════════════════════════════════════════════════════════
//  4. Session Fingerprint — يُستخدم لربط الـ Stream Token بالجلسة
//  يعتمد على session_id + UA (دون IP لمراعاة التنقل بين الشبكات)
// ══════════════════════════════════════════════════════════════════════════════

function sec_session_fp(): string {
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 120);
    return substr(hash_hmac('sha256', session_id() . $ua, defined('APP_SECRET') ? APP_SECRET : 'fp_default'), 0, 16);
}

// ══════════════════════════════════════════════════════════════════════════════
//  5. File-based Rate Limiting
// ══════════════════════════════════════════════════════════════════════════════

function sec_rate_limit(string $key, int $max, int $window_sec): bool {
    $dir = DATA_DIR . '/rate_limits';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // Periodic cleanup (1% chance per request)
    if (random_int(1, 100) === 1) {
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < (time() - $window_sec * 3)) @unlink($f);
        }
    }

    $file = $dir . '/' . md5($key) . '.json';
    $now  = time();
    $data = ['count' => 0, 'start' => $now];

    if (is_file($file)) {
        $raw = json_decode((string)file_get_contents($file), true);
        if (is_array($raw) && ($now - (int)($raw['start'] ?? 0)) < $window_sec) {
            $data = $raw;
        }
    }

    $data['count']++;
    file_put_contents($file, json_encode($data), LOCK_EX);
    return $data['count'] <= $max;
}

// ══════════════════════════════════════════════════════════════════════════════
//  6. Progressive Block — حظر تصاعدي (1m → 5m → 15m → 1h → 6h)
// ══════════════════════════════════════════════════════════════════════════════

function sec_block_check(string $key): bool {
    $dir  = DATA_DIR . '/rate_limits/blocks';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . md5($key) . '.json';
    if (!is_file($file)) return true; // غير محظور

    $data  = json_decode((string)file_get_contents($file), true);
    $until = (int)($data['until'] ?? 0);
    if (time() > $until) return true; // انتهت مدة الحظر
    return false; // لا يزال محظوراً
}

function sec_block_set(string $key): void {
    $dir  = DATA_DIR . '/rate_limits/blocks';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . md5($key) . '.json';

    $violations = 1;
    if (is_file($file)) {
        $existing = json_decode((string)file_get_contents($file), true);
        if (is_array($existing)) {
            $violations = max(1, (int)($existing['violations'] ?? 0) + 1);
        }
    }

    // مدد الحظر التصاعدية: 1m → 5m → 15m → 1h → 6h
    $durations = [60, 300, 900, 3600, 21600];
    $duration  = $durations[min($violations - 1, count($durations) - 1)];

    file_put_contents($file, json_encode([
        'violations' => $violations,
        'until'      => time() + $duration,
        'last_seen'  => time(),
    ]), LOCK_EX);

    sec_log('BLOCKED', ['key_hash' => substr(md5($key), 0, 8), 'violations' => $violations, 'duration_sec' => $duration]);
}

function sec_rate_block(string $endpoint = ''): never {
    http_response_code(429);
    header('Content-Type: application/json');
    header('Retry-After: 60');
    sec_log('RATE_LIMITED', ['endpoint' => $endpoint]);
    echo json_encode(['ok' => false, 'error' => 'Too many requests. Please wait and try again.', 'code' => 429]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
//  7. Nonce System — منع Replay Attacks
//  كل nonce يُستخدم مرة واحدة فقط خلال نافذة 15 دقيقة
// ══════════════════════════════════════════════════════════════════════════════

function sec_nonce_consume(string $nonce): bool {
    if (!$nonce || strlen($nonce) < 8 || strlen($nonce) > 128) {
        sec_log('NONCE_INVALID', ['reason' => 'bad_length', 'len' => strlen($nonce)]);
        return false;
    }

    // السماح فقط بالأحرف hex والـ alphanumeric الآمنة
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $nonce)) {
        sec_log('NONCE_INVALID', ['reason' => 'bad_chars']);
        return false;
    }

    $dir  = DATA_DIR . '/rate_limits/nonces';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // تنظيف دوري للـ nonces القديمة (2% احتمال)
    if (random_int(1, 50) === 1) {
        $window = 900; // 15 دقيقة
        foreach (glob($dir . '/*.n') ?: [] as $f) {
            if (filemtime($f) < (time() - $window)) @unlink($f);
        }
    }

    $file = $dir . '/' . md5($nonce) . '.n';

    if (is_file($file)) {
        sec_log('REPLAY_ATTACK', [
            'nonce_hash' => substr(md5($nonce), 0, 8),
            'ip_hash'    => substr(hash('sha256', get_client_ip() . (APP_SECRET ?? '')), 0, 8),
        ]);
        return false; // Nonce مستخدم مسبقاً — Replay Attack
    }

    // تسجيل الـ nonce كمستخدم
    file_put_contents($file, time(), LOCK_EX);
    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
//  8. Timestamp Validation — رفض الطلبات القديمة أو المستقبلية
// ══════════════════════════════════════════════════════════════════════════════

function sec_validate_timestamp(int $ts, int $window_sec = 300): bool {
    if ($ts <= 0) return false;
    $drift = abs(time() - $ts);
    return $drift <= $window_sec;
}

// ══════════════════════════════════════════════════════════════════════════════
//  9. Security Logging (بلا بيانات حساسة)
// ══════════════════════════════════════════════════════════════════════════════

function sec_log(string $event, array $ctx = []): void {
    $dir = DATA_DIR . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $ip_raw  = get_client_ip();
    // نسجّل hash جزئي فقط — لا نحفظ IP كاملة
    $ip_hash = substr(hash('sha256', $ip_raw . (defined('APP_SECRET') ? APP_SECRET : '')), 0, 16);

    $row = [
        't'      => gmdate('Y-m-d H:i:s'),
        'rid'    => sec_request_id(),
        'event'  => $event,
        'ip'     => $ip_hash,
        'uri'    => substr($_SERVER['REQUEST_URI'] ?? '', 0, 200),
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 80),
    ];

    // دمج السياق مع تنظيف أي مفاتيح حساسة
    $sensitive = ['password', 'token', 'api_key', 'secret', 'auth', '_csrf', 'sig', 'key'];
    foreach ($ctx as $k => $v) {
        if (in_array(strtolower((string)$k), $sensitive, true)) continue;
        $row[$k] = $v;
    }

    $log = DATA_DIR . '/logs/sec-' . gmdate('Y-m-d') . '.log';
    file_put_contents($log, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    // تنظيف ملفات أقدم من 7 أيام (0.5% احتمال)
    static $rotated = false;
    if (!$rotated && random_int(1, 200) === 1) {
        $rotated = true;
        foreach (glob(DATA_DIR . '/logs/sec-*.log') ?: [] as $f) {
            if (filemtime($f) < (time() - 7 * 86400)) @unlink($f);
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  10. Session Security
// ══════════════════════════════════════════════════════════════════════════════

function sec_session_guard(): void {
    // تحقق من انتهاء صلاحية الجلسة (8 ساعات من آخر نشاط)
    $ttl = 8 * 3600;
    if (isset($_SESSION['_sec_last']) && (time() - (int)$_SESSION['_sec_last']) > $ttl) {
        session_unset();
        session_regenerate_id(true);
    }
    $_SESSION['_sec_last'] = time();
}

/**
 * يُستدعى مرة واحدة بعد تسجيل الدخول الناجح لمنع Session Fixation.
 */
function sec_session_rotate(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['_sec_last'] = time();
}

// ══════════════════════════════════════════════════════════════════════════════
//  11. CSRF Tokens
// ══════════════════════════════════════════════════════════════════════════════

function sec_csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function sec_csrf_verify(): bool {
    $submitted = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['_csrf']) || !$submitted) return false;
    return hash_equals((string)$_SESSION['_csrf'], (string)$submitted);
}

// ══════════════════════════════════════════════════════════════════════════════
//  12. Stream-Access Tokens — مربوطة بالجلسة، قصيرة الصلاحية، مقاومة للـ Replay
//
//  البنية: {type}|{id}|{exp}|{sess_fp}|{sig}
//  • sess_fp: بصمة الجلسة (session_id + UA) → لا يمكن استخدام التوكن في جلسة أخرى
//  • صلاحية 1 ساعة بدلاً من 6 ساعات
//  • يُستخدم مع nonce في index.php لمنع الـ Replay الكامل
// ══════════════════════════════════════════════════════════════════════════════

function sec_stream_token(string $type, int $id): string {
    $secret   = defined('APP_SECRET') ? APP_SECRET : 'insecure_default';
    $exp      = time() + (1 * 3600); // 1 ساعة (كانت 6 ساعات)
    $sess_fp  = sec_session_fp();
    $payload  = "{$type}|{$id}|{$exp}|{$sess_fp}";
    $sig      = hash_hmac('sha256', $payload, $secret);
    return rtrim(base64_encode("{$payload}|{$sig}"), '=');
}

// توكن داخلي للـ API بدون session fingerprint
function sec_internal_api_token(string $type, int $id): string {
    $secret  = defined('APP_SECRET') ? APP_SECRET : 'insecure_default';
    $exp     = time() + 60; // دقيقة واحدة
    $marker  = 'internal';
    $payload = "{$type}|{$id}|{$exp}|{$marker}";
    $sig     = hash_hmac('sha256', $payload, $secret);
    return rtrim(base64_encode("{$payload}|{$sig}"), '=');
}

function sec_verify_stream_token(string $token, string $type = '', int $id = 0): bool {
    if (!$token) return false;
    $secret  = defined('APP_SECRET') ? APP_SECRET : 'insecure_default';

    // استعادة base64 مع padding صحيح
    $pad     = strlen($token) % 4;
    $padded  = $pad ? $token . str_repeat('=', 4 - $pad) : $token;
    $decoded = base64_decode($padded, true);
    if (!$decoded) return false;

    $parts = explode('|', $decoded);

    // دعم التوكنات: قديمة (4 أجزاء) أو داخلية API (4 أجزاء + internal) أو جديدة (5 أجزاء)
    if (count($parts) === 4) {
        [$tok_type, $tok_id, $tok_exp, $sig] = $parts;
        if ((int)$tok_exp < time()) return false;
        $expected = hash_hmac('sha256', "{$tok_type}|{$tok_id}|{$tok_exp}", $secret);
        if (!hash_equals($expected, $sig)) return false;
        sec_log('LEGACY_TOKEN', ['type' => $tok_type, 'provider_hint' => 'old_format']);
    } elseif (count($parts) === 5 && $parts[3] === 'internal') {
        // توكن داخلي من api/v1 — بدون session fingerprint
        [$tok_type, $tok_id, $tok_exp, $marker, $sig] = $parts;
        if ((int)$tok_exp < time()) return false;
        $expected = hash_hmac('sha256', "{$tok_type}|{$tok_id}|{$tok_exp}|{$marker}", $secret);
        if (!hash_equals($expected, $sig)) return false;
    } elseif (count($parts) === 5) {
        // توكن جديد مع session fingerprint
        [$tok_type, $tok_id, $tok_exp, $tok_sess_fp, $sig] = $parts;

        if ((int)$tok_exp < time()) return false;

        $expected = hash_hmac('sha256', "{$tok_type}|{$tok_id}|{$tok_exp}|{$tok_sess_fp}", $secret);
        if (!hash_equals($expected, $sig)) return false;

        // التحقق من بصمة الجلسة
        $current_fp = sec_session_fp();
        if (!hash_equals((string)$tok_sess_fp, $current_fp)) {
            sec_log('TOKEN_SESSION_MISMATCH', [
                'type' => $tok_type,
                'ip'   => substr(hash('sha256', get_client_ip()), 0, 8),
            ]);
            return false;
        }
    } else {
        return false; // بنية غير معروفة
    }

    if ($type && ($tok_type ?? '') !== $type) return false;
    if ($id   && (int)($tok_id ?? 0) !== $id) return false;

    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
//  13. CORS Origin Validation
//  يتحقق أن الطلب قادم من نفس النطاق (Same-Site)
// ══════════════════════════════════════════════════════════════════════════════

function sec_is_same_origin(): bool {
    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    // إذا لا يوجد Origin ولا Referer → طلب مباشر (curl، PHP، إلخ) → نقبل
    if (!$origin && !$referer) return true;

    $own_host = $_SERVER['HTTP_HOST'] ?? '';
    if (!$own_host) return true; // لا يمكن التحقق → نقبل

    $check = $origin ?: $referer;
    $parsed_host = parse_url($check, PHP_URL_HOST) ?? '';
    if (!$parsed_host) return true; // تعذّر تحليل الـ host → نقبل بتسامح

    // تطبيع: نزيل www والـ port من كلا الجانبين قبل المقارنة
    $normalize = function(string $h): string {
        $h = strtolower(trim($h));
        // إزالة الـ port إذا وُجد (localhost:5000 → localhost)
        $h = preg_replace('/:\d+$/', '', $h);
        // إزالة www.
        $h = preg_replace('/^www\./', '', $h);
        return $h;
    };

    $req_host  = $normalize($parsed_host);
    $self_host = $normalize($own_host);

    // مطابقة مباشرة
    if ($req_host === $self_host) return true;

    // السماح بـ localhost / 127.0.0.1 / ::1 كمصدر واحد (تطوير)
    $localhost_aliases = ['localhost', '127.0.0.1', '::1'];
    if (in_array($req_host, $localhost_aliases, true) && in_array($self_host, $localhost_aliases, true)) {
        return true;
    }

    return false;
}

function sec_assert_same_origin(): void {
    if (!sec_is_same_origin()) {
        sec_log('CROSS_ORIGIN_BLOCKED', [
            'origin'  => substr($_SERVER['HTTP_ORIGIN'] ?? '', 0, 100),
            'referer' => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 100),
        ]);
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Cross-origin request denied.', 'code' => 403]);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  14. Bot / Abuse Detection
//  يكشف الأنماط غير الطبيعية — بدون منع المستخدمين الحقيقيين
// ══════════════════════════════════════════════════════════════════════════════

function sec_bot_score(): int {
    $score = 0;
    $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // UA مشبوه أو فارغ
    if (empty($ua))          $score += 40;
    if (strlen($ua) < 20)    $score += 20;

    // UA يشير صراحة لـ bot/crawler
    $bot_patterns = ['curl/', 'wget/', 'python-', 'scrapy', 'bot/', 'crawler', 'spider', 'headless'];
    foreach ($bot_patterns as $p) {
        if (stripos($ua, $p) !== false) { $score += 30; break; }
    }

    // لا يوجد Accept-Language (المتصفحات الحقيقية ترسله دائماً)
    if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $score += 15;

    // لا يوجد Accept header
    if (empty($_SERVER['HTTP_ACCEPT'])) $score += 10;

    return min(100, $score);
}

function sec_check_bot(int $threshold = 60): void {
    $score = sec_bot_score();
    if ($score >= $threshold) {
        $ip = get_client_ip();
        sec_log('BOT_DETECTED', ['score' => $score, 'ip_hash' => substr(hash('sha256', $ip), 0, 8)]);
        // لا نوقف المستخدم تلقائياً، بل نسجّل ونحدّ الطلبات
        // Rate limit أشد للـ bots المشتبه بها
        if (!sec_rate_limit("bot:{$ip}", 10, 60)) {
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Too many requests.', 'code' => 429]);
            exit;
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  15. API Security Headers — للـ API responses
// ══════════════════════════════════════════════════════════════════════════════

function sec_api_headers(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    // CORS: نسمح فقط بـ same-origin للـ APIs الحساسة
    // (تُعوَّض الـ * الموجودة في الملفات الفردية عبر index.php)
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $own    = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    if ($origin && rtrim($origin, '/') === rtrim($own, '/')) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  16. Input Helpers
// ══════════════════════════════════════════════════════════════════════════════

function sec_int(string $key, int $default = 0): int {
    return (int)($_GET[$key] ?? $_POST[$key] ?? $default);
}

function sec_str(string $key, int $max = 300): string {
    $v = $_GET[$key] ?? $_POST[$key] ?? '';
    return substr(trim((string)$v), 0, $max);
}
