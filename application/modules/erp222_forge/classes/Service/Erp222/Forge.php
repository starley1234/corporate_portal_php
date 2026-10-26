<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ERP-222 «Кузница систем»: миры, роли, циклы синтеза и живой ход.
 *
 * Наработки владельца (source_data/erp222_aeterna_forge, Koseven + pgvector)
 * перенесены в n2j.ru и пересмотрены под одну цель: чтобы КАЖДЫЙ цикл
 * заканчивался пачкой полезных артефактов, а не перепиской агентов.
 *
 * Роли цикла (конфиг roles): Архитектор → Инженер → Аналитик → Критик →
 * Редактор. Роль на шаге выбирается кругом, поэтому цикл из 6 шагов даёт
 * сбалансированный набор: смысл, реализуемость, деньги, риски, редактура.
 *
 * Живой ход — тот же контракт, что у ИИ-радара (Service_Nj661_Airadar::run_progress):
 * этап, заметка этапа, индекс этапа в лестнице `$stages`, секундомер и счётчики
 * строкой. Отсюда одна и та же вёрстка статус-ряда на двух страницах и один
 * JS-движок прогресса — правила эталона /pages/required_design_sample.
 *
 * Стиль SQL — интерполяция с $db->quote(), как в nj593/nj660/nj661. Чистые
 * функции (оценки, отпечатки, круг ролей) закрыты стендом .tools/erp222_forge_stand.php
 * и тестом tests/Unit/ForgeServiceTest.php.
 */
class Service_Erp222_Forge {

	/** Канал очереди nj120: цикл синтеза исполняет python-воркер. */
	const CHANNEL = 'erp222.forge';

	/** Этапы цикла: по ним дашборд рисует, где прогон сейчас. */
	public static $stages = ['Роли', 'Синтез', 'Критика', 'Сводка'];

	// ==================================================================
	// Конфиг и словари
	// ==================================================================

	/** Значение конфига модуля (dot-путь, как Arr::path). */
	public static function config($key = NULL, $default = NULL)
	{
		$cfg = (array) Kohana::$config->load('erp222_forge');

		if ($key === NULL)
		{
			return $cfg;
		}

		return Arr::path($cfg, $key, $default);
	}

	/** Роли цикла: код → описание (title, focus, types, icon). */
	public static function roles()
	{
		return (array) self::config('roles', []);
	}

	/** Типы артефактов: код → (title, about, proof, icon). */
	public static function types()
	{
		return (array) self::config('types', []);
	}

	/** Человеческое название типа артефакта (для каталога и вводной шага). */
	public static function type_title($type)
	{
		$types = self::types();

		return isset($types[$type]['title']) ? (string) $types[$type]['title'] : (string) $type;
	}

	/** Название роли по коду. */
	public static function role_title($code)
	{
		$roles = self::roles();

		return isset($roles[$code]['title']) ? (string) $roles[$code]['title'] : (string) $code;
	}

	/**
	 * «Провайдер::модель» для шагов синтеза.
	 *
	 * Пусто в конфиге модуля — берём умолчание платформы (llm.default_model):
	 * одна точка смены модели на весь сайт. Мир может переопределить модель
	 * у себя (поле model) — это личный инструмент, эксперименты ожидаемы.
	 */
	public static function model_for($world_model = '')
	{
		$model = trim((string) $world_model);

		if ($model !== '')
		{
			return $model;
		}

		$model = trim((string) self::config('model', ''));

		if ($model !== '')
		{
			return $model;
		}

		return (string) Kohana::$config->load('llm.default_model');
	}

	// ==================================================================
	// Чистые функции (проверяются без базы)
	// ==================================================================

	/**
	 * Круг ролей для N шагов: коды enabled-ролей по порядку sort.
	 *
	 * Цикл обязан возвращать не только «творцов», но и проверяющих: без
	 * Критика и Редактора мир выдаёт пачку черновиков без оценки — это ровно та
	 * бесполезность, из-за которой наработки пересматривались.
	 *
	 * @param  array $roles роли из конфига (код → поля)
	 * @param  int   $steps сколько шагов в цикле
	 * @return array список кодов ролей длиной $steps
	 */
	public static function role_cycle(array $roles, $steps)
	{
		$codes = array_keys($roles);

		if ( ! $codes)
		{
			return [];
		}

		$steps = max(1, (int) $steps);
		$out   = [];

		for ($i = 0; $i < $steps; $i++)
		{
			$out[] = (string) $codes[$i % count($codes)];
		}

		return $out;
	}

	/**
	 * Ценность артефакта 0..100 из оценок ролей.
	 *
	 * Раньше «эволюция» ставила один общий score и хранила его в отдельном
	 * логе; здесь ценность складывается из четырёх понятных осей с весами из
	 * конфига — видно, ЧЕМ артефакт хорош, а чем слаб.
	 *
	 * @param  array $scores  usefulness|concreteness|novelty|feasibility (0..1)
	 * @param  array $weights веса осей (по умолчанию — value_weights)
	 * @return int            ценность 0..100
	 */
	public static function value_score(array $scores, array $weights = [])
	{
		$weights = $weights ? $weights : (array) self::config('value_weights', []);

		if ( ! $weights)
		{
			$weights = ['usefulness' => 0.25, 'concreteness' => 0.25, 'novelty' => 0.25, 'feasibility' => 0.25];
		}

		$sum = 0.0;
		$w   = 0.0;

		foreach ($weights as $key => $weight)
		{
			$weight = (float) $weight;

			if ($weight <= 0)
			{
				continue;
			}

			// Оценки приходят и строками («0.8»), и в процентах (80): приводим
			// к 0..1, чтобы шкала не разъезжалась между воркером и сайтом.
			$v = isset($scores[$key]) ? self::unit_scale($scores[$key]) : 0.5;

			$sum += $v * $weight;
			$w   += $weight;
		}

		if ($w <= 0)
		{
			return 0;
		}

		return (int) max(0, min(100, (int) round(100 * ($sum / $w))));
	}

	/** Привести оценку к 0..1: «0.8» → 0.8, 80 → 0.8, мусор → 0.5. */
	public static function unit_scale($value)
	{
		if (is_string($value))
		{
			$value = str_replace(',', '.', trim($value));
		}

		if ( ! is_numeric($value))
		{
			return 0.5;
		}

		$value = (float) $value;

		if ($value > 1.0)
		{
			$value = $value / 100;
		}

		return max(0.0, min(1.0, $value));
	}

	/**
	 * Новизна артефакта относительно уже выпущенных: 1 − самая похожая пара.
	 *
	 * Считаем словами (пересечение значимых слов) — без pgvector и без внешнего
	 * сервиса эмбеддингов: наработки требовали расширения vector(1024) в схеме
	 * public и падали на несовпадении размерности, а пользы для «не повторяйся»
	 * хватает и у лексического сравнения.
	 *
	 * @param  string $text   заголовок + сводка нового артефакта
	 * @param  array  $others существующие ['title' => …, 'summary' => …]
	 * @return float          0..1 (1 — ничего похожего)
	 */
	public static function novelty($text, array $others)
	{
		$words = self::keywords($text);

		if ( ! $words)
		{
			return 1.0;
		}

		$worst = 0.0;

		foreach ($others as $other)
		{
			$own = self::keywords(Arr::get((array) $other, 'title', '').' '.Arr::get((array) $other, 'summary', ''));

			if ( ! $own)
			{
				continue;
			}

			$shared = count(array_intersect_key($words, $own));
			$union  = count($words + $own);

			$similarity = $union > 0 ? $shared / $union : 0.0;

			if ($similarity > $worst)
			{
				$worst = $similarity;
			}
		}

		return round(max(0.0, min(1.0, 1.0 - $worst)), 4);
	}

