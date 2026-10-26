<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Файлы «Графового 3D-конструктора» на диске: отчёт и очистка (владелец, 14.10.2026).
 *
 * Всё бинарное лежит в storage/nj667/<code>/, в базе только путь (ОТНОСИТЕЛЬНО
 * storage/). Виды файлов:
 *   - photo — кадры проекта NN.jpg: обычная порционная очистка их сохраняет;
 *   - build — build-<id>.scad|stl|glb: сборка изделия;
 *   - part  — build-<id>-part-<i>.scad|stl|png: узлы к печати (png — превью);
 *   - card  — build-<id>-card-160.png: карточка опубликованной сборки (160 px);
 *   - other — всё остальное: порционная очистка их не удаляет.
 *
 * Полная очистка может удалить любые непубликованные файлы, включая кадры,
 * последние сборки и неизвестные файлы; опубликованные ресурсы защищены списком.
 *
 * Категории отчёта:
 *   - keep    — в работе: кадры живых проектов, последние сборки, свежие файлы;
 *   - stale   — устаревшие сборки: не последняя и не последняя готовая у проекта,
 *               старше stale_days. Строка сборки остаётся, статус → «spec»;
 *   - orphan  — сироты: файлы без строки в базе старше orphan_min_age секунд
 *               (свежие могут ещё писаться) и каталоги без проекта;
 *   - deleted — проект помечен удалённым (мягко): все его файлы. Необратимо:
 *               восстановить проект из мягкого удаления нельзя.
 *
 * Порционная очистка доступна модератору (storage.batch), крупные файлы первыми.
 * Полная очистка имеет отдельный строгий allowlist опубликованных ресурсов.
 */
class Service_Nj667_Storage
{
	/** Категории, которые можно очистить. */
	const CLEANABLE = ['stale', 'orphan', 'deleted'];

	/** Порядок и подписи категорий для отчёта. */
	public static function cats()
	{
		return [
			'keep' => [
				'title' => 'В работе',
				'rule'  => 'Кадры живых проектов, последние сборки и свежие файлы. Не трогаем.',
			],
			'published' => [
				'title' => 'Опубликованные сборки',
				'rule'  => 'Опубликованные сборки и детали. Список скрыт по умолчанию; обычная очистка их пропускает.',
			],
			'stale' => [
				'title' => 'Устаревшие сборки',
				'rule'  => 'Не последняя и не последняя готовая сборка проекта, старше порога. STL, GLB и узлы уходят; сборка остаётся в статусе «спецификация».',
			],
			'orphan' => [
				'title' => 'Сироты',
				'rule'  => 'Файлы без строки в базе (каталог без проекта, сборка без записи), старше часа.',
			],
			'deleted' => [
				'title' => 'Удалённые проекты',
				'rule'  => 'Все файлы проектов, помеченных удалёнными. Восстановить нельзя.',
			],
			'other' => [
				'title' => 'Прочее',
				'rule'  => 'Файлы с неизвестным именем. Очистка по категориям их не трогает.',
			],
		];
	}

	/** Пороги из конфига ttl-подобного блока `storage`. */
	public static function cfg()
	{
		$c = (array) Arr::get(Service_Nj667_Graph3d::config(), 'storage', []);

		return [
			'stale_days'     => max(1, (int) Arr::get($c, 'stale_days', 30)),
			'batch'          => max(10, min(5000, (int) Arr::get($c, 'batch', 500))),
			'orphan_min_age' => max(60, (int) Arr::get($c, 'orphan_min_age', 3600)),
		];
	}

	/** Корень каталога файлов модуля или '' (нет APPPATH или каталога). */
	public static function root()
	{
		if ( ! defined('APPPATH'))
		{
			return '';
		}

		$base = realpath(APPPATH.'../storage');

		if ($base === FALSE)
		{
			return '';
		}

		$dir  = $base.DIRECTORY_SEPARATOR.'nj667';
		$real = realpath($dir);

		// Символическая ссылка не должна перенаправить очистку за пределы storage.
		if ($real === FALSE OR strpos($real, $base.DIRECTORY_SEPARATOR) !== 0)
		{
			return '';
		}

		return is_dir($real) ? $real : '';
	}

