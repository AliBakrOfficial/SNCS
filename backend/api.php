<?php

declare(strict_types=1);

/**
 * SNCS — API Entry Point
 *
 * Bootstraps php-crud-api with custom middleware and routing.
 * Order: RateLimiter -> AuthMiddleware -> CsrfMiddleware -> Custom Controllers | php-crud-api
 *
 * @package App
 */

require_once __DIR__ . '/config.php';

use Tqdev\PhpCrudApi\Api;
use Tqdev\PhpCrudApi\Config\Config;
use App\Helpers\ResponseHelper;
use App\Middleware\RateLimiter;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Controllers\AuthController;
use App\Controllers\CallController;
use App\Controllers\PatientController;
use App\Controllers\NurseController;
use App\Controllers\AdminController;

// ── Session Bootstrap ──────────────────────────────────────
session_set_cookie_params([
    'lifetime' => SESSION_CONFIG['lifetime'],
    'path'     => '/',
    'domain'   => '',
    'secure'   => SESSION_CONFIG['secure'],
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$db = createPDO();
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ── 1. Rate Limiting ────────────────────────────────────────
if (!RateLimiter::check($db, $requestPath, $_SERVER['REMOTE_ADDR'] ?? '')) {
    exit; // Response handled by RateLimiter
}

// ── 2. Authentication ───────────────────────────────────────
if (!AuthMiddleware::handle($requestPath, $method)) {
    exit; // Response handled by AuthMiddleware
}

// ── 3. CSRF Protection ──────────────────────────────────────
if (!CsrfMiddleware::handle($method)) {
    exit; // Response handled by CsrfMiddleware
}

// ── 4. Custom Routing (Priority) ───────────────────────────
// Auth Routes
if ($requestPath === '/api/auth/login' && $method === 'POST') {
    (new AuthController($db))->login($_POST ? $_POST : json_decode(file_get_contents('php://input'), true));
    exit;
}
if ($requestPath === '/api/auth/logout' && $method === 'POST') {
    (new AuthController($db))->logout();
    exit;
}
if ($requestPath === '/api/auth/session' && $method === 'GET') {
    (new AuthController($db))->checkSession();
    exit;
}

// Patient Routes
if ($requestPath === '/api/patient/verify' && $method === 'POST') {
    (new PatientController($db))->verifyToken(json_decode(file_get_contents('php://input'), true));
    exit;
}
if (str_starts_with($requestPath, '/api/patient/call') && $method === 'POST') {
    (new PatientController($db))->initiateCall(json_decode(file_get_contents('php://input'), true));
    exit;
}

// Call Routes
if ($requestPath === '/api/calls/active' && $method === 'GET') {
    (new CallController($db))->getActiveCalls();
    exit;
}
if (preg_match('#^/api/calls/(\d+)/accept$#', $requestPath, $matches) && $method === 'POST') {
    (new CallController($db))->acceptCall((int)$matches[1]);
    exit;
}
if (preg_match('#^/api/calls/(\d+)/complete$#', $requestPath, $matches) && $method === 'POST') {
    (new CallController($db))->completeCall((int)$matches[1], json_decode(file_get_contents('php://input'), true));
    exit;
}

// Nurse Routes
if ($requestPath === '/api/nurse/profile' && $method === 'GET') {
    (new NurseController($db))->getProfile();
    exit;
}
if ($requestPath === '/api/nurse/shift/start' && $method === 'POST') {
    (new NurseController($db))->startShift();
    exit;
}
if ($requestPath === '/api/nurse/shift/end' && $method === 'POST') {
    (new NurseController($db))->endShift();
    exit;
}

// Admin Routes (Partial example, full CRUD handled by php-crud-api below)
if ($requestPath === '/api/admin/audit' && $method === 'GET') {
    (new AdminController($db))->getAuditLog();
    exit;
}

// ── 5. Standard CRUD (php-crud-api) ────────────────────────
$config = new Config([
    'driver'   => 'mysql',
    'address'  => DB_CONFIG['host'],
    'port'     => DB_CONFIG['port'],
    'database' => DB_CONFIG['database'],
    'username' => DB_CONFIG['username'],
    'password' => DB_CONFIG['password'],

    'middlewares' => 'cors,dbAuth,authorization,sanitation,multiTenancy',
    
    'cors.allowedOrigins'  => implode(',', CORS_CONFIG['allowed_origins']),
    'cors.allowHeaders'    => 'Content-Type,X-CSRF-Token,X-Requested-With',
    'cors.allowMethods'    => 'GET,POST,PUT,DELETE,PATCH,OPTIONS',
    'cors.allowCredentials' => 'true',

    'dbAuth.mode' => 'optional',
    'dbAuth.usersTable' => 'users',

    'authorization.tableHandler' => function ($operation, $tableName) {
        $role = $_SESSION['user']['role'] ?? '';
        if ($role === 'superadmin') return true;
        
        $roleAccess = [
            'hospital_admin' => ['hospitals', 'departments', 'rooms', 'users', 'nurses', 'nurse_shifts', 'calls', 'audit_log', 'system_settings'],
            'dept_manager' => ['rooms', 'nurses', 'nurse_shifts', 'calls', 'audit_log'],
            'nurse' => ['calls'],
        ];
        
        return in_array($tableName, $roleAccess[$role] ?? [], true);
    },

    'multiTenancy.handler' => function ($operation, $tableName) {
        if (!isset($_SESSION['user']['hospital_id'])) return [];
        $tenantTables = ['departments', 'rooms', 'nurses', 'nurse_shifts', 'calls', 'events'];
        return in_array($tableName, $tenantTables, true) ? ['hospital_id' => $_SESSION['user']['hospital_id']] : [];
    },

    'sanitation.handler' => function ($operation, $tableName, $column, $value) {
        return is_string($value) ? htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
    },
]);

$api = new Api($config);
$api->handleCommand();
