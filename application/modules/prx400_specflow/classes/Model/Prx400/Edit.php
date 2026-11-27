<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_edits — журнал действий оператора по строкам (только вставка и
 * чтение). field — имя поля или действие: code (код НСИ), verified,
 * row_add, row_delete, split, bulk_verify. actor_id — подписанный (UI < 0,
 * API-ключ > 0). Удалённая строка оставляет запись (row_id → NULL, row_label).
 */
class Model_Prx400_Edit {

    public static function add(?int $row_id, int $spec_id, string $field, ?string $old, ?string $new, int $actor_id, string $label = ''): int
    {
        return Prx_Db::execute(
            'INSERT INTO prx_400_edits (row_id, spec_id, field, old_value, new_value, actor_id, row_label, created_at)
             VALUES (:r, :spec, :f, :o, :n, :a, :l, now())',
            [':r' => $row_id, ':spec' => $spec_id, ':f' => mb_substr($field, 0, 32), ':o' => $old, ':n' => $new,
             ':a' => $actor_id, ':l' => mb_substr($label, 0, 80)]);
    }

    public static function for_spec(int $spec_id, int $limit = 200): array
    {
        return Prx_Db::select(
            'SELECT e.*, r.seq, r.pos, r.pos_path FROM prx_400_edits e LEFT JOIN prx_400_rows r ON r.id = e.row_id
              WHERE e.spec_id = :spec ORDER BY e.id DESC LIMIT '.max(1, $limit),
            [':spec' => $spec_id]);
    }

    /**
     * Аналитика журнала за N дней (prx400:eval --journal): какие поля правят,
     * как часто меняют код НСИ после автопривязки.
     */
    public static function analytics(int $days): array
    {
        $params = [':d' => max(1, $days)];
        $since = 'e.created_at >= now() - (CAST(:d AS int) * interval \'1 day\')';

        return [
            'fields' => Prx_Db::select(
                'SELECT e.field, count(*) AS n, count(DISTINCT e.spec_id) AS specs FROM prx_400_edits e
                  WHERE '.$since.' GROUP BY e.field ORDER BY n DESC, e.field', $params),
            'code_changes' => Prx_Db::select(
                'SELECT coalesce(e.old_value, \'\') AS old_code, coalesce(e.new_value, \'\') AS new_code, count(*) AS n
                   FROM prx_400_edits e WHERE '.$since.' AND e.field = \'code\'
                  GROUP BY 1, 2 ORDER BY n DESC LIMIT 20', $params),
            'specs' => (int) (Prx_Db::one('SELECT count(DISTINCT e.spec_id) AS c FROM prx_400_edits e WHERE '.$since, $params)['c'] ?? 0),
        ];
    }

    /**
     * Качество разбора в бою за $days дней (prx400:eval): исходы спецификаций
     * и происхождение кода у строк разобранных спецификаций. Ручная правка
     * после автопривязки — главный сигнал ошибок матчинга.
     */
    public static function quality(int $days): array
    {
        $params = [':d' => max(1, $days)];
        $since = 's.created_at >= now() - (CAST(:d AS int) * interval \'1 day\')';
        $specs = Prx_Db::select(
            'SELECT s.status, coalesce(s.error, \'\') AS error, count(*) AS n FROM prx_400_specs s
              WHERE '.$since.' GROUP BY 1, 2 ORDER BY n DESC', $params);
        $rows = Prx_Db::one(
            'SELECT count(*) AS total,
                    count(*) FILTER (WHERE r.verified) AS verified,
                    count(*) FILTER (WHERE r.origin <> \'parsed\') AS added_by_operator,
                    count(*) FILTER (WHERE coalesce(r.match->>\'code\', \'\') = \'\') AS without_code,
                    count(*) FILTER (WHERE r.match->>\'via\' = \'code\') AS via_code,
                    count(*) FILTER (WHERE r.match->>\'via\' = \'memory\') AS via_memory,
                    count(*) FILTER (WHERE r.match->>\'via\' = \'fuzzy\') AS via_fuzzy,
                    count(*) FILTER (WHERE r.match->>\'via\' = \'manual\') AS via_manual,
                    count(*) FILTER (WHERE r.status = \'ok\' AND r.match->>\'via\' <> \'manual\') AS auto_ok,
                    count(*) FILTER (WHERE r.status = \'error\') AS math_errors
               FROM prx_400_rows r JOIN prx_400_specs s ON s.id = r.spec_id
              WHERE '.$since, $params) ?? [];
        $overridden = Prx_Db::one(
            'SELECT count(DISTINCT e.row_id) AS n FROM prx_400_edits e JOIN prx_400_specs s ON s.id = e.spec_id
              WHERE '.$since.' AND e.field = \'code\' AND coalesce(e.old_value, \'\') <> \'\'', $params);

        return [
            'specs' => array_map(static fn (array $r): array => ['status' => (string) $r['status'], 'error' => (string) $r['error'], 'n' => (int) $r['n']], $specs),
            'rows' => array_map('intval', $rows),
            'auto_code_overridden' => (int) ($overridden['n'] ?? 0),
        ];
    }
}
