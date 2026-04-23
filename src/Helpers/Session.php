<?php

namespace App\Helpers;

class Session
{
    /**
     * Start session if not already started
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Set session value
     */
    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Get session value
     */
    public static function get(string $key, $default = null)
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Check if session key exists
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * Remove session key
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * Clear all session data
     */
    public static function clear(): void
    {
        self::start();
        session_unset();
    }

    /**
     * Destroy session
     */
    public static function destroy(): void
    {
        self::start();
        session_unset();
        session_destroy();
    }

    /**
     * Set flash message
     */
    public static function flash(string $key, string $message): void
    {
        self::set('flash_' . $key, $message);
    }

    /**
     * Get and remove flash message
     */
    public static function getFlash(string $key): ?string
    {
        $message = self::get('flash_' . $key);
        self::remove('flash_' . $key);
        return $message;
    }

    /**
     * Check if flash message exists
     */
    public static function hasFlash(string $key): bool
    {
        return self::has('flash_' . $key);
    }
}
