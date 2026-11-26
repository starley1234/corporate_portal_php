<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_rows: SQL по владельцу-модели (стража архитектуры).
 */
class Model_Prx400_Row {

    public static function replace_for_spec($spec_id, array $rows)
    {
        return Prx_Db::transaction(function () use ($spec_id, $rows)
        {
            Prx_Db::execute('DELETE FROM prx_400_rows WHERE spec_id = :id', [':id' => (int) $spec_id]);
            foreach ($rows as $r)
            {
                Prx_Db::execute(
                    'INSERT INTO prx_400_rows
                        (spec_id, seq, section, pos, raw_name, code_doc, qty, unit,
                         unit_price, total_price, flags, match, status)
                     VALUES (:spec, :seq, :section, :pos, :name, :code, :qty, :unit,
                         :price, :total, :flags::jsonb, :match::jsonb, :status)',
                    [
                        ':spec' => (int) $spec_id,
                        ':seq' => (int) $r['seq'],
                        ':section' => (string) ($r['section'] ?? ''),
                        ':pos' => $r['pos'] !== NULL ? (int) $r['pos'] : NULL,
                        ':name' => (string) ($r['raw_name'] ?? ''),
                        ':code' => (string) ($r['code_doc'] ?? ''),
                        ':qty' => $r['qty'] !== NULL ? (float) $r['qty'] : NULL,
                        ':unit' => (string) ($r['unit'] ?? ''),
                        ':price' => $r['unit_price'] !== NULL ? (float) $r['unit_price'] : NULL,
                        ':total' => $r['total_price'] !== NULL ? (float) $r['total_price'] : NULL,
                        ':flags' => json_encode(array_slice((array) ($r['flags'] ?? []), 0, 10), JSON_UNESCAPED_UNICODE),
                        ':match' => isset($r['match']) ? json_encode($r['match'], JSON_UNESCAPED_UNICODE) : 'null',
                        ':status' => (string) ($r['status'] ?? 'new'),
                    ]);
            }

            return count($rows);
        });
    }

    public static function for_spec($spec_id)
    {
        // JSONB-колонки PDO отдаёт СТРОКОЙ — приводим к массивам для вьюхи
        // и экспорта (иначе (array) от строки даёт [0 => 'строка'] и
        // TypeError «Cannot access offset of type string»).
        $out = [];
        foreach (Prx_Db::select(
            'SELECT * FROM prx_400_rows WHERE spec_id = :id ORDER BY seq ASC',
            [':id' => (int) $spec_id]) as $r)
        {
            $r = (array) $r;
            foreach (['flags', 'match'] as $k)
            {
                if (isset($r[$k]) AND ! is_array($r[$k]))
                {
                    $r[$k] = json_decode((string) $r[$k], TRUE);
                }
            }

            $out[] = $r;
        }

        return $out;
    }

    /**
     * Пересчитать статус строки из её же данных (после снятия «Проверено»):
     * ошибка математики → error; код НСИ → ok; есть альтернативы → review;
     * иначе → new. Детерминированно, зеркально раскраске result().
     */
    public static function recompute_status($id)
    {
        $row = self::get($id);
        if ( ! $row)
        {
            return;
        }
        $match = is_array($row['match'] ?? NULL) ? $row['match'] : json_decode((string) ($row['match'] ?? 'null'), TRUE);
        $flags = is_array($row['flags'] ?? NULL) ? $row['flags'] : json_decode((string) ($row['flags'] ?? '[]'), TRUE);
        $types = array_column((array) $flags, 'type');

        $status = 'new';
        if (in_array('math_row', $types, TRUE))
        {
            $status = 'error';
        }
        elseif ( ! empty($match['code']))
        {
            $status = 'ok';
        }
        elseif ( ! empty($match['alternatives']))
        {
            $status = 'review';
        }

        return Prx_Db::execute(
            'UPDATE prx_400_rows SET status = :s WHERE id = :id',
            [':id' => (int) $id, ':s' => $status]);
    }

    /**
     * Правка полей строки оператором (HITL-коррекция vision-распознавания):
     * каждое изменённое поле — запись в журнал prx_400_edits; после правки
     * пересчитываются флаг математики строки и статус (детерминированно).
     */
    /** Разрешённые к правке поля (белый список — имена колонок не из ввода). */
    public static function editable_fields(): array
    {
        return ['raw_name', 'code_doc', 'qty', 'unit'];
    }

    public static function apply_edit($row_id, $actor_id, array $fields)
    {
        $row = self::get($row_id);
        if ( ! $row)
        {
            return NULL;
        }

        $allowed = array_fill_keys(self::editable_fields(), TRUE);
        foreach ($fields as $field => $new)
        {
            if ( ! isset($allowed[$field]) OR ! array_key_exists($field, $row))
            {
                continue;
            }
            $old = (string) $row[$field];
            if ((string) $new === $old)
            {
                continue;
            }
            Model_Prx400_Edit::add($row_id, (int) $row['spec_id'], $field, $old, (string) $new, $actor_id);
            Prx_Db::execute(
                'UPDATE prx_400_rows SET '.$field.' = :v WHERE id = :id', // $field — из белого списка выше
                [':id' => (int) $row_id, ':v' => $new]);
        }

        // Флаг математики строки: qty * unit_price == total_price (tolerance 0.01).
        $fresh = self::get($row_id);
        $flags = is_array($fresh['flags'] ?? NULL) ? $fresh['flags'] : json_decode((string) ($fresh['flags'] ?? '[]'), TRUE);
        $flags = array_values(array_filter((array) $flags, static fn ($f) => ($f['type'] ?? '') !== 'math_row'));
        if ($fresh['qty'] !== NULL AND $fresh['unit_price'] !== NULL AND $fresh['total_price'] !== NULL)
        {
            $expected = round((float) $fresh['qty'] * (float) $fresh['unit_price'], 2);
            if (abs($expected - (float) $fresh['total_price']) > 0.01)
            {
                $flags[] = ['type' => 'math_row', 'expected' => $expected];
            }
        }
        Prx_Db::execute(
            'UPDATE prx_400_rows SET flags = :f::jsonb WHERE id = :id',
            [':id' => (int) $row_id, ':f' => json_encode($flags, JSON_UNESCAPED_UNICODE)]);

        self::recompute_status((int) $row_id);

        return self::get($row_id);
    }

    public static function get($id)
    {
        $row = Prx_Db::one('SELECT * FROM prx_400_rows WHERE id = :id', [':id' => (int) $id]);

        return is_array($row) ? $row : NULL;
    }

    public static function set_verified($id, $actor_id, $verified = TRUE)
    {
        return Prx_Db::execute(
            'UPDATE prx_400_rows
             SET verified = :v, verified_by = :by, verified_at = CASE WHEN :v THEN now() ELSE NULL END,
                 status = CASE WHEN :v THEN \'ok\' ELSE status END
             WHERE id = :id',
            [':id' => (int) $id, ':v' => $verified ? 't' : 'f', ':by' => (int) $actor_id]);
    }

    /** Верификация с ручным сопоставлением НСИ (Active Learning — позже): пока фикс кода. */
    public static function set_manual_match($id, $actor_id, array $match)
    {
        return Prx_Db::execute(
            'UPDATE prx_400_rows
             SET match = :match::jsonb, status = \'ok\', verified = \'t\',
                 verified_by = :by, verified_at = now()
             WHERE id = :id',
            [
                ':id' => (int) $id,
                ':match' => json_encode($match, JSON_UNESCAPED_UNICODE),
                ':by' => (int) $actor_id,
            ]);
    }
}
