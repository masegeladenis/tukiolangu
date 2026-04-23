<?php

namespace App\Helpers;

use App\Database\Connection;

class Auth
{
    /**
     * Attempt to log in user
     */
    public static function attempt(string $username, string $password): bool
    {
        $db = Connection::getInstance();
        
        $user = $db->queryOne(
            "SELECT * FROM users WHERE username = :username AND is_active = 1",
            ['username' => $username]
        );

        if (!$user) {
            return false;
        }

        if (!password_verify($password, $user['password'])) {
            return false;
        }

        // Update last login
        $db->execute(
            "UPDATE users SET last_login = NOW() WHERE id = :id",
            ['id' => $user['id']]
        );

        // Store user in session
        Session::set('user_id', $user['id']);
        Session::set('username', $user['username']);
        Session::set('full_name', $user['full_name']);
        Session::set('role', $user['role']);
        Session::set('logged_in', true);

        return true;
    }

    /**
     * Check if user is logged in
     */
    public static function check(): bool
    {
        return Session::get('logged_in', false) === true;
    }

    /**
     * Get current user ID
     */
    public static function id(): ?int
    {
        return Session::get('user_id');
    }

    /**
     * Get current user data
     */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        return [
            'id' => Session::get('user_id'),
            'username' => Session::get('username'),
            'full_name' => Session::get('full_name'),
            'role' => Session::get('role')
        ];
    }

    /**
     * Check if current user is admin
     */
    public static function isAdmin(): bool
    {
        return Session::get('role') === 'admin';
    }

    /**
     * Check if current user is scanner
     */
    public static function isScanner(): bool
    {
        return Session::get('role') === 'scanner';
    }

    /**
     * Log out user
     */
    public static function logout(): void
    {
        Session::destroy();
    }

    /**
     * Require authentication, redirect to login if not authenticated
     */
    public static function require(): void
    {
        if (!self::check()) {
            Utils::redirect('/login.php');
        }
    }

    /**
     * Require admin role
     */
    public static function requireAdmin(): void
    {
        self::require();
        if (!self::isAdmin()) {
            Utils::redirect('/dashboard.php');
        }
    }

    /**
     * Get event IDs assigned to the current user.
     * Admins get all event IDs; scanners get only their assigned ones.
     */
    public static function getAssignedEventIds(): array
    {
        if (self::isAdmin()) {
            $db = Connection::getInstance();
            $rows = $db->query("SELECT id FROM events ORDER BY created_at DESC");
            return array_column($rows, 'id');
        }

        $userId = self::id();
        if (!$userId) {
            return [];
        }

        $db = Connection::getInstance();
        $rows = $db->query(
            "SELECT event_id FROM event_users WHERE user_id = :user_id",
            ['user_id' => $userId]
        );
        return array_column($rows, 'event_id');
    }

    /**
     * Check if the current user has access to a specific event.
     */
    public static function canAccessEvent(int $eventId): bool
    {
        if (self::isAdmin()) {
            return true;
        }

        $userId = self::id();
        if (!$userId) {
            return false;
        }

        $db = Connection::getInstance();
        $row = $db->queryOne(
            "SELECT id FROM event_users WHERE user_id = :user_id AND event_id = :event_id",
            ['user_id' => $userId, 'event_id' => $eventId]
        );
        return $row !== null;
    }

    /**
     * Hash password
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
