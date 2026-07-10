<?php
/**
 * SessionManager
 * ------------------------------------------------------------------
 * Centralises session hardening: secure cookie params, fixation
 * protection (regenerate on privilege change), idle + absolute
 * timeouts, and a single start() entrypoint used by every page
 * instead of a bare session_start().
 */
final class SessionManager
{
    private const IDLE_TIMEOUT_SECONDS     = 20 * 60;   // 20 min inactivity
    private const ABSOLUTE_TIMEOUT_SECONDS = 8 * 60 * 60; // 8 hr hard cap

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => BASE_PATH,
            'domain'   => '',
            'secure'   => $secure,   // set true automatically once served over HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();

        self::enforceTimeouts();
    }

    private static function enforceTimeouts(): void
    {
        $now = time();

        if (isset($_SESSION['user_id'])) {
            if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > self::IDLE_TIMEOUT_SECONDS) {
                self::expire('Your session expired due to inactivity. Please sign in again.');
            }
            if (isset($_SESSION['login_time']) && ($now - $_SESSION['login_time']) > self::ABSOLUTE_TIMEOUT_SECONDS) {
                self::expire('Your session reached its maximum length. Please sign in again.');
            }
        }
        $_SESSION['last_activity'] = $now;
    }

    private static function expire(string $reason): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        session_start();
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => $reason];
        header('Location: ' . BASE_PATH . 'login.php');
        exit;
    }

    /** Call right after a successful password check, before setting session data. */
    public static function regenerateOnLogin(): void
    {
        session_regenerate_id(true);
        $_SESSION['login_time']    = time();
        $_SESSION['last_activity'] = time();
    }
}
