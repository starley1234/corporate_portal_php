<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Модуль «Вакансии компаний» (nj642_vacancies).
 *
 * Зачем (владелец, 26.09.2026): «в обход страниц также смотри страницы „Карьера“
 * „Вакансии“ „О компании“ (для сбора информации о вакансиях с сайта работодателя)
 * — эта важная информация, её можно обновлять и хранить историю появления и
 * исчезновения вакансий, по вакансиям от работодателя можно сделать отдельный
 * функционал: указать город, направление и мониторить изменение вакансий на
 * сайтах компаний, с уведомлением письмом».
 *
 * Что здесь:
 *   nj_642_watches   — мониторы («город + направление + компании»), у каждого свой
 *                      владелец, пауза и переключатель уведомлений;
 *   nj_642_vacancies — вакансии КОМПАНИИ (одна запись на вакансию, сколько бы
 *                      сборов её ни встретило): источник, город, направление,
 *                      зарплата, ссылка, отпечаток для дедупликации;
 *   nj_642_events    — история: появилась / исчезла / вернулась / обновлена;
 *   nj_642_matches   — «какая вакансия подошла какому монитору» + оценка и
 *                      пометка «уведомили».
 *
 * Ничего не выдумывается: в базу попадает только то, что воркер прочитал на
 * сайте работодателя (анти-галлюцинации в промпте + отпечаток + ссылка).
 */
class pgMigration_Nj_642_001_Vacancies extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_642_watches" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(16)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "title"       VARCHAR(200) NOT NULL DEFAULT '',
    "city"        VARCHAR(120) NOT NULL DEFAULT '',
    "direction"   VARCHAR(200) NOT NULL DEFAULT '',
    "keywords"    VARCHAR(300) NOT NULL DEFAULT '',
    "companies"   JSONB        NULL,
    "enabled"     BOOLEAN      NOT NULL DEFAULT TRUE,
    "notify"      BOOLEAN      NOT NULL DEFAULT TRUE,
    "min_score"   INTEGER      NOT NULL DEFAULT 55,
    "last_run_at" TIMESTAMPTZ  NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL
        )->execute();

        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_642_vacancies" (
    "id"           BIGSERIAL PRIMARY KEY,
    "company_key"  VARCHAR(180) NOT NULL DEFAULT '',
    "company"      VARCHAR(200) NOT NULL DEFAULT '',
    "profile_code" VARCHAR(16)  NOT NULL DEFAULT '',
    "title"        VARCHAR(300) NOT NULL DEFAULT '',
    "city"         VARCHAR(120) NOT NULL DEFAULT '',
    "direction"    VARCHAR(120) NOT NULL DEFAULT '',
    "salary"       VARCHAR(120) NOT NULL DEFAULT '',
    "url"          VARCHAR(600) NOT NULL DEFAULT '',
    "source_url"   VARCHAR(600) NOT NULL DEFAULT '',
    "snippet"      VARCHAR(1000) NOT NULL DEFAULT '',
    "fingerprint"  VARCHAR(64)  NOT NULL UNIQUE,
    "status"       VARCHAR(12)  NOT NULL DEFAULT 'active',
    "seen_count"   INTEGER      NOT NULL DEFAULT 1,
    "first_seen_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    "last_seen_at"  TIMESTAMPTZ NOT NULL DEFAULT now(),
    "gone_at"       TIMESTAMPTZ NULL,
    "updated_at"    TIMESTAMPTZ NOT NULL DEFAULT now()
)
SQL
        )->execute();

        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_642_events" (
    "id"         BIGSERIAL PRIMARY KEY,
    "vacancy_id" BIGINT       NOT NULL,
    "watch_id"   BIGINT       NOT NULL DEFAULT 0,
    "kind"       VARCHAR(16)  NOT NULL DEFAULT '',
    "note"       VARCHAR(300) NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL
        )->execute();

        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_642_matches" (
    "id"          BIGSERIAL PRIMARY KEY,
    "watch_id"    BIGINT       NOT NULL,
    "vacancy_id"  BIGINT       NOT NULL,
    "score"       INTEGER      NOT NULL DEFAULT 0,
    "why"         VARCHAR(300) NOT NULL DEFAULT '',
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "notified_at" TIMESTAMPTZ  NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL
        )->execute();

        $indexes = [
            'CREATE UNIQUE INDEX IF NOT EXISTS "nj_642_matches_uniq" ON "nj_642_matches" ("watch_id", "vacancy_id")',
            'CREATE INDEX IF NOT EXISTS "nj_642_watches_user_idx" ON "nj_642_watches" ("user_id", "id" DESC)',
            'CREATE INDEX IF NOT EXISTS "nj_642_vacancies_company_idx" ON "nj_642_vacancies" ("company_key", "status", "id" DESC)',
            'CREATE INDEX IF NOT EXISTS "nj_642_vacancies_seen_idx" ON "nj_642_vacancies" ("status", "last_seen_at" DESC)',
            'CREATE INDEX IF NOT EXISTS "nj_642_events_vacancy_idx" ON "nj_642_events" ("vacancy_id", "id" DESC)',
            'CREATE INDEX IF NOT EXISTS "nj_642_matches_watch_idx" ON "nj_642_matches" ("watch_id", "status", "id" DESC)',
        ];

        foreach ($indexes as $sql)
        {
            DB::query(NULL, $sql)->execute();
        }

        $comments = [
            "COMMENT ON TABLE \"nj_642_vacancies\" IS 'nj642_vacancies: вакансии с сайтов работодателей (только прочитанное воркером)'",
            "COMMENT ON TABLE \"nj_642_events\" IS 'nj642_vacancies: история появления/исчезновения вакансий'",
            "COMMENT ON TABLE \"nj_642_watches\" IS 'nj642_vacancies: мониторы вакансий (город + направление + компании)'",
        ];

        foreach ($comments as $sql)
        {
            DB::query(NULL, $sql)->execute();
        }
    }

    public function down()
    {
        foreach (['nj_642_matches', 'nj_642_events', 'nj_642_vacancies', 'nj_642_watches'] as $table)
        {
            DB::query(NULL, 'DROP TABLE IF EXISTS "'.$table.'"')->execute();
        }
    }
}
