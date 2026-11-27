<?php defined('SYSPATH') or die('No direct script access.');

/** prx_400_edits — журнал правок строк оператором (только вставка и чтение). */
class Model_Prx400_Edit {

    public static function add(int $row_id, int $spec_id, string $field, ?string $old, ?string $new, int $actor_id): int
    {
        return Prx_Db::execute(
            'INSERT INTO prx_400_edits (row_id, spec_id, field, old_value, new_value, actor_id, created_at)
             VALUES (:r, :spec, :f, :o, :n, :a, now())',
            [':r' => $row_id, ':spec' => $spec_id, ':f' => $field, ':o' => $old, ':n' => $new, ':a' => $actor_id]);
    }

    public static function for_spec(int $spec_id, int $limit = 200): array
    {
        return Prx_Db::select(
            'SELECT e.*, r.seq, r.pos FROM prx_400_edits e JOIN prx_400_rows r ON r.id = e.row_id
              WHERE e.spec_id = :spec ORDER BY e.id DESC LIMIT '.max(1, $limit),
            [':spec' => $spec_id]);
    }
}
