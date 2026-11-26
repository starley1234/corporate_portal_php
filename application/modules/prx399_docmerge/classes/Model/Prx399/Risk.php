<?php defined('SYSPATH') or die('No direct script access.');

/** Матрица рисков: source = 'static' (сборка) | 'llm' (аудит воркера). */
class Model_Prx399_Risk {

    public static function for_contract($contract_id)
    {
        return Prx_Db::select(
            "SELECT * FROM prx_399_risks WHERE contract_id = :id
             ORDER BY CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, id",
            [':id' => (int) $contract_id]);
    }

    public static function delete_for_contract($contract_id)
    {
        return Prx_Db::execute(
            'DELETE FROM prx_399_risks WHERE contract_id = :id',
            [':id' => (int) $contract_id]);
    }

    public static function delete_llm($contract_id)
    {
        return Prx_Db::execute(
            "DELETE FROM prx_399_risks WHERE contract_id = :id AND source = 'llm'",
            [':id' => (int) $contract_id]);
    }

    public static function insert(array $row)
    {
        return Prx_Db::execute(
            'INSERT INTO prx_399_risks
                (contract_id, severity, area, chunk_path, title, detail, recommendation, source)
             VALUES (:contract_id, :severity, :area, :chunk_path, :title, :detail, :recommendation, :source)',
            [
                ':contract_id' => (int) $row['contract_id'],
                ':severity' => (string) $row['severity'],
                ':area' => $row['area'],
                ':chunk_path' => $row['chunk_path'],
                ':title' => (string) $row['title'],
                ':detail' => $row['detail'],
                ':recommendation' => $row['recommendation'],
                ':source' => (string) $row['source'],
            ]);
    }
}
