<?php

declare(strict_types=1);

/**
 * SNCS — Application Configuration
 *
 * Loads environment variables from .env and provides
 * database connection and application settings.
 *
 * @package App
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// ── Load .env ──────────────────────────────────────────────
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Required env vars
$dotenv->required([
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER',
    'APP_SECRET', 'QR_HMAC_SECRET',
])->notEmpty();

// ── Database Configuration ─────────────────────────────────
define('DB_CONFIG', [
    'host'     => $_ENV['DB_HOST'],
    'port'     => (int)($_ENV['DB_PORT'] ?? 3306),
    'database' => $_ENV['DB_NAME'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'] ?? '',
    'charset'  => 'utf8mb4',
]);

// ── PDO Factory ────────────────────────────────────────────

/**
 * Create a PDO connection with secure defaults.
 *
 * @return PDO
 */
function createPDO(): PDO
{
    $cfg = DB_CONFIG;
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['database'],
        $cfg['charset']
    );

    return new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
    ]);
}

// ── Application Settings ───────────────────────────────────
define('APP_CONFIG', [
    'env'            => $_ENV['APP_ENV'] ?? 'development',
    'debug'          => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'secret'         => $_ENV['APP_SECRET'],
    'url'            => $_ENV['APP_URL'] ?? 'http://localhost:8000',
    'qr_hmac_secret' => $_ENV['QR_HMAC_SECRET'],
]);

// ── WebSocket Settings ─────────────────────────────────────
define('WS_CONFIG', [
    'host' => $_ENV['WS_HOST'] ?? '0.0.0.0',
    'port' => (int)($_ENV['WS_PORT'] ?? 8080),
]);

// ── VAPID Settings ─────────────────────────────────────────
define('VAPID_CONFIG', [
    'public_key'  => $_ENV['VAPID_PUBLIC_KEY'] ?? '',
    'private_key' => $_ENV['VAPID_PRIVATE_KEY'] ?? '',
    'subject'     => $_ENV['VAPID_SUBJECT'] ?? 'mailto:admin@sncs.io',
]);

// ── CORS Settings ──────────────────────────────────────────
define('CORS_CONFIG', [
    'allowed_origins' => explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:9000'),
]);

// ── Session Settings ───────────────────────────────────────
define('SESSION_CONFIG', [
    'lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 3600),
    'secure'   => filter_var($_ENV['SESSION_SECURE'] ?? true, FILTER_VALIDATE_BOOLEAN),
]);
