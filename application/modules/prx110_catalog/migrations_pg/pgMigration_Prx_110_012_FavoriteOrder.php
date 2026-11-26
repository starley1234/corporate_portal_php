<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRX-110/012: управление Избранным (prx113_favorites).
 *
 * sort — ручной порядок (prx113 переупорядочивает, шаг 10);
 * category — категория для группировки в левой панели;
 * note — заметка «зачем это здесь».
 */
class pgMigration_Prx_110_012_FavoriteOrder extends pgMigration
{
    public function up()
    {
        $this->run_query("ALTER TABLE prx_110_favorites
            ADD COLUMN IF NOT EXISTS sort INTEGER NULL,
            ADD COLUMN IF NOT EXISTS category VARCHAR(80) NULL,
            ADD COLUMN IF NOT EXISTS note VARCHAR(300) NULL");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_110_favorites_sort_idx
            ON prx_110_favorites (user_id, sort)");
    }

    public function down()
    {
        $this->run_query("DROP INDEX IF EXISTS prx_110_favorites_sort_idx");
        $this->run_query("ALTER TABLE prx_110_favorites
            DROP COLUMN IF EXISTS sort,
            DROP COLUMN IF EXISTS category,
            DROP COLUMN IF EXISTS note");
    }
}
