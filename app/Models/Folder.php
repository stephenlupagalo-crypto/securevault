<?php
final class Folder
{
    public static function forUser(int $userId): array
    {
        $s = db()->prepare('SELECT * FROM folders WHERE user_id=? ORDER BY name ASC');
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    public static function find(int $id, int $userId): ?array
    {
        $s = db()->prepare('SELECT * FROM folders WHERE id=? AND user_id=?');
        $s->execute([$id, $userId]);
        $row = $s->fetch();
        return $row ?: null;
    }

    public static function create(int $userId, string $name, ?int $parentId = null): int
    {
        db()->prepare('INSERT INTO folders (user_id, name, parent_id) VALUES (?,?,?)')
            ->execute([$userId, $name, $parentId]);
        return (int)db()->lastInsertId();
    }

    public static function rename(int $id, int $userId, string $name): void
    {
        db()->prepare('UPDATE folders SET name=? WHERE id=? AND user_id=?')->execute([$name, $id, $userId]);
    }

    /** Deletes the folder; files inside are NOT deleted, just unfiled (folder_id -> NULL via FK ON DELETE SET NULL). */
    public static function delete(int $id, int $userId): void
    {
        db()->prepare('DELETE FROM folders WHERE id=? AND user_id=?')->execute([$id, $userId]);
    }

    public static function fileCount(int $id): int
    {
        $s = db()->prepare('SELECT COUNT(*) FROM files WHERE folder_id=? AND is_deleted=0');
        $s->execute([$id]);
        return (int)$s->fetchColumn();
    }
}
