<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100: «Проверка сервера» /prx100/selfcheck — кнопка вместо консоли.
 *
 * 25.11.2026: миграция Д1 сломала два файла (prx390 Intake, prx103 Errors)
 * так, что парность кавычек не ловилась, а прод упал с ParseError. Статические
 * гейты (make check / make lint) живут в репозитории и требуют shell; на
 * развёрнутом сервере администратору нечем было быстро проверить деплой.
 *
 * Страница выполняет Service_Prx103_Doctor::run(TRUE): среда, .env, каталоги,
 * PostgreSQL, миграции, очередь, доки модулей — и php -l по всему коду.
 * Проверки только читают состояние; php -l компилирует файлы без исполнения.
 * POST, а не GET: прогон заметно дольше обычного запроса и не должен
 * запускаться случайным переходом по ссылке. Доступ — право prx100.admin.
 */
class Controller_Prx103_Selfcheck extends Controller_Prx102_Base {

	public function before()
	{
		parent::before();

		if ( ! $this->can('prx100.admin'))
		{
			throw HTTP_Exception::factory(403, 'Только для администратора');
		}

		$this->set_title('Проверка сервера');
		$this->set_module_nav([
			['title' => 'Дашборд', 'href' => '/dashboard', 'icon' => 'fas fa-gauge-high'],
			['title' => 'Чистка данных', 'href' => '/prx100/cleanup', 'icon' => 'fas fa-broom'],
			['title' => 'Хвост ошибок', 'href' => '/prx100/errors', 'icon' => 'fas fa-heart-crack'],
			['title' => 'Логи', 'href' => '/prx100/logs', 'icon' => 'fas fa-terminal'],
			['title' => 'События', 'href' => '/prx208_events', 'icon' => 'fas fa-bolt'],
			['title' => 'Все сервисы', 'href' => '/services', 'icon' => 'fas fa-grip'],
		]);
	}

	public function action_index()
	{
		$results = NULL;
		$flash   = '';

		if ($this->request->method() === Request::POST)
		{
			if ( ! Security::check((string) $this->request->post('csrf')))
			{
				$flash = 'Сессия устарела — попробуйте ещё раз';
			}
			else
			{
				// php -l по всему коду дольше обычного запроса
				if (function_exists('set_time_limit'))
				{
					@set_time_limit(0);
				}

				try
				{
					$results = Service_Prx103_Doctor::run(TRUE);
				}
				catch (Throwable $e)
				{
					Kohana::$log->add(Log::ERROR, 'prx100 selfcheck: :e', [':e' => $e->getMessage()]);
					$flash = 'Проверка упала: '.$e->getMessage();
				}
			}
		}

		$this->template->content = View::factory('prx103/selfcheck', [
			'results' => $results,
			'flash'   => $flash,
			'csrf'    => Security::token(),
		]);
	}
}
