<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-663 «Генератор favicon» — JSON API.
 *
 *   POST /favicon-gen/api/generate     — собрать комплект (PNG/ICO/zip/HTML);
 *   GET  /favicon-gen/api/pack/<code>  — состав набора (JSON);
 *   GET  /favicon-gen/api/sizes        — справочник размеров и умолчаний;
 *   GET  /favicon-gen/f/<code>/<name>  — сам файл (не JSON).
 *
 * Исходник принимаем двумя путями: `image_b64` (канвас браузера — буква,
 * эмодзи или уже обрезанная картинка) и обычная загрузка файла полем `image`
 * (работает и без JS: полотно соберёт сервер).
 */
class Controller_Nj663_Api extends Controller
{
	public $auto_render = FALSE;

	protected $_body = [];

	public function before()
	{
		parent::before();

		$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

		$raw = $this->request->body();
		$decoded = $raw ? json_decode($raw, TRUE) : NULL;

		$this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();

		if ( ! $this->_body AND $this->request->query())
		{
			$this->_body = (array) $this->request->query();
		}
	}

	/** POST /favicon-gen/api/generate — собрать комплект иконок. */
	public function action_generate()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		if ( ! Security::check((string) Arr::get($this->_body, 'csrf', '')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела, обновите страницу.'], 403);
		}

		if ( ! Service_Nj663_Favicon::rate_ok())
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Слишком часто. Лимит: '.Service_Nj663_Favicon::rate_text().'.',
			], 429);
		}

		$bytes = Service_Nj663_Favicon::source_bytes($this->_body);

		if ($bytes === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Не удалось прочитать исходник.'], 400);
		}

		if ($bytes === '')
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Загрузите картинку или напечатайте букву.'], 400);
		}

		$result = Service_Nj663_Favicon::generate($bytes, Service_Nj663_Favicon::options_from_post($this->_body));

		if ( ! $result['ok'])
		{
			if (class_exists('Log_Plain'))
			{
				Log_Plain::event(Log::ERROR, 'nj663: комплект не собран: :e',
					[':e' => mb_substr((string) $result['error'], 0, 200)]);
			}

			return $this->_respond(['ok' => FALSE, 'error' => $result['error']], 422);
		}

		return $this->_respond([
			'ok'       => TRUE,
			'code'     => $result['code'],
			'url'      => Service_Nj663_Favicon::pack_url($result['code']),
			'html'     => $result['html'],
			'manifest' => $result['manifest'],
			'bytes'    => (int) $result['bytes'],
			'files'    => $result['files'],
		]);
	}

	/** GET /favicon-gen/api/pack/<code> — состав набора (для интеграций). */
	public function action_pack()
	{
		$code = (string) $this->request->param('code');
		$pack = Service_Nj663_Favicon::pack_files($code);

		if ($pack === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Набор не найден.'], 404);
		}

		Service_Nj663_Favicon::touch_download($code);

		return $this->_respond(['ok' => TRUE] + $pack);
	}

	/** GET /favicon-gen/api/sizes — справочник: что именно получится. */
	public function action_sizes()
	{
		return $this->_respond([
			'ok'        => TRUE,
			'base_size' => Service_Nj663_Favicon::base_size(),
			'sizes'     => Service_Nj663_Favicon::sizes(),
			'ico_sizes' => Service_Nj663_Favicon::ico_sizes(),
			'targets'   => Service_Nj663_Favicon::targets(),
			'defaults'  => Service_Nj663_Favicon::defaults(),
			'limits'    => Service_Nj663_Favicon::limits(),
			'rate_text' => Service_Nj663_Favicon::rate_text(),
		]);
	}

	/**
	 * GET /favicon-gen/f/<code>/<name> — файл набора.
	 *
	 * Имя проверяется белым списком, код — шаблоном: выйти за пределы
	 * каталога набора нельзя (ровно как у /imagegen/file/<имя> в nj641).
	 */
	public function action_file()
	{
		$code = (string) $this->request->param('code');
		$name = (string) $this->request->param('name');

		$path = Service_Nj663_Favicon::file_path($code, $name);

		if ($path === NULL)
		{
			return $this->response->status(404)->body('Файл не найден');
		}

		// Код одноразовый и не переиспользуется — кэшируем надолго.
		$this->response->headers('Content-Type', Service_Nj663_Favicon::media_type($name));
		$this->response->headers('Content-Length', (string) filesize($path));
		$this->response->headers('Content-Disposition', 'inline; filename="'.$name.'"');
		$this->response->headers('Cache-Control', 'public, max-age=604800');
		$this->response->headers('X-Content-Type-Options', 'nosniff');
		$this->response->body((string) file_get_contents($path));

		Service_Nj663_Favicon::touch_download($code);
	}

	// ------------------------------------------------------------------

	protected function _respond(array $data, $status = 200)
	{
		return $this->response->status((int) $status)
			->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
