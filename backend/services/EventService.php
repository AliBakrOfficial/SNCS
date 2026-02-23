<?php

declare(strict_types=1);

/**
 * SNCS — Event Service
 *
 * Bridge between the REST API and the Ratchet WebSocket server.
 * Writes event records to the events table for Ratchet to pick up
 * via incremental polling (WHERE id > last_id LIMIT batch_size).
 *
 * @package App\Services
 */

namespace App\Services;

use PDO;

class EventService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new event for WebSocket broadcast.
     *
     * @param string               $type       Event type (e.g., 'call_created', 'call_assigned')
     * @param array<string, mixed> $payload    Event payload data
     * @param int                  $deptId     Target department ID
     * @param int                  $hospitalId Target hospital ID
     */
    public function create(string $type, array $payload, int $deptId, int $hospitalId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO events (type, payload, dept_id, hospital_id, is_broadcast, created_at)
             VALUES (?, ?, ?, ?, 0, NOW(3))"
        );

        $stmt->execute([
            $type,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $deptId,
            $hospitalId,
        ]);
    }

    /**
     * Fetch unbroadcasted events since lastId for a department.
     * Used by Ratchet's incremental polling loop.
     *
     * @param int $deptId    Department ID to poll for
     * @param int $lastId    Last event ID already processed
     * @param int $batchSize Max events to return (default: 50)
     * @return array<int, array<string, mixed>>
     */
    public function pollEvents(int $deptId, int $lastId, int $batchSize = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, type, payload, dept_id, hospital_id, created_at
             FROM events
             WHERE dept_id = ? AND id > ? AND is_broadcast = 0
             ORDER BY id ASC
             LIMIT ?"
        );
        $stmt->execute([$deptId, $lastId, $batchSize]);

        return $stmt->fetchAll();
    }

    /**
     * Mark events as broadcasted after Ratchet sends them to clients.
     *
     * @param array<int> $eventIds List of event IDs to mark
     */
    public function markBroadcasted(array $eventIds): void
    {
        if (empty($eventIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->db->prepare(
            "UPDATE events SET is_broadcast = 1 WHERE id IN ({$placeholders})"
        );
        $stmt->execute($eventIds);
    }

    /**
     * Clean up old broadcasted events (retention: 24 hours).
     *
     * @return int Number of deleted events
     */
    public function cleanup(): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM events WHERE is_broadcast = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }
}
