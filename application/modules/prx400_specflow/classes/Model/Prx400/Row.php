<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_rows: SQL таблицы позиций. Транзакции открывает сервис
 * (Prx_Db::transaction) — модель их не вкладывает.
 *
 * Дерево BOM: parent_id/level/pos_path; источник — file_no/page; origin —
 * parsed (воркер), manual (добавил человек), split (часть разделённой строки).
 * verified_by — подписанный ID (UI < 0, API-ключ > 0), как owner_id.
 */
class Model_Prx400_Row {

    /** Разрешённые к правке поля (белый список — имена колонок не из ввода). */
    public const EDITABLE = ['raw_name', 'code_doc', 'qty', 'unit', 'unit_price', 'total_price', 'section'];

    /**
     * Заменить позиции спецификации (вызывать внутри транзакции сервиса).
     * parent_seq — индекс родителя в $rows (Pipeline::link_tree).
     */
    public static function replace_for_spec(int $spec_id, array $rows): int
    {
        Prx_Db::execute('DELETE FROM prx_400_rows WHERE spec_id = :spec', [':spec' => $spec_id]);
        $ids = [];
        foreach (array_values($rows) as $i => $r)
        {
            $parent = isset($r['parent_seq']) && isset($ids[$r['parent_seq']]) ? $ids[$r['parent_seq']] : NULL;
            $ids[$i] = self::insert($spec_id, ['seq' => $i] + $r, $parent);
        }

        return count($rows);
    }