	/**
	 * Значимые слова текста: нижний регистр, ≥4 букв, без «воды» и служебных.
	 *
	 * Возвращает набор (слово => TRUE) — по нему считается пересечение.
	 */
	public static function keywords($text)
	{
		$text = mb_strtolower((string) $text);
		$text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

		$stop = ['этот', 'этого', 'если', 'чтобы', 'который', 'которые', 'будет', 'будут', 'нужно',
			'можно', 'также', 'только', 'более', 'менее', 'система', 'данные', 'также', 'после',
			'перед', 'через', 'между', 'либо', 'именно', 'например', 'того', 'чему', 'свои'];

		$out = [];

		foreach (preg_split('/\s+/u', (string) $text) as $word)
		{
			if (mb_strlen($word) < 4 OR in_array($word, $stop, TRUE))
			{
				continue;
			}

			$out[$word] = TRUE;
		}

		return $out;
	}

	/** Отпечаток артефакта: ловит повтор шага (та же мысль другими словами — нет). */
	public static function fingerprint($title, $summary = '')
	{
		$title   = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $title)));
		$summary = mb_strtolower(mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $summary)), 0, 200));

		return sha1($title.'|'.$summary);
	}

	/**
	 * Человеческая запись таймера: «0:07», «12:40», «1:02:15».
	 *
	 * Секундомер — часть статус-ряда (правило 4 эталона), поэтому формат живёт
	 * в сервисе: серверная отрисовка и JS-обновление обязаны совпадать.
	 */
	public static function elapsed_text($sec)
	{
		$sec = max(0, (int) $sec);
		$h   = floor($sec / 3600);
		$m   = floor(($sec % 3600) / 60);
		$s   = $sec % 60;

		if ($h > 0)
		{
			return $h.':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT).':'.str_pad((string) $s, 2, '0', STR_PAD_LEFT);
		}

		return $m.':'.str_pad((string) $s, 2, '0', STR_PAD_LEFT);
	}

	/**
	 * Строка счётчиков для статус-ряда: «шаг 3/6 · артефактов: 3 · ценность: 210».
	 *
	 * Показываем только то, что уже есть: пустой прогон читается как «ставлю
	 * задачу в очередь», а не как нули по всем осям.
	 */
	public static function counters_text(array $run)
	{
		$steps     = (int) Arr::get($run, 'steps_planned', 0);
		$done      = (int) Arr::get($run, 'steps_done', 0);
		$artifacts = (int) Arr::get($run, 'artifacts_new', 0);
		$value     = (int) Arr::get((array) Arr::get($run, 'stats', []), 'value_total', 0);

		$parts = [];

		if ($steps > 0)
		{
			$parts[] = 'шаг '.min($done + ($done < $steps ? 1 : 0), $steps).'/'.$steps;
		}

		if ($artifacts > 0)
		{
			$parts[] = 'артефактов: '.$artifacts;
		}

		if ($value > 0)
		{
			$parts[] = 'ценность: '.$value;
		}

		return $parts ? implode(' · ', $parts) : 'ставлю задачу в очередь…';
	}

	/**
	 * Данные живого статус-ряда (контракт один с nj661 → один JS-движок).
	 *
	 * @param  array|NULL $run строка erp_222_runs (или NULL)
	 * @return array|NULL     этап, индекс этапа, секунды, счётчики и роль
	 */
	public static function run_progress($run)
	{
		if ( ! $run)
		{
			return NULL;
		}

		$run    = (array) $run;
		$stats  = is_array(Arr::get($run, 'stats', []))
			? (array) $run['stats']
			: (array) json_decode((string) Arr::get($run, 'stats', ''), TRUE);
		$status = (string) Arr::get($run, 'status', 'new');
		$live   = in_array($status, ['new', 'running'], TRUE);
		$stage  = trim((string) Arr::get($run, 'stage', ''));
		$role   = (string) Arr::get($run, 'role_code', '');

		// Индекс этапа в лестнице: воркер пишет «Синтез», «Критика», «Сводка».
		$index = -1;

		foreach (self::$stages as $i => $name)
		{
			if ($stage !== '' AND mb_stripos($stage, $name) !== FALSE)
			{
				$index = $i;
				break;
			}
		}

		$started  = strtotime((string) Arr::get($run, 'created_at', 'now')) ?: time();
		$finished = trim((string) Arr::get($run, 'finished_at', '')) !== ''
			? (strtotime((string) $run['finished_at']) ?: NULL)
			: NULL;

		$note = (string) Arr::get($run, 'stage_note', '');

		return [
			'id'            => (int) Arr::get($run, 'id', 0),
			'world_id'      => (int) Arr::get($run, 'world_id', 0),
			'status'        => $status,
			'live'          => $live,
			'trigger'       => (string) Arr::get($run, 'trigger', ''),
			'stage'         => $stage !== '' ? $stage : ($live ? 'Готовлю роли мира…' : ''),
			'stage_note'    => $note,
			'stage_index'   => $index,
			'stages'        => self::$stages,
			'role_code'     => $role,
			'role_title'    => $role !== '' ? self::role_title($role) : '',
			'elapsed_sec'   => $finished !== NULL ? max(0, $finished - $started) : max(0, time() - $started),
			'elapsed_text'  => self::elapsed_text($finished !== NULL ? max(0, $finished - $started) : max(0, time() - $started)),
			'error'         => (string) Arr::get($run, 'error', ''),
			'steps_planned' => (int) Arr::get($run, 'steps_planned', 0),
			'steps_done'    => (int) Arr::get($run, 'steps_done', 0),
			'artifacts_new' => (int) Arr::get($run, 'artifacts_new', 0),
			'counters_text' => self::counters_text($run),
			'value_total'   => (int) Arr::get($stats, 'value_total', 0),
		];
	}

	// ==================================================================
	// Миры
	// ==================================================================

	/**
	 * Подключение к БД.
	 *
	 * Публичный метод, а не protected `_db()`: артефакты живут в отдельном
	 * сервисе (Service_Erp222_Artifact) и ходят в ту же базу — дублировать
	 * доступ к соединению в двух местах незачем.
	 */
	public static function db()
	{
		return Database::instance();
	}

	/** Мир по коду (для URL) или NULL. */
	public static function world_by_code($code)
	{
		$row = self::db()->query(Database::SELECT,
			'SELECT * FROM erp_222_worlds WHERE code = '.self::db()->quote((string) $code).' LIMIT 1', FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	/** Мир по id или NULL. */
	public static function world($id)
	{
		$row = self::db()->query(Database::SELECT,
			'SELECT * FROM erp_222_worlds WHERE id = '.(int) $id.' LIMIT 1', FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	/** Список миров: свежие сверху, с числом артефактов и средней ценностью. */
	public static function worlds($limit = 50)
	{
		$limit = max(1, min(200, (int) $limit));

		$rows = self::db()->query(Database::SELECT,
			'SELECT w.*,
			        (SELECT count(*) FROM erp_222_artifacts a WHERE a.world_id = w.id) AS artifacts,
			        (SELECT COALESCE(round(avg(a.value_score)), 0) FROM erp_222_artifacts a WHERE a.world_id = w.id) AS value_avg
			   FROM erp_222_worlds w
			  ORDER BY w.updated_at DESC, w.id DESC
			  LIMIT '.$limit, FALSE)->as_array();

		$out = [];

		foreach ($rows as $row)
		{
			$out[] = (array) $row;
		}

		return $out;
	}

	/**
	 * Создать мир: задумка → роли-каркас.
	 *
	 * Наработки создавали мир через LLM («Genesis»): модель придумывала название,
	 * протокол и агентов одним JSON. Здесь первый шаг делает владелец (это его
	 * инструмент и его формулировка задачи), а протокол дописывает цикл: роль
	 * Архитектора на первом шаге уточняет границы — так вводная не превращается
	 * в «сгенерируйте мне мир» без ответственности за результат.
	 */
	public static function create_world($title, $brief, $domain = '', $user_id = 1)
	{
		$title = trim(preg_replace('/\s+/u', ' ', (string) $title));
		$brief = trim((string) $brief);
		$domain = mb_substr(trim((string) $domain), 0, 64);

		if (mb_strlen($title) < 3)
		{
			return ['error' => 'Название мира — минимум 3 знака'];
		}

		if (mb_strlen($brief) < 40)
		{
			return ['error' => 'Опишите задумку подробнее: что строим и для кого (минимум 40 знаков) — от этого зависит качество артефактов'];
		}

		$db  = self::db();
		$code = self::unique_code($title);

		$world_id = (int) $db->query(Database::SELECT,
			'INSERT INTO erp_222_worlds (code, title, brief, domain, owner_id, protocol, goal)
			 VALUES ('.$db->quote($code).', '.$db->quote(mb_substr($title, 0, 200)).', '.$db->quote($brief).', '
				.$db->quote($domain).', '.(int) $user_id.', '.$db->quote(self::default_protocol()).', '.$db->quote(self::default_goal($title)).')
			 RETURNING id', FALSE)->get('id');

		self::seed_roles($world_id);
		self::event($world_id, 0, '', 'run', 'Мир создан: '.$title);

		return ['ok' => TRUE, 'id' => $world_id, 'code' => $code];
	}

	/**
	 * Код мира из названия: латиница/цифры, «_», уникален.
	 *
	 * Наработки делали `URL::title($code).'_'.mt_rand(100,999)` — случайный
	 * хвост и код на латинице из русского названия не получался; здесь код —
	 * транслит названия, а уникальность добирается суффиксом только при коллизии.
	 */
	public static function unique_code($title)
	{
		$base = self::slug($title);

		if ($base === '')
		{
			$base = 'world';
		}

		$candidate = $base;
		$n         = 2;

		while (self::world_by_code($candidate) !== NULL)
		{
			$candidate = $base.'_'.$n;
			$n++;

			if ($n > 200)
			{
				$candidate = $base.'_'.time();
				break;
			}
		}

		return $candidate;
	}

	/**
	 * Слаг для URL: русские буквы → латиница (свой транслит), остальное — «_».
	 *
	 * Отдельная функция, а не URL::title: тот выкидывает кириллицу целиком, и
	 * «Кузница отчётов» превращалась в пустую строку.
	 */
	public static function slug($text)
	{
		$map = [
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
			'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
			'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
			'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
			'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
		];

		$text = mb_strtolower(trim((string) $text));
		$text = strtr($text, $map);
		$text = preg_replace('/[^a-z0-9]+/u', '_', $text);
		$text = trim((string) $text, '_');

		return mb_substr($text, 0, 48);
	}

	/** Протокол мира по умолчанию: правила, которые делают артефакты полезными. */
	public static function default_protocol()
	{
		return "ПРАВИЛА МИРА (обязательны для каждого шага):\n"
			."1. Каждый шаг выпускает ОДИН артефакт: готовый документ, а не рассуждение о нём.\n"
			."2. У артефакта есть вход и выход: что нужно на входе, что получается на выходе, в каком виде.\n"
			."3. Запрещены абстракции без числа, имени и срока: «улучшить», «оптимизировать», «внедрить» без конкретики не считаются работой.\n"
			."4. Не повторяй уже выпущенное: если мысль есть — развивай её связью (refines), а не новой копией.\n"
			."5. Всё, что нельзя проверить (тест, метрика, ручная проверка), помечай как допущение.\n"
			."6. Критик оценивает чужие артефакты по осям: полезность, конкретность, новизна, реализуемость — от 0 до 1.";
	}

	public static function default_goal($title)
	{
		return 'Собрать по теме «'.$title.'» полный комплект рабочих артефактов: спецификацию, схему данных, контракт API, алгоритм, промпт, план проверки, риски, метрики, оценку и оффер — так, чтобы по ним можно было начинать работу без дополнительных разъяснений.';
	}

	/**
	 * Роли-каркас нового мира из конфига.
	 *
	 * Роли живут в БД (а не только в конфиге), потому что мир может отключить
	 * ненужную роль или сменить ей модель — это эксперимент владельца.
	 */
	public static function seed_roles($world_id)
	{
		$db = self::db();
		$sort = 0;

		foreach (self::roles() as $code => $role)
		{
			$db->query(Database::INSERT,
				'INSERT INTO erp_222_roles (world_id, code, title, focus, types, sort)
				 VALUES ('.(int) $world_id.', '.$db->quote($code).', '.$db->quote((string) Arr::get($role, 'title', $code)).', '
					.$db->quote((string) Arr::get($role, 'focus', '')).', '
					.$db->quote(implode(',', (array) Arr::get($role, 'types', []))).', '.(int) $sort.')
				 ON CONFLICT (world_id, code) DO NOTHING', FALSE);

			$sort++;
		}

		return $sort;
	}

	/** Роли мира (включённые), по порядку. */
	public static function world_roles($world_id)
	{
		$rows = self::db()->query(Database::SELECT,
			'SELECT * FROM erp_222_roles WHERE world_id = '.(int) $world_id.' ORDER BY sort, id', FALSE)->as_array();

		$out = [];

		foreach ($rows as $row)
		{
			$row = (array) $row;
			$row['types'] = array_values(array_filter(array_map('trim', explode(',', (string) $row['types']))));
			$out[] = $row;
		}

		return $out;
	}

	// ==================================================================
	// Циклы синтеза
	// ==================================================================

	/**
	 * Активный (незавершённый) цикл или NULL; при необходимости догоняет очередь.
	 *
	 * Здесь же — лечение осиротевших циклов, иначе мир нельзя запустить второй
	 * раз: цикл, чей воркер умер или чья задача закрыта, навсегда оставался бы
	 * «идущим» и блокировал запуски. Два признака смерти, оба проверяются:
	 *   · задача очереди закрыта (done/failed/cancelled) — цикл закрываем по факту;
	 *   · воркер молчит дольше порога (limits.run_stale_min, 90 мин — выше
	 *     timeout_sec в 45 минут, поэтому живой прогон сюда не попадает).
	 */
	public static function active_run($world_id = 0)
	{
		$where = $world_id > 0 ? ' AND world_id = '.(int) $world_id : '';

		$row = self::db()->query(Database::SELECT,
			"SELECT * FROM erp_222_runs WHERE status IN ('new','running')".$where.' ORDER BY id DESC LIMIT 1', FALSE)->current();

		if ( ! $row)
		{
			return NULL;
		}

		$run = (array) $row;

		if (self::_close_if_dead($run))
		{
			return NULL;
		}

		return self::_catch_up($run);
	}

	/**
	 * Закрыть цикл, который уже не может продолжаться. TRUE — цикл закрыт.
	 *
	 * Ошибку очередь не обязана отдавать: job мог не создаться (start_run тогда
	 * закрывает цикл сам), а воркер мог упасть между шагами. Поэтому «нет
	 * задачи» и «воркер молчит» — тоже признаки, только мягче: сначала смотрим
	 * статус задачи, и лишь когда его нет — порог молчания.
	 */
	protected static function _close_if_dead(array $run)
	{
		$db     = self::db();
		$job_id = (int) Arr::get($run, 'job_id', 0);

		if ($job_id > 0)
		{
			$status = $db->query(Database::SELECT,
				'SELECT status FROM nj_120_jobs WHERE id = '.$job_id.' LIMIT 1', FALSE)->get('status');

			if ($status !== NULL AND in_array((string) $status, ['done', 'failed', 'cancelled'], TRUE))
			{
				self::finish_run((int) $run['id'], (string) $status === 'done' ? 'done' : 'fail',
					'задача очереди закрыта со статусом '.(string) $status.' — цикл закрыт сайтом');

				return TRUE;
			}
		}

		$silent = (int) Arr::get((array) self::config('limits', []), 'run_stale_min', 90);

		if ($silent > 0 AND strtotime((string) Arr::get($run, 'created_at', 'now')) < time() - $silent * 60)
		{
			self::finish_run((int) $run['id'], 'fail',
				'воркер молчал больше '.$silent.' мин — цикл закрыт сайтом, можно запускать заново');

			return TRUE;
		}

		return FALSE;
	}

	/** Циклы мира: свежие сверху (страница мира показывает их историей). */
	public static function runs_of($world_id, $limit = 10)
	{
		$rows = self::db()->query(Database::SELECT,
			'SELECT * FROM erp_222_runs WHERE world_id = '.(int) $world_id
				.' ORDER BY id DESC LIMIT '.max(1, min(50, (int) $limit)), FALSE)->as_array();

		$out = [];

		foreach ($rows as $row)
		{
			$row = (array) $row;
			$row['stats'] = (array) json_decode((string) $row['stats'], TRUE);
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Пауза/продолжение мира.
	 *
	 * Удаления мира нет намеренно: артефакты — выпущенный результат, их нельзя
	 * «убрать вместе с миром». Пауза останавливает новые циклы, всё выпущенное
	 * живёт и читается (в том числе в публичном каталоге).
	 */
	public static function set_status($world_id, $status)
	{
		$status = (string) $status === 'active' ? 'active' : 'paused';

		self::db()->query(Database::UPDATE,
			'UPDATE erp_222_worlds SET status = '.self::db()->quote($status).', updated_at = now()
			  WHERE id = '.(int) $world_id, FALSE);

		$world = self::world($world_id);

		if ($world)
		{
			self::event($world_id, 0, '', 'note', $status === 'active'
				? 'Мир возобновлён'
				: 'Мир поставлен на паузу');
		}

		return $status;
	}

	/** Последний цикл мира (или вообще) — для дашборда и страницы мира. */
	public static function last_run($world_id = 0)
	{
		$where = $world_id > 0 ? ' WHERE world_id = '.(int) $world_id : '';

		$row = self::db()->query(Database::SELECT,
			'SELECT * FROM erp_222_runs'.$where.' ORDER BY id DESC LIMIT 1', FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	/**
	 * Догнать цикл по задаче очереди (тот же урок, что у ИИ-радара).
	 *
	 * Живой случай класса: задача закрыта авто-остановкой nj120, а /finish от
	 * воркера не доехал — цикл навсегда остался running, дашборд залип на
	 * «Конвейер работает…», новый цикл запустить нельзя. Каждый взгляд на
	 * активный цикл сверяется с очередью: задача закрыта → закрываем и цикл.
	 *
	 * @param  array $run строка erp_222_runs (status new|running)
	 * @return array|NULL обновлённый прогон или NULL — активных не осталось
	 */
	protected static function _catch_up(array $run)
	{
		$job_id = (int) Arr::get($run, 'job_id', 0);

		if ($job_id <= 0)
		{
			// Цикл-сирота: задачу не поставили. Старше 3 часов — закрываем.
			if (time() - strtotime((string) $run['created_at']) > 3 * 3600)
			{
				self::finish_run((int) $run['id'], 'fail', 'Цикл остался без задачи очереди и молчит дольше 3 часов — закрыт сверкой.');

				return NULL;
			}

			return $run;
		}

		try
		{
			$job = self::db()->query(Database::SELECT,
				'SELECT status, error FROM nj_120_jobs WHERE id = '.$job_id.' LIMIT 1', FALSE)->current();
		}
		catch (Throwable $e)
		{
			return $run;
		}

		if ( ! $job)
		{
			return $run;
		}

		$status = (string) $job['status'];

		if ( ! in_array($status, ['done', 'failed', 'cancelled'], TRUE))
		{
			return $run;
		}

		// Задача закрыта. Если артефакты цикла есть — он отработал (пусть и без
		// финального отчёта), иначе — ошибка с причиной из очереди.
		$artifacts = (int) self::db()->query(Database::SELECT,
			'SELECT count(*) AS c FROM erp_222_artifacts WHERE run_id = '.(int) $run['id'], FALSE)->get('c');

		if ($artifacts > 0)
		{
			self::finish_run((int) $run['id'], 'done', '');
		}
		else
		{
			self::finish_run((int) $run['id'], 'fail',
				'Задача очереди закрыта ('.(string) $job['status'].')'.(trim((string) $job['error']) !== '' ? ': '.trim((string) $job['error']) : ' без артефактов — цикл остановлен сверкой.'));
		}

		return NULL;
	}

	/**
	 * Запустить цикл синтеза: строка прогона + задача очереди nj120.
	 *
	 * @param  int    $world_id мир
	 * @param  int    $steps    сколько шагов (каждый шаг — вызов LLM)
	 * @param  string $trigger  manual|schedule
	 * @param  int    $user_id  кто запустил
	 * @return array            ['ok'=>…] либо ['error'=>…]
	 */
	public static function start_run($world_id, $steps = 0, $trigger = 'manual', $user_id = 1)
	{
		$world = self::world($world_id);

		if ( ! $world)
		{
			return ['error' => 'Мир не найден'];
		}

		if ((string) $world['status'] !== 'active')
		{
			return ['error' => 'Мир на паузе — сначала продолжите его'];
		}

		if (self::active_run($world_id) !== NULL)
		{
			return ['error' => 'Цикл уже идёт — дождитесь завершения'];
		}

		if ( ! class_exists('Service_Nj120_JobQueue'))
		{
			return ['error' => 'Очередь задач nj120 недоступна — цикл запустить нельзя'];
		}

		$limits = (array) self::config('limits', []);
		$steps  = (int) $steps > 0 ? (int) $steps : (int) Arr::get($limits, 'steps_default', 6);
		$steps  = max(1, min((int) Arr::get($limits, 'steps_max', 12), $steps));

		$db = self::db();

		$run_id = (int) $db->query(Database::SELECT,
			'INSERT INTO erp_222_runs (world_id, trigger, status, stage, stage_note, steps_planned, stats)
			 VALUES ('.(int) $world_id.', '.$db->quote((string) $trigger).", 'new', 'Роли', 'готовлю роли и вводную', ".(int) $steps.", '{}')
			 RETURNING id", FALSE)->get('id');

		$payload = [
			'run_id'   => $run_id,
			'world_id' => (int) $world_id,
			'steps'    => $steps,
			'limits'   => $limits,
			'model'    => self::model_for((string) $world['model']),
		];

		try
		{
			$job_id = Service_Nj120_JobQueue::push(self::CHANNEL, $payload, [
				'module'       => 'erp222',
				'visitor_id'   => 'u:'.((int) $user_id > 0 ? (int) $user_id : 1),
				'created_by'   => (int) $user_id > 0 ? (int) $user_id : 1,
				'max_attempts' => 2,
				// Каждый шаг — отдельный вызов LLM: цикл из 12 шагов на локальной
				// модели идёт долго. Порог молчания канала поднимается в конфиге
				// очереди (silent_stop_channels), как у ИИ-радара.
				'timeout_sec'  => 2700,
			]);
		}
		catch (Throwable $e)
		{
			self::finish_run($run_id, 'fail', 'Задача очереди не поставилась: '.$e->getMessage());

			return ['error' => 'Очередь не приняла задачу: '.$e->getMessage()];
		}

		$db->query(Database::UPDATE,
			'UPDATE erp_222_runs SET job_id = '.$job_id.', status = \'running\', stage = \'Роли\' WHERE id = '.$run_id, FALSE);

		self::event((int) $world_id, $run_id, '', 'run', 'Цикл #'.$run_id.' запущен: шагов '.$steps);

		return ['ok' => TRUE, 'run_id' => $run_id, 'job_id' => $job_id, 'steps' => $steps];
	}

	/**
	 * Обновить этап цикла (воркер сообщает: «Синтез — пишу контракт API»).
	 *
	 * Этап — это то, что видит владелец в статус-ряде, поэтому заметку режем по
	 * длине: строка этапа не должна ломать вёрстку.
	 */
	public static function set_stage($run_id, $stage, $note = '')
	{
		$db    = self::db();
		$stage = trim((string) $stage);
		$note  = trim(preg_replace('/\s+/u', ' ', (string) $note));

		// Этап приводим к лестнице прогресса: воркер может написать «Синтез: …»,
		// «Критика» или своё слово — неизвестное читается как «Синтез» (реже
		// всего это именно он), иначе статус-ряд гаснет.
		$index = -1;

		foreach (self::$stages as $i => $name)
		{
			if ($stage !== '' AND mb_stripos($stage, $name) !== FALSE)
			{
				$index = $i;
				break;
			}
		}

		if ($index < 0 AND $stage !== '')
		{
			$stage = 'Синтез';
		}

		$db->query(Database::UPDATE,
			'UPDATE erp_222_runs SET stage = '.$db->quote(mb_substr($stage, 0, 60)).', '
				.'stage_note = '.$db->quote(mb_substr($note, 0, 380)).' WHERE id = '.(int) $run_id, FALSE);

		$run = $db->query(Database::SELECT, 'SELECT world_id, role_code FROM erp_222_runs WHERE id = '.(int) $run_id.' LIMIT 1', FALSE)->current();

		if ($run AND $stage !== '')
		{
			self::event((int) $run['world_id'], (int) $run_id, (string) $run['role_code'], 'note', $stage.($note !== '' ? ': '.$note : ''));
		}

		return TRUE;
	}

	/** Завершить цикл (вызывает воркер через /forge/api/finish или сверка). */
	public static function finish_run($run_id, $status, $error = '', array $stats = [])
	{
		$status = in_array($status, ['done', 'fail'], TRUE) ? $status : 'done';
		$db     = self::db();

		$run = $db->query(Database::SELECT,
			'SELECT * FROM erp_222_runs WHERE id = '.(int) $run_id.' LIMIT 1', FALSE)->current();

		if ( ! $run)
		{
			return ['error' => 'Цикл не найден'];
		}

		$run = (array) $run;

		// Счётчики итога: сколько артефактов и какова суммарная ценность.
		$agg = $db->query(Database::SELECT,
			'SELECT count(*) AS c, COALESCE(sum(value_score), 0) AS v FROM erp_222_artifacts WHERE run_id = '.(int) $run_id, FALSE)->current();

		$stats = array_merge((array) json_decode((string) $run['stats'], TRUE), $stats, [
			'artifacts'   => (int) ($agg ? $agg['c'] : 0),
			'value_total' => (int) ($agg ? $agg['v'] : 0),
		]);

		$stage = $status === 'done' ? 'Сводка' : 'Остановлен';

		$db->query(Database::UPDATE,
			'UPDATE erp_222_runs SET status = '.$db->quote($status).', stage = '.$db->quote($stage).', '
				.'stage_note = '.$db->quote($status === 'done' ? 'цикл завершён' : mb_substr($error, 0, 380)).', '
				.'error = '.$db->quote($status === 'fail' ? mb_substr($error, 0, 1000) : '').', '
				."stats = ".$db->quote(json_encode($stats, JSON_UNESCAPED_UNICODE)).", finished_at = now() WHERE id = ".(int) $run_id, FALSE);

		self::event((int) $run['world_id'], (int) $run_id, '', $status === 'done' ? 'run' : 'error',
			$status === 'done'
				? 'Цикл #'.$run_id.' завершён: артефактов '.$stats['artifacts'].', ценность '.$stats['value_total']
				: 'Цикл #'.$run_id.' остановлен: '.mb_substr($error, 0, 300));

		return ['ok' => TRUE, 'artifacts' => $stats['artifacts'], 'value_total' => $stats['value_total']];
	}

	/**
	 * Вводная для шага: что делать, кому и на чём стоять.
	 *
	 * Один вызов = один шаг воркера. Возвращает роль, типы артефактов, контекст
	 * мира, уже выпущенное (чтобы не повторяться), опору из базы знаний
	 * ИИ-радара (nj661) и рубрику оценки. Шаг сразу помечается взятым —
	 * steps_done++, поэтому повторный запрос даёт следующий шаг.
	 *
	 * @param  int $run_id цикл
	 * @return array       план шага либо ['error' => …]
	 */
	public static function step_input($run_id)
	{
		$db  = self::db();
		$run = $db->query(Database::SELECT, 'SELECT * FROM erp_222_runs WHERE id = '.(int) $run_id.' LIMIT 1', FALSE)->current();

		if ( ! $run)
		{
			return ['error' => 'Цикл не найден'];
		}

		$run   = (array) $run;
		$world = self::world((int) $run['world_id']);

		if ( ! $world)
		{
			return ['error' => 'Мир цикла не найден'];
		}

		$steps = max(1, (int) $run['steps_planned']);
		$index = (int) $run['steps_done'];

		if ($index >= $steps)
		{
			return ['ok' => TRUE, 'done' => TRUE, 'step' => $steps, 'steps' => $steps];
		}

		$roles = self::world_roles((int) $world['id']);

		if ( ! $roles)
		{
			self::seed_roles((int) $world['id']);
			$roles = self::world_roles((int) $world['id']);
		}

		$enabled = [];

		foreach ($roles as $role)
		{
			if ( ! empty($role['enabled']))
			{
				$enabled[] = $role;
			}
		}

		if ( ! $enabled)
		{
			return ['error' => 'У мира не включено ни одной роли'];
		}

		$role  = $enabled[$index % count($enabled)];
		$types = (array) $role['types'];

		if ( ! $types)
		{
			$types = ['spec'];
		}

		// Тип артефакта внутри роли чередуется по кругу: у Архитектора — сначала
		// спецификация, потом схема данных и снова спецификация (уже с учётом
		// выпущенного). Так цикл из 6 шагов не даёт трёх одинаковых документов.
		$type = (string) $types[$index % count($types)];

		$prev = self::artifacts_of((int) $world['id'], 0, (int) Arr::get((array) self::config('limits', []), 'artifacts_context', 12));

		// Критик работает не «в воздухе»: ему отдаём самый ценный артефакт мира,
		// который ещё не разобран — тогда замечания касаются реальной работы.
		$target = NULL;

		if ((string) $role['code'] === 'critic')
		{
			$target = self::artifact_for_critique((int) $world['id']);
		}

		$limits = (array) self::config('limits', []);

		$plan = [
			'ok'    => TRUE,
			'run_id' => (int) $run['id'],
			'step'  => $index + 1,
			'steps' => $steps,
			'role'  => [
				'code'  => (string) $role['code'],
				'title' => (string) $role['title'],
				'focus' => (string) $role['focus'],
				'types' => $types,
			],
			'type'        => $type,
			'types_allowed' => array_keys(self::types()),
			'world'       => [
				'id'       => (int) $world['id'],
				'code'     => (string) $world['code'],
				'title'    => (string) $world['title'],
				'brief'    => (string) $world['brief'],
				'protocol' => (string) $world['protocol'],
				'goal'     => (string) $world['goal'],
				'domain'   => (string) $world['domain'],
			],
			'existing'    => array_map(function ($a)
				{
					return [
						'id'          => (int) $a['id'],
						'type'        => (string) $a['type'],
						'title'       => (string) $a['title'],
						'summary'     => mb_substr((string) $a['summary'], 0, 240),
						'value_score' => (int) $a['value_score'],
					];
				}, $prev),
			'knowledge'   => self::knowledge_context((int) Arr::get($limits, 'knowledge_facts', 8), (int) Arr::get($limits, 'knowledge_ideas', 6)),
			'rubric'      => (array) self::config('value_weights', []),
			'type_proof'  => (string) Arr::path((array) self::types(), $type.'.proof', ''),
			'history'     => self::events((int) $run['id'], 8),
			'model'       => self::model_for((string) $world['model']),
		];

		if ($target !== NULL)
		{
			$plan['target'] = [
				'id'          => (int) $target['id'],
				'type'        => (string) $target['type'],
				'title'       => (string) $target['title'],
				'summary'     => (string) $target['summary'],
				'body'        => mb_substr((string) $target['body'], 0, 6000),
				'value_score' => (int) $target['value_score'],
				'critique'    => (string) $target['critique'],
			];
		}

		$db->query(Database::UPDATE,
			'UPDATE erp_222_runs SET steps_done = '.($index + 1).', role_code = '.$db->quote((string) $role['code']).', '
				.'stage = '.$db->quote((string) $role['code'] === 'critic' ? 'Критика' : 'Синтез').', '
				.'stage_note = '.$db->quote('шаг '.($index + 1).'/'.$steps.' · '.$role['title'].' → '.self::type_title($type)).'
			 WHERE id = '.(int) $run['id'], FALSE);

		self::event((int) $world['id'], (int) $run['id'], (string) $role['code'], 'step',
			'Шаг '.($index + 1).'/'.$steps.': '.$role['title'].' — '.self::type_title($type));

		return $plan;
	}

	/**
	 * Опора из базы знаний «ИИ-радара» (nj661): факты и идеи под тему мира.
	 *
	 * Наработки генерировали мир «из головы модели». Здесь мир стоит на реальных
	 * находках соседнего сервиса: факты и идеи отбираются по совпадению слов с
	 * задумкой мира. Идеи ИИ-радара — это буквально «что применить на n2j»,
	 * поэтому Кузница и Радар работают в паре: радар находит — кузница проектирует.
	 *
	 * Если таблиц nj661 ещё нет (модуль старше схемы), возвращаем пустой контекст —
	 * цикл работает, просто без опоры.
	 */
	public static function knowledge_context($facts_limit = 8, $ideas_limit = 6)
	{
		$db    = self::db();
		$facts = [];
		$ideas = [];

		try
		{
			$rows = $db->query(Database::SELECT,
				'SELECT id, title, content, tags FROM nj_661_facts ORDER BY last_seen DESC, id DESC LIMIT 200', FALSE)->as_array();

			foreach ($rows as $row)
			{
				$row = (array) $row;
				$facts[] = [
					'id'      => (int) $row['id'],
					'title'   => (string) $row['title'],
					'content' => mb_substr((string) $row['content'], 0, 400),
					'tags'    => (string) $row['tags'],
				];
			}
		}
		catch (Throwable $e)
		{
			$facts = [];
		}

		try
		{
			$rows = $db->query(Database::SELECT,
				"SELECT id, title, why, where_to, status FROM nj_661_ideas WHERE status IN ('new','accepted') ORDER BY score DESC, id DESC LIMIT 100", FALSE)->as_array();

			foreach ($rows as $row)
			{
				$row = (array) $row;
				$ideas[] = [
					'id'       => (int) $row['id'],
					'title'    => (string) $row['title'],
					'why'      => mb_substr((string) $row['why'], 0, 300),
					'where_to' => mb_substr((string) $row['where_to'], 0, 200),
				];
			}
		}
		catch (Throwable $e)
		{
			$ideas = [];
		}

		return [
			'facts' => array_slice($facts, 0, max(0, (int) $facts_limit)),
			'ideas' => array_slice($ideas, 0, max(0, (int) $ideas_limit)),
		];
	}

	/**
	 * Отбор опоры под конкретную задумку: слова мира ∩ слова находки.
	 *
	 * Отдельная чистая функция — стенд проверяет её без базы: пустая тема даёт
	 * «свежие вообще», совпадение слов поднимает находку наверх.
	 */
	public static function rank_knowledge($theme, array $items, $limit = 8)
	{
		$words = self::keywords($theme);

		$scored = [];

		foreach ($items as $item)
		{
			$item  = (array) $item;
			$hay   = Arr::get($item, 'title', '').' '.Arr::get($item, 'content', Arr::get($item, 'why', ''));

			$score = 0;

			if ($words)
			{
				$own = self::keywords($hay);

				$score = count(array_intersect_key($words, $own));
			}

			$scored[] = ['score' => $score, 'item' => $item];
		}

		usort($scored, function ($a, $b) { return $b['score'] - $a['score']; });

		$out = [];

		foreach (array_slice($scored, 0, max(1, (int) $limit)) as $row)
		{
			$out[] = $row['item'];
		}

		return $out;
	}

	// ==================================================================
	// Артефакты мира и события
	// ==================================================================

	/** Артефакты мира (свежие сверху) — общая выборка для контекста и страниц. */
	public static function artifacts_of($world_id, $type = '', $limit = 50)
	{
		$where = ['world_id = '.(int) $world_id];

		if ($type !== '')
		{
			$where[] = 'type = '.self::db()->quote((string) $type);
		}

		$rows = self::db()->query(Database::SELECT,
			'SELECT * FROM erp_222_artifacts WHERE '.implode(' AND ', $where).' ORDER BY id DESC LIMIT '.max(1, min(500, (int) $limit)), FALSE)->as_array();

		$out = [];

		foreach ($rows as $row)
		{
			$out[] = (array) $row;
		}

		return $out;
	}

	/**
	 * Артефакт для разбора Критиком: самый ценный из ещё не разобранных.
	 *
	 * Порядок такой: сначала те, у кого нет критики (critique = ''), среди них —
	 * самый ценный. Если всё разобрано, берём самый ценный вообще: повторный
	 * разбор полезен, когда мир сильно ушёл вперёд.
	 */
	public static function artifact_for_critique($world_id)
	{
		$row = self::db()->query(Database::SELECT,
			"SELECT * FROM erp_222_artifacts WHERE world_id = ".(int) $world_id."
			  ORDER BY (critique = '') DESC, value_score DESC, id ASC LIMIT 1", FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	/** Лента событий: по циклу или по миру (0 — всё). */
	public static function events($run_id = 0, $limit = 20, $world_id = 0)
	{
		$where = [];

		if ((int) $run_id > 0)
		{
			$where[] = 'run_id = '.(int) $run_id;
		}

		if ((int) $world_id > 0)
		{
			$where[] = 'world_id = '.(int) $world_id;
		}

		$rows = self::db()->query(Database::SELECT,
			'SELECT * FROM erp_222_events'.($where ? ' WHERE '.implode(' AND ', $where) : '')
				.' ORDER BY id DESC LIMIT '.max(1, min(200, (int) $limit)), FALSE)->as_array();

		$out = [];

		foreach ($rows as $row)
		{
			$out[] = (array) $row;
		}

		return $out;
	}

	/** Записать событие (лента цикла; не падает — журнал не важнее работы). */
	public static function event($world_id, $run_id, $role_code, $kind, $message, array $payload = [])
	{
		try
		{
			$db = self::db();

			$db->query(Database::INSERT,
				'INSERT INTO erp_222_events (world_id, run_id, role_code, kind, message, payload)
				 VALUES ('.(int) $world_id.', '.(int) $run_id.', '.$db->quote((string) $role_code).', '
					.$db->quote((string) $kind).', '.$db->quote(mb_substr(trim((string) $message), 0, 480)).', '
					.$db->quote(json_encode($payload, JSON_UNESCAPED_UNICODE)).')', FALSE);
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::ERROR, 'erp222: событие не записалось: :e', [':e' => $e->getMessage()]);
		}
	}

	/** Обновить счётчики мира после шага (артефакты, ценность, обновлён). */
	public static function touch_world($world_id)
	{
		$db = self::db();

		$agg = $db->query(Database::SELECT,
			'SELECT count(*) AS c, COALESCE(sum(value_score), 0) AS v FROM erp_222_artifacts WHERE world_id = '.(int) $world_id, FALSE)->current();

		$db->query(Database::UPDATE,
			'UPDATE erp_222_worlds SET artifacts_done = '.(int) ($agg ? $agg['c'] : 0).', '
				.'value_total = '.(int) ($agg ? $agg['v'] : 0).', updated_at = now() WHERE id = '.(int) $world_id, FALSE);
	}

	/** Сводка дашборда: мир, живой цикл, счётчики, свежие артефакты. */
	public static function overview()
	{
		$run   = self::active_run();
		$worlds = self::worlds(6);

		$world = NULL;

		if ($run)
		{
			$world = self::world((int) $run['world_id']);
		}
		elseif ($worlds)
		{
			$world = $worlds[0];
		}

		return [
			'world'    => $world,
			'worlds'   => $worlds,
			'run'      => $run,
			'counts'   => self::counts(),
			'latest'   => self::latest_artifacts(6),
			'events'   => self::events(0, 12),
		];
	}

	/** Счётчики сервиса: миры, артефакты, ценность, доля разобранных Критиком. */
	public static function counts()
	{
		$row = self::db()->query(Database::SELECT,
			"SELECT
				(SELECT count(*) FROM erp_222_worlds) AS worlds,
				(SELECT count(*) FROM erp_222_worlds WHERE status = 'active') AS worlds_active,
				(SELECT count(*) FROM erp_222_artifacts) AS artifacts,
				(SELECT COALESCE(sum(value_score), 0) FROM erp_222_artifacts) AS value_total,
				(SELECT COALESCE(round(avg(value_score)), 0) FROM erp_222_artifacts) AS value_avg,
				(SELECT count(*) FROM erp_222_artifacts WHERE critique <> '') AS critiqued,
				(SELECT count(*) FROM erp_222_artifacts WHERE published) AS published", FALSE)->current();

		$row = $row ? (array) $row : [];

		return [
			'worlds'        => (int) Arr::get($row, 'worlds', 0),
			'worlds_active' => (int) Arr::get($row, 'worlds_active', 0),
			'artifacts'     => (int) Arr::get($row, 'artifacts', 0),
			'value_total'   => (int) Arr::get($row, 'value_total', 0),
			'value_avg'     => (int) Arr::get($row, 'value_avg', 0),
			'critiqued'     => (int) Arr::get($row, 'critiqued', 0),
			'published'     => (int) Arr::get($row, 'published', 0),
		];
	}

	/** Свежие артефакты всех миров — для дашборда. */
	public static function latest_artifacts($limit = 6)
	{
		return self::search_artifacts('', '', 1, max(1, (int) $limit), TRUE);
	}

	// ==================================================================
	// Каталог артефактов (публичный: люди и поисковики)
	// ==================================================================

	/**
	 * Поиск по каталогу артефактов.
	 *
	 * @param  string $q       поиск по заголовку, сводке и телу (ILIKE, метасимволы глушим)
	 * @param  string $type    фильтр типа ('' — все)
	 * @param  int    $page    страница с 1
	 * @param  int    $per     строк на странице
	 * @param  bool   $any     TRUE — и снятые с публикации (внутренние списки)
	 * @return array           строки с разобранными json-полями
	 */
	public static function search_artifacts($q = '', $type = '', $page = 1, $per = 10, $any = FALSE)
	{
		$db    = self::db();
		$where = [];

		if ( ! $any)
		{
			$where[] = 'a.published IS TRUE';
		}

		$q = trim((string) $q);

		if ($q !== '')
		{
			$like = $db->quote('%'.str_replace(['%', '_', '\\'], ' ', $q).'%');
			$where[] = '(a.title ILIKE '.$like.' OR a.summary ILIKE '.$like.' OR a.body ILIKE '.$like.')';
		}

		$type = trim((string) $type);

		if ($type !== '' AND isset(self::types()[$type]))
		{
			$where[] = 'a.type = '.$db->quote($type);
		}

		$per    = max(5, min(50, (int) $per));
		$offset = max(0, ((int) $page - 1) * $per);

		$sql = 'SELECT a.*, w.title AS world_title, w.code AS world_code
			      FROM erp_222_artifacts a
			      LEFT JOIN erp_222_worlds w ON w.id = a.world_id'
			.($where ? ' WHERE '.implode(' AND ', $where) : '')
			.' ORDER BY a.value_score DESC, a.id DESC LIMIT '.$per.' OFFSET '.$offset;

		$rows = $db->query(Database::SELECT, $sql, FALSE)->as_array();

		$out = [];

		foreach ($rows as $row)
		{
			$out[] = self::decorate((array) $row);
		}

		return $out;
	}

	/** Сколько артефактов в каталоге (с учётом поиска и типа). */
	public static function count_artifacts($q = '', $type = '', $any = FALSE)
	{
		$db    = self::db();
		$where = [];

		if ( ! $any)
		{
			$where[] = 'published IS TRUE';
		}

		$q = trim((string) $q);

		if ($q !== '')
		{
			$like = $db->quote('%'.str_replace(['%', '_', '\\'], ' ', $q).'%');
			$where[] = '(title ILIKE '.$like.' OR summary ILIKE '.$like.' OR body ILIKE '.$like.')';
		}

		$type = trim((string) $type);

		if ($type !== '' AND isset(self::types()[$type]))
		{
			$where[] = 'type = '.$db->quote($type);
		}

		$row = $db->query(Database::SELECT,
			'SELECT count(*) AS c FROM erp_222_artifacts'.($where ? ' WHERE '.implode(' AND ', $where) : ''), FALSE)->current();

		return (int) ($row ? $row['c'] : 0);
	}

	/**
	 * Артефакт по id: json-поля разобраны, добавлены названия мира, роли и типа.
	 *
	 * @param  int        $id  идентификатор
	 * @param  bool       $any TRUE — отдать и снятый с публикации (владельцу)
	 * @return array|NULL      артефакт или NULL
	 */
	public static function artifact($id, $any = FALSE)
	{
		$row = self::db()->query(Database::SELECT,
			'SELECT a.*, w.title AS world_title, w.code AS world_code, w.goal AS world_goal
			   FROM erp_222_artifacts a LEFT JOIN erp_222_worlds w ON w.id = a.world_id
			  WHERE a.id = '.(int) $id.' LIMIT 1', FALSE)->current();

		if ( ! $row)
		{
			return NULL;
		}

		$artifact = self::decorate((array) $row);

		if ( ! $artifact['published'] AND ! $any)
		{
			return NULL;
		}

		return $artifact;
	}

	/** Разобрать json-поля и добавить человеческие подписи. */
	public static function decorate(array $row)
	{
		foreach (['metadata', 'scores'] as $key)
		{
			$value = Arr::get($row, $key, '');
			$row[$key] = is_array($value) ? $value : (array) json_decode((string) $value, TRUE);
		}

		foreach (['depends_on', 'sources'] as $key)
		{
			$value = Arr::get($row, $key, '');
			$decoded = is_array($value) ? $value : json_decode((string) $value, TRUE);
			$row[$key] = is_array($decoded) ? $decoded : [];
		}

		$row['published']  = self::as_bool(Arr::get($row, 'published', TRUE));
		$row['type_title'] = self::type_title((string) Arr::get($row, 'type', ''));
		$row['role_title'] = self::role_title((string) Arr::get($row, 'role_code', ''));
		$row['score_word'] = self::value_word((int) Arr::get($row, 'value_score', 0));

		return $row;
	}

	/** Слово о ценности артефакта: каталог не должен показывать «73» без смысла. */
	public static function value_word($score)
	{
		$score = (int) $score;

		if ($score >= 85)
		{
			return 'брать в работу';
		}

		if ($score >= 70)
		{
			return 'полезно';
		}

		if ($score >= 50)
		{
			return 'на доработку';
		}

		return 'черновик';
	}

	/** PostgreSQL отдаёт BOOLEAN строками: (bool)'f' в PHP — TRUE, поэтому явно. */
	public static function as_bool($value)
	{
		return $value === TRUE OR $value === 't' OR $value === 'true' OR $value === 1 OR $value === '1';
	}

	/** Снять артефакт с публикации или вернуть в каталог. */
	public static function set_published($artifact_id, $published)
	{
		$db = self::db();

		$db->query(Database::UPDATE,
			'UPDATE erp_222_artifacts SET published = '.($published ? 'TRUE' : 'FALSE')
				.' WHERE id = '.(int) $artifact_id, FALSE);

		$artifact = self::artifact($artifact_id, TRUE);

		if ($artifact)
		{
			self::event((int) $artifact['world_id'], 0, '', 'note',
				($published ? 'Артефакт #'.$artifact_id.' возвращён в каталог' : 'Артефакт #'.$artifact_id.' снят с публикации'));
		}

		return TRUE;
	}

	/**
	 * id опубликованных артефактов — для карты сайта.
	 *
	 * Только артефакты достаточной ценности: страницы-черновики в индексе не
	 * нужны ни людям, ни поисковикам.
	 */
	public static function public_ids($limit = 200, $min_score = 50)
	{
		$rows = self::db()->query(Database::SELECT,
			'SELECT id FROM erp_222_artifacts WHERE published IS TRUE AND value_score >= '.(int) $min_score.'
			  ORDER BY value_score DESC, id DESC LIMIT '.max(1, min(500, (int) $limit)), FALSE)->as_array();

		$ids = [];

		foreach ($rows as $row)
		{
			$ids[] = (int) ((array) $row)['id'];
		}

		return $ids;
	}

	/** Свежие артефакты для дайджеста на дашборде (без поиска). */
	public static function digest_artifacts($limit = 5)
	{
		return self::search_artifacts('', '', 1, max(1, (int) $limit), FALSE);
	}
}
