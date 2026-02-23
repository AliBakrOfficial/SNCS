<?php

declare(strict_types=1);

/**
 * SNCS — Standard JSON Response Helper
 *
 * Provides a consistent JSON envelope for all API responses.
 *
 * @package App\Helpers
 */

namespace App\Helpers;

class ResponseHelper
{
    /**
     * Send a success response.
     *
     * @param mixed  $data    Response payload
     * @param int    $code    HTTP status code
     * @param string $message Optional message
     */
    public static function success(mixed $data = null, int $code = 200, string $message = 'OK'): void
    {
        self::send([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * Send an error response.
     *
     * @param string $error   Error message
     * @param int    $code    HTTP status code
     * @param mixed  $details Additional error details
     */
    public static function error(string $error, int $code = 400, mixed $details = null): void
    {
        self::send([
            'success' => false,
            'error'   => $error,
            'code'    => $code,
            'details' => $details,
        ], $code);
    }

    /**
     * Send a JSON response and exit.
     *
     * @param array<string, mixed> $payload Response body
     * @param int                  $code    HTTP status code
     */
    private static function send(array $payload, int $code): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
