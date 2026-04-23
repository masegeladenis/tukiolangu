<?php

namespace App\Helpers;

use App\Database\Connection;

class Auth
{
    /**
     * All available permissions mapped to their human-readable labels.
     */
    public const PERMISSIONS = [
        'dashboard'              => 'View Dashboard',
        'events_view'            => 'View Events',
        'events_manage'          => 'Create / Edit / Delete Events',
        'batches'                => 'Upload & Process Card Batches',
        'participants_view'      => 'View Participants',
        'participants_manage'    => 'Add / Edit / Delete Participants',
        'participants_checkin'   => 'Check-In Participants',
        'scanner'                => 'QR Code Scanner',
        'reports'                => 'View Reports & Analytics',
        'sms'                    => 'SMS Management',
        'users_manage'           => 'Manage Users & Permissions',
    ];

    /**
     * Role presets — quick permission bundles shown on the Users form.
     */
    public const ROLE_PRESETS = [
        'super_admin' => [
            'label'       => 'Super Admin',
            'permissions' => [
                'dashboard','events_view','events_manage','batches',
                'participants_view','participants_manage','participants_checkin',
                'scanner','reports','sms','users_manage',
            ],
        ],
        'event_admin' => [
            'label'       => 'Event Admin',
            'permissions' => [
                'dashboard','events_view','events_manage','batches',
                'participants_view','participants_manage','participants_checkin',
                'scanner','reports','sms',
            ],
        ],
        'scanner' => [
            'label'       => 'Scanner',
            'permissions' => [
                'dashboard','participants_view','participants_checkin','scanner',
            ],
        ],
        'viewer' => [
            'label'       => 'Viewer',
            'permissions' => [
                'dashboard','participants_view','reports',
            ],
        ],
        'custom' => [
            'label'       => 'Custom',
            'permissions' => [],
        ],
    ];

    // ── Login / Session ──────────────────────────────────────────────────────

    public static function attempt(string $username, string $password): bool
    {
        $db = Connection::getInstance();

        $user = $db->queryOne(
            "SELECT * FROM users WHERE username = :username AND is_active = 1",
            ['username' => $username]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }

        $db->execute(
            "UPDATE users SET last_login = NOW() WHERE id = :id",
            ['id' => $user['id']]
        );

        $permissions = [];
        if (!empty($user['permissions'])) {
            $decoded = json_decode($user['permissions'], true);
            if (is_array($decoded)) {
                $permissions = $decoded;
            }
        }

        Session::set('user_id',     $user['id']);
        Session::set('username',    $user['username']);
        Session::set('full_name',   $user['full_name']);
        Session::set('role',        $user['role']);
        Session::set('permissions', $permissions);
        Session::set('logged_in',   true);

        return true;
    }

    public static function check(): bool
    {
        return Session::get('logged_in', false) === true;
    }

    public static function id(): ?int
    {
        return Session::get('user_id');
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        return [
            'id'          => Session::get('user_id'),
            'username'    => Session::get('username'),
            'full_name'   => Session::get('full_name'),
            'role'        => Session::get('role'),
            'permissions' => Session::get('permissions', []),
        ];
    }

    // ── Permission checks ────────────────────────────────────────────────────

    public static function hasPermission(string $permission): bool
    {
        $perms = Session::get('permissions', []);
        return in_array($permission, (array) $perms, true);
    }

    public static function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (self::hasPermission($p)) {
                return true;
            }
        }
        return false;
    }

    /** Legacy: "admin" = has users_manage permission */
    public static function isAdmin(): bool
    {
        return self::hasPermission('users_manage');
    }

    // ── Access guards ────────────────────────────────────────────────────────

    public static function require(): void
    {
        if (!self::check()) {
            Utils::redirect('/login.php');
        }
    }

    public static function requirePermission(string $permission): void
    {
        self::require();
        if (!self::hasPermission($permission)) {
            Utils::redirect('/dashboard.php');
        }
    }

    /** Legacy alias */
    public static function requireAdmin(): void
    {
        self::requirePermission('users_manage');
    }

    // ── Event scoping ─────────────────────────────────────────────────────────

    /**
     * Event IDs the current user is allowed to see.
     *
     * Rules:
     *  - Has assigned events in event_users → only those.
     *  - No assignments + has users_manage  → all events (true super admin).
     *  - Otherwise                          → empty (no access).
     */
    public static function getAssignedEventIds(): array
    {
        $userId = self::id();
        if (!$userId) {
            return [];
        }

        $db   = Connection::getInstance();
        $rows = $db->query(
            "SELECT event_id FROM event_users WHERE user_id = :user_id",
            ['user_id' => $userId]
        );
        $assigned = array_column($rows, 'event_id');

        if (!empty($assigned)) {
            return array_map('intval', $assigned);
        }

        if (self::hasPermission('users_manage')) {
            $allRows = $db->query("SELECT id FROM events ORDER BY created_at DESC");
            return array_map('intval', array_column($allRows, 'id'));
        }

        return [];
    }

    public static function canAccessEvent(int $eventId): bool
    {
        return in_array($eventId, self::getAssignedEventIds(), true);
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    public static function logout(): void
    {
        Session::destroy();
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
