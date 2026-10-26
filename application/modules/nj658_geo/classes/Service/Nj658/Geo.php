<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-658 «Города и посёлки»: справочник населённых пунктов для всей платформы.
 *
 * Как пользоваться из других модулей (ла конично, без своей таблицы городов):
 *
 *   Service_Nj658_Geo::search('моск', 10);        // подсказки для формы
 *   Service_Nj658_Geo::city('moskva');            // по slug (или id)
 *   Service_Nj658_Geo::regions();                 // регионы справочника
 *   Service_Nj658_Geo::by_region('Красноярский край', 50);
 *   Service_Nj658_Geo::label($row);               // «Красноярск · край»
 *
 * Поиск нормализован: нижний регистр, ё→е; латиница ищет по name_latin.
 * Справочник правится на /geo/admin (сид — из source_data/0_loc_rus.sql,
 * 2020 записей; правки не теряются при переезде: сид идемпотентен по slug).
 *
 * Чистые методы (normalize_query, slugify, label, csv) покрыты
 * tests/Unit/GeoServiceTest.php и стендом .tools/nj658_geo_stand.php.
 */
class Service_Nj658_Geo
{
	// ------------------------------------------------------------------
	// Конфиг
	// ------------------------------------------------------------------

	/** Конфиг модуля как массив. */
	public static function config()
	{
		$config = Kohana::$config->load('nj658_geo');

		return $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;
	}

	/** Белый список видов населённых пунктов. */
	public static function kinds()
	{
		return (array) Arr::get(self::config(), 'kinds', []);
	}

	// ------------------------------------------------------------------
	// Чистые функции (без БД)
	// ------------------------------------------------------------------

	/**
	 * Нормализация поискового запроса: нижний регистр, ё→е, без лишних
	 * пробелов; спецсимволы LIKE экранируются в _like().
	 */
	public static function normalize_query($query)
	{
		$query = mb_strtolower(trim((string) $query));
		$query = str_replace('ё', 'е', $query);
		$query = preg_replace('/\s+/u', ' ', $query);

		return mb_substr(trim((string) $query), 0, 64);
	}

