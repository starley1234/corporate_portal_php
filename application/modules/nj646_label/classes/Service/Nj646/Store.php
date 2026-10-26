<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — работа «Разбора состава» с базой: ЕДИНСТВЕННАЯ дверь в БД.
 *
 * Три вида накопления:
 *   * БАЗА ВЕЩЕСТВ (`nj_646_ingredients`) — карточки растут из конфига,
 *     из правок посетителей и из ИИ-изучения неизвестных ингредиентов;
 *   * ОПЫТ (`nj_646_analyses`, `nj_646_unknowns`) — разборы и незнакомые
 *     вещества: чаще встречается — выше hits, вероятность изучения;
 *   * ПРАВКИ (`nj_646_votes`) и ПРОФИЛЬ (`nj_646_profiles`) — под посетителя
 *     подстраиваются предупреждения.
 *
 * Чистая логика (разбор строки, сопоставление, оценка) — в Service_Nj646_Label.
 *
 * РАСПИЛ (03.10.2026, долг P2-9): класс дорос до 4 500 строк, и правка в одном
 * разделе требовала держать в голове весь файл. Разделы живут в трейтах
 * (`classes/Service/Nj646/Store/*.php`), здесь остались свойства и общие
 * помощники (`db`, `cfg`, `flag`, `ensure_*`, `_all`, `_one`, `_json*`).
 *
 * Почему трейты, а не отдельные классы: публичный контракт не меняется ни на
 * букву (`Store::ingredient_by_id()` — как и раньше), а `self::` внутри частей
 * видит те же свойства и помощники. Отдельные классы потребовали бы сделать
 * помощники публичными и переписать сотни вызовов — риск без выгоды. Правило
 * «вся запись — через Service_Nj646_Store» остаётся: части подключены только
 * к нему.
 */
class Service_Nj646_Store
{
    /** @var Database|NULL */
    protected static $_db = NULL;

    /** @var bool защита от повторного создания таблиц в одном запросе */
    protected static $_ensured = FALSE;

    /** @var bool таблицы товаров/производителей проверены в текущем запросе */
    protected static $_goods_ensured = FALSE;

    /** Индексы и поздние колонки (см. ensure_indexes) — один раз на запрос. */
    protected static $_indexes_ensured = FALSE;

    /** @var array|NULL кеш индекса веществ на запрос */

    protected static $_index = NULL;

    // Вещества: чтение, поиск, черновики и публикация, выгрузка.
    use Service_Nj646_Store_Ingredients;

    // Справочник деревом: уровни каталога, пресеты, правка карточки.
    use Service_Nj646_Store_Book;

    // Голоса посетителей — «согласен» и «спорно».
    use Service_Nj646_Store_Votes;

    // Разборы: сохранение, история, «мои разборы», рабочие имена.
    use Service_Nj646_Store_Analyses;

    // Товары и производители с этикеток, дубли и слияние.
    use Service_Nj646_Store_Goods;

    // Очередь незнакомых веществ, изучение и отказы.
    use Service_Nj646_Store_Unknowns;

    // Прогоны очереди (nj120): постановка, статусы, отмена.
    use Service_Nj646_Store_Runs;

    // Кадры прогонов: файлом, для воркера, чистка.
    use Service_Nj646_Store_Photos;

    // Профиль посетителя и «удалить мои данные».
    use Service_Nj646_Store_Profiles;

    // Счётчики модуля, поздние индексы, служебные выборки.
    use Service_Nj646_Store_Stats;

    public static function db()
    {
        return self::$_db !== NULL ? self::$_db : self::$_db = Database::instance();
    }

    /** Публичный доступ к конфигу модуля. */
    public static function cfg($key = NULL, $default = NULL)
    {
        static $cfg = NULL;

        if ($cfg === NULL)
        {
            $cfg = (array) Kohana::$config->load('nj646_label');
        }

        if ($key === NULL)
        {
            return $cfg;
        }

        $value = $cfg;

        foreach (explode('.', $key) as $part)
        {
            if ( ! is_array($value) OR ! array_key_exists($part, $value))
            {
                return $default;
            }

            $value = $value[$part];
        }

        return $value === NULL ? $default : $value;
    }

