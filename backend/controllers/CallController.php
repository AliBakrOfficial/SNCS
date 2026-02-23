<?php

declare(strict_types=1);

/**
 * SNCS — Call Controller
 *
 * Handles call lifecycle: creation, assignment (via SP + GET_LOCK fallback),
 * status transitions, and active call queries.
 *
 * Reads/writes dispatch_queue for nurse round-robin assignment.
 *
 * @package App\Controllers
 */

namespace App\Controllers;

use App\Helpers\ResponseHelper;
use PDO;
use PDOException;

class CallController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new call and attempt automatic assignment.
     *
     * @param array{room_id: int} $data Request data
     * @return array<string, mixed> Call creation result
     */
    public function create(array $data): array
    {
        $roomId     = (int)($data['room_id'] ?? 0);
        $hospitalId = (int)($_SESSION['user']['hospital_id'] ?? 0);

        // Validate room exists and belongs to hospital
        $stmt = $this->db->prepare(
            "SELECT id, dept_id, hospital_id FROM rooms
             WHERE id = ? AND hospital_id = ? AND is_active = 1"
        );
        $stmt->execute([$roomId, $hospitalId]);
        $room = $stmt->fetch();

        if (!$room) {
            ResponseHelper::error('Room not found or inactive', 404);
            return [];
        }

        // Check throttle: no duplicate calls from same room in 5 minutes
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM calls
             WHERE room_id = ? AND status IN ('pending', 'assigned')
               AND initiated_at >= DATE_SUB(NOW(3), INTERVAL 5 MINUTE)"
        );
        $stmt->execute([$roomId]);

        if ((int)$stmt->fetchColumn() > 0) {
            ResponseHelper::error('Call already pending for this room', 429);
            return [];
        }

        // Create call record
        $stmt = $this->db->prepare(
            "INSERT INTO calls (room_id, dept_id, hospital_id, status, initiated_by, initiated_at, is_broadcast)
             VALUES (?, ?, ?, 'pending', 'patient_app', NOW(3), 0)"
        );
        $stmt->execute([$roomId, (int)$room['dept_id'], $hospitalId]);
        $callId = (int)$this->db->lastInsertId();

        // Attempt assignment via Stored Procedure first
        $result = $this->assignCallViaSP($callId, (int)$room['dept_id']);

        // Fallback to GET_LOCK if SP fails (and not due to no available nurse)
        if (!$result['success'] && $result['error'] !== 'NO_NURSE_AVAILABLE') {
            $result = $this->assignCallWithLock((int)$room['dept_id'], $callId);
        }

        // Create event for Ratchet polling
        $stmt = $this->db->prepare(
            "INSERT INTO events (type, payload, dept_id, hospital_id, is_broadcast, created_at)
             VALUES ('call_created', ?, ?, ?, 0, NOW(3))"
        );
        $stmt->execute([
            json_encode([
                'call_id'    => $callId,
                'room_id'    => $roomId,
                'status'     => $result['success'] ? 'assigned' : 'pending',
                'nurse_id'   => $result['nurse_id'] ?? null,
            ], JSON_THROW_ON_ERROR),
            (int)$room['dept_id'],
            $hospitalId,
        ]);

        // Audit log
        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (call_id, action, actor, created_at)
             VALUES (?, 'call_created', 'patient_app', NOW())"
        );
        $stmt->execute([$callId]);

        return [
            'success'    => true,
            'call_id'    => $callId,
            'assignment' => $result,
        ];
    }

    /**
     * Assign call using stored procedure.
     *
     * @param int $callId Call ID
     * @param int $deptId Department ID
     * @return array{success: bool, nurse_id: ?int, error: ?string}
     */
    private function assignCallViaSP(int $callId, int $deptId): array
    {
        try {
            $stmt = $this->db->prepare("CALL sp_assign_call_to_next_nurse(?, ?, @nurse, @success, @error)");
            $stmt->execute([$callId, $deptId]);

            $result = $this->db->query(
                "SELECT @nurse AS nurse_id, @success AS success, @error AS error_code"
            )->fetch();

            return [
                'success'  => (bool)($result['success'] ?? false),
                'nurse_id' => $result['nurse_id'] ? (int)$result['nurse_id'] : null,
                'error'    => $result['error_code'] ?? null,
            ];
        } catch (PDOException $e) {
            return [
                'success'  => false,
                'nurse_id' => null,
                'error'    => 'SP_EXCEPTION: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fallback: Assign call using GET_LOCK for application-level locking.
     *
     * @param int $deptId Department ID
     * @param int $callId Call ID
     * @return array{success: bool, nurse_id: ?int, error: ?string}
     */
    private function assignCallWithLock(int $deptId, int $callId): array
    {
        $lockName = 'sncs_assign_dept_' . abs((int)$deptId);

        // Acquire lock (3 second timeout)
        $acquired = $this->db->query("SELECT GET_LOCK('" . $lockName . "', 3) AS result")->fetch();

        if (!$acquired || (int)$acquired['result'] !== 1) {
            ResponseHelper::error('Service busy — please retry', 423);
            return ['success' => false, 'nurse_id' => null, 'error' => 'LOCK_TIMEOUT'];
        }

        try {
            // Select next available nurse from dispatch_queue
            $stmt = $this->db->prepare(
                "SELECT nurse_id FROM dispatch_queue
                 WHERE dept_id = ? AND is_excluded = 0
                 ORDER BY queue_position ASC LIMIT 1"
            );
            $stmt->execute([$deptId]);
            $nurse = $stmt->fetch();

            if (!$nurse) {
                // No nurse available — escalate
                $stmt = $this->db->prepare(
                    "INSERT INTO escalation_queue (call_id, level, reason, created_at)
                     VALUES (?, 1, 'no_available_nurse', NOW())"
                );
                $stmt->execute([$callId]);

                return ['success' => false, 'nurse_id' => null, 'error' => 'NO_NURSE_AVAILABLE'];
            }

            $nurseId = (int)$nurse['nurse_id'];

            // Assign nurse
            $stmt = $this->db->prepare("UPDATE nurses SET status = 'busy', last_assigned_at = NOW() WHERE id = ?");
            $stmt->execute([$nurseId]);

            $stmt = $this->db->prepare("UPDATE calls SET nurse_id = ?, status = 'assigned', assigned_at = NOW() WHERE id = ?");
            $stmt->execute([$nurseId, $callId]);

            // Round robin: move to end of queue
            $stmt = $this->db->prepare(
                "UPDATE dispatch_queue SET queue_position = (
                    SELECT COALESCE(MAX(qp.queue_position), 0) + 1
                    FROM (SELECT queue_position FROM dispatch_queue WHERE dept_id = ?) qp
                 ) WHERE nurse_id = ? AND dept_id = ?"
            );
            $stmt->execute([$deptId, $nurseId, $deptId]);

            return ['success' => true, 'nurse_id' => $nurseId, 'error' => null];
        } finally {
            // Always release lock
            $this->db->query("SELECT RELEASE_LOCK('{$lockName}')");
        }
    }

    /**
     * Get active calls for a department or nurse.
     *
     * @param array<string, mixed> $filters Filter parameters
     * @return array<int, array<string, mixed>>
     */
    public function getActiveCalls(array $filters): array
    {
        $hospitalId = (int)($_SESSION['user']['hospital_id'] ?? 0);
        $conditions = ["c.hospital_id = ?"];
        $params     = [$hospitalId];

        if (!empty($filters['dept_id'])) {
            $conditions[] = "c.dept_id = ?";
            $params[]     = (int)$filters['dept_id'];
        }

        if (!empty($filters['nurse_id'])) {
            $conditions[] = "c.nurse_id = ?";
            $params[]     = (int)$filters['nurse_id'];
        }

        if (!empty($filters['status'])) {
            $conditions[] = "c.status = ?";
            $params[]     = $filters['status'];
        } else {
            $conditions[] = "c.status IN ('pending', 'assigned', 'in_progress', 'escalated')";
        }

        $where = implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT c.*, r.room_number, n.name AS nurse_name
             FROM calls c
             LEFT JOIN rooms r ON c.room_id = r.id
             LEFT JOIN nurses n ON c.nurse_id = n.id
             WHERE {$where}
             ORDER BY c.priority DESC, c.initiated_at ASC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Update call status (accept, arrive, complete, cancel).
     *
     * @param int    $callId  Call ID
     * @param string $action  Action to perform
     * @param string $notes   Optional notes
     */
    public function updateStatus(int $callId, string $action, string $notes = ''): void
    {
        $hospitalId = (int)($_SESSION['user']['hospital_id'] ?? 0);
        $nurseId    = (int)($_SESSION['user']['id'] ?? 0);

        // Verify call belongs to hospital
        $stmt = $this->db->prepare("SELECT id, status, nurse_id FROM calls WHERE id = ? AND hospital_id = ?");
        $stmt->execute([$callId, $hospitalId]);
        $call = $stmt->fetch();

        if (!$call) {
            ResponseHelper::error('Call not found', 404);
            return;
        }

        $transitions = [
            'accept'   => ['from' => 'assigned',    'to' => 'in_progress', 'time_col' => 'accepted_at'],
            'arrive'   => ['from' => 'in_progress', 'to' => 'in_progress', 'time_col' => 'arrived_at'],
            'complete' => ['from' => 'in_progress', 'to' => 'completed',   'time_col' => 'completed_at'],
            'cancel'   => ['from' => ['pending', 'assigned'], 'to' => 'cancelled', 'time_col' => null],
        ];

        if (!isset($transitions[$action])) {
            ResponseHelper::error('Invalid action', 400);
            return;
        }

        $t = $transitions[$action];
        $validFrom = is_array($t['from']) ? $t['from'] : [$t['from']];

        if (!in_array($call['status'], $validFrom, true)) {
            ResponseHelper::error("Cannot {$action} a call with status: {$call['status']}", 409);
            return;
        }

        $setClauses = ["status = ?"];
        $params     = [$t['to']];

        if ($t['time_col']) {
            $setClauses[] = "{$t['time_col']} = NOW(3)";
        }

        if ($action === 'complete') {
            $setClauses[] = "response_time_ms = TIMESTAMPDIFF(MICROSECOND, initiated_at, NOW(3)) / 1000";
            $setClauses[] = "notes = ?";
            $params[]     = htmlspecialchars($notes, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Return nurse to available
            $this->db->prepare("UPDATE nurses SET status = 'available' WHERE id = ?")->execute([(int)$call['nurse_id']]);
        }

        $params[] = $callId;

        $stmt = $this->db->prepare(
            "UPDATE calls SET " . implode(', ', $setClauses) . " WHERE id = ?"
        );
        $stmt->execute($params);

        // Audit log
        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (call_id, nurse_id, action, actor, reason, created_at)
             VALUES (?, ?, ?, 'nurse', ?, NOW())"
        );
        $stmt->execute([$callId, $nurseId, "call_{$action}", $notes ?: null]);

        // Create event for Ratchet
        $stmt = $this->db->prepare(
            "INSERT INTO events (type, payload, dept_id, hospital_id, is_broadcast, created_at)
             VALUES (?, ?, (SELECT dept_id FROM calls WHERE id = ?), ?, 0, NOW(3))"
        );
        $stmt->execute([
            "call_{$action}",
            json_encode(['call_id' => $callId, 'nurse_id' => $nurseId, 'status' => $t['to']], JSON_THROW_ON_ERROR),
            $callId,
            $hospitalId,
        ]);

        ResponseHelper::success(['call_id' => $callId, 'status' => $t['to']]);
    }
}
