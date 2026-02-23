<?php

declare(strict_types=1);

/**
 * SNCS — Ratchet WebSocket Server
 *
 * Bootstraps the Ratchet IoServer on port 8080 with:
 * - Connection limits (max 500, warn at 400)
 * - Graceful shutdown (SIGTERM → broadcast → 10s drain → exit)
 * - Health endpoint via periodic timer
 *
 * Usage:
 *   php backend/websocket/server.php
 *
 * @package App\WebSocket
 */

require_once __DIR__ . '/../config.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

$db = createPDO();

$messageHandler = new \App\WebSocket\MessageHandler($db);

$wsServer = new WsServer($messageHandler);
$wsServer->setStrictSubProtocolCheck(false);

$httpServer = new HttpServer($wsServer);

$server = IoServer::factory(
    $httpServer,
    WS_CONFIG['port'],
    WS_CONFIG['host']
);

// ── Periodic Tasks ─────────────────────────────────────────
$loop = $server->loop;

// Health check: memory monitoring (every 30 seconds)
$loop->addPeriodicTimer(30, function () use ($messageHandler) {
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = ini_get('memory_limit');
    $memoryLimitBytes = self::parseMemoryLimit($memoryLimit);
    $memoryPct = ($memoryLimitBytes > 0) ? ($memoryUsage / $memoryLimitBytes) * 100 : 0;

    if ($memoryPct > 80) {
        echo "[WARN] Memory usage at {$memoryPct}% — consider restarting\n";
        // In production: trigger graceful restart
    }

    // Log connection count
    $connCount = $messageHandler->getConnectionCount();
    echo "[HEALTH] connections={$connCount} memory_pct=" . round($memoryPct, 1) . "%\n";
});

// Incremental polling: events table (every 500ms)
$loop->addPeriodicTimer(0.5, function () use ($messageHandler) {
    $messageHandler->pollAndBroadcast();
});

// Escalation check (every 15 seconds)
$escalationService = new \App\Services\EscalationService($db);
$loop->addPeriodicTimer(15, function () use ($escalationService) {
    $result = $escalationService->processEscalations();
    if ($result['escalated'] > 0) {
        echo "[ESCALATION] Escalated {$result['escalated']} call(s)\n";
    }
});

// Session validation (every 30 seconds)
$loop->addPeriodicTimer(30, function () use ($messageHandler) {
    $messageHandler->validateSessions();
});

// Event cleanup (every hour)
$loop->addPeriodicTimer(3600, function () use ($db) {
    $eventService = new \App\Services\EventService($db);
    $deleted = $eventService->cleanup();
    if ($deleted > 0) {
        echo "[CLEANUP] Deleted {$deleted} old events\n";
    }
});

// ── Graceful Shutdown ──────────────────────────────────────
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use ($messageHandler, $server) {
        echo "[SHUTDOWN] SIGTERM received — broadcasting shutdown\n";
        $messageHandler->broadcastShutdown();

        // 10 second drain period
        $server->loop->addTimer(10, function () use ($server) {
            echo "[SHUTDOWN] Drain complete — exiting\n";
            $server->loop->stop();
            exit(0);
        });
    });

    pcntl_signal(SIGINT, function () use ($server) {
        echo "[SHUTDOWN] SIGINT received — stopping immediately\n";
        $server->loop->stop();
        exit(0);
    });
}

// ── Start Server ───────────────────────────────────────────
echo "╔══════════════════════════════════════════════╗\n";
echo "║  SNCS WebSocket Server                      ║\n";
echo "║  Listening on " . WS_CONFIG['host'] . ':' . WS_CONFIG['port'] . "                  ║\n";
echo "╚══════════════════════════════════════════════╝\n";

$server->run();

/**
 * Parse PHP memory_limit to bytes.
 *
 * @param string $limit Memory limit string (e.g., '128M')
 * @return int Bytes
 */
function parseMemoryLimit(string $limit): int
{
    $limit = trim($limit);
    $last  = strtolower(substr($limit, -1));
    $value = (int)$limit;

    return match ($last) {
        'g' => $value * 1024 * 1024 * 1024,
        'm' => $value * 1024 * 1024,
        'k' => $value * 1024,
        default => $value,
    };
}
