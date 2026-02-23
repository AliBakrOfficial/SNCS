<?php

declare(strict_types=1);

/**
 * SNCS — Authentication Middleware
 *
 * Validates the PHP session and populates $_SESSION['user'].
 * Returns 401 for unauthenticated requests to protected endpoints.
 *
 * @package App\Middleware
 */

namespace App\Middleware;

use App\Helpers\ResponseHelper;

class AuthMiddleware
{
    /**
     * Public paths that do not require authentication.
     *
     * @var array<string>
     */
    private const PUBLIC_PATHS = [
        '/api/auth/login',
        '/api/patient/verify',
        '/api/patient/call',
        '/healthz',
    ];

    /**
     * Handle the authentication check.
     *
     * @param string $requestPath Current request path
     * @param string $method      HTTP method
     * @return bool True if request is allowed to proceed
     */
    public static function handle(string $requestPath, string $method): bool
    {
        // OPTIONS requests are always allowed (CORS preflight)
        if ($method === 'OPTIONS') {
            return true;
        }

        // Check if path is public
        foreach (self::PUBLIC_PATHS as $publicPath) {
            if (str_starts_with($requestPath, $publicPath)) {
                return true;
            }
        }

        // Must have a valid session
        if (empty($_SESSION['user']) || empty($_SESSION['user']['id'])) {
            ResponseHelper::error('Unauthorized — session required', 401);
            return false; // Unreachable due to exit in ResponseHelper
        }

        // Validate session integrity
        if (!self::validateSession()) {
            session_destroy();
            ResponseHelper::error('Session expired or invalid', 401);
            return false;
        }

        return true;
    }

    /**
     * Validate session data integrity.
     *
     * @return bool
     */
    private static function validateSession(): bool
    {
        // Check required fields
        if (!isset($_SESSION['user']['id'], $_SESSION['user']['role'])) {
            return false;
        }

        // Check IP binding (optional — may cause issues with proxies)
        if (
            isset($_SESSION['user']['ip'])
            && $_SESSION['user']['ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')
        ) {
            return false;
        }

        // Check session age
        $maxAge = SESSION_CONFIG['lifetime'] ?? 3600;
        if (
            isset($_SESSION['created_at'])
            && (time() - $_SESSION['created_at']) > $maxAge
        ) {
            return false;
        }

        return true;
    }

    /**
     * Initialize a session for an authenticated user.
     *
     * @param array<string, mixed> $user User data from database
     */
    public static function initSession(array $user): void
    {
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'            => (int)$user['id'],
            'username'      => $user['username'],
            'role'          => $user['role'],
            'full_name'     => $user['full_name'],
            'hospital_id'   => isset($user['hospital_id']) ? (int)$user['hospital_id'] : null,
            'department_id' => isset($user['department_id']) ? (int)$user['department_id'] : null,
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        $_SESSION['created_at'] = time();
    }
}
