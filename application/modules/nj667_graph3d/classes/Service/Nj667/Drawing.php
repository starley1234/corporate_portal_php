<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-667 чертёж изделия: ортогонали, штамп, SVG и CSV из координат графа.
 *
 * Модель для размеров не зовём. Дверь — Service_Nj667_Graph3d::drawing_*
 * (владелец, 11.10.2026).
 */
class Service_Nj667_Drawing
{

	/**
	 * Чертёж для физической реализации (владелец, 11.10.2026).
	 *
	 * Координаты графа — клетки, не метры. Пользователь задаёт, сколько мм/см/м
	 * в одной клетке, и масштаб листа. Размеры считает сайт: модель для чертежа
	 * не зовём, иначе габариты будут выдуманы.
	 */
	public static function drawing_units()
	{
		return [
			'mm' => ['title' => 'миллиметры', 'short' => 'мм', 'to_mm' => 1.0,
				'sizes' => [1, 5, 10, 20, 50], 'hint' => 'мелочь, мебель, узлы'],
			'cm' => ['title' => 'сантиметры', 'short' => 'см', 'to_mm' => 10.0,
				'sizes' => [1, 2, 5, 10], 'hint' => 'бытовые изделия'],
			'm'  => ['title' => 'метры', 'short' => 'м', 'to_mm' => 1000.0,
				'sizes' => [0.1, 0.5, 1, 2, 5], 'hint' => 'каркас, навес, здание'],
		];
	}

	/** Масштабы листа по ГОСТ 2.302; fit — самый крупный из них, что вмещает изделие. */
	public static function drawing_scales()
	{
		$out = ['fit' => 'вписать в лист'];

		foreach (self::_drawing_ladder() as $step)
		{
			$out[$step[1]] = $step[0] < 1 ? $step[1].' (крупнее)' : $step[1];
		}

		return $out;
	}

	/** Нормализовать единицу, клетку и масштаб чертежа. */
	public static function drawing_opt(array $opt = [])
	{
		$units = self::drawing_units();
		$unit  = strtolower(trim((string) Arr::get($opt, 'unit', 'mm')));

		if ( ! isset($units[$unit]))
		{
			$unit = 'mm';
		}

		$raw = str_replace(',', '.', (string) Arr::get($opt, 'unit_size', ''));
		$unit_size = (float) $raw;

		if ($unit_size <= 0)
		{
			$unit_size = ($unit === 'm') ? 0.5 : (($unit === 'cm') ? 2.0 : 10.0);
		}

		$unit_size = max(0.001, min(10000.0, $unit_size));
		$scale     = trim((string) Arr::get($opt, 'scale', 'fit'));

		if ( ! isset(self::drawing_scales()[$scale]))
		{
			$scale = 'fit';
		}

		$paper = strtolower(trim((string) Arr::get($opt, 'paper', 'a4')));

		if ( ! in_array($paper, ['a4', 'a3'], TRUE))
		{
			$paper = 'a4';
		}

		$ids_raw = Arr::get($opt, 'ids', FALSE);
		$ids     = ($ids_raw === TRUE || in_array(strtolower(trim((string) $ids_raw)), ['1', 'on', 'yes', 'true'], TRUE));

		return [
			'unit'      => $unit,
			'unit_size' => $unit_size,
			'scale'     => $scale,
			'paper'     => $paper,
			'title'     => UTF8::substr(trim((string) Arr::get($opt, 'title', '')), 0, 200),
			'code'      => UTF8::substr(trim((string) Arr::get($opt, 'code', '')), 0, 24),
			'ids'       => $ids,
		];
	}

	/** Сохранённый масштаб проекта (settings_json.drawing). */
	public static function drawing_prefs(array $project)
	{
		$settings = json_decode((string) Arr::get($project, 'settings_json', ''), TRUE);
		$saved    = is_array($settings) ? Arr::get($settings, 'drawing', []) : [];

		return self::drawing_opt(is_array($saved) ? $saved : []);
	}

	/** Число с единицей для штампа и таблицы. */
	public static function drawing_fmt($value, $unit)
	{
		$info = Arr::get(self::drawing_units(), $unit, Arr::get(self::drawing_units(), 'mm'));
		$v    = (float) $value;
		$dec  = ($unit === 'm') ? 3 : (($unit === 'cm') ? 2 : (abs($v) < 10 ? 1 : 0));
		$s    = number_format($v, $dec, ',', '');

		if (strpos($s, ',') !== FALSE)
		{
			$s = rtrim(rtrim($s, '0'), ',');
		}

		if ($s === '' OR $s === '-')
		{
			$s = '0';
		}

		return $s.' '.(string) Arr::get($info, 'short', 'мм');
	}

