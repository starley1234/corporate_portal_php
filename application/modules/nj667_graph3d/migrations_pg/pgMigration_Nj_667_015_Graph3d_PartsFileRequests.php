<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 015: учитывать в журнале отдельные запросы STL-узлов.
 *
 * Запрос «Собрать узлы к печати» ставит отдельную задачу `kind=parts` и теперь
 * учитывается рядом с GLB/STL всего изделия после принятия задачи очередью.
 */
class pgMigration_Nj_667_015_Graph3d_PartsFileRequests extends pgMigration
{
	public function up()
	{
		$this->run_query('ALTER TABLE nj_667_build_file_requests
			DROP CONSTRAINT IF EXISTS nj_667_build_file_requests_file_kind_check');

		$this->run_query('ALTER TABLE nj_667_build_file_requests
			ADD CONSTRAINT nj_667_build_file_requests_file_kind_check
			CHECK (file_kind IN (\'glb\', \'stl\', \'parts\'))');

		$this->run_query('COMMENT ON TABLE nj_667_build_file_requests IS
			\'NJ-667: принятые в очередь запросы пользователей на GLB, STL изделия и STL-узлы; build_id указывает на снимок графа и его параметры\'');
	}

	public function down()
	{
		$this->run_query("DELETE FROM nj_667_build_file_requests WHERE file_kind = 'parts'");

		$this->run_query('ALTER TABLE nj_667_build_file_requests
			DROP CONSTRAINT IF EXISTS nj_667_build_file_requests_file_kind_check');

		$this->run_query('ALTER TABLE nj_667_build_file_requests
			ADD CONSTRAINT nj_667_build_file_requests_file_kind_check
			CHECK (file_kind IN (\'glb\', \'stl\'))');

		$this->run_query('COMMENT ON TABLE nj_667_build_file_requests IS
			\'NJ-667: принятые в очередь запросы пользователей на GLB/STL; build_id указывает на снимок графа и его параметры\'');
	}
}
