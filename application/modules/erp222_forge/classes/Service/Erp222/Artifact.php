<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ERP-222 «Кузница систем»: приём, оценка и хранение артефактов.
 *
 * Почему отдельный сервис: артефакт — это то, ради чего сервис существует, и
 * именно здесь решается, будет ли он полезен. Наработки владельца складывали
 * «любую сущность» в Universal Store с одним score в отдельном логе эволюции —
 * по такому артефакту нельзя было понять ни что в нём, ни стоит ли он времени.
 *
 * Здесь у артефакта есть:
 *   · тип из словаря (10 типов) и требование «чем доказывается» (type_proof);
 *   · разложенные метаданные: входы, выходы, допущения, открытые вопросы;
 *   · четыре оси оценки и свёрнутая ценность 0..100 (Forge::value_score);
 *   · автоподсчёт новизны относительно уже выпущенного мира (Forge::novelty);
 *   · отпечаток содержимого: шаг, повторяющий прежний артефакт, не создаёт копию;
 *   · связи с другими артефактами (depends_on → erp_222_links);
 *   · критика Критика, которая ПЕРЕСЧИТЫВАЕТ ценность разобранного артефакта.
 *
 * Чистые функции (normalize, merge_scores, critique_target) закрыты стендом
 * .tools/erp222_forge_stand.php и тестом tests/Unit/ForgeServiceTest.php.
 */
class Service_Erp222_Artifact {

	/** Пределы полей: артефакт нужен читаемым, а не «на 200 тысяч знаков». */
	const TITLE_MAX   = 240;
	const SUMMARY_MAX = 700;
	const BODY_MAX    = 24000;

	/**
	 * Разобрать присланное воркером в нормальный артефакт (чистая функция).
	 *
	 * Терпимость к формату — намеренная: LLM на локальной модели возвращает то
	 * объект, то массив, то строку; метаданные — то массивом, то текстом. Лучше
	 * принять и привести, чем потерять шаг. Но заголовок, тело и тип обязательны:
	 * без них это не артефакт, а пересказ.
	 *
	 * @param  array $data          то, что прислал воркер
	 * @param  array $types_allowed допустимые типы ('' — любой из словаря)
	 * @param  array $role_types    типы роли шага (первый — умолчание)
	 * @return array                ['ok'=>bool, …] с 'artifact' либо 'error'
	 */
	public static function normalize(array $data, array $types_allowed = [], array $role_types = [])
	{
		$dictionary = array_keys(Service_Erp222_Forge::types());

		$type = mb_strtolower(trim((string) Arr::get($data, 'type', '')));
		$type = preg_replace('/[^a-z_]+/u', '', $type);

		// Тип не из словаря → тип роли шага (а не «noise»): мир должен получить
		// артефакт, даже если модель назвала его своими словами.
		if ( ! in_array($type, $dictionary, TRUE))
		{
			$type = isset($role_types[0]) ? (string) $role_types[0] : 'spec';
		}

		if ($types_allowed AND ! in_array($type, $types_allowed, TRUE))
		{
			$type = (string) $types_allowed[0];
		}

		$title = self::flatten(Arr::get($data, 'title', ''));
		$title = preg_replace('/\s+/u', ' ', $title);
		$title = mb_substr(trim((string) $title), 0, self::TITLE_MAX);

		$summary = self::flatten(Arr::get($data, 'summary', ''));
		$summary = mb_substr(trim(preg_replace('/\s+/u', ' ', $summary)), 0, self::SUMMARY_MAX);

		$body = self::flatten(Arr::get($data, 'body', Arr::get($data, 'content', '')));
		$body = mb_substr(trim((string) $body), 0, self::BODY_MAX);

		if ($title === '')
		{
			return ['ok' => FALSE, 'error' => 'У артефакта нет заголовка'];
		}

		if (mb_strlen($body) < 120)
		{
			return ['ok' => FALSE, 'error' => 'Тело артефакта слишком короткое (меньше 120 знаков) — это пересказ, а не документ'];
		}

		// Метаданные: ожидаем входы/выходы/допущения/открытые вопросы. Что пришло
		// массивом — оставляем массивом, что строкой — режем на пункты по строкам.
		$meta_in = Arr::get($data, 'metadata', []);
		$meta_in = is_array($meta_in) ? $meta_in : (array) json_decode((string) $meta_in, TRUE);

		$metadata = [
			'inputs'          => self::list_of(Arr::get($meta_in, 'inputs', Arr::get($data, 'inputs', []))),
			'outputs'         => self::list_of(Arr::get($meta_in, 'outputs', Arr::get($data, 'outputs', []))),
			'assumptions'     => self::list_of(Arr::get($meta_in, 'assumptions', [])),
			'open_questions'  => self::list_of(Arr::get($meta_in, 'open_questions', [])),
		];

		$scores = [];
		$scores_in = Arr::get($data, 'scores', []);

		if (is_array($scores_in))
		{
			foreach ((array) Service_Erp222_Forge::config('value_weights', []) as $key => $weight)
			{
				if (isset($scores_in[$key]))
				{
					$scores[$key] = Service_Erp222_Forge::unit_scale($scores_in[$key]);
				}
			}
		}

		$depends_on = self::ids(Arr::get($data, 'depends_on', []));
		$sources    = self::sources(Arr::get($data, 'sources', []));

		return [
			'ok' => TRUE,
			'artifact' => [
				'type'       => $type,
				'title'      => $title,
				'summary'    => $summary,
				'body'       => $body,
				'metadata'   => $metadata,
				'scores'     => $scores,
				'depends_on' => $depends_on,
				'sources'    => $sources,
			],
		];
	}

