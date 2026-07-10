<?php
// ============================================================
//  SecureVault – Configuration
//  Edit the values in the "EDIT THESE FOR YOUR HOST" block below
//  to match your InfinityFree (or other) hosting account.
// ============================================================

// Never show raw PHP errors/stack traces to visitors (they can leak
// file paths, DB structure, etc.) — but still log them so you can
// debug. Set SHOW_ERRORS true only temporarily while troubleshooting.
define('SHOW_ERRORS', false);
ini_set('display_errors', SHOW_ERRORS ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Fix for reverse proxies (Railway, Koyeb, Cloudflare) - tells PHP to trust HTTPS from the proxy
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// ── EDIT THESE FOR YOUR HOST ─────────────────────────────────
// InfinityFree does not support environment variables on the free
// tier, so these fall back to hardcoded values. Get the DB values
// from vPanel → MySQL Databases after you create a database there.
define('DB_HOST', getenv('MYSQLHOST') ?: 'sqlXXX.infinityfree.com');   // vPanel → MySQL Databases
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'epiz_XXXXXXXX_securevault');
define('DB_USER', getenv('MYSQLUSER') ?: 'epiz_XXXXXXXX');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'your-db-password');

// Base path of the app relative to the domain root.
// '/' if uploaded straight into htdocs (recommended on InfinityFree).
// '/securevault/' if installed in a subfolder instead.
define('BASE_PATH', '/');
// ──────────────────────────────────────────────────────────────

// Encryption key – MUST be exactly 32 bytes (256-bit).
// Generate a strong one and keep it secret (never commit the real
// value to a public GitHub repo).
define('ENCRYPTION_KEY', 'SecureVault32ByteKeyChangeMe!!X');

// Upload directory (absolute path on disk, NOT web-accessible)
define('UPLOAD_DIR', __DIR__ . '/../uploads/');

// Max single upload size (bytes) – also set in php.ini.
// InfinityFree's free tier hard-caps uploads at ~10MB regardless of
// what you set here or in .htaccess, so we match that by default.
// Raise this only if you've confirmed a higher limit works on your plan.
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 MB

// Allowed MIME types
define('ALLOWED_TYPES', [
    // Documents
    'application/pdf', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv',
    // Images
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    // Audio
    'audio/mpeg', 'audio/wav', 'audio/ogg',
    // Video
    'video/mp4', 'video/webm', 'video/ogg',
    // Archives
    'application/zip', 'application/x-rar-compressed',
    'application/x-7z-compressed', 'application/gzip',
]);

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');

// Timezone
date_default_timezone_set('Africa/Dar_es_Salaam'); // Tanzania (EAT, UTC+3)

// ── Meseji SMS gateway (used for SMS-based 2FA) ──────────────
// Get credentials at https://meseji.co.tz
// SECURITY NOTE: rotate/regenerate this API key before going live —
// a real key was hardcoded here previously and has now been shared
// in chat/uploaded files, so treat it as compromised. Never commit
// real keys to a public GitHub repo.
define('AT_USERNAME',  'LUPAGALO');   // change to your live username in production
define('AT_API_KEY',   'PASTE_YOUR_ROTATED_MESEJI_API_KEY_HERE');
define('AT_SENDER_ID', 'MESEJI');         // optional short code / alphanumeric sender id

// ── Brevo (formerly Sendinblue) transactional email API ─────
// InfinityFree blocks outbound SMTP (ports 25/465/587) on the free
// tier, so PHPMailer-over-SMTP will NOT work there. Brevo's HTTPS
// API works fine because it's a normal cURL call over port 443 —
// the same pattern already used for Meseji SMS above.
// 1. Create a free account at https://www.brevo.com (300 emails/day free)
// 2. Verify a sender email/domain under Senders & IP
// 3. Get your API key under SMTP & API → API Keys
define('BREVO_API_KEY',    'PASTE_YOUR_BREVO_API_KEY_HERE');
define('MAIL_FROM_EMAIL',  'no-reply@yourdomain.com'); // must be a verified Brevo sender
define('MAIL_FROM_NAME',   'SecureVault');

