<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Чтение прогонов и предложений prx392.
 *
 * Выделено из `Service_Prx392_Analysis`: экран ИИ-разбора и дашборд читают
 * чаще, чем меняют очередь, поэтому запросы живут отдельно от постановки
 * задач и разбора ответа модели. Публичный фасад `Analysis` делегирует сюда.
 */
class Service_Prx392_Runs
{
    public static function latest_for_document($document_id)
    {
        $row = self::_row("
            SELECT s.*, r.profile, r.model, r.prompt_version, r.tokens, r.duration_ms, r.status AS run_status,
                   r.created_at AS run_created_at
            FROM prx_392_suggestions s
            JOIN prx_392_runs r ON r.id = s.run_id
            WHERE s.document_id = ".(int) $document_id."
            ORDER BY s.created_at DESC, s.id DESC LIMIT 1
        ");

        return $row ?: NULL;
    }

    public static function latest_suggestion($document_id)
    {
        $row = self::latest_for_document($document_id);
        if ( ! $row)
        {
            return NULL;
        }
        $row['payload'] = json_decode($row['payload'], TRUE);

        return $row;
    }

    public static function proposals($limit = 50)
    {
        return self::_with_headers(self::_all("
            SELECT s.*, r.profile, r.model, r.tokens, r.duration_ms, r.finished_at
            FROM prx_392_suggestions s
            JOIN prx_392_runs r ON r.id = s.run_id
            WHERE s.status IN ('proposed', 'edited')
            ORDER BY s.created_at DESC, s.id DESC LIMIT ".max(0, (int) $limit)."
        "), TRUE);
    }

    public static function runs_for_document($document_id, $limit = 10)
    {
        return self::_with_headers(self::_all("
            SELECT r.*
            FROM prx_392_runs r
            WHERE r.document_id = ".(int) $document_id."
            ORDER BY r.created_at DESC, r.id DESC LIMIT ".max(0, (int) $limit)."
        "));
    }

    public static function runs($limit = 50)
    {
        return self::_with_headers(self::_all("
            SELECT r.*
            FROM prx_392_runs r
            ORDER BY r.created_at DESC, r.id DESC LIMIT ".max(0, (int) $limit)."
        "));
    }

    /**
     * Доверенное межсервисное чтение (prx391 documents:auto), не UI-read API:
     * подмножество переданных ID, у которых ещё нет ни одного прогона.
     * Пустые/невалидные ID отбрасываются до БД, список ограничен 500;
     * результат по ID, порядок выдачи не является контрактом.
     */
    public static function documents_without_runs(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id)
        {
            $id = (int) $id;
            if ($id > 0) $clean[$id] = TRUE;
        }
        $clean = array_slice(array_keys($clean), 0, 500);
        if ( ! $clean) return [];

        $rows = self::_all(
            "SELECT DISTINCT document_id FROM prx_392_runs
             WHERE document_id IN (".implode(',', $clean).")");
        $with_runs = array_map('intval', array_column($rows, 'document_id'));

        return array_values(array_diff($clean, $with_runs));
    }

    /** Один batch lookup вместо JOIN к чужой таблице или N+1 карточек. */
    private static function _with_headers(array $rows, bool $require_document = FALSE): array
    {
        $headers = Service_Prx391_Documents::headers_for_processing(array_column($rows, 'document_id'));
        $out = [];
        foreach ($rows as $row)
        {
            $header = $headers[(int) $row['document_id']] ?? NULL;
            if ($require_document AND $header === NULL) continue;
            $row['reg_no'] = $header['reg_no'] ?? NULL;
            $row['subject'] = $header['subject'] ?? NULL;
            $out[] = $row;
        }
        return $out;
    }

    public static function run($id)
    {
        return self::_row("SELECT * FROM prx_392_runs WHERE id = ".(int) $id);
    }

    public static function suggestion($id)
    {
        $row = self::_row("
            SELECT s.*, r.profile, r.model, r.prompt_version, r.tokens, r.duration_ms, r.status AS run_status
            FROM prx_392_suggestions s JOIN prx_392_runs r ON r.id = s.run_id
            WHERE s.id = ".(int) $id."
        ");
        if ( ! $row)
        {
            return NULL;
        }
        $row['payload'] = json_decode($row['payload'], TRUE);

        return $row;
    }

    public static function stats()
    {
        $row = self::_row("
            SELECT
                (SELECT count(*) FROM prx_392_runs WHERE created_at::date = current_date) AS today,
                (SELECT count(*) FROM prx_392_runs WHERE status IN ('queued', 'running')) AS active,
                (SELECT count(*) FROM prx_392_runs WHERE status = 'ready') AS ready,
                (SELECT count(*) FROM prx_392_runs WHERE status = 'failed') AS failed,
                (SELECT count(*) FROM prx_392_suggestions WHERE status = 'proposed') AS proposed,
                (SELECT count(*) FROM prx_392_suggestions WHERE status IN ('accepted', 'edited')) AS accepted,
                (SELECT avg(confidence) FROM prx_392_suggestions WHERE created_at > now() - interval '30 days') AS avg_confidence
        ");

        foreach ($row as $k => $v)
        {
            $row[$k] = $k === 'avg_confidence' ? ($v === NULL ? NULL : round((float) $v, 3)) : (int) $v;
        }

        return $row;
    }

    protected static function _row($sql, array $params = [])
    {
        return Prx_Db::one($sql, $params);
    }

    protected static function _all($sql, array $params = [])
    {
        return Prx_Db::select($sql, $params);
    }
}