	/**
	 * Габариты, длины связей и масштаб листа — без модели.
	 *
	 * Длина связи — расстояние между узлами в пространстве. Это каркас:
	 * толщину трубы и отверстия чертёж не знает.
	 */
	public static function drawing_plan(array $graph, array $opt = [])
	{
		$graph = Service_Nj667_Graph3d::graph_clean($graph);
		$opt   = self::drawing_opt($opt);
		$units = self::drawing_units();
		$info  = $units[$opt['unit']];
		$nodes = (array) Arr::get($graph, 'nodes', []);
		$edges = (array) Arr::get($graph, 'edges', []);

		$minx = $maxx = $miny = $maxy = $minz = $maxz = NULL;
		$by_id = [];
		$out_n = [];

		foreach ($nodes as $node)
		{
			$id = (string) Arr::get($node, 'id');
			$x  = (float) Arr::get($node, 'x', 0);
			$y  = (float) Arr::get($node, 'y', 0);
			$z  = (float) Arr::get($node, 'z', 0);
			$minx = $minx === NULL ? $x : min($minx, $x);
			$maxx = $maxx === NULL ? $x : max($maxx, $x);
			$miny = $miny === NULL ? $y : min($miny, $y);
			$maxy = $maxy === NULL ? $y : max($maxy, $y);
			$minz = $minz === NULL ? $z : min($minz, $z);
			$maxz = $maxz === NULL ? $z : max($maxz, $z);
			$row = [
				'id' => $id,
				'x'  => $x, 'y' => $y, 'z' => $z,
				'rx' => $x * $opt['unit_size'],
				'ry' => $y * $opt['unit_size'],
				'rz' => $z * $opt['unit_size'],
				'type' => (string) Arr::get($node, 'type', 'connector'),
			];
			$by_id[$id] = $row;
			$out_n[] = $row;
		}

		$span_u = [
			'x' => ($minx === NULL) ? 0.0 : max(0.0, $maxx - $minx),
			'y' => ($miny === NULL) ? 0.0 : max(0.0, $maxy - $miny),
			'z' => ($minz === NULL) ? 0.0 : max(0.0, $maxz - $minz),
		];
		$span_r = [
			'x' => $span_u['x'] * $opt['unit_size'],
			'y' => $span_u['y'] * $opt['unit_size'],
			'z' => $span_u['z'] * $opt['unit_size'],
		];

		$out_e = [];
		$sum   = 0.0;
		$long  = 0.0;
		$lid   = '';

		foreach ($edges as $edge)
		{
			$a = Arr::get($by_id, (string) Arr::get($edge, 'source'));
			$b = Arr::get($by_id, (string) Arr::get($edge, 'target'));

			if ( ! is_array($a) OR ! is_array($b))
			{
				continue;
			}

			$du = sqrt(
				pow($a['x'] - $b['x'], 2) + pow($a['y'] - $b['y'], 2) + pow($a['z'] - $b['z'], 2)
			);
			$lr = $du * $opt['unit_size'];
			$sum += $lr;
			$id = (string) Arr::get($edge, 'id', '');

			if ($lr >= $long)
			{
				$long = $lr;
				$lid  = $id;
			}

			$out_e[] = [
				'id'     => $id,
				'source' => $a['id'],
				'target' => $b['id'],
				'type'   => (string) Arr::get($edge, 'type', 'rigid'),
				'length_u' => $du,
				'length'   => $lr,
				'dx' => abs($a['x'] - $b['x']),
				'dy' => abs($a['y'] - $b['y']),
				'dz' => abs($a['z'] - $b['z']),
			];
		}

		usort($out_e, function ($a, $b) {
			if ($a['length'] === $b['length'])
			{
				return strcmp($a['id'], $b['id']);
			}

			return ($a['length'] > $b['length']) ? -1 : 1;
		});

		$paper = self::_drawing_sheet($opt['paper']);
		$to_mm = (float) $info['to_mm'];
		$pick  = self::_drawing_pick_scale([
			'to_mm' => $to_mm, 'unit_size' => (float) $opt['unit_size'],
			'paper_mm' => $paper, 'nodes' => $out_n,
		], (string) $opt['scale']);
		$ratio = $pick['ratio'];
		$fit   = $pick['fits'];
		$opt['scale'] = $pick['label'];

		return [
			'ok'         => (bool) $out_n,
			'unit'       => $opt['unit'],
			'unit_size'  => $opt['unit_size'],
			'unit_short' => (string) $info['short'],
			'to_mm'      => $to_mm,
			'scale'      => $opt['scale'],
			'scale_ratio'=> $ratio,
			'paper'      => $opt['paper'],
			'paper_mm'   => $paper,
			'title'      => $opt['title'],
			'code'       => $opt['code'],
			'fits'       => $fit,
			'span_u'     => $span_u,
			'span'       => $span_r,
			'min'        => ['x' => (float) $minx, 'y' => (float) $miny, 'z' => (float) $minz],
			'max'        => ['x' => (float) $maxx, 'y' => (float) $maxy, 'z' => (float) $maxz],
			'nodes'      => $out_n,
			'edges'      => $out_e,
			'sum_length' => $sum,
			'longest'    => $long,
			'longest_id' => $lid,
			'ids'        => $opt['ids'],
		];
	}

