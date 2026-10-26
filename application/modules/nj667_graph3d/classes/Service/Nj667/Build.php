<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-667: сборка графа в реальное изделие (типы узлов, посадка, спецификация).
 *
 * Зачем. Граф описывает конструкцию, но до сих пор из него можно было получить
 * только картинку и чертёж. Здесь из того же графа считается ЖЕЛЕЗО: какие
 * узлы печатать (и сколько копий каждого), какой длины резать палочки и что с
 * конструкцией не так (острые углы, нет места под палочку). Наработки
 * владельца — `source_data/for_3d_graph.scad`; по его же manual.md из них
 * вырезаны три зоны: данные графа, математика и спецификация.
 *
 * Почему математика здесь, а не в OpenSCAD (договор с воркером):
 *
 *   * спецификация нужна мгновенно — она считается из графа за миллисекунды,
 *     рендер OpenSCAD идёт минуты. Крутить BOM внутри .scad через echo() —
 *     значит парсить stdout и терять мгновенность;
 *   * дедупликация узлов («25 одинаковых, печатать один STL») решает, СКОЛЬКО
 *     CSG-задач послать в воркер. Пусть это считает сайт — воркер только рендерит;
 *   * один источник правды: те же CL/CTYPE/UNIQ уезжают в .scad, поэтому
 *     спецификация и модель не могут разойтись (правило модуля).
 *
 * Чистые функции: ни БД, ни сети. Оси: у графа вверх — Y (правило подсказки
 * SPACE_RULES), у OpenSCAD вверх — Z, поэтому в мм координаты переводятся
 * поворотом (x, −z, y), а не зеркалом: зеркальная велосипедная рама — уже другая.
 *
 * @see AGENTS.md (модуль) — правило «Спецификацию считает сайт»
 */
class Service_Nj667_Build
{
	/** Режимы вывода scad-файла (то, что умеет generators/assembly.scad). */
	const MODES = ['assembly', 'parts', 'single'];

	/** Лимит сборки из конфига (`build.limits`). */
	public static function limit($key, $default = 0)
	{
		return Arr::get((array) self::config('limits', []), (string) $key, $default);
	}

	/**
	 * Отпечаток графа для сборки: координаты и связи, без подписей.
	 *
	 * Отличается от topology_hash() проекта тем, что чувствителен к геометрии:
	 * сдвинули узел — модель на диске уже врёт, и страница обязана это сказать.
	 * Порядок узлов и направлений связей не важен (сортировка) — он на чертёж
	 * не влияет, а без сортировки «то же изделие» считалось бы другим.
	 */
	public static function graph_hash(array $graph)
	{
		$graph = Service_Nj667_Graph3d::graph_clean($graph);
		$nodes = [];

		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			$nodes[] = (string) Arr::get($node, 'id').':'
				.self::scad_num((float) Arr::get($node, 'x', 0)).','
				.self::scad_num((float) Arr::get($node, 'y', 0)).','
				.self::scad_num((float) Arr::get($node, 'z', 0));
		}

		sort($nodes);

		$edges = [];

		foreach ((array) Arr::get($graph, 'edges', []) as $edge)
		{
			$a = (string) Arr::get($edge, 'source');
			$b = (string) Arr::get($edge, 'target');

			$edges[] = ($a <= $b ? $a.'-'.$b : $b.'-'.$a);
		}

		sort($edges);

