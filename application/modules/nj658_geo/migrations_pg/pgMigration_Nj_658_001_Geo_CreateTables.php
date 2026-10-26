<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-658 «Города и посёлки»: таблица справочника и сид из source_data.
 *
 * nj_658_cities — населённые пункты России (2020 записей из дампа старого
 * справочника, migrations_pg/data/nj658_cities_seed.sql). Сид выполняется
 * порциями, оператор за оператором, и идемпотентен (ON CONFLICT DO NOTHING).
 *
 * title_norm — поисковый ключ (нижний регистр, ё→е): поиск «москв» находит
 * и «Москва», а запрос с «е» — города с «ё» в названии.
 */
class pgMigration_Nj_658_001_Geo_CreateTables extends pgMigration
{
	public function up()
	{
		$this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_658_cities" (
	id          BIGSERIAL PRIMARY KEY,
	slug        VARCHAR(60)  NOT NULL UNIQUE,
	title       VARCHAR(100) NOT NULL,
	title_norm  VARCHAR(100) NOT NULL,
	title_full  VARCHAR(160) NOT NULL DEFAULT '',
	name_latin  VARCHAR(60)  NOT NULL DEFAULT '',
	region      VARCHAR(120) NOT NULL DEFAULT '',
	district    VARCHAR(140) NOT NULL DEFAULT '',
	kind        VARCHAR(40)  NOT NULL DEFAULT '',
	population  INT          NOT NULL DEFAULT 0,
	is_dup      BOOLEAN      NOT NULL DEFAULT FALSE,
	lat         NUMERIC(9,6) NOT NULL DEFAULT 0,
	lon         NUMERIC(9,6) NOT NULL DEFAULT 0,
	is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
	source      VARCHAR(20)  NOT NULL DEFAULT 'seed',
	created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
	updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS "nj_658_cities_title_norm_idx" ON "nj_658_cities" (title_norm);
CREATE INDEX IF NOT EXISTS "nj_658_cities_region_idx" ON "nj_658_cities" (region);
CREATE INDEX IF NOT EXISTS "nj_658_cities_population_idx" ON "nj_658_cities" (population DESC);
SQL);

		// Сид: дамп source_data/0_loc_rus.sql, преобразованный в INSERT-порции.
		// run_script режет по «;» вне строковых литералов (в названиях городов
		// кавычки безопаснее не доверять explode).
		$seed_file = __DIR__.'/data/nj658_cities_seed.sql';

		if (is_file($seed_file))
		{
			$this->run_script((string) file_get_contents($seed_file));
		}
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS "nj_658_cities" CASCADE;');
	}
}
