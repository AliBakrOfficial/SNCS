<?php

declare(strict_types=1);

/**
 * SNCS — Rate Limiter
 *
 * Per-IP sliding window rate limiter backed by MySQL.
 * Prevents brute-force attacks on auth endpoints.
 *
 * @package App\Middleware
 */

namespace App\Middleware;

use App\Helpers\ResponseHelper;
use PDO;

class RateLimiter
{
    /**
     * Default configuration per endpoint group.
     *
     * @var array<string, array{max: int, window: int}>
     */
    private const LIMITS = [
        'auth'    => ['max' => 5,  'window' => 300],  // 5 attempts per 5 minutes
        'calls'   => ['max' => 10, 'window' => 60],   // 10 calls per minute
        'patient' => ['max' => 5,  'window' => 300],  // 5 calls per 5 minutes (patient throttle)
        'default' => ['max' => 60, 'window' => 60],   // 60 requests per minute
    ];

    /**
     * Check rate limit for the current request.
     *
     * @param PDO    $db       Database connection
     * @param string $path     Request path
     * @param string $clientIp Client IP address
     * @return bool True if request is within limits
     */
    public static function check(PDO $db, string $path, string $clientIp): bool
    {
        $group = self::resolveGroup($path);
        $config = self::LIMITS[$group] ?? self::LIMITS['default'];

        // Clean up old entries
        $stmt = $db->prepare(
            "DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL :window SECOND)"
        );
        $stmt->execute(['window' => $config['window']]);

        // Count recent requests
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM rate_limits
             WHERE ip = :ip AND endpoint_group = :grp
               AND created_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)"
        );
        $stmt->execute([
            'ip'     => $clientIp,
            'grp'    => $group,
            'window' => $config['window'],
        ]);

        $count = (int)$stmt->fetchColumn();

        if ($count >= $config['max']) {
            $retryAfter = $config['window'];
            header("Retry-After: {$retryAfter}");
            ResponseHelper::error(
                'Rate limit exceeded. Try again later.',
                429,
                ['retry_after' => $retryAfter]
            );
            return false;
        }

        // Record this request
        $stmt = $db->prepare(
            "INSERT INTO rate_limits (ip, endpoint_group, created_at) VALUES (:ip, :grp, NOW())"
        );
        $stmt->execute(['ip' => $clientIp, 'grp' => $group]);

        return true;
    }

    /**
     * Resolve the rate limit group from the request path.
     *
     * @param string $path Request path
     * @return string Group key
     */
    private static function resolveGroup(string $path): string
    {
        if (str_contains($path, '/auth/')) {
            return 'auth';
        }
        if (str_contains($path, '/calls')) {
            return 'calls';
        }
        if (str_contains($path, '/patient/')) {
            return 'patient';
        }
        return 'default';
    }
}
