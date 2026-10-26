<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-661 «ИИ-радар»: ядро конвейера наблюдения за прогрессом ИИ.
 *
 * Разделение ответственности (как nj605_radar/nj613_digest):
 *   python-воркер nj661.airadar — всё долгое: HTTP к источникам (HuggingFace,
 *     OpenRouter, RSS, ddgs-поиск, lmarena), чтение страниц, вызовы LLM;
 *   этот сервис — хранение, дедуп, база знаний, отчёт, письмо, UI-выборки.
 *
 * База знаний (не вода): факты с нормализованным хешем (текст = личность
 * факта) и эмбеддингом (Llm::get_embedding) — семантический дедуп по косинусу
 * (паттерн nj654_similar / RAG): повторно всплывший факт не создаётся заново,
 * а наращивает sightings; недавние факты идут контекстом в анализ, чтобы LLM
 * отличал новое от известного («учитывать сделанное ранее»).
 *
 * Чистые функции (norm_url/rss_parse/openrouter_scan/hf_scan/mail_due/
 * report_sections/cosine…) не трогают БД и закрыты стендом
 * .tools/nj661_airadar_stand.php. Стиль SQL — интерполяция с $db->quote(),
 * как nj660/nj613.
 */
class Service_Nj661_Airadar {

	const CHANNEL = 'nj661.airadar';

	/** Категории анализа (единый словарь PHP ↔ воркер ↔ LLM). */
	public static $categories = [
		'breakthrough' => 'Технологический прорыв',
		'window'       => 'Окно возможностей',
		'release'      => 'Релиз модели',
		'framework'    => 'Фреймворк / инструмент',
		'niche'        => 'Недооценённое / ниша',
		'research'     => 'Исследование',
	];

	// ==================================================================
	// Чистые функции конвейера (тестируются стендом без БД и сети)
	// ==================================================================

	/**
	 * Нормализация URL для дедупа: схема+хост в нижний регистр, без фрагмента,
	 * без трекинг-параметров, без хвостового слэша. Неверный URL → ''.
	 */
	public static function norm_url($url)
	{
		$url = trim((string) $url);

		if ($url === '' OR ! preg_match('~^https?://~i', $url))
		{
			return '';
		}

		$query = parse_url($url, PHP_URL_QUERY);      // query до его вырезания
		$url = preg_replace('~[#?].*$~', '', $url);   // путь без query/фрагмента

		// хост — в нижний регистр
		if (preg_match('~^(https?://)([^/?#]+)(.*)$~i', $url, $m))
		{
			$url = strtolower($m[1]).strtolower($m[2]).$m[3];
		}

		// вернуть query без трекинг-параметров
		if ($query)
		{
			parse_str($query, $q);
			$cut = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
				'utm_term', 'fbclid', 'gclid', 'yclid', 'ref_src'];

			foreach ($cut as $k)
			{
				unset($q[$k]);
			}

			if ($q)
			{
				$url .= '?'.http_build_query($q);
			}
		}

		if (strlen($url) > 12)
		{
			$url = rtrim($url, '/');
		}

