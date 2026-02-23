<?php

declare(strict_types=1);

/**
 * SNCS — Nurse Controller
 *
 * Handles nurse operations:
 * - QR scan verification
 * - Shift management (start/end → dispatch_queue write)
 * - Room assignments
 * - Availability toggling
 *
 * @package App\Controllers
 */

namespace App\Controllers;

use App\Helpers\ResponseHelper;
use PDO;

class NurseController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get nurse profile and current shift info.
     */
    public function profile(): void
    {
        $userId     = (int)($_SESSION['user']['id'] ?? 0);
        $hospitalId = (int)($_SESSION['user']['hospital_id'] ?? 0);

        $stmt = $this->db->prepare(
            "SELECT n.id, n.name, n.status, n.last_assigned_at, n.dept_id,
                    d.name AS dept_name,
                    ns.id AS shift_id, ns.started_at, ns.total_calls
             FROM nurses n
             JOIN departments d ON n.dept_id = d.id
             LEFT JOIN nurse_shifts ns ON n.id = ns.nurse_id AND ns.status = 'active'
             WHERE n.user_id = ? AND n.hospital_id = ?"
        );
        $stmt->execute([$userId, $hospitalId]);
        $nurse = $stmt->fetch();

        if (!$nurse) {
            ResponseHelper::error('Nurse profile not found', 404);
            return;
        }

        ResponseHelper::success($nurse);
    }

    /**
     * Start a nurse shift — adds nurse to dispatch_queue.
     */
    public function startShift(): void
    {
        $userId     = (int)($_SESSION['user']['id'] ?? 0);
        $hospitalId = (int)($_SESSION['user']['hospital_id'] ?? 0);

        // Get nurse
        $stmt = $this->db->prepare("SELECT id, dept_id FROM nurses WHERE user_id = ? AND hospital_id = ?");
        $stmt->execute([$userId, $hospitalId]);
        $nurse = $stmt->fetch();

        if (!$nurse) {
            ResponseHelper::error('Nurse not found', 404);
            return;
        }

        $nurseId = (int)$nurse['id'];
        $deptId  = (int)$nurse['dept_id'];

        // Check if shift already active
        $stmt = $this->db->prepare("SELECT id FROM nurse_shifts WHERE nurse_id = ? AND status = 'active'");
        $stmt->execute([$nurseId]);
        if ($stmt->fetch()) {
            ResponseHelper::error('Shift already active', 409);
            return;
        }

        $this->db->beginTransaction();

        try {
            // Create shift
            $stmt = $this->db->prepare(
                "INSERT INTO nurse_shifts (nurse_id, dept_id, hospital_id, started_at, status)
                 VALUES (?, ?, ?, NOW(), 'active')"
            );
            $stmt->execute([$nurseId, $deptId, $hospitalId]);

            // Set nurse available
            $stmt = $this->db->prepare("UPDATE nurses SET status = 'available' WHERE id = ?");
            $stmt->execute([$nurseId]);

            // Add to dispatch_queue (end of queue)
            $stmt = $this->db->prepare(
                "SELECT COALESCE(MAX(queue_position), 0) + 1 AS next_pos
                 FROM dispatch_queue WHERE dept_id = ?"
            );
            $stmt->execute([$deptId]);
            $nextPos = (int)$stmt->fetch()['next_pos'];

            $stmt = $this->db->prepare(
                "INSERT INTO dispatch_queue (dept_id, nurse_id, hospital_id, queue_position, is_excluded)
                 VALUES (?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE queue_position = VALUES(queue_position), is_excluded = 0"
            );
            $stmt->execute([$deptId, $nurseId, $hospitalId, $nextPos]);

            // Audit log
            $stmt = $this->db->prepare(
                "INSERT INTO audit_log (nurse_id, action, actor, created_at)
                 VALUES (?, 'shift_start', 'nurse', NOW())"
            );
            $stmt->execute([$nurseId]);

            $this->db->commit();

            ResponseHelper::success(['shift_started' => true, 'queue_position' => $nextPos], 201);

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * End a nurse shift — removes nurse from dispatch_queue.
     */
    public function endShift(): void
    {
        $userId     = (int)($_SESSION['user']['id'] ?? 0);
        $hospitalId = (int)($_SESSION['user']['hospital_id'] ?? 0);

        $stmt = $this->db->prepare("SELECT id, dept_id FROM nurses WHERE user_id = ? AND hospital_id = ?");
        $stmt->execute([$userId, $hospitalId]);
        $nurse = $stmt->fetch();

        if (!$nurse) {
            ResponseHelper::error('Nurse not found', 404);
            return;
        }

        $nurseId = (int)$nurse['id'];
        $deptId  = (int)$nurse['dept_id'];

        // Check for active calls
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM calls WHERE nurse_id = ? AND status IN ('assigned', 'in_progress')"
        );
        $stmt->execute([$nurseId]);
        if ((int)$stmt->fetchColumn() > 0) {
            ResponseHelper::error('Cannot end shift with active calls', 409);
            return;
        }

        $this->db->beginTransaction();

        try {
            // End shift
            $stmt = $this->db->prepare(
                "UPDATE nurse_shifts SET status = 'ended', ended_at = NOW()
                 WHERE nurse_id = ? AND status = 'active'"
            );
            $stmt->execute([$nurseId]);

            // Set nurse offline
            $stmt = $this->db->prepare("UPDATE nurses SET status = 'offline' WHERE id = ?");
            $stmt->execute([$nurseId]);

            // Remove from dispatch_queue
            $stmt = $this->db->prepare("DELETE FROM dispatch_queue WHERE nurse_id = ? AND dept_id = ?");
            $stmt->execute([$nurseId, $deptId]);

            // Audit log
            $stmt = $this->db->prepare(
                "INSERT INTO audit_log (nurse_id, action, actor, created_at)
                 VALUES (?, 'shift_end', 'nurse', NOW())"
            );
            $stmt->execute([$nurseId]);

            $this->db->commit();

            ResponseHelper::success(['shift_ended' => true]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get room assignments for the current nurse.
     */
    public function getAssignments(): void
    {
        $userId     = (int)($_SESSION['user']['id'] ?? 0);
        $hospitalId = (int)($_SESSION['user']['hospital_id'] ?? 0);

        $stmt = $this->db->prepare(
            "SELECT nra.id, nra.room_id, r.room_number, nra.is_manual, nra.expires_at
             FROM nurse_room_assignments nra
             JOIN rooms r ON nra.room_id = r.id
             JOIN nurses n ON nra.nurse_id = n.id
             WHERE n.user_id = ? AND n.hospital_id = ?
               AND (nra.expires_at IS NULL OR nra.expires_at > NOW())"
        );
        $stmt->execute([$userId, $hospitalId]);

        ResponseHelper::success($stmt->fetchAll());
    }

    /**
     * Toggle nurse exclusion from dispatch queue.
     *
     * @param array{nurse_id: int, exclude: bool, reason?: string} $data
     */
    public function toggleExclusion(array $data): void
    {
        $nurseId = (int)($data['nurse_id'] ?? 0);
        $exclude = (bool)($data['exclude'] ?? false);
        $reason  = htmlspecialchars(trim($data['reason'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $actorId = (int)($_SESSION['user']['id'] ?? 0);

        if ($exclude) {
            $stmt = $this->db->prepare(
                "UPDATE dispatch_queue
                 SET is_excluded = 1, excluded_until = DATE_ADD(NOW(), INTERVAL 4 HOUR),
                     excluded_by = ?, exclusion_reason = ?
                 WHERE nurse_id = ?"
            );
            $stmt->execute([$actorId, $reason, $nurseId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE dispatch_queue
                 SET is_excluded = 0, excluded_until = NULL, excluded_by = NULL, exclusion_reason = NULL
                 WHERE nurse_id = ?"
            );
            $stmt->execute([$nurseId]);
        }

        // Audit
        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (nurse_id, action, actor, reason, created_at)
             VALUES (?, ?, 'manager', ?, NOW())"
        );
        $stmt->execute([$nurseId, $exclude ? 'nurse_exclude' : 'nurse_restore', $reason]);

        ResponseHelper::success(['nurse_id' => $nurseId, 'excluded' => $exclude]);
    }
}