	/** Любое значение → строка (массив/объект — JSON): тело приходит любым. */
	public static function flatten($value)
	{
		if (is_array($value) OR is_object($value))
		{
			$value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		}

		return (string) $value;
	}

	/** Значение → список строк: массив как есть, строка — по строкам и «;». */
	public static function list_of($value)
	{
		if (is_array($value))
		{
			$out = [];

			foreach ($value as $item)
			{
				$item = trim(self::flatten($item));

				if ($item !== '')
				{
					$out[] = mb_substr($item, 0, 400);
				}
			}

			return array_slice($out, 0, 20);
		}

		$value = trim((string) $value);

		if ($value === '')
		{
			return [];
		}

		$out = [];

		foreach (preg_split('/\r?\n|;(?=\s)/u', $value) as $item)
		{
			$item = trim(preg_replace('/^\s*[-–*•\d.)]+\s*/u', '', (string) $item));

			if ($item !== '')
			{
				$out[] = mb_substr($item, 0, 400);
			}
		}

		return array_slice($out, 0, 20);
	}

	/** Только положительные целые id — из «[3, «5», null]» получаем [3, 5]. */
	public static function ids($value)
	{
		if (is_string($value))
		{
			$decoded = json_decode($value, TRUE);
			$value = is_array($decoded) ? $decoded : preg_split('/\D+/', $value);
		}

		if ( ! is_array($value))
		{
			return [];
		}

		$out = [];

		foreach ($value as $id)
		{
			$id = (int) $id;

			if ($id > 0)
			{
				$out[$id] = $id;
			}
		}

		return array_slice(array_values($out), 0, 20);
	}

	/** Источники опоры: строки «факт #12», URL, «идея #4» — как есть, но чисто. */
	public static function sources($value)
	{
		if (is_string($value))
		{
			$decoded = json_decode($value, TRUE);
			$value = is_array($decoded) ? $decoded : preg_split('/\r?\n/u', $value);
		}

		if ( ! is_array($value))
		{
			return [];
		}

		$out = [];

		foreach ($value as $item)
		{
			$item = trim(self::flatten($item));

			if ($item !== '')
			{
				$out[] = mb_substr($item, 0, 300);
			}
		}

		return array_slice($out, 0, 20);
	}

