<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 002: настройки модуля (выбор модели генерации).
 *
 * Повод (10.10.2026, владелец): модель, которой собирается граф, должна
 * выбираться в админке, а не только переменной воркера. Храним ключ/значение
 * отдельно от проектов — это не данные пользователя, а конфигурация сервиса.
 *
 * Выбранная спецификация (`провайдер::модель`) уезжает в задачу воркера
 * полем `model`. Если в воркере задан `NJ667_MODEL`, он важнее этой строки.
 */
class pgMigration_Nj_667_002_Graph3d_Settings extends pgMigration
{
	public function up()
	{
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_667_settings (
			key        VARCHAR(64)  PRIMARY KEY,
			value      TEXT         NOT NULL DEFAULT \'\',
			updated_at TIMESTAMPTZ  NOT NULL DEFAULT now()
		)');

		$this->run_query('COMMENT ON TABLE nj_667_settings IS \'NJ-667 настройки модуля: выбранная модель генерации и прочие ключи админки\'');
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_667_settings');
	}
}