	/**
	 * Лист A4/A3 альбом по ГОСТ 2.104 и 2.305 (владелец, 14.10.2026).
	 *
	 * Рамка с полями 20/5 мм, основная надпись 185×55 справа внизу. Виды — в
	 * проекционной связи: спереди в центре, сверху над ним, слева справа от него,
	 * изометрия — в свободном углу. Один масштаб на весь лист, без рамок у видов.
	 * Размеры — по ГОСТ 2.307: выносные линии, стрелки, цифры над размерной линией.
	 */
	public static function drawing_svg(array $graph, array $opt = [])
	{
		$plan  = self::drawing_plan($graph, $opt);
		$paper = $plan['paper_mm'];
		$w     = $paper[0];
		$h     = $paper[1];
		$ink   = '#0f172a';
		$mute  = '#475569';
		$title = $plan['title'] !== '' ? $plan['title'] : 'Чертёж';

		$out = ['<?xml version="1.0" encoding="UTF-8"?>',
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" '
				.'width="'.$w.'mm" height="'.$h.'mm" role="img" data-drawing="ortho" '
				.'aria-label="Чертёж: '.HTML::chars($title).'">',
			'<rect width="'.$w.'" height="'.$h.'" fill="#ffffff"/>',
			'<rect x="20" y="5" width="'.($w - 25).'" height="'.($h - 10).'" fill="none" stroke="'.$ink.'" stroke-width="0.7"/>',
		];

		if ( ! $plan['ok'])
		{
			$out[] = '<text x="'.($w / 2).'" y="'.($h / 2).'" text-anchor="middle" font-size="4" fill="'.$mute.'" '
				.'font-family="DejaVu Sans, Arial, sans-serif">граф пуст</text>';
			$out[] = '</svg>';

			return implode("\n", $out);
		}

		$lay = self::_drawing_layout($plan, (float) $plan['scale_ratio']);

		foreach ($lay['views'] as $name => $v)
		{
			$out = array_merge($out, self::_drawing_view_elements($name, $v, $plan, $lay['p'], $ink, $mute, (bool) $plan['ids']));
		}

		$out = array_merge($out, self::_drawing_notes_and_stamp($plan, $w, $h, $ink, $mute));
		$out[] = '</svg>';

		return implode("\n", $out);
	}

	/**
	 * Четыре вида отдельными SVG — для экрана, не для печати.
	 *
	 * Лист в миллиметрах на телефоне либо обрезается, либо сжимается до
	 * нечитаемых подписей. Каждый вид — своя рамка по содержимому, с тем же
	 * масштабом, что и на листе (владелец, 11.10.2026).
	 */
	public static function drawing_view_svgs(array $graph, array $opt = [])
	{
		$plan = self::drawing_plan($graph, $opt);
		$out  = [];

		if ( ! $plan['ok'])
		{
			return $out;
		}

		$ink = '#0f172a';
		$mute = '#475569';
		$lay = self::_drawing_layout($plan, (float) $plan['scale_ratio']);

		foreach ($lay['views'] as $name => $v)
		{
			list($bx, $by, $bw, $bh) = $v['box'];
			$body = self::_drawing_view_elements($name, $v, $plan, $lay['p'], $ink, $mute, (bool) $plan['ids']);
			$out[$name] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="'
				.round($bx, 2).' '.round($by, 2).' '.round($bw, 2).' '.round($bh, 2).'" '
				.'width="100%" height="100%" preserveAspectRatio="xMidYMid meet" role="img" '
				.'data-view="'.HTML::chars($name).'" aria-label="Вид: '.HTML::chars($name).'">'
				.implode('', $body).'</svg>';
		}

		return $out;
	}

	/** Спецификация: узлы, заготовки, габарит. CSV с BOM для Excel. */
	public static function drawing_csv(array $graph, array $opt = [])
	{
		$plan = self::drawing_plan($graph, $opt);
		$u    = $plan['unit_short'];
		$rows = ["\xEF\xBB\xBFГабарит Д (X);Габарит Ш (Z);Габарит В (Y);Единица;1 клетка;Масштаб"];
		$rows[] = self::drawing_fmt($plan['span']['x'], $plan['unit']).';'
			.self::drawing_fmt($plan['span']['z'], $plan['unit']).';'
			.self::drawing_fmt($plan['span']['y'], $plan['unit']).';'
			.$u.';'.self::drawing_fmt($plan['unit_size'], $plan['unit']).';'.$plan['scale'];
		$rows[] = '';
		$rows[] = 'Заготовка;От;К;Длина '.$u.';Тип';

		foreach ($plan['edges'] as $e)
		{
			$rows[] = $e['id'].';'.$e['source'].';'.$e['target'].';'
				.str_replace('.', ',', (string) round($e['length'], 3)).';'.$e['type'];
		}

		$rows[] = 'сумма длин;;;;'.str_replace('.', ',', (string) round($plan['sum_length'], 3));
		$rows[] = '';
		$rows[] = 'Узел;X '.$u.';Y '.$u.';Z '.$u.';Тип';

		foreach ($plan['nodes'] as $n)
		{
			$rows[] = $n['id'].';'
				.str_replace('.', ',', (string) round($n['rx'], 3)).';'
				.str_replace('.', ',', (string) round($n['ry'], 3)).';'
				.str_replace('.', ',', (string) round($n['rz'], 3)).';'
				.$n['type'];
		}

		return implode("\r\n", $rows)."\r\n";
	}