	/** Оценки → нормализованный набор 0..1 по осям конфига. */
	public static function merge_scores(array $base, array $extra)
	{
		$weights = (array) Service_Erp222_Forge::config('value_weights', []);
		$out     = [];

		foreach ($weights as $key => $weight)
		{
			$value = Arr::get($extra, $key, Arr::get($base, $key, 0.5));
			$out[$key] = Service_Erp222_Forge::unit_scale($value);
		}

		return $out;
	}

	/**
	 * Критика: оценки Критика по чужому артефакту.
	 *
	 * Критик возвращает artifact типа risk и target_id — разбираемый артефакт.
	 * Оценки применяются К РАЗБИРАЕМОМУ: ценность в каталоге должна отражать не
	 * самооценку автора, а взгляд со стороны (это и есть «эволюция» наработок,
	 * только без отдельного лога).
	 *
	 * @param  array $target   разбираемый артефакт (строка БД, json разобран)
	 * @param  array $critique ['scores'=>[…], 'text'=>'…']
	 * @return array           ['scores'=>[…], 'value_score'=>int]
	 */
	public static function critique_target(array $target, array $critique)
	{
		$scores = self::merge_scores((array) Arr::get($target, 'scores', []), (array) Arr::get($critique, 'scores', []));

		return [
			'scores'      => $scores,
			'value_score' => Service_Erp222_Forge::value_score($scores),
		];
	}

	// ==================================================================
	// Приём артефакта от воркера
	// ==================================================================

