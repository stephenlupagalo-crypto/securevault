<?php
final class ShareLink
{
    /**
     * Creates a new share link and returns [id, plainToken].
     * The plain token is only available here — store it in the URL,
     * never persisted anywhere in reversible form.
     */
    public static function create(
        int $fileId,
        int $userId,
        ?string $password = null,
        ?string $expiresAt = null,
        ?int $maxDownloads = null
    ): array {
        $token     = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);
        $pwHash    = $password !== null && $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;

        db()->prepare(
            'INSERT INTO share_links (file_id, user_id, token_hash, password_hash, expires_at, max_downloads)
             VALUES (?,?,?,?,?,?)'
        )->execute([$fileId, $userId, $tokenHash, $pwHash, $expiresAt ?: null, $maxDownloads ?: null]);

        return ['id' => (int)db()->lastInsertId(), 'token' => $token];
    }

    public static function findByToken(string $token): ?array
    {
        $hash = hash('sha256', $token);
        $s = db()->prepare(
            'SELECT sl.*, f.original_name, f.file_size, f.mime_type, f.stored_name, f.encryption_iv, f.encryption_tag, f.is_deleted
             FROM share_links sl JOIN files f ON f.id = sl.file_id
             WHERE sl.token_hash = ? LIMIT 1'
        );
        $s->execute([$hash]);
        $row = $s->fetch();
        return $row ?: null;
    }

    public static function isValid(array $share): bool
    {
        if ((int)$share['revoked'] === 1) return false;
        if ((int)$share['is_deleted'] === 1) return false;
        if ($share['expires_at'] && strtotime($share['expires_at']) < time()) return false;
        if ($share['max_downloads'] !== null && (int)$share['download_count'] >= (int)$share['max_downloads']) return false;
        return true;
    }

    public static function checkPassword(array $share, ?string $submitted): bool
    {
        if (!$share['password_hash']) return true; // no password required
        return $submitted !== null && password_verify($submitted, $share['password_hash']);
    }

    public static function registerDownload(int $shareId): void
    {
        db()->prepare('UPDATE share_links SET download_count = download_count + 1 WHERE id=?')->execute([$shareId]);
    }

    public static function forFile(int $fileId, int $userId): array
    {
        $s = db()->prepare('SELECT * FROM share_links WHERE file_id=? AND user_id=? ORDER BY created_at DESC');
        $s->execute([$fileId, $userId]);
        return $s->fetchAll();
    }

    public static function revoke(int $id, int $userId): void
    {
        db()->prepare('UPDATE share_links SET revoked=1 WHERE id=? AND user_id=?')->execute([$id, $userId]);
    }
}