		return $url;
	}

	/** Хеш URL — ключ дедупа материалов (работу не делаем заново). */
	public static function url_hash($url)
	{
		return sha1('u:'.self::norm_url($url));
	}

	/** Хост URL (для группировки и лимитов на источник). */
	public static function url_host($url)
	{
		$host = parse_url((string) $url, PHP_URL_HOST);

		return $host ? strtolower($host) : '';
	}

	/** Нормализация текста факта: регистр, ё→е, пунктуация → пробелы. */
	public static function fact_norm($text)
	{
		$t = mb_strtolower(trim((string) $text));
		$t = str_replace('ё', 'е', $t);
		$t = preg_replace('~[^\p{L}\p{N}%+.]+~u', ' ', $t);
		$t = preg_replace('~\s+~u', ' ', $t);

		return trim($t);
	}

	/** Хеш факта: личность факта — его нормализованный текст. */
	public static function fact_hash($text)
	{
		return sha1('f:'.self::fact_norm($text));
	}

	/** Хеш идеи: личность идеи — нормализованный заголовок. */
	public static function idea_hash($title)
	{
		return sha1('i:'.self::fact_norm($title));
	}

	/** Нормализация тегов: нижний регистр, без дублей, разумный лимит. */
	public static function tags_norm($tags)
	{
		if (is_string($tags))
		{
			$tags = preg_split('~[,;]+~', $tags);
		}

		$out = [];

		foreach ((array) $tags as $tag)
		{
			$tag = mb_strtolower(trim((string) $tag));

			if (mb_strlen($tag) >= 2 AND mb_strlen($tag) <= 24 AND ! in_array($tag, $out, TRUE))
			{
				$out[] = $tag;
			}
		}

		return array_slice($out, 0, 8);
	}

	/**
	 * Разбор RSS/Atom (SimpleXML) → материалы. Ничего не знает о сети:
	 * на входе сырой XML, на выходе [{url,title,snippet,published_at}].
	 */
	public static function rss_parse($xml, $limit = 8)
	{
		$xml = trim((string) $xml);

		if ($xml === '' OR $limit <= 0 OR ! function_exists('simplexml_load_string'))
		{
			return [];
		}

		$doc = @simplexml_load_string($xml);

		if ($doc === FALSE)
		{
			return [];
		}

		$nodes = [];

		if (isset($doc->channel) AND isset($doc->channel->item))
		{
			$nodes = $doc->channel->item;
		}
		elseif (isset($doc->entry))
		{
			$nodes = $doc->entry;
		}

		$out = [];

		foreach ($nodes as $node)
		{
			$url = '';

			if (isset($node->link) AND trim((string) $node->link) !== '')
			{
				$url = trim((string) $node->link);
			}
			elseif (isset($node->link['href']))
			{
				$url = trim((string) $node->link['href']);   // Atom: <link href="…"/>
			}

			$title = isset($node->title) ? trim((string) $node->title) : '';

			if ($url === '' OR $title === '')
			{
				continue;
			}

			$snippet = '';

			if (isset($node->description))
			{
				$snippet = strip_tags((string) $node->description);
			}
			elseif (isset($node->summary))
			{
				$snippet = strip_tags((string) $node->summary);
			}

			$date = '';

			if (isset($node->pubDate))
			{
				$date = (string) $node->pubDate;
			}
			elseif (isset($node->updated))
			{
				$date = (string) $node->updated;
			}

			$ts = $date !== '' ? strtotime($date) : FALSE;

			$out[] = [
				'url'          => $url,
				'title'        => mb_substr($title, 0, 300),
				'snippet'      => mb_substr(trim($snippet), 0, 500),
				'published_at' => $ts ? date('Y-m-d H:i:s', $ts) : NULL,
			];

			if (count($out) >= $limit)
			{
				break;
			}
		}

		return $out;
	}

	/**
	 * OpenRouter /api/v1/models → материалы. Чистая скоринг-функция:
	 * «окна возможностей» — бесплатные токены; плюс новые и длинноконтекстные.
	 *
	 * @param array $models декодированный data[] из OpenRouter
	 * @param int   $now    текущий unix-time
	 * @param int   $limit  сколько материалов вернуть
	 */
	public static function openrouter_scan(array $models, $now, $limit = 30)
	{
		$out = [];

		foreach ($models as $m)
		{
			$id   = (string) Arr::get($m, 'id', '');
			$name = (string) Arr::get($m, 'name', '');

			if ($id === '' OR $name === '')
			{
				continue;
			}

			$pricing = (array) Arr::get($m, 'pricing', []);
			$prompt_price = (float) Arr::get($pricing, 'prompt', 1);
			$completion_price = (float) Arr::get($pricing, 'completion', 1);
			$free = ($prompt_price == 0 AND $completion_price == 0);

			$created = (int) Arr::get($m, 'created', 0);
			$fresh = ($created > 0 AND ($now - $created) <= 14 * 86400);
			$ctx = (int) Arr::get($m, 'context_length', 0);
			$big = $ctx >= 128000;

			$mods = implode('/', array_slice((array) Arr::get((array) Arr::get($m, 'architecture', []), 'input_modalities', []), 0, 3));
			$multimodal = (strpos($mods, 'image') !== FALSE OR strpos($mods, 'audio') !== FALSE);

			// старая платная модель с обычным контекстом — не новость
			if ( ! $free AND ! $fresh AND ! $big AND ! $multimodal)
			{
				continue;
			}

			$score = 30 + ($free ? 35 : 0) + ($fresh ? 20 : 0) + ($big ? 10 : 0) + ($multimodal ? 5 : 0);
			$score = min(100, $score);

			$snippet = 'OpenRouter · контекст '.number_format($ctx, 0, ',', ' ').' токенов'
				.($mods !== '' ? ' · ввод: '.$mods : '')
				.($free ? ' · БЕСПЛАТНО (окно возможностей)' : sprintf(' · $%.2f/M вход', $prompt_price));

			$out[] = [
				'source'       => 'openrouter',
				'url'          => 'https://openrouter.ai/'.$id,
				'title'        => $name.' ('.$id.')',
				'snippet'      => $snippet,
				'category'     => $free ? 'window' : 'release',
				'score'        => $score,
				'published_at' => $created > 0 ? date('Y-m-d H:i:s', $created) : NULL,
				'meta'         => ['id' => $id, 'free' => $free, 'context' => $ctx, 'fresh' => $fresh],
			];
		}

		usort($out, function ($a, $b) { return $b['score'] - $a['score']; });

		return array_slice($out, 0, $limit);
	}

	/**
	 * HuggingFace /api/models → материалы: новые открытые модели и заметные.
	 *
	 * @param array $models элементы ответа HF API
	 * @param int   $now    текущий unix-time
	 * @param int   $limit  сколько материалов вернуть
	 */
	public static function hf_scan(array $models, $now, $limit = 30)
	{
		$closed = ['license:other', 'license:proprietary', 'license:unknown'];
		$out = [];

		foreach ($models as $m)
		{
			$id = (string) Arr::get($m, 'id', '');

			if ($id === '')
			{
				continue;
			}

			$tags = (array) Arr::get($m, 'tags', []);
			$license = '';

			foreach ($tags as $tag)
			{
				if (strpos((string) $tag, 'license:') === 0)
				{
					$license = strtolower((string) $tag);
					break;
				}
			}

			$open = ($license !== '' AND ! in_array($license, $closed, TRUE));
			$created = strtotime((string) Arr::get($m, 'createdAt', ''));
			$created = $created === FALSE ? 0 : $created;
			$fresh = ($created > 0 AND ($now - $created) <= 7 * 86400);
			$downloads = (int) Arr::get($m, 'downloads', 0);
			$likes = (int) Arr::get($m, 'likes', 0);
			$pipeline = (string) Arr::get($m, 'pipeline_tag', '');

			// отсеиваем игрушки: интересны свежие, открытые или заметные
			if ( ! $fresh AND ! $open AND $downloads < 10000 AND $likes < 50)
			{
				continue;
			}

			$score = 25 + ($fresh ? 30 : 0) + ($open ? 20 : 0)
				+ min(25, (int) round($likes / 10))
				+ min(20, (int) round(log10(max(10, $downloads)) * 4));
			$score = min(100, $score);

			// закрытая/неясная лицензия без заметной аудитории — шум для нашей цели
			if ( ! $open AND $score < 60)
			{
				continue;
			}

			$out[] = [
				'source'       => 'huggingface',
				'url'          => 'https://huggingface.co/'.$id,
				'title'        => $id,
				'snippet'      => 'HF · '.($pipeline !== '' ? $pipeline.' · ' : '')
					.'скачиваний '.number_format($downloads, 0, ',', ' ').' · лайков '.$likes
					.($license !== '' ? ' · '.str_replace('license:', '', $license) : ''),
				'category'     => 'release',
				'score'        => $score,
				'published_at' => $created > 0 ? date('Y-m-d H:i:s', $created) : NULL,
				'meta'         => ['id' => $id, 'open' => $open, 'downloads' => $downloads, 'likes' => $likes, 'pipeline' => $pipeline],
			];
		}

		usort($out, function ($a, $b) { return $b['score'] - $a['score']; });

		return array_slice($out, 0, $limit);
	}

	/** Косинусная близость двух векторов (RAG-дедуп фактов, паттерн nj654). */
	public static function cosine(array $a, array $b)
	{
		$n = min(count($a), count($b));

		if ($n === 0)
		{
			return 0.0;
		}

		$dot = 0.0; $na = 0.0; $nb = 0.0;

		for ($i = 0; $i < $n; $i++)
		{
			$dot += (float) $a[$i] * (float) $b[$i];
			$na += (float) $a[$i] * (float) $a[$i];
			$nb += (float) $b[$i] * (float) $b[$i];
		}

		if ($na <= 0 OR $nb <= 0)
		{
			return 0.0;
		}

		return $dot / (sqrt($na) * sqrt($nb));
	}

	/** Локальный дедуп кандидатов по url_hash (первое вхождение выигрывает). */
	public static function dedupe_items(array $items)
	{
		$seen = [];
		$out = [];

		foreach ($items as $item)
		{
			$hash = self::url_hash(Arr::get($item, 'url', ''));

			if ($hash === sha1('u:') OR isset($seen[$hash]))
			{
				continue;
			}

			$seen[$hash] = TRUE;
			$item['url_hash'] = $hash;
			$item['host'] = self::url_host(Arr::get($item, 'url', ''));
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Решение «писать ли письмо» — чистая функция (троттлинг + порог смысла).
	 *
	 * @param array    $stats  статистика отчёта: max_score, ideas_new…
	 * @param int|NULL $last   unix-time прошлого письма (NULL — ещё не писали)
	 * @param array    $cfg    конфиг mail (to_email, every_days, min_score)
	 * @param int      $now    текущий unix-time
	 * @return array ['send' => bool, 'reason' => string]
	 */
	public static function mail_due(array $stats, $last, array $cfg, $now)
	{
		if (trim((string) Arr::get($cfg, 'to_email', '')) === '')
		{
			return ['send' => FALSE, 'reason' => 'адрес письма не задан в конфиге nj661_airadar.php'];
		}

		$every = max(0, (int) Arr::get($cfg, 'every_days', 3));

		if ($last !== NULL AND ($now - (int) $last) < $every * 86400)
		{
			return ['send' => FALSE, 'reason' => 'не прошло '.$every.' дн. с прошлого письма'];
		}

		$min_score = (int) Arr::get($cfg, 'min_score', 70);
		$max_score = (int) Arr::get($stats, 'max_score', 0);
		$ideas_new = (int) Arr::get($stats, 'ideas_new', 0);

		if ($max_score >= $min_score OR $ideas_new > 0)
		{
			return ['send' => TRUE, 'reason' => 'есть находка со скором '.$max_score.($ideas_new > 0 ? ' и новых идей: '.$ideas_new : '')];
		}

		return ['send' => FALSE, 'reason' => 'нет находок со скором ≥ '.$min_score.' (порог письма) и новых идей — писать не о чем'];
	}

	/**
	 * Сборка секций отчёта из строк БД — чистая (группировка/сортировка/срез).
	 *
	 * @param array $items analyzed-материалы прогона [{category,score,title,why,url,source}]
	 * @param array $facts новые факты прогона [{text,tags,source_url,sightings}]
	 * @param array $ideas идеи прогона [{title,body,benefit,fit,score,source_url}]
	 */
	public static function report_sections(array $items, array $facts, array $ideas)
	{
		$by_cat = [];

		foreach ($items as $item)
		{
			$cat = (string) Arr::get($item, 'category', '');

			if ($cat === '' OR $cat === 'noise')
			{
				continue;
			}

			$by_cat[$cat][] = $item;
		}

		foreach ($by_cat as $cat => $rows)
		{
			usort($by_cat[$cat], function ($a, $b) { return (int) $b['score'] - (int) $a['score']; });
			$by_cat[$cat] = array_slice($by_cat[$cat], 0, 8);
		}

		// порядок секций: окна возможностей и прорывы — первыми
		$order = ['window', 'breakthrough', 'release', 'framework', 'niche', 'research'];
		$sections = [];

		foreach ($order as $cat)
		{
			if ( ! empty($by_cat[$cat]))
			{
				$sections[] = [
					'key'   => $cat,
					'title' => Arr::get(self::$categories, $cat, $cat),
					'items' => $by_cat[$cat],
				];
			}
		}

		return [
			'sections'    => $sections,
			'facts_new'   => array_slice($facts, 0, 30),
			'ideas'       => $ideas,
			'max_score'   => $items ? max(array_map(function ($i)
					{ return ((string) Arr::get($i, 'category', '') === '' OR $i['category'] === 'noise') ? 0 : (int) Arr::get($i, 'score', 0); }, $items)) : 0,
			'count_items' => count($items),
		];
	}

	// ==================================================================
	// Конфиг и БД
	// ==================================================================

	public static function config($key = NULL, $default = NULL)
	{
		$cfg = (array) Kohana::$config->load('nj661_airadar');

		if ($key === NULL)
		{
			return $cfg;
		}

		return Arr::path($cfg, $key, $default);
	}

	protected static function _db()
	{
		return Database::instance();
	}

	// ==================================================================
	// Прогоны конвейера
	// ==================================================================

	/**
	 * Активный (незавершённый) прогон или NULL.
	 *
	 * Перед ответом прогон сверяется с очередью nj120 (`_catch_up`): если
	 * задача уже закрыта, а `/finish` от воркера не доехал — прогон закрывается
	 * прямо здесь, и дашборд не залипает на «Конвейер работает…».
	 */
	public static function active_run()
	{
		$row = self::_db()->query(Database::SELECT,
			"SELECT * FROM nj_661_runs WHERE status IN ('new','running') ORDER BY id DESC LIMIT 1", FALSE)
			->current();

		if ( ! $row)
		{
			return NULL;
		}

		return self::_catch_up((array) $row);
	}

	/**
	 * Догнать прогон по задаче очереди (паттерн `Vision::_catch_up`).
	 *
	 * Живой случай 06.10.2026: авто-остановка nj120 сняла задачу («Воркер не
	 * отвечал больше 4 мин»), конвейер при этом дошёл до конца, но `/finish`
	 * не доехал — прогон навсегда остался running: дашборд залип на «Конвейер
	 * работает…», новый прогон запустить нельзя. Поэтому каждый взгляд на
	 * активный прогон (дашборд поллит раз в 6 с, start_run, tick) сверяется
	 * с очередью: задача закрыта (done/failed/cancelled) — закрываем и прогон.
	 * Отчёт по прогону успел сохраниться → done, иначе → fail с причиной.
	 *
	 * @param  array $run строка nj_661_runs (status new|running)
	 * @return array|NULL обновлённый прогон или NULL — активных не осталось
	 */
	protected static function _catch_up(array $run)
	{
		$job_id = (int) ($run['job_id'] ?? 0);

		if ($job_id <= 0)
		{
			// прогон-сирота без задачи (очередь упала при постановке): старше
			// 3 часов — закрываем, свежий — оставляем (задачу может ещё поставить)
			if (time() - strtotime((string) $run['created_at']) > 3 * 3600)
			{
				self::api_finish((int) $run['id'], 'fail',
					(array) json_decode((string) $run['stats'], TRUE),
					'Прогон остался без задачи очереди и молчит дольше 3 часов — закрыт сверкой.');

				return NULL;
			}

			return $run;
		}

		if ( ! class_exists('Service_Nj120_JobQueue'))
		{
			return $run;
		}

		try
		{
			$job = Service_Nj120_JobQueue::instance()->get($job_id);
		}
		catch (Throwable $e)
		{
			return $run; // очередь недоступна — не трогаем, следующий запрос догонит
		}

		if ( ! is_array($job) OR ! in_array((string) ($job['status'] ?? ''), ['done', 'failed', 'cancelled'], TRUE))
		{
			return $run; // задача ещё живёт — её ведёт воркер
		}

		// конвейер дошёл до конца? Тогда это done, а не fail
		$has_report = (bool) self::_db()->query(Database::SELECT,
			'SELECT 1 FROM nj_661_reports WHERE run_id = '.(int) $run['id'].' LIMIT 1', FALSE)->current();

		// статистику воркер мог привезти только в result задачи (finish не доехал)
		$result = (array) json_decode((string) ($job['result'] ?? ''), TRUE);
		$stats  = is_array($result['stats'] ?? NULL)
			? $result['stats']
			: (array) json_decode((string) $run['stats'], TRUE);

		$complete = ($has_report AND $job['status'] === 'done'
			AND ($result['ok'] ?? TRUE) AND (int) ($stats['pending'] ?? 0) === 0);
		$error = $complete ? '' : 'Прогон закрыт сверкой с очередью: '.(string) $job['status'].'. '
			.(string) ($result['error'] ?? $job['error'] ?? 'Нет полного отчёта.');
		self::api_finish((int) $run['id'], $complete ? 'done' : 'fail', $stats, $error);
		Log_Plain::event(Log::WARNING, 'nj661: прогон :r закрыт сверкой с очередью, задача :j: :s',
			[':r' => (int) $run['id'], ':j' => $job_id, ':s' => $complete ? 'done' : 'fail']);

		return NULL;
	}

	/**
	 * Поставить прогон: строка runs + задача воркеру (канал nj661.airadar).
	 *
	 * @param string $trigger schedule|manual
	 * @param int    $user_id кто запустил (для ручного)
	 */
	public static function start_run($trigger, $user_id = NULL)
	{
		if (self::active_run() !== NULL)
		{
			return ['error' => 'Прогон уже идёт — дождитесь завершения'];
		}

		if ( ! class_exists('Service_Nj120_JobQueue'))
		{
			return ['error' => 'Очередь задач nj120 недоступна — конвейер запустить нельзя'];
		}

		$limits = (array) self::config('limits', []);
		$db = self::_db();

		$id = (int) $db->query(Database::SELECT,
			'INSERT INTO nj_661_runs ("trigger", status) VALUES ('.$db->quote((string) $trigger).", 'new') RETURNING id", FALSE)
			->get('id');

		try
		{
			$job_id = Service_Nj120_JobQueue::push(self::CHANNEL, [
				'run_id' => $id,
				'limits' => $limits,
			], [
				'module'       => 'nj661',
				'visitor_id'   => 'u:'.((int) $user_id > 0 ? (int) $user_id : 1),
				'created_by'   => (int) $user_id > 0 ? (int) $user_id : 1,
				'max_attempts' => 2,
				// 45 мин: прогон с локальной моделью (владелец: Gemma 12B на
				// своей карте) идёт дольше облака — батчи анализа считаются
				// минутами. Порог молчания канала поднят отдельным правилом:
				// config nj120_jobs → silent_stop_channels (3000 с).
				'timeout_sec'  => 2700,
			]);
		}
		catch (Throwable $e)
		{
			$db->query(Database::UPDATE,
				'UPDATE nj_661_runs SET status = \'fail\', error = '
				.$db->quote('очередь: '.mb_substr($e->getMessage(), 0, 300)).", finished_at = now() WHERE id = ".$id, FALSE);

			return ['error' => 'Не удалось поставить задачу воркеру: '.mb_substr($e->getMessage(), 0, 200)];
		}

		$db->query(Database::UPDATE,
			'UPDATE nj_661_runs SET job_id = '.((int) $job_id > 0 ? (int) $job_id : 'NULL').' WHERE id = '.$id, FALSE);

		return ['ok' => TRUE, 'run_id' => $id];
	}

	/** Тик расписания (его дёргает cron через /airadar/api/tick). */
	public static function tick()
	{
		$cfg = (array) self::config('schedule', []);
		$hour = (int) date('G');

		if ($hour < (int) Arr::get($cfg, 'hour_from', 8) OR $hour > (int) Arr::get($cfg, 'hour_to', 21))
		{
			return ['ok' => TRUE, 'started' => FALSE, 'reason' => 'вне окна запуска ('.$hour.' ч)'];
		}

		if (self::active_run() !== NULL)
		{
			return ['ok' => TRUE, 'started' => FALSE, 'reason' => 'прогон уже идёт'];
		}

		$last = self::_db()->query(Database::SELECT,
			'SELECT created_at FROM nj_661_reports ORDER BY id DESC LIMIT 1', FALSE)->current();
		$every = max(1, (int) Arr::get($cfg, 'every_days', 3));

		if ($last)
		{
			$age = (time() - strtotime((string) $last['created_at'])) / 86400;

			if ($age < $every)
			{
				return ['ok' => TRUE, 'started' => FALSE, 'reason' => sprintf('последний отчёт %.1f дн. назад (период %d дн.)', $age, $every)];
			}
		}

		$res = self::start_run('schedule');

		return $res + ['started' => ! isset($res['error'])];
	}

	/** Этап воркера — живой статус-ряд на дашборде. */
	public static function api_progress($run_id, $stage, $note, array $stats = [])
	{
		$db = self::_db();
		$db->query(Database::UPDATE,
			'UPDATE nj_661_runs SET status = \'running\', stage = '.$db->quote(mb_substr((string) $stage, 0, 120))
			.', stage_note = '.$db->quote(mb_substr((string) $note, 0, 300))
			.', stats = (COALESCE(NULLIF(stats, \'\')::jsonb, \'{}\'::jsonb) || '
			.$db->quote(json_encode((object) $stats, JSON_UNESCAPED_UNICODE)).'::jsonb)::text'
			.', finished_at = NULL, error = \'\' WHERE id = '.(int) $run_id, FALSE);

		return ['ok' => TRUE];
	}

	/** Завершение прогона. */
	public static function api_finish($run_id, $status, array $stats = [], $error = '')
	{
		$db = self::_db();
		$status = in_array($status, ['done', 'fail'], TRUE) ? $status : 'done';

		$db->query(Database::UPDATE,
			'UPDATE nj_661_runs SET status = '.$db->quote($status)
			.', stats = (COALESCE(NULLIF(stats, \'\')::jsonb, \'{}\'::jsonb) || '
			.$db->quote(json_encode((object) $stats, JSON_UNESCAPED_UNICODE)).'::jsonb)::text'
			.', error = '.$db->quote(mb_substr((string) $error, 0, 1000))
			.', finished_at = now() WHERE id = '.(int) $run_id, FALSE);

		if ($status === 'fail')
		{
			Log_Plain::event(Log::ERROR, 'nj661: прогон :r завершён с ошибкой: :e',
				[':r' => (int) $run_id, ':e' => (string) $error]);
		}

		return ['ok' => TRUE];
	}

	// ==================================================================
	// Приём материалов от воркера (дедуп — работу не делаем заново)
	// ==================================================================

	/**
	 * POST /airadar/api/items: принять кандидатов, вернуть НОВЫЕ (не виденные)
	 * + контекст базы знаний для LLM-анализа.
	 *
	 * @param int   $run_id
	 * @param array $items  [{source,url,title,snippet,meta,published_at,score?,category?}]
	 */
	public static function api_items($run_id, array $items)
	{
		if ((int) $run_id <= 0)
		{
			return ['error' => 'нет run_id'];
		}

		$items = self::dedupe_items($items);
		$db = self::_db();

		// Завершённые URL не анализируем повторно. new — незавершённая работа, не дубль.
		$known = [];

		if ($items)
		{
			$in = implode(',', array_map([$db, 'quote'], array_map(function ($i) { return $i['url_hash']; }, $items)));

			foreach ($db->query(Database::SELECT,
				"SELECT url_hash FROM nj_661_items WHERE status <> 'new' AND url_hash IN (".$in.')', FALSE)->as_array() as $row)
			{
				$known[(string) $row['url_hash']] = TRUE;
			}
		}

		$accepted = [];
		$skipped = 0;
		$deferred = 0;
		$limit = max(1, (int) Arr::get((array) self::config('limits', []), 'max_items', 120));

		foreach ($items as $item)
		{
			if (isset($known[$item['url_hash']]))
			{
				$skipped++;
				continue;
			}
			if (count($accepted) >= $limit)
			{
				$deferred++;
				continue;
			}

			$meta = Arr::get($item, 'meta', []);
			$published = Arr::get($item, 'published_at');

			$saved = $db->query(Database::SELECT,
				'INSERT INTO nj_661_items (run_id, source, url, url_hash, host, title, snippet, meta, published_at, status)
				 VALUES ('.(int) $run_id.', '.$db->quote(mb_substr((string) Arr::get($item, 'source', ''), 0, 24)).', '
				.$db->quote((string) Arr::get($item, 'url', '')).', '.$db->quote($item['url_hash']).', '
				.$db->quote(mb_substr((string) Arr::get($item, 'host', ''), 0, 120)).', '
				.$db->quote(mb_substr((string) Arr::get($item, 'title', ''), 0, 300)).', '
				.$db->quote(mb_substr((string) Arr::get($item, 'snippet', ''), 0, 2000)).', '
				.$db->quote(json_encode($meta, JSON_UNESCAPED_UNICODE)).', '
				.($published ? $db->quote((string) $published) : 'NULL').", 'new')
				 ON CONFLICT (url_hash) DO UPDATE SET run_id = EXCLUDED.run_id,
				 title = EXCLUDED.title, snippet = EXCLUDED.snippet, meta = EXCLUDED.meta
				 WHERE nj_661_items.status = 'new' RETURNING id", FALSE)->current();

			if ( ! $saved)
			{
				$skipped++;
				continue;
			}

			$accepted[] = [
				'id'           => (int) $saved['id'],
				'url'          => (string) Arr::get($item, 'url', ''),
				'title'        => (string) Arr::get($item, 'title', ''),
				'snippet'      => (string) Arr::get($item, 'snippet', ''),
				'meta'         => $meta,
				'source'       => (string) Arr::get($item, 'source', ''),
				'pre_score'    => (int) Arr::get($item, 'score', 0),
				'pre_category' => (string) Arr::get($item, 'category', ''),
			];
		}

		// Подхватываем и старые необработанные материалы, уже выпавшие из лент.
		$left = $limit - count($accepted);
		if ($left > 0)
		{
			$ids = array_map(function ($it) { return (int) $it['id']; }, $accepted);
			$rows = $db->query(Database::SELECT,
				"UPDATE nj_661_items SET run_id = ".(int) $run_id." WHERE id IN (
				 SELECT id FROM nj_661_items WHERE status = 'new'"
				.($ids ? ' AND id NOT IN ('.implode(',', $ids).')' : '')
				.' ORDER BY id LIMIT '.$left.') RETURNING id, url, title, snippet, meta, source', FALSE)->as_array();
			foreach ($rows as $row)
			{
				$row = (array) $row;
				$row['meta'] = (array) json_decode((string) $row['meta'], TRUE);
				$accepted[] = $row;
			}
		}
		self::api_progress($run_id, 'Источники', 'Материалы приняты, начинаю анализ',
			['candidates' => count($items), 'accepted' => count($accepted), 'skipped_dup' => $skipped, 'deferred' => $deferred]);

		return [
			'ok'        => TRUE,
			'accepted'  => $accepted,
			'skipped'   => $skipped,
			'deferred'  => $deferred,
			'knowledge' => self::knowledge_context(),
		];
	}

	/** Контекст базы знаний для промпта анализа: недавние факты + частые теги. */
	public static function knowledge_context()
	{
		$cfg = (array) self::config('knowledge', []);
		$days = max(1, (int) Arr::get($cfg, 'recent_days', 45));
		$limit = max(5, (int) Arr::get($cfg, 'context_facts', 40));

		$facts = self::_db()->query(Database::SELECT,
			'SELECT text, tags FROM nj_661_facts
			  WHERE last_seen >= now() - interval \''.$days." days'
			  ORDER BY last_seen DESC LIMIT ".$limit, FALSE)->as_array();

		$tags = self::_db()->query(Database::SELECT,
			"SELECT tag, count(*) AS c FROM (
				SELECT lower(unnest(string_to_array(tags, ','))) AS tag FROM nj_661_facts
				 WHERE last_seen >= now() - interval '30 days' AND tags <> ''
			 ) t WHERE tag <> '' GROUP BY tag ORDER BY c DESC LIMIT 24", FALSE)->as_array();

		$total = self::_db()->query(Database::SELECT,
			'SELECT (SELECT count(*) FROM nj_661_facts) AS facts, (SELECT count(*) FROM nj_661_ideas) AS ideas', FALSE)->current();
		$total = $total ? (array) $total : ['facts' => 0, 'ideas' => 0];

		return [
			'recent_facts' => array_map(function ($r) { return ['text' => (string) $r['text'], 'tags' => (string) $r['tags']]; }, $facts),
			'known_tags'   => array_map(function ($r) { return (string) $r['tag']; }, $tags),
			'total_facts'  => (int) $total['facts'],
			'total_ideas'  => (int) $total['ideas'],
		];
	}

	// ==================================================================
	// Анализ: факты и идеи в базу знаний
	// ==================================================================

	/**
	 * POST /airadar/api/analysis: применить результаты LLM к материалам.
	 *
	 * @param int   $run_id
	 * @param array $results [{id, relevant, category, score, why, facts:[{text,tags}], ideas:[{title,body,benefit,fit,score}]}]
	 */
	public static function api_analysis($run_id, array $results)
	{
		$db = self::_db();
		$facts_new = 0;
		$facts_seen = 0;
		$ideas_new = 0;

		foreach ($results as $res)
		{
			$item_id = (int) Arr::get($res, 'id', 0);

			if ($item_id <= 0)
			{
				continue;
			}

			$item = $db->query(Database::SELECT,
				'SELECT id, url FROM nj_661_items WHERE id = '.$item_id.' AND run_id = '.(int) $run_id, FALSE)->current();

			if ( ! $item)
			{
				continue;
			}

			$item = (array) $item;
			$relevant = (bool) Arr::get($res, 'relevant', TRUE);
			$category = (string) Arr::get($res, 'category', '');
			$category = array_key_exists($category, self::$categories) ? $category : '';

			$mark_sql =
				'UPDATE nj_661_items SET status = '.($relevant ? "'analyzed'" : "'noise'")
				.', category = '.$db->quote($category)
				.', score = '.max(0, min(100, (int) Arr::get($res, 'score', 0)))
				.', why = '.$db->quote(mb_substr((string) Arr::get($res, 'why', ''), 0, 1000))
				.' WHERE id = '.$item_id;

			if ( ! $relevant)
			{
				$db->query(Database::UPDATE, $mark_sql, FALSE);
				continue;
			}

			// факты → база знаний (хеш = нормализованный текст; дубли не создаём)
			foreach ((array) Arr::get($res, 'facts', []) as $fact)
			{
				$text = trim((string) Arr::get($fact, 'text', ''));

				if (mb_strlen($text) < 8)
				{
					continue;
				}

				$text = mb_substr($text, 0, 500);
				$tags = self::tags_norm(Arr::get($fact, 'tags', []));
				$hash = self::fact_hash($text);

				$exists = $db->query(Database::SELECT,
					'SELECT id FROM nj_661_facts WHERE fact_hash = '.$db->quote($hash), FALSE)->current();

				if ($exists)
				{
					$db->query(Database::UPDATE,
						'UPDATE nj_661_facts SET sightings = sightings + 1, last_seen = now() WHERE id = '.(int) $exists['id'], FALSE);
					$facts_seen++;

					continue;
				}

				$embedding = self::fact_embedding(Arr::get($fact, 'embedding'));

				$db->query(Database::INSERT,
					'INSERT INTO nj_661_facts (fact_hash, text, tags, source_url, item_id, sightings, embedding, first_seen, last_seen)
					 VALUES ('.$db->quote($hash).', '.$db->quote($text).', '
					.$db->quote(implode(',', $tags)).', '.$db->quote((string) $item['url']).', '.$item_id.', 1, '
					.$db->quote($embedding !== NULL ? json_encode($embedding) : '').', now(), now())', FALSE);
				$facts_new++;
			}

			// идеи для n2j (хеш — нормализованный заголовок)
			foreach ((array) Arr::get($res, 'ideas', []) as $idea)
			{
				$title = trim((string) Arr::get($idea, 'title', ''));

				if (mb_strlen($title) < 4)
				{
					continue;
				}

				$hash = self::idea_hash($title);
				$score = max(0, min(100, (int) Arr::get($idea, 'score', 0)));

				$exists = $db->query(Database::SELECT,
					'SELECT id, score FROM nj_661_ideas WHERE idea_hash = '.$db->quote($hash), FALSE)->current();

				if ($exists)
				{
					// идея уже есть: подтянем скор к максимуму (повтор — сигнал ценности)
					if ($score > (int) $exists['score'])
					{
						$db->query(Database::UPDATE,
							'UPDATE nj_661_ideas SET score = '.$score.', updated_at = now() WHERE id = '.(int) $exists['id'], FALSE);
					}

					continue;
				}

				$db->query(Database::INSERT,
					'INSERT INTO nj_661_ideas (idea_hash, title, body, benefit, fit, score, source_url, item_id, status, created_at, updated_at)
					 VALUES ('.$db->quote($hash).', '
					.$db->quote(mb_substr($title, 0, 200)).', '
					.$db->quote(mb_substr((string) Arr::get($idea, 'body', ''), 0, 2000)).', '
					.$db->quote(mb_substr((string) Arr::get($idea, 'benefit', ''), 0, 1000)).', '
					.$db->quote(mb_substr((string) Arr::get($idea, 'fit', ''), 0, 500)).', '
					.$score.', '
					.$db->quote((string) $item['url']).', '.$item_id.", 'new', now(), now())", FALSE);
				$ideas_new++;
			}
			$db->query(Database::UPDATE, $mark_sql, FALSE);
		}

		return ['ok' => TRUE, 'facts_new' => $facts_new, 'facts_seen' => $facts_seen, 'ideas_new' => $ideas_new];
	}

	/** Вектор приходит из воркера; приёмка не запускает сетевые LLM-вызовы. */
	public static function fact_embedding($value)
	{
		if ( ! is_array($value) OR count($value) < 8 OR count($value) > 8192)
		{
			return NULL;
		}
		foreach ($value as $n)
		{
			if ( ! is_numeric($n) OR ! is_finite((float) $n)) { return NULL; }
		}
		return array_values(array_map('floatval', $value));
	}

	/** Эмбеддинг текста (или NULL, если движок не настроен — база работает и без него). */
	protected static function _embed($text)
	{
		try
		{
			if ( ! class_exists('Llm'))
			{
				return NULL;
			}

			$vec = Llm::get_embedding(mb_substr((string) $text, 0, 1000));

			return (is_array($vec) AND count($vec) >= 8) ? array_values(array_map('floatval', $vec)) : NULL;
		}
		catch (Throwable $e)
		{
			return NULL;
		}
	}

	/** Похожие факты (RAG-поиск по косинусу) — для дедупа и подсказок. */
	public static function similar_facts($text, $top = 3)
	{
		$cfg = (array) self::config('knowledge', []);
		$vec = self::_embed($text);

		if ($vec === NULL)
		{
			return [];
		}

		$scan = max(50, (int) Arr::get($cfg, 'max_facts_scan', 800));
		$rows = self::_db()->query(Database::SELECT,
			"SELECT id, text, tags, embedding FROM nj_661_facts
			  WHERE embedding <> '' ORDER BY last_seen DESC LIMIT ".$scan, FALSE)->as_array();

		$scored = [];

		foreach ($rows as $row)
		{
			$other = json_decode((string) $row['embedding'], TRUE);

			if ( ! is_array($other))
			{
				continue;
			}

			$scored[] = ['id' => (int) $row['id'], 'text' => (string) $row['text'], 'score' => round(self::cosine($vec, $other), 4)];
		}

		usort($scored, function ($a, $b) { return $b['score'] - $a['score']; });

		return array_slice($scored, 0, max(1, (int) $top));
	}

	// ==================================================================
	// Отчёт и письмо
	// ==================================================================

	/** Данные для LLM-отчёта: топ-материалы прогона, новые факты и идеи. */
	public static function report_input($run_id)
	{
		$db = self::_db();

		$items = $db->query(Database::SELECT,
			"SELECT id, source, url, title, snippet, category, score, why
			   FROM nj_661_items
			  WHERE run_id = ".(int) $run_id." AND status = 'analyzed'
			  ORDER BY score DESC LIMIT 25", FALSE)->as_array();

		$facts = $db->query(Database::SELECT,
			'SELECT f.text, f.tags, f.source_url, f.sightings
			   FROM nj_661_facts f JOIN nj_661_items i ON i.id = f.item_id
			  WHERE i.run_id = '.(int) $run_id.' ORDER BY f.id DESC LIMIT 40', FALSE)->as_array();

		$ideas = $db->query(Database::SELECT,
			'SELECT g.title, g.body, g.benefit, g.fit, g.score, g.source_url
			   FROM nj_661_ideas g JOIN nj_661_items i ON i.id = g.item_id
			  WHERE i.run_id = '.(int) $run_id.' ORDER BY g.score DESC LIMIT 15', FALSE)->as_array();

		return [
			'ok'        => TRUE,
			'items'     => array_map(function ($r) { return (array) $r; }, $items),
			'facts'     => array_map(function ($r) { return (array) $r; }, $facts),
			'ideas'     => array_map(function ($r) { return (array) $r; }, $ideas),
			'knowledge' => self::knowledge_context(),
		];
	}

	/**
	 * POST /airadar/api/report: сохранить отчёт (LLМ-заголовок/сводка/главное)
	 * и решить, пора ли письмо.
	 */
	public static function api_report($run_id, array $payload, array $run_stats = [])
	{
		if ((int) $run_id <= 0)
		{
			return ['error' => 'нет run_id'];
		}

		$db = self::_db();
		$state = (array) $db->query(Database::SELECT,
			"SELECT count(*) AS total, count(*) FILTER (WHERE status IN ('analyzed', 'noise')) AS analyzed
			 FROM nj_661_items WHERE run_id = ".(int) $run_id, FALSE)->current();
		if ((int) ($state['total'] ?? 0) > 0 AND (int) ($state['analyzed'] ?? 0) === 0)
		{
			$error = 'Нет проанализированных материалов: пустой отчёт не сохранён. Проверьте LLM и повторите сбор.';
			Log_Plain::event(Log::ERROR, 'nj661: отчёт прогона :r отклонён: :e', [':r' => (int) $run_id, ':e' => $error]);
			return ['ok' => FALSE, 'error' => $error];
		}
		$run = $db->query(Database::SELECT, 'SELECT stats FROM nj_661_runs WHERE id = '.(int) $run_id, FALSE)->current();
		$run_stats = array_replace((array) json_decode((string) ($run['stats'] ?? ''), TRUE), $run_stats);

		$input = self::report_input($run_id);
		$assembled = self::report_sections($input['items'], $input['facts'], $input['ideas']);

		$title = trim((string) Arr::get($payload, 'title', ''));
		$title = $title !== '' ? mb_substr($title, 0, 200) : 'ИИ-радар: сводка за '.date('d.m.Y');
		$summary = mb_substr(trim((string) Arr::get($payload, 'summary', '')), 0, 2000);

		$highlights = [];

		foreach ((array) Arr::get($payload, 'highlights', []) as $h)
		{
			$h = trim((string) $h);

			if ($h !== '')
			{
				$highlights[] = mb_substr($h, 0, 300);
			}
		}

		$stats = array_replace($run_stats, [
			'count_items' => (int) Arr::get($assembled, 'count_items', 0),
			'max_score'   => (int) Arr::get($assembled, 'max_score', 0),
			'facts_new'   => count($input['facts']),
			'ideas_new'   => count($input['ideas']),
			'total_facts' => (int) Arr::get($input['knowledge'], 'total_facts', 0),
			'total_ideas' => (int) Arr::get($input['knowledge'], 'total_ideas', 0),
		]);

		$previous = $db->query(Database::SELECT,
			'SELECT id, emailed FROM nj_661_reports WHERE run_id = '.(int) $run_id.' ORDER BY id DESC LIMIT 1', FALSE)->current();
		if ($previous)
		{
			$id = (int) $previous['id'];
			$db->query(Database::UPDATE, 'UPDATE nj_661_reports SET title = '.$db->quote($title)
				.', summary = '.$db->quote($summary)
				.', highlights = '.$db->quote(json_encode($highlights, JSON_UNESCAPED_UNICODE))
				.', sections = '.$db->quote(json_encode($assembled, JSON_UNESCAPED_UNICODE))
				.', stats = '.$db->quote(json_encode($stats, JSON_UNESCAPED_UNICODE)).' WHERE id = '.$id, FALSE);
		}
		else
		{
			$id = (int) $db->query(Database::SELECT,
				'INSERT INTO nj_661_reports (run_id, title, summary, highlights, sections, stats)
				 VALUES ('.(int) $run_id.', '.$db->quote($title).', '.$db->quote($summary).', '
				.$db->quote(json_encode($highlights, JSON_UNESCAPED_UNICODE)).', '
				.$db->quote(json_encode($assembled, JSON_UNESCAPED_UNICODE)).', '
				.$db->quote(json_encode($stats, JSON_UNESCAPED_UNICODE)).') RETURNING id', FALSE)->get('id');
		}
		$mail = ! empty($previous['emailed']) ? ['mailed' => TRUE, 'reason' => 'письмо уже отправлено'] : self::send_report_mail($id);

		return ['ok' => TRUE, 'report_id' => $id] + $mail;
	}

	/**
	 * Отправить письмо с отчётом (с троттлингом; $force — кнопка «прислать сейчас»).
	 * Возвращает ['mailed' => bool, 'reason' => string].
	 */
	public static function send_report_mail($report_id, $force = FALSE)
	{
		$db = self::_db();
		$report = $db->query(Database::SELECT,
			'SELECT * FROM nj_661_reports WHERE id = '.(int) $report_id, FALSE)->current();

		if ( ! $report)
		{
			return ['mailed' => FALSE, 'reason' => 'отчёт не найден'];
		}

		$report = (array) $report;
		$stats = (array) json_decode((string) $report['stats'], TRUE);
		$cfg = (array) self::config('mail', []);
		$to = trim((string) Arr::get($cfg, 'to_email', ''));

		if ($to === '')
		{
			return ['mailed' => FALSE, 'reason' => 'адрес не задан в конфиге nj661_airadar.php (mail.to_email)'];
		}

		if ( ! $force)
		{
			$last = $db->query(Database::SELECT,
				'SELECT emailed_at FROM nj_661_reports WHERE emailed = TRUE ORDER BY emailed_at DESC LIMIT 1', FALSE)->current();

			$due = self::mail_due($stats, $last ? strtotime((string) $last['emailed_at']) : NULL, $cfg, time());

			if ( ! $due['send'])
			{
				return ['mailed' => FALSE, 'reason' => $due['reason']];
			}
		}

		if ( ! class_exists('Service_Nj597_Mail'))
		{
			return ['mailed' => FALSE, 'reason' => 'почтовый сервис nj597 недоступен'];
		}

		$subject = ((int) Arr::get($stats, 'max_score', 0) >= (int) Arr::get($cfg, 'min_score', 70) ? 'есть важное — ' : '')
			.(string) $report['title'];

		try
		{
			if ( ! Service_Nj597_Mail::send_direct($to, $subject, self::mail_html($report)))
			{
				throw new RuntimeException('Почтовый сервис вернул отказ');
			}
		}
		catch (Throwable $e)
		{
			Log_Plain::event(Log::ERROR, 'nj661: письмо отчёта не ушло: :e', [':e' => $e->getMessage()]);

			return ['mailed' => FALSE, 'reason' => 'ошибка отправки: '.mb_substr($e->getMessage(), 0, 200)];
		}

		$db->query(Database::UPDATE,
			'UPDATE nj_661_reports SET emailed = TRUE, emailed_at = now() WHERE id = '.(int) $report_id, FALSE);

		return ['mailed' => TRUE, 'reason' => 'письмо отправлено на '.$to];
	}

	/** HTML письма (чистая функция от строки отчёта). */
	public static function mail_html(array $report)
	{
		// из БД поля приходят JSON-строками, из тестов — массивами: приводим к одному виду
		$soft = function ($val)
		{
			if (is_array($val))
			{
				return $val;
			}

			$decoded = json_decode((string) $val, TRUE);

			return is_array($decoded) ? $decoded : [];
		};

		$sections  = (array) $soft(Arr::get($report, 'sections', ''));
		$highlights = (array) $soft(Arr::get($report, 'highlights', ''));
		$stats = (array) $soft(Arr::get($report, 'stats', ''));

		$html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:640px;margin:0 auto;color:#1f2937">'
			.'<h2 style="margin:0 0 8px">🛰 '.HTML::chars((string) Arr::get($report, 'title', '')).'</h2>'
			.'<p style="color:#6b7280;font-size:13px">'.HTML::chars(date('d.m.Y H:i', strtotime((string) Arr::get($report, 'created_at', 'now')))).'</p>';

		$summary = trim((string) Arr::get($report, 'summary', ''));

		if ($summary !== '')
		{
			$html .= '<p>'.nl2br(HTML::chars($summary)).'</p>';
		}

		if ($highlights)
		{
			$html .= '<ul style="padding-left:18px">';

			foreach ($highlights as $h)
			{
				$html .= '<li style="margin:4px 0">'.HTML::chars((string) $h).'</li>';
			}

			$html .= '</ul>';
		}

		foreach ((array) Arr::get($sections, 'sections', []) as $section)
		{
			$html .= '<h3 style="margin:16px 0 6px;font-size:15px">'.HTML::chars((string) Arr::get($section, 'title', '')).'</h3>';

			foreach ((array) Arr::get($section, 'items', []) as $item)
			{
				$html .= '<p style="margin:6px 0;font-size:14px"><b>'.HTML::chars((string) Arr::get($item, 'title', '')).'</b>'
					.' <span style="color:#059669;font-size:12px">('.(int) Arr::get($item, 'score', 0).'/100)</span><br>'
					.'<span style="color:#4b5563">'.HTML::chars((string) Arr::get($item, 'why', '')).'</span><br>'
					.'<a href="'.HTML::chars((string) Arr::get($item, 'url', '')).'" style="color:#2563eb;font-size:12px">'
					.HTML::chars((string) Arr::get($item, 'url', '')).'</a></p>';
			}
		}

		$ideas = (array) Arr::get($sections, 'ideas', []);

		if ($ideas)
		{
			$html .= '<h3 style="margin:16px 0 6px;font-size:15px">💡 Идеи для N2J</h3>';

			foreach ($ideas as $idea)
			{
				$html .= '<p style="margin:6px 0;font-size:14px"><b>'.HTML::chars((string) Arr::get($idea, 'title', '')).'</b>'
					.'<br><span style="color:#4b5563">Польза: '.HTML::chars((string) Arr::get($idea, 'benefit', '')).'</span>'
					.'<br><span style="color:#7c3aed">Куда: '.HTML::chars((string) Arr::get($idea, 'fit', '')).'</span></p>';
			}
		}

		$html .= '<hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0">'
			.'<p style="color:#6b7280;font-size:12px">База знаний: '.(int) Arr::get($stats, 'total_facts', 0).' фактов · '
			.(int) Arr::get($stats, 'total_ideas', 0).' идей. Полный отчёт — '
			.'<a href="https://n2j.ru/airadar" style="color:#2563eb">n2j.ru/airadar</a>.</p></div>';

		return $html;
	}

	// ==================================================================
	// Выборки для админки
	// ==================================================================

	/** Дашборд: последний отчёт, последний прогон, счётчики, топ-идеи/материалы. */
	public static function overview()
	{
		$db = self::_db();

		$report = $db->query(Database::SELECT,
			'SELECT * FROM nj_661_reports ORDER BY id DESC LIMIT 1', FALSE)->current();
		$report = $report ? (array) $report : NULL;

		if ($report)
		{
			foreach (['highlights', 'sections', 'stats'] as $key)
			{
				$report[$key] = (array) json_decode((string) $report[$key], TRUE);
			}
		}

		$run = $db->query(Database::SELECT,
			'SELECT * FROM nj_661_runs ORDER BY id DESC LIMIT 1', FALSE)->current();
		$run = $run ? (array) $run : NULL;

		// незавершённый прогон сверяем с очередью: если его задача давно
		// закрыта (авто-остановка nj120, гибель воркера), дашборд не должен
		// вечно показывать «Конвейер работает…» (см. _catch_up)
		if ($run AND in_array((string) $run['status'], ['new', 'running'], TRUE))
		{
			$run = self::_catch_up($run);

			if ($run === NULL)
			{
				$row = $db->query(Database::SELECT,
					'SELECT * FROM nj_661_runs ORDER BY id DESC LIMIT 1', FALSE)->current();
				$run = $row ? (array) $row : NULL;
			}
		}

		if ($run)
		{
			$run['stats'] = (array) json_decode((string) $run['stats'], TRUE);
		}

		$counts = $db->query(Database::SELECT,
			"SELECT
				(SELECT count(*) FROM nj_661_items WHERE status = 'analyzed') AS items_ok,
				(SELECT count(*) FROM nj_661_items WHERE created_at >= now() - interval '30 days') AS items_30d,
				(SELECT count(*) FROM nj_661_facts) AS facts,
				(SELECT count(*) FROM nj_661_facts WHERE last_seen >= now() - interval '30 days') AS facts_30d,
				(SELECT count(*) FROM nj_661_ideas WHERE status = 'new') AS ideas_new,
				(SELECT count(*) FROM nj_661_ideas WHERE status = 'accepted') AS ideas_accepted,
				(SELECT count(*) FROM nj_661_ideas WHERE status = 'done') AS ideas_done,
				(SELECT count(*) FROM nj_661_reports) AS reports,
				(SELECT count(*) FROM nj_661_reports WHERE emailed) AS reports_emailed", FALSE)->current();

		$top_items = $db->query(Database::SELECT,
			"SELECT id, source, url, title, category, score, why FROM nj_661_items
			  WHERE status = 'analyzed' AND created_at >= now() - interval '30 days'
			  ORDER BY score DESC, created_at DESC LIMIT 8", FALSE)->as_array();

		$top_ideas = $db->query(Database::SELECT,
			"SELECT id, title, benefit, fit, score, status FROM nj_661_ideas
			  WHERE status IN ('new','accepted') ORDER BY score DESC, created_at DESC LIMIT 6", FALSE)->as_array();

		return [
			'report'    => $report,
			'run'       => $run,
			'counts'    => $counts ? (array) $counts : [],
			'top_items' => array_map(function ($r) { return (array) $r; }, $top_items),
			'top_ideas' => array_map(function ($r) { return (array) $r; }, $top_ideas),
		];
	}

	/** Отчёт по id (для страницы отчёта). */
	public static function report($id)
	{
		$row = self::_db()->query(Database::SELECT,
			'SELECT * FROM nj_661_reports WHERE id = '.(int) $id, FALSE)->current();

		if ( ! $row)
		{
			return NULL;
		}

		$report = (array) $row;

		foreach (['highlights', 'sections', 'stats'] as $key)
		{
			$report[$key] = (array) json_decode((string) $report[$key], TRUE);
		}

		return $report;
	}

	/** Отчёты (архив). */
	public static function reports($limit = 30)
	{
		$rows = self::_db()->query(Database::SELECT,
			'SELECT id, title, summary, stats, emailed, created_at FROM nj_661_reports ORDER BY id DESC LIMIT '.(int) $limit, FALSE)
			->as_array();

		$out = [];

		foreach ($rows as $row)
		{
			$row = (array) $row;
			$row['stats'] = (array) json_decode((string) $row['stats'], TRUE);
			$out[] = $row;
		}

		return $out;
	}

	// ==================================================================
	// Публикация отчётов и каталог (владелец, 08.10.2026)
	//
	// «Каталог опубликованных обзоров — как в трендах: читаемый список, свежие
	// сверху, пагинация, поиск; отчёт публикуется по умолчанию, но я могу снять
	// его с публикации».
	// ==================================================================

	/** Этапы конвейера: по ним дашборд рисует, где прогон сейчас. */
	public static $stages = ['Источники', 'Чтение', 'Анализ', 'Отчёт'];

	/**
	 * Есть ли колонка published (миграция 002 могла ещё не накатиться).
	 *
	 * Код и схема едут на прод разными шагами: пока колонки нет, каталог читает
	 * это как «всё опубликовано» (умолчание колонки), а не падает 500-й.
	 */
	protected static function _has_published()
	{
		static $has = NULL;

		if ($has !== NULL)
		{
			return $has;
		}

		try
		{
			$row = self::_db()->query(Database::SELECT,
				"SELECT 1 AS has FROM information_schema.columns
				  WHERE table_name = 'nj_661_reports' AND column_name = 'published'", FALSE)->current();

			$has = ($row !== NULL AND $row !== FALSE);
		}
		catch (Throwable $e)
		{
			$has = FALSE;
		}

		return $has;
	}

	/**
	 * PostgreSQL отдаёт BOOLEAN строками 't'/'f', а (bool)'f' в PHP — это TRUE
	 * (непустая строка). Все булевы значения из БД прогоняем здесь.
	 */
	public static function as_bool($value)
	{
		return $value === TRUE OR $value === 't' OR $value === 'true' OR $value === 1 OR $value === '1';
	}

	/** Опубликован ли отчёт. Нет колонки или ключа — считаем опубликованным. */
	public static function is_published($report)
	{
		$report = (array) $report;

		if ( ! array_key_exists('published', $report) OR $report['published'] === NULL)
		{
			return TRUE;
		}

		return self::as_bool($report['published']);
	}

	/**
	 * Снять отчёт с публикации или вернуть его в каталог.
	 *
	 * Колонку добавляем лениво и только здесь — на явном действии владельца,
	 * а не на каждом запросе.
	 */
	public static function set_published($report_id, $published)
	{
		if ( ! self::_has_published())
		{
			try
			{
				DB::query(NULL, 'ALTER TABLE "nj_661_reports" ADD COLUMN IF NOT EXISTS "published" BOOLEAN NOT NULL DEFAULT TRUE')->execute();
			}
			catch (Throwable $e)
			{
				Kohana::$log->add(Log::ERROR, 'nj661: колонка published не добавилась: :e', [':e' => $e->getMessage()]);

				return FALSE;
			}
		}

		try
		{
			self::_db()->query(Database::UPDATE,
				'UPDATE nj_661_reports SET published = '.($published ? 'TRUE' : 'FALSE')
				.' WHERE id = '.(int) $report_id, FALSE);
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::ERROR, 'nj661: публикация отчёта не переключилась: :e', [':e' => $e->getMessage()]);

			return FALSE;
		}

		return TRUE;
	}

	/** Условие каталога: опубликованный отчёт и (если ищем) совпадение по тексту. */
	protected static function _published_where($q = '')
	{
		$where = self::_has_published() ? ['published IS TRUE'] : ['TRUE'];
		$q = trim((string) $q);

		if ($q !== '')
		{
			// LIKE-метасимволы обезвреживаем: владелец ищет словами, не шаблонами.
			$like = self::_db()->quote('%'.str_replace(['%', '_', '\\'], ' ', $q).'%');
			$where[] = '(title ILIKE '.$like.' OR summary ILIKE '.$like.' OR sections ILIKE '.$like.')';
		}

		return implode(' AND ', $where);
	}

	/** Сколько отчётов в каталоге (с учётом поиска). */
	public static function count_published($q = '')
	{
		$row = self::_db()->query(Database::SELECT,
			'SELECT count(*) AS c FROM nj_661_reports WHERE '.self::_published_where($q), FALSE)->current();

		return (int) ($row ? $row['c'] : 0);
	}

	/**
	 * Страница каталога отчётов: свежие сверху, с числами прогона.
	 *
	 * Числа берём из stats отчёта (кандидаты, принятые материалы, новые факты и
	 * идеи): по одному заголовку не видно, что было в прогоне.
	 */
	public static function search_published($q = '', $page = 1, $per = 10)
	{
		$per    = max(5, min(50, (int) $per));
		$offset = max(0, ((int) $page - 1) * $per);

		$rows = self::_db()->query(Database::SELECT,
			'SELECT id, title, summary, highlights, stats, emailed, emailed_at, created_at, published
			   FROM nj_661_reports
			  WHERE '.self::_published_where($q).'
			  ORDER BY id DESC LIMIT '.$per.' OFFSET '.$offset, FALSE)->as_array();

		$out = [];

		foreach ($rows as $row)
		{
			$row = (array) $row;
			$row['stats']      = (array) json_decode((string) $row['stats'], TRUE);
			$row['highlights'] = (array) json_decode((string) $row['highlights'], TRUE);
			$row['published']  = self::is_published($row);
			$out[] = $row;
		}

		return $out;
	}

	/** Идентификаторы опубликованных отчётов, свежие сверху (карта сайта). */
	public static function public_ids($limit = 100)
	{
		$limit = max(1, min(500, (int) $limit));

		$rows = self::_db()->query(Database::SELECT,
			'SELECT id FROM nj_661_reports WHERE '.self::_published_where('').'
			  ORDER BY id DESC LIMIT '.$limit, FALSE)->as_array();

		$ids = [];

		foreach ($rows as $row)
		{
			$ids[] = (int) ((array) $row)['id'];
		}

		return $ids;
	}

	/** Последний опубликованный отчёт — дайджест на дашборде. */
	public static function latest_published()
	{
		$row = self::_db()->query(Database::SELECT,
			'SELECT id, title, summary, stats, created_at FROM nj_661_reports
			  WHERE '.self::_published_where('').' ORDER BY id DESC LIMIT 1', FALSE)->current();

		if ( ! $row)
		{
			return NULL;
		}

		$row = (array) $row;
		$row['stats'] = (array) json_decode((string) $row['stats'], TRUE);

		return $row;
	}

	/**
	 * Числа прогона одной строкой: «страниц: 12 · кандидатов: 8 · фактов: 5».
	 *
	 * Показываем только то, что уже насчитано, в порядке прохождения конвейера —
	 * в начале «собираю источники…», дальше счётчики добавляются по мере работы.
	 * Больше четырёх чисел не выводим: строка должна читаться глазом, а не
	 * превращаться в полосу показателей.
	 */
	public static function counters_text($stats)
	{
		$stats = (array) $stats;

		$labels = [
			'pages_read' => 'страниц',
			'candidates' => 'кандидатов',
			'accepted'   => 'отобрано',
			'facts_new'  => 'фактов',
			'ideas_new'  => 'идей',
		];

		$parts = [];

		// Источники воркер отдаёт картой имя→сколько, отдельного счётчика нет.
		$sources = (array) ($stats['sources'] ?? []);

		if ($sources)
		{
			$parts[] = 'источников: '.count($sources);
		}

		foreach ($labels as $key => $label)
		{
			$n = (int) ($stats[$key] ?? 0);

			if ($n > 0)
			{
				$parts[] = $label.': '.$n;
			}
		}

		if ( ! $parts)
		{
			return 'собираю источники…';
		}

		return implode(' · ', array_slice($parts, 0, 4));
	}

	/**
	 * Данные живого статус-ряда: этап, секундомер и числа прогона.
	 *
	 * Правило 4 дизайн-эталона: спиннер, название этапа и секундомер — один
	 * неразрывный ряд; полос хода нет, числа — числом. Отсюда и форма ответа:
	 * текущий этап + индекс в лестнице `$stages` + секунды + счётчики.
	 */
	public static function run_progress($run)
	{
		if ( ! $run)
		{
			return NULL;
		}

		$run = (array) $run;
		$stats = is_array($run['stats'] ?? NULL)
			? $run['stats']
			: (array) json_decode((string) ($run['stats'] ?? ''), TRUE);

		$status = (string) ($run['status'] ?? 'new');
		$live   = in_array($status, ['new', 'running'], TRUE);
		$stage  = trim((string) ($run['stage'] ?? ''));

		// Индекс текущего этапа в лестнице: воркер пишет «Чтение», «Анализ» и т.д.
		$index = -1;

		foreach (self::$stages as $i => $name)
		{
			if ($stage !== '' AND mb_stripos($stage, $name) !== FALSE)
			{
				$index = $i;
				break;
			}
		}

		$started = strtotime((string) ($run['created_at'] ?? 'now')) ?: time();
		$finished = trim((string) ($run['finished_at'] ?? '')) !== ''
			? (strtotime((string) $run['finished_at']) ?: NULL)
			: NULL;

		return [
			'id'          => (int) ($run['id'] ?? 0),
			'status'      => $status,
			'live'        => $live,
			'trigger'     => (string) ($run['trigger'] ?? ''),
			'stage'       => $stage !== '' ? $stage : ($live ? 'Ставлю задачу в очередь…' : ''),
			'stage_note'  => (string) ($run['stage_note'] ?? ''),
			'stage_index' => $index,
			'stages'      => self::$stages,
			'elapsed_sec' => $finished !== NULL ? max(0, $finished - $started) : max(0, time() - $started),
			'error'       => (string) ($run['error'] ?? ''),
			'counters'    => [
				'candidates' => (int) ($stats['candidates'] ?? 0),
				'accepted'   => (int) ($stats['accepted'] ?? 0),
				'pages_read' => (int) ($stats['pages_read'] ?? 0),
				'facts_new'  => (int) ($stats['facts_new'] ?? 0),
				'ideas_new'  => (int) ($stats['ideas_new'] ?? 0),
			],
			// Текст счётчиков считает ОДНА функция: сервер рисует его в разметке,
			// движок (nj661-airadar.js) ставит то же значение при опросе — правило
			// не разъезжается между PHP и JS.
			'counters_text' => self::counters_text($stats),
			'sources'     => (array) ($stats['sources'] ?? []),
			'source_errors' => (array) ($stats['source_errors'] ?? []),
		];
	}

	/** Материалы: фильтры source/category/status/starred, поиск по подстроке. */
	public static function items_list(array $filter = [], $limit = 100)
	{
		$db = self::_db();
		$where = ["status <> 'dup'"];

		$source = trim((string) Arr::get($filter, 'source', ''));

		if ($source !== '')
		{
			$where[] = 'source = '.$db->quote(mb_substr($source, 0, 24));
		}

		$category = trim((string) Arr::get($filter, 'category', ''));

		if ($category !== '')
		{
			$where[] = 'category = '.$db->quote(mb_substr($category, 0, 24));
		}

		$status = trim((string) Arr::get($filter, 'status', ''));

		if ($status !== '')
		{
			$where[] = 'status = '.$db->quote(mb_substr($status, 0, 16));
		}

		if (Arr::get($filter, 'starred'))
		{
			$where[] = 'starred = TRUE';
		}

		$q = trim((string) Arr::get($filter, 'q', ''));

		if ($q !== '')
		{
			$like = $db->quote('%'.$q.'%');
			$where[] = '(title ILIKE '.$like.' OR snippet ILIKE '.$like.' OR why ILIKE '.$like.')';
		}

		$rows = $db->query(Database::SELECT,
			'SELECT id, run_id, source, url, host, title, snippet, category, score, why, status, starred, published_at, created_at
			   FROM nj_661_items WHERE '.implode(' AND ', $where)
			.' ORDER BY created_at DESC, id DESC LIMIT '.(int) $limit, FALSE)->as_array();

		return array_map(function ($r) { return (array) $r; }, $rows);
	}

	/** База знаний: факты (поиск по тексту/тегам). */
	public static function facts_list($q = '', $limit = 120)
	{
		$db = self::_db();
		$sql = 'SELECT id, text, tags, source_url, sightings, first_seen, last_seen FROM nj_661_facts';

		$q = trim((string) $q);

		if ($q !== '')
		{
			$like = $db->quote('%'.$q.'%');
			$sql .= ' WHERE text ILIKE '.$like.' OR tags ILIKE '.$like;
		}

		$sql .= ' ORDER BY last_seen DESC LIMIT '.(int) $limit;

		$rows = $db->query(Database::SELECT, $sql, FALSE)->as_array();

		return array_map(function ($r) { return (array) $r; }, $rows);
	}

	/** Идеи (борд). */
	public static function ideas_list($status = '', $limit = 150)
	{
		$status = trim((string) $status);
		$sql = 'SELECT id, title, body, benefit, fit, score, source_url, status, created_at, updated_at FROM nj_661_ideas';

		if (in_array($status, ['new', 'accepted', 'done', 'rejected'], TRUE))
		{
			$sql .= " WHERE status = '".str_replace("'", "''", $status)."'";
		}

		$sql .= ' ORDER BY score DESC, created_at DESC LIMIT '.(int) $limit;

		$rows = self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();

		return array_map(function ($r) { return (array) $r; }, $rows);
	}

	/** Статус идеи (борд владельца). */
	public static function idea_set_status($id, $status)
	{
		if ( ! in_array($status, ['new', 'accepted', 'done', 'rejected'], TRUE))
		{
			return FALSE;
		}

		self::_db()->query(Database::UPDATE,
			'UPDATE nj_661_ideas SET status = '.self::_db()->quote($status).', updated_at = now() WHERE id = '.(int) $id, FALSE);

		return TRUE;
	}

	/** Избранное материала. */
	public static function item_star($id, $starred)
	{
		self::_db()->query(Database::UPDATE,
			'UPDATE nj_661_items SET starred = '.($starred ? 'TRUE' : 'FALSE').' WHERE id = '.(int) $id, FALSE);

		return TRUE;
	}
}
