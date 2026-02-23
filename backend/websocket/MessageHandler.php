<?php

declare(strict_types=1);

/**
 * SNCS — WebSocket Message Handler
 *
 * Implements Ratchet MessageComponentInterface.
 * Handles: authentication, call lifecycle events, ping/pong,
 * incremental polling from events table, and rate limiting.
 *
 * @package App\WebSocket
 */

namespace App\WebSocket;

use App\Services\EventService;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use PDO;

class MessageHandler implements MessageComponentInterface
{
    private PDO $db;
    private EventService $eventService;

    /** @var \SplObjectStorage<ConnectionInterface, array> Connection storage with metadata */
    private \SplObjectStorage $clients;

    /** @var int Last polled event ID */
    private int $lastEventId = 0;

    /** @var int Maximum connections */
    private const MAX_CONNECTIONS = 500;

    /** @var int Warning threshold */
    private const WARN_THRESHOLD = 400;

    /** @var int Max pending messages per connection */
    private const MAX_PENDING = 100;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->eventService = new EventService($db);
        $this->clients = new \SplObjectStorage();

        // Initialize lastEventId to latest event
        $stmt = $db->query("SELECT COALESCE(MAX(id), 0) FROM events");
        $this->lastEventId = (int)$stmt->fetchColumn();
    }

    /**
     * New WebSocket connection opened.
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        $connCount = $this->clients->count();

        // Reject if over limit
        if ($connCount >= self::MAX_CONNECTIONS) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Server full', 'retry_after' => 30]));
            $conn->close();
            return;
        }

        if ($connCount >= self::WARN_THRESHOLD) {
            echo "[WARN] Connection count at {$connCount}/{self::MAX_CONNECTIONS}\n";
        }

        // Store connection with metadata
        $this->clients->attach($conn, [
            'authenticated' => false,
            'user_id'       => null,
            'hospital_id'   => null,
            'dept_id'       => null,
            'role'          => null,
            'pending_count' => 0,
            'connected_at'  => time(),
        ]);

        echo "[OPEN] New connection (total: " . ($connCount + 1) . ")\n";
    }

    /**
     * Message received from a client.
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid message format']));
            return;
        }

        $meta = $this->clients[$from];

        // Rate limit: max 30 messages per second
        if (($meta['pending_count'] ?? 0) > 30) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Rate limited']));
            return;
        }
        $meta['pending_count'] = ($meta['pending_count'] ?? 0) + 1;
        $this->clients[$from] = $meta;

        switch ($data['type']) {
            case 'auth':
                $this->handleAuth($from, $data);
                break;

            case 'ping':
                $from->send(json_encode(['type' => 'pong', 'ts' => time()]));
                break;

            case 'subscribe':
                $this->handleSubscribe($from, $data);
                break;

            default:
                if (!$meta['authenticated']) {
                    $from->send(json_encode(['type' => 'error', 'message' => 'Not authenticated']));
                    return;
                }
                // Forward to message router
                $this->routeMessage($from, $data);
        }
    }

    /**
     * Handle authentication message.
     */
    private function handleAuth(ConnectionInterface $conn, array $data): void
    {
        $sessionId = $data['session_id'] ?? '';

        if (empty($sessionId)) {
            $conn->send(json_encode(['type' => 'auth_error', 'message' => 'Session ID required']));
            return;
        }

        // Validate session against database
        // In production, this would use the session handler to verify
        $stmt = $this->db->prepare(
            "SELECT id, role, hospital_id, department_id FROM users WHERE id = ? AND is_active = 1"
        );
        $stmt->execute([(int)($data['user_id'] ?? 0)]);
        $user = $stmt->fetch();

        if (!$user) {
            $conn->send(json_encode(['type' => 'auth_error', 'message' => 'Invalid session']));
            $conn->close();
            return;
        }

        $meta = $this->clients[$conn];
        $meta['authenticated'] = true;
        $meta['user_id']       = (int)$user['id'];
        $meta['hospital_id']   = $user['hospital_id'] ? (int)$user['hospital_id'] : null;
        $meta['dept_id']       = $user['department_id'] ? (int)$user['department_id'] : null;
        $meta['role']          = $user['role'];
        $this->clients[$conn] = $meta;

        $conn->send(json_encode(['type' => 'auth_ok', 'user_id' => $user['id']]));
        echo "[AUTH] User {$user['id']} authenticated (role: {$user['role']})\n";
    }

    /**
     * Handle department subscription.
     */
    private function handleSubscribe(ConnectionInterface $conn, array $data): void
    {
        $meta = $this->clients[$conn];
        if (!$meta['authenticated']) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Not authenticated']));
            return;
        }

        $deptId = (int)($data['dept_id'] ?? $meta['dept_id']);
        $meta['dept_id'] = $deptId;
        $this->clients[$conn] = $meta;

        $conn->send(json_encode(['type' => 'subscribed', 'dept_id' => $deptId]));
    }

    /**
     * Route authenticated messages.
     */
    private function routeMessage(ConnectionInterface $from, array $data): void
    {
        // Extend based on event types needed
        $from->send(json_encode(['type' => 'ack', 'message_type' => $data['type']]));
    }

    /**
     * Connection closed.
     */
    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
        echo "[CLOSE] Connection closed (total: {$this->clients->count()})\n";
    }

    /**
     * Connection error.
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[ERROR] {$e->getMessage()}\n";
        $conn->close();
    }

    /**
     * Poll events table and broadcast to subscribed clients.
     * Called periodically (every 500ms) by the server loop.
     */
    public function pollAndBroadcast(): void
    {
        // Poll across all departments — use global last ID
        $stmt = $this->db->prepare(
            "SELECT id, type, payload, dept_id, hospital_id, created_at
             FROM events
             WHERE id > ? AND is_broadcast = 0
             ORDER BY id ASC
             LIMIT 50"
        );
        $stmt->execute([$this->lastEventId]);
        $events = $stmt->fetchAll();

        if (empty($events)) {
            return;
        }

        $broadcastedIds = [];

        foreach ($events as $event) {
            $deptId     = (int)$event['dept_id'];
            $hospitalId = (int)$event['hospital_id'];
            $message    = json_encode([
                'type'    => $event['type'],
                'payload' => json_decode($event['payload'], true),
                'ts'      => $event['created_at'],
            ], JSON_UNESCAPED_UNICODE);

            // Broadcast to matching clients
            foreach ($this->clients as $client) {
                $meta = $this->clients[$client];
                if (
                    $meta['authenticated']
                    && $meta['hospital_id'] === $hospitalId
                    && ($meta['dept_id'] === $deptId || $meta['role'] === 'superadmin')
                ) {
                    if ($meta['pending_count'] < self::MAX_PENDING) {
                        $client->send($message);
                    }
                }
            }

            $broadcastedIds[] = (int)$event['id'];
            $this->lastEventId = (int)$event['id'];
        }

        // Mark as broadcasted
        $this->eventService->markBroadcasted($broadcastedIds);
    }

    /**
     * Validate all connected sessions (called every 30 seconds).
     */
    public function validateSessions(): void
    {
        foreach ($this->clients as $client) {
            $meta = $this->clients[$client];
            if (!$meta['authenticated']) {
                // Disconnect unauthenticated connections older than 30 seconds
                if ((time() - $meta['connected_at']) > 30) {
                    $client->send(json_encode(['type' => 'error', 'message' => 'Auth timeout']));
                    $client->close();
                }
                continue;
            }

            // Reset pending count each cycle
            $meta['pending_count'] = 0;
            $this->clients[$client] = $meta;
        }
    }

    /**
     * Broadcast shutdown notice to all clients.
     */
    public function broadcastShutdown(): void
    {
        $message = json_encode(['type' => 'server_shutdown', 'message' => 'Server restarting', 'retry_after' => 10]);
        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }

    /**
     * Get current connection count.
     *
     * @return int
     */
    public function getConnectionCount(): int
    {
        return $this->clients->count();
    }
}
