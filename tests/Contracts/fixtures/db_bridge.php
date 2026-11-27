<?php
/**
 * Мост для изолированных контрактов: НАСТОЯЩИЙ Prx_Db поверх фейкового Database.
 *
 * Сервисы ходят в БД через Prx_Db (DB::query()->parameters()->execute($db)).
 * Контракт без bootstrap объявляет только свой class Database с методом
 * query($type, $sql, $as_object) — как у драйвера Kohana. Здесь подменён лишь
 * построитель DB::query: он подставляет параметры (как Database_Query::compile,
 * через $db->quote) и отдаёт готовый SQL фейку. Транзакции, запрет вложенности и
 * identifier() — боевой код Prx_Db.
 *
 * SELECT-фейку можно вернуть массив строк: мост обернёт его в результат с
 * as_array()/current(), как Database_Result.
 *
 * Подключать ПОСЛЕ объявления class Database:
 *   require __DIR__.'/fixtures/db_bridge.php';
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 3).'/system/');

if ( ! class_exists('DB', FALSE))
{
    final class DB
    {
        public static function query($type, string $sql): Contract_Db_Query
        {
            return new Contract_Db_Query((int) $type, $sql);
        }
    }
}

final class Contract_Db_Query
{
    private array $params = [];

    public function __construct(private int $type, private string $sql) {}

    public function parameters(array $params): self
    {
        $this->params = $params + $this->params;
        return $this;
    }

    public function compile($db): string
    {
        $map = [];
        foreach ($this->params as $name => $value)
        {
            $map[$name] = match (TRUE) {
                $value === NULL => 'NULL',
                is_bool($value) => $value ? 'TRUE' : 'FALSE',
                is_int($value), is_float($value) => (string) $value,
                default => $db->quote((string) $value),
            };
        }
        // Длинные имена первыми: :id не должен съесть префикс :id_list.
        uksort($map, static fn ($a, $b) => strlen($b) <=> strlen($a));

        return strtr($this->sql, $map);
    }

    public function execute($db = NULL)
    {
        $db = $db ?? Database::instance();
        $result = $db->query($this->type, $this->compile($db), FALSE);
        if ($this->type === Database::SELECT && ! (is_object($result) && method_exists($result, 'as_array')))
        {
            return new Contract_Db_Result(self::rows($result));
        }

        return $result;
    }

    /** Строки из того, что вернул фейк: массив, итератор или объект с current(). */
    private static function rows($result): array
    {
        if ($result === NULL || $result === FALSE) return [];
        if (is_array($result)) return $result;
        if ($result instanceof Traversable) return iterator_to_array($result, FALSE);
        if (is_object($result) && method_exists($result, 'current'))
        {
            $row = $result->current();
            return $row ? [$row] : [];
        }

        return [];
    }
}

final class Contract_Db_Result
{
    public function __construct(private array $rows) {}

    public function as_array(): array
    {
        return array_map(static fn ($row) => (array) $row, array_values($this->rows));
    }

    public function current()
    {
        $first = reset($this->rows);
        return $first === FALSE ? NULL : $first;
    }
}

require_once dirname(__DIR__, 3).'/application/classes/Prx/Db.php';
