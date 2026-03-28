<?php
// ============================================================
// config/config.php  —  App-wide settings
// ============================================================
//
// ── Local environment ─────────────────────────────────────────
// Change BOTH values below to match your setup. They must stay
// in sync — that's why they live next to each other.
//
//   Setup            APP_URL                                DB_PORT
//   ──────────────────────────────────────────────────────────────
//   WAMP (Windows)   http://localhost/eldershield/src        3306
//   MAMP Pro (Mac)   http://localhost/eldershield/src        3306
//   MAMP Std (Mac)   http://localhost:8888/eldershield/src   8889
// ─────────────────────────────────────────────────────────────

define('APP_URL',  'http://localhost/eldershield/src'); // ← Change if needed
define('DB_PORT',  '3306');                             // ← Change if needed (MAMP Std → 8889)

define('APP_NAME', 'ElderShield');
define('APP_ROOT', __DIR__ . '/..');

// ── Timezone ──────────────────────────────────────────────────
// Set this to your server's local timezone so timestamps display correctly.
// Full list: https://www.php.net/manual/en/timezones.php
define('APP_TIMEZONE', 'America/Denver');
date_default_timezone_set(APP_TIMEZONE);

// ── Ollama (local AI) ─────────────────────────────────────────
define('OLLAMA_URL',   'http://localhost:11434');
define('OLLAMA_MODEL', 'qwen3-vl:8b');

// ── Upload settings ───────────────────────────────────────────
define('UPLOAD_DIR',          APP_ROOT . '/uploads/');
define('UPLOAD_URL',          APP_URL  . '/uploads/');
define('UPLOAD_MAX_MB',       10);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);

// ── Session ───────────────────────────────────────────────────
define('SESSION_LIFETIME', 3600);

// ── Risk thresholds ───────────────────────────────────────────
define('RISK_HIGH',   70);
define('RISK_MEDIUM', 40);

// ── Error handling ────────────────────────────────────────────
ini_set('display_errors', 0);
ini_set('log_errors',     1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
set_time_limit(0);

// ── Security headers ──────────────────────────────────────────
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}
