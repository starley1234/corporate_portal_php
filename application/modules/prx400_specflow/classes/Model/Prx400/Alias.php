<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_aliases — память сопоставлений: нормализованные «наименование +
 * обозначение» строки → код НСИ, выбранный человеком (или интегратором).
 * Активна одна запись на ключ (частичный уникальный индекс revoked_at IS NULL).
 */
class Model_Prx400_Alias {

    /** Активные решения для набора ключей: ["name|code_doc" => catalog_code]. */
    public static function lookup(array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values($keys), 500) as $chunk)
        {
            $pairs = [];
            $params = [];
            foreach ($chunk as $i => [$name, $code_doc])
            {
                $pairs[] = "(:n$i, :c$i)";
                $params[":n$i"] = (string) $name;
                $params[":c$i"] = (string) $code_doc;
            }
            foreach (Prx_Db::select(
                'SELECT name_norm, code_doc_norm, catalog_code FROM prx_400_aliases
                  WHERE revoked_at IS NULL AND (name_norm, code_doc_norm) IN ('.implode(', ', $pairs).')',
                $params) as $row)
            {
                $out[$row['name_norm'].'|'.$row['code_doc_norm']] = (string) $row['catalog_code'];
            }
        }

        return $out;
    }

    /** Запомнить (или переписать) решение по ключу. */
    public static function remember(string $name_norm, string $code_doc_norm, string $code, string $source, int $actor_id): void
    {
        Prx_Db::execute(
            'INSERT INTO prx_400_aliases (name_norm, code_doc_norm, catalog_code, source, actor_id)
             VALUES (:n, :c, :code, :src, :a)
             ON CONFLICT (name_norm, code_doc_norm) WHERE revoked_at IS NULL
             DO UPDATE SET catalog_code = EXCLUDED.catalog_code, source = EXCLUDED.source,
                           actor_id = EXCLUDED.actor_id, updated_at = now()',
            [':n' => $name_norm, ':c' => $code_doc_norm, ':code' => $code, ':src' => $source, ':a' => $actor_id]);
    }

    /** Счётчик применений (статистика «память сработала»). */
    public static function hit(array $keys): void
    {
        foreach ($keys as [$name, $code_doc])
        {
            Prx_Db::execute(
                'UPDATE prx_400_aliases SET hits = hits + 1 WHERE revoked_at IS NULL AND name_norm = :n AND code_doc_norm = :c',
                [':n' => (string) $name, ':c' => (string) $code_doc]);
        }
    }

    public static function revoke(int $id): int
    {
        return Prx_Db::execute('UPDATE prx_400_aliases SET revoked_at = now() WHERE id = :id AND revoked_at IS NULL', [':id' => $id]);
    }

    /** Отозвать активное решение по ключу (оператор выбрал другое/снял ручной код). */
    public static function revoke_key(string $name_norm, string $code_doc_norm): int
    {
        return Prx_Db::execute(
            'UPDATE prx_400_aliases SET revoked_at = now() WHERE revoked_at IS NULL AND name_norm = :n AND code_doc_norm = :c',
            [':n' => $name_norm, ':c' => $code_doc_norm]);
    }

    /** @return array{rows: array, total: int} */
    public static function page(string $query, int $limit, int $offset): array
    {
        $where = 'WHERE revoked_at IS NULL';
        $params = [];
        if (trim($query) !== '')
        {
            $where .= ' AND (name_norm ILIKE :q OR code_doc_norm ILIKE :q OR catalog_code ILIKE :q)';
            $params[':q'] = '%'.addcslashes(mb_substr(trim($query), 0, 200), '%_\\').'%';
        }
        $total = Prx_Db::one('SELECT count(*) AS c FROM prx_400_aliases '.$where, $params);

        return [
            'rows' => Prx_Db::select('SELECT * FROM prx_400_aliases '.$where
                .' ORDER BY updated_at DESC, id DESC LIMIT '.max(1, $limit).' OFFSET '.max(0, $offset), $params),
            'total' => (int) ($total['c'] ?? 0),
        ];
    }
}
