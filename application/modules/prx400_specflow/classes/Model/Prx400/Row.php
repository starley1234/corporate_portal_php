<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_rows: SQL таблицы позиций. Транзакции открывает сервис
 * (Prx_Db::transaction) — модель их не вкладывает.
 */
class Model_Prx400_Row {

    /** Разрешённые к правке поля (белый список — имена колонок не из ввода). */
    public const EDITABLE = ['raw_name', 'code_doc', 'qty', 'unit'];

    /** Заменить позиции спецификации (вызывать внутри транзакции сервиса). */
    public static function replace_for_spec(int $spec_id, array $rows): int
    {
        Prx_Db::execute('DELETE FROM prx_400_rows WHERE spec_id = :spec', [':spec' => $spec_id]);
        foreach ($rows as $r)
        {
            Prx_Db::execute(
                'INSERT INTO prx_400_rows
                    (spec_id, seq, section, pos, raw_name, code_doc, qty, unit,
                     unit_price, total_price, flags, match, status)
                 VALUES (:spec, :seq, :section, :pos, :name, :code, :qty, :unit,
                     :price, :total, CAST(:flags AS jsonb), CAST(:match AS jsonb), :status)',
                [
                    ':spec' => $spec_id,
                    ':seq' => (int) $r['seq'],
                    ':section' => (string) $r['section'],
                    ':pos' => $r['pos'],
                    ':name' => (string) $r['raw_name'],
                    ':code' => (string) $r['code_doc'],
                    ':qty' => $r['qty'],
                    ':unit' => (string) $r['unit'],
                    ':price' => $r['unit_price'],
                    ':total' => $r['total_price'],
                    ':flags' => json_encode($r['flags'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ':match' => json_encode($r['match'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ':status' => (string) $r['status'],
                ]);
        }

        return count($rows);
    }

    /** Позиции спецификации; JSONB и boolean приведены к PHP-типам. */
    public static function for_spec(int $spec_id): array
    {
        return array_map([self::class, 'decode'], Prx_Db::select(
            'SELECT * FROM prx_400_rows WHERE spec_id = :spec ORDER BY seq ASC',
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

    /** Ручное сопоставление НСИ оператором: код зафиксирован и проверен. */
    public static function set_manual_match(int $id, int $actor_id, array $match): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_rows
                SET match = CAST(:match AS jsonb), status = \'ok\', verified = TRUE,
                    verified_by = :by, verified_at = now()
              WHERE id = :id',
            [
                ':id' => $id,
                ':match' => json_encode($match, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':by' => $actor_id,
            ]);
    }

    /** Строки, требующие решения оператора (не ok и не подтверждены). */
    public static function pending_count(int $spec_id): int
    {
        $row = Prx_Db::one(
            'SELECT count(*) AS c FROM prx_400_rows WHERE spec_id = :spec AND status <> \'ok\' AND NOT verified',
            [':spec' => $spec_id]);

        return (int) ($row['c'] ?? 0);
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

        return $r;
    }
}