	protected static function _drawing_sheet($paper)
	{
		return $paper === 'a3' ? [420.0, 297.0] : [297.0, 210.0];
	}

	/**
	 * Стандартные масштабы ГОСТ 2.302: [натура/бумага, подпись].
	 * 0.1 — это 10:1 (крупнее натуры), 1000 — это 1:1000 (мельче).
	 */
	protected static function _drawing_ladder()
	{
		return [
			[0.1, '10:1'], [0.2, '5:1'], [0.25, '4:1'], [0.4, '2.5:1'], [0.5, '2:1'],
			[1.0, '1:1'], [2.0, '1:2'], [2.5, '1:2.5'], [4.0, '1:4'], [5.0, '1:5'],
			[10.0, '1:10'], [15.0, '1:15'], [20.0, '1:20'], [25.0, '1:25'], [40.0, '1:40'],
			[50.0, '1:50'], [75.0, '1:75'], [100.0, '1:100'], [200.0, '1:200'],
			[400.0, '1:400'], [500.0, '1:500'], [1000.0, '1:1000'],
		];
	}

	/** «10:1» → 0.1, «1:50» → 50, «2.5:1» → 0.4. Не масштаб → 0. */
	protected static function _drawing_ratio($scale)
	{
		if ( ! preg_match('~^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$~', (string) $scale, $m))
		{
			return 0.0;
		}

		$a = max(0.001, (float) $m[1]);
		$b = max(0.001, (float) $m[2]);

		return $b / $a;
	}

	/**
	 * Масштаб листа: фиксированный берём как есть; «вписать» — самый крупный
	 * стандартный, при котором всё изделие с проекциями влезает в поле листа.
	 */
	protected static function _drawing_pick_scale(array $plan, $scale)
	{
		if (empty($plan['nodes']))
		{
			return ['ratio' => 1.0, 'label' => '1:1', 'fits' => TRUE];
		}

		$fixed = self::_drawing_ratio($scale);

		if ($fixed > 0)
		{
			$lay = self::_drawing_layout($plan, $fixed);

			return ['ratio' => $fixed, 'label' => (string) $scale, 'fits' => $lay['fits']];
		}

		$last = 1000.0;
		$label = '1:1000';

		foreach (self::_drawing_ladder() as $step)
		{
			$last  = (float) $step[0];
			$label = $step[1];

			if (self::_drawing_layout($plan, $last)['fits'])
			{
				return ['ratio' => $last, 'label' => $label, 'fits' => TRUE];
			}
		}

		return ['ratio' => $last, 'label' => $label, 'fits' => FALSE];
	}

	/**
	 * Больше — ближе к наблюдателю этой проекции.
	 * Сверху смотрим вдоль Y: выше в графе — поверх стопки (владелец, 11.10.2026).
	 */
	protected static function _drawing_depth($au, $av, $iso, array $n)
	{
		if ($iso)
		{
			return (float) $n['y'] - (float) $n['x'] - (float) $n['z'];
		}

		if ($au === 'x' AND $av === 'z')
		{
			return (float) $n['y'];
		}

		if ($au === 'x' AND $av === 'y')
		{
			return (float) $n['z'];
		}

		return - (float) $n['x'];
	}

	/**
	 * Поле для видов: слева и сверху по 24/8 мм (там размеры и подписи),
	 * справа 8 мм, снизу до основной надписи с отступом 4 мм.
	 *
	 * @return float[] x, y, ширина, высота в мм
	 */
	protected static function _drawing_area(array $paper)
	{
		return [24.0, 8.0, $paper[0] - 32.0, $paper[1] - 72.0];
	}

	/** Виды: [ось u, ось v, изометрия, перевернуть v]. Оси — как в ГОСТ 2.305 с проекционной связью. */
	protected static function _drawing_views_def()
	{
		return [
			'Сверху'    => ['x', 'z', FALSE, TRUE],
			'Спереди'   => ['x', 'y', FALSE, FALSE],
			'Слева'     => ['z', 'y', FALSE, FALSE],
			'Изометрия' => ['x', 'y', TRUE, FALSE],
		];
	}

