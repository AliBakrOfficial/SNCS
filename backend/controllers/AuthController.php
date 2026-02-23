<?php

declare(strict_types=1);

/**
 * SNCS — Authentication Controller
 *
 * Handles login, logout, session check, and CSRF token provisioning.
 *
 * @package App\Controllers
 */

namespace App\Controllers;

use App\Helpers\ResponseHelper;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use PDO;

class AuthController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Handle user login.
     *
     * @param array{username: string, password: string} $data
     */
    public function login(array $data): void
    {
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($username) || empty($password)) {
            ResponseHelper::error('Username and password are required', 400);
            return;
        }

        // Fetch user by username
        $stmt = $this->db->prepare(
            "SELECT id, username, password, role, full_name, hospital_id, department_id, is_active
             FROM users WHERE username = ? LIMIT 1"
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            // Log failed attempt for auditing
            $this->logAttempt($username, false);
            ResponseHelper::error('Invalid credentials', 401);
            return;
        }

        if (!(bool)$user['is_active']) {
            ResponseHelper::error('Account is disabled', 403);
            return;
        }

        // Initialize secure session
        AuthMiddleware::initSession($user);

        // Generate CSRF token
        $csrfToken = CsrfMiddleware::generateToken();

        // Update last_login
        $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([(int)$user['id']]);

        // Audit log
        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (action, actor, reason, meta_json, created_at)
             VALUES ('user_login', ?, 'success', ?, NOW())"
        );
        $stmt->execute([
            $user['username'],
            json_encode(['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'], JSON_THROW_ON_ERROR),
        ]);

        $this->logAttempt($username, true);

        ResponseHelper::success([
            'user' => [
                'id'            => (int)$user['id'],
                'username'      => $user['username'],
                'role'          => $user['role'],
                'full_name'     => $user['full_name'],
                'hospital_id'   => $user['hospital_id'] ? (int)$user['hospital_id'] : null,
                'department_id' => $user['department_id'] ? (int)$user['department_id'] : null,
            ],
            'csrf_token' => $csrfToken,
        ]);
    }

    /**
     * Handle user logout.
     */
    public function logout(): void
    {
        $username = $_SESSION['user']['username'] ?? 'unknown';

        // Audit log
        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (action, actor, reason, created_at)
             VALUES ('user_logout', ?, 'manual', NOW())"
        );
        $stmt->execute([$username]);

        // Destroy session
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        ResponseHelper::success(null, 200, 'Logged out');
    }

    /**
     * Check current session status.
     */
    public function checkSession(): void
    {
        if (empty($_SESSION['user'])) {
            ResponseHelper::error('Not authenticated', 401);
            return;
        }

        ResponseHelper::success([
            'user'       => $_SESSION['user'],
            'csrf_token' => CsrfMiddleware::generateToken(),
        ]);
    }

    /**
     * Log login attempt for audit trail.
     *
     * @param string $username Username attempted
     * @param bool   $success  Whether login succeeded
     */
    private function logAttempt(string $username, bool $success): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (action, actor, reason, meta_json, created_at)
             VALUES ('login_attempt', ?, ?, ?, NOW())"
        );
        $stmt->execute([
            htmlspecialchars($username, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $success ? 'success' : 'failed',
            json_encode([
                'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'success' => $success,
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
