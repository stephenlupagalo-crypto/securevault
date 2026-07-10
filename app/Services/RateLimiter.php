<?php
/**
 * RateLimiter
 * ------------------------------------------------------------------
 * Tracks login/registration attempts per identifier (username/email)
 * AND per IP address, and enforces temporary lockouts to slow down
 * brute-force / credential-stuffing attacks.
 *
 * Strategy:
 *   - Every attempt (success or failure) is recorded in login_attempts.
 *   - A window of the last N minutes is checked for failed attempts.
 *   - After MAX_ATTEMPTS failures (per identifier OR per IP), further
 *     attempts are blocked for a cooldown period (exponential-ish).
 *   - On successful login the user's failed_attempts/locked_until in
 *     the `users` table are cleared.
 */
final class RateLimiter
{
    // Tunables
    private const MAX_ATTEMPTS      = 5;     // failed attempts allowed
    private const WINDOW_MINUTES    = 15;    // window to count failures in
    private const BASE_LOCK_MINUTES = 15;    // lockout length after limit hit

    public static function record(string $identifier, string $ip, bool $success): void
    {
        db()->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?,?,?)'
        )->execute([$identifier, $ip, $success ? 1 : 0]);
    }

    /**
     * Returns null if the request is allowed, or a human-readable
     * message explaining why it is blocked.
     */
    public static function check(string $identifier, string $ip): ?string
    {
        // Account-level lock (set on the users row for persistence across identifiers)
        $u = db()->prepare('SELECT locked_until FROM users WHERE username=? OR email=? LIMIT 1');
        $u->execute([$identifier, $identifier]);
        $row = $u->fetch();
        if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
            $mins = (int)ceil((strtotime($row['locked_until']) - time()) / 60);
            return "Too many failed attempts. Account locked for another {$mins} minute(s).";
        }

        $since = date('Y-m-d H:i:s', strtotime('-' . self::WINDOW_MINUTES . ' minutes'));

        $byId = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier=? AND success=0 AND attempted_at > ?'
        );
        $byId->execute([$identifier, $since]);
        $failCountId = (int)$byId->fetchColumn();

        $byIp = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address=? AND success=0 AND attempted_at > ?'
        );
        $byIp->execute([$ip, $since]);
        $failCountIp = (int)$byIp->fetchColumn();

        if ($failCountId >= self::MAX_ATTEMPTS || $failCountIp >= (self::MAX_ATTEMPTS * 3)) {
            self::lockAccount($identifier);
            return 'Too many failed attempts. Please try again in ' . self::BASE_LOCK_MINUTES . ' minutes, or reset your password.';
        }

        return null; // allowed
    }

    public static function remainingAttempts(string $identifier): int
    {
        $since = date('Y-m-d H:i:s', strtotime('-' . self::WINDOW_MINUTES . ' minutes'));
        $s = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier=? AND success=0 AND attempted_at > ?'
        );
        $s->execute([$identifier, $since]);
        return max(0, self::MAX_ATTEMPTS - (int)$s->fetchColumn());
    }

    private static function lockAccount(string $identifier): void
    {
        $until = date('Y-m-d H:i:s', strtotime('+' . self::BASE_LOCK_MINUTES . ' minutes'));
        db()->prepare(
            'UPDATE users SET locked_until=?, failed_attempts=failed_attempts+1 WHERE username=? OR email=?'
        )->execute([$until, $identifier, $identifier]);
    }

    public static function clearLock(string $identifier): void
    {
        db()->prepare(
            'UPDATE users SET locked_until=NULL, failed_attempts=0 WHERE username=? OR email=?'
        )->execute([$identifier, $identifier]);
    }

    /** Simple IP-only limiter for the registration endpoint. */
    public static function checkRegistration(string $ip): ?string
    {
        $since = date('Y-m-d H:i:s', strtotime('-60 minutes'));
        $s = db()->prepare(
            "SELECT COUNT(*) FROM login_attempts WHERE ip_address=? AND identifier='__register__' AND attempted_at > ?"
        );
        $s->execute([$ip, $since]);
        if ((int)$s->fetchColumn() >= 6) {
            return 'Too many registration attempts from this network. Please try again later.';
        }
        return null;
    }

    public static function recordRegistration(string $ip): void
    {
        db()->prepare(
            "INSERT INTO login_attempts (identifier, ip_address, success) VALUES ('__register__', ?, 1)"
        )->execute([$ip]);
    }
}
