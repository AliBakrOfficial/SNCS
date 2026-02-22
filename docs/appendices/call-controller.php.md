---
title: "Appendix D — CallController.php"
path: docs/appendices/call-controller.php.md
version: "1.3"
summary: "نص CallController.php الكامل: إنشاء النداء، التحقق من الغرفة، استدعاء SP، وGET_LOCK fallback"
tags: [appendix, php, controller, call, get-lock, stored-procedure]
---

# Appendix D — CallController.php

**Related Paths:** `backend/controllers/CallController.php`

<!-- PATH: backend/controllers/CallController.php -->

```php
<?php
// ============================================================
// SNCS — CallController.php v1.3 (Enterprise Hardened)
// ============================================================
namespace App\Controllers;

use PDO;
use PDOException;

class CallController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ──────────────────────────────────────────────────────────
    // إنشاء نداء جديد — يُستدعى من POST /api/call/initiate
    // ──────────────────────────────────────────────────────────
    public function create(array $data): array
    {
        // hospital_id من SESSION فقط — لا تقبل أي قيمة من العميل
        $hospital_id = (int)($_SESSION['user']['hospital_id'] ?? 0);
        $room_id     = (int)($data['room_id'] ?? 0);

        if (!$room_id || !$hospital_id) {
            return ['success' => false, 'error' => 'INVALID_INPUT'];
        }

        // تحقق أن الغرفة تنتمي للمستشفى الصحيح
        $stmt = $this->db->prepare(
            "SELECT dept_id FROM rooms
             WHERE id = ? AND hospital_id = ? AND is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$room_id, $hospital_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            return ['success' => false, 'error' => 'ROOM_NOT_FOUND'];
        }

        // إنشاء النداء
        $this->db->prepare(
            "INSERT INTO calls (room_id, dept_id, hospital_id, status,
                                initiated_by, initiated_at, is_broadcast)
             VALUES (?, ?, ?, 'pending', 'patient_app', NOW(3), 0)"
        )->execute([$room_id, $room['dept_id'], $hospital_id]);

        $call_id = (int)$this->db->lastInsertId();

        // التعيين التلقائي عبر Stored Procedure
        $result = $this->assignCallViaSP($call_id, (int)$room['dept_id']);

        // إذا فشل SP → محاولة GET_LOCK fallback
        if (!$result['success'] && $result['error'] !== 'NO_NURSE_AVAILABLE') {
            $result = $this->assignCallWithLock((int)$room['dept_id'], $call_id);
        }

        return [
            'success'    => true,
            'call_id'    => $call_id,
            'assignment' => $result,
        ];
    }

    // ──────────────────────────────────────────────────────────
    // التعيين عبر Stored Procedure (الخيار الأول دائماً)
    // ──────────────────────────────────────────────────────────
    private function assignCallViaSP(int $call_id, int $dept_id): array
    {
        try {
            $stmt = $this->db->prepare(
                "CALL sp_assign_call_to_next_nurse(?, ?, @nurse_id, @success, @error)"
            );
            $stmt->execute([$call_id, $dept_id]);
            $stmt->closeCursor();

            $out = $this->db->query(
                "SELECT @nurse_id AS nurse_id, @success AS success, @error AS error"
            )->fetch(PDO::FETCH_ASSOC);

            return [
                'success'  => (bool)$out['success'],
                'nurse_id' => $out['nurse_id'],
                'error'    => $out['error'],
                'method'   => 'stored_procedure',
            ];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'SP_EXCEPTION', 'method' => 'stored_procedure'];
        }
    }

    // ──────────────────────────────────────────────────────────
    // GET_LOCK Fallback — يُستخدم عند عدم توفر SP
    // مناسب لتزامن خفيف (< 50 concurrent requests per dept)
    // ──────────────────────────────────────────────────────────
    public function assignCallWithLock(int $dept_id, int $call_id): array
    {
        $lock_name    = "dispatch_dept_{$dept_id}";
        $lock_timeout = 3; // ثوانٍ

        // محاولة الحصول على القفل
        $stmt = $this->db->prepare(
            "SELECT GET_LOCK(:name, :timeout) AS acquired"
        );
        $stmt->execute([':name' => $lock_name, ':timeout' => $lock_timeout]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int)$row['acquired'] !== 1) {
            // HTTP 423 Locked — يُطبَّق في الـ Router
            http_response_code(423);
            header('Retry-After: 5');
            return ['success' => false, 'error' => 'LOCK_TIMEOUT', 'method' => 'get_lock'];
        }

        try {
            $this->db->beginTransaction();

            // SELECT FOR UPDATE على الممرضة المتاحة
            $stmt = $this->db->prepare(
                "SELECT id FROM nurses
                 WHERE dept_id = :dept AND status = 'available'
                 ORDER BY last_assigned_at ASC
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([':dept' => $dept_id]);
            $nurse = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$nurse) {
                $this->db->rollBack();
                // إضافة للـ escalation_queue
                $this->db->prepare(
                    "INSERT INTO escalation_queue (call_id, reason, created_at)
                     VALUES (?, 'no_available_nurse', NOW())"
                )->execute([$call_id]);
                return ['success' => false, 'error' => 'NO_NURSE_AVAILABLE', 'method' => 'get_lock'];
            }

            // تعيين الممرض
            $this->db->prepare(
                "UPDATE nurses SET status='busy', last_assigned_at=NOW() WHERE id=?"
            )->execute([$nurse['id']]);

            $this->db->prepare(
                "UPDATE calls SET nurse_id=?, status='assigned', assigned_at=NOW()
                 WHERE id=?"
            )->execute([$nurse['id'], $call_id]);

            // Audit Log
            $this->db->prepare(
                "INSERT INTO audit_log (call_id, nurse_id, action, actor, created_at)
                 VALUES (?, ?, 'assigned', 'system', NOW())"
            )->execute([$call_id, $nurse['id']]);

            $this->db->commit();

            return [
                'success'  => true,
                'nurse_id' => $nurse['id'],
                'method'   => 'get_lock',
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'DB_ERROR', 'method' => 'get_lock'];
        } finally {
            // RELEASE_LOCK دائماً في finally لتجنب القفل المعلّق
            $this->db->prepare("SELECT RELEASE_LOCK(:name)")
                ->execute([':name' => $lock_name]);
        }
    }

    // ──────────────────────────────────────────────────────────
    // جلب النداءات النشطة (للـ Dashboard)
    // ──────────────────────────────────────────────────────────
    public function getActive(): array
    {
        // hospital_id من SESSION فقط
        $hospital_id = (int)($_SESSION['user']['hospital_id'] ?? 0);

        $stmt = $this->db->prepare(
            "SELECT c.*,
                    r.room_number,
                    n.name AS nurse_name
             FROM calls c
             JOIN rooms  r ON r.id = c.room_id
             LEFT JOIN nurses n ON n.id = c.nurse_id
             WHERE c.hospital_id = ?
               AND c.status != 'completed'
             ORDER BY c.priority DESC, c.initiated_at ASC"
        );
        $stmt->execute([$hospital_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
```
