<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-520: закрытие опросов. closed_at IS NULL — опрос активен (можно голосовать),
 * NOT NULL — закрыт (голос запрещён, результаты остаются доступны).
 */
class pgMigration_Nj_520_003_Poll_Closed_At extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE "nj_520_polls" ADD COLUMN IF NOT EXISTS "closed_at" TIMESTAMPTZ NULL;');
    }

    public function down()
    {
        $this->run_query('ALTER TABLE "nj_520_polls" DROP COLUMN IF EXISTS "closed_at";');
    }
}