    /** Одна строка; возвращает id. */
    public static function insert(int $spec_id, array $r, ?int $parent_id): int
    {
        $row = Prx_Db::one(
            'INSERT INTO prx_400_rows
                (spec_id, seq, section, pos, raw_name, code_doc, qty, unit,
                 unit_price, total_price, flags, match, status,
                 parent_id, level, pos_path, file_no, page, origin,
                 verified, verified_by, verified_at)
             VALUES (:spec, :seq, :section, :pos, :name, :code, :qty, :unit,
                 :price, :total, CAST(:flags AS jsonb), CAST(:match AS jsonb), :status,
                 :parent, :level, :path, :file_no, :page, :origin,
                 :verified, :verified_by, CAST(:verified_at AS timestamptz))
             RETURNING id',
            [
                ':spec' => $spec_id,
                ':seq' => (int) $r['seq'],
                ':section' => (string) ($r['section'] ?? ''),
                ':pos' => $r['pos'] ?? NULL,
                ':name' => (string) $r['raw_name'],
                ':code' => (string) ($r['code_doc'] ?? ''),
                ':qty' => $r['qty'] ?? NULL,
                ':unit' => (string) ($r['unit'] ?? ''),
                ':price' => $r['unit_price'] ?? NULL,
                ':total' => $r['total_price'] ?? NULL,
                ':flags' => json_encode(array_values((array) ($r['flags'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':match' => json_encode($r['match'] ?? NULL, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':status' => (string) $r['status'],
                ':parent' => $parent_id,
                ':level' => (int) ($r['level'] ?? 0),
                ':path' => (string) ($r['pos_path'] ?? ''),
                ':file_no' => max(1, (int) ($r['file_no'] ?? 1)),
                ':page' => isset($r['page']) ? (int) $r['page'] : NULL,
                ':origin' => (string) ($r['origin'] ?? 'parsed'),
                ':verified' => ! empty($r['verified']),
                ':verified_by' => ! empty($r['verified']) && isset($r['verified_by']) ? (int) $r['verified_by'] : NULL,
                ':verified_at' => ! empty($r['verified']) ? (string) ($r['verified_at'] ?? date('c')) : NULL,
            ]);
        if ( ! is_array($row)) throw new RuntimeException('prx400: INSERT row без RETURNING');

        return (int) $row['id'];
    }

    /** Освободить место после позиции $after (seq > after сдвигаются на $n). */
    public static function shift(int $spec_id, int $after, int $n = 1): int
    {
        return Prx_Db::execute('UPDATE prx_400_rows SET seq = seq + :n WHERE spec_id = :spec AND seq > :after',
            [':spec' => $spec_id, ':after' => $after, ':n' => $n]);
    }

    /** Позиции спецификации; JSONB и boolean приведены к PHP-типам. */
    public static function for_spec(int $spec_id): array
    {
        return array_map([self::class, 'decode'], Prx_Db::select(
            'SELECT * FROM prx_400_rows WHERE spec_id = :spec ORDER BY seq ASC, id ASC',
            [':spec' => $spec_id]));
    }

    public static function get(int $id): ?array
    {
        $row = Prx_Db::one('SELECT * FROM prx_400_rows WHERE id = :id', [':id' => $id]);

        return $row !== NULL ? self::decode($row) : NULL;
    }

    /** Строка под блокировкой (внутри транзакции сервиса). */
    public static function lock(int $id): ?array
    {
        $row = Prx_Db::one('SELECT * FROM prx_400_rows WHERE id = :id FOR UPDATE', [':id' => $id]);

        return $row !== NULL ? self::decode($row) : NULL;
    }

    /** Все строки спецификации под блокировкой (пересопоставление пачкой). */
    public static function lock_for_spec(int $spec_id): array
    {
        return array_map([self::class, 'decode'], Prx_Db::select(
            'SELECT * FROM prx_400_rows WHERE spec_id = :spec ORDER BY seq ASC, id ASC FOR UPDATE',
            [':spec' => $spec_id]));
    }

    public static function children_count(int $id): int
    {
        $row = Prx_Db::one('SELECT count(*) AS c FROM prx_400_rows WHERE parent_id = :id', [':id' => $id]);

        return (int) ($row['c'] ?? 0);
    }

    public static function delete(int $id): int
    {
        return Prx_Db::execute('DELETE FROM prx_400_rows WHERE id = :id', [':id' => $id]);
    }

    /** Поле из белого списка EDITABLE. */
    public static function set_field(int $id, string $field, $value): int
    {
        if ( ! in_array($field, self::EDITABLE, TRUE)) throw new InvalidArgumentException('prx400: поле не из белого списка');

        return Prx_Db::execute(
            'UPDATE prx_400_rows SET '.Prx_Db::identifier($field).' = :v WHERE id = :id',
            [':id' => $id, ':v' => $value]);
    }

    /** Флаги, сопоставление и статус после пересчёта сервисом. */
    public static function set_assessment(int $id, array $flags, ?array $match, string $status): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_rows SET flags = CAST(:f AS jsonb), match = CAST(:m AS jsonb), status = :s WHERE id = :id',
            [
                ':id' => $id,
                ':f' => json_encode(array_values($flags), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':m' => json_encode($match, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':s' => $status,
            ]);
    }

    public static function set_verified(int $id, int $actor_id, bool $verified): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_rows
                SET verified = :v, verified_by = :by,
                    verified_at = CASE WHEN :v THEN now() ELSE NULL END
              WHERE id = :id',
            [':id' => $id, ':v' => $verified, ':by' => $verified ? $actor_id : NULL]);
    }

    /** Ручное сопоставление НСИ: код зафиксирован и проверен. */
    public static function set_manual_match(int $id, int $actor_id, array $match, array $flags, string $status): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_rows
                SET match = CAST(:match AS jsonb), flags = CAST(:flags AS jsonb), status = :status,
                    verified = TRUE, verified_by = :by, verified_at = now()
              WHERE id = :id',
            [
                ':id' => $id,
                ':match' => json_encode($match, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':flags' => json_encode(array_values($flags), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':status' => $status,
                ':by' => $actor_id,
            ]);
    }

    /**
     * Массовое подтверждение: строки с кодом НСИ, скором ≥ порога и без ошибки
     * арифметики, ещё не подтверждённые. Возвращает их id (для журнала).
     */
    public static function verify_confident(int $spec_id, float $min_score, int $actor_id): array
    {
        return array_map('intval', array_column(Prx_Db::select(
            'UPDATE prx_400_rows
                SET verified = TRUE, verified_by = :by, verified_at = now()
              WHERE spec_id = :spec AND NOT verified AND status <> \'error\'
                AND coalesce(match->>\'code\', \'\') <> \'\'
                AND CAST(coalesce(match->>\'score\', \'0\') AS numeric) >= CAST(:min AS numeric)
              RETURNING id',
            [':spec' => $spec_id, ':min' => $min_score, ':by' => $actor_id]), 'id'));
    }

    /** Строки, требующие решения оператора (не ok и не подтверждены). */
    public static function pending_count(int $spec_id): int
    {
        $row = Prx_Db::one(
            'SELECT count(*) AS c FROM prx_400_rows WHERE spec_id = :spec AND status <> \'ok\' AND NOT verified',
            [':spec' => $spec_id]);

        return (int) ($row['c'] ?? 0);
    }

    /** Счётчики по статусам для карточки/API: ok/review/new/error/verified/total. */
    public static function counts(int $spec_id): array
    {
        $row = Prx_Db::one(
            'SELECT count(*) AS total,
                    count(*) FILTER (WHERE verified) AS verified,
                    count(*) FILTER (WHERE status = \'ok\') AS ok,
                    count(*) FILTER (WHERE status = \'review\') AS review,
                    count(*) FILTER (WHERE status = \'new\') AS new,
                    count(*) FILTER (WHERE status = \'error\') AS error,
                    count(*) FILTER (WHERE status <> \'ok\' AND NOT verified) AS pending
               FROM prx_400_rows WHERE spec_id = :spec',
            [':spec' => $spec_id]) ?? [];

        return array_map('intval', $row);
    }

    private static function decode(array $r): array
    {
        foreach (['flags', 'match'] as $k)
        {
            if (isset($r[$k]) AND is_string($r[$k]))
            {
                $r[$k] = json_decode($r[$k], TRUE);
            }
        }
        $r['flags'] = is_array($r['flags'] ?? NULL) ? $r['flags'] : [];
        $r['match'] = is_array($r['match'] ?? NULL) ? $r['match'] : NULL;
        $r['verified'] = Prx400_Bool::from_db($r['verified'] ?? FALSE);
        foreach (['id', 'spec_id', 'seq', 'level', 'file_no'] as $k)
        {
            if (isset($r[$k])) $r[$k] = (int) $r[$k];
        }
        foreach (['pos', 'parent_id', 'page'] as $k)
        {
            $r[$k] = isset($r[$k]) ? (int) $r[$k] : NULL;
        }
        $r['pos_path'] = (string) ($r['pos_path'] ?? '');
        $r['origin'] = (string) ($r['origin'] ?? 'parsed');

        return $r;
    }
}
