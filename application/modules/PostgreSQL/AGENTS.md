# AGENTS.md — PostgreSQL (драйвер базы)

> Памятка агенту. Общие правила платформы — в корневом /AGENTS.md.

## Назначение
Нативный драйвер PostgreSQL (ext `pgsql`, функции `pg_*`) для Kohana Database:
быстрее PDO, поддерживает схему (`DB_DEFAULT_SCHEMA`), кэш первичных ключей,
умное `RETURNING` для INSERT.

## Ключевые классы
| Класс | Роль |
|---|---|
| `Kohana_Database_PostgreSQL` | драйвер: connect/query/begin/commit/rollback, list_columns, quote |
| `Database_PostgreSQL_Result` | результат выборки |

## Подводные камни (важно для всего проекта!)
- **Параметры запросов НЕ биндятся** (`pg_query` без prepare): все значения
  интерполируются через `$db->quote()`. Незаквотированные пользовательские
  данные = SQL-инъекция.
- `query()` возвращает:
  * SELECT → `Database_Result`;
  * UPDATE/DELETE без RETURNING → число строк (int);
  * INSERT → `array($insert_id, $rows)`.
  Если нужен RETURNING от UPDATE/INSERT — выполняйте с типом
  `Database::SELECT`, чтобы получить выборку (паттерн модуля nj120_jobs).
- Конфиг БД: `application/config/database.php`, переменные `DB_DEFAULT_*`.

## Не трогать
- Драйвер трогать только осознанно — от него зависит весь монолит.
- Второй драйвер `PostgreSQL_PDO` существует как альтернатива; по умолчанию
  используется нативный (`'type' => 'PostgreSQL'`).
