<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-663 «Генератор favicon» — страницы.
 *
 *   GET /favicon-gen            — генератор (форма + живое превью);
 *   GET /favicon-gen/p/<code>   — готовый набор: файлы, превью, HTML-код.
 *
 * Считает браузер (канвас) + сервер (GD): JS рисует базовое полотно 512×512
 * (эмодзи цветными умеет только браузер) и отдаёт PNG, сервер размножает его
 * по размерам, собирает ICO/манифест/zip и выдавает всё по коду набора.
 */
class Controller_Nj663_Favicon extends Controller_Erp100_Base
{
	public function before()
	{
		parent::before();

		$this->set_title('Генератор favicon: иконки сайта из картинки, буквы или эмодзи');
		$this->set_description('Загрузите картинку, напечатайте букву или поставьте эмодзи — получите полный комплект иконок сайта: PNG 16–512, apple-touch-icon, favicon.ico, site.webmanifest и zip-архив с готовым HTML-кодом.');
		$this->set_keywords('favicon генератор иконка сайта ico png apple-touch-icon webmanifest эмодзи буква');
	}

	/**
	 * GET /favicon-gen — генератор.
	 *
	 * POST сюда же — работа без JS: браузер отдаёт файл (multipart), сервер сам
	 * собирает полотно (вписать, отступ, фон, скругление) и тут же рисует
	 * результат. С JS форма перехватывается скриптом и уходит в JSON API —
	 * там полотно уже собрано канвасом (цветные эмодзи, шрифт, начертание).
	 *
	 * Путь на сайте и название из страницы убраны (09.10.2026): код всегда
	 * собирается под корень сайта, а в manifest подставляется имя площадки.
	 */
	public function action_index()
	{
		$result = NULL;
		$error  = '';

		if ($this->request->method() === Request::POST)
		{
			$post = (array) $this->request->post();

			if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
			{
				$error = 'Сессия устарела, обновите страницу.';
			}
			elseif ( ! Service_Nj663_Favicon::rate_ok())
			{
				$error = 'Слишком часто. Лимит: '.Service_Nj663_Favicon::rate_text().'.';
			}
			else
			{
				$bytes = Service_Nj663_Favicon::source_bytes($post);

				if ($bytes === NULL)
				{
					$error = 'Не удалось прочитать файл: нужен PNG, JPEG, GIF или WebP до '
						.(int) Arr::get(Service_Nj663_Favicon::limits(), 'max_input_mb', 8).' МБ.';
				}
				elseif ($bytes === '')
				{
					$error = 'Выберите файл картинки.';
				}
				else
				{
					$post['composed'] = FALSE;   // полотно собирает сервер
					$made = Service_Nj663_Favicon::generate($bytes,
						Service_Nj663_Favicon::options_from_post($post));

					if ($made['ok'])
					{
						$result = $made;
					}
					else
					{
						$error = (string) $made['error'];
					}
				}
			}
		}

		$this->template->content = View::factory('663/index', [
			'defaults'  => Service_Nj663_Favicon::defaults(),
			'limits'    => Service_Nj663_Favicon::limits(),
			'fonts'     => Service_Nj663_Favicon::fonts(),
			'base_size' => Service_Nj663_Favicon::base_size(),
			'sizes'     => Service_Nj663_Favicon::sizes(),
			'ico_sizes' => Service_Nj663_Favicon::ico_sizes(),
			'targets'   => Service_Nj663_Favicon::targets(),
			'rate_text' => Service_Nj663_Favicon::rate_text(),
			'csrf'      => Security::token(),
			'result'    => $result,
			'error'     => $error,
		]);
	}

	/**
	 * GET /favicon-gen/p/<code> — страница готового набора.
	 *
	 * Код — capability: угадать чужой набор нельзя, а файлы живут 30 дней
	 * (sweep() выметает их при следующей генерации).
	 */
	public function action_pack()
	{
		$code = (string) $this->request->param('code');
		$pack = Service_Nj663_Favicon::pack_files($code);

		if ($pack === NULL OR ! $pack['files'])
		{
			throw HTTP_Exception::factory(404, 'Такого набора нет: возможно, он старше 30 дней и уже вычищен');
		}

		Service_Nj663_Favicon::touch_download($code);

		$this->template->content = View::factory('663/pack', [
			'pack' => $pack,
			'url'  => Service_Nj663_Favicon::pack_url($code),
		]);
	}
}
