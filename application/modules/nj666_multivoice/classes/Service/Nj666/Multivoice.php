<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис NJ-666 «Многоголосая озвучка» (nj666_multivoice).
 *
 * Разделение труда:
 *
 *   * воркер (python, канал nj666.plan) — разбор: ходит по тексту окнами,
 *     ведёт список персонажей (не плодя двойников), режет на блоки с
 *     логическим завершением; при режиме «утка го» сначала ищет книгу
 *     в интернете и берёт персонажей оттуда;
 *   * воркер (канал nj666.voice) — синтез OmniVoice v1 (тот же контракт,
 *     что /voice): длинный блок не идёт через nginx/php-fpm;
 *   * САЙТ — файлы, журнал, zip и короткое превью голоса (synth_bytes).
 *     Ключ NJ665_* есть и у сайта, и у воркера.
 *
 * Почему голос реплики подставляется в момент озвучки, а не при разборе:
 * владелец правит персонажей (голос, описание, замочек) уже после разбора —
 * и блок переозвучивается новым голосом без повторного разбора книги.
 */
class Service_Nj666_Multivoice
{
	const VERSION = '1.0.0';

	const CHANNEL_PLAN  = 'nj666.plan';
	const CHANNEL_VOICE = 'nj666.voice';

	/** Короткая фраза для play/stop у карточки персонажа (владелец, 11.10.2026). */
	const PREVIEW_PHRASE = 'Раз-два-три. Это пробная фраза выбранного голоса.';

	/** Статусы книги — их видит страница и по ним решает, что показывать. */
	const S_NEW     = 'new';
	const S_PLAN    = 'planning';
	const S_PLANNED = 'planned';
	const S_VOICING = 'voicing';
	const S_DONE    = 'done';
	const S_ERROR   = 'error';

	// ---------------------------------------------------------------- конфиг

	public static function config()
	{
		$config = Kohana::$config->load('nj666_multivoice');

		return $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;
	}

	public static function branch($name, $default = [])
	{
		return (array) Arr::get(self::config(), $name, $default);
	}

	public static function limits($key = NULL, $default = NULL)
	{
		$limits = self::branch('limits');

		return $key === NULL ? $limits : Arr::get($limits, $key, $default);
	}

	public static function defaults($key = NULL, $default = NULL)
	{
		$defaults = self::branch('defaults');

		return $key === NULL ? $defaults : Arr::get($defaults, $key, $default);
	}

	public static function modes()   { return (array) Arr::get(self::config(), 'modes', []); }
	public static function genders() { return (array) Arr::get(self::config(), 'genders', []); }
	public static function ages()    { return (array) Arr::get(self::config(), 'ages', []); }

	public static function chars_per_minute()
	{
		return max(200, (int) Arr::get(self::config(), 'chars_per_minute', 950));
	}

	/**
	 * Сколько знаков уходит в ОДИН запрос синтеза (лимит OmniVoice / nj665).
	 *
	 * Не путать с limits.max_chars книги (600 000): 11.10.2026 voice_block
	 * резал главу по лимиту книги, кусок не влезал в clean_turns и либо
	 * обрезался, либо висел на nginx, пока TTS писал звук в никуда.
	 */
	public static function synth_chunk_chars()
	{
		$max = 4000;

		if (class_exists('Service_Nj665_Voice'))
		{
			$max = (int) Service_Nj665_Voice::limits('max_chars', 4000);
		}

		return max(200, $max);
	}

	/**
	 * Модель OmniVoice для синтеза: книга, иначе NJ665_MODEL, иначе fallback.
	 *
	 * Пустую в API не шлём — 422 «model: Field required» (владелец, 11.10.2026).
	 * Каталог не трогаем: воркеру достаточно того же имени, что у /voice.
	 */
	public static function synth_model($book = NULL)
	{
		$model = trim((string) Arr::get((array) $book, 'model', ''));

		if ($model !== '')
		{
			return $model;
		}

		if (class_exists('Service_Nj665_Voice'))
		{
			$cfg = Service_Nj665_Voice::config();
			$want = trim((string) Arr::get($cfg, 'model', ''));

			if ($want !== '')
			{
				return $want;
			}

			$want = trim((string) Arr::get($cfg, 'model_fallback', 'k2-fsa/omnivoice'));

			if ($want !== '')
			{
				return $want;
			}
		}

		return 'k2-fsa/omnivoice';
	}

	/** base64 из тела воркера → байты звука (data:-префикс и пробелы допускаем). */
	public static function decode_audio_b64($value)
	{
		$value = trim((string) $value);

		if ($value === '')
		{
			return '';
		}

		$value = preg_replace('~^data:[^;]*;base64,~i', '', $value);
		$value = preg_replace('~\s+~', '', $value);

		if ($value === '')
		{
			return '';
		}

		$bytes = base64_decode($value, TRUE);

		return $bytes === FALSE ? '' : $bytes;
	}

	// ------------------------------------------------------------- прикидки

	/** Сколько секунд звучит текст: символы / (символов в минуту × скорость). */
	public static function estimate_seconds($chars, $speed = 1.0)
	{
		$speed = (float) $speed > 0 ? (float) $speed : 1.0;

		return (int) round(((int) $chars / (self::chars_per_minute() * $speed)) * 60);
	}

	/** Границы блока в символах по диапазону минут. */
	public static function target_chars($min_min, $max_min)
	{
		$cpm = self::chars_per_minute();

		$min = (int) round(max(0.1, (float) $min_min) * $cpm);
		$max = (int) round(max(0.1, (float) $max_min) * $cpm);

		if ($max < $min)
		{
			$max = $min;
		}

		return ['min' => $min, 'max' => $max];
	}

	/** «7 мин» из секунд — для подписей в интерфейсе. */
	public static function human_time($seconds)
	{
		$seconds = max(0, (int) $seconds);
		$min     = (int) floor($seconds / 60);
		$sec     = $seconds % 60;

		return $min > 0
			? $min.' мин '.$sec.' с'
			: $sec.' с';
	}

	/**
	 * Имя без служебных хвостов модели: «Принц (голос 2)», «(голос, новая)».
	 *
	 * Модель пишет голос в имя, а не в поле voice — тогда «Принц» и
	 * «Принц (голос)» становятся двумя людьми (владелец, 11.10.2026).
	 */
	public static function clean_character_name($name)
	{
		$name = trim((string) $name);
		$prev = NULL;

		while ($name !== $prev)
		{
			$prev = $name;
			$name = preg_replace(
				'~\s*\((?=[^)]*(голос|voice|нов(?:ая|ый|ое)|new))[^)]*\)\s*$~iu',
				'',
				$name
			);
			$name = trim((string) $name);
		}

		return $name;
	}

	/** Приведение имени к виду, по которому сравнивают персонажей. */
	public static function name_key($name)
	{
		$name = self::clean_character_name($name);
		$name = mb_strtolower(trim((string) $name));
		$name = preg_replace('~[ёЁ]~u', 'е', $name);
		$name = preg_replace('~[^\p{L}\p{N} ]+~u', ' ', $name);
		$name = preg_replace('~\s+~u', ' ', $name);
		$name = trim((string) $name);
		$name = preg_replace('~\s+голос(\s+\d+)?(\s+нов(?:ая|ый|ое))?$~u', '', $name);
		$name = preg_replace('~\s+voice(\s+\d+)?(\s+new)?$~u', '', $name);

		return trim((string) $name);
	}

	/**
	 * Это рассказчик, а не действующее лицо.
	 *
	 * Модель плодит «Рассказчик (голос 1)», «Рассказчик (Рассказчик)»: в
	 * подсказке было «Рассказчик (рассказчик)», и она принимала пометку
	 * за часть имени (владелец, 11.10.2026).
	 */
	public static function is_narrator_name($name)
	{
		$key = self::name_key($name);

		if ($key === '' OR $key === 'narrator' OR $key === 'рассказчик')
		{
			return $key !== '';
		}

		return (bool) preg_match('~^(narrator|рассказчик)(\s|$)~u', $key);
	}

	// --------------------------------------------------------------- коды

	public static function code_new($len = 12)
	{
		$alphabet = '23456789abcdefghijkmnpqrstuvwxyz';
		$code     = '';

		for ($i = 0; $i < (int) $len; $i++)
		{
			$code .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
		}

		return $code;
	}

	// ------------------------------------------------------------ хранение

	/** Каталог модуля: public_html/storage/nj666/<код книги>/. */
	public static function storage_dir()
	{
		return DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj666';
	}

	public static function book_dir($book)
	{
		return self::storage_dir().DIRECTORY_SEPARATOR.basename((string) Arr::get((array) $book, 'code', 'book'));
	}

	public static function block_path($book, $block, $format = NULL)
	{
		$no  = (int) (is_array($block) ? Arr::get($block, 'no', 0) : $block);
		$ext = ($format !== NULL AND $format !== '')
			? $format
			: (string) Arr::get((array) $book, 'format', 'mp3');

		return self::book_dir($book).DIRECTORY_SEPARATOR
			.sprintf('block-%03d', $no).'.'.Service_Nj665_Voice::format_ext($ext);
	}

	/**
	 * Готовый файл блока (с проверкой расширения).
	 *
	 * Формат синтеза может подмениться сервисом (просили mp3, пришло wav) —
	 * тогда имя файла будет другим. Ищем по всем известным расширениям,
	 * чтобы блок не «потерялся» для плеера и архива.
	 */
	public static function block_file($book, $block)
	{
		$primary = self::block_path($book, $block);

		if (is_file($primary))
		{
			return $primary;
		}

		foreach ((array) Service_Nj665_Voice::formats() as $format)
		{
			$path = self::block_path($book, $block, (string) $format);

			if (is_file($path))
			{
				return $path;
			}
		}

		return $primary;
	}

	public static function zip_path($book)
	{
		return self::book_dir($book).DIRECTORY_SEPARATOR.'audiobook.zip';
	}

	/** Каталог превью голосов: не код книги, в алфавите code_new нет «_». */
	public static function preview_dir()
	{
		return self::storage_dir().DIRECTORY_SEPARATOR.'_preview';
	}

	public static function preview_phrase()
	{
		return self::PREVIEW_PHRASE;
	}

	public static function preview_hash($model, $voice)
	{
		return sha1(trim((string) $model).'|'.trim((string) $voice).'|'.self::preview_phrase());
	}

	/**
	 * Готовый файл превью по sha1, или NULL. Расширение — как вернул синтез.
	 */
	public static function preview_file($hash)
	{
		$hash = strtolower(preg_replace('~[^a-f0-9]~', '', (string) $hash));

		if (strlen($hash) !== 40)
		{
			return NULL;
		}

		$dir   = self::preview_dir();
		$tried = [];

		foreach (array_merge(['mp3'], (array) Service_Nj665_Voice::formats()) as $format)
		{
			$ext = Service_Nj665_Voice::format_ext((string) $format);

			if ($ext === '' OR isset($tried[$ext]))
			{
				continue;
			}

			$tried[$ext] = TRUE;
			$path        = $dir.DIRECTORY_SEPARATOR.$hash.'.'.$ext;

			if (is_file($path) AND filesize($path) > 0)
			{
				return $path;
			}
		}

		return NULL;
	}

	/**
	 * Превью выбранного голоса: кэш по модели+голосу+фразе.
	 * Пустой голос — default_voice книги, затем умолчание nj665 (владелец, 11.10.2026).
	 */
	public static function preview_voice($book, $voice)
	{
		$book  = (array) $book;
		$voice = trim((string) $voice);
		$model = (string) Arr::get($book, 'model', '');

		if ($voice === '')
		{
			$voice = trim((string) Arr::get($book, 'default_voice', ''));
		}

		if ($voice === '')
		{
			$voice = (string) Service_Nj665_Voice::default_voice($model);
		}

		$hash = self::preview_hash($model, $voice);
		$have = self::preview_file($hash);

		if ($have !== NULL)
		{
			return [
				'ok'     => TRUE,
				'hash'   => $hash,
				'url'    => '/multivoice/p/'.$hash,
				'voice'  => $voice,
				'cached' => TRUE,
			];
		}

		$dir = self::preview_dir();

		if ( ! is_dir($dir) AND ! @mkdir($dir, 0755, TRUE) AND ! is_dir($dir))
		{
			return ['ok' => FALSE, 'error' => 'Не удалось создать каталог превью.'];
		}

		$res = Service_Nj665_Voice::synth_bytes([
			'text'     => self::preview_phrase(),
			'model'    => $model,
			'voice'    => $voice,
			'format'   => 'mp3',
			'speed'    => 1.0,
			'language' => (string) Arr::get($book, 'language', ''),
		]);

		if ( ! Arr::get($res, 'ok'))
		{
			return ['ok' => FALSE, 'error' => (string) Arr::get($res, 'error', 'Превью не озвучилось.')];
		}

		$bytes  = (string) Arr::get($res, 'bytes', '');
		$format = (string) Arr::get($res, 'format', 'mp3');

		if ($bytes === '')
		{
			return ['ok' => FALSE, 'error' => 'Синтез вернул пустой звук.'];
		}

		$path = $dir.DIRECTORY_SEPARATOR.$hash.'.'.Service_Nj665_Voice::format_ext($format);

		if (@file_put_contents($path, $bytes) === FALSE)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось сохранить превью.'];
		}

