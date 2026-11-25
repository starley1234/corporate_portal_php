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
 *
 * Соединение: по умолчанию — дефолтное. Если операции нужен конкретный коннект
 * (например, очередь prx120 и её транзакция), передайте его третьим аргументом
 * `$db`: тогда значения остаются именованными, а ручной quote() не нужен.
 * `Database::instance()` и `$db->quote()` — только внутри инфраструктуры
 * (Prx_Db, Prx_Idempotency, prx120), не в бизнес-коде модулей.
 */
final class Prx_Db
{
    /** Транзакция принадлежит вызывающей операции, повторный вход запрещён. */
    private static array $transactions = [];

    /** @return array<int, array> */
    public static function select(string $sql, array $params = [], ?Database $db = NULL): array
    {
        return self::_run(Database::SELECT, $sql, $params, $db)->as_array();
    }

    /** Одна строка или NULL. */
    public static function one(string $sql, array $params = [], ?Database $db = NULL): ?array
    {
        $row = self::_run(Database::SELECT, $sql, $params, $db)->current();

        return $row ? (array) $row : NULL;
    }

    /** UPDATE/DELETE и другие запросы без возвращаемых строк. */
    public static function execute(string $sql, array $params = [], ?Database $db = NULL): int
    {
        return (int) self::_run(Database::UPDATE, $sql, $params, $db);
    }

    /** INSERT; возвращает [insert_id, rows]. */
    public static function insert(string $sql, array $params = [], ?Database $db = NULL): array
    {
        return (array) self::_run(Database::INSERT, $sql, $params, $db);
    }

    /** Выполнить запрос на выбранном соединении (по умолчанию — дефолтном). */
    private static function _run(int $type, string $sql, array $params, ?Database $db)
    {
        // $type — это int-константа Database::SELECT/INSERT/UPDATE/DELETE;
        // её нельзя объявлять строкой: приведение к "1" ломает строгое
        // сравнение `$type === Database::SELECT` в драйвере, и SELECT вернёт int.
        // DB::query() создаёт Database_Query; соединение выбирает execute($db).
        // $db->query() здесь НЕЛЬЗЯ: это низкоуровневый метод драйвера — он
        // выполняет SQL сразу и возвращает результат PDO, без parameters().
        return DB::query($type, $sql)->parameters($params)->execute($db);
    }

    /**
     * Одна операция — одна транзакция на явно выбранном соединении.
     * Вложенные вызовы на том же соединении запрещены, а не делают ранний COMMIT.
     * Не оборачивать в ручной begin(): владельцем границы должен быть один слой.
     */
    public static function transaction(callable $operation, ?Database $db = NULL)
    {
        $db = $db ?? Database::instance();
        $connection = spl_object_id($db);
        if (isset(self::$transactions[$connection])) {
            throw new LogicException('Вложенная транзакция на том же соединении запрещена');
        }

        self::$transactions[$connection] = TRUE;
        $started = FALSE;
        try {
            if ( ! $db->begin()) {
                throw new RuntimeException('Не начата транзакция');
            }
            $started = TRUE;
            $result = $operation();
            if ( ! $db->commit()) {
                throw new RuntimeException('Не подтверждена транзакция');
            }
            return $result;
        } catch (Throwable $e) {
            if ($started) {
                try {
                    $db->rollback();
                } catch (Throwable $rollback_error) {
                    // Сохраняем исходную ошибку, не раскрываем SQL/данные.
                    error_log('prx_db: rollback failed');
                }
            }
            throw $e;
        } finally {
            unset(self::$transactions[$connection]);
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
