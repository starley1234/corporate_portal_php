<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Пин «звездой» теперь доступен на любой странице (page_header), а не только
 * на сервисах каталога. Для подстраниц метаданных в каталоге нет — храним их
 * при закреплении (title/icon от страницы через data-nav-pin-*).
 */
class pgMigration_Prx_110_010_FavoriteMeta extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE prx_110_favorites
            ADD COLUMN IF NOT EXISTS title VARCHAR(200) NULL,
            ADD COLUMN IF NOT EXISTS icon VARCHAR(64) NULL');
    }

    public function down()
    {
        $this->run_query('ALTER TABLE prx_110_favorites
            DROP COLUMN IF EXISTS title,
            DROP COLUMN IF EXISTS icon');
    }
}