	/**
	 * Точки вида в клетках (до масштаба). v растёт вниз на листе: верх — большие y,
	 * поэтому для «Спереди» и «Слева» v = −y, для «Сверху» v = −z.
	 */
	protected static function _drawing_view_points(array $nodes, array $def)
	{
		list($au, $av, $iso, $flip) = $def;
		$cos = 0.8660254;
		$sin = 0.5;
		$pts = [];
		$umin = NULL; $umax = NULL; $vmin = NULL; $vmax = NULL;

		foreach ($nodes as $n)
		{
			if ($iso)
			{
				$u = ((float) $n['x'] - (float) $n['z']) * $cos;
				$v = ((float) $n['x'] + (float) $n['z']) * $sin - (float) $n['y'];
			}
			else
			{
				$u = (float) $n[$au];
				$v = (float) $n[$av];

				if ($flip)
				{
					$v = -$v;
				}

				if ($av === 'y')
				{
					$v = -$v;
				}
			}

			$pts[(string) $n['id']] = [$u, $v, $n];
			$umin = $umin === NULL ? $u : min($umin, $u);
			$umax = $umax === NULL ? $u : max($umax, $u);
			$vmin = $vmin === NULL ? $v : min($vmin, $v);
			$vmax = $vmax === NULL ? $v : max($vmax, $v);
		}

		return [
			'pts'  => $pts,
			'au'   => $au,
			'av'   => $av,
			'iso'  => $iso,
			'umin' => (float) ($umin ?? 0.0),
			'vmin' => (float) ($vmin ?? 0.0),
			'du'   => (float) ($umax === NULL ? 0.0 : $umax - $umin),
			'dv'   => (float) ($vmax === NULL ? 0.0 : $vmax - $vmin),
		];
	}

	/**
	 * Раскладка видов на листе в проекционной связи (ГОСТ 2.305).
	 *
	 * Сверху — над «Спереди», «Слева» — справа от него, изометрия — в свободном
	 * углу над «Слева». Все виды в одном масштабе (paper mm на клетку = p).
	 *
	 * @return array{p: float, fits: bool, views: array, w: float, h: float}
	 */
	protected static function _drawing_layout(array $plan, $ratio)
	{
		$k   = (float) $plan['to_mm'] * (float) $plan['unit_size'];
		$p   = $k / max(1e-6, (float) $ratio);
		$geo = [];

		foreach (self::_drawing_views_def() as $name => $def)
		{
			$g = self::_drawing_view_points((array) $plan['nodes'], $def);
			$g['w'] = $g['du'] * $p;
			$g['h'] = $g['dv'] * $p;
			$geo[$name] = $g;
		}

		$lm  = 14.0; // слева: размер по высоте Y
		$tm  = 8.0;  // сверху: подписи видов
		$gap = 16.0; // между видами: подписи и место для размеров
		$dimz = 12.0; // под видами: размер по длине
		$rm  = 6.0;
		$bm  = 4.0;

		$t = $geo['Сверху'];
		$f = $geo['Спереди'];
		$l = $geo['Слева'];
		$i = $geo['Изометрия'];

		// Изометрия — в углу над «Слева». Если она выше «Сверху», ряд «Спереди» и «Слева»
		// опускается под неё, чтобы подписи и узлы не накладывались.
		$fy = $tm + max($t['h'], $i['h']) + $gap;

		$pos = [
			'Сверху'    => [$lm, $tm],
			'Спереди'   => [$lm, $fy],
			'Слева'     => [$lm + $f['w'] + $gap, $fy],
			'Изометрия' => [$lm + $f['w'] + $gap, $tm],
		];

		$W = max($lm + $t['w'], $lm + $f['w'] + $gap + max($l['w'], $i['w'])) + $rm;
		$H = max($fy + $f['h'] + $dimz, $fy + $l['h'] + $dimz, $tm + $i['h']) + $bm;

		$area = self::_drawing_area($plan['paper_mm']);
		$fits = ($W <= $area[2] + 1e-6) && ($H <= $area[3] + 1e-6);
		$ox   = $area[0] + max(0.0, ($area[2] - $W) / 2.0);
		$oy   = $area[1] + max(0.0, ($area[3] - $H) / 2.0);

		$views = [];

		foreach ($pos as $name => $xy)
		{
			$g = $geo[$name];
			$x = $ox + $xy[0];
			$y = $oy + $xy[1];
			$g['x']   = $x;
			$g['y']   = $y;
			$g['box'] = self::_drawing_view_box($name, $x, $y, $g['w'], $g['h']);
			$views[$name] = $g;
		}

		return ['p' => $p, 'fits' => $fits, 'views' => $views, 'w' => $W, 'h' => $H];
	}

	/** Рамка вида на экране: подпись, размеры и поле, без лишнего. */
	protected static function _drawing_view_box($name, $x, $y, $w, $h)
	{
		switch ($name)
		{
			case 'Сверху':
				return [$x - 12.0, $y - 8.0, $w + 14.0, $h + 11.0];

			case 'Спереди':
				return [$x - 12.0, $y - 8.0, $w + 14.0, $h + 23.0];

			case 'Слева':
				return [$x - 2.0, $y - 8.0, $w + 6.0, $h + 23.0];

			default:
				return [$x - 2.0, $y - 8.0, $w + 6.0, $h + 11.0];
		}
	}