	/**
	 * Принять артефакт шага: нормализовать, оценить, связать, сохранить.
	 *
	 * @param  int   $run_id цикл
	 * @param  array $data   присланное воркером
	 * @return array         ['ok'=>…, 'id'=>…, 'value_score'=>…, 'duplicate'=>bool]
	 */
	public static function intake($run_id, array $data)
	{
		$db  = Service_Erp222_Forge::db();
		$run = $db->query(Database::SELECT, 'SELECT * FROM erp_222_runs WHERE id = '.(int) $run_id.' LIMIT 1', FALSE)->current();

		if ( ! $run)
		{
			return ['error' => 'Цикл не найден'];
		}

		$run   = (array) $run;
		$world = Service_Erp222_Forge::world((int) $run['world_id']);

		if ( ! $world)
		{
			return ['error' => 'Мир цикла не найден'];
		}

		$role_code  = (string) (Arr::get($data, 'role_code', '') !== '' ? Arr::get($data, 'role_code', '') : $run['role_code']);
		$role_types = [];

		foreach (Service_Erp222_Forge::world_roles((int) $world['id']) as $role)
		{
			if ((string) $role['code'] === (string) $role_code)
			{
				$role_types = (array) $role['types'];
			}
		}

		$norm = self::normalize((array) $data, [], $role_types);

		if (empty($norm['ok']))
		{
			Service_Erp222_Forge::event((int) $world['id'], (int) $run['id'], $role_code, 'error', (string) $norm['error']);

			return ['error' => (string) $norm['error']];
		}

		$artifact = (array) $norm['artifact'];

		// Повтор шага: та же мысль в тех же словах — не создаём копию. Отпечаток
		// считается по заголовку и началу сводки: перефразировка с точностью до
		// знаков ловится, честно новый документ по той же теме — нет.
		$hash = Service_Erp222_Forge::fingerprint($artifact['title'], $artifact['summary']);

		$dupe = $db->query(Database::SELECT,
			'SELECT id, title FROM erp_222_artifacts WHERE world_id = '.(int) $world['id'].' AND novelty_hash = '.$db->quote($hash).' LIMIT 1', FALSE)->current();

		if ($dupe)
		{
			Service_Erp222_Forge::event((int) $world['id'], (int) $run['id'], $role_code, 'note',
				'Повтор: «'.$artifact['title'].'» — уже есть артефакт #'.(int) $dupe['id']);

			return ['ok' => TRUE, 'duplicate' => TRUE, 'id' => (int) $dupe['id'], 'value_score' => 0];
		}

		// Новизна: считаем сами, не веря самооценке модели. Сравниваем с уже
		// выпущенным этим миром (заголовки и сводки — их достаточно).
		$existing = [];

		foreach (Service_Erp222_Forge::artifacts_of((int) $world['id'], '', 200) as $row)
		{
			$existing[] = ['title' => $row['title'], 'summary' => $row['summary']];
		}

		$novelty = Service_Erp222_Forge::novelty($artifact['title'].' '.$artifact['summary'], $existing);

		$scores = self::merge_scores($artifact['scores'], ['novelty' => $novelty]);

		// Прозрачность оценки: автор может не дать осей, тогда ось считается
		// нейтральной (0.5) — ценность не падает до нуля из-за молчания модели.
		$value = Service_Erp222_Forge::value_score($scores);

		$artifact_id = (int) $db->query(Database::SELECT,
			'INSERT INTO erp_222_artifacts
				(world_id, run_id, role_code, type, title, summary, body, metadata, depends_on, sources, scores, value_score, novelty_hash)
			 VALUES ('.(int) $world['id'].', '.(int) $run['id'].', '.$db->quote($role_code).', '
				.$db->quote($artifact['type']).', '.$db->quote($artifact['title']).', '.$db->quote($artifact['summary']).', '
				.$db->quote($artifact['body']).', '.$db->quote(json_encode($artifact['metadata'], JSON_UNESCAPED_UNICODE)).', '
				.$db->quote(json_encode($artifact['depends_on'], JSON_UNESCAPED_UNICODE)).', '
				.$db->quote(json_encode($artifact['sources'], JSON_UNESCAPED_UNICODE)).', '
				.$db->quote(json_encode($scores, JSON_UNESCAPED_UNICODE)).', '.(int) $value.', '.$db->quote($hash).')
			 RETURNING id', FALSE)->get('id');

		// Связи: опора артефакта на уже выпущенное (только внутри этого мира —
		// ссылка на чужой мир была бы фантазией).
		foreach ($artifact['depends_on'] as $target_id)
		{
			try
			{
				$db->query(Database::INSERT,
					'INSERT INTO erp_222_links (world_id, source_id, target_id, link_type)
					 SELECT '.(int) $world['id'].', '.(int) $artifact_id.', id, \'depends_on\' FROM erp_222_artifacts
					  WHERE id = '.(int) $target_id.' AND world_id = '.(int) $world['id'].'
					 ON CONFLICT (source_id, target_id, link_type) DO NOTHING', FALSE);
			}
			catch (Throwable $e)
			{
				// связь — не повод терять артефакт
			}
		}

		// Критика: если шаг Критика и есть target_id — разбираем чужую работу.
		if ( ! empty($data['target_id']))
		{
			self::apply_critique((int) $data['target_id'], $artifact_id, $artifact, (array) Arr::get($data, 'target_scores', []));
		}

		$stats = (array) json_decode((string) $run['stats'], TRUE);
		$stats['value_total'] = (int) Arr::get($stats, 'value_total', 0) + $value;
		$stats['steps_ok']    = (int) Arr::get($stats, 'steps_ok', 0) + 1;

		$db->query(Database::UPDATE,
			'UPDATE erp_222_runs SET artifacts_new = artifacts_new + 1, '
				.'stats = '.$db->quote(json_encode($stats, JSON_UNESCAPED_UNICODE)).' WHERE id = '.(int) $run['id'], FALSE);

		Service_Erp222_Forge::touch_world((int) $world['id']);
		Service_Erp222_Forge::event((int) $world['id'], (int) $run['id'], $role_code, 'artifact',
			'Артефакт #'.$artifact_id.' ('.Service_Erp222_Forge::type_title($artifact['type']).'): '.$artifact['title'].' — ценность '.$value,
			['id' => $artifact_id, 'value_score' => $value]);

		return ['ok' => TRUE, 'id' => $artifact_id, 'value_score' => $value, 'duplicate' => FALSE];
	}

	/** Применить критику к разобранному артефакту и записать связь. */
	public static function apply_critique($target_id, $review_id, array $review, array $target_scores = [])
	{
		$db     = Service_Erp222_Forge::db();
		$target = $db->query(Database::SELECT,
			'SELECT * FROM erp_222_artifacts WHERE id = '.(int) $target_id.' LIMIT 1', FALSE)->current();

		if ( ! $target)
		{
			return NULL;
		}

		$target = (array) $target;
		$target['scores'] = (array) json_decode((string) $target['scores'], TRUE);

		$result = self::critique_target($target, [
			'scores' => $target_scores,
		]);

		$critique = trim((string) $review['summary'].(trim((string) $review['summary']) !== '' ? "\n\n" : '').$review['body']);

		$db->query(Database::UPDATE,
			'UPDATE erp_222_artifacts SET critique = '.$db->quote(mb_substr($critique, 0, 4000)).', '
				.'scores = '.$db->quote(json_encode($result['scores'], JSON_UNESCAPED_UNICODE)).', '
				.'value_score = '.(int) $result['value_score'].' WHERE id = '.(int) $target_id, FALSE);

		try
		{
			$db->query(Database::INSERT,
				'INSERT INTO erp_222_links (world_id, source_id, target_id, link_type, note)
				 VALUES ('.(int) $target['world_id'].', '.(int) $review_id.', '.(int) $target_id.', \'refines\', '
					.$db->quote('разбор: '.mb_substr((string) $review['title'], 0, 200)).')
				 ON CONFLICT (source_id, target_id, link_type) DO NOTHING', FALSE);
		}
		catch (Throwable $e)
		{
			// связь — не повод терять критику
		}

		Service_Erp222_Forge::event((int) $target['world_id'], 0, 'critic', 'critique',
			'Разобран артефакт #'.(int) $target_id.': ценность стала '.$result['value_score']);

		return $result;
	}

	/** Связи артефакта: на что он опирается и что опирается на него. */
	public static function links($artifact_id)
	{
		$db = Service_Erp222_Forge::db();

		$out = ['depends_on' => [], 'used_by' => []];

		$rows = $db->query(Database::SELECT,
			'SELECT l.link_type, l.note, a.id, a.title, a.type
			   FROM erp_222_links l JOIN erp_222_artifacts a ON a.id = l.target_id
			  WHERE l.source_id = '.(int) $artifact_id.' ORDER BY a.id', FALSE)->as_array();

		foreach ($rows as $row)
		{
			$out['depends_on'][] = (array) $row;
		}

		$rows = $db->query(Database::SELECT,
			'SELECT l.link_type, l.note, a.id, a.title, a.type
			   FROM erp_222_links l JOIN erp_222_artifacts a ON a.id = l.source_id
			  WHERE l.target_id = '.(int) $artifact_id.' ORDER BY a.id', FALSE)->as_array();

		foreach ($rows as $row)
		{
			$out['used_by'][] = (array) $row;
		}

		return $out;
	}

	/** Подборки каталога: сколько артефактов каждого типа (для фильтров). */
	/**
	 * Markdown → HTML для показа документа на странице.
	 *
	 * Parsedown берём из nj596_guide/vendor (он уже лежит в проекте и проверен:
	 * им же рисуются AGENTS.md и манифесты). ВКЛЮЧЁН безопасный
	 * режим: тело артефакта пишет LLM, и доверять ему разметку нельзя —
	 * `setSafeMode(TRUE)` режет встроенный HTML и javascript:-ссылки.
	 *
	 * Если Parsedown по какой-то причине недоступен — отдаём текст в <pre>,
	 * а не «сырой» HTML: лучше некрасиво, чем дыра в странице.
	 */
	public static function markdown($text)
	{
		static $parsedown = NULL;

		if ($parsedown === NULL)
		{
			$path = APPPATH.'modules/nj596_guide/vendor/Parsedown.php';

			if (is_file($path))
			{
				require_once $path;

				if (class_exists('Parsedown'))
				{
					$parsedown = new Parsedown();
					$parsedown->setSafeMode(TRUE);
				}
			}

			if ($parsedown === NULL)
			{
				$parsedown = FALSE;
			}
		}

		$text = (string) $text;

		if ( ! $parsedown)
		{
			return '<pre class="whitespace-pre-wrap">'.HTML::chars($text).'</pre>';
		}

		return $parsedown->text($text);
	}

	/**
	 * Разбить документ на страницы для показа.
	 *
	 * Сначала режем по заголовкам `#` и `##` (это и есть естественные
	 * разделы спецификации: «Схема данных», «Контракт API»…).
	 * Раздел, который не влез в страницу, дробим по абзацам —
	 * иначе получается страница размером с простыню.
	 * Короткий документ остаётся одной страницей:
	 * листалка тогда не показывается.
	 *
	 * @param  string $body      исходник в markdown
	 * @param  int    $max_chars комфортный размер страницы, символов
	 * @return array  [['title' => …, 'markdown' => …, 'html' => …], …]
	 */
	public static function document_pages($body, $max_chars = 2600)
	{
		$body = str_replace(["\r\n", "\r"], "\n", trim((string) $body));

		if ($body === '')
		{
			return [];
		}

		$parts = preg_split('/^(?=#{1,2} )/m', $body, -1, PREG_SPLIT_NO_EMPTY);

		if ( ! $parts)
		{
			$parts = [$body];
		}

		$pages = [];

		foreach ($parts as $part)
		{
			foreach (self::_chunks($part, $max_chars) as $index => $chunk)
			{
				$title = self::_page_title($chunk);
				$title = $index > 0 ? $title.' (продолжение)' : $title;

				$pages[] = [
					'title'    => $title,
					'markdown' => trim($chunk),
					'html'     => self::markdown($chunk),
				];
			}
		}

		return $pages;
	}

	/** Заголовок страницы — первая строка вида «## Что-то». */
	protected static function _page_title($text)
	{
		$lines = explode("\n", (string) $text);

		foreach ($lines as $line)
		{
			if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/', $line, $m) === 1)
			{
				return mb_substr($m[1], 0, 120);
			}
		}

		return '';
	}

	/** Кусок состоит только из заголовка (одна строка, начинается с #)? */
	protected static function _only_heading($text)
	{
		$lines = explode("\n", trim((string) $text));
		$lines = array_values(array_filter($lines, function ($line) { return trim($line) !== ''; }));

		return count($lines) === 1 AND strpos(ltrim($lines[0]), '#') === 0;
	}

	/**
	 * Нарезать кусок markdown на части не длиннее $max_chars по абзацам.
	 * Абзац, который сам длиннее лимита, не рвём — лучше
	 * длинная страница, чем разорванная таблица посередине строки.
	 */
	protected static function _chunks($text, $max_chars)
	{
		$text = (string) $text;

		if (mb_strlen($text) <= $max_chars)
		{
			return [$text];
		}

		$blocks = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];

		$out    = [];
		$buffer = '';

		foreach ($blocks as $block)
		{
			if ($buffer === '')
			{
				$buffer = $block;

				continue;
			}

			$candidate = $buffer."\n\n".$block;

			// Заголовок отдельной страницей не отдаём: «## Схема данных» без
			// содержимого — это не страница, а обрубок. Если в буфере пока
			// только заголовок, следующий блок приклеиваем к нему в любом случае.
			if (mb_strlen($candidate) > $max_chars AND ! self::_only_heading($buffer))
			{
				$out[]  = $buffer;
				$buffer = $block;

				continue;
			}

			$buffer = $candidate;
		}

		if ($buffer !== '')
		{
			$out[] = $buffer;
		}

		return $out ? $out : [$text];
	}

	public static function type_counts($any = FALSE)
	{
		$db    = Service_Erp222_Forge::db();
		$rows  = $db->query(Database::SELECT,
			'SELECT type, count(*) AS c FROM erp_222_artifacts'.($any ? '' : ' WHERE published IS TRUE').' GROUP BY type', FALSE)->as_array();

		$out = [];

		foreach ($rows as $row)
		{
			$row = (array) $row;
			$out[(string) $row['type']] = (int) $row['c'];
		}

		return $out;
	}
}
