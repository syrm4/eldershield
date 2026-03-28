<?php
// ============================================================
// config/db.php  —  PDO database connection
// ============================================================
//
// MySQL port guide:
//   WAMP (Windows)       → 3306
//   MAMP Pro (Mac)       → 3306
//   MAMP standard (Mac)  → 8889
//
// Change DB_PORT below to match your local environment.
// ============================================================

define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');      // See port guide above
define('DB_NAME',    'eldershield');
define('DB_USER',    'root');
define('DB_PASS',    'root');      // MAMP default — change for production
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Never expose DB errors to users
            error_log('DB Connection failed: ' . $e->getMessage());
            die(json_encode(['error' => 'Database connection failed. Please try again later.']));
        }
    }
    return $pdo;
}