	/**
	 * SVG одного вида: подпись, рёбра (основная линия), узлы, подписи номеров
	 * (только если включены), размеры. Координаты — абсолютные на листе.
	 */
	protected static function _drawing_view_elements($name, array $v, array $plan, $p, $ink, $mute, $ids)
	{
		$font = 'font-family="DejaVu Sans, Arial, sans-serif"';
		$ox   = (float) $v['x'];
		$oy   = (float) $v['y'];
		$umin = (float) $v['umin'];
		$vmin = (float) $v['vmin'];
		$map  = function ($u, $vv) use ($ox, $oy, $p, $umin, $vmin)
		{
			return [round($ox + ($u - $umin) * $p, 2), round($oy + ($vv - $vmin) * $p, 2)];
		};

		$out = ['<g data-view="'.HTML::chars($name).'">'];
		$out[] = '<text x="'.round($ox, 2).'" y="'.round($oy - 4.0, 2).'" font-size="2.6" fill="'.$mute.'" '.$font.'>'.HTML::chars($name).'</text>';

		foreach ($plan['edges'] as $e)
		{
			$a = Arr::get($v['pts'], (string) $e['source']);
			$b = Arr::get($v['pts'], (string) $e['target']);

			if ( ! is_array($a) || ! is_array($b))
			{
				continue;
			}

			list($x1, $y1) = $map($a[0], $a[1]);
			list($x2, $y2) = $map($b[0], $b[1]);
			$out[] = '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="'.$ink.'" '
				.'stroke-width="0.5" stroke-linecap="round"/>';
		}

		$order = array_values($v['pts']);
		usort($order, function ($a, $b) use ($v)
		{
			return self::_drawing_depth($v['au'], $v['av'], $v['iso'], $a[2]) <=> self::_drawing_depth($v['au'], $v['av'], $v['iso'], $b[2]);
		});

		foreach ($order as $pt)
		{
			list($cx, $cy) = $map($pt[0], $pt[1]);
			$out[] = '<circle cx="'.$cx.'" cy="'.$cy.'" r="1" fill="#ffffff" stroke="'.$ink.'" stroke-width="0.35"/>';
		}

		if ($ids)
		{
			$labeled = [];

			foreach (array_reverse($order) as $pt)
			{
				list($cx, $cy) = $map($pt[0], $pt[1]);
				$near = FALSE;

				foreach ($labeled as $q)
				{
					if (hypot($q[0] - $cx, $q[1] - $cy) < 2.6)
					{
						$near = TRUE;
						break;
					}
				}

				if ($near)
				{
					continue;
				}

				$labeled[] = [$cx, $cy];
				$out[] = '<text x="'.round($cx + 1.6, 2).'" y="'.round($cy - 1.4, 2).'" font-size="2.1" fill="'.$mute.'" '.$font.' '
					.'data-nid="'.HTML::chars((string) $pt[2]['id']).'">'.HTML::chars((string) $pt[2]['id']).'</text>';
			}
		}

		if (empty($v['iso']))
		{
			$sp = $plan['span'];
			$u  = $plan['unit'];
			$xl = $ox;
			$yb = $oy + $v['h'];
			$dims = [];

			switch ($name)
			{
				case 'Сверху':
					$dims = self::_drawing_dim_v($oy, $yb, $xl - 6.0, $xl, self::drawing_fmt($sp['z'], $u), $ink, $font);
					break;

				case 'Спереди':
					$dims = array_merge(
						self::_drawing_dim_h($ox, $ox + $v['w'], $yb + 6.0, $yb, self::drawing_fmt($sp['x'], $u), $ink, $font),
						self::_drawing_dim_v($oy, $yb, $xl - 6.0, $xl, self::drawing_fmt($sp['y'], $u), $ink, $font)
					);
					break;

				case 'Слева':
					$dims = self::_drawing_dim_h($ox, $ox + $v['w'], $yb + 6.0, $yb, self::drawing_fmt($sp['z'], $u), $ink, $font);
					break;
			}

			$out = array_merge($out, $dims);
		}

		$out[] = '</g>';

		return $out;
	}

