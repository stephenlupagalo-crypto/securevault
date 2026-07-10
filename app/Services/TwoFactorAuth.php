<?php
/**
 * TwoFactorAuth
 * ------------------------------------------------------------------
 * Self-contained TOTP (RFC 6238) implementation — no Composer/external
 * library needed, since XAMPP setups often don't have Composer wired up.
 * Also manages backup recovery codes and admin-issued fixed codes.
 */
final class TwoFactorAuth
{
    private const PERIOD = 30;   // seconds per TOTP step
    private const DIGITS = 6;

    // ── Secret generation ───────────────────────────────────────
    public static function generateSecret(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // base32
        $secret = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[ord($bytes[$i]) & 31];
        }
        return $secret;
    }

    public static function provisioningUri(string $secret, string $username, string $issuer = 'SecureVault'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d',
            rawurlencode($issuer), rawurlencode($username), $secret,
            rawurlencode($issuer), self::DIGITS, self::PERIOD
        );
    }

    // ── TOTP verify ──────────────────────────────────────────────
    public static function verifyCode(string $base32Secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;

        $secretKey = self::base32Decode($base32Secret);
        $currentSlice = (int)floor(time() / self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            if (self::calcCode($secretKey, $currentSlice + $i) === $code) {
                return true;
            }
        }
        return false;
    }

    private static function calcCode(string $secretKey, int $timeSlice): string
    {
        $time = str_pad(pack('N', $timeSlice), 8, "\x00", STR_PAD_LEFT);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );
        $modulo = 10 ** self::DIGITS;
        return str_pad((string)($value % $modulo), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(rtrim($b32, '='));
        $bits = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $bytes .= chr(bindec($byte));
        }
        return $bytes;
    }

    // ── Backup / recovery codes ─────────────────────────────────
    /** @return array{plain: string[], hashed: string[]} */
    public static function generateBackupCodes(int $count = 8): array
    {
        $plain = [];
        $hashed = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4))); // e.g. 9F3A2C1B
            $code = substr($code, 0, 4) . '-' . substr($code, 4, 4);
            $plain[] = $code;
            $hashed[] = password_hash($code, PASSWORD_BCRYPT);
        }
        return ['plain' => $plain, 'hashed' => $hashed];
    }

    /**
     * Verifies a backup code against the stored hashed list and, if valid,
     * returns the updated hashed list with that code removed (one-time use).
     * Returns null if the code did not match anything.
     */
    public static function consumeBackupCode(array $hashedCodes, string $submitted): ?array
    {
        foreach ($hashedCodes as $idx => $hash) {
            if (password_verify($submitted, $hash)) {
                unset($hashedCodes[$idx]);
                return array_values($hashedCodes);
            }
        }
        return null;
    }

    // ── Admin-issued fixed verification code ────────────────────
    // Used as an override when a user is locked out (lost phone, SMS
    // not reachable, etc). An admin sets a temporary fixed code with
    // an expiry; the user enters it in place of a TOTP/SMS code.
    public static function hashAdminCode(string $code): string
    {
        return password_hash($code, PASSWORD_BCRYPT);
    }

    public static function verifyAdminCode(?string $hash, ?string $expiresAt, string $submitted): bool
    {
        if (!$hash || !$expiresAt) return false;
        if (strtotime($expiresAt) < time()) return false;
        return password_verify($submitted, $hash);
    }

    /** Generates a random, easy-to-read fixed code for an admin to hand to a user. */
    public static function generateAdminCode(): string
    {
        return (string)random_int(100000, 999999);
    }
}
