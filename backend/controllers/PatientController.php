<?php

declare(strict_types=1);

/**
 * SNCS — Patient Controller
 *
 * Handles patient-facing operations:
 * - QR token verification
 * - Patient session management (with nonce)
 * - Call initiation with throttling
 *
 * @package App\Controllers
 */

namespace App\Controllers;

use App\Helpers\ResponseHelper;
use PDO;

class PatientController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Verify a QR token and return room info.
     *
     * @param array{qr_token: string} $data
     */
    public function verifyQr(array $data): void
    {
        $token = trim($data['qr_token'] ?? '');

        if (empty($token)) {
            ResponseHelper::error('QR token is required', 400);
            return;
        }

        // Parse HMAC-signed token: room_id|expiry|hmac
        $parts = explode('|', $token);
        if (count($parts) !== 3) {
            ResponseHelper::error('Invalid QR token format', 400);
            return;
        }

        [$roomId, $expiry, $hmac] = $parts;
        $roomId = (int)$roomId;
        $expiry = (int)$expiry;

        // Verify HMAC
        $expected = hash_hmac('sha256', "{$roomId}|{$expiry}", APP_CONFIG['qr_hmac_secret']);
        if (!hash_equals($expected, $hmac)) {
            ResponseHelper::error('Invalid QR token', 403);
            return;
        }

        // Check expiry
        if (time() > $expiry) {
            ResponseHelper::error('QR token expired', 410);
            return;
        }

        // Fetch room details
        $stmt = $this->db->prepare(
            "SELECT r.id, r.room_number, r.dept_id, r.hospital_id, d.name AS dept_name
             FROM rooms r
             JOIN departments d ON r.dept_id = d.id
             WHERE r.id = ? AND r.is_active = 1"
        );
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();

        if (!$room) {
            ResponseHelper::error('Room not found or inactive', 404);
            return;
        }

        ResponseHelper::success([
            'room_id'     => (int)$room['id'],
            'room_number' => $room['room_number'],
            'dept_name'   => $room['dept_name'],
            'hospital_id' => (int)$room['hospital_id'],
        ]);
    }

    /**
     * Create a patient session with a single-use nonce.
     *
     * @param array{room_id: int} $data
     */
    public function createSession(array $data): void
    {
        $roomId = (int)($data['room_id'] ?? 0);

        if ($roomId <= 0) {
            ResponseHelper::error('Room ID is required', 400);
            return;
        }

        // Generate UUIDv4 session token
        $sessionToken = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );

        // Generate single-use nonce
        $nonce = bin2hex(random_bytes(32));

        // Insert session (trigger will enforce max 3 per room)
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO patient_sessions (room_id, session_token, nonce, is_used, expires_at, created_at)
                 VALUES (?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())"
            );
            $stmt->execute([$roomId, $sessionToken, $nonce]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'MAX_SESSIONS_PER_ROOM')) {
                ResponseHelper::error('Maximum sessions reached for this room', 429);
                return;
            }
            throw $e;
        }

        ResponseHelper::success([
            'session_token' => $sessionToken,
            'nonce'         => $nonce,
            'expires_in'    => 86400,
        ], 201);
    }

    /**
     * Initiate a call using a patient session token + nonce.
     *
     * @param array{session_token: string, nonce: string} $data
     */
    public function initiateCall(array $data): void
    {
        $token = trim($data['session_token'] ?? '');
        $nonce = trim($data['nonce'] ?? '');

        if (empty($token) || empty($nonce)) {
            ResponseHelper::error('Session token and nonce are required', 400);
            return;
        }

        // Verify session and consume nonce
        $stmt = $this->db->prepare(
            "SELECT id, room_id FROM patient_sessions
             WHERE session_token = ? AND nonce = ?
               AND is_used = 0 AND expires_at > NOW()"
        );
        $stmt->execute([$token, $nonce]);
        $session = $stmt->fetch();

        if (!$session) {
            ResponseHelper::error('Invalid or expired session', 403);
            return;
        }

        // Mark nonce as used (single-use)
        $stmt = $this->db->prepare(
            "UPDATE patient_sessions SET is_used = 1, last_used_at = NOW() WHERE id = ?"
        );
        $stmt->execute([(int)$session['id']]);

        // Check throttle: prevent duplicate calls from same room within 5 min
        $roomId = (int)$session['room_id'];
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM calls
             WHERE room_id = ? AND status IN ('pending', 'assigned')
               AND initiated_at >= DATE_SUB(NOW(3), INTERVAL 5 MINUTE)"
        );
        $stmt->execute([$roomId]);

        if ((int)$stmt->fetchColumn() > 0) {
            ResponseHelper::error('A call is already pending for this room', 429);
            return;
        }

        // Get room details
        $stmt = $this->db->prepare("SELECT dept_id, hospital_id FROM rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();

        if (!$room) {
            ResponseHelper::error('Room not found', 404);
            return;
        }

        // Delegate to CallController
        $callController = new CallController($this->db);

        // Temporarily set session for hospital context
        $_SESSION['user'] = $_SESSION['user'] ?? [];
        $_SESSION['user']['hospital_id'] = (int)$room['hospital_id'];

        $result = $callController->create(['room_id' => $roomId]);

        ResponseHelper::success($result, 201);
    }
}