	/** Размер по горизонтали (ГОСТ 2.307): выносные линии, стрелки, цифра над линией. */
	protected static function _drawing_dim_h($x1, $x2, $y, $yref, $text, $ink, $font)
	{
		if ($x2 - $x1 < 0.5)
		{
			return [];
		}

		$y  = round($y, 2);
		$out = [
			'<line x1="'.round($x1, 2).'" y1="'.round($yref + 1.0, 2).'" x2="'.round($x1, 2).'" y2="'.round($y + 1.2, 2).'" stroke="'.$ink.'" stroke-width="0.2"/>',
			'<line x1="'.round($x2, 2).'" y1="'.round($yref + 1.0, 2).'" x2="'.round($x2, 2).'" y2="'.round($y + 1.2, 2).'" stroke="'.$ink.'" stroke-width="0.2"/>',
			'<line x1="'.round($x1, 2).'" y1="'.$y.'" x2="'.round($x2, 2).'" y2="'.$y.'" stroke="'.$ink.'" stroke-width="0.25"/>',
			'<polygon points="'.round($x1, 2).','.$y.' '.round($x1 + 2.2, 2).','.round($y - 0.7, 2).' '.round($x1 + 2.2, 2).','.round($y + 0.7, 2).'" fill="'.$ink.'"/>',
			'<polygon points="'.round($x2, 2).','.$y.' '.round($x2 - 2.2, 2).','.round($y - 0.7, 2).' '.round($x2 - 2.2, 2).','.round($y + 0.7, 2).'" fill="'.$ink.'"/>',
			'<text x="'.round(($x1 + $x2) / 2, 2).'" y="'.round($y - 1.0, 2).'" text-anchor="middle" font-size="2.4" fill="'.$ink.'" '.$font.'>'.HTML::chars($text).'</text>',
		];

		return $out;
	}

	/** Размер по вертикали: цифра повёрнута, читается снизу вверх (ГОСТ 2.307). */
	protected static function _drawing_dim_v($y1, $y2, $x, $xref, $text, $ink, $font)
	{
		if ($y2 - $y1 < 0.5)
		{
			return [];
		}

		$x   = round($x, 2);
		$out = [
			'<line x1="'.round($xref - 1.0, 2).'" y1="'.round($y1, 2).'" x2="'.round($x - 1.2, 2).'" y2="'.round($y1, 2).'" stroke="'.$ink.'" stroke-width="0.2"/>',
			'<line x1="'.round($xref - 1.0, 2).'" y1="'.round($y2, 2).'" x2="'.round($x - 1.2, 2).'" y2="'.round($y2, 2).'" stroke="'.$ink.'" stroke-width="0.2"/>',
			'<line x1="'.$x.'" y1="'.round($y1, 2).'" x2="'.$x.'" y2="'.round($y2, 2).'" stroke="'.$ink.'" stroke-width="0.25"/>',
			'<polygon points="'.$x.','.round($y1, 2).' '.round($x - 0.7, 2).','.round($y1 + 2.2, 2).' '.round($x + 0.7, 2).','.round($y1 + 2.2, 2).'" fill="'.$ink.'"/>',
			'<polygon points="'.$x.','.round($y2, 2).' '.round($x - 0.7, 2).','.round($y2 - 2.2, 2).' '.round($x + 0.7, 2).','.round($y2 - 2.2, 2).'" fill="'.$ink.'"/>',
		];
		$tx  = round($x - 0.8, 2);
		$ty  = round(($y1 + $y2) / 2, 2);
		$out[] = '<text transform="rotate(-90 '.$tx.' '.$ty.')" x="'.$tx.'" y="'.$ty.'" text-anchor="middle" font-size="2.4" fill="'.$ink.'" '.$font.'>'.HTML::chars($text).'</text>';

		return $out;
	}

