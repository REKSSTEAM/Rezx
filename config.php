<?php
// AFVeo Stream API — Render.com config

// منع التشغيل المباشر
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── إعدادات أساسية ──────────────────────────────────────────────
define('SITE_NAME',    'AFVeo');
define('BASE_URL',     getenv('BASE_URL') ?: 'https://your-app.onrender.com');
define('APP_SECRET',   getenv('APP_SECRET') ?: 'afveo_default_secret_change_me');
define('DATA_DIR',     __DIR__ . '/data');

// ── TMDB ────────────────────────────────────────────────────────
define('TMDB_API_KEY', getenv('TMDB_API_KEY') ?: '60a8d6ad3b8e5fbdbde539526b196d9b');
define('TMDB_API_URL', 'https://api.themoviedb.org/3');
define('TMDB_IMG',     'https://image.tmdb.org/t/p');

// ── إنشاء مجلد data ─────────────────────────────────────────────
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// ── تضمين الأمان ────────────────────────────────────────────────
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';
