<?php defined('SYSPATH') or die('No direct script access.');

/** prx_400_edits — журнал правок строк оператором (только вставка и чтение). */
class Model_Prx400_Edit {

    public static function add($row_id, $spec_id, $field, $old, $new, $actor_id)
    {
        return Prx_Db::execute(
            'INSERT INTO prx_400_edits (row_id, spec_id, field, old_value, new_value, actor_id, created_at)
             VALUES (:r, :s, :f, :o, :n, :a, now())',
            [
                ':r' => (int) $row_id, ':s' => (int) $spec_id, ':f' => (string) $field,
                ':o' => $old, ':n' => $new, ':a' => (int) $actor_id,
            ]);
    }

    public static function for_spec($spec_id)
    {
        return Prx_Db::select(
            'SELECT e.*, r.seq FROM prx_400_edits e JOIN prx_400_rows r ON r.id = e.row_id
             WHERE e.spec_id = :s ORDER BY e.id DESC LIMIT 200',
            [':s' => (int) $spec_id]);
    }
}