		return sha1(implode('|', $nodes).'#'.implode('|', $edges));
	}

	/**
	 * Спецификация для spec_json: только то, что показывают страница и карточка.
	 *
	 * Полная геометрия (координаты, лучи) в базу не пишется: её пересчитывают
	 * из графа за миллисекунды, а jsonb на 200 КБ в каждой сборке — это
	 * раздутая таблица и медленный список проектов (урок nj646).
	 */
	public static function spec_pack(array $geom)
	{
		$types = [];

		foreach ((array) Arr::get($geom, 'types', []) as $t)
		{
			$types[] = [
				'i'         => (int) $t['i'],
				'count'     => (int) $t['count'],
				'slots'     => (int) $t['slots'],
				'arms'        => count((array) $t['arms']),
				'angles'    => array_map(function ($v) { return self::round($v, 1); }, (array) $t['arms']),
				'min_angle' => (float) $t['min_angle'],
				'size'      => (float) $t['size'],
				'nodes'     => (array) $t['nodes'],
			];
		}

		$issues = [];

		foreach ((array) Arr::get($geom, 'issues', []) as $issue)
		{
			$issues[] = ['level' => (string) Arr::get($issue, 'level', 'note'),
				'text' => (string) Arr::get($issue, 'text', '')];

			if (count($issues) >= 40)
			{
				break;
			}
		}

		return [
			'ok'       => !empty($geom['ok']),
			'error'    => (string) Arr::get($geom, 'error', ''),
			'unit'     => (string) Arr::get($geom, 'unit', 'mm'),
			'unit_size'=> (float) Arr::get($geom, 'unit_size', 1),
			'dims'     => (array) Arr::get($geom, 'dims', []),
			'span'     => (array) Arr::get($geom, 'span', []),
			'sticks'   => (array) Arr::get($geom, 'sticks', []),
			'types'    => $types,
			'clusters' => count((array) Arr::get($geom, 'clusters', [])),
			'counts'   => (array) Arr::get($geom, 'counts', []),
			'issues'   => $issues,
		];
	}

	/** Параметры сборки — как их надо вернуть в форму («повторить ровно то»). */
	public static function params_pack(array $opt)
	{
		return [
			'type'        => (string) Arr::get($opt, 'type', 'flat'),
			'quality'     => (string) Arr::get($opt, 'quality', 'low'),
			'fn'          => (int) Arr::get($opt, 'fn', 32),
			'params'      => (array) Arr::get($opt, 'params', []),
			'unit'        => (string) Arr::get($opt, 'unit', 'mm'),
			'unit_size'   => (float) Arr::get($opt, 'unit_size', 1),
			'merge'       => (int) Arr::get($opt, 'merge', 1),
			'perforation' => (int) Arr::get($opt, 'perforation', 0),
			'show_sticks' => (int) Arr::get($opt, 'show_sticks', 1),
			'mode'        => (string) Arr::get($opt, 'mode', 'assembly'),
			'part'        => (int) Arr::get($opt, 'part', 0),
		];
	}

	/**
	 * GLB на входе — та же оборона, что у stl_stats: в браузер пускаем только
	 * то, что сами прочитали, и враньё в файле называем словами.
	 *
	 * Разбираем ровно наше подмножество glTF: один mesh, POSITION + NORMAL +
	 * индексы, бинарник внутри файла. Чужой GLB (текстуры, анимация, внешние
	 * .bin) — ошибка: страница его всё равно не нарисует, а молча нарисованный
	 * наполовину купол хуже честного «не могу».
	 */
	public static function glb_stats($bytes)
	{
		$out = ['ok' => FALSE, 'error' => '', 'size' => 0, 'triangles' => 0,
			'vertices' => 0, 'groups' => 0, 'min' => [0.0, 0.0, 0.0], 'max' => [0.0, 0.0, 0.0],
			'dims' => [0.0, 0.0, 0.0], 'generator' => ''];

		$bytes = (string) $bytes;
		$out['size'] = strlen($bytes);

		if ($out['size'] < 20)
		{
			$out['error'] = 'GLB короче заголовка: выгрузка оборвалась.';

			return $out;
		}

		$head = unpack('Vmagic/Vversion/Vtotal', substr($bytes, 0, 12));

		if ((int) Arr::get($head, 'magic', 0) !== 0x46546C67)
		{
			$out['error'] = 'Это не GLB: сигнатуры glTF на месте нет.';

			return $out;
		}

		if ((int) Arr::get($head, 'version', 0) !== 2)
		{
			$out['error'] = 'Умеем glTF 2.0, а в файле версия '
				.(int) Arr::get($head, 'version', 0).'.';

			return $out;
		}

		if ((int) Arr::get($head, 'total', 0) !== $out['size'])
		{
			$out['error'] = 'GLB обрезан: заявлено '.(int) Arr::get($head, 'total', 0)
				.' байт, пришло '.$out['size'].'.';

			return $out;
		}

		$json = self::_glb_chunk($bytes, 12, 0x4E4F534A, 'JSON');

		if ( ! is_array($json))
		{
			$out['error'] = is_string($json) ? $json : 'JSON-описание glTF не читается.';

			return $out;
		}

		$meshes = (array) Arr::get($json, 'meshes', []);
		$prims  = (array) Arr::get(Arr::get($meshes, 0, []), 'primitives', []);
		// Цветная сборка — ОДИН меш, но примитивов в нём столько, сколько
		// `color()` написал OpenSCAD (обычно: типов узлов + палочки). Мешей по
		//-прежнему ждём ровно один: узлы страницы крутим камерой вокруг одной
		// сцены, а не листаем несколько.
		$max_prims = (int) self::limit('glb_prims_max', 24);

		if (count($meshes) !== 1 OR ! $prims OR count($prims) > $max_prims)
		{
			$out['error'] = 'В сборке '.count($meshes).' меш(ей) и '.count($prims)
				.' примитив(ов), а страница рисует один меш до '.$max_prims.' групп цвета.';

			return $out;
		}

		// Внешний буфер (data: или .bin рядом) страница не дотянет: GLB у нас
		// самодостаточен по определению, иначе человек получит битую модель.
		$b0 = (array) Arr::get((array) Arr::get($json, 'buffers', []), 0, []);

		if (count((array) Arr::get($json, 'buffers', [])) > 1 OR Arr::get($b0, 'uri') !== NULL)
		{
			$out['error'] = 'GLB со внешними данными — страница умеет только файл целиком.';

			return $out;
		}

		$acc   = (array) Arr::get($json, 'accessors', []);
		$views = (array) Arr::get($json, 'bufferViews', []);
		$mats  = (array) Arr::get($json, 'materials', []);
		// BIN — это байты, его не json_decode'ят: для нас важны длина и то,
		// что он действительно лежит внутри файла.
		$bin = self::_glb_bin($bytes, 12 + 8 + self::_glb_len($bytes, 12));

		if ($bin['error'] !== '')
		{
			$out['error'] = $bin['error'];

			return $out;
		}

		// Все примитивы проверяем одинаково и считаем суммарно: «габарит
		// сборки» — это рамка вокруг всех цветов, а не вокруг первого.
		$min  = [INF, INF, INF];
		$max  = [-INF, -INF, -INF];
		$need = 0;
		$tris = 0;
		$verts = 0;

		foreach ($prims as $num => $prim)
		{
			$attr = (array) Arr::get($prim, 'attributes', []);
			$pos  = (int) Arr::get($attr, 'POSITION', -1);
			$idx  = (int) Arr::get($prim, 'indices', -1);

			if ($pos < 0 OR ! isset($acc[$pos]) OR $idx < 0 OR ! isset($acc[$idx]))
			{
				$out['error'] = 'В glTF (группа '.((int) $num + 1).') нет POSITION или индексов'
					.' — рисовать нечего.';

				return $out;
			}

			$p_acc = (array) $acc[$pos];

			// min/max в glTF обязательны для POSITION — по ним страница ставит
			// камеру и подписывает габарит, поэтому проверяем их, а не верим.
			$b0 = (array) Arr::get($p_acc, 'min', []);
			$b1 = (array) Arr::get($p_acc, 'max', []);

			if (count($b0) !== 3 OR count($b1) !== 3)
			{
				$out['error'] = 'У POSITION (группа '.((int) $num + 1).') нет габарита (min/max)'
					.' — камеру ставить некуда.';

				return $out;
			}

			foreach ([0, 1, 2] as $d)
			{
				$min[$d] = min($min[$d], (float) $b0[$d]);
				$max[$d] = max($max[$d], (float) $b1[$d]);
			}

			$span_acc = [$p_acc, (array) $acc[$idx]];
			$nrm      = (int) Arr::get($attr, 'NORMAL', -1);

			if ($nrm >= 0 AND isset($acc[$nrm]))
			{
				$span_acc[] = (array) $acc[$nrm];
			}

			foreach ($span_acc as $a)
			{
				$need = max($need, self::_glb_span($a, $views));
			}

			// Цвет группа берёт из материала. Материала может и не быть —
			// файл без него рисуется нейтральным, и отвергать его «за отсутствие
			// красоты» значило бы лишать человека модели. А вот если материал
			// обещан, но битый — это обрезанный файл, и молчать нельзя.
			$mat = (int) Arr::get($prim, 'material', -1);

			if ($mat >= 0 AND isset($mats[$mat]))
			{
				$rgb = (array) Arr::get((array) Arr::get((array) $mats[$mat],
					'pbrMetallicRoughness', []), 'baseColorFactor', []);

				if (count($rgb) < 3 OR count($rgb) > 4)
				{
					$out['error'] = 'У группы '.((int) $num + 1).' материал без цвета — '
						.'раскрасить её нечем.';

					return $out;
				}
			}

			$tris  += (int) floor((int) Arr::get($acc[$idx], 'count', 0) / 3);
			$verts += (int) Arr::get($p_acc, 'count', 0);
		}

		if ($need > $bin['len'])
		{
			$out['error'] = 'Данные glTF ссылаются за конец бинарника: нужно '
				.$need.' байт, а в файле '.$bin['len'].'.';

			return $out;
		}

		if ($tris < 1)
		{
			$out['error'] = 'В glTF нет ни одной грани: файл есть, рисовать нечего.';

			return $out;
		}

		$out['ok']         = TRUE;
		$out['groups']     = count($prims);
		$out['triangles']  = $tris;
		$out['vertices']   = $verts;
		$out['min']        = $min;
		$out['max']        = $max;
		$out['dims']       = [round($max[0] - $min[0], 3), round($max[1] - $min[1], 3),
			round($max[2] - $min[2], 3)];
		$out['generator']  = UTF8::substr((string) Arr::get((array) Arr::get($json, 'asset', []),
			'generator', ''), 0, 80);

		return $out;
	}

	/**
	 * Прочитать chunk GLB по смещению и сверить тип. Массив — данные,
	 * строка — текст ошибки (возвращаем одно из двух, не два).
	 */
	protected static function _glb_chunk($bytes, $at, $type, $name)
	{
		if ($at + 8 > strlen($bytes))
		{
			return 'Chunk '.$name.' не влезает в файл.';
		}

		$h = unpack('Vlen/Vkind', substr($bytes, $at, 8));
		$len = (int) Arr::get($h, 'len', 0);

		if ((int) Arr::get($h, 'kind', 0) !== $type)
		{
			return 'Первым в GLB должен быть chunk '.$name.'.';
		}

		if ($len < 0 OR $at + 8 + $len > strlen($bytes))
		{
			return 'Chunk '.$name.' вылезает за конец файла.';
		}

		$text = rtrim(substr($bytes, $at + 8, $len), " \0");
		$json = json_decode($text, TRUE);

		return is_array($json) ? $json : 'JSON-описание glTF не читается.';
	}

	/**
	 * Бинарный chunk GLB: только границы и тип. Данные возвращаем длиной —
	 * копия мегабайтного бинарника в памяти ради проверки не нужна.
	 */
	protected static function _glb_bin($bytes, $at)
	{
		$out = ['len' => 0, 'start' => 0, 'error' => ''];

		if ($at + 8 > strlen($bytes))
		{
			$out['error'] = 'Бинарного chunk glTF не найти.';

			return $out;
		}

		$h = unpack('Vlen/Vkind', substr($bytes, $at, 8));

		if ((int) Arr::get($h, 'kind', 0) !== 0x004E4942)
		{
			$out['error'] = 'Вслед за описанием glTF должен быть chunk BIN.';

			return $out;
		}

		$len = (int) Arr::get($h, 'len', 0);

		if ($len < 0 OR $at + 8 + $len > strlen($bytes))
		{
			$out['error'] = 'Chunk BIN вылезает за конец файла.';

			return $out;
		}

		$out['len'] = $len;
		$out['start'] = $at + 8;

		return $out;
	}

	/** Длина chunk по заголовку (для границы между JSON и BIN). */
	protected static function _glb_len($bytes, $at)
	{
		$h = unpack('Vlen', substr($bytes, $at, 4));

		return (int) Arr::get($h, 'len', 0);
	}

	/** Сколько байт от начала бинарника занимает accessor со своим bufferView. */
	protected static function _glb_span(array $accessor, array $views)
	{
		$view = (array) Arr::get($views, (int) Arr::get($accessor, 'bufferView', -1), []);
		$type = (int) Arr::get($accessor, 'componentType', 5126);
		$comp = (int) Arr::get(['SCALAR' => 1, 'VEC3' => 3],
			(string) Arr::get($accessor, 'type', 'SCALAR'), 1);
		// Ширина по componentType: 5125 (uint32) — 4, а не 2, иначе обрезанный
		// файл с 32-битными индексами прошёл бы проверку «данных хватает».
		$wide = (int) Arr::get([5120 => 1, 5121 => 1, 5122 => 2, 5123 => 2,
			5124 => 4, 5125 => 4, 5126 => 4], $type, 4);

		return (int) Arr::get($view, 'byteOffset', 0) + (int) Arr::get($accessor, 'count', 0)
			* $comp * $wide;
	}

	/** Каталог scad-библиотеки модуля (application/modules/nj667_graph3d/scad). */
	public static function scad_dir()
	{
		return (string) realpath(dirname(__FILE__).'/../../../scad');
	}

	/** Ветка `build` конфига модуля. */
	public static function config($key = NULL, $default = NULL)
	{
		$build = (array) Arr::get(Service_Nj667_Graph3d::config(), 'build', []);

		if ($key === NULL)
		{
			return $build;
		}

		return Arr::get($build, $key, $default);
	}

	/** Справочник типов узла: ключ → [title, hint, params, …]. */
	public static function types()
	{
		return (array) self::config('types', []);
	}

	public static function type($key)
	{
		$types = self::types();

		return (array) Arr::get($types, (string) $key, []);
	}

	public static function type_title($key)
	{
		$type = self::type($key);

		return (string) Arr::get($type, 'title', (string) $key);
	}

	/** Уровни сглаживания ($fn). */
	public static function qualities()
	{
		$out = [];

		foreach ((array) self::config('quality', []) as $key => $row)
		{
			$out[(string) $key] = (string) Arr::get($row, 'title', (string) $key);
		}

		return $out;
	}

	/** Все параметры, которые умеет тип (его сечение + общие посадки). */
	public static function type_params($key)
	{
		$type   = self::type($key);
		$params = [];

		foreach ((array) Arr::get($type, 'params', []) as $name => $spec)
		{
			$params[(string) $name] = (array) $spec;
		}

		foreach ((array) self::config('common', []) as $name => $spec)
		{
			$params[(string) $name] = (array) $spec;
		}

		return $params;
	}

	/**
	 * Параметры сборки: что прислал человек + что сохранено в проекте.
	 *
	 * Правил тут два, и оба важные:
	 *   * неизвестный тип/параметр не проходит (в .scad уедут только числа из
	 *     конфига — иначе человек мог бы отправить в OpenSCAD произвольный код);
	 *   * всё клампится в min/max: 0.01 мм стенки — это не модель, а зависший
	 *     на часы рендер.
	 */
	public static function opt(array $raw = [], array $saved = [])
	{
		$src   = $raw + $saved;
		$types = self::types();
		$type  = (string) Arr::get($src, 'type', '');

		// Ни «flat», ни того, что просили: берём первый тип из конфига — иначе
		// новый тип в конфиге пришлось бы править ещё и здесь.
		if ( ! isset($types[$type]))
		{
			$saved_type = (string) Arr::get($saved, 'type', '');
			$type = isset($types[$saved_type]) ? $saved_type
				: (isset($types['flat']) ? 'flat' : (string) array_key_first($types));
		}

		$quality = (string) Arr::get($src, 'quality', '');
		$quals   = (array) self::config('quality', []);

		if ( ! isset($quals[$quality]))
		{
			$quality = isset($quals['low']) ? 'low' : (string) array_key_first($quals);
		}

		$fn      = (int) Arr::get((array) Arr::get($quals, $quality, []), 'fn', 32);
		$checks  = (array) self::config('checks', []);
		$limits  = (array) self::config('limits', []);
		$params  = [];

		// Форма шлёт параметры плоско (stick_width=10), история проекта — в
		// «params»; ищем сначала там, затем там, дефолт берём из конфига.
		$from = (array) Arr::get($src, 'params', []);

		foreach (self::type_params($type) as $name => $spec)
		{
			$raw = array_key_exists($name, $from) ? $from[$name] : Arr::get($src, $name, Arr::get($spec, 'def'));

			$params[$name] = self::num_clamp($raw,
				(float) Arr::get($spec, 'min', 0.0),
				(float) Arr::get($spec, 'max', 1000.0),
				(float) Arr::get($spec, 'def', 1.0));
		}

		// Единицы — те же, что у чертежа: 1 клетка графа = N мм/см/м.
		$drawing = Service_Nj667_Graph3d::drawing_opt([
			'unit'      => Arr::get($src, 'unit', Arr::get($saved, 'unit')),
			'unit_size' => Arr::get($src, 'unit_size', Arr::get($saved, 'unit_size')),
		]);

		$mode = strtolower(trim((string) Arr::get($src, 'mode', 'assembly')));
		$part = (int) Arr::get($src, 'part', 0);

		// Флаги: ключ пришёл — берём как есть (в т.ч. «выключено»), не пришёл —
		// дефолт из конфига. Иначе чекбокс «галочка снята» не отличим от
		// «пользователь ещё ничего не трогал».
		$flags = (array) Arr::get(self::config(), 'flags', []);

		$flag = function ($name) use ($src, $flags)
		{
			$def = ! empty($flags[$name]);

			return array_key_exists($name, $src) ? self::bool($src[$name], $def) : $def;
		};

		return [
			'type'        => $type,
			'quality'     => $quality,
			'fn'          => max(3, min(256, $fn)),
			'params'      => $params,
			'insert'      => (float) Arr::get($params, 'stick_insert_depth', 14.0),
			'tolerance'   => (float) Arr::get($params, 'tolerance', 0.25),
			'wall'        => (float) Arr::get($params, 'wall_thickness', 1.0),
			'perforation' => $flag('perforation') ? 1 : 0,
			'show_sticks' => $flag('show_sticks') ? 1 : 0,
			'merge'       => $flag('merge') ? 1 : 0,
			'merge_gap'   => (float) Arr::get($checks, 'merge_gap', 0.5),
			'min_angle'   => (float) Arr::get($checks, 'min_angle', 22.0),
			'min_span'    => (float) Arr::get($checks, 'min_span', 5.0),
			'unit'        => $drawing['unit'],
			// Сколько миллиметров в одной клетке графа — единая линейка для
			// чертежа и сборки (расходится — расходятся размеры на листе и STL).
			'unit_size'   => (float) $drawing['unit_size'],
			'mode'        => in_array($mode, self::MODES, TRUE) ? $mode : 'assembly',
			'part'        => max(0, min(999, $part)),
			'max_nodes'   => (int) Arr::get($limits, 'max_nodes', 400),
			'max_stl_mb'  => (int) Arr::get($limits, 'max_stl_mb', 24),
		];
	}

	/** Габарит сечения, паз, наружный профиль и «юбка» узла — в мм. */
	public static function dims(array $opt)
	{
		$type   = self::type($opt['type']);
		$bb     = (array) Arr::get($type, 'bb', ['w' => '', 't' => '']);
		$params = (array) Arr::get($opt, 'params', []);
		$w      = (float) Arr::get($params, (string) Arr::get($bb, 'w', ''), 6.0);
		$t      = (float) Arr::get($params, (string) Arr::get($bb, 't', ''), 6.0);
		$tol    = (float) Arr::get($opt, 'tolerance', 0.25);
		$wall   = (float) Arr::get($opt, 'wall', 1.0);
		$stop   = (float) Arr::get($params, 'stop_thickness', 2.0);

		$slot_w  = $w + $tol;
		$slot_t  = $t + $tol;
		$outer_w = $slot_w + $wall * 2;
		$outer_t = $slot_t + $wall * 2;
		$core    = max($outer_w, $outer_t);

		return [
			'bb_w'      => $w,
			'bb_t'      => $t,
			'slot_w'    => $slot_w,
			'slot_t'    => $slot_t,
			'outer_w'   => $outer_w,
			'outer_t'   => $outer_t,
			'core_size' => $core,
			// На сколько от центра узла палочка уже «вошла» — порог склейки
			// и длина визуальной палочки в сборке.
			'r_in'      => $core / 2 + $stop,
		];
	}

	/**
	 * Геометрия сборки: кластеры узлов, уникальные типы, раскрой и замечания.
	 *
	 * Возврат — договор сразу с тремя потребителями: .scad (CL/CTYPE/UNIQ),
	 * страница спецификации и CSV. Поэтому ключи стабильные, а числа — готовые
	 * к показу (округлённые), без «посчитай сам во вьюхе».
	 */
	public static function geometry(array $graph, array $opt)
	{
		$graph = Service_Nj667_Graph3d::graph_clean($graph);
		$mm    = (float) $opt['unit_size'];
		$dims  = self::dims($opt);
		$nodes = (array) Arr::get($graph, 'nodes', []);
		$edges = (array) Arr::get($graph, 'edges', []);

		$geom = [
			'ok'       => FALSE,
			'error'    => '',
			'dims'     => $dims,
			'unit'     => $opt['unit'],
			'unit_size'=> $mm,
			'mm'       => [],
			'ids'      => [],
			'labels'   => [],
			'edges'    => [],
			'arms'     => [],
			'cluster'  => [],
			'clusters' => [],
			'types'    => [],
			'sticks'   => [],
			'issues'   => [],
			'span'     => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
			'counts'   => ['nodes' => count($nodes), 'edges' => count($edges)],
		];

		if (count($nodes) < 2)
		{
			$geom['error'] = 'Для сборки нужно минимум два узла: сейчас граф пустой или одиночный.';

			return $geom;
		}

		if ( ! $edges)
		{
			$geom['error'] = 'В графе нет связей — собирать нечего, каждая палочка живёт сама по себе.';

			return $geom;
		}

		if (count($nodes) > (int) $opt['max_nodes'])
		{
			$geom['error'] = 'Узлов '.count($nodes).', а сборка потянет не больше '.(int) $opt['max_nodes']
				.'. Укрупните конструкцию: лишние детали в графе — это часы рендера и сотни граммов пластика.';

			return $geom;
		}

		// --- узлы в миллиметры (ось Y графа — вверх, у OpenSCAD вверх — Z) ----
		$by_id = [];

		foreach ($nodes as $node)
		{
			$id = (string) Arr::get($node, 'id');
			$x  = (float) Arr::get($node, 'x', 0);
			$y  = (float) Arr::get($node, 'y', 0);
			$z  = (float) Arr::get($node, 'z', 0);

			$by_id[$id] = count($geom['mm']);
			$geom['mm'][]   = [self::round($x * $mm, 3), self::round(-$z * $mm, 3), self::round($y * $mm, 3)];
			$geom['ids'][]  = $id;
			$geom['labels'][] = (string) Arr::get($node, 'label', '');
		}

		// --- связи ------------------------------------------------------------
		$seen = [];

		foreach ($edges as $edge)
		{
			$a = Arr::get($by_id, (string) Arr::get($edge, 'source'));
			$b = Arr::get($by_id, (string) Arr::get($edge, 'target'));

			if ($a === NULL OR $b === NULL OR $a === $b)
			{
				continue;
			}

			$key = min($a, $b).'-'.max($a, $b);

			if (isset($seen[$key]))
			{
				$geom['issues'][] = ['level' => 'warn', 'text' => 'Связь '.$geom['ids'][$a].' — '.$geom['ids'][$b].' повторяется: в сборке она одна.'];

				continue;
			}

			$seen[$key] = TRUE;

			$p = $geom['mm'][(int) $a];
			$q = $geom['mm'][(int) $b];
			$d = sqrt(pow($p[0] - $q[0], 2) + pow($p[1] - $q[1], 2) + pow($p[2] - $q[2], 2));

			$geom['edges'][] = [
				'a'    => (int) $a,
				'b'    => (int) $b,
				'len'  => self::round($d, 1),
				'free' => self::round($d - 2 * $dims['r_in'], 1),
				'cut'  => self::round($d - 2 * $dims['r_in'] + 2 * (float) $opt['insert'], 1),
			];
		}

		if ( ! $geom['edges'])
		{
			$geom['error'] = 'Ни одна связь не смотрит в два разных узла графа — проверьте граф.';

			return $geom;
		}

		// --- лучи каждого узла: направление + «внутренний» ли он -------------
		$geom['arms'] = self::arms_of($geom, $dims);

		// --- сигнатуры углов (для дедупликации типов) -------------------------
		// Внутренность луча зависит от кластеров, поэтому помечаем их здесь,
		// до сигнатур: «паза нет» — свойство луча, а не узла.
		// --- кластеры: пересекающиеся узлы печатаются одной деталью ----------
		$geom['cluster'] = self::clusters($geom, $opt, $dims);

		foreach ($geom['arms'] as $i => $arms)
		{
			foreach ($arms as $k => $arm)
			{
				$geom['arms'][$i][$k]['internal'] = ($geom['cluster'][(int) $arm['to']] == $geom['cluster'][$i]) ? 1 : 0;
			}
		}

		$sigs = [];

		foreach ($geom['arms'] as $i => $arms)
		{
			$sigs[$i] = self::signature($arms, (float) $opt['min_angle']);
		}
		$clusters        = [];

		foreach ($geom['cluster'] as $i => $label)
		{
			$clusters[$label][] = $i;
		}

		ksort($clusters);

		foreach ($clusters as $label => $members)
		{
			$internal = [];

			foreach ($geom['edges'] as $e)
			{
				if ($geom['cluster'][$e['a']] == $label AND $geom['cluster'][$e['b']] == $label)
				{
					$internal[] = self::round((float) $e['len'], 1);
				}
			}

			sort($internal);

			$ext = 0;

			foreach ($geom['edges'] as $e)
			{
				if (($geom['cluster'][$e['a']] == $label) !== ($geom['cluster'][$e['b']] == $label))
				{
					$ext++;
				}
			}

			$node_sigs = [];

			foreach ($members as $i)
			{
				$node_sigs[] = $sigs[$i]['sig'];
			}

			sort($node_sigs);

			$geom['clusters'][] = [
				'label'    => (int) $label,
				'nodes'    => $members,
				'ids'      => array_map(function ($i) use ($geom) { return $geom['ids'][$i]; }, $members),
				'internal' => $internal,
				'ext'      => $ext,
				// Подпись КЛАСТЕРА, а не узла: два узла с одинаковыми лучами,
				// но разные по составу, — разные детали.
				'sig'      => count($members).'N'.implode('|', $node_sigs).'L'.implode(',', $internal),
			];
		}

		// --- уникальные типы деталей: сигнатура → одна STL, сколько копий ----
		// Ключ — строка сигнатуры, порядок — ksort: он же порядок part_index в
		// .scad (UNIQ), поэтому «тип #2» на странице и второй STL-файл — одно.
		$by_sig = [];

		foreach ($geom['clusters'] as $ci => $c)
		{
			$by_sig[$c['sig']][] = $ci;
		}

		ksort($by_sig);

		foreach ($by_sig as $sig => $group)
		{
			$rep   = $geom['clusters'][$group[0]];
			$i     = count($geom['types']);
			$sizes = [];
			$labels = [];

			foreach ($group as $ci)
			{
				$sizes[]  = self::cl_size($geom, $opt, $dims, $geom['clusters'][$ci]['nodes']);
				$labels[] = (int) $geom['clusters'][$ci]['label'];

				$geom['clusters'][$ci]['type'] = $i;
			}

			$first = (int) $rep['nodes'][0];

			$geom['types'][] = [
				'i'         => $i,
				'sig'       => $sig,
				'count'     => count($group),
				'rep'       => (int) $rep['label'],
				'node'      => $first,
				'nodes'     => $rep['ids'],
				'arms'      => (array) $sigs[$first]['arms_deg'],
				'min_angle' => (float) $sigs[$first]['min'],
				'slots'     => (int) $rep['ext'],
				'size'      => self::round(max($sizes), 1),
				'clusters'  => $labels,
			];
		}

		// --- лист раскроя палочек --------------------------------------------
		$cuts = [];

		foreach ($geom['edges'] as $k => $e)
		{
			$external = $geom['cluster'][$e['a']] != $geom['cluster'][$e['b']];

			$geom['edges'][$k]['external'] = $external ? 1 : 0;

			if ( ! $external)
			{
				continue;
			}

			$len = (float) $e['cut'];

			if ($len <= 0)
			{
				$geom['issues'][] = ['level' => 'warn', 'text' => 'Палочка '.$geom['ids'][$e['a']].' — '.$geom['ids'][$e['b']]
					.' не влезает: между узлами осталось '.self::round($e['free'], 1).' мм. Увеличьте расстояние или сдвиньте узлы.'];

				continue;
			}

			$cuts[self::key($len)][] = $e;
		}

		ksort($cuts, SORT_NUMERIC);

		foreach ($cuts as $len => $group)
		{
			$geom['sticks'][] = [
				'len'   => self::round((float) $len, 1),
				'count' => count($group),
				'pair'  => $group[0]['a'].'—'.$group[0]['b'],
			];
		}

		// --- габарит ---------------------------------------------------------
		$min = [INF, INF, INF];
		$max = [-INF, -INF, -INF];

		foreach ($geom['mm'] as $p)
		{
			for ($k = 0; $k < 3; $k++)
			{
				$min[$k] = min($min[$k], $p[$k]);
				$max[$k] = max($max[$k], $p[$k]);
			}
		}

		$geom['span'] = [
			'x' => self::round($max[0] - $min[0], 1),
			'y' => self::round($max[1] - $min[1], 1),
			'z' => self::round($max[2] - $min[2], 1),
		];

		// --- замечания собираемости ------------------------------------------
		$geom['issues'] = array_merge($geom['issues'], self::issues($geom, $opt, $sigs));

		$geom['counts'] = [
			'nodes'    => count($geom['ids']),
			'edges'    => count($geom['edges']),
			'clusters' => count($geom['clusters']),
			'types'    => count($geom['types']),
			'sticks'   => array_sum(array_column($geom['sticks'], 'count')),
			'meters'   => self::round(array_sum(array_map(function ($s) { return $s['len'] * $s['count']; }, $geom['sticks'])) / 1000, 2),
			'merged'   => count($geom['ids']) - count($geom['clusters']),
			'issues'   => count($geom['issues']),
		];

		$geom['ok'] = TRUE;

		return $geom;
	}

	// ---------------------------------------------------------------- числа

	/** Округление для показа и для сравнения: 0.1 мм — предел чертежа. */
	public static function round($value, $dec = 1)
	{
		return round((float) $value, (int) $dec) + 0.0;
	}

	/** Ключ группировки раскроя: 123.4 и 123.4000001 — одна заготовка. */
	protected static function key($len)
	{
		return (string) round((float) $len, 1);
	}

	/** Число из формы: запятая допустима, мимо min/max — в границы. */
	protected static function num_clamp($raw, $min, $max, $def)
	{
		$raw = str_replace(',', '.', trim((string) $raw));

		if ($raw === '')
		{
			$raw = (string) $def;
		}

		if ( ! is_numeric($raw))
		{
			$raw = (string) $def;
		}

		$value = (float) $raw;

		if ($min === $max)
		{
			return $value;
		}

		return max((float) $min, min((float) $max, $value));
	}

	/** Чекбокс/галка из формы и из jsonb: «1|on|yes|true|да» — истина. */
	protected static function bool($raw, $default = TRUE)
	{
		if ($raw === NULL OR $raw === '')
		{
			return (bool) $default;
		}

		if (is_bool($raw))
		{
			return $raw;
		}

		if (is_numeric($raw))
		{
			return ((float) $raw) > 0;
		}

		return in_array(mb_strtolower(trim((string) $raw)), ['1', 'true', 'on', 'yes', 'да'], TRUE);
	}

	/** Число для OpenSCAD: точка, без «1.000000», без локальных запятых. */
	protected static function scad_num($value)
	{
		$s = number_format((float) $value, 6, '.', '');

		return rtrim(rtrim($s, '0'), '.') ?: '0';
	}

	protected static function esc($text)
	{
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}

	// ------------------------------------------------------------- лучи, углы

	/**
	 * Лучи узла: направление, «внутренний?» (ведёт в узел того же кластера —
	 * такому паз не режем), длина, азимут и наклон.
	 *
	 * Азимут и наклон нужны чертежу узла: печатник смотрит на деталь сверху и
	 * должен видеть, под каким углом уходит луч, а не пересчитывать косинусы.
	 */
	protected static function arms_of(array $geom, array $dims)
	{
		$arms = [];

		foreach ($geom['ids'] as $i => $id)
		{
			$arms[$i] = [];
		}

		foreach ($geom['edges'] as $e)
		{
			foreach ([[$e['a'], $e['b']], [$e['b'], $e['a']]] as $pair)
			{
				list($from, $to) = $pair;
				$p = $geom['mm'][$from];
				$q = $geom['mm'][$to];
				$d = [$q[0] - $p[0], $q[1] - $p[1], $q[2] - $p[2]];
				$len = sqrt($d[0] * $d[0] + $d[1] * $d[1] + $d[2] * $d[2]);
				$plan = sqrt($d[0] * $d[0] + $d[1] * $d[1]);

				$arms[$from][] = [
					'to'   => $to,
					'dir'  => $d,
					'len'  => self::round($len, 1),
					'az'   => self::round(rad2deg(atan2($d[1], $d[0])), 1),
					'inc'  => self::round($plan < 1e-9 ? 90.0 : rad2deg(atan2($d[2], $plan)), 1),
				];
			}
		}

		return $arms;
	}

	/** Угол между двумя лучами (градусы, 0.5° — та же сетка, что в наработках). */
	protected static function angle($a, $b)
	{
		$na = sqrt($a[0] * $a[0] + $a[1] * $a[1] + $a[2] * $a[2]);
		$nb = sqrt($b[0] * $b[0] + $b[1] * $b[1] + $b[2] * $b[2]);

		if ($na < 1e-9 OR $nb < 1e-9)
		{
			return 0.0;
		}

		$c = ($a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2]) / ($na * $nb);
		$c = max(-1.0, min(1.0, $c));

		return round(rad2deg(acos($c)) * 2) / 2;
	}

	/**
	 * Сигнатура узла: сколько лучей + отсортированный набор углов между ними.
	 *
	 * Поворот узла сигнатуру не меняет (углы инвариантны), поэтому зеркало и
	 * отражение считаются ОДНИМ типом детали — ровно то, что нужно печати.
	 */
	protected static function signature(array $arms, $min_angle)
	{
		$n = count($arms);
		$angles = [];

		for ($i = 0; $i < $n - 1; $i++)
		{
			for ($j = $i + 1; $j < $n; $j++)
			{
				$angles[] = self::angle($arms[$i]['dir'], $arms[$j]['dir']);
			}
		}

		sort($angles);

		$min = $angles ? min($angles) : 999.0;
		$sig = $n.':'.implode(';', array_map(function ($v) { return self::round($v, 1); }, $angles));

		return [
			'sig'     => $sig,
			'arms'    => $n,
			'arms_deg'=> $angles,
			'min'     => self::round($min, 1),
			'narrow'  => ($n > 1 AND $min < (float) $min_angle) ? TRUE : FALSE,
		];
	}

	// -------------------------------------------------------------- кластеры

	/**
	 * Порог «короткой палочки»: свободная длина меньше него — концы узлов
	 * сращиваются и в геометрии, и в замечаниях (одна точка правды).
	 *
	 * Не меньше min_span, и не меньше ширины луча соединителя: палочка короче
	 * собственной ширины на виде не читается как отдельная связь, узлы стоят
	 * вплотную и выглядят соединёнными.
	 */
	protected static function short_span(array $opt, array $dims)
	{
		return max((float) Arr::get($opt, 'min_span', 5.0), (float) Arr::get($dims, 'bb_w', 0.0));
	}

	/**
	 * Сращивание узлов: union-find по двум условиям (только при merge = 1).
	 *
	 * 1. Пересечение: центры ближе 2·r_in + merge_gap — «юбки» задевают друг
	 *    друга, две детали физически невозможны, печатаются одним объектом.
	 * 2. Короткая палочка: между узлами связь, а свободной длины меньше
	 *    short_span() (расстояние − 2·r_in < max(min_span, ширина луча)). Деталь с такой палочкой
	 *    не сойдётся по высоте — значит узлы срастаем, а не предупреждаем.
	 *    Раньше это правило давало только замечание, и узлы оставались
	 *    отдельными деталями, которые не собираются.
	 *
	 * Метки плотные (0..k-1) и идут по первому появлению: их видит и .scad, и
	 * спецификация, и порядок должен совпадать.
	 */
	protected static function clusters(array $geom, array $opt, array $dims)
	{
		$n     = count($geom['mm']);
		$limit = $opt['merge'] ? (2 * (float) $dims['r_in'] + (float) $opt['merge_gap']) : 0.0;
		$parent = range(0, $n - 1);

		$find = function ($x) use (&$find, &$parent)
		{
			while ($parent[$x] != $x)
			{
				$parent[$x] = $parent[$parent[$x]];
				$x = $parent[$x];
			}

			return $x;
		};

		$join = function ($x, $y) use (&$find, &$parent)
		{
			$ra = $find($x);
			$rb = $find($y);

			if ($ra != $rb)
			{
				$parent[max($ra, $rb)] = min($ra, $rb);
			}
		};

		if ($limit > 0)
		{
			for ($i = 0; $i < $n; $i++)
			{
				for ($j = $i + 1; $j < $n; $j++)
				{
					$dx = $geom['mm'][$i][0] - $geom['mm'][$j][0];
					$dy = $geom['mm'][$i][1] - $geom['mm'][$j][1];
					$dz = $geom['mm'][$i][2] - $geom['mm'][$j][2];

					if (sqrt($dx * $dx + $dy * $dy + $dz * $dz) < $limit)
					{
						$join($i, $j);
					}
				}
			}

			// Короткая палочка: та же посадка, что и в issues() — расстояние минус
			// 2·r_in. Связь, которая короче min_span свободной, склеивает концы.
			$span = self::short_span($opt, $dims);

			foreach ($geom['edges'] as $e)
			{
				$a = (int) $e['a'];
				$b = (int) $e['b'];

				if ($a === $b)
				{
					continue;
				}

				$dx = $geom['mm'][$a][0] - $geom['mm'][$b][0];
				$dy = $geom['mm'][$a][1] - $geom['mm'][$b][1];
				$dz = $geom['mm'][$a][2] - $geom['mm'][$b][2];
				$free = sqrt($dx * $dx + $dy * $dy + $dz * $dz) - 2 * (float) $dims['r_in'];

				if ($free < $span)
				{
					$join($a, $b);
				}
			}
		}

		$labels = [];
		$dense  = [];

		for ($i = 0; $i < $n; $i++)
		{
			$root = $find($i);

			if ( ! isset($dense[$root]))
			{
				$dense[$root] = count($dense);
			}

			$labels[$i] = $dense[$root];
		}

		return $labels;
	}

	/** Габарит кластера с посадкой — шаг раскладки деталей на столе. */
	protected static function cl_size(array $geom, array $opt, array $dims, array $members)
	{
		$span = 0.0;

		foreach ($members as $a)
		{
			foreach ($members as $b)
			{
				if ($a === $b)
				{
					continue;
				}

				$dx = $geom['mm'][$a][0] - $geom['mm'][$b][0];
				$dy = $geom['mm'][$a][1] - $geom['mm'][$b][1];
				$dz = $geom['mm'][$a][2] - $geom['mm'][$b][2];
				$span = max($span, sqrt($dx * $dx + $dy * $dy + $dz * $dz));
			}
		}

		return $span + (float) $dims['core_size'] + 2 * (float) $opt['insert'];
	}

	// ------------------------------------------------------------- замечания

	/**
	 * Проверка собираемости — то, из-за чего конструкция «есть на экране, но
	 * её не собрать». Уровень два: `warn` (напечатать можно, но будут
	 * поддержки/щель) и `note` (просто объяснение, почему узлов меньше, чем
	 * деталей). Ошибкой помечается только то, что уже вернуло geometry ok=FALSE.
	 */
	protected static function issues(array $geom, array $opt, array $sigs)
	{
		$out  = [];
		$min  = (float) $opt['min_angle'];
		$span = self::short_span($opt, (array) Arr::get($geom, 'dims', []));

		foreach ($geom['ids'] as $i => $id)
		{
			$s = $sigs[$i];

			if ($s['narrow'])
			{
				$out[] = ['level' => 'warn', 'text' => 'Узел '.$id.': угол между лучами '.$s['min'].'° — меньше '.$min
					.'°. Пазы пересекутся, стенка между ними тоньше нуля: раздвиньте узлы или уберите один луч.'];
			}
		}

		foreach ($geom['edges'] as $e)
		{
			$external = $geom['cluster'][$e['a']] != $geom['cluster'][$e['b']];

			if ($e['len'] < 0.001)
			{
				$out[] = ['level' => 'warn', 'text' => 'Узлы '.$geom['ids'][$e['a']].' и '.$geom['ids'][$e['b']]
					.' стоят в одной точке — палочки между ними нет.'];
			}
			elseif ($external AND (float) $e['free'] < $span)
			{
				$out[] = ['level' => 'warn', 'text' => 'Между '.$geom['ids'][$e['a']].' и '.$geom['ids'][$e['b']]
					.' остаётся '.$e['free'].' мм свободной палочки — минимум '.$span
					.' мм: деталь войдёт в пазы вплотную и не сойдётется по высоте.'];
			}
		}

		// Сколько узлов уехало в общие детали — человек должен видеть, что
		// «7 узлов» и «5 деталей» это не расхождение, а склейка.
		foreach ($geom['clusters'] as $c)
		{
			if (count($c['nodes']) > 1)
			{
				$out[] = ['level' => 'note', 'text' => 'Узлы '.implode(', ', $c['ids'])
					.' пересекаются или стоят вплотную (палочка между ними короче '.$span
					.' мм): печатаются одной деталью (пазы внутри общие).'];
			}
		}

		$parts = self::components($geom);

		if ($parts > 1)
		{
			$out[] = ['level' => 'warn', 'text' => 'Конструкция распалась на '.$parts
				.' несвязанных частей: это '.$parts.' отдельных изделия, а не одно.'];
		}

		return $out;
	}

	/** Сколько несвязных кусков в графе (BFS по рёбрам). */
	protected static function components(array $geom)
	{
		$n = count($geom['ids']);

		if ($n === 0)
		{
			return 0;
		}

		$adj = [];

		foreach ($geom['edges'] as $e)
		{
			$adj[$e['a']][] = $e['b'];
			$adj[$e['b']][] = $e['a'];
		}

		$seen = array_fill(0, $n, FALSE);
		$parts = 0;

		for ($start = 0; $start < $n; $start++)
		{
			if ($seen[$start])
			{
				continue;
			}

			$parts++;
			$queue = [$start];
			$seen[$start] = TRUE;

			while ($queue)
			{
				$v = array_shift($queue);

				foreach ((array) Arr::get($adj, $v, []) as $w)
				{
					if ( ! $seen[$w])
					{
						$seen[$w] = TRUE;
						$queue[] = $w;
					}
				}
			}
		}

		return $parts;
	}

	// ----------------------------------------------------------------- OpenSCAD

	/**
	 * Какие части библиотеки нужны этой сборке (в порядке склейки).
	 *
	 * Файлы не подключаются `use <>`: воркер разрешает include только из BOSL2
	 * (белый список, чтобы человек не читал файлы хоста), поэтому PHP склеивает
	 * сам. Порядок фиксированный: параметры → тип узла → математика → коннектор
	 * → генератор → данные.
	 */
	public static function scad_parts($type)
	{
		$row = self::type($type);

		return array_values(array_filter([
			(string) Arr::get($row, 'scad', ''),
			'core/math.scad',
			'core/connector.scad',
			'generators/assembly.scad',
		]));
	}

	/** Чего не хватает в scad-библиотеке (пусто = всё на месте). */
	public static function scad_missing($type = NULL)
	{
		$dir = self::scad_dir();
		$miss = [];

		foreach (self::scad_parts($type === NULL ? 'flat' : $type) as $rel)
		{
			if ($dir === '' OR strpos($rel, '..') !== FALSE OR ! is_file($dir.'/'.$rel))
			{
				$miss[] = $rel;
			}
		}

		return $miss;
	}

	public static function scad_ready($type = NULL)
	{
		return self::scad_missing($type) === [];
	}

	/**
	 * Готовый .scad сборки: параметры + тип узла + ядро + данные графа.
	 *
	 * Пустая строка — геометрия не готова или библиотеки нет (см. scad_missing():
	 * это не «стиль не подошёл», а сломанная установка, и сказать надо прямо).
	 */
	public static function scad(array $geom, array $opt, $note = '')
	{
		if (empty($geom['ok']))
		{
			return '';
		}

		$dir  = self::scad_dir();
		$body = '';

		foreach (self::scad_parts($opt['type']) as $rel)
		{
			$file = $dir.'/'.$rel;

			if (strpos($rel, '..') !== FALSE OR ! is_file($file))
			{
				return '';
			}

			$body .= "\n// ————— ".basename(dirname($rel)).'/'.basename($rel)." —————\n"
				.rtrim((string) file_get_contents($file), "\n")."\n";
		}

		$head = "// ============================================================\n"
			."// n2j.ru · «Графовый 3D-конструктор» — сборка графа в изделие.\n"
			.'// Сгенерировано '.date('d.m.Y H:i').($note !== '' ? ' · '.str_replace(["\r", "\n"], ' ', $note) : '')."\n"
			."// Править здесь бессмысленно: источник правды — граф на сайте.\n"
			."// Единицы чертежа — миллиметры (1 клетка графа = "
			.self::scad_num((float) $opt['unit_size']).' мм).\n'
			."// ============================================================\n";

		return $head.self::scad_params($opt).$body.self::scad_data($geom);
	}

	/** Параметры: только белые списки конфига (тип и ключи), числа — как есть. */
	protected static function scad_params(array $opt)
	{
		$lines = [
			'',
			'// --- режим и параметры (выбор человека на странице сборки) --------',
			'mode        = "'.(string) $opt['mode'].'";',
			'part_index  = '.(int) $opt['part'].';',
			'show_sticks = '.(empty($opt['show_sticks']) ? 'false' : 'true').';',
			'perforation = '.(empty($opt['perforation']) ? 'false' : 'true').';',
			'stick_type  = "'.(string) $opt['type'].'";',
		];

		foreach ((array) $opt['params'] as $name => $value)
		{
			if (preg_match('~^[a-z][a-z0-9_]*$~', (string) $name) !== 1)
			{
				continue;
			}

			$lines[] = $name.' = '.self::scad_num($value).';';
		}

		$lines[] = '$fn         = '.(int) $opt['fn'].';';

		return implode("\n", $lines)."\n";
	}

	/**
	 * Данные графа. CL/CTYPE/UNIQ считает PHP — scad обязан рисовать РОВНО то,
	 * что посчитал сайт: тогда «25 шт.» в спецификации и 25 деталей в STL
	 * расходятся только вместе с багом, а не независимо.
	 */
	protected static function scad_data(array $geom)
	{
		$nodes = [];
		$edges = [];

		foreach ((array) $geom['mm'] as $p)
		{
			$nodes[] = '['.self::scad_num($p[0]).', '.self::scad_num($p[1]).', '.self::scad_num($p[2]).']';
		}

		foreach ((array) $geom['edges'] as $e)
		{
			$edges[] = '['.(int) $e['a'].', '.(int) $e['b'].']';
		}

		$cl = [];

		foreach ((array) $geom['cluster'] as $i => $label)
		{
			$cl[$i] = (int) $label;
		}

		$ctype = [];
		$max   = $cl ? max($cl) : -1;

		for ($r = 0; $r <= $max; $r++)
		{
			$ctype[$r] = 0;
		}

		foreach ((array) $geom['clusters'] as $c)
		{
			$ctype[(int) $c['label']] = (int) Arr::get($c, 'type', 0);
		}

		$uniq = [];

		foreach ((array) $geom['types'] as $t)
		{
			$uniq[] = (int) $t['rep'];
		}

		return "\n// --- данные: узлы в мм (вверх — Z), связи, кластеры, типы ------\n"
			.'NODES = ['.implode(', ', $nodes)."];\n"
			.'EDGES = ['.implode(', ', $edges)."];\n"
			.'CL    = ['.implode(', ', $cl)."];\n"
			.'CTYPE = ['.implode(', ', $ctype)."];\n"
			.'UNIQ  = ['.implode(', ', $uniq)."];
";
	}

	// ------------------------------------------------------ узлы к печати

	/**
	 * План «узлов к печати»: один STL на уникальный тип детали.
	 *
	 * Порядок тот же, что и в спецификации (`types[]` = ksort по сигнатуре),
	 * поэтому part_index на странице, `UNIQ[part]` в scad и `#0;4;…` в CSV —
	 * про одну и ту же деталь. Копии множит слайсер: в файл кладём ОДНУ, так
	 * лист раскроя и STL не расходятся, когда человек решает печатать шесть.
	 *
	 * `$done` — индексы типов, STL которых уже лежит на диске. Вычитать их
	 * нужно ДО обрезка по `$limit`, а не после: иначе окно пачки навечно
	 * застревает на первых `parts_max` типах, «осталось 19» не сокращается, а
	 * кнопка отвечает «все узлы уже собраны» (собственно жалоба владельца).
	 */
	public static function parts_plan(array $geom, array $opt, $limit = 0, array $done = [])
	{
		$plan  = [];
		$types = (array) Arr::get($geom, 'types', []);
		$todo  = [];
		$have  = [];

		foreach ($types as $t)
		{
			if (isset($done[(int) $t['i']]))
			{
				$have[] = (int) $t['i'];

				continue;
			}

			$todo[] = $t;
		}

		foreach ($todo as $t)
		{
			$plan[] = [
				'part'    => (int) $t['i'],
				'type'    => (string) Arr::get($opt, 'type', 'flat'),
				'title'   => self::type_title((string) Arr::get($opt, 'type', 'flat')),
				'copies'  => max(1, (int) $t['count']),
				'slots'   => (int) $t['slots'],
				'arms'    => count((array) $t['arms']),
				'size'    => (float) $t['size'],
			];

			if ($limit > 0 AND count($plan) >= $limit)
			{
				break;
			}
		}

		return ['parts' => $plan, 'total' => count($types), 'done' => count($have),
			'skipped' => max(0, count($todo) - count($plan))];
	}

	/**
	 * Оставить в плане только узлы, закреплённые за одним запуском подготовки.
	 * Автопродолжение повторно планирует геометрию, но не должно добавлять в
	 * цепочку новые part_index после успешных ранних рендеров.
	 */
	public static function parts_run_window(array $plan, array $run_parts)
	{
		$allowed = [];

		foreach ($run_parts as $part)
		{
			$allowed[(int) $part] = TRUE;
		}

		$parts = [];

		foreach ((array) Arr::get($plan, 'parts', []) as $part)
		{
			if (isset($allowed[(int) Arr::get($part, 'part', -1)]))
			{
				$parts[] = $part;
			}
		}

		$plan['parts']   = $parts;
		$plan['skipped'] = max(0, (int) Arr::get($plan, 'total', 0)
			- (int) Arr::get($plan, 'done', 0) - count($parts));

		return $plan;
	}

	/** .scad ОДНОЙ детали (режим single в generators/assembly.scad). */
	public static function scad_for_part(array $geom, array $opt, $part, $note = '')
	{
		$opt['mode'] = 'single';
		$opt['part'] = max(0, (int) $part);

		return self::scad($geom, $opt, $note);
	}

	// ------------------------------------------------------------- спецификация

	/**
	 * Спецификация сборки (BOM) — CSV для Excel/Stan.
	 *
	 * Точка с запятой и запятая в числе — как в чертеже (русская Excel-локаль),
	 * BOM в начале — чтобы кириллица не съехалась в кракозябры.
	 */
	public static function spec_csv(array $geom, array $opt)
	{
		$rows = ["\xEF\xBB\xBFСборка графа — спецификация"];
		$rows[] = 'Тип узла;'.self::type_title($opt['type']);
		$rows[] = 'Масштаб;1 клетка графа = '.self::num((float) $opt['unit_size']).' мм'
			.( ! empty($opt['merge']) ? ';узлы сращиваются' : ';каждый узел — отдельная деталь');

		if ( ! empty($geom['ok']))
		{
			$rows[] = 'Габарит;'.self::num((float) $geom['span']['x']).' × '.self::num((float) $geom['span']['y'])
				.' × '.self::num((float) $geom['span']['z']).' мм';
			$rows[] = 'Узлов;'.$geom['counts']['nodes'].';Связей;'.$geom['counts']['edges']
				.';Деталей;'.$geom['counts']['clusters'].';Уникальных;'.$geom['counts']['types'];

			$rows[] = '';
			$rows[] = 'ДЕТАЛИ НА ПЕЧАТЬ';
			$rows[] = 'Тип;Копий;Лучей;Пазов;Мин. угол, °;Габарит, мм;Узлы';

			foreach ((array) $geom['types'] as $t)
			{
				$rows[] = '#'.(int) $t['i'].';'.(int) $t['count'].';'.count((array) $t['arms']).';'.(int) $t['slots']
					.';'.self::num((float) $t['min_angle']).';'.self::num((float) $t['size'])
					.';'.self::cell(implode(' ', (array) $t['nodes']));
			}

			$rows[] = '';
			$rows[] = 'ПАЛОЧКИ (лист раскроя)';
			$rows[] = 'Длина, мм;Сколько';

			foreach ((array) $geom['sticks'] as $s)
			{
				$rows[] = self::num((float) $s['len']).';'.(int) $s['count'];
			}

			$rows[] = 'ИТОГО;'.$geom['counts']['sticks'].' шт.;'.self::num((float) $geom['counts']['meters']).' м';
		}

		$rows[] = '';
		$rows[] = 'ЗАМЕЧАНИЯ';

		foreach ((array) $geom['issues'] as $issue)
		{
			$rows[] = Arr::get($issue, 'level', 'note').';'.str_replace(["\r", "\n", ';'], [' ', ' ', ','], (string) Arr::get($issue, 'text', ''));
		}

		if ( ! $geom['issues'])
		{
			$rows[] = 'ok;замечаний нет — собирается без подгонок';
		}

		return implode("\n", $rows)."\n";
	}

	/** Ячейка CSV: текст пользователя не должен разорвать строку или столбец. */
	protected static function cell($text)
	{
		return str_replace(["\r", "\n", ';'], [' ', ' ', ','], (string) $text);
	}

	/** Число для CSV/экранов: ноль после запятой не нужен, разделитель — запятая. */
	public static function num($value, $dec = 1)
	{
		$s = number_format((float) $value, (int) $dec, '.', '');

		if ($dec > 0)
		{
			$s = rtrim(rtrim($s, '0'), '.');
		}

		return str_replace('.', ',', $s);
	}

	/**
	 * Чертёж уникального узла: план в масштабе, изометрия, сечение А–А и
	 * основная надпись (лист A4 альбомный).
	 *
	 * Зачем отдельно от STL: по STL печатают, по чертежу проверяют — под каким
	 * углом уходит луч и какой ширины паз, если принтер «съел» полмиллиметра.
	 * Углы берёт та же геометрия, что и .scad, поэтому лист и модель не спорят.
	 *
	 * Владелец (13.10.2026): «чертёж сделай понятным, в идеале по ГОСТ — не
	 * хватает измерений и изометрии». Отсюда четыре правила, и каждое что-то
	 * запрещает:
	 *
	 *   * план — в масштабе: длина и ТОЛЩИНА луча берутся из геометрии, а не
	 *     «40 + 105 · len/max» (красивый плакат, по которому ничего не
	 *     проверить);
	 *   * размеры — выносные линии, стрелки, число над линией (ГОСТ 2.304);
	 *   * изометрия (ГОСТ 2.317) — иначе «луч вверх» и «луч в стол» на плане
	 *     неотличимы, а это ровно то, что портит печать;
	 *   * штамп — масштаб, количество копий, допуск и дата: лист без штампа в
	 *     цеху не документ.
	 *
	 * Паз показан у торца луча схематично: в изделии его режет scad-файл
	 * сечения, и «красивая» форма здесь спорнила бы с моделью.
	 */
	public static function part_svg(array $geom, array $opt, $t)
	{
		$type = (array) Arr::get($geom['types'], (int) $t);

		if ( ! $type OR empty($geom['ok']))
		{
			return '';
		}

		// Представитель типа — первый узел кластера-представителя.
		$node = (int) Arr::get((array) Arr::get((array) $geom['clusters'], 0, []), 'nodes', [0])[0] ?? 0;

		foreach ((array) $geom['clusters'] as $c)
		{
			if ((int) Arr::get($c, 'label') === (int) $type['rep'])
			{
				$node = (int) $c['nodes'][0];

				break;
			}
		}

		$arms  = (array) Arr::get($geom['arms'], $node, []);
		$dims  = (array) $geom['dims'];
		$title = self::type_title($opt['type']);
		$ink   = '#0f172a';
		$muted = '#64748b';
		$light = '#94a3b8';
		$parts = [];

		// Лист 900×636 px = 297×210 мм: 1 мм листа = 3,0303 px. Из этого числа
		// растёт и штамповой «Масштаб», поэтому врать в нём не выйдет.
		$pxm = 900.0 / 297.0;

		$parts[] = '<rect x="0" y="0" width="900" height="636" fill="#ffffff"/>';
		// Рамка: поле слева 20 мм, остальные — 5 мм (ГОСТ 2.301).
		$parts[] = '<rect x="'.self::scad_num(20 * $pxm).'" y="'.self::scad_num(5 * $pxm)
			.'" width="'.self::scad_num(900 - 25 * $pxm).'" height="'.self::scad_num(636 - 10 * $pxm)
			.'" fill="none" stroke="'.$ink.'" stroke-width="1.6"/>';

		// --- лучи: план-проекция, наклон и настоящие длины ---------------------
		$rays   = [];
		$reach  = 1.0;
		$preach = 1.0;

		foreach ($arms as $a)
		{
			$d = (array) Arr::get($a, 'dir', [0, 0, 0]);
			$x = (float) Arr::get($d, 0, 0);
			$y = (float) Arr::get($d, 1, 0);
			$z = (float) Arr::get($d, 2, 0);

			$rays[] = [
				'x'    => $x,
				'y'    => $y,
				'z'    => $z,
				'plan' => sqrt($x * $x + $y * $y),
				'len'  => (float) Arr::get($a, 'len', 0),
				'az'   => (float) Arr::get($a, 'az', 0),
				'inc'  => (float) Arr::get($a, 'inc', 0),
				'to'   => (int) Arr::get($a, 'to', 0),
				'slot' => empty($a['internal']),
			];

			$reach  = max($reach, max(abs($x), abs($y), abs($z)));
			$preach = max($preach, sqrt($x * $x + $y * $y));
		}

		// План и изометрия масштабируются раздельно: у узла с вертикальными
		// лучами план-проекция в разы меньше 3D-размаха, и общий масштаб
		// превратил бы вид сверху в точку. Каждый вид подписан своим масштабом
		// (ГОСТ 2.302), в штампе — масштаб плана. 6 px/мм — потолок.
		$kp = min(118.0 / max(12.0, $preach), 6.0);
		$ki = min(104.0 / max(12.0, $reach), 6.0);
		$k  = $kp;
		// Клетка: без неё надпись «1 клетка = 10 мм» — обещание, а не факт,
		// поэтому в шапку она попадает только когда сетка действительно есть.
		$cell = max(1.0, (float) $opt['unit_size']) * $kp;
		$grid = ($cell >= 6.0);
		$core = (float) Arr::get($dims, 'core_size', 0);
		$ow   = (float) $dims['outer_w'];
		$ot   = (float) $dims['outer_t'];
		$sw   = (float) $dims['slot_w'];
		$st   = (float) $dims['slot_t'];

		$parts[] = '<text x="40" y="72" font-size="16" font-weight="700" fill="'.$ink.'">Узел #'
			.(int) $type['i'].' — '.(int) $type['count'].' шт.</text>';
		$parts[] = '<text x="40" y="92" font-size="12" fill="'.$muted.'">'.self::esc($title)
			.' · лучей '.count($rays).' · пазов '.(int) $type['slots']
			.' · корпус '.self::num($core, 2).' мм · паз '.self::num($sw, 2).' × '.self::num($st, 2)
			.' мм'.($grid ? ' · 1 клетка = '.self::num((float) $opt['unit_size'], 0).' мм' : '').'</text>';

		// Центр плана поднят: под ним ещё сечение А–А, и на тесном листе A4
		// знаки разреза иначе ложатся прямо на его заголовок.
		$cx = 250.0;
		$cy = 268.0;

		$parts[] = '<text x="40" y="124" font-size="12" font-weight="600" fill="'.$ink.'">Вид сверху (план)'
			.($kp > 0 ? ' 1:'.self::num($pxm / $kp, 1) : '').'</text>';

		// Сетка — паттерном, а не тысячей <rect>: чертёж открывают в браузере,
		// и он не должен собираться секунду.
		if ($grid)
		{
			$parts[] = '<defs><pattern id="nj667-grid" width="'.self::scad_num($cell).'" height="'
				.self::scad_num($cell).'" patternUnits="userSpaceOnUse" x="'.self::scad_num($cx)
				.'" y="'.self::scad_num($cy).'"><path d="M '.self::scad_num($cell).' 0 L 0 0 0 '.self::scad_num($cell)
				.'" fill="none" stroke="#eef2f7" stroke-width="1"/></pattern></defs>';
			// Клетка не должна вылезать за рамку листа — иначе чертёж выглядит
			// обрезанным ещё в браузере.
			$parts[] = '<rect x="64" y="140" width="372" height="252" fill="url(#nj667-grid)"'
				.' stroke="none"/>';
			$parts[] = '<rect x="64" y="140" width="372" height="252" fill="none" stroke="#e2e8f0"/>';
		}

		// Окружность по кончику самого длинного луча: глаз сравнивает лучи по
		// ней, а не на глаз.
		$farest = 0.0;

		foreach ($rays as $ray)
		{
			$farest = max($farest, $ray['plan']);
		}

		if ($farest > 0)
		{
			$parts[] = '<circle cx="'.self::scad_num($cx).'" cy="'.self::scad_num($cy).'" r="'
				.self::scad_num($farest * $k).'" fill="none" stroke="'.$light.'" stroke-dasharray="5 4"/>';
		}

		$parts[] = '<circle cx="'.self::scad_num($cx).'" cy="'.self::scad_num($cy).'" r="'
			.self::scad_num(max(5.0, $core / 2 * $k)).'" fill="#eef2ff" stroke="'.$muted.'"/>';

		foreach ($rays as $i => $ray)
		{
			$ang = atan2(-$ray['y'], $ray['x']);
			$ex  = $cx + cos($ang) * $ray['plan'] * $k;
			$ey  = $cy + sin($ang) * $ray['plan'] * $k;
			$hw  = max(1.6, $ot / 2 * $k);

			$parts[] = self::_svg_bar($cx, $cy, $ex, $ey, $hw, $ray['slot'] ? '#f1f5f9' : '#e2e8f0', $ink);

			if ($ray['slot'])
			{
				$parts[] = self::_svg_slot($ex, $ey, $ang, max(2.0, $sw * $k), max(1.4, $st * $k), $muted);
			}

			if ($ray['plan'] * $k < 9.0)
			{
				// Луч уходит почти вертикально: на плане это точка, и без
				// шпурка человек решил бы, что луча нет вовсе.
				$parts[] = '<line x1="'.self::scad_num($cx).'" y1="'.self::scad_num($cy).'" x2="'
					.self::scad_num($cx).'" y2="'.self::scad_num($cy - 26).'" stroke="'.$ink
					.'" stroke-width="1.2" stroke-dasharray="3 3"/>';
				$ex = $cx;
				$ey = $cy - 26;
			}

			$parts[] = '<circle cx="'.self::scad_num($ex).'" cy="'.self::scad_num($ey).'" r="2.6" fill="'.$ink.'"/>';
			$la = atan2($ey - $cy, $ex - $cx);
			$lax = cos($la) * 12;
			$lay = sin($la) * 12;

			$parts[] = '<text x="'.self::scad_num($ex + $lax).'" y="'.self::scad_num($ey + $lay + 3)
				.'" font-size="10" fill="'.$ink.'"'
				.($lax > 3 ? ' text-anchor="start"' : ($lax < -3 ? ' text-anchor="end"' : ' text-anchor="middle"')).'>'
				.($i + 1).' · '.self::esc((string) Arr::get((array) $geom['ids'], $ray['to'], (string) $ray['to']))
				.(abs($ray['inc']) > 0.05 ? ' ∠'.self::num($ray['inc']).'°' : '')
				.($ray['slot'] ? '' : ' (внутр.)').'</text>';

			// Число — ДЛИНА ЛУЧА В 3D, а не её план-проекция: печатают-то по
			// реальной длине, а проекция короче на cos наклона. Линию уводим в
			// сторону, противоположную знакам разреза А–А, иначе они сливаются.
			$parts[] = self::_svg_dim($cx, $cy, $ex, $ey, self::num($ray['len'], 1), -(19 + $hw), $ink);
		}

		$parts[] = self::_svg_arcs($cx, $cy, $rays, min(44.0, 18.0 + $core * $k * 0.5), $muted);

		// --- изометрия --------------------------------------------------------
		$ix = 640.0;
		$iy = 250.0;

		$parts[] = '<text x="500" y="124" font-size="12" font-weight="600" fill="'.$ink.'">Изометрия (X, Y — план, Z — вверх) 1:'
			.self::num($pxm / $ki, 1).'</text>';
		$parts[] = '<ellipse cx="'.self::scad_num($ix).'" cy="'.self::scad_num($iy).'" rx="'
			.self::scad_num(max(5.0, $core / 2 * $ki * 1.7321)).'" ry="'
			.self::scad_num(max(3.0, $core / 2 * $ki)).'" fill="#eef2ff" stroke="'.$muted.'"/>';

		foreach ($rays as $ray)
		{
			$p = self::_svg_iso($ray['x'], $ray['y'], $ray['z'], $ix, $iy, $ki);

			$parts[] = '<line x1="'.self::scad_num($ix).'" y1="'.self::scad_num($iy).'" x2="'
				.self::scad_num($p[0]).'" y2="'.self::scad_num($p[1]).'" stroke="'.$ink
				.'" stroke-width="'.self::scad_num(max(2.0, $ot * $ki * 0.8)).'" stroke-linecap="round"/>';
			$parts[] = '<circle cx="'.self::scad_num($p[0]).'" cy="'.self::scad_num($p[1]).'" r="2.2" fill="'.$ink.'"/>';
		}

		// Тройник осей: без него «луч вверх» и «луч в стол» — две палки.
		foreach ([['X', 20, 0, 0], ['Y', 0, 20, 0], ['Z', 0, 0, 20]] as $axis)
		{
			$p = self::_svg_iso($axis[1], $axis[2], $axis[3], $ix, $iy, 1.0);

			$parts[] = '<line x1="'.self::scad_num($ix).'" y1="'.self::scad_num($iy).'" x2="'
				.self::scad_num($p[0]).'" y2="'.self::scad_num($p[1]).'" stroke="'.$muted
				.'" stroke-dasharray="4 3"/>';
			$parts[] = '<text x="'.self::scad_num($ix + ($p[0] - $ix) * 1.28 - 3).'" y="'
				.self::scad_num($iy + ($p[1] - $iy) * 1.28 + 3).'" font-size="10" fill="'.$muted.'">'
				.$axis[0].'</text>';
		}

		// Габарит по осям — те три числа, которых на плане не бывает вовсе.
		$ext = ['x' => 0.0, 'y' => 0.0, 'z' => 0.0];

		foreach ($rays as $ray)
		{
			foreach (['x', 'y', 'z'] as $axis_key)
			{
				$ext[$axis_key] = max($ext[$axis_key], abs((float) $ray[$axis_key]));
			}
		}

		$gy = 372.0;

		$shown = [];

		foreach (['X' => $ext['x'] * 2, 'Y' => $ext['y'] * 2, 'Z' => $ext['z'] * 2] as $axis_key => $value)
		{
			if ((float) $value > 0.05)
			{
				$shown[] = $axis_key.' '.self::num((float) $value, 1);
			}
		}

		$parts[] = '<text x="500" y="'.self::scad_num($gy).'" font-size="11" fill="'.$ink.'">Габарит: '
			.($shown ? implode(' · ', $shown).' мм' : 'нет лучей').'</text>';

		// --- сечение А–А: стенка, паз, посадки --------------------------------
		$bx = 250.0;
		$by = 486.0;
		$ks = max($k, min(6.5, 120.0 / max(6.0, $ow)));

		$parts[] = '<text x="150" y="'.self::scad_num($by - 60).'" font-size="12" font-weight="600"'
			.' fill="'.$ink.'">Сечение А–А (увеличено '
			.self::num($ks / $k, 1).'×)</text>';
		$parts[] = '<rect x="'.self::scad_num($bx - $ow / 2 * $ks).'" y="'.self::scad_num($by - $ot / 2 * $ks)
			.'" width="'.self::scad_num($ow * $ks).'" height="'.self::scad_num($ot * $ks)
			.'" fill="url(#nj667-hatch)" stroke="'.$ink.'"/>';
		$parts[] = '<rect x="'.self::scad_num($bx - $sw / 2 * $ks).'" y="'.self::scad_num($by - $st / 2 * $ks)
			.'" width="'.self::scad_num($sw * $ks).'" height="'.self::scad_num($st * $ks)
			.'" fill="#ffffff" stroke="'.$ink.'"/>';

		$parts[] = self::_svg_dim($bx - $ow / 2 * $ks, $by - $ot / 2 * $ks, $bx + $ow / 2 * $ks, $by - $ot / 2 * $ks,
			'стенка наружу '.self::num($ow, 2), -22, $ink);
		$parts[] = self::_svg_dim($bx + $ow / 2 * $ks, $by - $ot / 2 * $ks, $bx + $ow / 2 * $ks, $by + $ot / 2 * $ks,
			self::num($ot, 2), 26, $ink);
		$parts[] = self::_svg_dim($bx - $sw / 2 * $ks, $by + $ot / 2 * $ks, $bx + $sw / 2 * $ks, $by + $ot / 2 * $ks,
			'паз '.self::num($sw, 2), 26, $ink);
		$parts[] = self::_svg_dim($bx - $ow / 2 * $ks, $by - $ot / 2 * $ks, $bx - $ow / 2 * $ks,
			$by - $ot / 2 * $ks + $st * $ks, self::num($st, 2), -34, $muted);
		$parts[] = '<text x="64" y="560" font-size="10" fill="'.$muted.'">стенка '
			.self::num((float) $opt['wall'], 2).' мм · посадка '.self::num((float) $opt['insert'], 1)
			.' мм · упор '.self::num($core / 2, 1).' мм · допуск ±'
			.self::num((float) $opt['tolerance'], 2).' мм</text>';

		// Линия разреза — по самому длинному лучу: без неё «А–А» ни о чём.
		$long = ['plan' => -1.0, 'x' => 0.0, 'y' => 0.0];

		foreach ($rays as $ray)
		{
			if ($ray['plan'] > $long['plan'])
			{
				$long = $ray;
			}
		}

		if ($long['plan'] > 0)
		{
			$ang = atan2(-$long['y'], $long['x']);
			$nx  = cos($ang + M_PI / 2);
			$ny  = sin($ang + M_PI / 2);
			$x1  = $cx - cos($ang) * 18;
			$y1  = $cy - sin($ang) * 18;
			$x2  = $cx + $long['plan'] * $k * cos($ang);
			$y2  = $cy + $long['plan'] * $k * sin($ang);

			foreach ([[$x1, $y1], [$x2, $y2]] as $p)
			{
				$parts[] = '<line x1="'.self::scad_num($p[0] - $nx * 12).'" y1="'.self::scad_num($p[1] - $ny * 12)
					.'" x2="'.self::scad_num($p[0] + $nx * 12).'" y2="'.self::scad_num($p[1] + $ny * 12)
					.'" stroke="'.$ink.'" stroke-width="1.6"/>';
			}

			$parts[] = '<line x1="'.self::scad_num($x1).'" y1="'.self::scad_num($y1).'" x2="'
				.self::scad_num($x2).'" y2="'.self::scad_num($y2).'" stroke="'.$ink
				.'" stroke-dasharray="14 3 2 3"/>';

			foreach ([[$x1, $y1], [$x2, $y2]] as $p)
			{
				$parts[] = '<text x="'.self::scad_num($p[0] + $nx * 20).'" y="'.self::scad_num($p[1] + $ny * 20)
					.'" font-size="12" font-weight="700" fill="'.$ink.'">А</text>';
			}
		}

		// --- таблица лучей ----------------------------------------------------
		$parts[] = '<text x="418" y="402" font-size="12" font-weight="600" fill="'.$ink.'">Лучи от центра корпуса</text>';
		$parts[] = '<text x="418" y="418" font-size="10" fill="'.$muted.'">№ · узел · длина, мм · азимут · наклон, ° · паз</text>';

		foreach (array_slice($rays, 0, 6) as $i => $ray)
		{
			$parts[] = '<text x="418" y="'.self::scad_num(432 + $i * 14).'" font-size="11" fill="'.$ink.'">'
				.($i + 1).' · '.self::esc((string) Arr::get((array) $geom['ids'], $ray['to'], (string) $ray['to']))
				.' · '.self::num($ray['len'], 1).' · '.self::num($ray['az'], 1).' · '.self::num($ray['inc'], 1)
				.' · '.($ray['slot'] ? 'да' : 'нет').'</text>';
		}

		if (count($rays) > 6)
		{
			$parts[] = '<text x="418" y="'.self::scad_num(432 + 6 * 14).'" font-size="11" fill="'.$muted.'">…ещё '
				.(count($rays) - 6).' (полный список — в спецификации CSV)</text>';
		}

		$parts[] = self::_svg_stamp(408.0, 536.0, 474.0, $type, $opt, $k, $pxm, $ink, $muted);

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 636" width="297mm" height="210mm"'
			.' role="img" font-family="ui-sans-serif, system-ui, sans-serif">'
			.'<style>text{font-feature-settings:"tnum"}</style>'
			.'<defs><pattern id="nj667-hatch" width="6" height="6" patternUnits="userSpaceOnUse"'
			.' patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="6" stroke="'.$muted
			.'" stroke-width="0.7"/></pattern></defs>'
			.implode('', $parts).'</svg>';
	}

	/** Проекция изометрии (ГОСТ 2.317): план под 30°, Z — вертикально вверх. */
	protected static function _svg_iso($x, $y, $z, $cx, $cy, $k)
	{
		return [
			$cx + (($x - $y) * 0.8660254) * $k,
			$cy + (($x + $y) * 0.5 - $z) * $k,
		];
	}

	/** Луч на плане: полоса НАСТОЯЩЕЙ толщины — «12 мм» и «2,2 мм» видны разными. */
	protected static function _svg_bar($x1, $y1, $x2, $y2, $half_w, $fill, $stroke)
	{
		$dx  = $x2 - $x1;
		$dy  = $y2 - $y1;
		$len = sqrt($dx * $dx + $dy * $dy);

		if ($len < 0.01)
		{
			return '';
		}

		$nx = -$dy / $len * $half_w;
		$ny = $dx / $len * $half_w;
		$corners = [
			[$x1 + $nx, $y1 + $ny], [$x2 + $nx, $y2 + $ny],
			[$x2 - $nx, $y2 - $ny], [$x1 - $nx, $y1 - $ny],
		];
		$pts = [];

		foreach ($corners as $p)
		{
			$pts[] = self::scad_num($p[0]).','.self::scad_num($p[1]);
		}

		return '<polygon points="'.implode(' ', $pts).'" fill="'.$fill.'" stroke="'.$stroke.'" stroke-width="1.2"/>';
	}

	/** Паз у торца луча: прямоугольник, повёрнутый вместе с лучом. */
	protected static function _svg_slot($x, $y, $ang, $wide, $deep, $stroke)
	{
		return '<g transform="rotate('.self::scad_num(rad2deg($ang)).' '.self::scad_num($x).' '.self::scad_num($y).')">'
			.'<rect x="'.self::scad_num($x - $wide / 2).'" y="'.self::scad_num($y - $deep / 2)
			.'" width="'.self::scad_num($wide).'" height="'.self::scad_num($deep)
			.'" fill="#ffffff" stroke="'.$stroke.'" stroke-dasharray="4 3"/></g>';
	}

	/**
	 * Дуги углов между соседними лучами — прямо на плане.
	 *
	 * Таблица «∠1–2 = 90,0°» была честной и бесполезной: человек ищет угол
	 * МЕЖДУ двумя лучами, которые видит, а не строку справа. Дугу рисуем
	 * полилинией, а не `<path A>`: флаг поворота в SVG смотрит на экранную
	 * систему координат и на виде сверху разъезжается ровно на тех узлах, где
	 * лучей больше двух.
	 */
	protected static function _svg_arcs($cx, $cy, array $rays, $r, $stroke)
	{
		$n = count($rays);

		if ($n < 2)
		{
			return '';
		}

		$out = [];

		$az = [];

		foreach ($rays as $ray)
		{
			$az[] = (float) $ray['az'];
		}

		sort($az);

		for ($i = 0; $i < $n; $i++)
		{
			if ($i + 1 === $n AND $n === 2)
			{
				continue;                       // «обратный» угол на двух лучах — тот же самый
			}

			$a = $az[$i];
			$b = $az[($i + 1) % $n] + ($i + 1 === $n ? 360.0 : 0.0);
			$sweep = $b - $a;

			if ($sweep < 0.6)
			{
				continue;
			}

			$rad = $r + ($i % 2 ? 15.0 : 0.0);
			$steps = max(6, (int) ceil($sweep / 12));
			$poly  = [];

			for ($k = 0; $k <= $steps; $k++)
			{
				$t   = deg2rad($a + $sweep * $k / $steps);
				$poly[] = self::scad_num($cx + cos($t) * $rad).','.self::scad_num($cy - sin($t) * $rad);
			}

			$out_pt = $a + $sweep / 2;

			$arc = '<polyline points="'.implode(' ', $poly).'" fill="none" stroke="'.$stroke.'"/>';

			if ($sweep < 350)
			{
				$arc .= '<text x="'.self::scad_num($cx + cos(deg2rad($out_pt)) * ($rad + 13)).'" y="'
					.self::scad_num($cy - sin(deg2rad($out_pt)) * ($rad + 13) + 3)
					.'" font-size="10" fill="'.$stroke.'" text-anchor="middle">'
					.self::num(min($sweep, 360 - $sweep), 1).'°</text>';
			}

			$out[] = $arc;
		}

		return implode('', $out);
	}

	/**
	 * Размерная линия по ГОСТ 2.304: выносные, сама линия, две стрелки, число
	 * над серединой. $lift — насколько отвести линию от измеряемой базы.
	 */
	protected static function _svg_dim($x1, $y1, $x2, $y2, $text, $lift = 16, $color = '#0f172a')
	{
		$dx  = $x2 - $x1;
		$dy  = $y2 - $y1;
		$len = sqrt($dx * $dx + $dy * $dy);

		if ($len < 0.5)
		{
			return '';
		}

		$ux = $dx / $len;
		$uy = $dy / $len;
		$nx = -$uy;
		$ny = $ux;
		$a1x = $x1 + $nx * $lift;
		$a1y = $y1 + $ny * $lift;
		$a2x = $x2 + $nx * $lift;
		$a2y = $y2 + $ny * $lift;
		$over = 5.0;
		$out = '';

		foreach ([[$x1, $y1, $a1x, $a1y], [$x2, $y2, $a2x, $a2y]] as $e)
		{
			$out .= '<line x1="'.self::scad_num($e[0]).'" y1="'.self::scad_num($e[1]).'" x2="'
				.self::scad_num($e[2] + $nx * $over).'" y2="'.self::scad_num($e[3] + $ny * $over)
				.'" stroke="'.$color.'" stroke-width="0.7"/>';
		}

		$out .= '<line x1="'.self::scad_num($a1x).'" y1="'.self::scad_num($a1y).'" x2="'
			.self::scad_num($a2x).'" y2="'.self::scad_num($a2y).'" stroke="'.$color.'" stroke-width="0.9"/>';
		$out .= self::_svg_arrow($a1x, $a1y, $ux, $uy, $color);
		$out .= self::_svg_arrow($a2x, $a2y, -$ux, -$uy, $color);

		$deg = rad2deg(atan2($dy, $dx));

		if ($deg > 90 OR $deg < -90)
		{
			$deg += 180;
		}

		$mx = ($a1x + $a2x) / 2;
		$my = ($a1y + $a2y) / 2;
		$out .= '<text x="'.self::scad_num($mx + $nx * 5).'" y="'.self::scad_num($my - $ny * 5 - 3)
			.'" font-size="11" fill="'.$color.'" text-anchor="middle" transform="rotate('
			.self::scad_num($deg).' '.self::scad_num($mx + $nx * 5).' '.self::scad_num($my - $ny * 5)
			.')">'.self::esc($text).'</text>';

		return $out;
	}

	/** Стрелка размера: треугольник остриём к измеряемой точке (≈2 мм на листе). */
	protected static function _svg_arrow($x, $y, $ux, $uy, $color)
	{
		$al = 7.0;
		$aw = 2.2;
		$nx = -$uy;
		$ny = $ux;

		return '<polygon points="'.self::scad_num($x).','.self::scad_num($y).' '
			.self::scad_num($x + $ux * $al + $nx * $aw).','.self::scad_num($y + $uy * $al + $ny * $aw).' '
			.self::scad_num($x + $ux * $al - $nx * $aw).','.self::scad_num($y + $uy * $al - $ny * $aw)
			.'" fill="'.$color.'"/>';
	}

	/**
	 * Основная надпись (ГОСТ 2.104, упрощённо): что за деталь, в каком
	 * масштабе, сколько копий и откуда лист. Чертёж узла несут в цех, и без
	 * штампа он картинка; масштаб считаем из реального px/мм листа, а не «1:1
	 * из вежливости».
	 */
	protected static function _svg_stamp($x, $y, $w, array $type, array $opt, $k, $pxm, $ink, $muted)
	{
		$row  = 20.0;
		$rows = 4;
		$cols = [0.30, 0.55, 0.76];
		$h    = $row * $rows;
		$out  = '<rect x="'.self::scad_num($x).'" y="'.self::scad_num($y).'" width="'.self::scad_num($w)
			.'" height="'.self::scad_num($h).'" fill="none" stroke="'.$ink.'" stroke-width="1.2"/>';

		foreach ($cols as $f)
		{
			$out .= '<line x1="'.self::scad_num($x + $w * $f).'" y1="'.self::scad_num($y)
				.'" x2="'.self::scad_num($x + $w * $f).'" y2="'.self::scad_num($y + $h)
				.'" stroke="'.$ink.'" stroke-width="0.7"/>';
		}

		for ($i = 1; $i < $rows; $i++)
		{
			$out .= '<line x1="'.self::scad_num($x).'" y1="'.self::scad_num($y + $row * $i)
				.'" x2="'.self::scad_num($x + $w).'" y2="'.self::scad_num($y + $row * $i)
				.'" stroke="'.$ink.'" stroke-width="0.7"/>';
		}

		$cell = function ($col, $line, $label, $value, $big = FALSE) use ($x, $y, $w, $row, $cols) {
			$from = $col > 0 ? (float) $cols[$col - 1] : 0.0;

			// Значение режем по ширине ячейки: переползти в соседнюю колонку —
			// значит получить «PLA/PETGКачество: medium» вместо двух полей.
			$value = (string) $value;

			if (UTF8::strlen($value) > ($big ? 18 : 16))
			{
				$value = UTF8::substr($value, 0, ($big ? 18 : 16) - 1).'…';
			}

			return '<text x="'.self::scad_num($x + $w * $from + 5).'" y="'
				.self::scad_num($y + $row * $line + ($big ? 14 : 13)).'" font-size="'.($big ? 11 : 9).'"'
				.($big ? ' font-weight="700"' : '').'><tspan fill="#64748b" font-size="7">'.$label.'</tspan> '
				.self::esc($value).'</text>';
		};

		$scale = $k > 0 ? $pxm / $k : 1.0;

		$out .= $cell(0, 0, 'Наименование:', 'узел #'.(int) Arr::get($type, 'i', 0), TRUE);
		$out .= $cell(1, 0, 'Масштаб:', '1:'.self::num($scale, 1));
		$out .= $cell(2, 0, 'Лист:', '1');
		$out .= $cell(3, 0, 'Листов:', '1');
		$out .= $cell(0, 1, 'Кол-во в изделии:', (int) Arr::get($type, 'count', 0).' шт.');
		$out .= $cell(1, 1, 'Пазов:', (int) Arr::get($type, 'slots', 0));
		$out .= $cell(2, 1, 'Материал:', Arr::get($opt, 'material', 'PLA/PETG'));
		$out .= $cell(3, 1, 'Качество:', Arr::get($opt, 'quality', 'low'));
		$out .= $cell(0, 2, 'Сечение:', (string) Arr::get($opt, 'type', 'flat'));
		$out .= $cell(1, 2, 'Допуск паза: ±'.self::num((float) Arr::get($opt, 'tolerance', 0), 2), '');
		$out .= $cell(2, 2, 'Клетка:', self::num((float) Arr::get($opt, 'unit_size', 0), 0).' мм');
		$out .= $cell(3, 2, 'Дата:', date('d.m.Y'));
		$out .= $cell(0, 3, 'Разработал:', 'n2j.ru / graph3d');
		$out .= $cell(1, 3, 'Габарит узла:', self::num((float) Arr::get($type, 'size', 0), 0).' мм');
		$out .= $cell(2, 3, 'Узлы:', implode(' ', array_slice((array) Arr::get($type, 'nodes', []), 0, 3)));
		$out .= $cell(3, 3, 'Мин. угол:', self::num((float) Arr::get($type, 'min_angle', 0), 1).'°');

		return $out;
	}

	// ---------------------------------------------------------------- STL

	/**
	 * STL глазами сайта: треугольники и габарит в мм.
	 *
	 * Зачем: «воркер вернул файл» — ещё не «модель есть». Открытый STL с 0
	 * треугольников или обрезанная выгрузка на 84 байта выглядят как успех и
	 * уезжают в каталог пустой картинкой. Проверка дешёвая, ловит ровно это.
	 *
	 * Бинарный STL: 80 байт заголовок, uint32 количество, дальше по 50 байт на
	 * грань (12 float + uint16 атрибут). ASCII не умеем — OpenSCAD пишет binary.
	 * Большие файлы меряем с шагом, чтобы не перебирать 200 000 граней на странице.
	 */
	public static function stl_stats($bytes)
	{
		$out = ['ok' => FALSE, 'error' => '', 'size' => 0, 'triangles' => 0,
			'min' => [0.0, 0.0, 0.0], 'max' => [0.0, 0.0, 0.0], 'dims' => [0.0, 0.0, 0.0],
			'sampled' => FALSE];

		$bytes = (string) $bytes;
		$out['size'] = strlen($bytes);

		if ($out['size'] < 84)
		{
			$out['error'] = 'Файл '.((int) $out['size']).' байт — короче заголовка STL: выгрузка оборвалась.';

			return $out;
		}

		$head = unpack('Vn', substr($bytes, 80, 4));
		$n    = (int) Arr::get($head, 'n', 0);

		if ($n <= 0)
		{
			$out['error'] = 'В STL нет ни одной грани: модель не собралась (пустой CSG).';

			return $out;
		}

		if (84 + 50 * $n > $out['size'])
		{
			$out['error'] = 'Обрезанный STL: заявлено '.$n.' граней, данных на '
				.(int) ((($out['size'] - 84) / 50)).'.';

			return $out;
		}

		$out['triangles'] = $n;
		$step  = $n > 8000 ? (int) ceil($n / 8000) : 1;
		$out['sampled'] = $step > 1;

		$min = [INF, INF, INF];
		$max = [-INF, -INF, -INF];

		for ($i = 0; $i < $n; $i += $step)
		{
			// 12 float, не 16: формат длиннее данных — unpack вернул бы FALSE,
			// и габарит молча стал бы нулём.
			$f = (array) unpack('f12', substr($bytes, 84 + 50 * $i, 48));

			// unpack даёт ключи с 1: 1–3 нормаль, 4–6 / 7–9 / 10–12 вершины
			for ($v = 4; $v <= 12; $v += 3)
			{
				for ($k = 0; $k < 3; $k++)
				{
					$coord = (float) Arr::get($f, $v + $k, 0.0);
					$min[$k] = min($min[$k], $coord);
					$max[$k] = max($max[$k], $coord);
				}
			}
		}

		$out['min'] = array_map(function ($v) { return self::round($v, 2); }, $min);
		$out['max'] = array_map(function ($v) { return self::round($v, 2); }, $max);
		$out['dims'] = [
			self::round($max[0] - $min[0], 1),
			self::round($max[1] - $min[1], 1),
			self::round($max[2] - $min[2], 1),
		];
		$out['ok'] = TRUE;

		return $out;
	}
}
