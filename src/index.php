<?php
// ============================================================
// index.php — Entry point, redirect based on auth state
// ============================================================

// ── Required extension check ──────────────────────────────────
// Runs before anything else so missing extensions produce a
// clear, friendly message rather than a cryptic PHP fatal error.
$required = ['pdo', 'pdo_mysql', 'curl'];
$missing  = array_filter($required, fn($ext) => !extension_loaded($ext));

if (!empty($missing)) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ElderShield — Setup Required</title>
        <style>
            body  { font-family: sans-serif; max-width: 640px; margin: 60px auto; padding: 0 24px; color: #333; }
            h1    { color: #c0392b; }
            code  { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-size: .9em; }
            .box  { border-radius: 6px; padding: 14px 18px; margin: 16px 0; }
            .warn { background: #fff3cd; border: 1px solid #ffc107; }
            .fix  { background: #d1ecf1; border: 1px solid #bee5eb; }
            li    { margin: 6px 0; }
        </style>
    </head>
    <body>
        <h1>⚠️ ElderShield — Setup Required</h1>
        <p>The following required PHP extension(s) are not enabled on your server:</p>
        <div class="box warn">
            <?php foreach ($missing as $ext): ?>
                <p>❌ <code><?= htmlspecialchars($ext) ?></code></p>
            <?php endforeach; ?>
        </div>

        <div class="box fix">
            <strong>How to fix on WAMP (Windows):</strong>
            <ol>
                <li>Left-click the WAMP icon in your taskbar</li>
                <li>Go to <em>PHP &rarr; PHP extensions</em></li>
                <li>Enable <code>php_pdo_mysql</code> and <code>php_curl</code></li>
                <li>WAMP will restart Apache automatically</li>
            </ol>
        </div>

        <div class="box fix">
            <strong>How to fix on MAMP (Mac):</strong>
            <ol>
                <li>These extensions are enabled by default in MAMP</li>
                <li>Open MAMP &rarr; <em>Preferences &rarr; PHP</em></li>
                <li>Make sure your PHP version is <strong>8.1 or higher</strong></li>
                <li>Click OK and restart your servers</li>
            </ol>
        </div>

        <p>After enabling extensions, restart your web server and <a href="">refresh this page</a>.</p>
    </body>
    </html>
    <?php
    exit;
}

// ── Normal startup ───────────────────────────────────────────────
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
} else {
    header('Location: ' . APP_URL . '/pages/login.php');
}
exit;
