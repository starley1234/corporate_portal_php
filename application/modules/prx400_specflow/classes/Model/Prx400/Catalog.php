<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx_400_catalog: локальный справочник НСИ (MVP). SQL по владельцу-модели.
 * Интеграция с внешним MDM/1C/SAP — плановое расширение.
 */
class Model_Prx400_Catalog {

    private const COLUMNS = 'id, code, name, unit, okei_code, keywords';

    public static function all(): array
    {
        return Prx_Db::select('SELECT '.self::COLUMNS.' FROM prx_400_catalog ORDER BY id ASC');
    }

    /**
     * Страница справочника для UI: поиск подстроки по коду/наименованию/ключевым словам.
     * @return array{rows: array, total: int}
     */
    public static function page(string $query, int $limit, int $offset): array
    {
        $where = '';
        $params = [];
        $query = trim($query);
        if ($query !== '')
        {
            // Обратная косая — экранирующий символ LIKE по умолчанию в PostgreSQL.
            $where = ' WHERE code ILIKE :q OR name ILIKE :q OR keywords ILIKE :q';
            $params[':q'] = '%'.addcslashes(mb_substr($query, 0, 200), '%_\\').'%';
        }
        $total = Prx_Db::one('SELECT count(*) AS c FROM prx_400_catalog'.$where, $params);
        $rows = Prx_Db::select('SELECT '.self::COLUMNS.' FROM prx_400_catalog'.$where
            .' ORDER BY code ASC LIMIT '.max(1, $limit).' OFFSET '.max(0, $offset), $params);

        return ['rows' => $rows, 'total' => (int) ($total['c'] ?? 0)];
    }

    /** @param string[] $codes */
    public static function by_codes(array $codes): array
    {
        if ($codes === []) return [];
        $params = [];
        foreach (array_values($codes) as $i => $code) $params[':c'.$i] = (string) $code;

        return Prx_Db::select('SELECT '.self::COLUMNS.' FROM prx_400_catalog WHERE code IN ('.implode(', ', array_keys($params)).')', $params);
    }

    /**
     * Пакетный upsert по коду (импорт). Вызывающий держит транзакцию и не
     * передаёт повторов кода в одном пакете (ON CONFLICT их не допускает).
     */
    public static function upsert_many(array $items): int
    {
        if ($items === []) return 0;
        $values = [];
        $params = [];
        foreach (array_values($items) as $i => $item)
        {
            $values[] = "(:code$i, :name$i, :unit$i, :okei$i, :kw$i)";
            $params += [
                ":code$i" => (string) $item['code'], ":name$i" => (string) $item['name'], ":unit$i" => (string) $item['unit'],
                ":okei$i" => (string) $item['okei_code'], ":kw$i" => (string) $item['keywords'],
            ];
        }

        return Prx_Db::execute(
            'INSERT INTO prx_400_catalog (code, name, unit, okei_code, keywords) VALUES '.implode(', ', $values).'
             ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, unit = EXCLUDED.unit,
                 okei_code = EXCLUDED.okei_code, keywords = EXCLUDED.keywords',
            $params);
    }

    public static function by_code(string $code): ?array
    {
        return Prx_Db::one('SELECT '.self::COLUMNS.' FROM prx_400_catalog WHERE code = :c', [':c' => $code]);
    }

    /** Есть ли pg_trgm (миграция 005 ставит его, если расширение доступно). */
    public static function trigram_available(): bool
    {
        static $available = NULL;
        if ($available === NULL)
        {
            $available = Prx_Db::one("SELECT 1 AS x FROM pg_extension WHERE extname = 'pg_trgm'") !== NULL;
        }

        return $available;
    }

    /**
     * Предотбор кандидатов на одну строку спецификации силами PostgreSQL
     * (индекс prx_400_catalog_trgm по тому же выражению). Окончательный скор
     * считает Service_Prx400_Match — пороги и формула не зависят от отбора.
     */
    public static function candidates(string $query, int $limit): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') return [];

        return Prx_Db::select(
            'SELECT '.self::COLUMNS.' FROM prx_400_catalog
              WHERE lower(name || \' \' || keywords) % :q
              ORDER BY similarity(lower(name || \' \' || keywords), :q) DESC, id
              LIMIT '.max(1, $limit),
            [':q' => $query]);
    }

    public static function insert(array $data): ?array
    {
        return Prx_Db::one(
            'INSERT INTO prx_400_catalog (code, name, unit, okei_code, keywords)
             VALUES (:code, :name, :unit, :okei, :kw)
             ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, unit = EXCLUDED.unit,
                 okei_code = EXCLUDED.okei_code, keywords = EXCLUDED.keywords
             RETURNING *',
            [
                ':code' => (string) $data['code'],
                ':name' => (string) $data['name'],
                ':unit' => (string) $data['unit'],
                ':okei' => (string) $data['okei_code'],
                ':kw' => (string) $data['keywords'],
            ]);
    }

    public static function delete(int $id): int
    {
        return Prx_Db::execute('DELETE FROM prx_400_catalog WHERE id = :id', [':id' => $id]);
    }

    public static function count(): int
    {
        $row = Prx_Db::one('SELECT count(*) AS c FROM prx_400_catalog');

        return (int) ($row['c'] ?? 0);
    }
}