	/**
	 * Все файлы каталога: rel («nj667/<code>/<name>»), code, name, bytes, mtime, abs.
	 *
	 * @return array[]
	 */
	public static function scan()
	{
		$root = self::root();
		$out  = [];

		if ($root === '')
		{
			return $out;
		}

		foreach ((array) @scandir($root) as $code)
		{
			$dir = $root.DIRECTORY_SEPARATOR.$code;

			if ($code === '' OR $code[0] === '.' OR is_link($dir) OR ! is_dir($dir))
			{
				continue;
			}

			foreach ((array) @scandir($dir) as $name)
			{
				$abs = $dir.DIRECTORY_SEPARATOR.$name;

				if ($name === '' OR $name[0] === '.' OR is_link($abs) OR ! is_file($abs))
				{
					continue;
				}

				$out[] = [
					'rel'   => 'nj667/'.$code.'/'.$name,
					'code'  => $code,
					'name'  => $name,
					'bytes' => (int) @filesize($abs),
					'mtime' => (int) @filemtime($abs),
					'abs'   => $abs,
				];
			}
		}

		return $out;
	}

	/**
	 * Чистая классификация (без диска и без базы): проверяется стендом.
	 *
	 * @param array $files    scan()
	 * @param array $projects code => ['deleted' => 0|1]
	 * @param array $builds   id => ['code' => code, 'status' => ..., 'ts' => unix|0]
	 * @param int   $now      unix-время
	 * @param array $cfg      cfg()
	 * @return array ['items' => [...], 'cats' => [cat => ['files', 'bytes']], 'projects' => [code => bytes]]
	 */
	public static function plan(array $files, array $projects, array $builds, $now, array $cfg)
	{
		// Последняя сборка проекта и последняя готовая: их не трогаем никогда.
		$latest      = [];
		$latest_done = [];

		foreach ($builds as $id => $b)
		{
			$code = (string) $b['code'];

			if ( ! isset($latest[$code]) OR $id > $latest[$code])
			{
				$latest[$code] = $id;
			}

			if (in_array($b['status'], ['done', 'published'], TRUE) AND ( ! isset($latest_done[$code]) OR $id > $latest_done[$code]))
			{
				$latest_done[$code] = $id;
			}
		}

		$stale_before = $now - $cfg['stale_days'] * 86400;
		$items        = [];
		$cats         = [];
		$per_project  = [];

		foreach ($files as $f)
		{
			$code = (string) $f['code'];
			$name = (string) $f['name'];
			$it   = $f + ['kind' => 'other', 'build' => 0, 'part' => -1, 'ext' => '', 'cat' => 'other', 'published' => FALSE];
			$it['ext'] = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

			if (preg_match('~^[0-9]{2}\.(jpe?g|png|webp|gif)$~i', $name))
			{
				$it['kind'] = 'photo';
			}
			elseif (preg_match('~^build-([0-9]+)\.(scad|stl|glb)$~', $name, $m))
			{
				$it['kind']  = 'build';
				$it['build'] = (int) $m[1];
				$it['ext']   = $m[2];
			}
			elseif (preg_match('~^build-([0-9]+)-card(-[0-9a-z]+)?\.png$~', $name, $m))
			{
				// карточка 128 px для витрин: живёт вместе со сборкой
				$it['kind']  = 'build';
				$it['build'] = (int) $m[1];
				$it['ext']   = 'png';
			}
			elseif (preg_match('~^build-([0-9]+)-part-([0-9]+)\.(scad|stl|png)$~', $name, $m))
			{
				$it['kind']  = 'part';
				$it['build'] = (int) $m[1];
				$it['part']  = (int) $m[2];
				$it['ext']   = $m[3];
			}

			$age_ok = ($now - (int) $f['mtime']) >= $cfg['orphan_min_age'];

			if ($it['kind'] === 'other')
			{
				$it['cat'] = 'other';
			}
			elseif (isset($projects[$code]) AND (int) $projects[$code]['deleted'] === 1)
			{
				$it['cat'] = 'deleted';
			}
			elseif ($it['kind'] !== 'photo' AND isset($builds[$it['build']])
				AND ! empty($builds[$it['build']]['published'])
				AND in_array((string) Arr::get($builds[$it['build']], 'code', ''), [$code, ''], TRUE))
			{
				// Даже без строки проекта опубликованный ресурс считаем защищённым.
				$it['cat']       = 'published';
				$it['published'] = TRUE;
			}
			elseif ( ! isset($projects[$code]))
			{
				// Каталог без проекта: свежее — может ещё писаться.
				$it['cat'] = $age_ok ? 'orphan' : 'keep';
			}
			elseif ($it['kind'] === 'photo')
			{
				$it['cat'] = 'keep';
			}
			elseif (isset($builds[$it['build']]) AND (string) $builds[$it['build']]['code'] === $code)
			{
				$b       = $builds[$it['build']];
				$is_last = (isset($latest[$code]) AND $latest[$code] === $it['build'])
					|| (isset($latest_done[$code]) AND $latest_done[$code] === $it['build']);

				if ($is_last)
				{
					$it['cat'] = 'keep';
				}
				elseif ((int) $b['ts'] > 0 AND (int) $b['ts'] < $stale_before)
				{
					$it['cat'] = 'stale';
				}
				else
				{
					$it['cat'] = 'keep';
				}
			}
			else
			{
				// Сборка без строки в базе (или чужой проект): сирота, если давно лежит.
				$it['cat'] = $age_ok ? 'orphan' : 'keep';
			}

			$items[] = $it;

			if ( ! isset($cats[$it['cat']]))
			{
				$cats[$it['cat']] = ['files' => 0, 'bytes' => 0];
			}

			$cats[$it['cat']]['files']++;
			$cats[$it['cat']]['bytes'] += (int) $f['bytes'];

			if ( ! isset($per_project[$code]))
			{
				$per_project[$code] = 0;
			}

			$per_project[$code] += (int) $f['bytes'];
		}

		arsort($per_project);

		return ['items' => $items, 'cats' => $cats, 'projects' => $per_project];
	}

