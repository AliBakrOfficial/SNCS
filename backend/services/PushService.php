<?php

declare(strict_types=1);

/**
 * SNCS — Web Push Notification Service
 *
 * Sends push notifications to subscribed users via Web Push + VAPID.
 * Uses minishlink/web-push library.
 *
 * @package App\Services
 */

namespace App\Services;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use PDO;

class PushService
{
    private PDO $db;
    private WebPush $webPush;

    public function __construct(PDO $db)
    {
        $this->db = $db;

        $auth = [
            'VAPID' => [
                'subject'    => VAPID_CONFIG['subject'],
                'publicKey'  => VAPID_CONFIG['public_key'],
                'privateKey' => VAPID_CONFIG['private_key'],
            ],
        ];

        $this->webPush = new WebPush($auth);
    }

    /**
     * Subscribe a user for push notifications.
     *
     * @param int    $userId   User ID
     * @param string $endpoint Push subscription endpoint
     * @param string $p256dh   P256DH key
     * @param string $authKey  Auth key
     */
    public function subscribe(int $userId, string $endpoint, string $p256dh, string $authKey): void
    {
        // Remove existing subscription for same endpoint
        $stmt = $this->db->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
        $stmt->execute([$userId, $endpoint]);

        $stmt = $this->db->prepare(
            "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$userId, $endpoint, $p256dh, $authKey]);
    }

    /**
     * Unsubscribe a user from push notifications.
     *
     * @param int    $userId   User ID
     * @param string $endpoint Endpoint to remove
     */
    public function unsubscribe(int $userId, string $endpoint): void
    {
        $stmt = $this->db->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
        $stmt->execute([$userId, $endpoint]);
    }

    /**
     * Send a push notification to a specific user.
     *
     * @param int                  $userId  Target user ID
     * @param array<string, mixed> $payload Notification payload
     * @return int Number of notifications sent
     */
    public function sendToUser(int $userId, array $payload): int
    {
        $stmt = $this->db->prepare(
            "SELECT endpoint, p256dh, auth_key FROM push_subscriptions WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $subscriptions = $stmt->fetchAll();

        return $this->sendNotifications($subscriptions, $payload);
    }

    /**
     * Send push notifications to all nurses in a department.
     *
     * @param int                  $deptId  Department ID
     * @param array<string, mixed> $payload Notification payload
     * @return int Number of notifications sent
     */
    public function sendToDepartment(int $deptId, array $payload): int
    {
        $stmt = $this->db->prepare(
            "SELECT ps.endpoint, ps.p256dh, ps.auth_key
             FROM push_subscriptions ps
             JOIN nurses n ON ps.user_id = n.user_id
             WHERE n.dept_id = ? AND n.status != 'offline'"
        );
        $stmt->execute([$deptId]);
        $subscriptions = $stmt->fetchAll();

        return $this->sendNotifications($subscriptions, $payload);
    }

    /**
     * Send notifications to a list of subscriptions.
     *
     * @param array<array<string, string>> $subscriptions
     * @param array<string, mixed>         $payload
     * @return int Number sent
     */
    private function sendNotifications(array $subscriptions, array $payload): int
    {
        $sent = 0;
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys'     => [
                    'p256dh' => $sub['p256dh'],
                    'auth'   => $sub['auth_key'],
                ],
            ]);

            $this->webPush->queueNotification($subscription, $jsonPayload);
        }

        // Flush queued notifications
        foreach ($this->webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
            } else {
                // Remove invalid subscriptions (HTTP 410 Gone)
                if ($report->getResponse()?->getStatusCode() === 410) {
                    $stmt = $this->db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
                    $stmt->execute([$report->getEndpoint()]);
                }
            }
        }

        return $sent;
    }
}
