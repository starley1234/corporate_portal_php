<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Тонкий доступ к PostgreSQL для новых модулей.
 *
 * Зачем: в legacy-сервисах SQL собирается склейкой строк и ручным quote().
 * Это работает, но легко ломается и плохо тестируется. Здесь — один
 * предсказуемый вход:
 *
 *   Prx_Db::select('SELECT * FROM prx_300_employees WHERE organization_id = :org', [':org' => $org]);
 *   Prx_Db::one('SELECT ... WHERE id = :id', [':id' => $id]);
 *   Prx_Db::execute('UPDATE ... WHERE id = :id', [':id' => $id]);
 *   Prx_Db::insert('INSERT INTO ... (name) VALUES (:name)', [':name' => $name]);
 *   Prx_Db::transaction(function () { ... });
 *
 * Плейсхолдеры — `:name`; Kohana подставляет их через Database_Query::compile()
 * с quoting. Идентификаторы (таблицы/колонки) — только через identifier().
 * Это не ORM и не query builder: сложные запросы остаются SQL, но без ручной
 * склейки значений.
 */
final class Prx_Db
{
    /** @return array<int, array> */
    public static function select(string $sql, array $params = []): array
    {
        return DB::query(Database::SELECT, $sql)
            ->parameters($params)
            ->execute()
            ->as_array();
    }

    /** Одна строка или NULL. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = DB::query(Database::SELECT, $sql)
            ->parameters($params)
            ->execute()
            ->current();

        return $row ? (array) $row : NULL;
    }

    /** UPDATE/DELETE и другие запросы без возвращаемых строк. */
    public static function execute(string $sql, array $params = []): int
    {
        return (int) DB::query(Database::UPDATE, $sql)
            ->parameters($params)
            ->execute();
    }

    /** INSERT; возвращает [insert_id, rows]. */
    public static function insert(string $sql, array $params = []): array
    {
        return (array) DB::query(Database::INSERT, $sql)
            ->parameters($params)
            ->execute();
    }

    /**
     * Транзакция с откатом на любом исключении.
     *
     *   $id = Prx_Db::transaction(function () use ($data) { ... return $id; });
     */
    public static function transaction(callable $operation)
    {
        $db = Database::instance();
        $db->begin();

        try {
            $result = $operation();
            $db->commit();

            return $result;
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /** Безопасный идентификатор: только [a-z_][a-z0-9_]*, затем quoting. */
    public static function identifier(string $name): string
    {
        if ( ! preg_match('/^[a-z_][a-z0-9_]*$/D', $name)) {
            throw new InvalidArgumentException('Недопустимый идентификатор: '.$name);
        }

        return Database::instance()->quote_identifier($name);
    }
}