	/** Экранирование спецсимволов LIKE (% и _). */
	public static function _like($query)
	{
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $query);
	}

	/**
	 * Транслитерация названия в slug («Звериноголовское» → zverinogolovskoe).
	 * Для городов, добавленных руками: в сиде слоги уже из исходного дампа.
	 */
	public static function slugify($title)
	{
		$map = [
			'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
			'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
			'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts',
			'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
		];

		$slug = mb_strtolower(trim((string) $title));
		$slug = strtr($slug, $map);
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
		$slug = trim((string) $slug, '-');

		return mb_substr($slug, 0, 60);
	}

	/** Нормализованное значение title_norm для записи в БД. */
	public static function title_norm($title)
	{
		return mb_substr(str_replace('ё', 'е', mb_strtolower(trim((string) $title))), 0, 100);
	}

	/**
	 * Человекочитаемая подпись города для списков и подсказок:
	 * «Красноярск» или «Абан · Красноярский край» (регион добавляется,
	 * когда он не повторяет название — «Москва · Москва» не нужно).
	 */
	public static function label(array $row)
	{
		$title = (string) Arr::get($row, 'title', '');
		$region = (string) Arr::get($row, 'region', '');

		if ($region === '' OR mb_strtolower($region) === mb_strtolower($title)
			OR mb_strpos(mb_strtolower($region), mb_strtolower($title)) === 0)
		{
			return $title;
		}

		return $title.' · '.$region;
	}

	/**
	 * CSV-выгрузка справочника (чистая: строки → текст).
	 *
	 * @param   array  $rows  строки nj_658_cities
	 * @param   string $glue  разделитель строк
	 * @return  string
	 */
	public static function csv(array $rows, $glue = "\r\n")
	{
		$out = ['id;slug;title;title_full;name_latin;region;district;kind;population;lat;lon'];

		foreach ($rows as $row)
		{
			$fields = [
				'id' => (string) Arr::get($row, 'id', ''),
				'slug' => (string) Arr::get($row, 'slug', ''),
				'title' => (string) Arr::get($row, 'title', ''),
				'title_full' => (string) Arr::get($row, 'title_full', ''),
				'name_latin' => (string) Arr::get($row, 'name_latin', ''),
				'region' => (string) Arr::get($row, 'region', ''),
				'district' => (string) Arr::get($row, 'district', ''),
				'kind' => (string) Arr::get($row, 'kind', ''),
				'population' => (string) Arr::get($row, 'population', '0'),
				'lat' => (string) Arr::get($row, 'lat', '0'),
				'lon' => (string) Arr::get($row, 'lon', '0'),
			];

			$out[] = implode(';', array_map(function ($value) {
				return str_replace(';', ',', (string) $value);
			}, $fields));
		}

		return implode($glue, $out).$glue;
	}

	// ------------------------------------------------------------------
	// БД
	// ------------------------------------------------------------------

	protected static function _db()
	{
		static $db = NULL;

		return $db !== NULL ? $db : $db = Database::instance();
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
		return self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();
	}

	protected static function _exec($type, $sql)
	{
		return self::_db()->query($type, $sql, FALSE);
	}

	/** Колонки, которые читаем из справочника (везде одинаковый набор). */
	const FIELDS = 'id, slug, title, title_full, name_latin, region, district, kind, population, is_dup, lat, lon, is_active, source';

	/**
	 * Подсказки по началу названия (для автодополнения в формах).
	 *
	 * Порядок: по населению (крупные города первыми), затем по алфавиту.
	 * Если по началу названия мало результатов и запрос длинный — ищем
	 * и по вхождению в середину.
	 *
	 * @param  string $query запрос (можно с «ё», любого регистра)
	 * @param  int    $limit максимум записей (1..25)
	 * @return array
	 */
	public static function search($query, $limit = 10)
	{
		$config = (array) Arr::get(self::config(), 'suggest', []);
		$limit = min(max(1, (int) $limit), (int) Arr::get($config, 'limit_max', 25));
		$q = self::normalize_query($query);

		if ($q === '')
		{
			return [];
		}

		$like = self::_like($q);
		$rows = self::_all('SELECT '.self::FIELDS.' FROM nj_658_cities
			WHERE is_active AND (title_norm LIKE '.self::_q($like.'%').' OR name_latin LIKE '.self::_q($like.'%').")
			ORDER BY population DESC, title
			LIMIT ".$limit);

		if (count($rows) < $limit AND mb_strlen($q) >= 3)
		{
			$extra = self::_all('SELECT '.self::FIELDS.' FROM nj_658_cities
				WHERE is_active
					AND (title_norm LIKE '.self::_q('%'.$like.'%').' OR name_latin LIKE '.self::_q('%'.$like.'%').')
					AND id NOT IN ('.implode(',', array_map(function ($r) { return (int) $r['id']; }, $rows ?: [['id' => 0]])).")
				ORDER BY population DESC, title
				LIMIT ".((int) $limit - count($rows)));

			$rows = array_merge($rows, $extra);
		}

		return $rows;
	}

	/**
	 * Населённый пункт по slug («moskva») или id. NULL, если не найден.
	 */
	public static function city($ident)
	{
		if (is_int($ident) OR ctype_digit((string) $ident))
		{
			return self::_one('SELECT '.self::FIELDS.' FROM nj_658_cities WHERE id = '.(int) $ident.' LIMIT 1');
		}

		$slug = trim((string) $ident);

		if ($slug === '')
		{
			return NULL;
		}

		return self::_one('SELECT '.self::FIELDS.' FROM nj_658_cities WHERE slug = '.self::_q($slug).' LIMIT 1');
	}

	/** по id (админка). */
	public static function city_by_id($id)
	{
		$id = (int) $id;

		return $id > 0 ? self::_one('SELECT '.self::FIELDS.' FROM nj_658_cities WHERE id = '.$id.' LIMIT 1') : NULL;
	}

	/**
	 * Регионы справочника: ['title' => …, 'cities' => N], по алфавиту.
	 */
	public static function regions()
	{
		return self::_all('SELECT region AS title, count(*) AS cities
			FROM nj_658_cities
			WHERE is_active AND region <> \'\'
			GROUP BY region
			ORDER BY region');
	}

	/**
	 * Населённые пункты региона (по населению).
	 */
	public static function by_region($region, $limit = 100)
	{
		$region = trim((string) $region);

		if ($region === '')
		{
			return [];
		}

		return self::_all('SELECT '.self::FIELDS.' FROM nj_658_cities
			WHERE is_active AND region = '.self::_q($region).'
			ORDER BY population DESC, title
			LIMIT '.max(1, min(1000, (int) $limit)));
	}

	/** Крупнейшие населённые пункты (для пустой строки поиска). */
	public static function top($limit = 20)
	{
		return self::_all('SELECT '.self::FIELDS.' FROM nj_658_cities
			WHERE is_active
			ORDER BY population DESC, title
			LIMIT '.max(1, min(200, (int) $limit)));
	}

	/** Статистика для админки. */
	public static function stats()
	{
		return self::_one('SELECT count(*) AS total,
				count(*) FILTER (WHERE is_active) AS active,
				count(*) FILTER (WHERE source = \'manual\') AS manual,
				count(*) FILTER (WHERE kind = \'город\') AS cities,
				count(DISTINCT region) AS regions
			FROM nj_658_cities') ?: [];
	}

	/**
	 * Список для публичной страницы: поиск по названию или фильтр по региону.
	 *
	 * @return array ['rows', 'total', 'page', 'pages']
	 */
	public static function listing($query, $region, $page)
	{
		$per_page = (int) Arr::get((array) Arr::get(self::config(), 'public', []), 'page_items', 50);
		$where = ['is_active'];
		$params = [];

		$q = self::normalize_query($query);

		if ($q !== '')
		{
			$like = self::_like($q);
			$where[] = '(title_norm LIKE '.self::_q($like.'%').' OR name_latin LIKE '.self::_q($like.'%').')';
		}

		$region = trim((string) $region);

		if ($region !== '')
		{
			$where[] = 'region = '.self::_q($region);
		}

		$where_sql = implode(' AND ', $where);

		$total_row = self::_one('SELECT count(*) AS c FROM nj_658_cities WHERE '.$where_sql);
		$total = (int) ($total_row['c'] ?? 0);
		$pages = max(1, (int) ceil($total / $per_page));
		$page = min(max(1, (int) $page), $pages);
		$offset = ($page - 1) * $per_page;

		$rows = self::_all('SELECT '.self::FIELDS.' FROM nj_658_cities
			WHERE '.$where_sql.'
			ORDER BY population DESC, title
			LIMIT '.$per_page.' OFFSET '.$offset);

		return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $per_page];
	}

	/**
	 * Список для админки: поиск (по названию/региону), все записи (и выключенные).
	 *
	 * @return array ['rows', 'total', 'page', 'pages']
	 */
	public static function admin_list($query, $page)
	{
		$per_page = (int) Arr::get((array) Arr::get(self::config(), 'admin', []), 'page_items', 30);
		$where = ['1 = 1'];

		$q = self::normalize_query($query);

		if ($q !== '')
		{
			$like = self::_like($q);
			$where[] = '(title_norm LIKE '.self::_q('%'.$like.'%').' OR region LIKE '.self::_q('%'.$like.'%').' OR slug LIKE '.self::_q('%'.$like.'%').')';
		}

		$where_sql = implode(' AND ', $where);

		$total_row = self::_one('SELECT count(*) AS c FROM nj_658_cities WHERE '.$where_sql);
		$total = (int) ($total_row['c'] ?? 0);
		$pages = max(1, (int) ceil($total / $per_page));
		$page = min(max(1, (int) $page), $pages);
		$offset = ($page - 1) * $per_page;

		$rows = self::_all('SELECT '.self::FIELDS.' FROM nj_658_cities
			WHERE '.$where_sql.'
			ORDER BY population DESC, title
			LIMIT '.$per_page.' OFFSET '.$offset);

		return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $per_page];
	}

	// ------------------------------------------------------------------
	// Админ: сохранение
	// ------------------------------------------------------------------

	/**
	 * Создание/правка населённого пункта из формы админки.
	 *
	 * @param   array $post $_POST (id пуст — создание)
	 * @return  array ['ok', 'error', 'errors' => [], 'id']
	 */
	public static function save_city(array $post)
	{
		$config = self::config();
		$limits = (array) Arr::get($config, 'limits', []);
		$errors = [];

		$id = (int) Arr::get($post, 'id', 0);
		$exists = $id > 0 ? self::city_by_id($id) : NULL;

		if ($id > 0 AND $exists === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Запись не найдена', 'errors' => ['Запись не найдена'], 'id' => 0];
		}

		$title = trim((string) Arr::get($post, 'title', ''));
		if ($title === '' OR mb_strlen($title) > (int) Arr::get($limits, 'title_max', 100))
		{
			$errors[] = 'Название: 1–'.Arr::get($limits, 'title_max', 100).' символов.';
		}

		$slug = trim((string) Arr::get($post, 'slug', ''));
		if ($slug === '')
		{
			$slug = self::slugify($title);
		}

		if ($slug === '' OR mb_strlen($slug) > (int) Arr::get($limits, 'slug_max', 60) OR preg_match('/^[a-z0-9-]+$/', $slug) !== 1)
		{
			$errors[] = 'Slug: латиница, цифры и дефисы, до '.Arr::get($limits, 'slug_max', 60).' символов.';
		}

		$name_latin = trim((string) Arr::get($post, 'name_latin', ''));
		if ($name_latin === '')
		{
			$name_latin = $slug;
		}

		if (mb_strlen($name_latin) > (int) Arr::get($limits, 'latin_max', 60))
		{
			$errors[] = 'Латинское название: до '.Arr::get($limits, 'latin_max', 60).' символов.';
		}

		$region = trim((string) Arr::get($post, 'region', ''));
		$district = trim((string) Arr::get($post, 'district', ''));
		$title_full = trim((string) Arr::get($post, 'title_full', ''));

		if (mb_strlen($region) > (int) Arr::get($limits, 'region_max', 120))
		{
			$errors[] = 'Регион: до '.Arr::get($limits, 'region_max', 120).' символов.';
		}

		if (mb_strlen($district) > (int) Arr::get($limits, 'district_max', 140))
		{
			$errors[] = 'Район: до '.Arr::get($limits, 'district_max', 140).' символов.';
		}

		$kind = trim((string) Arr::get($post, 'kind', ''));

		if ($kind !== '' AND ! in_array($kind, self::kinds(), TRUE))
		{
			$errors[] = 'Вид населённого пункта: выберите из списка или оставьте пустым.';
		}

		$population = (int) Arr::get($post, 'population', 0);
		if ($population < 0 OR $population > (int) Arr::get($limits, 'population_max', 50000000))
		{
			$errors[] = 'Население: целое от 0 до '.Arr::get($limits, 'population_max', 50000000).'.';
		}

		$lat = str_replace(',', '.', (string) Arr::get($post, 'lat', '0'));
		$lon = str_replace(',', '.', (string) Arr::get($post, 'lon', '0'));

		foreach (['Широта' => $lat, 'Долгота' => $lon] as $label => $coord)
		{
			if ($coord === '' OR ! is_numeric($coord) OR abs((float) $coord) > 180)
			{
				$errors[] = $label.': число от −180 до 180.';
			}
		}

		// Уникальность slug (кроме себя).
		if ($slug !== '')
		{
			$dup = self::_one('SELECT id FROM nj_658_cities WHERE slug = '.self::_q($slug)
				.($exists !== NULL ? ' AND id <> '.(int) $exists['id'] : ''));

			if ($dup !== NULL)
			{
				$errors[] = 'Такой slug уже занят другим населённым пунктом.';
			}
		}

		if ($errors)
		{
			return ['ok' => FALSE, 'error' => $errors[0], 'errors' => $errors, 'id' => $id];
		}

		$values = [
			'slug' => $slug,
			'title' => $title,
			'title_norm' => self::title_norm($title),
			'title_full' => $title_full !== '' ? $title_full : $title,
			'name_latin' => $name_latin,
			'region' => $region,
			'district' => $district,
			'kind' => $kind,
			'population' => $population,
			'is_dup' => Arr::get($post, 'is_dup') !== NULL,
			'lat' => $lat,
			'lon' => $lon,
			'is_active' => Arr::get($post, 'is_active') !== NULL,
		];

		try
		{
			if ($exists !== NULL)
			{
				$sets = ['updated_at = now()'];

				foreach ($values as $field => $value)
				{
					$sets[] = '"'.$field.'" = '.self::_q($value);
				}

				self::_exec(Database::UPDATE, 'UPDATE nj_658_cities SET '.implode(', ', $sets)
					.' WHERE id = '.(int) $exists['id']);
				$saved_id = (int) $exists['id'];
			}
			else
			{
				$values['source'] = 'manual';

				$columns = implode(', ', array_map(function ($f) { return '"'.$f.'"'; }, array_keys($values)));
				$placeholders = implode(', ', array_map(function ($v) { return self::_q($v); }, array_values($values)));

				self::_exec(Database::INSERT, 'INSERT INTO nj_658_cities ('.$columns.') VALUES ('.$placeholders.')');

				$saved_id = (int) self::_one('SELECT id FROM nj_658_cities WHERE slug = '.self::_q($slug).' LIMIT 1')['id'];
			}
		}
		catch (Throwable $e)
		{
			Log_Plain::event(Log::ERROR, 'geo: населённый пункт :t не сохранён: :e',
				[':t' => $title, ':e' => mb_substr($e->getMessage(), 0, 300)]);

			return ['ok' => FALSE, 'error' => 'Ошибка базы: '.$e->getMessage(), 'errors' => ['Ошибка базы: '.$e->getMessage()], 'id' => $id];
		}

		if (class_exists('Log_Plain'))
		{
			Log_Plain::event(Log::INFO, 'geo: населённый пункт :t сохранён (:s)', [':t' => $title, ':s' => $slug]);
		}

		return ['ok' => TRUE, 'error' => '', 'errors' => [], 'id' => $saved_id];
	}

	/** Удаление записи (сидовые вернутся только при пересеве миграции). */
	public static function delete_city($id)
	{
		$city = self::city_by_id($id);

		if ($city === NULL)
		{
			return FALSE;
		}

		self::_exec(Database::DELETE, 'DELETE FROM nj_658_cities WHERE id = '.(int) $city['id']);

		if (class_exists('Log_Plain'))
		{
			Log_Plain::event(Log::INFO, 'geo: населённый пункт :t удалён', [':t' => $city['title']]);
		}

		return TRUE;
	}

	/** Все записи (для CSV-экспорта). */
	public static function all_for_export()
	{
		return self::_all('SELECT '.self::FIELDS.' FROM nj_658_cities ORDER BY region, title');
	}
}
