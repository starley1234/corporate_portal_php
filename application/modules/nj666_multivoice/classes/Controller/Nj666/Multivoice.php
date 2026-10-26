<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-666 «Многоголосая озвучка» — страницы.
 *
 *   GET  /multivoice            — мои книги и форма новой;
 *   POST /multivoice            — создать книгу (работает и без JS);
 *   GET  /multivoice/b/<code>   — рабочее место: персонажи, блоки, прогресс;
 *   GET  /multivoice/b/<code>/k/<no> — просмотр и правка блока;
 *   GET  /multivoice/f/<code>/<no> — файл блока;
 *   GET  /multivoice/p/<hash>   — превью голоса;
 *   GET  /multivoice/zip/<code> — архив (project + production).
 *
 * Разбор и озвучка идут в воркере (очередь nj120), страница только поллит
 * состояние: вызов модели на книгу — это десятки минут, веб-процесс столько
 * ждать не может.
 */
class Controller_Nj666_Multivoice extends Controller_Erp100_Base
{
	public function before()
	{
		parent::before();

		$this->set_title('Многоголосая озвучка текста: книга разными голосами');
		$this->set_description('Загрузите большой текст — модель найдёт персонажей, не перепутает их по ходу книги, разобьёт текст на блоки с логическим завершением и озвучит каждый блок разными голосами. Скачать можно одним архивом.');
		$this->set_keywords('озвучка книги многоголосая аудиокнига text to speech голоса персонажей');
	}

	/** GET|POST /multivoice — список книг и создание новой. */
	public function action_index()
	{
		$logged  = Gate::logged_in();
		$user_id = $logged && $this->_current_user ? (int) $this->_current_user->id : 0;
		$uid     = class_exists('Visitor') ? (string) Visitor::uid() : '';
		$can_run = Service_Nj666_Multivoice::can_start($this->_current_user);

		$error = '';

		if ($this->request->method() === Request::POST)
		{
			$post = (array) $this->request->post();

			if ( ! $logged)
			{
				$error = 'Озвучка книги доступна по аккаунту — войдите на сайт.';
			}
			elseif ( ! Security::check((string) Arr::get($post, 'csrf', '')))
			{
				$error = 'Сессия устарела, обновите страницу.';
			}
			elseif ( ! $can_run)
			{
				$error = 'Запускать озвучку книги могут привилегированные аккаунты (роль vip) '
					.'и администраторы — это десятки вызовов модели и часы синтеза.';
			}
			elseif ( ! Service_Nj666_Multivoice::rate_ok())
			{
				$error = 'Слишком часто. Лимит: '.Service_Nj666_Multivoice::rate_text().'.';
			}
			else
			{
				$done = Service_Nj666_Multivoice::create($user_id, $post);

				if ($done['ok'])
				{
					HTTP::redirect('/multivoice/b/'.$done['code'], 303);
				}

				$error = (string) Arr::get($done, 'error', 'Не удалось создать книгу.');
			}
		}

		$this->template->content = View::factory('666/index', [
			'logged'    => $logged,
			'can_run'   => $can_run,
			'enabled'   => Service_Nj665_Voice::enabled(),
			'books'     => Service_Nj666_Multivoice::for_user($user_id, $uid),
			'modes'     => Service_Nj666_Multivoice::modes(),
			'languages' => Service_Nj665_Voice::languages(),
			'defaults'  => Service_Nj666_Multivoice::defaults(),
			'limits'    => Service_Nj666_Multivoice::limits(),
			'rate_text' => Service_Nj666_Multivoice::rate_text(),
			'error'     => $error,
			'csrf'      => Security::token(),
			'post'      => (array) $this->request->post(),
		]);
	}

