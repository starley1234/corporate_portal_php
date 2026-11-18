<?php defined('SYSPATH') or die('No direct script access.');

/** Личные закладки каталога; удаляются вместе с учётной записью. */
class pgMigration_Prx_110_005_Favorites extends pgMigration
{
    public function up()
    {
        $this->run_query('CREATE TABLE IF NOT EXISTS prx_110_favorites (
            id BIGSERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            href VARCHAR(512) NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            CONSTRAINT prx_110_favorites_user_href_uq UNIQUE (user_id, href)
        )');
        $this->run_query('CREATE INDEX IF NOT EXISTS prx_110_favorites_user_order_idx
            ON prx_110_favorites (user_id, id)');
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_110_favorites');
    }
}
