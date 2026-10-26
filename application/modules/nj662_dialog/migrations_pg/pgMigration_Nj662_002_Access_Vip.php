<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция NJ-662 (2): роль vip — «можно запускать диалог двух ИИ».
 *
 * Зачем роль, а не просто «все зарегистрированные»: каждый обмен диалога — это
 * два вызова модели, очередь задач nj120 и, если разрешён поиск, выход в
 * интернет. Запуск стоит денег и времени воркера, поэтому владелец отдаёт его
 * привилегированным аккаунтам, а чтение опубликованных разговоров остаётся
 * открытым для всех (решение владельца 09.10.2026).
 *
 * Роль одна на всю платформу (таблица roles ядра erp100_base), но заводит её
 * модуль, которому она нужна: так миграция самодостаточна, а откат удаляет
 * только то, что создал.
 *
 * Выдаётся роль без правки кода: /nj595/admin → «роль пользователя» (там же
 * список всех ролей из таблицы) — отдельной страницы под vip нет.
 *
 * Проверка права — ключ 'nj662.start' в application/config/acl.php:
 *   'nj662.start' => ['admin', 'vip'],   админ получает доступ автоматически.
 */
class pgMigration_Nj662_002_Access_Vip extends pgMigration {

    public function up()
    {
        // Повторный запуск миграции не ломает ничего: роль уже есть — тихо.
        $this->run_query("INSERT INTO roles (name, description) VALUES
            ('vip', 'Privileged user: may start and steer AI dialogs (nj662).')
            ON CONFLICT (name) DO NOTHING");
    }

    public function down()
    {
        // Сначала снимаем роль с людей (иначе строки roles_users висели бы на
        // несуществующей роли), потом убираем саму роль.
        $this->run_query("DELETE FROM roles_users
            WHERE role_id IN (SELECT id FROM roles WHERE name = 'vip')");

        $this->run_query("DELETE FROM roles WHERE name = 'vip'");
    }
}
