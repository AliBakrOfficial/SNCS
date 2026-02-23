<?php

declare(strict_types=1);

/**
 * SNCS — Admin Controller
 *
 * CRUD operations for system management:
 * - Hospitals, departments, rooms, staff
 * - System settings
 * - Audit log queries
 *
 * @package App\Controllers
 */

namespace App\Controllers;

use App\Helpers\ResponseHelper;
use PDO;

class AdminController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Hospitals ───────────────────────────────────────────

    /**
     * List hospitals (superadmin only).
     */
    public function listHospitals(): void
    {
        $this->requireRole(['superadmin']);

        $stmt = $this->db->query("SELECT id, name, name_en, city, is_active, created_at FROM hospitals ORDER BY name");
        ResponseHelper::success($stmt->fetchAll());
    }

    /**
     * Create a hospital.
     *
     * @param array{name: string, city: string, name_en?: string} $data
     */
    public function createHospital(array $data): void
    {
        $this->requireRole(['superadmin']);

        $name   = htmlspecialchars(trim($data['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $nameEn = htmlspecialchars(trim($data['name_en'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $city   = htmlspecialchars(trim($data['city'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (empty($name) || empty($city)) {
            ResponseHelper::error('Name and city are required', 400);
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO hospitals (name, name_en, city, is_active, created_at) VALUES (?, ?, ?, 1, NOW())"
        );
        $stmt->execute([$name, $nameEn ?: null, $city]);

        $this->audit('hospital_created', ['hospital_id' => (int)$this->db->lastInsertId()]);
        ResponseHelper::success(['id' => (int)$this->db->lastInsertId()], 201);
    }

    // ── Departments ─────────────────────────────────────────

    /**
     * List departments for a hospital.
     */
    public function listDepartments(): void
    {
        $this->requireRole(['superadmin', 'hospital_admin']);
        $hospitalId = $this->getHospitalId();

        $stmt = $this->db->prepare(
            "SELECT id, name, name_en, is_active, created_at FROM departments WHERE hospital_id = ? ORDER BY name"
        );
        $stmt->execute([$hospitalId]);
        ResponseHelper::success($stmt->fetchAll());
    }

    /**
     * Create a department.
     *
     * @param array{name: string, name_en?: string} $data
     */
    public function createDepartment(array $data): void
    {
        $this->requireRole(['superadmin', 'hospital_admin']);
        $hospitalId = $this->getHospitalId();

        $name   = htmlspecialchars(trim($data['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $nameEn = htmlspecialchars(trim($data['name_en'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (empty($name)) {
            ResponseHelper::error('Department name is required', 400);
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO departments (hospital_id, name, name_en, is_active) VALUES (?, ?, ?, 1)"
        );
        $stmt->execute([$hospitalId, $name, $nameEn ?: null]);

        $this->audit('department_created', ['department_id' => (int)$this->db->lastInsertId()]);
        ResponseHelper::success(['id' => (int)$this->db->lastInsertId()], 201);
    }

    // ── Rooms ───────────────────────────────────────────────

    /**
     * List rooms for a department.
     *
     * @param int $deptId Department ID
     */
    public function listRooms(int $deptId): void
    {
        $this->requireRole(['superadmin', 'hospital_admin', 'dept_manager']);
        $hospitalId = $this->getHospitalId();

        $stmt = $this->db->prepare(
            "SELECT id, room_number, qr_code, is_active, created_at
             FROM rooms WHERE dept_id = ? AND hospital_id = ? ORDER BY room_number"
        );
        $stmt->execute([$deptId, $hospitalId]);
        ResponseHelper::success($stmt->fetchAll());
    }

    /**
     * Create a room.
     *
     * @param array{dept_id: int, room_number: string} $data
     */
    public function createRoom(array $data): void
    {
        $this->requireRole(['superadmin', 'hospital_admin']);
        $hospitalId = $this->getHospitalId();

        $deptId     = (int)($data['dept_id'] ?? 0);
        $roomNumber = htmlspecialchars(trim($data['room_number'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($deptId <= 0 || empty($roomNumber)) {
            ResponseHelper::error('Department ID and room number are required', 400);
            return;
        }

        // Generate unique QR code
        $qrCode = 'SNCS-' . strtoupper(bin2hex(random_bytes(8)));

        $stmt = $this->db->prepare(
            "INSERT INTO rooms (hospital_id, dept_id, room_number, qr_code, is_active) VALUES (?, ?, ?, ?, 1)"
        );
        $stmt->execute([$hospitalId, $deptId, $roomNumber, $qrCode]);

        $this->audit('room_created', ['room_id' => (int)$this->db->lastInsertId()]);
        ResponseHelper::success(['id' => (int)$this->db->lastInsertId(), 'qr_code' => $qrCode], 201);
    }

    // ── Staff Management ────────────────────────────────────

    /**
     * List staff for a hospital.
     *
     * @param string|null $role Filter by role
     */
    public function listStaff(?string $role = null): void
    {
        $this->requireRole(['superadmin', 'hospital_admin']);
        $hospitalId = $this->getHospitalId();

        $sql    = "SELECT id, username, role, full_name, department_id, is_active, last_login, created_at FROM users WHERE hospital_id = ?";
        $params = [$hospitalId];

        if ($role) {
            $sql      .= " AND role = ?";
            $params[] = $role;
        }

        $sql .= " ORDER BY full_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        ResponseHelper::success($stmt->fetchAll());
    }

    /**
     * Create a staff member.
     *
     * @param array{username: string, password: string, role: string, full_name: string, department_id?: int} $data
     */
    public function createStaff(array $data): void
    {
        $this->requireRole(['superadmin', 'hospital_admin']);
        $hospitalId = $this->getHospitalId();

        $username  = trim($data['username'] ?? '');
        $password  = $data['password'] ?? '';
        $role      = $data['role'] ?? '';
        $fullName  = htmlspecialchars(trim($data['full_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $deptId    = isset($data['department_id']) ? (int)$data['department_id'] : null;

        // Validate
        if (empty($username) || empty($password) || empty($role) || empty($fullName)) {
            ResponseHelper::error('Username, password, role, and full name are required', 400);
            return;
        }

        $validRoles = ['hospital_admin', 'dept_manager', 'nurse'];
        if (!in_array($role, $validRoles, true)) {
            ResponseHelper::error('Invalid role', 400);
            return;
        }

        if (strlen($password) < 8) {
            ResponseHelper::error('Password must be at least 8 characters', 400);
            return;
        }

        // Hash password
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $this->db->prepare(
            "INSERT INTO users (username, password, role, full_name, hospital_id, department_id, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW())"
        );
        $stmt->execute([$username, $passwordHash, $role, $fullName, $hospitalId, $deptId]);

        $userId = (int)$this->db->lastInsertId();

        // If role is nurse, create nurse record
        if ($role === 'nurse' && $deptId) {
            $stmt = $this->db->prepare(
                "INSERT INTO nurses (hospital_id, dept_id, user_id, name, status)
                 VALUES (?, ?, ?, ?, 'offline')"
            );
            $stmt->execute([$hospitalId, $deptId, $userId, $fullName]);
        }

        $this->audit('staff_created', ['user_id' => $userId, 'role' => $role]);
        ResponseHelper::success(['id' => $userId], 201);
    }

    // ── System Settings ─────────────────────────────────────

    /**
     * Get system settings.
     */
    public function getSettings(): void
    {
        $this->requireRole(['superadmin', 'hospital_admin']);

        $stmt = $this->db->query("SELECT setting_key, value FROM system_settings ORDER BY setting_key");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['value'];
        }
        ResponseHelper::success($settings);
    }

    /**
     * Update a system setting.
     *
     * @param array{key: string, value: string} $data
     */
    public function updateSetting(array $data): void
    {
        $this->requireRole(['superadmin']);

        $key   = trim($data['key'] ?? '');
        $value = trim($data['value'] ?? '');

        if (empty($key)) {
            ResponseHelper::error('Setting key is required', 400);
            return;
        }

        $stmt = $this->db->prepare(
            "UPDATE system_settings SET value = ? WHERE setting_key = ?"
        );
        $stmt->execute([$value, $key]);

        if ($stmt->rowCount() === 0) {
            ResponseHelper::error('Setting not found', 404);
            return;
        }

        $this->audit('setting_updated', ['key' => $key, 'value' => $value]);
        ResponseHelper::success(['key' => $key, 'value' => $value]);
    }

    // ── Audit Log ───────────────────────────────────────────

    /**
     * Query audit log entries.
     *
     * @param array{limit?: int, offset?: int, action?: string} $filters
     */
    public function getAuditLog(array $filters): void
    {
        $this->requireRole(['superadmin', 'hospital_admin']);

        $limit  = min((int)($filters['limit'] ?? 50), 200);
        $offset = max((int)($filters['offset'] ?? 0), 0);

        $sql    = "SELECT id, call_id, nurse_id, action, actor, reason, meta_json, created_at FROM audit_log";
        $params = [];

        if (!empty($filters['action'])) {
            $sql .= " WHERE action = ?";
            $params[] = $filters['action'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        ResponseHelper::success($stmt->fetchAll());
    }

    // ── Helpers ─────────────────────────────────────────────

    /**
     * Require specific role(s) for the current user.
     *
     * @param array<string> $roles Allowed roles
     */
    private function requireRole(array $roles): void
    {
        $role = $_SESSION['user']['role'] ?? '';
        if (!in_array($role, $roles, true)) {
            ResponseHelper::error('Insufficient permissions', 403);
        }
    }

    /**
     * Get the hospital ID from the current session.
     *
     * @return int
     */
    private function getHospitalId(): int
    {
        return (int)($_SESSION['user']['hospital_id'] ?? 0);
    }

    /**
     * Write an audit log entry.
     *
     * @param string                $action Action name
     * @param array<string, mixed>  $meta   Additional metadata
     */
    private function audit(string $action, array $meta = []): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (action, actor, meta_json, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([
            $action,
            $_SESSION['user']['username'] ?? 'system',
            json_encode($meta, JSON_THROW_ON_ERROR),
        ]);
    }
}
