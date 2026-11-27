<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Model = «1 метод — 1 запрос, только :param» (канон Д8). Сценарии, транзакции
 * и проверки прав — в Service_Prx399_Contracts / Merge / Pipeline; контроллер
 * в SQL не ходит. Статусные колонки — VARCHAR, булевых нет намеренно: PDO
 * отдаёт BOOLEAN строкой 't'/'f' (см. AGENTS модуля и Model_Prx102_User::is_active).
 */
class Model_Prx399_Contract {

    public static function get($id)
    {
        return Prx_Db::one('SELECT * FROM prx_399_contracts WHERE id = :id', [':id' => (int) $id]);
    }

    public static function list($limit = 50)
    {
        return Prx_Db::select(
            'SELECT * FROM prx_399_contracts ORDER BY id DESC LIMIT :limit',
            [':limit' => max(1, (int) $limit)]);
    }

    public static function insert(array $row)
    {
        return Prx_Db::one(
            'INSERT INTO prx_399_contracts (title, focus, policy, status, created_by)
             VALUES (:title, :focus, :policy::jsonb, :status, :created_by) RETURNING *',
            [
                ':title' => (string) $row['title'],
                ':focus' => (string) $row['focus'],
                ':policy' => (string) $row['policy'],
                ':status' => 'draft',
                ':created_by' => (int) $row['created_by'],
            ]);
    }

    public static function set_status($id, $status, $error = NULL)
    {
        return Prx_Db::execute(
            'UPDATE prx_399_contracts
             SET status = :status, error = :error, updated_at = now()
             WHERE id = :id',
            [
                ':id' => (int) $id,
                ':status' => (string) $status,
                ':error' => $error,
            ]);
    }

    public static function set_deal_card($id, array $card)
    {
        return Prx_Db::execute(
            'UPDATE prx_399_contracts
             SET deal_card = :card::jsonb, updated_at = now() WHERE id = :id',
            [':id' => (int) $id, ':card' => json_encode($card, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)]);
    }

    public static function set_built($id, array $tree, array $broken_links)
    {
        return Prx_Db::execute(
            'UPDATE prx_399_contracts
             SET doc_tree = :tree::jsonb, broken_links = :links::jsonb,
                 status = :status, updated_at = now()
             WHERE id = :id',
            [
                ':id' => (int) $id,
                ':tree' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                ':links' => json_encode($broken_links, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                ':status' => 'built',
            ]);
    }

    public static function set_audited($id, $summary)
    {
        // Статус ставится ЗДЕСЬ же: раньше метод писал только summary и чистил
        // audit_job_id, а пакет навсегда оставался auditing (аудит выполнен,
        // карточка — «идёт»). WHERE по 'auditing' сохраняет идемпотентность
        // повторного POST результата.
        return Prx_Db::execute(
            'UPDATE prx_399_contracts
             SET status = \'audited\', error = NULL, summary = :summary, audit_job_id = NULL, updated_at = now()
             WHERE id = :id AND status = :was',
            [
                ':id' => (int) $id,
                ':summary' => (string) $summary,
                ':was' => 'auditing',
            ]);
    }

    public static function set_audit_job($id, $job_id)
    {
        return Prx_Db::execute(
            'UPDATE prx_399_contracts SET audit_job_id = :job, updated_at = now() WHERE id = :id',
            [':id' => (int) $id, ':job' => (int) $job_id]);
    }

    /**
     * Сериализация сценариев одного пакета: advisory-лок (399, id) до конца
     * транзакции. Сборка, запуск, повтор файла и приём аудита берут его —
     * двойной клик не ставит задачи дважды, устаревший результат не пишет
     * поверх нового цикла.
     */
    public static function lock($id)
    {
        return Prx_Db::execute('SELECT pg_advisory_xact_lock(399, :id)', [':id' => (int) $id]);
    }

    /** Пакеты с идущим пайплайном — для самолечения по расписанию (prx399:maintain). */
    public static function active_ids($limit = 200)
    {
        return array_map('intval', array_column(Prx_Db::select(
            "SELECT id FROM prx_399_contracts WHERE status IN ('parsing', 'auditing') ORDER BY updated_at LIMIT :limit",
            [':limit' => max(1, (int) $limit)]), 'id'));
    }

    public static function delete($id)
    {
        return Prx_Db::execute('DELETE FROM prx_399_contracts WHERE id = :id', [':id' => (int) $id]);
    }

    public static function counts_by_status()
    {
        return Prx_Db::one(
            "SELECT count(*) AS total,
                    count(*) FILTER (WHERE status = 'audited') AS audited,
                    count(*) FILTER (WHERE status IN ('parsing', 'auditing')) AS running
             FROM prx_399_contracts");
    }
}
