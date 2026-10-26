<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 009: узлы к печати (долг сборки, 12.10.2026).
 *
 * STL всей конструкции хорош для проверки «соберётся ли», но в цех несут
 * детали: узел №3 в четырёх копиях, палочки листом. На каждый уникальный тип
 * узла — свой рендер (mode="single" в generators/assembly.scad), свой файл и
 * своя судьба: один тип мог не собраться, когда остальные уже готовы, и
 * перечитывать из-за этого всю сборку нельзя.
 *
 * Отсюда и таблица, а не jsonb в сборке: строку можно пометить отдельной
 * ошибкой, отдельным job_id и перебрать выборочно («доделать узлы»), не
 * трогая готовые файлы.
 */
class pgMigration_Nj_667_009_Graph3d_BuildParts extends pgMigration
{
	public function up()
	{
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_667_build_parts (
			id           BIGSERIAL    PRIMARY KEY,
			build_id     BIGINT       NOT NULL,
			part_index   SMALLINT     NOT NULL DEFAULT 0,
			type_key     VARCHAR(24)  NOT NULL DEFAULT \'flat\',
			title        VARCHAR(80)  NOT NULL DEFAULT \'\',
			copies       INTEGER      NOT NULL DEFAULT 1,
			status       VARCHAR(16)  NOT NULL DEFAULT \'spec\',
			scad_path    VARCHAR(200) NOT NULL DEFAULT \'\',
			stl_path     VARCHAR(200) NOT NULL DEFAULT \'\',
			stl_size     INTEGER      NOT NULL DEFAULT 0,
			stl_sha      VARCHAR(64)  NOT NULL DEFAULT \'\',
			render_ms    INTEGER      NOT NULL DEFAULT 0,
			job_id       BIGINT       NOT NULL DEFAULT 0,
			error        VARCHAR(500) NOT NULL DEFAULT \'\',
			created_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
			done_at      TIMESTAMPTZ  NULL
		)');

		// Один тип узла — одна строка сборки: «доделать» не должно находить два
		// ответа на один вопрос.
		$this->run_query('CREATE UNIQUE INDEX IF NOT EXISTS nj_667_build_parts_uniq
			ON nj_667_build_parts (build_id, part_index)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_build_parts_status_idx
			ON nj_667_build_parts (build_id, status)');

		$this->run_query('COMMENT ON TABLE nj_667_build_parts IS \'NJ-667 узлы сборки к печати: .scad и .stl на каждый уникальный тип детали\'');
		$this->run_query('COMMENT ON COLUMN nj_667_build_parts.part_index IS \'индекс уникального типа: он же UNIQ[part] в scad, он же строка спецификации\'');
		$this->run_query('COMMENT ON COLUMN nj_667_build_parts.status IS \'spec — есть чертёж, STL не просили; queued — ждёт рендера; done — файл на диске; error — рендер не удался\'');
		$this->run_query('COMMENT ON COLUMN nj_667_build_parts.copies IS \'сколько таких деталей в изделии — для слайсера и человека, файл всегда одна\'');
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_667_build_parts');
	}
}
