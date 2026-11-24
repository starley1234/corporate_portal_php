<?php defined('SYSPATH') or die('No direct script access.');

/** Внутреннее чтение/отметка задач для сборщика уведомлений. Схемой владеет prx120. */
final class Service_Prx120_NotificationJobs
{
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** Доверенный серверный контракт: не пользовательский список и не подтверждение доставки. */
    public function pending(int $limit = 40): array
    {
        if ($limit <= 0) return [];
        return $this->db->query(Database::SELECT,
            "SELECT id, channel, status, payload, error, created_by, visitor_id
             FROM prx_120_jobs
             WHERE notified_at IS NULL AND status IN ('done', 'failed')
               AND (created_by IS NOT NULL OR visitor_id LIKE 'u:%')
             ORDER BY id DESC LIMIT ".$limit, FALSE)->as_array();
    }

    /** Повторная отметка не меняет исходный timestamp; чужие статусы/анонимы не отмечаются. */
    public function mark_processed(int $id): bool
    {
        if ($id <= 0) return FALSE;
        $rows = $this->db->query(Database::UPDATE,
            "UPDATE prx_120_jobs SET notified_at = now()
             WHERE id = ".$id." AND notified_at IS NULL AND status IN ('done', 'failed')
               AND (created_by IS NOT NULL OR visitor_id LIKE 'u:%')", FALSE);
        return is_numeric($rows) AND (int) $rows > 0;
    }
}