	/** GET /multivoice/b/<code> — рабочее место книги. */
	public function action_book()
	{
		$code = (string) $this->request->param('code', '');
		$book = Service_Nj666_Multivoice::book($code);

		if ($book === NULL)
		{
			throw HTTP_Exception::factory(404, 'Книга не найдена или удалена.');
		}

		$logged  = Gate::logged_in();
		$user_id = $logged && $this->_current_user ? (int) $this->_current_user->id : 0;

		if ( ! Service_Nj666_Multivoice::owns($book, $user_id,
			class_exists('Visitor') ? (string) Visitor::uid() : ''))
		{
			throw HTTP_Exception::factory(403, 'Это чужая книга.');
		}

		$this->set_title('Озвучка: '.(string) Arr::get($book, 'title'));

		// Клоны «Рассказчик (голос 1)» и «Принц (голос)» с прошлого разбора
		// убираем при открытии, не дожидаясь нового прогона (владелец, 11.10.2026).
		Service_Nj666_Multivoice::fold_aliases((int) Arr::get($book, 'id'));

		$this->template->content = View::factory('666/book', [
			'logged'     => $logged,
			'can_run'    => Service_Nj666_Multivoice::can_start($this->_current_user),
			'enabled'    => Service_Nj665_Voice::enabled(),
			'book'       => $book,
			'characters' => Service_Nj666_Multivoice::characters((int) Arr::get($book, 'id')),
			'blocks'     => Service_Nj666_Multivoice::blocks((int) Arr::get($book, 'id')),
			'progress'   => Service_Nj666_Multivoice::progress_of((int) Arr::get($book, 'id')),
			'excerpt'    => UTF8::substr(Service_Nj666_Multivoice::source_text($book), 0, 600),
			'voices'     => Service_Nj665_Voice::voices_catalog(),
			'genders'    => Service_Nj666_Multivoice::genders(),
			'ages'       => Service_Nj666_Multivoice::ages(),
			'modes'      => Service_Nj666_Multivoice::modes(),
			'languages'  => Service_Nj665_Voice::languages(),
			'defaults'   => Service_Nj666_Multivoice::defaults(),
			'limits'     => Service_Nj666_Multivoice::limits(),
			'rate_text'  => Service_Nj666_Multivoice::rate_text(),
			'csrf'       => Security::token(),
		]);
	}

	/**
	 * GET|POST /multivoice/b/<code>/k/<no> — просмотр и правка блока.
	 * POST без JS: title + turns[i][speaker|text]. Смена реплик сбрасывает озвучку.
	 */
	public function action_block()
	{
		$code = (string) $this->request->param('code', '');
		$no   = (int) $this->request->param('no', 0);
		$book = Service_Nj666_Multivoice::book($code);

		if ($book === NULL OR $no <= 0)
		{
			throw HTTP_Exception::factory(404, 'Блок не найден.');
		}

		$logged  = Gate::logged_in();
		$user_id = $logged && $this->_current_user ? (int) $this->_current_user->id : 0;

		if ( ! Service_Nj666_Multivoice::owns($book, $user_id,
			class_exists('Visitor') ? (string) Visitor::uid() : ''))
		{
			throw HTTP_Exception::factory(403, 'Это чужая книга.');
		}

		$block = Service_Nj666_Multivoice::block_by_no((int) Arr::get($book, 'id'), $no);

		if ($block === NULL)
		{
			throw HTTP_Exception::factory(404, 'Блок не найден.');
		}

		$error = '';
		$turns = NULL;
		$title = NULL;

		if ($this->request->method() === Request::POST)
		{
			$post = (array) $this->request->post();

			if ( ! $logged)
			{
				$error = 'Правка блока доступна по аккаунту — войдите на сайт.';
			}
			elseif ( ! Security::check((string) Arr::get($post, 'csrf', '')))
			{
				$error = 'Сессия устарела, обновите страницу.';
			}
			else
			{
				$done = Service_Nj666_Multivoice::save_block((int) Arr::get($book, 'id'), $no, $post);

				if (Arr::get($done, 'ok'))
				{
					HTTP::redirect('/multivoice/b/'.$code.'/k/'.$no, 303);
				}

				$error = (string) Arr::get($done, 'error', 'Блок не сохранился.');
			}

			$title = UTF8::substr(trim((string) Arr::get($post, 'title', '')), 0, 300);
			$turns = (array) Arr::get($post, 'turns', []);
		}

		if ($turns === NULL)
		{
			$turns = Service_Nj666_Multivoice::block_turns($block);
			$title = (string) Arr::get($block, 'title', '');
		}

		$this->set_title('Блок '.$no.': '.(string) Arr::get($book, 'title'));

		$this->template->content = View::factory('666/block', [
			'logged'     => $logged,
			'can_run'    => Service_Nj666_Multivoice::can_start($this->_current_user),
			'enabled'    => Service_Nj665_Voice::enabled(),
			'book'       => $book,
			'block'      => $block + ['title' => $title],
			'characters' => Service_Nj666_Multivoice::characters((int) Arr::get($book, 'id')),
			'turns'      => $turns,
			'error'      => $error,
			'csrf'       => Security::token(),
		]);
	}