// ── App autoloader (MVC-lite: Core / Models / Services) ─────
spl_autoload_register(function (string $class): void {
    $dirs = ['Core', 'Models', 'Services'];
    foreach ($dirs as $dir) {
        $path = __DIR__ . "/../app/{$dir}/{$class}.php";
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});

// ── PDO connection ──────────────────────────────────────────
define('DB_CHARSET', 'UTF8MB4');
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ── Helpers ─────────────────────────────────────────────────
function redirect(string $path): never {
    header('Location: ' . $path);
    exit;
}

function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_login(): void {
    if (!logged_in()) redirect(BASE_PATH . 'login.php');
}

function require_role(string $role): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== $role) {
        http_response_code(403);
        die('Access denied: this page requires the "' . e($role) . '" role.');
    }
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('CSRF validation failed.');
    }
}

function log_activity(string $action, string $detail = ''): void {
    try {
        $uid = $_SESSION['user_id'] ?? null;
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        db()->prepare('INSERT INTO activity_log (user_id,action,detail,ip_address) VALUES (?,?,?,?)')
             ->execute([$uid, $action, $detail, $ip]);
    } catch (Throwable) {}
}

/**
 * Sends a transactional email via Brevo's HTTPS API (cURL, port 443).
 * We use an HTTP API instead of SMTP because InfinityFree's free tier
 * blocks outbound SMTP connections entirely — PHPMailer over SMTP would
 * throw "SMTP connect() failed" there even with correct credentials.
 */
function send_email(string $toEmail, string $subject, string $htmlBody, string $toName = ''): bool {
    if (!defined('BREVO_API_KEY') || BREVO_API_KEY === '' || str_starts_with(BREVO_API_KEY, 'PASTE_')) {
        log_activity('email_not_configured', "Would have emailed $toEmail: $subject");
        return false;
    }

    $payload = [
        'sender'      => ['name' => MAIL_FROM_NAME, 'email' => MAIL_FROM_EMAIL],
        'to'          => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject'     => $subject,
        'htmlContent' => $htmlBody,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . BREVO_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $httpCode >= 300) {
        log_activity('email_send_failed', "To $toEmail, HTTP $httpCode, curl_errno $errno, resp: " . substr((string)$response, 0, 200));
        return false;
    }

    log_activity('email_sent', "To $toEmail: $subject");
    return true;
}

function add_alert(int $user_id, string $type, string $title, string $message): void {
    db()->prepare('INSERT INTO alerts (user_id,type,title,message) VALUES (?,?,?,?)')
         ->execute([$user_id, $type, $title, $message]);
}

function unread_alerts(int $user_id): int {
    $s = db()->prepare('SELECT COUNT(*) FROM alerts WHERE user_id=? AND is_read=0');
    $s->execute([$user_id]);
    return (int)$s->fetchColumn();
}

function file_category(string $mime): string {
    if (str_starts_with($mime, 'image/'))             return 'image';
    if (str_starts_with($mime, 'audio/'))             return 'audio';
    if (str_starts_with($mime, 'video/'))             return 'video';
    if (in_array($mime, ['application/zip','application/x-rar-compressed',
                          'application/x-7z-compressed','application/gzip'])) return 'archive';
    if (str_starts_with($mime, 'text/') ||
        str_contains($mime, 'pdf') || str_contains($mime, 'word') ||
        str_contains($mime, 'excel') || str_contains($mime, 'powerpoint')) return 'document';
    return 'other';
}

function format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes/1073741824,2).' GB';
    if ($bytes >= 1048576)    return round($bytes/1048576,2).' MB';
    if ($bytes >= 1024)       return round($bytes/1024,2).' KB';
    return $bytes.' B';
}

// ── AES-256-GCM helpers ─────────────────────────────────────
function encrypt_file(string $plaintext): array {
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Encryption failed');
    return [
        'cipher' => $cipher,
        'iv'     => bin2hex($iv),
        'tag'    => bin2hex($tag),
    ];
}

function decrypt_file(string $cipher, string $iv_hex, string $tag_hex): string {
    $plain = openssl_decrypt(
        $cipher, 'aes-256-gcm', ENCRYPTION_KEY,
        OPENSSL_RAW_DATA, hex2bin($iv_hex), hex2bin($tag_hex)
    );
    if ($plain === false) throw new RuntimeException('Decryption failed – file may be corrupted.');
    return $plain;
}
