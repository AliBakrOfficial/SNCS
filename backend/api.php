<?php

declare(strict_types=1);

/**
 * SNCS — API Entry Point
 *
 * Bootstraps php-crud-api with middleware chain:
 *   cors → dbAuth → authorization → customControllers
 *
 * @package App
 */

require_once __DIR__ . '/config.php';

use Tqdev\PhpCrudApi\Api;
use Tqdev\PhpCrudApi\Config\Config;

// ── Session Bootstrap ──────────────────────────────────────
session_set_cookie_params([
    'lifetime' => SESSION_CONFIG['lifetime'],
    'path'     => '/',
    'domain'   => '',
    'secure'   => SESSION_CONFIG['secure'],
    'httponly'  => true,
    'samesite'  => 'Strict',
]);
session_start();

// ── php-crud-api Configuration ─────────────────────────────
$config = new Config([
    'driver'   => 'mysql',
    'address'  => DB_CONFIG['host'],
    'port'     => DB_CONFIG['port'],
    'database' => DB_CONFIG['database'],
    'username' => DB_CONFIG['username'],
    'password' => DB_CONFIG['password'],

    // ── Middleware Chain ────────────────────────────────────
    'middlewares' => 'cors,dbAuth,authorization,sanitation,multiTenancy',

    // ── CORS ────────────────────────────────────────────────
    'cors.allowedOrigins'  => implode(',', CORS_CONFIG['allowed_origins']),
    'cors.allowHeaders'    => 'Content-Type,X-CSRF-Token,X-Requested-With',
    'cors.allowMethods'    => 'GET,POST,PUT,DELETE,PATCH,OPTIONS',
    'cors.allowCredentials' => 'true',

    // ── dbAuth ──────────────────────────────────────────────
    'dbAuth.mode'             => 'optional',
    'dbAuth.usersTable'       => 'users',
    'dbAuth.usernameColumn'   => 'username',
    'dbAuth.passwordColumn'   => 'password',
    'dbAuth.loginAfterRegistration' => 'false',

    // ── Authorization ───────────────────────────────────────
    'authorization.tableHandler' => function ($operation, $tableName) {
        // Public endpoints: patient_sessions (limited)
        $publicTables = ['patient_sessions'];

        if (!isset($_SESSION['user'])) {
            return in_array($tableName, $publicTables, true);
        }

        $role = $_SESSION['user']['role'] ?? '';

        // Superadmin has full access
        if ($role === 'superadmin') {
            return true;
        }

        // Role-based table access
        $roleAccess = [
            'hospital_admin' => [
                'hospitals', 'departments', 'rooms', 'users', 'nurses',
                'nurse_shifts', 'nurse_room_assignments', 'dispatch_queue',
                'calls', 'patient_sessions', 'escalation_queue', 'audit_log',
                'events', 'system_settings', 'push_subscriptions',
            ],
            'dept_manager' => [
                'rooms', 'nurses', 'nurse_shifts', 'nurse_room_assignments',
                'dispatch_queue', 'calls', 'escalation_queue', 'audit_log',
                'events', 'push_subscriptions',
            ],
            'nurse' => [
                'calls', 'events', 'push_subscriptions',
            ],
        ];

        $allowedTables = $roleAccess[$role] ?? [];
        return in_array($tableName, $allowedTables, true);
    },

    // ── Multi-Tenancy (Row-Level Isolation) ─────────────────
    'multiTenancy.handler' => function ($operation, $tableName) {
        if (!isset($_SESSION['user']['hospital_id'])) {
            return [];
        }

        // Tables with hospital_id column get automatic filtering
        $tenantTables = [
            'departments', 'rooms', 'nurses', 'nurse_shifts',
            'dispatch_queue', 'calls', 'events',
        ];

        if (in_array($tableName, $tenantTables, true)) {
            return ['hospital_id' => $_SESSION['user']['hospital_id']];
        }

        return [];
    },

    // ── Sanitation ──────────────────────────────────────────
    'sanitation.handler' => function ($operation, $tableName, $column, $value) {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $value;
    },

    // ── Column Hiding ───────────────────────────────────────
    'authorization.columnHandler' => function ($operation, $tableName, $columnName) {
        // Never expose password hashes
        $hiddenColumns = [
            'users' => ['password'],
        ];

        if (isset($hiddenColumns[$tableName])) {
            return !in_array($columnName, $hiddenColumns[$tableName], true);
        }

        return true;
    },
]);

// ── Run API ────────────────────────────────────────────────
$api = new Api($config);
$response = $api->handle(ServerRequestFactory::fromGlobals());

// ── Emit Response ──────────────────────────────────────────
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
}
echo $response->getBody();
