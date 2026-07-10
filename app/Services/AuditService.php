<?php
/**
 * AuditService
 * ------------------------------------------------------------------
 * Thin structured wrapper over the existing activity_log table plus
 * the new download_log table, so every sensitive action across the
 * app is recorded consistently (who / what / when / from where).
 */
final class AuditService
{
    public static function log(string $event, string $detail = '', ?int $userId = null): void
    {
        $uid = $userId ?? ($_SESSION['user_id'] ?? null);
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        db()->prepare('INSERT INTO activity_log (user_id, action, detail, ip_address) VALUES (?,?,?,?)')
            ->execute([$uid, $event, $detail, $ip]);
    }

    public static function logDownload(int $fileId, ?int $userId, ?int $shareId = null): void
    {
        db()->prepare(
            'INSERT INTO download_log (file_id, user_id, share_id, ip_address, user_agent) VALUES (?,?,?,?,?)'
        )->execute([
            $fileId,
            $userId,
            $shareId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }

    /** @return array<int, array<string,mixed>> */
    public static function fileHistory(int $fileId, int $limit = 50): array
    {
        $s = db()->prepare(
            'SELECT dl.*, u.username FROM download_log dl
             LEFT JOIN users u ON u.id = dl.user_id
             WHERE dl.file_id = ? ORDER BY dl.created_at DESC LIMIT ?'
        );
        $s->bindValue(1, $fileId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }
}
