<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Создание пользователя из CLI.
 *
 *   bin/console auth:user --email=admin@prx.ru --password=admin123 --username=admin --admin
 *
 * Задача упомянута в README/Makefile/bin/restore-env.sh (make seed) — раньше
 * класса не существовало и команда падала «not a valid minion task».
 * Логика создания — как в prx595 (ORM Auth сам хэширует пароль при записи).
 *
 * @package Minion
 */
class Task_Auth_User extends Minion_Task {

	protected $_options = [
		'email'    => NULL,
		'username' => NULL,
		'password' => NULL,
		'admin'    => FALSE,
	];

	protected function _execute(?array $options = NULL)
	{
		$email    = trim((string) Arr::get($options, 'email'));
		$username = trim((string) Arr::get($options, 'username'));
		$password = (string) Arr::get($options, 'password');
		// Особенность Minion: голый «--admin» приходит как NULL (не TRUE),
		// отсутствующий флаг — FALSE (дефолт из $_options), «--admin=0» — '0'.
		// Скобки обязательны: OR слабее присваивания, поэтому без них значение
		// флага вообще не учитывалось — «--admin=1» не давал прав.
		$as_admin = (Arr::get($options, 'admin') === NULL
			OR in_array((string) Arr::get($options, 'admin'), ['1', 'true', 'yes', 'on'], TRUE));

		if ( ! filter_var($email, FILTER_VALIDATE_EMAIL))
		{
			Minion_CLI::write('✘ Email выглядит некорректно: "'.$email.'"');

			return 1;
		}

		if (mb_strlen($username) < 2)
		{
			Minion_CLI::write('✘ Имя пользователя — минимум 2 символа');

			return 1;
		}

		if (mb_strlen($password) < 6)
		{
			Minion_CLI::write('✘ Пароль — минимум 6 символов');

			return 1;
		}

		$exists = Prx_Db::select(
			'SELECT id FROM users WHERE email = :email OR username = :username LIMIT 1',
			[':email' => $email, ':username' => $username]);

		if (count($exists) > 0)
		{
			Minion_CLI::write('✘ Пользователь с таким email или username уже есть');

			return 1;
		}

		try
		{
			$user = ORM::factory('User');
			$user->email    = $email;
			$user->username = $username;
			$user->password = $password; // ORM Auth сам хэширует при записи
			// CLI-созданный пользователь (seed админа) без письма-подтверждения:
			// без этого логин блокируется «подтвердите ваш email».
			$user->email_confirmed = 1;
			$user->create();

			Prx_Db::execute(
				'INSERT INTO roles_users (user_id, role_id)
				 SELECT :uid, id FROM roles WHERE name = \'login\'',
				[':uid' => (int) $user->id]);

			if ($as_admin)
			{
				Prx_Db::execute(
					'INSERT INTO roles_users (user_id, role_id)
					 SELECT :uid, id FROM roles WHERE name = \'admin\'',
					[':uid' => (int) $user->id]);
			}

			Minion_CLI::write('✔ Пользователь создан: '.$username.' (id '.$user->id.')'
				.($as_admin ? ' · роль admin' : ''));

			return 0;
		}
		catch (ORM_Validation_Exception $e)
		{
			Minion_CLI::write('✘ Валидация: '.implode('; ', Arr::flatten($e->errors('models', 'ru'))));

			return 1;
		}
		catch (Throwable $e)
		{
			Minion_CLI::write('✘ '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());

			return 1;
		}
	}
}
