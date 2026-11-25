<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100: «Проверка сервера» /prx100/selfcheck — кнопка вместо консоли.
 *
 * 25.11.2026: миграция Д1 сломала два файла (prx390 Intake, prx103 Errors)
 * так, что парность кавычек не ловилась, а прод упал с ParseError. Статические
 * гейты (make check / make lint) живут в репозитории и требуют shell; на
 * развёрнутом сервере администратору нечем было быстро проверить деплой.
 *
 * Механика (прод 25.11.2026: 750+ файлов одним запросом = 502 от nginx):
 *  - POST /prx100/selfcheck — быстрые проверки (среда, БД, миграции, очередь,
 *    каталоги) и подготовка: полный список php-файлов кладётся в СЕССИЮ
 *    (пути никогда не приходят от клиента), страница отдаёт общее число;
 *  - POST /prx100/selfcheck/lint — AJAX-порции: offset/count в границах
 *    сохранённого списка, Service_Prx103_Doctor::lint_chunk() компилирует
 *    порцию одним дочерним процессом, JSON уходит браузеру, тот продолжает
 *    до конца — каждый запрос укладывается в таймауты хостинга.
 * Проверки только читают состояние; php -l компилирует файлы без исполнения.
 * CSRF: Prx_PostGuard (токен формы / глобальный fetch-хук X-CSRF-Token).
 * Доступ — право prx100.admin.
 */
class Controller_Prx103_Selfcheck extends Controller_Prx102_Base {

	/** Границы размера порции lint-запроса. */
	private const CHUNK_MIN = 10;
	private const CHUNK_MAX = 80;

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
			['title' => 'Проверка сервера', 'href' => '/prx100/selfcheck', 'icon' => 'fas fa-clipboard-check'],
			['title' => 'События', 'href' => '/prx208_events', 'icon' => 'fas fa-bolt'],
			['title' => 'Все сервисы', 'href' => '/services', 'icon' => 'fas fa-grip'],
		]);
	}

	public function action_index()
	{
		$results   = NULL;
		$flash     = '';
		$lint_note = '';
		$lint_max  = 0;

		if ($this->request->method() === Request::POST)
		{
			if ( ! Security::check((string) $this->request->post('csrf')))
			{
				$flash = 'Сессия устарела — попробуйте ещё раз';
			}
			else
			{
				try
				{
					$results = Service_Prx103_Doctor::run();

					// Список файлов живёт в сессии: клиент шлёт только offset/count
					$files = Service_Prx103_Doctor::php_files();
					Session::instance()->set('prx100_selfcheck_files', $files);
					$lint_max = count($files);

					if ($lint_max === 0)
					{
						$lint_note = 'php-файлы не найдены — компиляция пропущена';
					}
				}
				catch (Throwable $e)
				{
					Kohana::$log->add(Log::ERROR, 'prx100 selfcheck: :e', [':e' => $e->getMessage()]);
					$flash = 'Проверка упала: '.$e->getMessage();
				}
			}
		}

		$this->template->content = View::factory('prx103/selfcheck', [
			'results'   => $results,
			'flash'     => $flash,
			'csrf'      => Security::token(),
			'lint_max'  => $lint_max,
			'lint_note' => $lint_note,
		]);
	}

	/** AJAX-порция компиляции; вызывается страницей до исчерпания списка. */
	public function action_lint()
	{
		$this->auto_render = FALSE;

		if ($this->request->method() !== Request::POST)
		{
			$this->_json_response(['ok' => FALSE, 'note' => 'нужен POST'], 405);
			return;
		}

		$session = Session::instance();
		$files   = $session->get('prx100_selfcheck_files');
		$total   = is_array($files) ? count($files) : 0;

		if ($total === 0)
		{
			$this->_json_response(['ok' => FALSE, 'note' => 'список устарел — запустите проверку заново'], 409);
			return;
		}

		$offset = max(0, min($total, (int) $this->request->post('offset')));
		$count  = max(self::CHUNK_MIN, min(self::CHUNK_MAX, (int) $this->request->post('count')));
		$slice  = array_slice($files, $offset, $count);

		$res = Service_Prx103_Doctor::lint_chunk($slice);

		if ( ! $res['ok'])
		{
			$this->_json_response(['ok' => FALSE, 'note' =>
				'CLI-интерпретатор PHP не найден или proc_open запрещён — '
				.'запустите проверку по SSH: make lint'], 200);
			return;
		}

		$bad = [];
		foreach ($res['bad'] as [$file, $error])
		{
			$bad[] = [
				'file' => basename((string) $file),
				'text' => Service_Prx103_Doctor::lint_error_text((string) $error),
			];
		}

		$this->_json_response([
			'ok'         => TRUE,
			'total'      => $total,
			'done'       => min($total, $offset + count($slice)),
			'linted'     => $res['total'],
			'bad'        => $bad,
			'finished'   => ($offset + count($slice)) >= $total,
		]);
	}
}
