<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Права ProcessHub для запросов без сессии (Bearer-токен API, MCP-хаб).
 *
 * Контроллеры берут роли из Controller_Erp100_Base, а фоновому агенту
 * сессия недоступна: ему нужно то же самое решение «можно ли запускать
 * сборки», посчитанное по user_id из таблицы roles_users.
 */
class Service_Nj670_Access extends Service_Nj670_Repo {

	/**
	 * Имена ролей пользователя. Ошибка БД не должна превращаться в «разрешено»:
	 * пустой список = прав нет.
	 */
	public static function roles($user_id)
	{
		$user_id = (int) $user_id;

		if ($user_id <= 0)
		{
			return [];
		}

		try
		{
			$rows = self::_db()->query(Database::SELECT,
				'SELECT r.name FROM roles_users ru
				 JOIN roles r ON r.id = ru.role_id
				 WHERE ru.user_id = '.$user_id, FALSE)->as_array();

			$out = [];

			foreach ($rows as $row)
			{
				if (isset($row['name']) AND $row['name'] !== '')
				{
					$out[] = (string) $row['name'];
				}
			}

			return $out;
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::WARNING, 'nj670: роли пользователя :u: :e',
				[':u' => $user_id, ':e' => $e->getMessage()]);

			return [];
		}
	}

	public static function can($user_id, $permission)
	{
		$roles = self::roles($user_id);

		if ( ! $roles)
		{
			return FALSE;
		}

		return class_exists('ACL')
			? (bool) ACL::check($roles, (string) $permission)
			: in_array('admin', $roles, TRUE);
	}

	/** Запуск сборок/аудитов/отчётов — то же право, что у кнопок в интерфейсе. */
	public static function can_start($user_id)
	{
		return self::can($user_id, 'nj670.start');
	}
}