		return [
			'ok'     => TRUE,
			'hash'   => $hash,
			'url'    => '/multivoice/p/'.$hash,
			'voice'  => $voice,
			'cached' => FALSE,
		];
	}

	/** Стереть файл озвучки блока, если он есть на диске. */
	public static function forget_block_audio($book, $block)
	{
		$path = self::block_file($book, $block);

		if (is_file($path))
		{
			@unlink($path);
		}

		return TRUE;
	}

	// ---------------------------------------------------------------- книги

	/**
	 * Новая книга: текст (или файл) + настройки. Рассказчик создаётся сразу —
	 * большая часть книги это авторская речь, и она должна кем-то читаться.
	 */
	public static function create($user_id, array $opt)
	{
		$text  = Service_Nj665_Voice::clean_text((string) Arr::get($opt, 'text', ''));
		$chars = UTF8::strlen($text);

		if ($chars < (int) self::limits('min_chars', 200))
		{
			return ['ok' => FALSE, 'error' => 'Слишком короткий текст: нужно хотя бы '
				.(int) self::limits('min_chars', 200).' символов, чтобы было что разбирать.'];
		}

		$max_chars = (int) self::limits('max_chars', 600000);

		if ($chars > $max_chars)
		{
			return ['ok' => FALSE, 'error' => 'Текст длиннее '.number_format($max_chars, 0, ',', ' ')
				.' символов — разбейте его на части.'];
		}

		$title = UTF8::substr(trim((string) Arr::get($opt, 'title', '')), 0, 300);

		if ($title === '')
		{
			$title = Service_Nj665_Voice::title_from_text($text, 60);
		}

		$min_min = (float) Arr::get($opt, 'block_min_min', self::defaults('block_min_min', 7.0));
		$max_min = (float) Arr::get($opt, 'block_max_min', self::defaults('block_max_min', 15.0));

		$code = self::code_new();
		$uid  = class_exists('Visitor') ? (string) Visitor::uid() : '';

		$row = [
			'code'          => $code,
			'user_id'       => (int) $user_id,
			'visitor_uid'   => mb_substr($uid, 0, 40),
			'title'         => $title,
			'author'        => UTF8::substr(trim((string) Arr::get($opt, 'author', '')), 0, 200),
			'source_kind'   => 'text',
			'chars'         => $chars,
			'mode'          => Arr::get(self::modes(), (string) Arr::get($opt, 'mode', 'auto')) !== NULL
				? (string) Arr::get($opt, 'mode', 'auto')
				: 'auto',
			'model'         => UTF8::substr(trim((string) Arr::get($opt, 'model', '')), 0, 160),
			'default_voice' => UTF8::substr(trim((string) Arr::get($opt, 'default_voice', '')), 0, 80),
			'language'      => Arr::get(Service_Nj665_Voice::languages(), (string) Arr::get($opt, 'language', '')) !== NULL
				? (string) Arr::get($opt, 'language', '')
				: '',
			'format'        => Service_Nj665_Voice::pick_format((string) Arr::get($opt, 'format', 'mp3'),
				Service_Nj665_Voice::formats((string) Arr::get($opt, 'model', ''))),
			'speed'         => Service_Nj665_Voice::clamp_speed((float) Arr::get($opt, 'speed',
				self::defaults('speed', 1.0))),
			'block_min_min' => self::_clamp_minutes($min_min),
			'block_max_min' => self::_clamp_minutes($max_min),
			'single_block'  => (int) (bool) Arr::get($opt, 'single_block', FALSE),
			'status'        => self::S_NEW,
			'stage'         => 'Книга ждёт разбора',
		];

		try
		{
			$id = self::_insert('nj_666_books', $row);
		}
		catch (Exception $e)
		{
			self::_log(Log::ERROR, 'книга не создана: :error', [':error' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Не удалось создать книгу: '.$e->getMessage()];
		}

		// Текст кладём рядом с книгой: он большой и не должен лежать в базе.
		$dir = self::storage_dir().DIRECTORY_SEPARATOR.$code;

		if ( ! is_dir($dir) AND ! @mkdir($dir, 0755, TRUE) AND ! is_dir($dir))
		{
			self::_exec('DELETE FROM nj_666_books WHERE id = '.(int) $id);

			return ['ok' => FALSE, 'error' => 'Не удалось создать каталог книги.'];
		}

		if (@file_put_contents($dir.DIRECTORY_SEPARATOR.'source.txt', $text) === FALSE)
		{
			self::_exec('DELETE FROM nj_666_books WHERE id = '.(int) $id);

			return ['ok' => FALSE, 'error' => 'Не удалось сохранить текст книги.'];
		}

		self::ensure_narrator($id, (string) Arr::get($row, 'default_voice', ''));

		return ['ok' => TRUE, 'code' => $code, 'id' => (int) $id, 'chars' => $chars];
	}

	public static function book($code)
	{
		$code = trim((string) $code);

		if ($code === '' OR preg_match('~^[A-Za-z0-9]{8,24}$~', $code) !== 1)
		{
			return NULL;
		}

		return self::_one('SELECT * FROM nj_666_books WHERE code = '.self::_q($code).' AND deleted_at IS NULL');
	}

	public static function book_by_id($id)
	{
		return self::_one('SELECT * FROM nj_666_books WHERE id = '.(int) $id.' AND deleted_at IS NULL');
	}

	public static function for_user($user_id, $uid = '', $limit = 20)
	{
		$limit = min(50, max(1, (int) $limit));
		$where = [];

		if ((int) $user_id > 0)
		{
			$where[] = 'user_id = '.(int) $user_id;
		}

		$uid = trim((string) $uid);

		if ($uid !== '')
		{
			$where[] = 'visitor_uid = '.self::_q(mb_substr($uid, 0, 40));
		}

		if ( ! $where)
		{
			return [];
		}

		$rows = self::_all('SELECT b.*,
				(SELECT count(*) FROM nj_666_characters c WHERE c.book_id = b.id) AS characters_count,
				(SELECT count(*) FROM nj_666_blocks k WHERE k.book_id = b.id) AS blocks_count,
				(SELECT count(*) FROM nj_666_blocks k WHERE k.book_id = b.id AND k.status = \'voiced\') AS blocks_ready
			FROM nj_666_books b
			WHERE ('.implode(' OR ', $where).') AND b.deleted_at IS NULL
			ORDER BY b.id DESC
			LIMIT '.$limit);

		return $rows;
	}

	/** Книги, у которых started-задача зависла: для пиналки (nj662-style). */
	public static function stuck($minutes = 30)
	{
		return self::_all('SELECT * FROM nj_666_books
			WHERE status IN ('.self::_q(self::S_PLAN).', '.self::_q(self::S_VOICING).')
				AND updated_at < now() - interval \''.(int) $minutes.' minutes\'
			ORDER BY id ASC LIMIT 20');
	}

	// ------------------------------------------------------------ настройки

	/** Правка настроек книги (до разбора — всё, после — только голоса). */
	public static function update($book_id, array $opt)
	{
		$set = [];

		$map = [
			'title'         => 300,
			'author'        => 200,
			'default_voice' => 80,
			'model'         => 160,
		];

		foreach ($map as $field => $len)
		{
			if (Arr::get($opt, $field) !== NULL)
			{
				$set[$field] = UTF8::substr(trim((string) Arr::get($opt, $field)), 0, $len);
			}
		}

		if (Arr::get($opt, 'language') !== NULL
			AND Arr::get(Service_Nj665_Voice::languages(), (string) Arr::get($opt, 'language')) !== NULL)
		{
			$set['language'] = (string) Arr::get($opt, 'language');
		}

		if (Arr::get($opt, 'format') !== NULL)
		{
			$set['format'] = Service_Nj665_Voice::pick_format((string) Arr::get($opt, 'format'),
				Service_Nj665_Voice::formats((string) Arr::get($opt, 'model', '')));
		}

		if (Arr::get($opt, 'speed') !== NULL)
		{
			$set['speed'] = Service_Nj665_Voice::clamp_speed((float) Arr::get($opt, 'speed'));
		}

		if (Arr::get($opt, 'block_min_min') !== NULL)
		{
			$set['block_min_min'] = self::_clamp_minutes((float) Arr::get($opt, 'block_min_min'));
		}

		if (Arr::get($opt, 'block_max_min') !== NULL)
		{
			$set['block_max_min'] = self::_clamp_minutes((float) Arr::get($opt, 'block_max_min'));
		}

		if (Arr::get($opt, 'single_block') !== NULL)
		{
			$set['single_block'] = Arr::get($opt, 'single_block') ? 1 : 0;
		}

		if (Arr::get($opt, 'mode') !== NULL
			AND Arr::get(self::modes(), (string) Arr::get($opt, 'mode')) !== NULL)
		{
			$set['mode'] = (string) Arr::get($opt, 'mode');
		}

		if ( ! $set)
		{
			return TRUE;
		}

		$parts = [];

		foreach ($set as $field => $value)
		{
			$parts[] = $field.' = '.self::_q($value);
		}

		self::_exec('UPDATE nj_666_books SET '.implode(', ', $parts).', updated_at = now()
			WHERE id = '.(int) $book_id);

		return TRUE;
	}

	public static function set_stage($book_id, $stage, $note = '', $progress = NULL)
	{
		$set = ['stage = '.self::_q(UTF8::substr((string) $stage, 0, 120)),
			'stage_note = '.self::_q(UTF8::substr((string) $note, 0, 300)),
			'updated_at = now()'];

		if ($progress !== NULL)
		{
			$set[] = 'progress = '.min(100, max(0, (int) $progress));
		}

		self::_exec('UPDATE nj_666_books SET '.implode(', ', $set).' WHERE id = '.(int) $book_id);

		return TRUE;
	}

	public static function set_status($book_id, $status, $error = '', $job_id = NULL)
	{
		$set = ['status = '.self::_q((string) $status), 'updated_at = now()'];

		if ($error !== NULL)
		{
			$set[] = 'error = '.self::_q(UTF8::substr((string) $error, 0, 1000));
		}

		if ($job_id !== NULL)
		{
			$set[] = 'job_id = '.(int) $job_id;
		}

		self::_exec('UPDATE nj_666_books SET '.implode(', ', $set).' WHERE id = '.(int) $book_id);

		return TRUE;
	}

	// ----------------------------------------------------------- персонажи

	public static function characters($book_id)
	{
		return self::_all('SELECT * FROM nj_666_characters
			WHERE book_id = '.(int) $book_id.'
			ORDER BY kind DESC, sort ASC, id ASC');
	}

	public static function character($id)
	{
		return self::_one('SELECT * FROM nj_666_characters WHERE id = '.(int) $id);
	}

	/**
	 * Рассказчик: читает всё, что не реплика. Создаётся вместе с книгой и
	 * сразу с замком — модель не должна перекраивать голос рассказчика.
	 */
	public static function ensure_narrator($book_id, $voice = '')
	{
		$row = self::_one('SELECT * FROM nj_666_characters
			WHERE book_id = '.(int) $book_id.' AND kind = \'narrator\' LIMIT 1');

		if ($row)
		{
			return (int) $row['id'];
		}

		return self::_insert('nj_666_characters', [
			'book_id' => (int) $book_id,
			'key'     => 'narrator',
			'name'    => 'Рассказчик',
			'kind'    => 'narrator',
			'voice'   => (string) $voice,
			'locked'  => 1,
			'sort'    => 0,
		]);
	}

	/**
	 * Добавить персонажа (из воркера или руками).
	 *
	 * Слияние по ключу имени: модель может назвать персонажа «Пётр Иванович»
	 * в одном окне и «Пётр Иванович» с опечаткой в другом — это один человек,
	 * и второй раз создавать его нельзя (ровно об этом просил владелец).
	 */
	public static function add_character($book_id, array $data)
	{
		$name = self::clean_character_name((string) Arr::get($data, 'name', ''));

		if (self::is_narrator_name($name) OR Arr::get($data, 'kind') === 'narrator')
		{
			return self::ensure_narrator($book_id, (string) Arr::get($data, 'voice', ''));
		}

		$key = self::name_key($name);

		if ($key === '')
		{
			return NULL;
		}

		$row = self::_one('SELECT * FROM nj_666_characters
			WHERE book_id = '.(int) $book_id.' AND key = '.self::_q($key).' LIMIT 1');

		if ($row)
		{
			self::touch_character((int) $row['id']);

			return (int) $row['id'];
		}

		if ((int) self::_value('SELECT count(*) FROM nj_666_characters WHERE book_id = '.(int) $book_id)
			>= (int) self::limits('max_characters', 60))
		{
			return NULL;
		}

		return self::_insert('nj_666_characters', [
			'book_id'      => (int) $book_id,
			'key'          => mb_substr($key, 0, 40),
			'name'         => UTF8::substr($name, 0, 120),
			'kind'         => Arr::get($data, 'kind') === 'narrator' ? 'narrator' : 'character',
			'gender'       => Arr::get(self::genders(), (string) Arr::get($data, 'gender', '')) !== NULL
				? (string) Arr::get($data, 'gender', '')
				: '',
			'age'          => Arr::get(self::ages(), (string) Arr::get($data, 'age', '')) !== NULL
				? (string) Arr::get($data, 'age', '')
				: '',
			'voice'        => UTF8::substr(trim((string) Arr::get($data, 'voice', '')), 0, 80),
			'description'  => UTF8::substr(trim((string) Arr::get($data, 'description', '')), 0, 1000),
			'instructions' => UTF8::substr(trim((string) Arr::get($data, 'instructions', '')), 0,
				(int) self::limits('max_instructions', 300)),
			'locked'       => (int) (bool) Arr::get($data, 'locked', FALSE),
			'mentions'     => 1,
			'sort'         => (int) self::_value('SELECT count(*) FROM nj_666_characters WHERE book_id = '.(int) $book_id),
		]);
	}

	/**
	 * Правка персонажа воркером: замок — это граница, которую модель не
	 * переходит (ни голос, ни описание, ни имя).
	 */
	public static function merge_character($book_id, array $data)
	{
		$id = (int) Arr::get($data, 'id', 0);
		$row = $id > 0 ? self::character($id) : NULL;

		if ($row === NULL OR (int) Arr::get($row, 'book_id', 0) !== (int) $book_id)
		{
			return self::add_character($book_id, $data);
		}

		if ((int) Arr::get($row, 'locked', 0) === 1)
		{
			self::touch_character($id);

			return $id;
		}

		$set = [];

		if (trim((string) Arr::get($data, 'description', '')) !== '')
		{
			$set['description'] = UTF8::substr(trim((string) Arr::get($data, 'description')), 0, 1000);
		}

		if (trim((string) Arr::get($data, 'gender', '')) !== ''
			AND Arr::get(self::genders(), (string) Arr::get($data, 'gender')) !== NULL)
		{
			$set['gender'] = (string) Arr::get($data, 'gender');
		}

		if (trim((string) Arr::get($data, 'age', '')) !== ''
			AND Arr::get(self::ages(), (string) Arr::get($data, 'age')) !== NULL)
		{
			$set['age'] = (string) Arr::get($data, 'age');
		}

		if (trim((string) Arr::get($data, 'voice', '')) !== '' AND trim((string) Arr::get($row, 'voice', '')) === '')
		{
			$set['voice'] = UTF8::substr(trim((string) Arr::get($data, 'voice')), 0, 80);
		}

		if ($set)
		{
			$parts = [];

			foreach ($set as $field => $value)
			{
				$parts[] = $field.' = '.self::_q($value);
			}

			self::_exec('UPDATE nj_666_characters SET '.implode(', ', $parts).', updated_at = now()
				WHERE id = '.$id);
		}

		self::touch_character($id);

		return $id;
	}

	/** Правка персонажа владельцем (ручная — всегда, поверх замка). */
	public static function save_character($book_id, $id, array $data)
	{
		$id  = (int) $id;
		$row = $id > 0 ? self::character($id) : NULL;

		// id=0 — «создай нового»: так со страницы добавляют персонажа руками,
		// когда модель его не нашла (владелец, 10.10.2026). add_character
		// возвращает id (новый или уже существующего тёзки), а не массив.
		if ($id <= 0)
		{
			if (trim((string) Arr::get($data, 'name', '')) === '')
			{
				return ['ok' => FALSE, 'error' => 'У персонажа должно быть имя.'];
			}

			if ((int) self::_value('SELECT count(*) FROM nj_666_characters WHERE book_id = '.(int) $book_id)
				>= (int) self::limits('max_characters', 60))
			{
				return ['ok' => FALSE, 'error' => 'Персонажей уже '
					.(int) self::limits('max_characters', 60).' — больше в одну книгу не влезет.'];
			}

			$new = self::add_character($book_id, $data);

			return $new
				? ['ok' => TRUE, 'id' => (int) $new]
				: ['ok' => FALSE, 'error' => 'Персонажа создать не удалось.'];
		}

		if ($row === NULL OR (int) Arr::get($row, 'book_id', 0) !== (int) $book_id)
		{
			return ['ok' => FALSE, 'error' => 'Персонаж не найден.'];
		}

		$patch = self::character_updates($row, $data);

		if ( ! Arr::get($patch, 'ok'))
		{
			return $patch;
		}

		if ( ! empty($patch['fold']))
		{
			self::delete_character($book_id, $id, TRUE);

			return ['ok' => TRUE, 'id' => self::ensure_narrator($book_id), 'folded' => TRUE];
		}

		$set = Arr::get($patch, 'set', []);

		if ( ! $set)
		{
			return ['ok' => TRUE, 'id' => (int) $id];
		}

		$parts = ['updated_at = now()'];

		foreach ($set as $field => $value)
		{
			$parts[] = $field.' = '.(is_int($value) ? (int) $value : self::_q($value));
		}

		// book_id в WHERE: чужая карточка с тем же id не заденется, даже если
		// клиент прислал чужой id (владелец, 11.10.2026).
		self::_exec('UPDATE nj_666_characters SET '.implode(', ', $parts)
			.' WHERE id = '.(int) $id.' AND book_id = '.(int) $book_id);

		return ['ok' => TRUE, 'id' => (int) $id];
	}

	/**
	 * Какие поля менять при ручной правке. Только присланные: иначе смена
	 * голоса затирала имя, замок и описание всей карточки, а пустое имя
	 * в запросе ломало сохранение пола/возраста (владелец, 11.10.2026).
	 *
	 * @return array {ok, error?, fold?, set?}
	 */
	public static function character_updates(array $row, array $data)
	{
		$kind = (string) Arr::get($row, 'kind', 'character');
		$set  = [];

		if (array_key_exists('name', $data) AND $kind !== 'narrator')
		{
			$name = self::clean_character_name(UTF8::substr(trim((string) $data['name']), 0, 120));

			if ($name === '')
			{
				return ['ok' => FALSE, 'error' => 'У персонажа должно быть имя.'];
			}

			if (self::is_narrator_name($name))
			{
				return ['ok' => TRUE, 'fold' => TRUE, 'set' => []];
			}

			$set['name'] = $name;
			$set['key']  = mb_substr(self::name_key($name), 0, 40);
		}

		if (array_key_exists('voice', $data))
		{
			$set['voice'] = UTF8::substr(trim((string) $data['voice']), 0, 80);
		}

		if (array_key_exists('description', $data))
		{
			$set['description'] = UTF8::substr(trim((string) $data['description']), 0, 1000);
		}

		if (array_key_exists('instructions', $data))
		{
			$set['instructions'] = UTF8::substr(trim((string) $data['instructions']), 0,
				(int) self::limits('max_instructions', 300));
		}

		if (array_key_exists('locked', $data))
		{
			$set['locked'] = ((int) $data['locked']) ? 1 : 0;
		}

		if (array_key_exists('gender', $data)
			AND Arr::get(self::genders(), (string) $data['gender']) !== NULL)
		{
			$set['gender'] = (string) $data['gender'];
		}

		if (array_key_exists('age', $data)
			AND Arr::get(self::ages(), (string) $data['age']) !== NULL)
		{
			$set['age'] = (string) $data['age'];
		}

		return ['ok' => TRUE, 'fold' => FALSE, 'set' => $set];
	}

	public static function delete_character($book_id, $id, $allow_narrator = FALSE, $to = 0)
	{
		$row = self::character($id);

		if ($row === NULL OR (int) Arr::get($row, 'book_id', 0) !== (int) $book_id)
		{
			return ['ok' => FALSE, 'error' => 'Персонаж не найден.'];
		}

		if (Arr::get($row, 'kind') === 'narrator' AND ! $allow_narrator)
		{
			return ['ok' => FALSE, 'error' => 'Рассказчика удалить нельзя: без него авторский текст некому читать.'];
		}

		$people = self::characters($book_id);

		if ($allow_narrator)
		{
			$to_speaker = '0';

			foreach ($people as $one)
			{
				if (Arr::get($one, 'kind') === 'narrator' AND (int) Arr::get($one, 'id') !== (int) $id)
				{
					$to_speaker = (string) Arr::get($one, 'id', 0);
					break;
				}
			}
		}
		else
		{
			$target = self::delete_remap_to($id, $to, $people);

			if ( ! Arr::get($target, 'ok'))
			{
				return $target;
			}

			$to_speaker = (string) Arr::get($target, 'to', '0');
		}

		self::_exec('DELETE FROM nj_666_characters WHERE id = '.(int) $id);

		// Реплики удалённого — выбранному (корзина: рассказчику). Иначе блок
		// озвучится чужим или пустым голосом (владелец, 11.10.2026).
		self::remap_block_speakers($book_id, (string) $id, $to_speaker);

		return ['ok' => TRUE, 'to' => $to_speaker];
	}

	/**
	 * Кому отдать реплики при удалении. $to=0 — рассказчик. Нельзя на себя
	 * и нельзя удалить рассказчика (владелец, 11.10.2026).
	 *
	 * @return array {ok, to?, error?}
	 */
	public static function delete_remap_to($id, $to, array $characters)
	{
		$id = (int) $id;
		$to = (int) $to;

		$by       = [];
		$narrator = 0;
		$self     = NULL;

		foreach ($characters as $row)
		{
			$cid = (int) Arr::get($row, 'id', 0);

			if ($cid <= 0)
			{
				continue;
			}

			$by[$cid] = $row;

			if (Arr::get($row, 'kind') === 'narrator' AND $narrator === 0)
			{
				$narrator = $cid;
			}

			if ($cid === $id)
			{
				$self = $row;
			}
		}

		if ($self === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Персонаж не найден.'];
		}

		if (Arr::get($self, 'kind') === 'narrator')
		{
			return ['ok' => FALSE, 'error' => 'Рассказчика удалить нельзя: без него авторский текст некому читать.'];
		}

		if ($to <= 0)
		{
			$to = $narrator;
		}

		if ($to === $id)
		{
			return ['ok' => FALSE, 'error' => 'Нельзя отдать реплики тому же персонажу.'];
		}

		if ($to <= 0 OR ! isset($by[$to]))
		{
			return ['ok' => FALSE, 'error' => 'Кому передать реплики — не найден.'];
		}

		return ['ok' => TRUE, 'to' => (string) $to];
	}

	/**
	 * speaker в turns_json: с $from на $to. Только цифры id или narrator —
	 * строка идёт в SQL как есть.
	 */
	public static function remap_block_speakers($book_id, $from, $to)
	{
		$from = (string) $from;
		$to   = (string) $to;

		if ($from === '' OR $to === '' OR $from === $to)
		{
			return FALSE;
		}

		$ok_id = static function ($value) {
			return $value === 'narrator' OR preg_match('~^[0-9]+$~', $value) === 1;
		};

		if ( ! $ok_id($from) OR ! $ok_id($to))
		{
			return FALSE;
		}

		// jsonb_agg по ВСЕМ элементам с CASE, а не выборка по WHERE —
		// иначе блок потерял бы всех остальных говорящих.
		self::_exec('UPDATE nj_666_blocks
			SET turns_json = (
				SELECT jsonb_agg(
					CASE WHEN t->>\'speaker\' = \''.$from.'\'
						THEN jsonb_set(t, \'{speaker}\', to_jsonb(\''.$to.'\'::text))
						ELSE t END)
				FROM jsonb_array_elements(turns_json) t)
			WHERE book_id = '.(int) $book_id.'
				AND turns_json IS NOT NULL
				AND jsonb_typeof(turns_json) = \'array\'');

		return TRUE;
	}

	/** Сколько раз персонаж встретился: по нему сортируем список для модели. */
	public static function touch_character($id, $times = 1)
	{
		self::_exec('UPDATE nj_666_characters
			SET mentions = mentions + '.(int) $times.', updated_at = now()
			WHERE id = '.(int) $id);

		return TRUE;
	}

	/**
	 * Слить клонов рассказчика в одного. Реплики переезжают, лишние строки
	 * удаляются. Вызывать со страницы книги и при разборе — иначе старые
	 * «Рассказчик (голос 1)» так и висят (11.10.2026).
	 */
	public static function fold_narrator_aliases($book_id)
	{
		$book_id = (int) $book_id;

		if ($book_id <= 0)
		{
			return 0;
		}

		$keep    = self::ensure_narrator($book_id);
		$folded  = 0;

		foreach (self::characters($book_id) as $row)
		{
			$id = (int) Arr::get($row, 'id');

			if ($id === (int) $keep)
			{
				continue;
			}

			if (Arr::get($row, 'kind') === 'narrator'
				OR self::is_narrator_name((string) Arr::get($row, 'name', '')))
			{
				self::delete_character($book_id, $id, TRUE);
				$folded++;
			}
		}

		return $folded;
	}

	/**
	 * Слить «Принц» / «Принц (голос)» / «Принц (голос 2, новая)» в одного.
	 * Реплики переезжают на оставшегося, имя чистится от хвоста (11.10.2026).
	 */
	public static function fold_character_aliases($book_id)
	{
		$book_id = (int) $book_id;

		if ($book_id <= 0)
		{
			return 0;
		}

		$groups = [];

		foreach (self::characters($book_id) as $row)
		{
			if (Arr::get($row, 'kind') === 'narrator'
				OR self::is_narrator_name((string) Arr::get($row, 'name', '')))
			{
				continue;
			}

			$stem = self::name_key((string) Arr::get($row, 'name', ''));

			if ($stem === '')
			{
				continue;
			}

			$groups[$stem][] = $row;
		}

		$folded = 0;

		foreach ($groups as $rows)
		{
			if (count($rows) < 2)
			{
				$keep = $rows[0];
				self::_polish_character_name($keep);
				continue;
			}

			usort($rows, ['Service_Nj666_Multivoice', '_prefer_character']);
			$keep = $rows[0];
			$kid  = (int) Arr::get($keep, 'id');

			self::_polish_character_name($keep);

			foreach (array_slice($rows, 1) as $extra)
			{
				$eid = (int) Arr::get($extra, 'id');

				if ($eid <= 0 OR $eid === $kid)
				{
					continue;
				}

				self::remap_block_speakers($book_id, (string) $eid, (string) $kid);
				self::_exec('DELETE FROM nj_666_characters WHERE id = '.$eid
					.' AND book_id = '.$book_id);
				self::touch_character($kid, max(1, (int) Arr::get($extra, 'mentions', 1)));
				$folded++;
			}
		}

		return $folded;
	}

	/** Рассказчик и клоны «(голос N)» — одним заходом. */
	public static function fold_aliases($book_id)
	{
		return self::fold_narrator_aliases($book_id) + self::fold_character_aliases($book_id);
	}

	/** Кого оставить из двух тёзок: замок, упоминания, короткое имя, меньший id. */
	public static function _prefer_character($a, $b)
	{
		$la = (int) Arr::get($a, 'locked');
		$lb = (int) Arr::get($b, 'locked');

		if ($la !== $lb)
		{
			return $lb - $la;
		}

		$ma = (int) Arr::get($a, 'mentions');
		$mb = (int) Arr::get($b, 'mentions');

		if ($ma !== $mb)
		{
			return $mb - $ma;
		}

		$na = UTF8::strlen((string) Arr::get($a, 'name', ''));
		$nb = UTF8::strlen((string) Arr::get($b, 'name', ''));

		if ($na !== $nb)
		{
			return $na - $nb;
		}

		return (int) Arr::get($a, 'id') - (int) Arr::get($b, 'id');
	}

	protected static function _polish_character_name(array $row)
	{
		$id    = (int) Arr::get($row, 'id');
		$clean = self::clean_character_name((string) Arr::get($row, 'name', ''));

		if ($id <= 0 OR $clean === '' OR $clean === (string) Arr::get($row, 'name', ''))
		{
			return;
		}

		self::_exec('UPDATE nj_666_characters SET name = '.self::_q(UTF8::substr($clean, 0, 120))
			.', key = '.self::_q(mb_substr(self::name_key($clean), 0, 40))
			.', updated_at = now() WHERE id = '.$id);
	}

	// ---------------------------------------------------------------- блоки

	public static function blocks($book_id)
	{
		return self::_all('SELECT * FROM nj_666_blocks
			WHERE book_id = '.(int) $book_id.' ORDER BY no ASC, id ASC');
	}

	public static function block($id)
	{
		return self::_one('SELECT * FROM nj_666_blocks WHERE id = '.(int) $id);
	}

	public static function block_by_no($book_id, $no)
	{
		$book_id = (int) $book_id;
		$no      = (int) $no;

		if ($book_id <= 0 OR $no <= 0)
		{
			return NULL;
		}

		return self::_one('SELECT * FROM nj_666_blocks
			WHERE book_id = '.$book_id.' AND no = '.$no.' LIMIT 1');
	}

	/** Перед повторным разбором старые блоки и их файлы убираем полностью. */
	public static function clear_blocks($book_id, $book = NULL)
	{
		$book = $book === NULL ? self::book_by_id($book_id) : $book;

		foreach (self::blocks($book_id) as $block)
		{
			if ((string) Arr::get($block, 'status') === 'voiced')
			{
				@unlink(self::block_file($book, $block));
			}
		}

		self::_exec('DELETE FROM nj_666_blocks WHERE book_id = '.(int) $book_id);

		return TRUE;
	}

	/**
	 * Принять блоки от воркера: реплики храним как есть (speaker — id
	 * персонажа или 'narrator'), голоса подставим в момент озвучки.
	 */
	public static function apply_blocks($book_id, array $blocks)
	{
		self::fold_aliases($book_id);
		$people = self::characters((int) $book_id);

		// Воркер шлёт блоки пачками. Без MAX(no) каждая пачка снова
		// нумеровалась с 1 — озвучка шла вперемешку (владелец, 11.10.2026).
		$no = 0;

		try
		{
			$no = (int) self::_value('SELECT COALESCE(MAX(no), 0) FROM nj_666_blocks
				WHERE book_id = '.(int) $book_id);
		}
		catch (Throwable $e)
		{
			$no = 0;
		}

		foreach ($blocks as $block)
		{
			if ( ! is_array($block))
			{
				continue;
			}

			$turns = self::infer_turn_speakers(
				self::clean_block_turns((array) Arr::get($block, 'turns', [])),
				self::characters((int) $book_id));

			if ( ! $turns)
			{
				continue;
			}

			$no++;

			if ($no > (int) self::limits('max_blocks', 400))
			{
				break;
			}

			$chars = 0;

			foreach ($turns as $turn)
			{
				$chars += UTF8::strlen((string) Arr::get($turn, 'text', ''));
			}

			self::_insert('nj_666_blocks', [
				'book_id' => (int) $book_id,
				'no'      => $no,
				'title'   => UTF8::substr(trim((string) Arr::get($block, 'title', '')), 0, 300),
				'chars'   => $chars,
				'turns_json' => json_encode($turns, JSON_UNESCAPED_UNICODE),
				'status'  => 'new',
			]);
		}

		return $no;
	}

	/** Реплики блока: [{speaker, text}] — без пустых и без мусора. */
	public static function clean_block_turns(array $turns)
	{
		$out = [];

		foreach ($turns as $turn)
		{
			if (is_string($turn))
			{
				$turn = ['speaker' => 'narrator', 'text' => $turn];
			}

			if ( ! is_array($turn))
			{
				continue;
			}

			$text = Service_Nj665_Voice::clean_text((string) Arr::get($turn, 'text', ''));

			if ($text === '')
			{
				continue;
			}

			$speaker = trim((string) Arr::get($turn, 'speaker', 'narrator'));
			$row     = [
				'speaker' => $speaker === '' ? 'narrator' : $speaker,
				'text'    => $text,
			];

			if (array_key_exists('p', $turn) AND (int) $turn['p'] > 0)
			{
				$row['p'] = (int) $turn['p'];
			}

			$out[] = $row;
		}

		return self::split_quoted_turns($out);
	}

	/** Реплики как лежат в turns_json (или уже массивом). */
	public static function block_turns(array $block)
	{
		$raw = Arr::get($block, 'turns_json', NULL);

		if (is_array($raw))
		{
			return $raw;
		}

		if (is_array(Arr::get($block, 'turns')))
		{
			return $block['turns'];
		}

		$decoded = json_decode((string) $raw, TRUE);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Ручная правка реплик: кавычки не режем — человек уже расставил
	 * говорящих. Неизвестный speaker → рассказчик (владелец, 11.10.2026).
	 */
	public static function edit_block_turns(array $turns, array $characters = [])
	{
		$allowed  = ['narrator' => TRUE];
		$restrict = FALSE;
		$nar_id   = 0;

		foreach ($characters as $row)
		{
			$cid = (int) Arr::get($row, 'id', 0);

			if ($cid <= 0)
			{
				continue;
			}

			$restrict = TRUE;
			$allowed[(string) $cid] = TRUE;

			if (Arr::get($row, 'kind') === 'narrator' AND $nar_id === 0)
			{
				$nar_id = $cid;
			}
		}

		$out = [];

		foreach ($turns as $turn)
		{
			if (is_string($turn))
			{
				$turn = ['speaker' => 'narrator', 'text' => $turn];
			}

			if ( ! is_array($turn))
			{
				continue;
			}

			$text = Service_Nj665_Voice::clean_text((string) Arr::get($turn, 'text', ''));

			if ($text === '')
			{
				continue;
			}

			$speaker = trim((string) Arr::get($turn, 'speaker', 'narrator'));

			if ($speaker === '' OR $speaker === '0')
			{
				$speaker = 'narrator';
			}

			if ($nar_id > 0 AND $speaker === (string) $nar_id)
			{
				$speaker = 'narrator';
			}

			if ($restrict AND ! isset($allowed[$speaker]))
			{
				$speaker = 'narrator';
			}

			$row = [
				'speaker' => $speaker,
				'text'    => $text,
			];

			if (array_key_exists('p', $turn) AND (int) $turn['p'] > 0)
			{
				$row['p'] = (int) $turn['p'];
			}

			$out[] = $row;

			if (count($out) >= 500)
			{
				break;
			}
		}

		return $out;
	}

	/** Сравнение реплик без поля p: абзац не должен сбрасывать озвучку. */
	public static function turns_fingerprint(array $turns)
	{
		$norm = [];

		foreach ($turns as $turn)
		{
			if ( ! is_array($turn))
			{
				continue;
			}

			$norm[] = [
				'speaker' => (string) Arr::get($turn, 'speaker', 'narrator'),
				'text'    => (string) Arr::get($turn, 'text', ''),
			];
		}

		return json_encode($norm, JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Сохранить title/turns блока. Смена реплик сбрасывает status и файл
	 * озвучки; одна смена заголовка — нет (владелец, 11.10.2026).
	 */
	public static function save_block($book_id, $no, array $data)
	{
		$book_id = (int) $book_id;
		$block   = self::block_by_no($book_id, $no);

		if ($block === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Блок не найден.'];
		}

		$book   = self::book_by_id($book_id);
		$people = self::characters($book_id);
		$title  = UTF8::substr(trim((string) Arr::get($data, 'title', Arr::get($block, 'title', ''))), 0, 300);

		$parts = [
			'title = '.self::_q($title),
			'updated_at = now()',
		];
		$reset = FALSE;

		if (array_key_exists('turns', $data) AND is_array($data['turns']))
		{
			$new = self::edit_block_turns($data['turns'], $people);

			if ( ! $new)
			{
				return ['ok' => FALSE, 'error' => 'В блоке должна остаться хотя бы одна реплика.'];
			}

			$old = self::edit_block_turns(self::block_turns($block), $people);

			if (self::turns_fingerprint($new) !== self::turns_fingerprint($old))
			{
				$chars = 0;

				foreach ($new as $turn)
				{
					$chars += UTF8::strlen((string) Arr::get($turn, 'text', ''));
				}

				$parts[] = 'turns_json = '.self::_q(json_encode($new, JSON_UNESCAPED_UNICODE));
				$parts[] = 'chars = '.(int) $chars;
				$parts[] = 'status = \'new\'';
				$parts[] = 'error = \'\'';
				$parts[] = 'file = \'\'';
				$parts[] = 'bytes = 0';
				$reset   = TRUE;
			}
		}

		self::_exec('UPDATE nj_666_blocks SET '.implode(', ', $parts)
			.' WHERE id = '.(int) Arr::get($block, 'id').' AND book_id = '.$book_id);

		if ($reset AND $book)
		{
			self::forget_block_audio($book, $block);

			if ((string) Arr::get($book, 'status') === self::S_DONE)
			{
				self::set_status($book_id, self::S_PLANNED, '');
				self::set_stage($book_id, 'Блок изменён', 'переозвучьте блок, чтобы услышать правку');
			}
		}

		return [
			'ok'    => TRUE,
			'id'    => (int) Arr::get($block, 'id'),
			'no'    => (int) Arr::get($block, 'no'),
			'reset' => $reset,
		];
	}

	/**
	 * Прямая речь в кавычках — отдельная реплика персонажа, авторский текст
	 * вокруг — рассказчику. Иначе «возговорит зверь: "…"» уезжает одним
	 * голосом (владелец, 11.10.2026).
	 */
	public static function split_quoted_turns(array $turns)
	{
		$out = [];

		foreach ($turns as $turn)
		{
			if ( ! is_array($turn))
			{
				continue;
			}

			$speaker = trim((string) Arr::get($turn, 'speaker', 'narrator'));
			$text    = (string) Arr::get($turn, 'text', '');
			$para    = (int) Arr::get($turn, 'p', 0);
			$spans   = self::split_speech_spans($text);

			if (count($spans) <= 1 AND Arr::get($spans, 0) AND Arr::get($spans[0], 'kind') !== 'speech')
			{
				$out[] = $turn;
				continue;
			}

			foreach ($spans as $span)
			{
				$chunk = trim((string) Arr::get($span, 'text', ''));

				if ($chunk === '')
				{
					continue;
				}

				// Скобки обязательны: `AND` связывает слабее `?:` и `=`, и без них
				// в $who попадал только результат первого сравнения.
				$who = (Arr::get($span, 'kind') === 'speech' AND $speaker !== '' AND $speaker !== 'narrator')
					? $speaker
					: 'narrator';

				if ($out AND (string) Arr::get($out[count($out) - 1], 'speaker') === (string) $who)
				{
					$out[count($out) - 1]['text'] = trim($out[count($out) - 1]['text'].' '.$chunk);
					continue;
				}

				$row = ['speaker' => $who, 'text' => $chunk];

				if ($para > 0)
				{
					$row['p'] = $para;
				}

				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Нарезать абзац: кавычки и тире — речь, остальное — автор.
	 * Без разметки — один кусок kind=plain (говорящего не меняем).
	 */
	public static function split_speech_spans($text)
	{
		$text = (string) $text;

		if (trim($text) === '')
		{
			return [];
		}

		$dash = self::_split_dash_speech($text);

		if ($dash !== NULL)
		{
			return $dash;
		}

		$pairs = [
			'«' => '»',
			'"' => '"',
			'“' => '”',
			'„' => '“',
		];
		$chars  = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		$out    = [];
		$buf    = '';
		$kind   = 'narrator';
		$closer = NULL;
		$marked = FALSE;

		foreach ($chars as $ch)
		{
			if ($closer === NULL AND isset($pairs[$ch]))
			{
				if (trim($buf) !== '')
				{
					$out[] = ['kind' => $kind, 'text' => trim($buf)];
				}

				$buf    = '';
				$kind   = 'speech';
				$closer = $pairs[$ch];
				$marked = TRUE;
				continue;
			}

			if ($closer !== NULL AND $ch === $closer)
			{
				if (trim($buf) !== '')
				{
					$out[] = ['kind' => 'speech', 'text' => trim($buf)];
				}

				$buf    = '';
				$kind   = 'narrator';
				$closer = NULL;
				continue;
			}

			$buf .= $ch;
		}

		if (trim($buf) !== '')
		{
			$out[] = ['kind' => $kind, 'text' => trim($buf)];
		}

		if ( ! $marked)
		{
			$cue = self::_split_cue_speech($text);

			if ($cue !== NULL)
			{
				return $cue;
			}

			return [['kind' => 'plain', 'text' => trim($text)]];
		}

		return $out ? $out : [['kind' => 'plain', 'text' => trim($text)]];
	}

	/** Абзац с тире («— Здравствуй, — сказал он.») или NULL, если это не диалог. */
	protected static function _split_dash_speech($text)
	{
		if ( ! preg_match('~^\s*[—–]\s+~u', $text))
		{
			return NULL;
		}

		$body = preg_replace('~^\s*[—–]\s+~u', '', $text);
		$bits = preg_split('~\s+[—–]\s+~u', $body);
		$out  = [];

		foreach (array_values(array_filter($bits, 'strlen')) as $i => $bit)
		{
			$chunk = trim($bit);

			if ($chunk === '')
			{
				continue;
			}

			$out[] = ['kind' => ($i % 2 === 0) ? 'speech' : 'narrator', 'text' => $chunk];
		}

	return $out ? $out : NULL;
	}

	/**
	 * «говорит купец: Милые доченьки» — автор и речь, даже без кавычек
	 * (владелец, 11.10.2026).
	 */
	protected static function _split_cue_speech($text)
	{
		$text = (string) $text;

		if ( ! preg_match('~^(.*(?:(?:воз)?говор(?:ит|ил[аои]?|ят)|сказ(?:ал[аои]?|ывает)|молв(?:ил[аои]?|ит)|восклик(?:нул[аои]?|ает)|отвеч(?:ал[аои]?|ает)|спрос(?:ил[аои]?|ит)|промолв(?:ил[аои]?|ит)|произнес(?:ла|ли|ло|ёт|ет)?|крикн(?:ул[аои]?|ет)|шепн(?:ул[аои]?|ет)|закрич(?:ал[аои]?|ит)|проговор(?:ил[аои]?|ит))[^\n:]{0,80})[:：]\s+(\S.+)$~usi', $text, $m))
		{
			return NULL;
		}

		$head = trim($m[1]);
		$tail = trim($m[2]);

		if ($head === '' OR UTF8::strlen($tail) < 8)
		{
			return NULL;
		}

		return [
			['kind' => 'narrator', 'text' => rtrim($head, ':：').':'],
			['kind' => 'speech', 'text' => $tail],
		];
	}

	/**
	 * Речь после «говорит купец:» без кавычек не оставляем narrator —
	 * иначе озвучка одним голосом, а движок без voice сам выбирает
	 * случайный (владелец, 11.10.2026).
	 */
	public static function infer_turn_speakers(array $turns, array $characters)
	{
		$by_name = [];
		$tokens  = [];
		$by_id   = [];

		foreach ($characters as $row)
		{
			if ( ! is_array($row))
			{
				continue;
			}

			if (Arr::get($row, 'kind') === 'narrator' OR self::is_narrator_name((string) Arr::get($row, 'name', '')))
			{
				continue;
			}

			$id = (string) Arr::get($row, 'id', '');

			if ($id !== '')
			{
				$by_id[$id] = $row;
			}

			$name = self::name_key((string) Arr::get($row, 'name', ''));

			if ($name === '')
			{
				continue;
			}

			$by_name[$name] = $row;

			foreach (preg_split('~\s+~u', $name) as $tok)
			{
				if (UTF8::strlen($tok) >= 5)
				{
					$tokens[$tok][] = $row;
				}
			}
		}

		$unique = [];

		foreach ($tokens as $tok => $rows)
		{
			$ids = [];

			foreach ($rows as $row)
			{
				$ids[(string) Arr::get($row, 'id')] = $row;
			}

			if (count($ids) === 1)
			{
				$unique[$tok] = reset($ids);
			}
		}

		$last = NULL;
		$out  = [];

		foreach (array_values($turns) as $i => $turn)
		{
			if ( ! is_array($turn))
			{
				continue;
			}

			$text    = (string) Arr::get($turn, 'text', '');
			$speaker = trim((string) Arr::get($turn, 'speaker', 'narrator'));
			$speech  = ($out AND self::_looks_like_cue((string) Arr::get($out[count($out) - 1], 'text', '')));

			if ($speaker !== '' AND $speaker !== 'narrator' AND isset($by_id[$speaker]))
			{
				$last = $by_id[$speaker];
				$out[] = $turn;
				continue;
			}

			if ($speaker !== '' AND $speaker !== 'narrator' AND ! self::is_narrator_name($speaker))
			{
				$named = self::_character_in_text($speaker, $by_name, $unique);

				if ($named !== NULL)
				{
					$turn['speaker'] = (string) Arr::get($named, 'id');
					$last = $named;
					$out[] = $turn;
					continue;
				}
			}

			$found = self::_character_in_text($text, $by_name, $unique);

			if ( ! $speech AND $found !== NULL)
			{
				$last = $found;
			}

			if ($speech AND ($speaker === '' OR $speaker === 'narrator'))
			{
				$pick = $last;

				if ($pick === NULL AND $out)
				{
					$blob = '';

					foreach (array_slice($out, -6) as $prev)
					{
						$blob .= ' '.(string) Arr::get($prev, 'text', '');
					}

					$pick = self::_character_in_text($blob, $by_name, $unique);
				}

				if ($pick !== NULL)
				{
					$turn['speaker'] = (string) Arr::get($pick, 'id');
				}
			}

			$out[] = $turn;
		}

		return $out;
	}

	protected static function _looks_like_cue($text)
	{
		$t = trim((string) $text);

		if ($t === '' OR ! preg_match('~[:：]$~u', $t))
		{
			return FALSE;
		}

		return (bool) preg_match('~(?:воз)?говор(?:ит|ил[аои]?|ят)|сказ(?:ал[аои]?|ывает)|молв(?:ил[аои]?|ит)|восклик(?:нул[аои]?|ает)|отвеч(?:ал[аои]?|ает)|спрос(?:ил[аои]?|ит)|промолв(?:ил[аои]?|ит)|произнес(?:ла|ли|ло|ёт|ет)?|крикн(?:ул[аои]?|ет)|шепн(?:ул[аои]?|ет)|закрич(?:ал[аои]?|ит)|проговор(?:ил[аои]?|ит)~ui', $t);
	}

	protected static function _character_in_text($text, array $by_name, array $unique)
	{
		$blob = self::name_key((string) $text);

		if ($blob === '')
		{
			return NULL;
		}

		$best = NULL;
		$best_len = 0;

		foreach ($by_name as $name => $row)
		{
			if ($name !== '' AND UTF8::strpos($blob, $name) !== FALSE AND UTF8::strlen($name) > $best_len)
			{
				$best     = $row;
				$best_len = UTF8::strlen($name);
			}
		}

		if ($best !== NULL)
		{
			return $best;
		}

		$best_tok = '';

		foreach ($unique as $tok => $row)
		{
			if (UTF8::strpos($blob, $tok) !== FALSE AND UTF8::strlen($tok) > UTF8::strlen($best_tok))
			{
				$best_tok = $tok;
				$best     = $row;
			}
		}

		return $best;
	}

	/**
	 * Реплики блока с голосами: персонаж → его голос (или голос книги),
	 * плюс инструкция «как говорить», если она задана.
	 */
	public static function block_turns_with_voices($book, array $block, array $characters = NULL)
	{
		$characters = $characters === NULL ? self::characters((int) Arr::get($book, 'id', 0)) : $characters;

		$by_id   = [];
		$narator = NULL;

		foreach ($characters as $row)
		{
			$by_id[(string) Arr::get($row, 'id')] = $row;

			if (Arr::get($row, 'kind') === 'narrator' AND $narator === NULL)
			{
				$narator = $row;
			}
		}

		$default = trim((string) Arr::get($book, 'default_voice', ''));

		if ($default === '' AND $narator !== NULL)
		{
			$default = (string) Arr::get($narator, 'voice', '');
		}

		$out = [];

		$raw_turns = self::infer_turn_speakers(
			self::clean_block_turns((array) json_decode((string) Arr::get($block, 'turns_json', ''), TRUE)),
			$characters);

		foreach ($raw_turns as $turn)
		{
			$text = (string) Arr::get($turn, 'text', '');

			if ($text === '')
			{
				continue;
			}

			$speaker = (string) Arr::get($turn, 'speaker', 'narrator');
			$row     = $speaker === 'narrator' ? $narator : Arr::get($by_id, $speaker);

			$voice = $row === NULL ? '' : trim((string) Arr::get($row, 'voice', ''));

			if ($voice === '')
			{
				$voice = $default;
			}

			$item = ['text' => $text];

			if ($voice !== '')
			{
				$item['voice'] = $voice;
			}

			$note = $row === NULL ? '' : trim((string) Arr::get($row, 'instructions', ''));

			if ($note !== '')
			{
				$item['instructions'] = UTF8::substr($note, 0, (int) self::limits('max_instructions', 300));
			}

			$out[] = $item;
		}

		return $out;
	}

	// -------------------------------------------------------------- озвучка

	/**
	 * Озвучить один блок: реплики собираются в один запрос (input — массив
	 * Turn{text, voice}), файл кладётся в каталог книги.
	 */
	public static function voice_block($book_id, $block_id)
	{
		$book  = self::book_by_id($book_id);
		$block = self::block($block_id);

		if ($book === NULL OR $block === NULL OR (int) Arr::get($block, 'book_id', 0) !== (int) $book_id)
		{
			return ['ok' => FALSE, 'error' => 'Блок не найден.'];
		}

		$turns = self::block_turns_with_voices($book, $block);

		if ( ! $turns)
		{
			self::_block_fail($block_id, 'В блоке нет реплик.');

			return ['ok' => FALSE, 'error' => 'В блоке нет реплик.'];
		}

		$dir = self::book_dir($book);

		if ( ! is_dir($dir) AND ! @mkdir($dir, 0755, TRUE) AND ! is_dir($dir))
		{
			self::_block_fail($block_id, 'Не удалось создать каталог книги.');

			return ['ok' => FALSE, 'error' => 'Не удалось создать каталог книги.'];
		}

		// Книга «одним блоком» — это сотни килобайт текста за раз: внешний
		// движок столько не съест, поэтому режем блок на запросы по лимиту
		// синтеза (NJ665_MAX_CHARS), не по лимиту книги, и склеиваем звук
		// обратно в один файл (10.10.2026, правка 11.10.2026).
		$format = (string) Arr::get($book, 'format', 'mp3');
		$max    = self::synth_chunk_chars();
		$parts  = [];
		$chars  = 0;
		$model  = (string) Arr::get($book, 'model', '');
		$real   = $format;

		foreach (self::segment_turns($turns, $max) as $chunk)
		{
			$res = Service_Nj665_Voice::synth_bytes([
				'turns'      => $chunk,
				'model'      => $model,
				'voice'      => trim((string) Arr::get($book, 'default_voice', '')),
				'format'     => $format,
				'speed'      => (float) Arr::get($book, 'speed', 1.0),
				'language'   => (string) Arr::get($book, 'language', ''),
			]);

			if ( ! Arr::get($res, 'ok'))
			{
				$error = (string) Arr::get($res, 'error', 'Синтез не удался.');

				self::_block_fail($block_id, $error);

				return ['ok' => FALSE, 'error' => $error];
			}

			$parts[] = (string) Arr::get($res, 'bytes', '');
			$chars  += (int) Arr::get($res, 'chars', 0);
			$real    = (string) Arr::get($res, 'format', $format);
		}

		$audio = self::concat_audio($parts, $real);

		if ($audio === '')
		{
			self::_block_fail($block_id, 'Синтез вернул пустой звук.');

			return ['ok' => FALSE, 'error' => 'Синтез вернул пустой звук.'];
		}

		return self::_store_block_audio($book, $block, $audio, $real, $chars, count($parts));
	}

	/**
	 * Реплики блока + настройки синтеза для воркера.
	 *
	 * Текст реплик и нарезка на куски NJ665_MAX_CHARS живут здесь: воркер
	 * не ходит в базу и не должен сам решать, где резать главу.
	 */
	public static function worker_block($book_id, $block_id)
	{
		$book  = self::book_by_id($book_id);
		$block = self::block($block_id);

		if ($book === NULL OR $block === NULL OR (int) Arr::get($block, 'book_id', 0) !== (int) $book_id)
		{
			return ['ok' => FALSE, 'error' => 'Блок не найден.'];
		}

		$turns = self::block_turns_with_voices($book, $block);

		if ( ! $turns)
		{
			return ['ok' => FALSE, 'error' => 'В блоке нет реплик.'];
		}

		$max    = self::synth_chunk_chars();
		$chunks = self::segment_turns($turns, $max);
		$chars  = 0;

		foreach ($turns as $turn)
		{
			$chars += UTF8::strlen((string) Arr::get((array) $turn, 'text', ''));
		}

		return [
			'ok'        => TRUE,
			'book_id'   => (int) $book_id,
			'block_id'  => (int) $block_id,
			'no'        => (int) Arr::get($block, 'no', 0),
			'model'     => self::synth_model($book),
			'voice'     => trim((string) Arr::get($book, 'default_voice', '')),
			'format'    => (string) Arr::get($book, 'format', 'mp3'),
			'speed'     => (float) Arr::get($book, 'speed', 1.0),
			'language'  => (string) Arr::get($book, 'language', ''),
			'max_chars' => $max,
			'chars'     => $chars,
			'chunks'    => $chunks,
		];
	}

	/**
	 * Принять звук блока от воркера: один кусок или последний из серии.
	 *
	 * Куски (part/total) пишем во временные файлы и склеиваем на последнем —
	 * так глава не едет через nginx одним 64-мегабайтным JSON, а wav
	 * пересобирает шапку уже существующий concat_audio().
	 */
	public static function accept_audio($book_id, $block_id, $bytes, array $opt = [])
	{
		$book  = self::book_by_id($book_id);
		$block = self::block($block_id);

		if ($book === NULL OR $block === NULL OR (int) Arr::get($block, 'book_id', 0) !== (int) $book_id)
		{
			return ['ok' => FALSE, 'error' => 'Блок не найден.'];
		}

		$error = trim((string) Arr::get($opt, 'error', ''));

		if ($error !== '')
		{
			self::_block_fail($block_id, $error);

			return ['ok' => FALSE, 'error' => $error];
		}

		$bytes = (string) $bytes;

		if ($bytes === '')
		{
			self::_block_fail($block_id, 'Синтез вернул пустой звук.');

			return ['ok' => FALSE, 'error' => 'Синтез вернул пустой звук.'];
		}

		if (strlen($bytes) > 32 * 1024 * 1024)
		{
			self::_block_fail($block_id, 'Кусок звука слишком большой.');

			return ['ok' => FALSE, 'error' => 'Кусок звука слишком большой.'];
		}

		$format = (string) Arr::get($opt, 'format', Arr::get($book, 'format', 'mp3'));
		$sniff  = class_exists('Service_Nj665_Voice')
			? Service_Nj665_Voice::sniff_format($bytes) : NULL;
		$format = $sniff ?: $format;
		$part   = max(0, (int) Arr::get($opt, 'part', 0));
		$total  = max(1, (int) Arr::get($opt, 'total', 1));
		$chars  = (int) Arr::get($opt, 'chars', 0);

		$dir = self::book_dir($book);

		if ( ! is_dir($dir) AND ! @mkdir($dir, 0755, TRUE) AND ! is_dir($dir))
		{
			self::_block_fail($block_id, 'Не удалось создать каталог книги.');

			return ['ok' => FALSE, 'error' => 'Не удалось создать каталог книги.'];
		}

		if ($total <= 1)
		{
			return self::_store_block_audio($book, $block, $bytes, $format, $chars, 1);
		}

		$no        = (int) Arr::get($block, 'no', 0);
		$part_path = $dir.DIRECTORY_SEPARATOR.sprintf('block-%03d.p%03d', $no, $part);

		if ($part === 0)
		{
			self::_clear_block_parts($book, $block);
		}

		if (@file_put_contents($part_path, $bytes) === FALSE)
		{
			self::_block_fail($block_id, 'Не удалось сохранить кусок звука.');

			return ['ok' => FALSE, 'error' => 'Не удалось сохранить кусок звука.'];
		}

		if ($part + 1 < $total)
		{
			return ['ok' => TRUE, 'block' => (int) $block_id, 'part' => $part, 'waiting' => TRUE];
		}

		$parts = [];

		for ($i = 0; $i < $total; $i++)
		{
			$path    = $dir.DIRECTORY_SEPARATOR.sprintf('block-%03d.p%03d', $no, $i);
			$parts[] = is_file($path) ? (string) @file_get_contents($path) : '';
		}

		$audio = self::concat_audio($parts, $format);

		if ($audio === '')
		{
			self::_block_fail($block_id, 'Синтез вернул пустой звук.');

			return ['ok' => FALSE, 'error' => 'Синтез вернул пустой звук.'];
		}

		$real = class_exists('Service_Nj665_Voice')
			? (Service_Nj665_Voice::sniff_format($audio) ?: $format) : $format;

		$done = self::_store_block_audio($book, $block, $audio, $real, $chars, $total);

		if (Arr::get($done, 'ok'))
		{
			self::_clear_block_parts($book, $block);
		}

		return $done;
	}

	/** Записать готовый файл блока и пометить voiced. */
	protected static function _store_block_audio($book, array $block, $audio, $format, $chars, $parts)
	{
		$block_id = (int) Arr::get($block, 'id', 0);
		$file     = self::block_path($book, $block, $format);

		if (@file_put_contents($file, $audio) === FALSE)
		{
			self::_block_fail($block_id, 'Не удалось сохранить файл блока.');

			return ['ok' => FALSE, 'error' => 'Не удалось сохранить файл блока.'];
		}

		$bytes = strlen($audio);

		self::_exec('UPDATE nj_666_blocks
			SET status = \'voiced\', file = '.self::_q(basename($file)).', bytes = '.(int) $bytes.',
				error = \'\', updated_at = now()
			WHERE id = '.(int) $block_id);

		return [
			'ok'     => TRUE,
			'block'  => $block_id,
			'bytes'  => $bytes,
			'chars'  => (int) $chars,
			'parts'  => (int) $parts,
			'format' => (string) $format,
		];
	}

	protected static function _clear_block_parts($book, array $block)
	{
		$dir  = self::book_dir($book);
		$no   = (int) Arr::get($block, 'no', 0);
		$glob = $dir.DIRECTORY_SEPARATOR.sprintf('block-%03d.p*', $no);

		foreach (glob($glob) ?: [] as $file)
		{
			@unlink($file);
		}
	}

	/**
	 * Реплики блока → порции, каждая не длиннее $max_chars.
	 *
	 * Режем по границам реплик: внутри одной реплики голос не меняется,
	 * поэтому склейка будет незаметной. Реплика длиннее лимита режется по
	 * предложениям — иначе один монолог не уедет ни в какой запрос.
	 */
	public static function segment_turns(array $turns, $max_chars)
	{
		$max = max(200, (int) $max_chars);

		if ($max >= 1000000)
		{
			return [$turns];
		}

		$flat = [];

		foreach ($turns as $turn)
		{
			if (is_string($turn))
			{
				$turn = ['text' => $turn];
			}

			$text = trim((string) Arr::get((array) $turn, 'text', ''));

			if ($text === '')
			{
				continue;
			}

			$voice = (string) Arr::get((array) $turn, 'voice', '');
			$instr = (string) Arr::get((array) $turn, 'instructions', '');

			foreach (self::split_text($text, $max) as $piece)
			{
				$flat[] = ['text' => $piece, 'voice' => $voice, 'instructions' => $instr];
			}
		}

		$chunks = [];
		$cur    = [];
		$size   = 0;

		foreach ($flat as $turn)
		{
			$len = UTF8::strlen($turn['text']);

			if ($cur AND $size + $len > $max)
			{
				$chunks[] = $cur;
				$cur      = [];
				$size     = 0;
			}

			$cur[]  = $turn;
			$size  += $len;
		}

		if ($cur)
		{
			$chunks[] = $cur;
		}

		return $chunks;
	}

	/**
	 * Режем длинный текст по границам предложений (чистая функция).
	 *
	 * Ищем последний «конец фразы» (.!?…) в пределах лимита: так склейка
	 * приходится на паузу между фразами. Нет такой точки — режем по слову,
	 * а уж потом и жёстко по символам (очень длинное слово без пробелов).
	 */
	public static function split_text($text, $max_chars)
	{
		$text = trim((string) $text);
		$max  = max(50, (int) $max_chars);

		if ($text === '' OR UTF8::strlen($text) <= $max)
		{
			return $text === '' ? [] : [$text];
		}

		// Ищем последний «конец фразы» не ближе середины куска: режем по
		// точке, а не по слову, иначе склейка придётся на середину фразы.
		$seps = ['. ', '! ', '? ', '… ', '.\n', '!\n', '?\n', '; ', ', '];

		$out  = [];
		$rest = $text;

		while (UTF8::strlen($rest) > $max)
		{
			$head = UTF8::substr($rest, 0, $max);
			$cut  = 0;

			foreach ($seps as $sep)
			{
				$pos = UTF8::strrpos($head, $sep);

				if ($pos !== FALSE AND $pos >= $max * 0.5)
				{
					$cut = $pos + UTF8::strlen($sep);

					break;
				}
			}

			// Нет конца фразы — режем по слову; нет и слова (сплошная строка
			// без пробелов) — жёстко по символам. $cut гарантированно больше
			// нуля: иначе цикл не сдвинется с места.
			if ($cut <= 0)
			{
				$pos = UTF8::strrpos($head, ' ');

				$cut = ($pos !== FALSE AND $pos >= $max * 0.5) ? $pos + 1 : $max;
			}

			$cut = max(1, (int) $cut);

			$out[] = rtrim(UTF8::substr($rest, 0, $cut));
			$rest  = ltrim(UTF8::substr($rest, $cut));

			if ($rest === '')
			{
				break;
			}
		}

		if ($rest !== '')
		{
			$out[] = $rest;
		}

		return $out;
	}

	/**
	 * Склеить куски звука в один файл.
	 *
	 * mp3/ogg/aac/flac — потоковые форматы: просто конкатенируем байты,
	 * плееры это понимают. wav — контейнер с длиной в шапке, его
	 * пересобираем, иначе плеер остановится на первом куске.
	 */
	public static function concat_audio(array $parts, $format)
	{
		$parts = array_values(array_filter($parts, function ($p) { return (string) $p !== ''; }));

		if ( ! $parts)
		{
			return '';
		}

		if (count($parts) === 1)
		{
			return $parts[0];
		}

		if ($format === 'wav')
		{
			return self::concat_wav($parts);
		}

		return implode('', $parts);
	}

	/**
	 * Склейка WAV: общая шапка RIFF с суммарной длиной + все data-куски.
	 *
	 * fmt берём из первого файла (все куски синтезированы одной моделью в
	 * одном формате), остальные fmt-блоки отбрасываем.
	 */
	public static function concat_wav(array $parts)
	{
		$fmt   = NULL;
		$data  = '';

		foreach ($parts as $part)
		{
			if (UTF8::substr($part, 0, 4) !== 'RIFF' OR UTF8::substr($part, 8, 4) !== 'WAVE')
			{
				$data .= $part;

				continue;
			}

			$pos  = 12;
			$size = strlen($part);

			while ($pos + 8 <= $size)
			{
				$id   = substr($part, $pos, 4);
				$len  = unpack('V', substr($part, $pos + 4, 4));
				$len  = $len ? (int) $len[1] : 0;
				$body = substr($part, $pos + 8, $len);

				if ($id === 'fmt ' AND $fmt === NULL)
				{
					$fmt = $body;
				}
				elseif ($id === 'data')
				{
					$data .= $body;
				}

				$pos += 8 + $len + ($len % 2);
			}
		}

		if ($fmt === NULL)
		{
			return implode('', $parts);
		}

		return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('V', strlen($fmt)).$fmt
			.'data'.pack('V', strlen($data)).$data;
	}

	protected static function _block_fail($block_id, $error)
	{
		self::_exec('UPDATE nj_666_blocks
			SET status = \'error\', error = '.self::_q(UTF8::substr($error, 0, 500)).', updated_at = now()
			WHERE id = '.(int) $block_id);

		self::_log(Log::ERROR, 'блок :id не озвучен: :error', [
			':id' => (int) $block_id, ':error' => $error]);

		return TRUE;
	}

	/** Готовность книги: сколько блоков озвучено. */
	public static function progress_of($book_id)
	{
		$row = self::_one('SELECT count(*) AS total,
				count(*) FILTER (WHERE status = \'voiced\') AS ready,
				count(*) FILTER (WHERE status = \'error\') AS failed
			FROM nj_666_blocks WHERE book_id = '.(int) $book_id);

		$total = (int) Arr::get((array) $row, 'total', 0);
		$ready = (int) Arr::get((array) $row, 'ready', 0);

		return [
			'total'  => $total,
			'ready'  => $ready,
			'failed' => (int) Arr::get((array) $row, 'failed', 0),
			'percent'=> $total > 0 ? (int) round($ready * 100 / $total) : 0,
		];
	}

	// ------------------------------------------------------------------ zip

	/**
	 * Один zip с двумя папками (владелец, 09.10.2026):
	 *
	 *   project/    — project.json (книга, персонажи, реплики блоков: можно
	 *                 восстановить и продолжить) + audio/*.mp3 как есть;
	 *   production/ — готовые нумерованные файлы «Глава 01 — название.mp3»,
	 *                 плейлист .m3u и описание с составом голосов.
	 */
	public static function build_zip($book_id)
	{
		$book = self::book_by_id($book_id);

		if ($book === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Книга не найдена.'];
		}

		if ( ! class_exists('ZipArchive'))
		{
			return ['ok' => FALSE, 'error' => 'На сервере нет ZipArchive — архив собрать нельзя.'];
		}

		$blocks = self::blocks($book_id);
		$ready  = [];

		foreach ($blocks as $block)
		{
			if ((string) Arr::get($block, 'status') === 'voiced')
			{
				$ready[] = $block;
			}
		}

		if ( ! $ready)
		{
			return ['ok' => FALSE, 'error' => 'Пока нет ни одного готового блока — нечего упаковывать.'];
		}

		$characters = self::characters($book_id);
		$dir        = self::book_dir($book);
		$zip_file   = self::zip_path($book);

		@unlink($zip_file);

		$zip = new ZipArchive;

		if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось создать архив.'];
		}

		$ext = Service_Nj665_Voice::format_ext((string) Arr::get($book, 'format', 'mp3'));

		// --- project/ ---------------------------------------------------------
		$project = [
			'version'  => 1,
			'module'   => 'nj666_multivoice',
			'code'     => (string) Arr::get($book, 'code'),
			'title'    => (string) Arr::get($book, 'title'),
			'author'   => (string) Arr::get($book, 'author'),
			'chars'    => (int) Arr::get($book, 'chars'),
			'mode'     => (string) Arr::get($book, 'mode'),
			'model'    => (string) Arr::get($book, 'model'),
			'language' => (string) Arr::get($book, 'language'),
			'format'   => (string) Arr::get($book, 'format'),
			'speed'    => (float) Arr::get($book, 'speed'),
			'blocks'   => [
				'min_minutes' => (float) Arr::get($book, 'block_min_min'),
				'max_minutes' => (float) Arr::get($book, 'block_max_min'),
				'single'      => (int) Arr::get($book, 'single_block') === 1,
			],
			'default_voice' => (string) Arr::get($book, 'default_voice'),
			'characters'    => [],
			'blocks_list'   => [],
		];

		foreach ($characters as $row)
		{
			$project['characters'][] = [
				'id'           => (int) Arr::get($row, 'id'),
				'name'         => (string) Arr::get($row, 'name'),
				'kind'         => (string) Arr::get($row, 'kind'),
				'gender'       => (string) Arr::get($row, 'gender'),
				'age'          => (string) Arr::get($row, 'age'),
				'voice'        => (string) Arr::get($row, 'voice'),
				'description'  => (string) Arr::get($row, 'description'),
				'instructions' => (string) Arr::get($row, 'instructions'),
				'locked'       => (int) Arr::get($row, 'locked') === 1,
				'mentions'     => (int) Arr::get($row, 'mentions'),
			];
		}

		foreach ($blocks as $block)
		{
			$project['blocks_list'][] = [
				'no'          => (int) Arr::get($block, 'no'),
				'title'       => (string) Arr::get($block, 'title'),
				'chars'       => (int) Arr::get($block, 'chars'),
				'status'      => (string) Arr::get($block, 'status'),
				'file'        => (string) Arr::get($block, 'file'),
				'turns'       => json_decode((string) Arr::get($block, 'turns_json', ''), TRUE) ?: [],
			];
		}

		$zip->addFromString('project/project.json',
			json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		foreach ($ready as $block)
		{
			$path = self::block_file($book, $block);

			if (is_file($path))
			{
				$zip->addFile($path, 'project/audio/'.sprintf('block-%03d', (int) Arr::get($block, 'no')).'.'.$ext);
			}
		}

		$zip->addFromString('project/README.txt', self::_readme_project($book, $characters, $blocks));

		// --- production/ ------------------------------------------------------
		$playlist = ["#EXTM3U"];

		foreach ($ready as $block)
		{
			$path = self::block_file($book, $block);

			if ( ! is_file($path))
			{
				continue;
			}

			$name = self::production_name($book, $block).'.'.$ext;

			$zip->addFile($path, 'production/'.$name);
			$playlist[] = '#EXTINF:'.self::estimate_seconds((int) Arr::get($block, 'chars'),
				(float) Arr::get($book, 'speed', 1.0)).', '.(string) Arr::get($block, 'title', $name);
			$playlist[] = $name;
		}

		$zip->addFromString('production/playlist.m3u', implode("\n", $playlist)."\n");
		$zip->addFromString('production/README.txt', self::_readme_production($book, $characters, $ready));
		$zip->close();

		return [
			'ok'    => TRUE,
			'path'  => $zip_file,
			'url'   => '/multivoice/zip/'.(string) Arr::get($book, 'code'),
			'bytes' => (int) @filesize($zip_file),
			'blocks'=> count($ready),
		];
	}

	/** Имя файла для продакшена: «Глава 01 — Название.mp3». */
	public static function production_name($book, $block)
	{
		$no    = (int) Arr::get($block, 'no', 0);
		$title = trim((string) Arr::get($block, 'title', ''));

		$name = 'Глава '.sprintf('%02d', $no).($title !== '' ? ' — '.$title : '');

		return self::safe_file_name($name);
	}

	/** Имя файла без того, что ломает файловые системы и заголовки. */
	public static function safe_file_name($name)
	{
		$name = trim((string) $name);
		$name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name);
		$name = preg_replace('~\s+~u', ' ', $name);
		$name = trim($name, ' .-');

		return $name !== '' ? UTF8::substr($name, 0, 120) : 'блок';
	}

	protected static function _readme_project($book, array $characters, array $blocks)
	{
		$lines = [
			'Многоголосая озвучка n2j.ru — проект',
			'',
			'Книга: '.(string) Arr::get($book, 'title'),
			'Автор: '.((string) Arr::get($book, 'author') ?: '—'),
			'Код: '.(string) Arr::get($book, 'code'),
			'Режим разбора: '.(string) Arr::get($book, 'mode'),
			'Модель синтеза: '.((string) Arr::get($book, 'model') ?: 'по умолчанию'),
			'Формат: '.(string) Arr::get($book, 'format').', скорость: '.(float) Arr::get($book, 'speed'),
			'',
			'Состав:',
			'  project.json    — книга, персонажи и реплики блоков (можно восстановить проект)',
			'  audio/          — файлы блоков как они вышли из синтеза',
			'',
			'Персонажи:',
		];

		foreach ($characters as $row)
		{
			$lines[] = '  '.(string) Arr::get($row, 'name')
				.' — голос: '.((string) Arr::get($row, 'voice') ?: 'не задан')
				.', упоминаний: '.(int) Arr::get($row, 'mentions')
				.((int) Arr::get($row, 'locked') === 1 ? ', закреплён' : '');
		}

		$lines[] = '';
		$lines[] = 'Блоков: '.count($blocks);

		return implode("\n", $lines)."\n";
	}

	protected static function _readme_production($book, array $characters, array $ready)
	{
		$lines = [
			(string) Arr::get($book, 'title'),
			((string) Arr::get($book, 'author') ?: 'автор не указан'),
			'',
			'Многоголосая озвучка: n2j.ru/multivoice',
			'Формат: '.(string) Arr::get($book, 'format').', скорость: '.(float) Arr::get($book, 'speed'),
			'Блоков: '.count($ready),
			'',
			'Голоса:',
		];

		foreach ($characters as $row)
		{
			if ((string) Arr::get($row, 'voice') === '')
			{
				continue;
			}

			$lines[] = '  '.(string) Arr::get($row, 'name').' — '.(string) Arr::get($row, 'voice');
		}

		$lines[] = '';
		$lines[] = 'Файлы пронумерованы по порядку, playlist.m3u открывает их по очереди.';

		return implode("\n", $lines)."\n";
	}

	// --------------------------------------------------------------- очередь

	/** Поставить задачу воркеру (nj120). Книга несёт id; extra — block_ids и т.п. */
	public static function queue($channel, $book, $timeout = NULL, array $extra = [])
	{
		if ( ! class_exists('Service_Nj120_JobQueue'))
		{
			return ['ok' => FALSE, 'error' => 'Очередь недоступна (модуль nj120 не подключён).'];
		}

		$book_id = (int) Arr::get((array) $book, 'id', 0);

		if ($book_id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Нет книги для задачи.'];
		}

		$timeout = (int) ($timeout !== NULL ? $timeout
			: Arr::get(self::branch('plan'), 'timeout_sec', 3600));

		$payload = array_merge($extra, ['book_id' => $book_id]);

		try
		{
			$job_id = Service_Nj120_JobQueue::push($channel, $payload, [
				'module'       => 'nj666',
				'visitor_id'   => 'u:'.(int) Arr::get((array) $book, 'user_id', 0),
				'created_by'   => (int) Arr::get((array) $book, 'user_id', 0),
				'max_attempts' => 2,
				// Книга — это десятки вызовов модели и столько же синтезов:
				// задача живёт часами, это нормально.
				'timeout_sec'  => $timeout,
			]);
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'очередь не приняла :channel книги :id: :e', [
				':channel' => $channel, ':id' => $book_id, ':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Очередь не приняла задачу: '.$e->getMessage()];
		}

		self::_exec('UPDATE nj_666_books SET job_id = '.(int) $job_id.', updated_at = now()
			WHERE id = '.$book_id);

		return ['ok' => TRUE, 'job_id' => (int) $job_id];
	}

	// ----------------------------------------------------------- для воркера

	/**
	 * Контекст для воркера: текст окнами, настройки, персонажи и каталог
	 * голосов (воркер в базу не ходит).
	 */
	public static function worker_context($book_id)
	{
		$book = self::book_by_id($book_id);

		if ($book === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Книга не найдена.'];
		}

		$text = self::source_text($book);

		if ($text === '')
		{
			return ['ok' => FALSE, 'error' => 'Текст книги не найден на диске.'];
		}

		self::fold_aliases($book_id);

		$targets = self::target_chars((float) Arr::get($book, 'block_min_min', 7.0),
			(float) Arr::get($book, 'block_max_min', 15.0));

		$characters = [];

		foreach (self::characters($book_id) as $row)
		{
			$characters[] = [
				'id'           => (int) Arr::get($row, 'id'),
				'name'         => (string) Arr::get($row, 'name'),
				'kind'         => (string) Arr::get($row, 'kind'),
				'gender'       => (string) Arr::get($row, 'gender'),
				'age'          => (string) Arr::get($row, 'age'),
				'voice'        => (string) Arr::get($row, 'voice'),
				'description'  => (string) Arr::get($row, 'description'),
				'locked'       => (int) Arr::get($row, 'locked') === 1,
				'mentions'     => (int) Arr::get($row, 'mentions'),
			];
		}

		$voices = [];

		foreach (Service_Nj665_Voice::voices_catalog() as $voice)
		{
			$voices[] = [
				'id'          => (string) Arr::get($voice, 'id'),
				'name'        => (string) Arr::get($voice, 'name'),
				'gender'      => (string) Arr::get($voice, 'gender'),
				'age'         => (string) Arr::get($voice, 'age'),
				'description' => (string) Arr::get($voice, 'description'),
				'model'       => (string) Arr::get($voice, 'model'),
			];
		}

		// Воркеру озвучки нужны номера блоков. Текст реплик сюда не кладём —
		// его отдаёт /block порциями под NJ665_MAX_CHARS.
		$blocks = [];

		foreach (self::blocks($book_id) as $row)
		{
			$blocks[] = [
				'id'     => (int) Arr::get($row, 'id'),
				'no'     => (int) Arr::get($row, 'no'),
				'title'  => (string) Arr::get($row, 'title'),
				'chars'  => (int) Arr::get($row, 'chars'),
				'status' => (string) Arr::get($row, 'status'),
			];
		}

		return [
			'ok'          => TRUE,
			'book_id'     => (int) $book_id,
			'code'        => (string) Arr::get($book, 'code'),
			'title'       => (string) Arr::get($book, 'title'),
			'author'      => (string) Arr::get($book, 'author'),
			'mode'        => (string) Arr::get($book, 'mode'),
			'model'       => self::synth_model($book),
			'language'    => (string) Arr::get($book, 'language'),
			'chars'       => (int) Arr::get($book, 'chars'),
			'single_block'=> (int) Arr::get($book, 'single_block') === 1,
			'targets'     => $targets,
			'plan'        => self::branch('plan'),
			'limits'      => self::limits(),
			'characters'  => $characters,
			'blocks'      => $blocks,
			'voices'      => $voices,
			'default_voice' => (string) Arr::get($book, 'default_voice'),
			'format'      => (string) Arr::get($book, 'format', 'mp3'),
			'speed'       => (float) Arr::get($book, 'speed', 1.0),
			'synth_max_chars' => self::synth_chunk_chars(),
			'text'        => $text,
		];
	}

	/** Текст книги с диска (он не в базе: файл большой). */
	public static function source_text($book)
	{
		$path = self::book_dir($book).DIRECTORY_SEPARATOR.'source.txt';

		return is_file($path) ? (string) @file_get_contents($path) : '';
	}

	// -------------------------------------------------------------- доступ

	/**
	 * Можно ли этому пользователю запускать разбор и озвучку.
	 *
	 * Книга — это не одна фраза: разбор книги это десятки вызовов модели, а
	 * озвучка — часы синтеза на внешнем GPU. Поэтому запуск закрыт правом
	 * nj666.start (роль vip или админ, решение владельца 10.10.2026 по
	 * образцу nj662), а читать и слушать свои книги может любой аккаунт.
	 *
	 * @param mixed $user модель пользователя или NULL (гость)
	 */
	public static function can_start($user = NULL)
	{
		if ($user === NULL OR ! is_object($user))
		{
			return FALSE;
		}

		$roles = [];

		try
		{
			foreach ($user->roles->find_all() as $role)
			{
				$roles[] = (string) Arr::get((array) $role->as_array(), 'name', '');
			}
		}
		catch (Exception $e)
		{
			// Роли не прочитались — не пускаем: запуск стоит денег, а
			// недосмотреть здесь дороже, чем переспросить.
			self::_log(Log::WARNING, 'роли пользователя не прочитаны: :error',
				[':error' => $e->getMessage()]);

			return FALSE;
		}

		return class_exists('ACL') ? ACL::check($roles, 'nj666.start') : FALSE;
	}

	// ------------------------------------------------------------- владение

	public static function owns(array $row, $user_id, $uid)
	{
		$user_id = (int) $user_id;
		$uid     = trim((string) $uid);

		if ($user_id > 0 AND (int) Arr::get($row, 'user_id', 0) === $user_id)
		{
			return TRUE;
		}

		return $uid !== '' AND (string) Arr::get($row, 'visitor_uid', '') === $uid;
	}

	/** Мягкое удаление книги + полное удаление файлов. */
	public static function delete($code, $user_id, $uid)
	{
		try
		{
			$book = self::book($code);
		}
		catch (Exception $e)
		{
			return ['ok' => FALSE, 'error' => 'Журнал книг недоступен, попробуйте позже.'];
		}

		if ($book === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Книга не найдена или уже удалена.'];
		}

		if ( ! self::owns($book, $user_id, $uid))
		{
			return ['ok' => FALSE, 'error' => 'Это не ваша книга.'];
		}

		try
		{
			self::_exec('UPDATE nj_666_books SET deleted_at = now(), updated_at = now()
				WHERE id = '.(int) Arr::get($book, 'id', 0).' AND deleted_at IS NULL');
		}
		catch (Exception $e)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось удалить книгу, попробуйте ещё раз.'];
		}

		// Файлы стираем сразу: книга восстановлению не подлежит (текста и
		// разбора больше нет), а место на диске занимает существенное.
		self::_wipe_dir(self::book_dir($book));

		return ['ok' => TRUE, 'code' => (string) Arr::get($book, 'code')];
	}

	/**
	 * Каталог книги целиком: файлы и вложенные папки. Только внутри
	 * storage/nj666 (владелец, 11.10.2026).
	 */
	protected static function _wipe_dir($dir)
	{
		$dir  = (string) $dir;
		$root = realpath(self::storage_dir());
		$real = is_dir($dir) ? realpath($dir) : FALSE;

		if ($dir === '' OR $real === FALSE OR $root === FALSE)
		{
			return;
		}

		if ($real === $root OR strpos($real, $root.DIRECTORY_SEPARATOR) !== 0)
		{
			return;
		}

		foreach ((array) @scandir($real) as $name)
		{
			if ($name === '.' OR $name === '..')
			{
				continue;
			}

			$path = $real.DIRECTORY_SEPARATOR.$name;

			if (is_dir($path))
			{
				self::_wipe_dir($path);
			}
			else
			{
				@unlink($path);
			}
		}

		@rmdir($real);
	}

	// -------------------------------------------------------------- уборка

	/** Удалённые книги и старые файлы: дешёвая порция на каждый запуск. */
	public static function sweep($limit = NULL)
	{
		$ttl   = max(1, (int) Arr::get(self::config(), 'ttl_days', 30));
		$limit = (int) ($limit !== NULL ? $limit : Arr::get(self::config(), 'sweep_limit', 10));

		if ($limit < 1)
		{
			return 0;
		}

		$rows = self::_all('SELECT id, code FROM nj_666_books
			WHERE (deleted_at IS NOT NULL AND deleted_at < now() - interval \'1 day\')
				OR (deleted_at IS NULL AND updated_at < now() - interval \''.$ttl.' days\')
			ORDER BY id ASC LIMIT '.$limit);

		$n = 0;

		foreach ($rows as $row)
		{
			self::_wipe_dir(self::storage_dir().DIRECTORY_SEPARATOR.basename((string) Arr::get($row, 'code')));

			self::_exec('DELETE FROM nj_666_blocks WHERE book_id = '.(int) Arr::get($row, 'id', 0));
			self::_exec('DELETE FROM nj_666_characters WHERE book_id = '.(int) Arr::get($row, 'id', 0));
			self::_exec('DELETE FROM nj_666_books WHERE id = '.(int) Arr::get($row, 'id', 0));

			$n++;
		}

		return $n;
	}

	// ------------------------------------------------------------- limiter

	public static function rate_ok($ip = NULL)
	{
		if ( ! class_exists('Service_Nj100_Ratelimit'))
		{
			return TRUE;
		}

		$rate = (array) Arr::get(self::config(), 'rate', []);
		$ip   = $ip !== NULL ? (string) $ip : Service_Nj100_Ratelimit::client_ip();

		// Считаем по книгам: одна книга = одна дорогая задача.
		$counts = Service_Nj100_Ratelimit::window_counts('nj_666_books', $ip, 'visitor_uid');

		return Service_Nj100_Ratelimit::allowed($counts,
			(int) Arr::get($rate, 'per_minute', 5),
			(int) Arr::get($rate, 'per_hour', 30));
	}

	public static function rate_text()
	{
		$rate = (array) Arr::get(self::config(), 'rate', []);

		return class_exists('Service_Nj100_Ratelimit')
			? Service_Nj100_Ratelimit::limit_text((int) Arr::get($rate, 'per_minute', 5),
				(int) Arr::get($rate, 'per_hour', 30), 'запусков')
			: '';
	}

	// ------------------------------------------------------------------ БД

	protected static function _clamp_minutes($minutes)
	{
		$min = (float) self::limits('block_min_min', 1.0);
		$max = (float) self::limits('block_max_min', 60.0);
		$val = (float) $minutes;

		if ($val < $min) $val = $min;
		if ($val > $max) $val = $max;

		return round($val, 2);
	}

	protected static function _insert($table, array $row)
	{
		$fields = [];
		$values = [];

		foreach ($row as $field => $value)
		{
			$fields[] = $field;
			$values[] = $value === NULL ? 'NULL' : self::_q($value);
		}

		$sql = 'INSERT INTO '.$table.' ('.implode(', ', $fields).') VALUES ('.implode(', ', $values).')'
			.' RETURNING id';

		$one = self::_one($sql);

		return (int) Arr::get((array) $one, 'id', 0);
	}

	protected static function _value($sql)
	{
		$row = self::_one($sql);

		return $row === NULL ? 0 : (int) reset($row);
	}

	protected static function _log($level, $message, array $params = [])
	{
		if ( ! class_exists('Log_Plain') OR ! is_object(Kohana::$log))
		{
			return;
		}

		Log_Plain::event($level, 'NJ666 '.$message, $params);
	}

	protected static function _db()
	{
		return Database::instance();
	}

	protected static function _q($value)
	{
		return self::_db()->quote($value);
	}

	protected static function _one($sql)
	{
		$row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	protected static function _all($sql)
	{
		$rows = self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();

		return $rows ? array_map(function ($row) { return (array) $row; }, $rows) : [];
	}

	protected static function _exec($sql)
	{
		return self::_db()->query(Database::INSERT, $sql, FALSE);
	}
}
