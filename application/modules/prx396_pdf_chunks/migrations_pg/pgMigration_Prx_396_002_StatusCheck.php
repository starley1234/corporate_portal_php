<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRX-396/002: CHECK-ограничения статусов партии и частей.
 *
 * Зачем: без них опечатка в UPDATE пишет произвольную строку — status() и
 * plan() рассчитывают только на четыре известных значения, и витрина
 * «Мои задачи» показывает пустой статус. Паттерн prx110/008: DROP IF EXISTS
 * + ADD, чтобы накат был идемпотентен на стенде, где 001 уже применён.
 */
class pgMigration_Prx_396_002_StatusCheck extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE prx_396_batches
            DROP CONSTRAINT IF EXISTS prx_396_batches_status_check,
            ADD CONSTRAINT prx_396_batches_status_check CHECK (status IN (\'queued\', \'running\', \'done\', \'failed\'))');
        $this->run_query('ALTER TABLE prx_396_parts
            DROP CONSTRAINT IF EXISTS prx_396_parts_status_check,
            ADD CONSTRAINT prx_396_parts_status_check CHECK (status IN (\'pending\', \'running\', \'done\', \'failed\'))');
    }

    public function down()
    {
        $this->run_query('ALTER TABLE prx_396_batches DROP CONSTRAINT IF EXISTS prx_396_batches_status_check');
        $this->run_query('ALTER TABLE prx_396_parts DROP CONSTRAINT IF EXISTS prx_396_parts_status_check');
    }
}
