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

/**
 * Read JSON body from php://input.
 *
 * @return array<string, mixed>
 */
function getJsonBody(): array
{
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

$db          = createPDO();
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method      = $_SERVER['REQUEST_METHOD'];

// ── 1. Rate Limiting ────────────────────────────────────────
if (!RateLimiter::check($db, $requestPath, $_SERVER['REMOTE_ADDR'] ?? '')) {
    exit;
}

// ── 2. Authentication ───────────────────────────────────────
if (!AuthMiddleware::handle($requestPath, $method)) {
    exit;
}

// ── 3. CSRF Protection ──────────────────────────────────────
if (!CsrfMiddleware::handle($method)) {
    exit;
}

// ── 4. Custom Routing ──────────────────────────────────────

// ── Auth Routes ─────────────────────────────────────────────
if ($requestPath === '/api/auth/login' && $method === 'POST') {
    (new AuthController($db))->login(getJsonBody());
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

// ── Patient Routes ──────────────────────────────────────────
if ($requestPath === '/api/patient/verify' && $method === 'POST') {
    (new PatientController($db))->verifyQr(getJsonBody());
    exit;
}
if ($requestPath === '/api/patient/call' && $method === 'POST') {
    (new PatientController($db))->initiateCall(getJsonBody());
    exit;
}

// ── Call Routes ─────────────────────────────────────────────
if ($requestPath === '/api/calls' && $method === 'POST') {
    $result = (new CallController($db))->create(getJsonBody());
    ResponseHelper::success($result, 201);
    exit;
}
if ($requestPath === '/api/calls/active' && $method === 'GET') {
    $calls = (new CallController($db))->getActiveCalls($_GET);
    ResponseHelper::success($calls);
    exit;
}
if (preg_match('#^/api/calls/(\d+)/accept$#', $requestPath, $m) && $method === 'POST') {
    (new CallController($db))->updateStatus((int)$m[1], 'accept');
    exit;
}
if (preg_match('#^/api/calls/(\d+)/arrive$#', $requestPath, $m) && $method === 'POST') {
    (new CallController($db))->updateStatus((int)$m[1], 'arrive');
    exit;
}
if (preg_match('#^/api/calls/(\d+)/complete$#', $requestPath, $m) && $method === 'POST') {
    $body = getJsonBody();
    (new CallController($db))->updateStatus((int)$m[1], 'complete', $body['notes'] ?? '');
    exit;
}
if (preg_match('#^/api/calls/(\d+)/cancel$#', $requestPath, $m) && $method === 'POST') {
    (new CallController($db))->updateStatus((int)$m[1], 'cancel');
    exit;
}

// ── Nurse Routes ────────────────────────────────────────────
if ($requestPath === '/api/nurse/profile' && $method === 'GET') {
    (new NurseController($db))->profile();
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
if ($requestPath === '/api/nurse/rooms' && $method === 'GET') {
    (new NurseController($db))->getAssignments();
    exit;
}
if ($requestPath === '/api/nurse/exclude' && $method === 'POST') {
    (new NurseController($db))->toggleExclusion(getJsonBody());
    exit;
}

// ── Admin Routes ────────────────────────────────────────────
if ($requestPath === '/api/admin/hospitals' && $method === 'GET') {
    (new AdminController($db))->listHospitals();
    exit;
}
if ($requestPath === '/api/admin/hospitals' && $method === 'POST') {
    (new AdminController($db))->createHospital(getJsonBody());
    exit;
}
if ($requestPath === '/api/admin/departments' && $method === 'GET') {
    (new AdminController($db))->listDepartments();
    exit;
}
if ($requestPath === '/api/admin/departments' && $method === 'POST') {
    (new AdminController($db))->createDepartment(getJsonBody());
    exit;
}
if (preg_match('#^/api/admin/rooms/(\d+)$#', $requestPath, $m) && $method === 'GET') {
    (new AdminController($db))->listRooms((int)$m[1]);
    exit;
}
if ($requestPath === '/api/admin/rooms' && $method === 'POST') {
    (new AdminController($db))->createRoom(getJsonBody());
    exit;
}
if ($requestPath === '/api/admin/staff' && $method === 'GET') {
    (new AdminController($db))->listStaff($_GET['role'] ?? null);
    exit;
}
if ($requestPath === '/api/admin/staff' && $method === 'POST') {
    (new AdminController($db))->createStaff(getJsonBody());
    exit;
}
if ($requestPath === '/api/admin/settings' && $method === 'GET') {
    (new AdminController($db))->getSettings();
    exit;
}
if ($requestPath === '/api/admin/settings' && $method === 'PUT') {
    (new AdminController($db))->updateSetting(getJsonBody());
    exit;
}
if ($requestPath === '/api/admin/audit' && $method === 'GET') {
    (new AdminController($db))->getAuditLog($_GET);
    exit;
}

// ── Health Check ────────────────────────────────────────────
if ($requestPath === '/healthz' && $method === 'GET') {
    try {
        $db->query('SELECT 1');
        ResponseHelper::success(['status' => 'ok']);
    } catch (\Exception $e) {
        ResponseHelper::error('Database unreachable', 503);
    }
    exit;
}

// ── 5. Standard CRUD (php-crud-api fallback) ────────────────
$config = new Config([
    'driver'   => 'mysql',
    'address'  => DB_CONFIG['host'],
    'port'     => DB_CONFIG['port'],
    'database' => DB_CONFIG['database'],
    'username' => DB_CONFIG['username'],
    'password' => DB_CONFIG['password'],

    'middlewares' => 'cors,dbAuth,authorization,sanitation,multiTenancy',

    'cors.allowedOrigins'   => implode(',', CORS_CONFIG['allowed_origins']),
    'cors.allowHeaders'     => 'Content-Type,X-CSRF-Token,X-Requested-With',
    'cors.allowMethods'     => 'GET,POST,PUT,DELETE,PATCH,OPTIONS',
    'cors.allowCredentials' => 'true',

    'dbAuth.mode'       => 'optional',
    'dbAuth.usersTable' => 'users',

    'authorization.tableHandler' => function ($operation, $tableName) {
        $role = $_SESSION['user']['role'] ?? '';
        if ($role === 'superadmin') {
            return true;
        }

        $roleAccess = [
            'hospital_admin' => [
                'hospitals', 'departments', 'rooms', 'users', 'nurses',
                'nurse_shifts', 'calls', 'audit_log', 'system_settings',
            ],
            'dept_manager' => [
                'rooms', 'nurses', 'nurse_shifts', 'calls', 'audit_log',
            ],
            'nurse' => ['calls'],
        ];

        return in_array($tableName, $roleAccess[$role] ?? [], true);
    },

    'multiTenancy.handler' => function ($operation, $tableName) {
        if (!isset($_SESSION['user']['hospital_id'])) {
            return [];
        }
        $tenantTables = [
            'departments', 'rooms', 'nurses', 'nurse_shifts', 'calls', 'events',
        ];
        return in_array($tableName, $tenantTables, true)
            ? ['hospital_id' => $_SESSION['user']['hospital_id']]
            : [];
    },

    'sanitation.handler' => function ($operation, $tableName, $column, $value) {
        return is_string($value)
            ? htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $value;
    },
]);

$api = new Api($config);
$api->handleCommand();
