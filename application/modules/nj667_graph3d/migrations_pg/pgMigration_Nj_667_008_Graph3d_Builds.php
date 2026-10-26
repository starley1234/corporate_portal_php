<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 008: сборки графа в изделие (просьба владельца, 12.10.2026).
 *
 * Проект описывает конструкцию графом; «сборка» — это снимок того же графа с
 * выбранным типом узла (палочки от мороженого / шкант / крест / труба) и
 * посадками. Храним именно снимок, а не «последние параметры проекта», по двум
 * причинам:
 *
 *   1) STL и спецификация должны описывать ОДНУ и ту же геометрию. Граф правят
 *      с холста в любой момент — если держать только текущий, страница начнёт
 *      врать про уже отрендеренную модель;
 *   2) сборка — артефакт (её скачивают, по ней печатают), а артефакт без
 *      истории невозможно объяснить: «откуда 25 шт.?».
 *
 * Сами файлы (.scad и .stl) в базу не едут — путь и контрольная сумма; лежат
 * рядом с кадрами проекта в storage/nj667/<code>/ (урок nj646: бинарь в jsonb
 * раздувает очередь и базу).
 */
class pgMigration_Nj_667_008_Graph3d_Builds extends pgMigration
{
	public function up()
	{
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_667_builds (
			id           BIGSERIAL    PRIMARY KEY,
			project_id   BIGINT       NOT NULL,
			user_id      INTEGER      NOT NULL DEFAULT 0,
			revision_id  BIGINT       NOT NULL DEFAULT 0,
			status       VARCHAR(16)  NOT NULL DEFAULT \'spec\',
			type_key     VARCHAR(24)  NOT NULL DEFAULT \'flat\',
			graph_hash   VARCHAR(64)  NOT NULL DEFAULT \'\',
			topo_hash    VARCHAR(64)  NOT NULL DEFAULT \'\',
			params_json  JSONB        NULL,
			spec_json    JSONB        NULL,
			stats_json   JSONB        NULL,
			scad_path    VARCHAR(200) NOT NULL DEFAULT \'\',
			scad_size    INTEGER      NOT NULL DEFAULT 0,
			stl_path     VARCHAR(200) NOT NULL DEFAULT \'\',
			stl_size     INTEGER      NOT NULL DEFAULT 0,
			stl_sha      VARCHAR(64)  NOT NULL DEFAULT \'\',
			error        VARCHAR(500) NOT NULL DEFAULT \'\',
			job_id       BIGINT       NOT NULL DEFAULT 0,
			render_ms    INTEGER      NOT NULL DEFAULT 0,
			created_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
			done_at      TIMESTAMPTZ  NULL
		)');

		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_builds_project_idx
			ON nj_667_builds (project_id, id DESC)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_builds_job_idx
			ON nj_667_builds (job_id)');

		$this->run_query('COMMENT ON TABLE nj_667_builds IS \'NJ-667 сборки графа в изделие: тип узла, посадки, спецификация, путь к .scad и .stl\'');
		$this->run_query('COMMENT ON COLUMN nj_667_builds.status IS \'spec — есть спецификация; queued — ждёт рендера; done — STL готов; error — рендер не удался\'');
		$this->run_query('COMMENT ON COLUMN nj_667_builds.graph_hash IS \'sha1 канонического графа: по нему видно, что граф уехал вперёд и сборку надо повторить\'');
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_667_builds');
	}
}
