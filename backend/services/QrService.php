<?php

declare(strict_types=1);

/**
 * SNCS — QR Code Service
 *
 * Handles QR code generation with HMAC-SHA256 signatures,
 * nonce management, and token validation.
 *
 * @package App\Services
 */

namespace App\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use PDO;

class QrService
{
    private PDO $db;
    private string $hmacSecret;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->hmacSecret = APP_CONFIG['qr_hmac_secret'];
    }

    /**
     * Generate an HMAC-signed QR token for a room.
     *
     * Token format: room_id|expiry_timestamp|hmac_signature
     *
     * @param int $roomId   Room ID
     * @param int $ttlHours Token TTL in hours (default 24, emergency 1)
     * @return array{token: string, expires_at: string}
     */
    public function generateToken(int $roomId, int $ttlHours = 24): array
    {
        $expiry = time() + ($ttlHours * 3600);
        $data   = "{$roomId}|{$expiry}";
        $hmac   = hash_hmac('sha256', $data, $this->hmacSecret);
        $token  = "{$data}|{$hmac}";

        // Store token in rooms table
        $stmt = $this->db->prepare(
            "UPDATE rooms SET qr_token = ?, qr_expires_at = FROM_UNIXTIME(?) WHERE id = ?"
        );
        $stmt->execute([$token, $expiry, $roomId]);

        return [
            'token'      => $token,
            'expires_at' => date('Y-m-d H:i:s', $expiry),
        ];
    }

    /**
     * Validate an HMAC-signed QR token.
     *
     * @param string $token Raw token string
     * @return array{valid: bool, room_id: ?int, error: ?string}
     */
    public function validateToken(string $token): array
    {
        $parts = explode('|', $token);
        if (count($parts) !== 3) {
            return ['valid' => false, 'room_id' => null, 'error' => 'INVALID_FORMAT'];
        }

        [$roomId, $expiry, $hmac] = $parts;
        $roomId = (int)$roomId;
        $expiry = (int)$expiry;

        // Verify HMAC
        $expected = hash_hmac('sha256', "{$roomId}|{$expiry}", $this->hmacSecret);
        if (!hash_equals($expected, $hmac)) {
            return ['valid' => false, 'room_id' => null, 'error' => 'INVALID_SIGNATURE'];
        }

        // Check expiry
        if (time() > $expiry) {
            return ['valid' => false, 'room_id' => $roomId, 'error' => 'EXPIRED'];
        }

        return ['valid' => true, 'room_id' => $roomId, 'error' => null];
    }

    /**
     * Generate a QR code image (PNG) for a room token.
     *
     * @param string $token The HMAC-signed token
     * @param int    $size  Image size in pixels
     * @return string Base64-encoded PNG image
     */
    public function generateQrImage(string $token, int $size = 300): string
    {
        $qrCode = new QrCode($token);
        $qrCode->setSize($size);
        $qrCode->setMargin(10);

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        return base64_encode($result->getString());
    }

    /**
     * Generate a single-use nonce for a patient session.
     *
     * @return string 64-character hex nonce
     */
    public function generateNonce(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Validate and consume a nonce (mark as used).
     *
     * @param string $sessionToken Patient session token (UUIDv4)
     * @param string $nonce        Nonce value
     * @return bool True if nonce was valid and consumed
     */
    public function consumeNonce(string $sessionToken, string $nonce): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE patient_sessions
             SET is_used = 1, last_used_at = NOW()
             WHERE session_token = ? AND nonce = ? AND is_used = 0 AND expires_at > NOW()"
        );
        $stmt->execute([$sessionToken, $nonce]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Clean up expired sessions (cron job — every 5 minutes).
     *
     * @return int Number of deleted sessions
     */
    public function cleanupExpiredSessions(): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM patient_sessions WHERE expires_at < NOW() OR is_used = 1"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }
}
