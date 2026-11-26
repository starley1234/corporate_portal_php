<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx102: ресурс позиционируется как «Платформа», видимые строки приведены
 * к новой формулировке. Первичный администратор из prx102_004 получал
 * full_name по старой формулировке; переименовываем в БД.
 * down() пуст: обратное переименование вернуло бы и профилям, созданным
 * вручную, старую формулировку.
 */
class pgMigration_Prx_102_007_AdminProfileNaming extends pgMigration {

    public function up()
    {
        $this->run_query("UPDATE user_profiles SET full_name = 'Администратор платформы'
            WHERE full_name = 'Администратор портала'");
    }

    public function down()
    {
        // переименование не откатываем
    }
}
