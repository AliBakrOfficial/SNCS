<?php

declare(strict_types=1);

/**
 * SNCS — Escalation Service
 *
 * Handles timeout-based call escalation:
 *   L1: 90s  → next floor nurse (priority += 1)
 *   L2: 180s → department manager (priority += 2)
 *   L3: 300s → system admin (priority += 5)
 *
 * Reads pending calls, writes to escalation_queue,
 * re-dispatches or notifies managers.
 *
 * @package App\Services
 */

namespace App\Services;

use PDO;

class EscalationService
{
    private PDO $db;

    /**
     * Escalation level configuration.
     *
     * @var array<int, array{timeout_sec: int, notify_role: string, priority_bump: int, reason: string}>
     */
    private const LEVELS = [
        1 => ['timeout_sec' => 90,  'notify_role' => 'nurse',        'priority_bump' => 1, 'reason' => 'no_response_L1'],
        2 => ['timeout_sec' => 180, 'notify_role' => 'dept_manager', 'priority_bump' => 2, 'reason' => 'no_response_L2'],
        3 => ['timeout_sec' => 300, 'notify_role' => 'superadmin',   'priority_bump' => 5, 'reason' => 'no_response_L3'],
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Check for calls needing escalation and process them.
     * Designed to be called periodically (every 10–30 seconds) by the WS server.
     *
     * @return array{escalated: int, details: array<int, array<string, mixed>>}
     */
    public function processEscalations(): array
    {
        $escalated = 0;
        $details   = [];

        foreach (self::LEVELS as $level => $config) {
            $calls = $this->findCallsForEscalation($level, $config['timeout_sec']);

            foreach ($calls as $call) {
                $this->escalateCall((int)$call['id'], $level, $config);
                $escalated++;
                $details[] = [
                    'call_id' => (int)$call['id'],
                    'level'   => $level,
                    'reason'  => $config['reason'],
                ];
            }
        }

        return ['escalated' => $escalated, 'details' => $details];
    }

    /**
     * Find calls that need escalation at a specific level.
     *
     * @param int $level      Escalation level (1, 2, 3)
     * @param int $timeoutSec Timeout in seconds
     * @return array<int, array<string, mixed>>
     */
    private function findCallsForEscalation(int $level, int $timeoutSec): array
    {
        // Level 1: pending calls older than timeout
        // Level 2+: assigned/in_progress calls that already have a lower-level escalation
        if ($level === 1) {
            $stmt = $this->db->prepare(
                "SELECT c.id, c.dept_id, c.hospital_id, c.nurse_id
                 FROM calls c
                 LEFT JOIN escalation_queue eq ON c.id = eq.call_id AND eq.level = ?
                 WHERE c.status IN ('pending', 'assigned')
                   AND c.initiated_at < DATE_SUB(NOW(3), INTERVAL ? SECOND)
                   AND eq.id IS NULL"
            );
        } else {
            $prevLevel = $level - 1;
            $stmt = $this->db->prepare(
                "SELECT c.id, c.dept_id, c.hospital_id, c.nurse_id
                 FROM calls c
                 JOIN escalation_queue eq_prev ON c.id = eq_prev.call_id AND eq_prev.level = {$prevLevel}
                 LEFT JOIN escalation_queue eq_curr ON c.id = eq_curr.call_id AND eq_curr.level = ?
                 WHERE c.status IN ('pending', 'assigned', 'in_progress', 'escalated')
                   AND c.initiated_at < DATE_SUB(NOW(3), INTERVAL ? SECOND)
                   AND eq_curr.id IS NULL"
            );
        }

        $stmt->execute([$level, $timeoutSec]);
        return $stmt->fetchAll();
    }

    /**
     * Escalate a single call.
     *
     * @param int                                                          $callId Call ID
     * @param int                                                          $level  Escalation level
     * @param array{timeout_sec: int, notify_role: string, priority_bump: int, reason: string} $config Level config
     */
    private function escalateCall(int $callId, int $level, array $config): void
    {
        $this->db->beginTransaction();

        try {
            // Insert escalation record
            $stmt = $this->db->prepare(
                "INSERT INTO escalation_queue (call_id, level, reason, created_at)
                 VALUES (?, ?, ?, NOW())"
            );
            $stmt->execute([$callId, $level, $config['reason']]);

            // Update call status and priority
            $stmt = $this->db->prepare(
                "UPDATE calls SET status = 'escalated', priority = priority + ? WHERE id = ?"
            );
            $stmt->execute([$config['priority_bump'], $callId]);

            // Audit log
            $stmt = $this->db->prepare(
                "INSERT INTO audit_log (call_id, action, actor, reason, created_at)
                 VALUES (?, ?, 'system', ?, NOW())"
            );
            $stmt->execute([$callId, "escalation_l{$level}", $config['reason']]);

            // Create event for Ratchet broadcast
            $stmt = $this->db->prepare(
                "INSERT INTO events (type, payload, dept_id, hospital_id, is_broadcast, created_at)
                 VALUES ('call_escalated', ?, (SELECT dept_id FROM calls WHERE id = ?), (SELECT hospital_id FROM calls WHERE id = ?), 0, NOW(3))"
            );
            $stmt->execute([
                json_encode([
                    'call_id' => $callId,
                    'level'   => $level,
                    'reason'  => $config['reason'],
                ], JSON_THROW_ON_ERROR),
                $callId,
                $callId,
            ]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Resolve an escalation (when a nurse responds or call is completed).
     *
     * @param int $callId Call ID
     */
    public function resolveEscalation(int $callId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE escalation_queue SET resolved = 1 WHERE call_id = ? AND resolved = 0"
        );
        $stmt->execute([$callId]);
    }
}
