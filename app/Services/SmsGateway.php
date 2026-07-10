<?php
/**
 * SmsGateway
 * ------------------------------------------------------------------
 * Thin wrapper around the Africa's Talking SMS API.
 * Configure your credentials in includes/config.php:
 *
 *   define('AT_USERNAME', 'your_at_username');   // 'sandbox' for testing
 *   define('AT_API_KEY',  'your_at_api_key');
 *   define('AT_SENDER_ID', '');                  // optional shortcode/sender id
 *
 * Get credentials at https://africastalking.com — the sandbox app
 * (username "sandbox") lets you test for free against simulator
 * numbers before going live.
 */
final class SmsGateway
{
    private const LIVE_URL    = 'https://api.africastalking.com/version1/messaging';
    private const SANDBOX_URL = 'https://api.sandbox.africastalking.com/version1/messaging';

    public static function send(string $phoneE164, string $message): bool
    {
        if (!defined('AT_USERNAME') || !defined('AT_API_KEY') || AT_API_KEY === '') {
            // Not configured yet — fail safe (log instead of throwing) so the
            // rest of the app keeps working while credentials are pending.
            log_activity('sms_not_configured', "Would have sent SMS to $phoneE164: $message");
            return false;
        }

        $url = (AT_USERNAME === 'sandbox') ? self::SANDBOX_URL : self::LIVE_URL;

        $fields = [
            'username' => AT_USERNAME,
            'to'       => $phoneE164,
            'message'  => $message,
        ];
        if (defined('AT_SENDER_ID') && AT_SENDER_ID !== '') {
            $fields['from'] = AT_SENDER_ID;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'apiKey: ' . AT_API_KEY,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $httpCode >= 300) {
            log_activity('sms_send_failed', "To $phoneE164, HTTP $httpCode, curl_errno $errno");
            return false;
        }

        log_activity('sms_sent', "OTP SMS sent to $phoneE164");
        return true;
    }

    /** Generates and sends a numeric one-time code, storing its hash for verification. */
    public static function sendOtp(int $userId, string $phoneE164): bool
    {
        $code = (string)random_int(100000, 999999);
        $hash = password_hash($code, PASSWORD_BCRYPT);
        $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        db()->prepare('INSERT INTO two_factor_otp (user_id, code_hash, expires_at) VALUES (?,?,?)')
            ->execute([$userId, $hash, $expires]);

        return self::send($phoneE164, "Your SecureVault verification code is $code. It expires in 5 minutes.");
    }

    public static function verifyOtp(int $userId, string $submitted): bool
    {
        $s = db()->prepare(
            'SELECT * FROM two_factor_otp WHERE user_id=? AND used=0 AND expires_at > NOW() ORDER BY id DESC LIMIT 5'
        );
        $s->execute([$userId]);
        foreach ($s->fetchAll() as $row) {
            if (password_verify($submitted, $row['code_hash'])) {
                db()->prepare('UPDATE two_factor_otp SET used=1 WHERE id=?')->execute([$row['id']]);
                return true;
            }
        }
        return false;
    }
}
