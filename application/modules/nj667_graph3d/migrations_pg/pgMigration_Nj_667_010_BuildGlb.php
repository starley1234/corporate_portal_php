<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 010: у сборки появляется GLB для браузера.
 *
 * Зачем (12.10.2026): STL человек открывает в слайсере, а «посмотреть на
 * конструкцию» должен уметь сам сайт. Воркер конвертирует STL в glTF
 * (бинарник, вершинные нормали), страница отдаёт его в three.js — там модель
 * вдвое легче и читается без парсера 50-байтных граней. Печатный источник
 * при этом остаётся STL: glb_path — второй файл, а не замена stl_path.
 */
class pgMigration_Nj_667_010_BuildGlb extends pgMigration
{
	public function up()
	{
		$this->run_query('ALTER TABLE nj_667_builds
			ADD COLUMN IF NOT EXISTS glb_path VARCHAR(200) NOT NULL DEFAULT \'\',
			ADD COLUMN IF NOT EXISTS glb_size INTEGER NOT NULL DEFAULT 0');

		$this->run_query("COMMENT ON COLUMN nj_667_builds.glb_path IS
			'путь к glTF сборки (просмотр в браузере); пусто — конвертер не отработал'");
		$this->run_query("COMMENT ON COLUMN nj_667_builds.glb_size IS
			'размер glb в байтах; по нему страница решает, что показывать'");
	}

	public function down()
	{
		// Столбцы не трогаем: они NULL-безопасны, а сборкам с glb путь нужен.
	}
}
