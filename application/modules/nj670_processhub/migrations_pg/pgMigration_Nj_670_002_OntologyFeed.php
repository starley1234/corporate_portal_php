<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ProcessHub (nj670): сырые данные → онтология и процессы.
 *
 * Один документ (регламент, описание отдела, набор заметок) прогоняется
 * моделью: она вытаскивает роли/системы/артефакты/подразделения и заготовки
 * процессов. Сайт хранит предложение отдельно от справочника: онтология
 * меняется только после явного «Принять» — иначе модель засоряла бы словарь
 * компании черновиками, на которые уже ссылаются схемы.
 *
 * Идемпотентно: повторный up на применённой схеме ничего не делает.
 */
class pgMigration_Nj_670_002_OntologyFeed extends pgMigration {

	public function up()
	{
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_ontology_jobs
		(
			id           bigserial PRIMARY KEY,
			workspace_id bigint NOT NULL REFERENCES nj_670_workspaces (id) ON DELETE CASCADE,
			user_id      integer NOT NULL DEFAULT 0,
			status       varchar(20) NOT NULL DEFAULT 'queued',
			source_kind  varchar(20) NOT NULL DEFAULT 'text',
			source_name  varchar(255) NOT NULL DEFAULT '',
			source_text  text NOT NULL DEFAULT '',
			source_path  varchar(255) NOT NULL DEFAULT '',
			job_id       bigint NULL,
			model        varchar(160) NOT NULL DEFAULT '',
			result       jsonb NOT NULL DEFAULT '{}'::jsonb,
			error        text NOT NULL DEFAULT '',
			accepted_at  timestamptz NULL,
			created_at   timestamptz NOT NULL DEFAULT now(),
			updated_at   timestamptz NOT NULL DEFAULT now()
		)");

		// Витрина и страница онтологии читают последнее предложение
		// пространства; отдельный индекс на статус — для диагностики.
		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_ontology_jobs_ws_idx
			ON nj_670_ontology_jobs (workspace_id, created_at DESC)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_ontology_jobs_status_idx
			ON nj_670_ontology_jobs (status)");
	}

	public function down()
	{
		$this->run_query("DROP TABLE IF EXISTS nj_670_ontology_jobs");
	}
}
