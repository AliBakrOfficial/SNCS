<?php

declare(strict_types=1);

/**
 * SNCS — Audit Service
 *
 * Append-only audit log writer.
 * All significant actions are recorded for compliance and traceability.
 *
 * @package App\Services
 */

namespace App\Services;

use PDO;

class AuditService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Write an audit log entry.
     *
     * @param string                $action  Action identifier (e.g., 'call_created', 'user_login')
     * @param string                $actor   Who performed the action (username or 'system')
     * @param int|null              $callId  Associated call ID (if applicable)
     * @param int|null              $nurseId Associated nurse ID (if applicable)
     * @param string|null           $reason  Reason or description
     * @param array<string, mixed>  $meta    Additional metadata (stored as JSON)
     */
    public function log(
        string $action,
        string $actor = 'system',
        ?int $callId = null,
        ?int $nurseId = null,
        ?string $reason = null,
        array $meta = []
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (call_id, nurse_id, action, actor, reason, meta_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );

        $stmt->execute([
            $callId,
            $nurseId,
            $action,
            htmlspecialchars($actor, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $reason ? htmlspecialchars($reason, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
            !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
        ]);
    }

    /**
     * Query audit log entries with filters.
     *
     * @param array<string, mixed> $filters Filter parameters
     * @return array<int, array<string, mixed>>
     */
    public function query(array $filters = []): array
    {
        $conditions = [];
        $params     = [];

        if (!empty($filters['action'])) {
            $conditions[] = 'action = ?';
            $params[]     = $filters['action'];
        }

        if (!empty($filters['actor'])) {
            $conditions[] = 'actor = ?';
            $params[]     = $filters['actor'];
        }

        if (!empty($filters['call_id'])) {
            $conditions[] = 'call_id = ?';
            $params[]     = (int)$filters['call_id'];
        }

        if (!empty($filters['nurse_id'])) {
            $conditions[] = 'nurse_id = ?';
            $params[]     = (int)$filters['nurse_id'];
        }

        if (!empty($filters['from'])) {
            $conditions[] = 'created_at >= ?';
            $params[]     = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $conditions[] = 'created_at <= ?';
            $params[]     = $filters['to'];
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $limit  = min((int)($filters['limit'] ?? 100), 500);
        $offset = max((int)($filters['offset'] ?? 0), 0);

        $stmt = $this->db->prepare(
            "SELECT id, call_id, nurse_id, action, actor, reason, meta_json, created_at
             FROM audit_log {$where}
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?"
        );

        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Export audit logs before purge (retention: 2 years).
     *
     * @param string $before Delete entries before this date (ISO 8601)
     * @return int Number of deleted entries
     */
    public function purgeOldEntries(string $before): int
    {
        // Note: In production, export to file/S3 before purging
        $stmt = $this->db->prepare("DELETE FROM audit_log WHERE created_at < ?");
        $stmt->execute([$before]);

        return $stmt->rowCount();
    }
}