	/**
	 * Основная надпись ГОСТ 2.104 (форма 1, 185×55) справа внизу и примечания слева от неё.
	 * Примечания: габарит, клетка, узлы, оговорка о толщине и о вписывании.
	 */
	protected static function _drawing_notes_and_stamp(array $plan, $w, $h, $ink, $mute)
	{
		$font = 'font-family="DejaVu Sans, Arial, sans-serif"';
		$x0   = $w - 190.0;
		$y0   = $h - 60.0;
		$ln   = function ($x1, $y1, $x2, $y2, $sw = 0.35) use ($ink)
		{
			return '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="'.$ink.'" stroke-width="'.$sw.'"/>';
		};
		$tx = function ($x, $y, $size, $text, $fill, $extra = '') use ($font)
		{
			return '<text x="'.round($x, 2).'" y="'.round($y, 2).'" font-size="'.$size.'" fill="'.$fill.'" '.$font.' '.$extra.'>'.HTML::chars($text).'</text>';
		};

		$s = [];
		$s[] = '<rect x="'.$x0.'" y="'.$y0.'" width="185" height="55" fill="none" stroke="'.$ink.'" stroke-width="0.5"/>';

		// Левая часть: изменения (сверху) и подписи (снизу).
		$s[] = $ln($x0, $y0 + 7, $x0 + 60, $y0 + 7);
		$s[] = $ln($x0, $y0 + 14, $x0 + 60, $y0 + 14);
		$s[] = $ln($x0, $y0 + 20, $x0 + 60, $y0 + 20);
		foreach ([7.0, 15.0, 38.0, 48.0] as $dx)
		{
			$s[] = $ln($x0 + $dx, $y0, $x0 + $dx, $y0 + 7);
		}
		$s[] = $tx($x0 + 1.5, $y0 + 5, 2.2, 'Изм.', $mute);
		$s[] = $tx($x0 + 8.5, $y0 + 5, 2.2, 'Лист', $mute);
		$s[] = $tx($x0 + 16, $y0 + 5, 2.2, '№ докум.', $mute);
		$s[] = $tx($x0 + 39, $y0 + 5, 2.2, 'Подп.', $mute);
		$s[] = $tx($x0 + 49, $y0 + 5, 2.2, 'Дата', $mute);
		foreach ([17.0, 40.0, 50.0] as $dx)
		{
			$s[] = $ln($x0 + $dx, $y0 + 20, $x0 + $dx, $y0 + 55);
		}
		$roles = ['Разраб.', 'Пров.', 'Т.контр.', 'Н.контр.', 'Утв.'];

		foreach ($roles as $k => $role)
		{
			$top = $y0 + 20 + 7 * $k;

			if ($k > 0)
			{
				$s[] = $ln($x0, $top, $x0 + 60, $top);
			}

			$s[] = $tx($x0 + 1.5, $top + 4.8, 2.4, $role, $ink);
		}

		// Средняя часть: наименование, обозначение.
		$s[] = $ln($x0 + 60, $y0, $x0 + 60, $y0 + 55);
		$s[] = $ln($x0 + 125, $y0, $x0 + 125, $y0 + 55);
		$s[] = $ln($x0 + 60, $y0 + 30, $x0 + 125, $y0 + 30);
		$title = $plan['title'] !== '' ? UTF8::substr($plan['title'], 0, 40) : 'Чертёж';
		$s[] = $tx($x0 + 62, $y0 + 3.6, 2.2, 'Наименование изделия', $mute);
		$s[] = $tx($x0 + 62, $y0 + 12.5, 4.2, $title, $ink, 'font-weight="600"');
		$s[] = $tx($x0 + 62, $y0 + 33.6, 2.2, 'Обозначение', $mute);
		$s[] = $tx($x0 + 62, $y0 + 43.5, 3.4, $plan['code'] !== '' ? 'код '.$plan['code'] : '—', $ink);

		// Правая часть: лит., масса, масштаб, лист, листов, организация.
		$s[] = $ln($x0 + 125, $y0 + 10, $x0 + 185, $y0 + 10);
		$s[] = $ln($x0 + 125, $y0 + 20, $x0 + 185, $y0 + 20);
		$s[] = $ln($x0 + 145, $y0, $x0 + 145, $y0 + 20);
		$s[] = $ln($x0 + 165, $y0, $x0 + 165, $y0 + 10);
		$s[] = $tx($x0 + 126.5, $y0 + 3.6, 2.2, 'Лит.', $mute);
		$s[] = $tx($x0 + 146.5, $y0 + 3.6, 2.2, 'Масса', $mute);
		$s[] = $tx($x0 + 166.5, $y0 + 3.6, 2.2, 'Масштаб', $mute);
		$s[] = $tx($x0 + 166.5, $y0 + 8.6, 3.2, (string) $plan['scale'], $ink);
		$s[] = $tx($x0 + 126.5, $y0 + 13.6, 2.2, 'Лист', $mute);
		$s[] = $tx($x0 + 146.5, $y0 + 13.6, 2.2, 'Листов', $mute);
		$s[] = $tx($x0 + 126.5, $y0 + 18.6, 3.2, '1', $ink);
		$s[] = $tx($x0 + 146.5, $y0 + 18.6, 3.2, '1', $ink);
		$s[] = $tx($x0 + 128, $y0 + 30, 3.6, 'n2j.ru', $ink);
		$s[] = $tx($x0 + 128, $y0 + 37, 2.2, 'чертёж по координатам графа', $mute);

		// Примечания слева от штампа.
		$sp  = $plan['span'];
		$u   = $plan['unit'];
		$nx  = 24.0;
		$ny  = $y0 + 4.0;
		$s[] = $tx($nx, $ny, 2.8, 'Примечания', $ink, 'font-weight="600"');
		$s[] = $tx($nx, $ny + 6.0, 2.5, 'Габарит Д × Ш × В: '.self::drawing_fmt($sp['x'], $u).' × '.self::drawing_fmt($sp['z'], $u).' × '.self::drawing_fmt($sp['y'], $u), $ink);
		$s[] = $tx($nx, $ny + 11.0, 2.5, '1 клетка графа = '.self::drawing_fmt($plan['unit_size'], $u).' · масштаб '.$plan['scale'], $ink);
		$s[] = $tx($nx, $ny + 16.0, 2.5, 'Узлов: '.count($plan['nodes']).', связей: '.count($plan['edges']), $ink);
		$s[] = $tx($nx, $ny + 21.0, 2.3, 'Каркас: длины по осям между узлами.', $mute);
		$s[] = $tx($nx, $ny + 25.0, 2.3, 'Толщину материала чертёж не учитывает.', $mute);

		if ( ! $plan['fits'])
		{
			$s[] = $tx($nx, $ny + 31.0, 2.3, 'При этом масштабе изделие не вмещается в поле листа.', '#b45309');
			$s[] = $tx($nx, $ny + 35.0, 2.3, 'Выберите масштаб мельче или «вписать в лист».', '#b45309');
		}

		return $s;
	}
}
