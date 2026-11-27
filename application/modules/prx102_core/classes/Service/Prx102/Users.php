<?php defined('SYSPATH') or die('No direct script access.');

/** Публичные серверные чтения Auth. Роли читаются заново, без данных профиля. */
final class Service_Prx102_Users
{
    /**
     * Кандидаты роли для фоновых операций. Право на объект проверяет вызывающий модуль.
     * Без login пользователь заблокирован; удалённые пользователи не возвращаются.
     *
     * @return array<int, Prx_Actor> Полный набор ролей каждого кандидата, порядок по ID.
     */
    public static function actors_with_role(string $role): array
    {
        $role = trim($role);
        if ($role === '') return [];

        $rows = Prx_Db::select(
            'SELECT DISTINCT ru.user_id, r.name '
            .'FROM roles_users ru '
            .'JOIN users u ON u.id = ru.user_id '
            .'JOIN roles r ON r.id = ru.role_id '
            .'WHERE EXISTS ('
                .'SELECT 1 FROM roles_users candidate JOIN roles target ON target.id = candidate.role_id '
                .'WHERE candidate.user_id = ru.user_id AND target.name = :role'
            .') AND EXISTS ('
                .'SELECT 1 FROM roles_users active JOIN roles login ON login.id = active.role_id '
                ."WHERE active.user_id = ru.user_id AND login.name = 'login'"
            .') ORDER BY ru.user_id, r.name',
            [':role' => $role]
        );
        $roles_by_user = [];
        foreach ($rows as $row)
        {
            $id = (int) $row['user_id'];
            if ($id > 0) $roles_by_user[$id][] = (string) $row['name'];
        }

        $actors = [];
        foreach ($roles_by_user as $id => $roles) $actors[] = new Prx_Actor((int) $id, $roles);
        return $actors;
    }

    /**
     * Отображаемые имена для списков модулей («кто загрузил»): только ФИО
     * из профиля, без прочих полей. Неизвестный ID в ответ не попадает.
     *
     * @param  int[] $user_ids
     * @return array<int, string> user_id => ФИО
     */
    public static function display_names(array $user_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) return [];

        $names = [];
        foreach (Prx_Db::select(
            'SELECT user_id, full_name FROM user_profiles WHERE user_id IN :ids',
            [':ids' => $ids]
        ) as $row)
        {
            $name = trim((string) $row['full_name']);
            if ($name !== '') $names[(int) $row['user_id']] = $name;
        }
        return $names;
    }
}