	/**
	 * Состояние из базы: проекты (code → deleted, title, status) и сборки (id → code, status, ts).
	 *
	 * @return array ['projects' => [...], 'builds' => [...], 'project_rows' => [...]]
	 */
	public static function state()
	{
		$projects = [];
		$by_id    = [];
		$rows     = self::_all('SELECT id, code, title, status,
				CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END AS deleted
			FROM nj_667_projects');

		foreach ($rows as $r)
		{
			$code = (string) $r['code'];
			$by_id[(int) $r['id']] = $code;
			$projects[$code] = [
				'deleted' => (int) $r['deleted'],
				'title'   => (string) $r['title'],
				'status'  => (string) $r['status'],
			];
		}

		$builds = [];
		$rows   = self::_all('SELECT id, project_id, status,
				EXTRACT(EPOCH FROM COALESCE(done_at, created_at))::bigint AS ts,
				CASE WHEN published_at IS NULL THEN 0 ELSE 1 END AS published
			FROM nj_667_builds');

		foreach ($rows as $r)
		{
			$builds[(int) $r['id']] = [
				'code'      => (string) Arr::get($by_id, (int) $r['project_id'], ''),
				'status'    => (string) $r['status'],
				'ts'        => (int) $r['ts'],
				'published' => (int) $r['published'] === 1,
			];
		}

		return ['projects' => $projects, 'builds' => $builds];
	}

	/** Сортировки списка файлов: ключ => подпись. */
	public static function sorts()
	{
		return [
			'size_desc' => 'по размеру: крупные сверху',
			'size_asc'  => 'по размеру: мелкие сверху',
			'old'       => 'по дате: давно не менялись',
			'new'       => 'по дате: недавно менялись',
			'type'      => 'по типу файла',
			'build'     => 'по номеру сборки',
		];
	}

	/** Разрешённые варианты группировки файлов. */
	public static function groups()
	{
		return [
			'build' => 'по сборке / проекту',
			'type'  => 'по типу файла',
			'none'  => 'без группировки',
		];
	}

	/** Сколько файлов показываем в списке (совпадает с лимитом удаления за запрос). */
	const FILES_SHOWN = 500;

	/** Сколько файлов удаляем одним нажатием в списке. */
	const DELETE_MAX = 500;

	/**
	 * Чистая сортировка списка файлов (проверяется стендом).
	 * При равенстве ключа порядок по имени пути, чтобы он был стабильным.
	 */
	public static function sort_items(array $items, $sort)
	{
		usort($items, function ($a, $b) use ($sort)
		{
			switch ($sort)
			{
				case 'size_asc':
					$d = $a['bytes'] <=> $b['bytes'];
					break;
				case 'old':
					$d = $a['mtime'] <=> $b['mtime'];
					break;
				case 'new':
					$d = $b['mtime'] <=> $a['mtime'];
					break;
				case 'type':
					$d = strnatcasecmp((string) Arr::get($a, 'ext', ''), (string) Arr::get($b, 'ext', ''));
					break;
				case 'build':
					$d = (int) Arr::get($a, 'build', 0) <=> (int) Arr::get($b, 'build', 0);
					break;
				default:
					$d = $b['bytes'] <=> $a['bytes'];
			}

			return $d !== 0 ? $d : strcmp((string) $a['rel'], (string) $b['rel']);
		});

		return $items;
	}

	/**
	 * Отчёт для страницы настроек: суммы, категории, место на диске, тяжёлые проекты,
	 * список файлов в выбранной сортировке.
	 */
	public static function report($sort = 'size_desc', $show_published = FALSE, $type = 'all', $group_by = 'build')
	{
		$sorts = self::sorts();
		$groups = self::groups();

		if ( ! isset($sorts[$sort]))
		{
			$sort = 'size_desc';
		}

		if ( ! isset($groups[$group_by]))
		{
			$group_by = 'build';
		}

		$cfg   = self::cfg();
		$root  = self::root();
		$scan  = self::scan();
		$state = self::state();
		$plan  = self::plan($scan, $state['projects'], $state['builds'], time(), $cfg);

		$files = 0;
		$bytes = 0;

		foreach ($scan as $f)
		{
			$files++;
			$bytes += (int) $f['bytes'];
		}

		$purge = ['files' => 0, 'bytes' => 0, 'kept_files' => 0, 'kept_bytes' => 0];
		$types = ['all' => 'Все типы'];

		foreach ($plan['items'] as $it)
		{
			$type_key = self::_type_key($it);
			$types[$type_key] = self::_type_title($type_key);

			if (self::keep_published_asset($it))
			{
				$purge['kept_files']++;
				$purge['kept_bytes'] += (int) $it['bytes'];
			}
			else
			{
				$purge['files']++;
				$purge['bytes'] += (int) $it['bytes'];
			}
		}

		if ( ! isset($types[$type]))
		{
			$type = 'all';
		}

		$rows = [];

		foreach (self::cats() as $key => $meta)
		{
			$c = Arr::get($plan['cats'], $key, ['files' => 0, 'bytes' => 0]);
			$rows[] = [
				'key'       => $key,
				'title'     => $meta['title'],
				'rule'      => $meta['rule'],
				'files'     => (int) $c['files'],
				'bytes'     => (int) $c['bytes'],
				'cleanable' => in_array($key, self::CLEANABLE, TRUE),
			];
		}

		$top = [];

		foreach (array_slice($plan['projects'], 0, 10, TRUE) as $code => $size)
		{
			$p     = Arr::get($state['projects'], $code, []);
			$top[] = [
				'code'    => (string) $code,
				'title'   => (string) Arr::get($p, 'title', ''),
				'status'  => (string) Arr::get($p, 'status', ''),
				'deleted' => (int) Arr::get($p, 'deleted', 0) === 1,
				'known'   => isset($state['projects'][$code]),
				'bytes'   => (int) $size,
			];
		}

		$cats_meta = self::cats();
		$hidden    = ['files' => 0, 'bytes' => 0];
		$list      = [];
		$group_stats = [];

		foreach ($plan['items'] as $it)
		{
			$type_key = self::_type_key($it);

			if ($type !== 'all' AND $type_key !== $type)
			{
				continue;
			}

			if ($it['published'] AND ! $show_published)
			{
				$hidden['files']++;
				$hidden['bytes'] += (int) $it['bytes'];
				continue;
			}

			$it['type_key']   = $type_key;
			$it['type_title'] = self::_type_title($type_key);

			if ($group_by === 'type')
			{
				$it['group_key']   = 'type:'.$type_key;
				$it['group_title'] = $it['type_title'];
			}
			elseif ($group_by === 'build')
			{
				if ((int) $it['build'] > 0)
				{
					$it['group_key']   = 'build:'.(int) $it['build'];
					$it['group_title'] = 'Сборка #'.(int) $it['build'].' · '.(string) $it['code'];
				}
				else
				{
					$code = (string) $it['code'];
					$p    = Arr::get($state['projects'], $code, []);
					$title = trim((string) Arr::get($p, 'title', ''));
					$it['group_key']   = 'project:'.$code;
					$it['group_title'] = $title !== '' ? 'Проект «'.$title.'» · '.$code
						: ($code !== '' ? 'Проект '.$code : 'Без сборки');
				}
			}
			else
			{
				$it['group_key']   = 'all';
				$it['group_title'] = 'Все файлы';
			}

			$list[] = $it;
			$group_key = (string) $it['group_key'];

			if ( ! isset($group_stats[$group_key]))
			{
				$group_stats[$group_key] = ['title' => (string) $it['group_title'], 'files' => 0, 'bytes' => 0];
			}

			$group_stats[$group_key]['files']++;
			$group_stats[$group_key]['bytes'] += (int) $it['bytes'];
		}

		$files_matching = count($list);
		$bytes_matching = array_sum(array_map(function ($it) { return (int) $it['bytes']; }, $list));

		if ($group_by === 'none')
		{
			$sorted = self::sort_items($list, $sort);
		}
		else
		{
			$buckets = [];

			foreach ($list as $it)
			{
				$buckets[$it['group_key']][] = $it;
			}

			uksort($buckets, function ($a, $b) use ($group_by, $group_stats)
			{
				if ($group_by === 'build')
				{
					$has_a = preg_match('~^build:([0-9]+)$~', (string) $a, $ma);
					$has_b = preg_match('~^build:([0-9]+)$~', (string) $b, $mb);

					if ($has_a AND $has_b)
					{
						return (int) $mb[1] <=> (int) $ma[1];
					}

					if ($has_a) { return -1; }
					if ($has_b) { return 1; }
				}

				$title_a = isset($group_stats[$a]) ? (string) $group_stats[$a]['title'] : '';
				$title_b = isset($group_stats[$b]) ? (string) $group_stats[$b]['title'] : '';

				return strnatcasecmp($title_a, $title_b);
			});

			$sorted = [];

			foreach ($buckets as $bucket)
			{
				foreach (self::sort_items($bucket, $sort) as $it)
				{
					$sorted[] = $it;
				}
			}
		}

		$shown = [];

		foreach (array_slice($sorted, 0, self::FILES_SHOWN) as $it)
		{
			$p       = Arr::get($state['projects'], $it['code'], []);
			$shown[] = [
				'rel'        => (string) $it['rel'],
				'name'       => (string) $it['name'],
				'code'       => (string) $it['code'],
				'title'      => (string) Arr::get($p, 'title', ''),
				'known'      => isset($state['projects'][$it['code']]),
				'kind'       => (string) $it['kind'],
				'build'      => (int) $it['build'],
				'ext'        => (string) $it['ext'],
				'type_key'   => (string) $it['type_key'],
				'type_title' => (string) $it['type_title'],
				'group_key'  => (string) $it['group_key'],
				'group_title'=> (string) $it['group_title'],
				'bytes'      => (int) $it['bytes'],
				'mtime'      => (int) $it['mtime'],
				'cat'        => (string) $it['cat'],
				'cat_title'  => (string) Arr::get(Arr::get($cats_meta, $it['cat'], []), 'title', ''),
				'published'  => (bool) $it['published'],
			];
		}

		$disk = NULL;

		if ($root !== '')
		{
			$free  = @disk_free_space($root);
			$total = @disk_total_space($root);

			if ($free !== FALSE AND $total !== FALSE)
			{
				$disk = ['free' => (float) $free, 'total' => (float) $total];
			}
		}

		return [
			'root_ok'        => $root !== '',
			'files'          => $files,
			'bytes'          => $bytes,
			'rows'           => $rows,
			'top'            => $top,
			'disk'           => $disk,
			'cfg'            => $cfg,
			'sort'           => $sort,
			'sorts'          => $sorts,
			'type'           => $type,
			'types'          => $types,
			'group_by'       => $group_by,
			'groups'         => $groups,
			'group_stats'    => $group_stats,
			'files_shown'    => $shown,
			'files_matching' => $files_matching,
			'bytes_matching' => $bytes_matching,
			'files_total'    => $files,
			'purge'          => $purge,
			'show_published' => (bool) $show_published,
			'hidden'         => $hidden,
		];
	}

	/** Ключ фильтра для расширения или файлов с неизвестным расширением. */
	protected static function _type_key(array $it)
	{
		$ext = strtolower(trim((string) Arr::get($it, 'ext', '')));

		if ($ext === '')
		{
			return 'no_extension';
		}

		return preg_match('~^[a-z0-9]{1,12}$~', $ext) ? $ext : 'other';
	}

	protected static function _type_title($key)
	{
		$key = (string) $key;

		if ($key === 'no_extension') { return 'без расширения'; }
		if ($key === 'other') { return 'неизвестное расширение'; }

		return '.'.$key;
	}

	/**
	 * Список файлов, сохраняемых полной очисткой: GLB сборки и её PNG-карточка,
	 * а также STL и PNG-превью деталей опубликованной сборки.
	 */
	public static function keep_published_asset(array $it)
	{
		if (empty($it['published']))
		{
			return FALSE;
		}

		$kind = (string) Arr::get($it, 'kind', '');
		$ext  = strtolower((string) Arr::get($it, 'ext', ''));

		return ($kind === 'build' AND in_array($ext, ['glb', 'png'], TRUE))
			OR ($kind === 'part' AND in_array($ext, ['stl', 'png'], TRUE));
	}

	/**
	 * Полная очистка: удаляет все просканированные файлы, кроме ресурсов,
	 * разрешённых keep_published_asset(). Пути от клиента не принимаются.
	 */
	public static function purge_unpublished()
	{
		$root = self::root();

		if ($root === '')
		{
			return ['ok' => FALSE, 'error' => 'Каталога с файлами пока нет.'];
		}

		$cfg   = self::cfg();
		$state = self::state();
		$plan  = self::plan(self::scan(), $state['projects'], $state['builds'], time(), $cfg);
		$done  = ['ok' => TRUE, 'removed' => 0, 'bytes' => 0, 'kept' => 0,
			'kept_bytes' => 0, 'failed' => 0, 'missing' => 0, 'left' => 0];
		$dirs = [];

		foreach ($plan['items'] as $it)
		{
			if (self::keep_published_asset($it))
			{
				$done['kept']++;
				$done['kept_bytes'] += (int) $it['bytes'];
				continue;
			}

			$abs = (string) Arr::get($it, 'abs', '');

			if ($abs === '' OR strpos($abs, $root.DIRECTORY_SEPARATOR) !== 0)
			{
				$done['failed']++;
				continue;
			}

			if ( ! is_file($abs))
			{
				$done['missing']++;
				continue;
			}

			if ( ! @unlink($abs))
			{
				$done['failed']++;
				continue;
			}

			self::_forget($it);
			$done['removed']++;
			$done['bytes'] += (int) $it['bytes'];
			$dirs[dirname($abs)] = TRUE;
		}

		self::_prune($dirs, $root);
		$done['left'] = $done['failed'] + $done['missing'];

		return $done;
	}

	/**
	 * Ручное удаление выбранных файлов из списка. Пути проверяются заново по диску:
	 * принимаем только то, что нашлось в scan(). Строки в базе гасятся тем же кодом,
	 * что и в порционной очистке.
	 *
	 * @param string[] $rels
	 * @return array ok, removed, bytes, failed, missing (или error)
	 */
	public static function delete_files(array $rels, $show_published = FALSE)
	{
		$rels = array_values(array_unique(array_filter(array_map('strval', $rels), function ($r)
		{
			return $r !== '';
		})));

		if ( ! $rels)
		{
			return ['ok' => FALSE, 'error' => 'Ничего не выбрано.'];
		}

		if (count($rels) > self::DELETE_MAX)
		{
			return ['ok' => FALSE, 'error' => 'За раз удаляем не больше '.self::DELETE_MAX.' файлов.'];
		}

		$root = self::root();

		if ($root === '')
		{
			return ['ok' => FALSE, 'error' => 'Каталога с файлами пока нет.'];
		}

		$cfg   = self::cfg();
		$state = self::state();
		$scan  = self::scan();
		$plan  = self::plan($scan, $state['projects'], $state['builds'], time(), $cfg);

		$index = [];

		foreach ($scan as $f)
		{
			$index[$f['rel']] = $f;
		}

		$items = [];

		foreach ($plan['items'] as $it)
		{
			$items[$it['rel']] = $it;
		}

		$removed   = 0;
		$bytes     = 0;
		$failed    = 0;
		$missing   = 0;
		$protected = 0;
		$dirs      = [];

		foreach ($rels as $rel)
		{
			if ( ! isset($index[$rel], $items[$rel]))
			{
				$missing++;
				continue;
			}

			// Опубликованные сборки удаляем только когда фильтр показан (не очистить по ошибке).
			if ($items[$rel]['published'] AND ! $show_published)
			{
				$protected++;
				continue;
			}

			$f   = $index[$rel];
			$abs = (string) $f['abs'];

			if (strpos($abs, $root.DIRECTORY_SEPARATOR) !== 0 OR ! is_file($abs) OR ! @unlink($abs))
			{
				$failed++;
				continue;
			}

			self::_forget($items[$rel]);
			$removed++;
			$bytes += (int) $f['bytes'];
			$dirs[dirname($abs)] = TRUE;
		}

		self::_prune($dirs, $root);

		return [
			'ok'      => TRUE,
			'removed' => $removed,
			'bytes'   => $bytes,
			'failed'    => $failed,
			'missing'   => $missing,
			'protected' => $protected,
		];
	}

	/** Пустые каталоги проектов уходят вслед за файлами (сам корень не трогаем). */
	protected static function _prune(array $dirs, $root)
	{
		foreach (array_keys($dirs) as $dir)
		{
			if ($dir !== $root AND @is_dir($dir) AND count((array) @scandir($dir)) === 2)
			{
				@rmdir($dir);
			}
		}
	}

	/**
	 * Очистка одной порцией категории: до batch файлов, крупные первыми.
	 *
	 * @return array ok, category, removed, bytes, left, failed (или error)
	 */
	public static function clean($category)
	{
		$category = (string) $category;

		if ( ! in_array($category, self::CLEANABLE, TRUE))
		{
			return ['ok' => FALSE, 'error' => 'Эту категорию нельзя очистить автоматически.'];
		}

		$cfg   = self::cfg();
		$root  = self::root();
		$state = self::state();
		$plan  = self::plan(self::scan(), $state['projects'], $state['builds'], time(), $cfg);

		$cands = [];

		foreach ($plan['items'] as $it)
		{
			if ($it['cat'] === $category)
			{
				$cands[] = $it;
			}
		}

		usort($cands, function ($a, $b)
		{
			return $b['bytes'] <=> $a['bytes'];
		});

		$removed = 0;
		$bytes   = 0;
		$failed  = 0;
		$dirs    = [];

		foreach (array_slice($cands, 0, $cfg['batch']) as $it)
		{
			$abs = (string) $it['abs'];

			// Путь обязан лежать под корнем модуля: из scan() он такой и есть, но проверяем.
			if ($root === '' OR strpos($abs, $root.DIRECTORY_SEPARATOR) !== 0)
			{
				$failed++;
				continue;
			}

			if (is_file($abs) AND ! @unlink($abs))
			{
				$failed++;
				continue;
			}

			self::_forget($it);
			$removed++;
			$bytes += (int) $it['bytes'];
			$dirs[dirname($abs)] = TRUE;
		}

		self::_prune($dirs, $root);

		return [
			'ok'       => TRUE,
			'category' => $category,
			'removed'  => $removed,
			'bytes'    => $bytes,
			'left'     => max(0, count($cands) - $removed),
			'failed'   => $failed,
		];
	}

	/** Гасим ссылку в базе на удалённый файл (путь и размер; статус — если файл был единственным). */
	protected static function _forget(array $it)
	{
		$rel = self::_q((string) $it['rel']);

		if ($it['kind'] === 'build')
		{
			if ($it['ext'] === 'scad')
			{
				self::_exec("UPDATE nj_667_builds SET scad_path = '', scad_size = 0 WHERE scad_path = {$rel}");
			}
			elseif ($it['ext'] === 'stl')
			{
				self::_exec("UPDATE nj_667_builds SET stl_path = '', stl_size = 0, stl_sha = '',
					status = CASE WHEN status IN ('done', 'published') AND glb_path = '' THEN 'spec' ELSE status END,
					published_at = CASE WHEN status IN ('done', 'published') AND glb_path = '' THEN NULL ELSE published_at END,
					published_by = CASE WHEN status IN ('done', 'published') AND glb_path = '' THEN 0 ELSE published_by END
					WHERE stl_path = {$rel}");
			}
			elseif ($it['ext'] === 'glb')
			{
				self::_exec("UPDATE nj_667_builds SET glb_path = '', glb_size = 0,
					status = CASE WHEN status IN ('done', 'published') AND stl_path = '' THEN 'spec' ELSE status END,
					published_at = CASE WHEN status IN ('done', 'published') AND stl_path = '' THEN NULL ELSE published_at END,
					published_by = CASE WHEN status IN ('done', 'published') AND stl_path = '' THEN 0 ELSE published_by END
					WHERE glb_path = {$rel}");
			}
		}
		elseif ($it['kind'] === 'part')
		{
			$build = (int) $it['build'];
			$part  = (int) $it['part'];

			if ($it['ext'] === 'stl')
			{
				self::_exec("UPDATE nj_667_build_parts SET stl_path = '', stl_size = 0, stl_sha = '',
					status = CASE WHEN status = 'done' THEN 'spec' ELSE status END
					WHERE build_id = {$build} AND part_index = {$part} AND stl_path = {$rel}");
			}
			elseif ($it['ext'] === 'scad')
			{
				self::_exec("UPDATE nj_667_build_parts SET scad_path = ''
					WHERE build_id = {$build} AND part_index = {$part} AND scad_path = {$rel}");
			}
			// png-превью узла в базе не записано: проверяется по наличию файла.
		}
	}

	/** Байты по-человечески: «12,3 МБ». */
	public static function fmt_bytes($bytes)
	{
		$v     = max(0.0, (float) $bytes);
		$units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
		$i     = 0;

		while ($v >= 1024 AND $i < 4)
		{
			$v /= 1024;
			$i++;
		}

		if ($i === 0)
		{
			return (int) round($v).' Б';
		}

		return number_format($v, $v < 10 ? 1 : 0, ',', '').' '.$units[$i];
	}

	protected static function _db()
	{
		return Database::instance();
	}

	protected static function _q($value)
	{
		return self::_db()->quote($value);
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
