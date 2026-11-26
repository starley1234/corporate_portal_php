<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRX-110/011: пользовательские пункты левой панели.
 *
 * Администратор добавляет их со страницы /services (карточка «Меню
 * «Сервисы»»): пункт попадает в категорию «Сервисы» сайдбара ВСЕМ
 * сотрудникам и в палитру «Все сервисы». Ссылка — только внутренний путь.
 */
class pgMigration_Prx_110_011_MenuItems extends pgMigration
{
    public function up()
    {
        $this->run_query("CREATE TABLE IF NOT EXISTS prx_110_menu_items (
            id         BIGSERIAL PRIMARY KEY,
            title      VARCHAR(120) NOT NULL,
            href       VARCHAR(300) NOT NULL UNIQUE,
            icon       VARCHAR(64)  NOT NULL DEFAULT 'fas fa-arrow-up-right-from-square',
            sort       INT          NOT NULL DEFAULT 0,
            created_by BIGINT       NULL,
            created_at TIMESTAMPTZ  NOT NULL DEFAULT now()
        )");
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prx_110_menu_items");
    }
}