	/**
	 * GET /multivoice/p/<hash> — кэш превью голоса (sha1 модели+голоса+фразы).
	 */
	public function action_preview()
	{
		$hash = (string) $this->request->param('hash', '');
		$file = Service_Nj666_Multivoice::preview_file($hash);

		if ($file === NULL OR ! is_file($file))
		{
			throw HTTP_Exception::factory(404, 'Превью не найдено.');
		}

		$ext    = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		$format = $ext !== '' ? $ext : 'mp3';

		$this->auto_render = FALSE;

		$this->response
			->headers('Content-Type', Service_Nj665_Voice::media_type($format))
			->headers('Content-Length', (string) filesize($file))
			->headers('Content-Disposition', 'inline; filename="preview.'.$ext.'"')
			->headers('Cache-Control', 'public, max-age=86400')
			->headers('X-Content-Type-Options', 'nosniff')
			->body(file_get_contents($file));
	}

	/**
	 * GET /multivoice/f/<code>/<no> — файл блока.
	 *
	 * Отдаём через сервер: файл привязан к блоку книги, и имя его собирается
	 * из номера, а не из пользовательского ввода.
	 */
	public function action_file()
	{
		$code = (string) $this->request->param('code', '');
		$no   = (int) $this->request->param('no', 0);
		$book = Service_Nj666_Multivoice::book($code);

		if ($book === NULL OR $no <= 0)
		{
			throw HTTP_Exception::factory(404, 'Блок не найден.');
		}

		$block = Service_Nj666_Multivoice::block_by_no((int) Arr::get($book, 'id'), $no);

		if ($block === NULL OR Arr::get($block, 'status') !== 'voiced')
		{
			throw HTTP_Exception::factory(404, 'Блок ещё не озвучен.');
		}

		$file = Service_Nj666_Multivoice::block_file($book, $block);

		if ( ! is_file($file))
		{
			throw HTTP_Exception::factory(404, 'Файл блока не найден на диске.');
		}

		$download = $this->request->query('dl') !== NULL;
		$format   = (string) Arr::get($book, 'format', 'mp3');

		// Расширение файла могло подмениться сервисом синтеза — берём то,
		// что реально лежит на диске, иначе плеер не поймёт ответ (10.10.2026).
		$real = strtolower(pathinfo($file, PATHINFO_EXTENSION));

		if ($real !== '' AND $real !== Service_Nj665_Voice::format_ext($format))
		{
			$format = $real;
		}

		$this->auto_render = FALSE;

		$this->response
			->headers('Content-Type', Service_Nj665_Voice::media_type($format))
			->headers('Content-Length', (string) filesize($file))
			->headers('Content-Disposition', ($download ? 'attachment' : 'inline')
				.'; filename="'.Service_Nj666_Multivoice::safe_file_name(
					Service_Nj666_Multivoice::production_name($book, $block)).'.'
				.Service_Nj665_Voice::format_ext($format).'"')
			->headers('Cache-Control', 'private, max-age=3600')
			->headers('X-Content-Type-Options', 'nosniff')
			->body(file_get_contents($file));
	}

	/** GET /multivoice/zip/<code> — архив: project/ и production/. */
	public function action_zip()
	{
		$code = (string) $this->request->param('code', '');
		$book = Service_Nj666_Multivoice::book($code);

		if ($book === NULL)
		{
			throw HTTP_Exception::factory(404, 'Книга не найдена.');
		}

		$logged  = Gate::logged_in();
		$user_id = $logged && $this->_current_user ? (int) $this->_current_user->id : 0;

		if ( ! Service_Nj666_Multivoice::owns($book, $user_id,
			class_exists('Visitor') ? (string) Visitor::uid() : ''))
		{
			throw HTTP_Exception::factory(403, 'Это чужая книга.');
		}

		$done = Service_Nj666_Multivoice::build_zip((int) Arr::get($book, 'id'));

		if ( ! Arr::get($done, 'ok'))
		{
			HTTP::redirect('/multivoice/b/'.$code.'?error='
				.rawurlencode((string) Arr::get($done, 'error', 'Архив не собрался.')), 303);
		}

		$this->auto_render = FALSE;

		$this->response->send_file($done['path'],
			Service_Nj666_Multivoice::safe_file_name((string) Arr::get($book, 'title')).'.zip',
			['delete' => FALSE, 'inline' => FALSE]);
	}
}
