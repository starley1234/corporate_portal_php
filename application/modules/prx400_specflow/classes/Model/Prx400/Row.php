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
