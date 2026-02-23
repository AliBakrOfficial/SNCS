<?php

declare(strict_types=1);

/**
 * SNCS — CSRF Protection Middleware
 *
 * Validates the X-CSRF-Token header on mutating requests (POST, PUT, DELETE, PATCH).
 * Uses SameSite=Strict cookies and Origin header check.
 *
 * @package App\Middleware
 */

namespace App\Middleware;

use App\Helpers\ResponseHelper;

class CsrfMiddleware
{
    /**
     * HTTP methods that require CSRF validation.
     *
     * @var array<string>
     */
    private const MUTATING_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /**
     * Handle CSRF validation.
     *
     * @param string $method HTTP request method
     * @return bool True if request passes CSRF check
     */
    public static function handle(string $method): bool
    {
        if (!in_array(strtoupper($method), self::MUTATING_METHODS, true)) {
            return true;
        }

        // Validate Origin header
        if (!self::validateOrigin()) {
            ResponseHelper::error('CSRF validation failed — invalid origin', 403);
            return false;
        }

        // Validate CSRF token from header
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (empty($headerToken) || empty($sessionToken)) {
            ResponseHelper::error('CSRF token missing', 403);
            return false;
        }

        if (!hash_equals($sessionToken, $headerToken)) {
            ResponseHelper::error('CSRF token mismatch', 403);
            return false;
        }

        return true;
    }

    /**
     * Generate and store a new CSRF token.
     *
     * @return string The generated CSRF token
     */
    public static function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    /**
     * Validate the Origin header against allowed origins.
     *
     * @return bool
     */
    private static function validateOrigin(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // If no Origin header, fall back to Referer
        if (empty($origin)) {
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            if (empty($referer)) {
                // Allow requests without Origin/Referer (same-origin, curl, etc.)
                return true;
            }
            $origin = parse_url($referer, PHP_URL_SCHEME)
                    . '://'
                    . parse_url($referer, PHP_URL_HOST);
        }

        $allowedOrigins = CORS_CONFIG['allowed_origins'] ?? [];
        return in_array($origin, $allowedOrigins, true);
    }
}
