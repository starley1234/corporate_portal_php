<?php defined('SYSPATH') or die('No direct script access.');

/** Дельты одного ДС: точечные инструкции сборки (replace | add | annul). */
class Model_Prx399_Delta {

    public static function for_contract($contract_id)
    {
        return Prx_Db::select(
            'SELECT * FROM prx_399_deltas WHERE contract_id = :id ORDER BY file_id, seq',
            [':id' => (int) $contract_id]);
    }

    /** Одна дельта (переключение состава сборки). */
    public static function get($id)
    {
        return Prx_Db::one('SELECT * FROM prx_399_deltas WHERE id = :id', [':id' => (int) $id]);
    }

    /** Состав сборки — решение человека (ADR-003); значения только yes|no. */
    public static function set_included($id, $included)
    {
        return Prx_Db::execute(
            'UPDATE prx_399_deltas SET included = :inc WHERE id = :id',
            [':id' => (int) $id, ':inc' => $included === 'no' ? 'no' : 'yes']);
    }

    public static function delete_for_file($file_id)
    {
        return Prx_Db::execute(
            'DELETE FROM prx_399_deltas WHERE file_id = :id',
            [':id' => (int) $file_id]);
    }

    public static function insert(array $row)
    {
        return Prx_Db::execute(
            'INSERT INTO prx_399_deltas
                (contract_id, file_id, seq, op, anchor, body, note, confidence, body_in_ds)
             VALUES (:contract_id, :file_id, :seq, :op, :anchor, :body, :note, :confidence, :body_in_ds)',
            [
                ':contract_id' => (int) $row['contract_id'],
                ':file_id' => (int) $row['file_id'],
                ':seq' => (int) $row['seq'],
                ':op' => (string) $row['op'],
                ':anchor' => (string) $row['anchor'],
                ':body' => $row['body'],
                ':note' => $row['note'],
                ':confidence' => $row['confidence'],
                ':body_in_ds' => isset($row['body_in_ds']) && in_array($row['body_in_ds'], ['yes', 'no'], TRUE)
                    ? (string) $row['body_in_ds'] : NULL,
            ]);
    }
}
