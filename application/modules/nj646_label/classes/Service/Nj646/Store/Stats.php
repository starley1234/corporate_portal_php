<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — часть `Stats`: счётчики модуля, поздние индексы и служебные выборки.
 *
 * Трейт класса `Service_Nj646_Store` (см. его шапку): разделы вынесены из одного
 * файла, публичные имена методов не менялись. Свойства и общие помощники
 * (`self::db()`, `self::_all()`, `self::ensure_*()`…) живут в самом классе.
 */
trait Service_Nj646_Store_Stats
{

    public static function stats()
    {
        self::ensure_tables();

        $c = self::_one('SELECT
            (SELECT COUNT(*) FROM nj_646_ingredients WHERE status = \'published\') AS ingredients,
            (SELECT COUNT(*) FROM nj_646_ingredients WHERE status = \'draft\') AS drafts,
            (SELECT COUNT(*) FROM nj_646_ingredients WHERE source = \'llm\') AS learned,
            (SELECT COUNT(*) FROM nj_646_analyses) AS analyses,
            (SELECT COUNT(*) FROM nj_646_analyses WHERE verdict IN (\'risky\', \'avoid\')) AS analyses_risky,
            (SELECT COUNT(*) FROM nj_646_unknowns) AS unknowns,
            (SELECT COUNT(*) FROM nj_646_unknowns WHERE status = \'studied\') AS unknowns_studied,
            (SELECT COUNT(*) FROM nj_646_runs) AS runs,
            (SELECT COUNT(*) FROM nj_646_runs WHERE status = \'done\') AS runs_done,
            (SELECT COUNT(*) FROM nj_646_votes) AS votes,
            (SELECT COUNT(*) FROM nj_646_votes WHERE value < 0) AS disputes,
            (SELECT COALESCE(SUM(hits), 0) FROM nj_646_ingredients) AS hits');

        $c = is_array($c) ? $c : [];

        $top = self::_all('SELECT name_ru, slug, hits, risk, verdict, category
                           FROM nj_646_ingredients WHERE hits > 0
                           ORDER BY hits DESC, id LIMIT 8');

        return [
            'ingredients'      => (int) ($c['ingredients'] ?? 0),
            'drafts'           => (int) ($c['drafts'] ?? 0),
            'learned'          => (int) ($c['learned'] ?? 0),
            'analyses'         => (int) ($c['analyses'] ?? 0),
            'analyses_risky'   => (int) ($c['analyses_risky'] ?? 0),
            'unknowns'         => (int) ($c['unknowns'] ?? 0),
            'unknowns_studied' => (int) ($c['unknowns_studied'] ?? 0),
            'runs'             => (int) ($c['runs'] ?? 0),
            'runs_done'        => (int) ($c['runs_done'] ?? 0),
            'votes'            => (int) ($c['votes'] ?? 0),
            'disputes'         => (int) ($c['disputes'] ?? 0),
            'hits'             => (int) ($c['hits'] ?? 0),
            'top'              => $top,
        ];
    }

    /**
     * Индексы и поздние колонки: модуль должен быстро работать на росте данных.
     *
     * Владелец (03.10.2026): «в БД — чтобы не тормозило, когда ингредиентов
     * будут тысячи и сотни продуктов и сотни пользователей». Все запросы
     * списков и страниц уже идут по конкретным колонкам, и здесь для них
     * лежат индексы; DDL идемпотентный (IF NOT EXISTS), вызывается из
     * ensure_tables() один раз на запрос.
     *
     * Миграция 001 накатывает то же самое для новой базы — этот метод нужен
     * тем базам, которые уже живут на проде: у них таблицы есть, индексов и
     * колонки `product_kind` ещё нет.
     */
    public static function ensure_indexes()
    {
        if (self::$_indexes_ensured)
        {
            return;
        }

        self::$_indexes_ensured = TRUE;

        // Колонки появились вместе с этой правкой: если они есть, значит DDL
        // (а с ним и индексы) уже накатывали — второй раз не трогаем таблицы.
        // Проверяем САМЫЕ ПОЗДНИЕ из них: база, пережившая 003, колонку
        // `product_kind` уже имеет, и по ней новые поля товара не появятся;
        // `key_aliases` — самая поздняя (006), поэтому стоит в той же пробе.
        // Проверка — два SELECT, а не девять CREATE INDEX на каждый запрос.
        try
        {
            self::db()->query(Database::SELECT,
                'SELECT title_auto FROM nj_646_analyses LIMIT 1', FALSE);
            self::db()->query(Database::SELECT,
                'SELECT last_verdict, nutrition, key_aliases FROM nj_646_products LIMIT 1', FALSE);
            return;
        }
        catch (Throwable $e)
        {
            // Колонок нет — накатываем DDL (он идемпотентный).
        }

        try
        {
            self::db()->query(Database::SELECT, self::_indexes_ddl(), FALSE);
        }
        catch (Throwable $e)
        {
            self::$_indexes_ensured = FALSE;
            Kohana::$log->add(Log::WARNING, 'nj646: ensure_indexes DDL: :e',
                [':e' => $e->getMessage()]);
        }
    }

    /**
     * Поздние колонки и индексы — одно место (миграция 003 дублирует слово в
     * слово, как 002 и `_goods_ddl()`).
     *
     * Индекс — под конкретный запрос, без «на всякий случай»: разборы
     * посетителя (`ORDER BY id DESC`), часто встречающиеся вещества
     * (`status = published ORDER BY hits DESC`) и пресеты справочника
     * (`category/risk/source`), товары по встречаемости, очередь изучения
     * (`status, seen_count DESC`). Индексы по товару, производителю и названиям
     * уже стоят в `_goods_ddl()` — второй раз их не создаём.
     */
    public static function _indexes_ddl()
    {
        return <<<SQL
ALTER TABLE "nj_646_analyses" ADD COLUMN IF NOT EXISTS "product_kind" VARCHAR(24) NOT NULL DEFAULT '';
ALTER TABLE "nj_646_analyses" ADD COLUMN IF NOT EXISTS "title_auto" BOOLEAN NOT NULL DEFAULT FALSE;
-- Товар — главная сущность списков: у него свой тип продукта (иконка и подпись),
-- признак придуманного названия и итог последнего разбора. Так список показывает
-- «Зубная паста „Лесная“ — 78/100, спокойный», а не «63/100 · нейтрально».
ALTER TABLE "nj_646_products" ADD COLUMN IF NOT EXISTS "product_kind" VARCHAR(24) NOT NULL DEFAULT '';
ALTER TABLE "nj_646_products" ADD COLUMN IF NOT EXISTS "name_auto" BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE "nj_646_products" ADD COLUMN IF NOT EXISTS "last_analysis_id" BIGINT NULL;
ALTER TABLE "nj_646_products" ADD COLUMN IF NOT EXISTS "last_score" SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE "nj_646_products" ADD COLUMN IF NOT EXISTS "last_verdict" VARCHAR(12) NOT NULL DEFAULT '';
ALTER TABLE "nj_646_products" ADD COLUMN IF NOT EXISTS "analyses_count" INTEGER NOT NULL DEFAULT 0;
-- Пищевая ценность (для продуктов): таблица «белки/жиры/углеводы/калорийность»
-- с этикетки или от админа. JSONB, потому что полей десяток и половина бывает
-- пустой; `nutrition_source` отличает разобранное автоматически (label) от
-- правки руками (manual) — правку этикетка больше не перетирает.
ALTER TABLE "nj_646_products" ADD COLUMN IF NOT EXISTS "nutrition" JSONB NOT NULL DEFAULT '{}'::jsonb;
ALTER TABLE "nj_646_products" ADD COLUMN IF NOT EXISTS "nutrition_source" VARCHAR(12) NOT NULL DEFAULT '';
COMMENT ON COLUMN "nj_646_products"."nutrition" IS 'Пищевая ценность: {basis: 100g|100ml|portion, portion, values:{kcal,kj,protein,fat,fat_sat,carbs,sugars,fiber,salt,sodium}}';
COMMENT ON COLUMN "nj_646_products"."nutrition_source" IS 'label — разобрано с этикетки, manual — заполнено админом (этикеткой не перетирается)';
-- Прежние ключи товара: ключ считается от названия, а название правят руками и
-- уточняет модель. Без псевдонимов следующий разбор той же этикетки заводил
-- ВТОРОЙ товар, а статистика и пищевая ценность оставались на первом.
ALTER TABLE "nj_646_products" ADD COLUMN IF NOT EXISTS "key_aliases" JSONB NOT NULL DEFAULT '[]'::jsonb;
COMMENT ON COLUMN "nj_646_products"."key_aliases" IS 'Прежние ключи товара (после правки названия/бренда): по ним находится тот же товар, а не заводится дубль';
CREATE INDEX IF NOT EXISTS "nj_646_products_aliases_idx"
    ON "nj_646_products" USING GIN ("key_aliases");
CREATE INDEX IF NOT EXISTS "nj_646_analyses_person_idx"
    ON "nj_646_analyses" ("visitor", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_646_analyses_kind_idx"
    ON "nj_646_analyses" ("product_kind");
CREATE INDEX IF NOT EXISTS "nj_646_ingredients_status_idx"
    ON "nj_646_ingredients" ("status", "hits" DESC);
CREATE INDEX IF NOT EXISTS "nj_646_ingredients_category_idx"
    ON "nj_646_ingredients" ("category", "kind", "status");
CREATE INDEX IF NOT EXISTS "nj_646_ingredients_risk_idx"
    ON "nj_646_ingredients" ("risk", "status");
CREATE INDEX IF NOT EXISTS "nj_646_ingredients_source_idx"
    ON "nj_646_ingredients" ("source");
CREATE INDEX IF NOT EXISTS "nj_646_products_hits_idx"
    ON "nj_646_products" ("hits" DESC);
CREATE INDEX IF NOT EXISTS "nj_646_products_kind_idx"
    ON "nj_646_products" ("product_kind");
CREATE INDEX IF NOT EXISTS "nj_646_unknowns_queue_idx"
    ON "nj_646_unknowns" ("status", "seen_count" DESC, "id");
SQL;
    }

    /** Доступен ли vision-провайдер для воркера (по конфигу llm.providers). */
    public static function llm_ready()
    {
        try
        {
            $providers = Kohana::$config->load('llm.providers');

            foreach ((array) $providers as $provider)
            {
                $url = is_array($provider) ? (string) ($provider['base_url'] ?? '') : '';
                $key = is_array($provider) ? (string) ($provider['api_key'] ?? '') : '';

                if ($url !== '' AND $key !== '')
                {
                    return TRUE;
                }
            }
        }
        catch (Throwable $e)
        {
            // Конфиг LLM не критичен для страницы.
        }

        return FALSE;
    }

}
