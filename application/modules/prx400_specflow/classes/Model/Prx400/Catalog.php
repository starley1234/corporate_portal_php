<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_catalog: локальный справочник НСИ (MVP). SQL по владельцу-модели.
 * Интеграция с внешним MDM/1C/SAP — плановое расширение (endpoint в шаблоне).
 */
class Model_Prx400_Catalog {

    public static function all()
    {
        return Prx_Db::select('SELECT id, code, name, unit, okei_code FROM prx_400_catalog ORDER BY id ASC');
    }

    public static function insert(array $data)
    {
        $row = Prx_Db::one(
            'INSERT INTO prx_400_catalog (code, name, unit, okei_code, keywords)
             VALUES (:code, :name, :unit, :okei, :kw)
             ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, unit = EXCLUDED.unit,
                 okei_code = EXCLUDED.okei_code, keywords = EXCLUDED.keywords
             RETURNING *',
            [
                ':code' => (string) $data['code'],
                ':name' => (string) $data['name'],
                ':unit' => (string) ($data['unit'] ?? 'шт.'),
                ':okei' => (string) ($data['okei_code'] ?? ''),
                ':kw' => (string) ($data['keywords'] ?? ''),
            ]);

        return is_array($row) ? $row : NULL;
    }

    public static function delete($id)
    {
        return Prx_Db::execute('DELETE FROM prx_400_catalog WHERE id = :id', [':id' => (int) $id]);
    }

    public static function count()
    {
        $row = Prx_Db::one('SELECT count(*) AS c FROM prx_400_catalog');

        return (int) ($row['c'] ?? 0);
    }
}
