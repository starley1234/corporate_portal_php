<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-665 «Озвучивание текста» — страницы.
 *
 *   GET  /voice            — форма, плеер и история озвучек;
 *   POST /voice            — то же без JS (сервер сам синтезирует);
 *   GET  /voice/f/<code>   — сам аудиофайл (по коду из журнала).
 *
 * Синтез требует аккаунта: внешний API платный и считает секунды GPU, так что
 * гость видит плашку Gate::wall, а не кнопку. JSON-вариант для скриптов и
 * агентов — Controller_Nj665_Api (/voice/api/*).
 */
class Controller_Nj665_Voice extends Controller_Erp100_Base
{
	public function before()
	{
		parent::before();

		$this->set_title('Озвучивание текста онлайн: голосом из текста — mp3, wav, opus');
		$this->set_description('Напечатайте текст — получите аудиофайл: выбор голоса, скорости и формата, инструкция «как говорить», проигрывание в браузере и скачивание. Работает на OmniVoice API v1.');
		$this->set_keywords('озвучить текст онлайн text to speech tts голос mp3 русский синтез речи');
	}

	/** GET|POST /voice — страница сервиса. */
	public function action_index()
	{
		$logged  = Gate::logged_in();
		$user_id = $logged && $this->_current_user ? (int) $this->_current_user->id : 0;
		$uid     = class_exists('Visitor') ? (string) Visitor::uid() : '';

		$result = NULL;
		$error  = '';

		if ($this->request->method() === Request::POST)
		{
			$post = (array) $this->request->post();

			if ( ! $logged)
			{
				$error = 'Озвучивание доступно по аккаунту — войдите на сайт.';
			}
			elseif ( ! Security::check((string) Arr::get($post, 'csrf', '')))
			{
				$error = 'Сессия устарела, обновите страницу.';
			}
			elseif ( ! Service_Nj665_Voice::rate_ok())
			{
				$error = 'Слишком часто. Лимит: '.Service_Nj665_Voice::rate_text().'.';
			}
			else
			{
				$post['user_id'] = $user_id;

				$done = Service_Nj665_Voice::synthesize($user_id, $post);

				if ($done['ok'])
				{
					// Показываем результат на этой же странице: переадресация
					// потеряла бы введённый текст, а он обычно правится ещё раз.
					$result = $done;
				}
				else
				{
					$error = (string) Arr::get($done, 'error', 'Не удалось озвучить текст.');
				}
			}
		}

		$model = (string) ($result ? Arr::get($result, 'model') : Service_Nj665_Voice::default_model());

		$this->template->content = View::factory('665/index', [
			'logged'      => $logged,
			'enabled'     => Service_Nj665_Voice::enabled(),
			'models'      => Service_Nj665_Voice::speech_models(),
			'model'       => $model,
			'voices'      => Service_Nj665_Voice::voices($model),
			'formats'     => Service_Nj665_Voice::formats($model),
			'voice'       => Service_Nj665_Voice::default_voice($model),
			'format'      => Service_Nj665_Voice::default_format($model),
			'speed'       => (float) Arr::get(Service_Nj665_Voice::config(), 'default_speed', 1.0),
			'languages'   => Service_Nj665_Voice::languages(),
			'language'    => (string) Arr::get(Service_Nj665_Voice::config(), 'default_language', ''),
			'limits'      => Service_Nj665_Voice::limits(),
			'rate_text'   => Service_Nj665_Voice::rate_text(),
			'history'     => Service_Nj665_Voice::for_user($user_id, $uid),
			'result'      => $result,
			'error'       => $error,
			'post'        => (array) $this->request->post(),
			'csrf'        => Security::token(),
			'is_admin'    => $this->_current_user !== NULL AND $this->can('nj665.admin'),
			'key_info'    => ($this->_current_user !== NULL AND $this->can('nj665.admin'))
				? Service_Nj665_Voice::key_info()
				: ['ok' => FALSE, 'error' => ''],
		]);
	}

	/**
	 * GET /voice/f/<code> — аудиофайл озвучки.
	 *
	 * Отдаём через сервер, а не ссылкой на storage: файл привязан к записи
	 * журнала (там формат и MIME), по нему считаем проигрывания и скачивания,
	 * а внешний адрес сервиса остаётся закрытым.
	 */
	public function action_f()
	{
		$code = (string) $this->request->param('code', '');
		$row  = Service_Nj665_Voice::speech($code);

		if ($row === NULL OR Arr::get($row, 'status') !== 'done')
		{
			throw HTTP_Exception::factory(404, 'Озвучка не найдена или уже удалена.');
		}

		$file = Service_Nj665_Voice::file_path($row);

		if ( ! is_file($file))
		{
			throw HTTP_Exception::factory(404, 'Файл озвучки не найден на диске.');
		}

		$format   = (string) Arr::get($row, 'format', 'mp3');
		$download = $this->request->query('dl') !== NULL;

		Service_Nj665_Voice::touch($code, $download ? 'downloads' : 'plays');

		$this->auto_render = FALSE;

		$this->response
			->headers('Content-Type', Service_Nj665_Voice::media_type($format))
			->headers('Content-Length', (string) filesize($file))
			->headers('Content-Disposition', Service_Nj665_Voice::content_disposition($row, $download))
			->headers('Cache-Control', 'private, max-age=3600')
			->headers('X-Content-Type-Options', 'nosniff')
			->body(file_get_contents($file));
	}

	/**
	 * GET /voice/h/<code> — страница одной озвучки (ссылка на файл + плеер).
	 *
	 * Нужна, чтобы ссылкой можно было поделиться: /voice/f/<code> открывает
	 * браузером поток, а здесь — плеер, текст и кнопка скачивания.
	 */
	public function action_h()
	{
		$code = (string) $this->request->param('code', '');
		$row  = Service_Nj665_Voice::speech($code);

		if ($row === NULL OR Arr::get($row, 'status') !== 'done')
		{
			throw HTTP_Exception::factory(404, 'Озвучка не найдена или уже удалена.');
		}

		$logged  = Gate::logged_in();
		$user_id = $logged && $this->_current_user ? (int) $this->_current_user->id : 0;

		$this->template->content = View::factory('665/index', [
			'logged'    => $logged,
			'enabled'   => Service_Nj665_Voice::enabled(),
			'models'    => Service_Nj665_Voice::speech_models(),
			'model'     => (string) Arr::get($row, 'model'),
			'voices'    => Service_Nj665_Voice::voices((string) Arr::get($row, 'model')),
			'formats'   => Service_Nj665_Voice::formats((string) Arr::get($row, 'model')),
			'voice'     => (string) Arr::get($row, 'voice'),
			'format'    => (string) Arr::get($row, 'format'),
			'speed'     => (float) Arr::get($row, 'speed', 1.0),
			'languages' => Service_Nj665_Voice::languages(),
			'language'  => (string) Arr::get($row, 'language', ''),
			'limits'    => Service_Nj665_Voice::limits(),
			'rate_text' => Service_Nj665_Voice::rate_text(),
			'history'   => Service_Nj665_Voice::for_user($user_id,
				class_exists('Visitor') ? (string) Visitor::uid() : ''),
			'result'    => [
				'ok'     => TRUE,
				'code'   => (string) Arr::get($row, 'code'),
				'url'    => '/voice/f/'.Arr::get($row, 'code'),
				'format' => (string) Arr::get($row, 'format'),
				'mime'   => Service_Nj665_Voice::media_type((string) Arr::get($row, 'format')),
				'bytes'  => (int) Arr::get($row, 'bytes', 0),
				'chars'  => (int) Arr::get($row, 'chars', 0),
				'model'  => (string) Arr::get($row, 'model'),
				'voice'  => (string) Arr::get($row, 'voice'),
				'title'  => (string) Arr::get($row, 'title'),
			],
			'error'     => '',
			'post'      => [],
			'csrf'      => Security::token(),
			'is_admin'  => FALSE,
			'key_info'  => ['ok' => FALSE, 'error' => ''],
		]);
	}
}