    public static function limit($key, $default)
    {
        return (int) self::cfg('limits.'.$key, $default);
    }

    /**
     * Булево из БД: драйвер отдаёт его и `true`, и строкой 't'/'1'.
     *
     * Нужно там, где флаг решает, что показать человеку (`title_auto`,
     * `name_auto`): строка 'f' при приведении `(bool)` дала бы «истину», и
     * рабочее имя выглядело бы настоящим.
     */
    public static function flag($value)
    {
        return $value === TRUE OR in_array((string) $value, ['1', 't', 'true', 'on', 'yes'], TRUE);
    }

    protected static function _json($value)
    {
        return self::db()->quote(json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Страховка на запись: обрезать текст по БАЙТАМ, не ломая UTF-8.
     *
     * Лимит на входе (`limits.text_max`) — это про форму, и он честно режет
     * то, что человек вставил. Но колонка может получить текст и другим
     * путём (поднятый env, новая точка приёма), а `raw_text` — это ещё и
     * транскрипт модели: кириллица в UTF-8 вдвое тяжелее знака. Поэтому
     * предел стоит и у самой записи, а `$was_cut` возвращает правду —
     * вызывающий обязан сказать человеку «состав сохранён в сокращении»,
     * а не молча показывать половину текста как целый.
     */
    protected static function _text_cut($text, $max_bytes, & $was_cut = NULL)
    {
        $text      = (string) $text;
        $max_bytes = max(1024, (int) $max_bytes);
        $was_cut   = strlen($text) > $max_bytes;

        // mb_strcut режет по границе символа — последний знак не рассыпается
        // в «кракозябру».
        return $was_cut ? mb_strcut($text, 0, $max_bytes, 'UTF-8') : $text;
    }

    /**
     * Страховка на запись jsonb-значения (результат прогона, снимки).
     *
     * Прогон несёт текст этикетки и транскрипт модели — это самые тяжёлые
     * поля очереди. Режем сначала длинные строки (вдвое, пока не влезем),
     * а совсем вырожденный случай оставляем «сутью»: статус и объяснение.
     * Флаг `_cut` внутри значения говорит, что результат сохранён не целиком;
     * для дожима это не потеря — `_cut_note` честно объясняет причину.
     */
    protected static function _json_cut(array $data, $max_bytes, & $was_cut = NULL)
    {
        $max_bytes = max(2048, (int) $max_bytes);
        $was_cut   = FALSE;

        $size = function (array $value) {
            return strlen(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        };

        if ($size($data) <= $max_bytes)
        {
            return $data;
        }

        $was_cut = TRUE;

        $cut = function (& $value, $per_string) use (& $cut) {
            if (is_array($value))
            {
                foreach ($value as & $item)
                {
                    $cut($item, $per_string);
                }

                unset($item);
            }
            elseif (is_string($value) AND strlen($value) > $per_string)
            {
                $value = mb_strcut($value, 0, $per_string, 'UTF-8');
            }
        };

        $per_string = $max_bytes;

        while ($size($data) > $max_bytes AND $per_string > 256)
        {
            $per_string = intdiv($per_string, 2);
            $cut($data, $per_string);
        }

        if ($size($data) > $max_bytes)
        {
            // Тысячи мелких строк: строки уже срезаны, значит дело в объёме
            // самого перечня. Оставляем то, по чему страница принимает
            // решение, остальное честно теряем.
            $data = array_intersect_key($data, array_flip([
                'status', 'mode', 'error', 'note', 'model', 'elapsed_ms',
                'analysis_id', 'run_id', 'title', 'kind',
            ]));
        }

        $data['_cut']      = TRUE;
        $data['_cut_note'] = 'Результат был больше отведённого размера — длинные строки сохранены в сокращении.';

        return $data;
    }

    protected static function _one($sql, $key = NULL)
    {
        $row = self::db()->query(Database::SELECT, $sql, FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        $row = (array) $row;

        return $key === NULL ? $row : ($row[$key] ?? NULL);
    }

    protected static function _all($sql)
    {
        $rows = self::db()->query(Database::SELECT, $sql, FALSE)->as_array();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Создать таблицы, если миграция ещё не накатана (стенды, первый запуск).
     * Не повторяет DDL: накатывает ту же миграцию 001 — один источник истины.
     */
    public static function ensure_tables()
    {
        if (self::$_ensured)
        {
            return;
        }

        self::$_ensured = TRUE;

        try
        {
            self::db()->query(Database::SELECT, 'SELECT 1 FROM nj_646_ingredients LIMIT 1', FALSE);
            // Товары и производители появились позже (миграция 002), тип
            // продукта и рабочие индексы — позже их: проверяем отдельно, база
            // могла быть создана (и обновлена) в любой момент.
            self::ensure_goods();
            self::ensure_indexes();

            return;
        }
        catch (Throwable $e)
        {
            // Таблиц нет — накатываем миграцию вручную.
        }

        try
        {
            $file = Kohana::find_file('migrations_pg', 'pgMigration_Nj_646_001_Label_Tables');

            if ($file)
            {
                require_once $file;
                $class = 'pgMigration_Nj_646_001_Label_Tables';

                if (class_exists($class))
                {
                    $migration = new $class;
                    $migration->up();
                }
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj646: ensure_tables: :e', [':e' => $e->getMessage()]);
        }

        try
        {
            $file = Kohana::find_file('migrations_pg', 'pgMigration_Nj_646_002_Goods');

            if ($file)
            {
                require_once $file;
                $class = 'pgMigration_Nj_646_002_Goods';

                if (class_exists($class))
                {
                    $migration = new $class;
                    $migration->up();
                }
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj646: ensure_goods: :e', [':e' => $e->getMessage()]);
        }
    }

    /**
     * Товары и производители с этикеток (миграция 002) — идемпотентно.
     *
     * Сопутствующая информация с этикетки лежит в ОБЩИХ для проекта таблицах:
     * производители — это те же предприятия, что в онтологии (nj607) и
     * справочнике организаций (nj594), поэтому запись одна на организацию
     * (`name_key`), а товар — одна карточка на штрихкод или «бренд + название».
     * Так накопленное пригодится и другим модулям, а не осядет в тексте разбора.
     */
    public static function ensure_goods()
    {
        if (self::$_goods_ensured)
        {
            return;
        }

        self::$_goods_ensured = TRUE;

        try
        {
            self::db()->query(Database::SELECT, 'SELECT producer_id, product_id, about FROM nj_646_analyses LIMIT 1', FALSE);

            // Поздние колонки товара (`nutrition`, `key_aliases`) живут в
            // `_indexes_ddl()`: без этого вызова код, который дошёл до товаров
            // в обход `ensure_tables()`, писал бы в колонку, которой нет.
            self::ensure_indexes();

            return;
        }
        catch (Throwable $e)
        {
            // Колонок/таблиц нет — накатываем DDL (он идемпотентный).
        }

        try
        {
            self::db()->query(Database::SELECT, self::_goods_ddl(), FALSE);
        }
        catch (Throwable $e)
        {
            self::$_goods_ensured = FALSE;
            Kohana::$log->add(Log::WARNING, 'nj646: ensure_goods DDL: :e', [':e' => $e->getMessage()]);
        }
    }

    /** DDL товаров/производителей — одно место (миграция 002 дублирует слово в слово). */
    public static function _goods_ddl()
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS "nj_646_producers" (
    "id"           BIGSERIAL PRIMARY KEY,
    "name_key"     CHAR(64)     NOT NULL UNIQUE,
    "name"         VARCHAR(300) NOT NULL DEFAULT '',
    "role"         VARCHAR(16)  NOT NULL DEFAULT 'producer',
    "address"      VARCHAR(400) NOT NULL DEFAULT '',
    "country"      VARCHAR(80)  NOT NULL DEFAULT '',
    "inn"          VARCHAR(12)  NOT NULL DEFAULT '',
    "ogrn"         VARCHAR(15)  NOT NULL DEFAULT '',
    "phone"        VARCHAR(40)  NOT NULL DEFAULT '',
    "site"         VARCHAR(120) NOT NULL DEFAULT '',
    "org_id"       BIGINT       NULL,
    "ontology_id"  BIGINT       NULL,
    "ontology_url" VARCHAR(300) NOT NULL DEFAULT '',
    "hits"         INTEGER      NOT NULL DEFAULT 0,
    "source"       VARCHAR(24)  NOT NULL DEFAULT 'label',
    "raw"          JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "first_seen"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "last_seen"    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_646_producers_name_idx" ON "nj_646_producers" (lower("name"));
CREATE INDEX IF NOT EXISTS "nj_646_producers_inn_idx"  ON "nj_646_producers" ("inn");
CREATE INDEX IF NOT EXISTS "nj_646_producers_ogrn_idx" ON "nj_646_producers" ("ogrn");
COMMENT ON TABLE "nj_646_producers" IS 'NJ-646 производители и импортёры с этикеток: одна запись на организацию, связана с онтологией (nj607) и справочником организаций (nj594)';
COMMENT ON COLUMN "nj_646_producers"."role" IS 'producer | importer — кем организация указана на этикетке';

CREATE TABLE IF NOT EXISTS "nj_646_products" (
    "id"          BIGSERIAL PRIMARY KEY,
    "key"         CHAR(64)     NOT NULL UNIQUE,
    "producer_id" BIGINT       NULL,
    "name"        VARCHAR(200) NOT NULL DEFAULT '',
    "brand"       VARCHAR(120) NOT NULL DEFAULT '',
    "kind"        VARCHAR(12)  NOT NULL DEFAULT 'auto',
    "barcode"     VARCHAR(20)  NOT NULL DEFAULT '',
    "weight"      VARCHAR(40)  NOT NULL DEFAULT '',
    "gost"        VARCHAR(80)  NOT NULL DEFAULT '',
    "shelf_life"  VARCHAR(120) NOT NULL DEFAULT '',
    "storage"     VARCHAR(200) NOT NULL DEFAULT '',
    "country"     VARCHAR(80)  NOT NULL DEFAULT '',
    "hits"        INTEGER      NOT NULL DEFAULT 0,
    "raw"         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "first_seen"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "last_seen"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_646_products_name_idx"     ON "nj_646_products" (lower("name"));
CREATE INDEX IF NOT EXISTS "nj_646_products_barcode_idx"  ON "nj_646_products" ("barcode");
CREATE INDEX IF NOT EXISTS "nj_646_products_producer_idx" ON "nj_646_products" ("producer_id");
COMMENT ON TABLE "nj_646_products" IS 'NJ-646 товары с этикеток: сопутствующая информация (штрихкод, масса, ГОСТ/ТУ, срок, хранение) и история разборов состава';

ALTER TABLE "nj_646_analyses" ADD COLUMN IF NOT EXISTS "producer_id" BIGINT NULL;
ALTER TABLE "nj_646_analyses" ADD COLUMN IF NOT EXISTS "product_id"  BIGINT NULL;
ALTER TABLE "nj_646_analyses" ADD COLUMN IF NOT EXISTS "about" JSONB NOT NULL DEFAULT '{}'::jsonb;
CREATE INDEX IF NOT EXISTS "nj_646_analyses_product_idx"  ON "nj_646_analyses" ("product_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_646_analyses_producer_idx" ON "nj_646_analyses" ("producer_id", "id" DESC);
COMMENT ON COLUMN "nj_646_analyses"."about" IS 'Сопутствующая информация с этикетки на момент разбора (товар, производитель, реквизиты)';
SQL;
    }
}
