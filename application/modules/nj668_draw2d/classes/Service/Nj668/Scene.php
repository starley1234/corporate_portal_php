<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Чистая сцена nj668: фигуры с заливкой, прямыми и кривыми Безье.
 *
 * Источник правды — JSON сцены, не растр. Картинку (SVG) всегда можно
 * перерисовать; из PNG геометрию не достать. Модель не обязана соблюдать
 * схему — clean() выкидывает мусор, svg() рисует только то, что осталось
 * (владелец, 11.10.2026).
 */
class Service_Nj668_Scene
{
	const KINDS = ['rect', 'circle', 'ellipse', 'poly', 'line', 'cubic', 'path'];

	public static function canvas_w() { return 960; }
	public static function canvas_h() { return 640; }

	public static function scene_new($bg = '#eef6ff')
	{
		return [
			'w'      => self::canvas_w(),
			'h'      => self::canvas_h(),
			'bg'     => self::color($bg, '#eef6ff'),
			'shapes' => [],
		];
	}

	/**
	 * Почистить сцену от модели или редактора. Неизвестные kind и битые
	 * координаты выкидываем, пустую сцену не запрещаем — холст тогда пустой.
	 */
	public static function clean($raw, $max_shapes = 120)
	{
		$raw = is_array($raw) ? $raw : [];
		$w   = self::num(Arr::get($raw, 'w', self::canvas_w()), 160, 2400, self::canvas_w());
		$h   = self::num(Arr::get($raw, 'h', self::canvas_h()), 120, 1800, self::canvas_h());
		$max_shapes = max(8, min(200, (int) $max_shapes));

		$out = [
			'w'      => (int) round($w),
			'h'      => (int) round($h),
			'bg'     => self::color(Arr::get($raw, 'bg', '#eef6ff'), '#eef6ff'),
			'shapes' => [],
		];

		$seen = [];
		$n    = 0;

		foreach ((array) Arr::get($raw, 'shapes', Arr::get($raw, 'items', [])) as $row)
		{
			if ( ! is_array($row) OR $n >= $max_shapes)
			{
				break;
			}

			$one = self::clean_shape($row, $out['w'], $out['h']);

			if ($one === NULL)
			{
				continue;
			}

			$id = (string) Arr::get($one, 'id');

			if ($id === '' OR isset($seen[$id]))
			{
				$id = 's'.($n + 1);
				$one['id'] = $id;
			}

			$seen[$id] = TRUE;
			$out['shapes'][] = $one;
			$n++;
		}

		return $out;
	}

	public static function clean_shape(array $row, $w, $h)
	{
		$kind = strtolower(trim((string) Arr::get($row, 'kind', Arr::get($row, 'type', ''))));

		if ( ! in_array($kind, self::KINDS, TRUE))
		{
			return NULL;
		}

		$id = preg_replace('~[^a-zA-Z0-9_-]~', '', (string) Arr::get($row, 'id', ''));
		$id = UTF8::substr($id, 0, 32);

		$fill    = self::color(Arr::get($row, 'fill', ''), '');
		$stroke  = self::color(Arr::get($row, 'stroke', ''), '');
		$sw      = self::num(Arr::get($row, 'sw', Arr::get($row, 'strokeWidth', 0)), 0, 48, 0);
		$opacity = self::num(Arr::get($row, 'opacity', 1), 0.05, 1, 1);

		if ($fill === '' AND $stroke === '' AND $kind !== 'line' AND $kind !== 'cubic' AND $kind !== 'path')
		{
			$fill = '#94a3b8';
		}

		if (($kind === 'line' OR $kind === 'cubic') AND $stroke === '' AND $fill === '')
		{
			$stroke = '#1e293b';
			$sw     = $sw > 0 ? $sw : 3;
		}

		$shape = [
			'id'      => $id,
			'kind'    => $kind,
			'fill'    => $fill,
			'stroke'  => $stroke,
			'sw'      => round($sw, 2),
			'opacity' => round($opacity, 3),
		];

		if ($kind === 'rect')
		{
			$shape['x'] = self::num(Arr::get($row, 'x', 0), -200, $w + 200, 0);
			$shape['y'] = self::num(Arr::get($row, 'y', 0), -200, $h + 200, 0);
			$shape['w'] = self::num(Arr::get($row, 'w', Arr::get($row, 'width', 40)), 1, $w + 400, 40);
			$shape['h'] = self::num(Arr::get($row, 'h', Arr::get($row, 'height', 40)), 1, $h + 400, 40);
			$rx = self::num(Arr::get($row, 'rx', 0), 0, 400, 0);
			if ($rx > 0) { $shape['rx'] = $rx; }
		}
		elseif ($kind === 'circle')
		{
			$shape['x'] = self::num(Arr::get($row, 'x', 0), -200, $w + 200, 0);
			$shape['y'] = self::num(Arr::get($row, 'y', 0), -200, $h + 200, 0);
			$shape['r'] = self::num(Arr::get($row, 'r', 20), 1, max($w, $h), 20);
		}
		elseif ($kind === 'ellipse')
		{
			$shape['x']  = self::num(Arr::get($row, 'x', 0), -200, $w + 200, 0);
			$shape['y']  = self::num(Arr::get($row, 'y', 0), -200, $h + 200, 0);
			$shape['rx'] = self::num(Arr::get($row, 'rx', 20), 1, $w + 200, 20);
			$shape['ry'] = self::num(Arr::get($row, 'ry', 12), 1, $h + 200, 12);
		}
		elseif ($kind === 'path')
		{
			$d = self::clean_path((string) Arr::get($row, 'd', ''));

			if ($d === '')
			{
				return NULL;
			}

			$shape['d'] = $d;
		}
		else
		{
			$pts = self::clean_points(Arr::get($row, 'pts', Arr::get($row, 'points', [])), $w, $h);

			if ($kind === 'poly' AND count($pts) < 3)
			{
				return NULL;
			}

			if ($kind === 'line' AND count($pts) < 2)
			{
				$x1 = self::num(Arr::get($row, 'x1', 0), -200, $w + 200, 0);
				$y1 = self::num(Arr::get($row, 'y1', 0), -200, $h + 200, 0);
				$x2 = self::num(Arr::get($row, 'x2', 40), -200, $w + 200, 40);
				$y2 = self::num(Arr::get($row, 'y2', 40), -200, $h + 200, 40);
				$pts = [[$x1, $y1], [$x2, $y2]];
			}

			if ($kind === 'cubic' AND count($pts) < 4)
			{
				return NULL;
			}

			if ($kind === 'line' AND count($pts) < 2)
			{
				return NULL;
			}

			$shape['pts'] = $pts;

			if ( ! empty($row['closed']) OR $kind === 'poly')
			{
				$shape['closed'] = 1;
			}
		}

		return $shape;
	}

	public static function clean_points($raw, $w, $h)
	{
		$out = [];

		foreach ((array) $raw as $pt)
		{
			if (count($out) >= 64)
			{
				break;
			}

			if (is_array($pt) AND count($pt) >= 2)
			{
				$x = self::num($pt[0], -400, $w + 400, 0);
				$y = self::num($pt[1], -400, $h + 400, 0);
				$out[] = [round($x, 2), round($y, 2)];
			}
		}

		return $out;
	}

	/** Path только M/L/C/Q/H/V/Z и числа — без url() и скриптов. */
	public static function clean_path($d)
	{
		$d = trim(preg_replace('~\s+~', ' ', (string) $d));

		if ($d === '' OR UTF8::strlen($d) > 4000)
		{
			return '';
		}

		if (preg_match('~[^MmLlHhVvCcQqZz0-9eE.,+\-\s]~', $d))
		{
			return '';
		}

		return $d;
	}

	public static function color($raw, $fallback = '')
	{
		$raw = strtolower(trim((string) $raw));

		if ($raw === '' OR $raw === 'none' OR $raw === 'transparent')
		{
			return $fallback;
		}

		if (preg_match('~^#([0-9a-f]{3}|[0-9a-f]{6})$~', $raw))
		{
			return $raw;
		}

		return $fallback;
	}

	public static function num($value, $min, $max, $fallback)
	{
		if ( ! is_numeric($value))
		{
			return (float) $fallback;
		}

		$n = (float) $value;

		if ($n < $min) { return (float) $min; }
		if ($n > $max) { return (float) $max; }

		return $n;
	}

	public static function hash(array $scene)
	{
		$clean = self::clean($scene);

		return sha1(json_encode($clean, JSON_UNESCAPED_UNICODE));
	}

	/** SVG картинка сцены. Без подписей — текст в JSON не рисуем. */
	public static function svg(array $scene, $width = 0, $height = 0)
	{
		$scene = self::clean($scene);
		$w     = (int) Arr::get($scene, 'w', self::canvas_w());
		$h     = (int) Arr::get($scene, 'h', self::canvas_h());
		$out_w = $width > 0 ? (int) $width : $w;
		$out_h = $height > 0 ? (int) $height : $h;
		$bg    = HTML::chars((string) Arr::get($scene, 'bg', '#eef6ff'));

		$parts = [
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" width="'
				.$out_w.'" height="'.$out_h.'" preserveAspectRatio="xMidYMid meet" role="img">',
			'<rect width="100%" height="100%" fill="'.$bg.'"/>',
		];

		foreach ((array) Arr::get($scene, 'shapes', []) as $shape)
		{
			$el = self::svg_shape((array) $shape);

			if ($el !== '')
			{
				$parts[] = $el;
			}
		}

		$parts[] = '</svg>';

		return implode('', $parts);
	}

	public static function svg_shape(array $s)
	{
		$kind    = (string) Arr::get($s, 'kind');
		$fill    = (string) Arr::get($s, 'fill', '');
		$stroke  = (string) Arr::get($s, 'stroke', '');
		$sw      = (float) Arr::get($s, 'sw', 0);
		$opacity = (float) Arr::get($s, 'opacity', 1);
		$fill_a  = $fill === '' ? 'none' : HTML::chars($fill);
		$stroke_a = $stroke === '' ? 'none' : HTML::chars($stroke);
		$attr    = ' fill="'.$fill_a.'" stroke="'.$stroke_a.'"';

		if ($sw > 0 AND $stroke !== '')
		{
			$attr .= ' stroke-width="'.$sw.'" stroke-linejoin="round" stroke-linecap="round"';
		}

		if ($opacity < 0.999)
		{
			$attr .= ' opacity="'.$opacity.'"';
		}

		if ($kind === 'rect')
		{
			$rx = (float) Arr::get($s, 'rx', 0);
			$r  = $rx > 0 ? ' rx="'.$rx.'" ry="'.$rx.'"' : '';

			return '<rect x="'.Arr::get($s, 'x', 0).'" y="'.Arr::get($s, 'y', 0)
				.'" width="'.Arr::get($s, 'w', 0).'" height="'.Arr::get($s, 'h', 0).'"'.$r.$attr.'/>';
		}

		if ($kind === 'circle')
		{
			return '<circle cx="'.Arr::get($s, 'x', 0).'" cy="'.Arr::get($s, 'y', 0)
				.'" r="'.Arr::get($s, 'r', 0).'"'.$attr.'/>';
		}

		if ($kind === 'ellipse')
		{
			return '<ellipse cx="'.Arr::get($s, 'x', 0).'" cy="'.Arr::get($s, 'y', 0)
				.'" rx="'.Arr::get($s, 'rx', 0).'" ry="'.Arr::get($s, 'ry', 0).'"'.$attr.'/>';
		}

		if ($kind === 'path')
		{
			return '<path d="'.HTML::chars((string) Arr::get($s, 'd', '')).'"'.$attr.'/>';
		}

		$pts = (array) Arr::get($s, 'pts', []);

		if ($kind === 'poly')
		{
			$pair = [];

			foreach ($pts as $pt)
			{
				$pair[] = $pt[0].','.$pt[1];
			}

			return '<polygon points="'.implode(' ', $pair).'"'.$attr.'/>';
		}

		if ($kind === 'line')
		{
			$pair = [];

			foreach ($pts as $pt)
			{
				$pair[] = $pt[0].','.$pt[1];
			}

			$line = ' fill="none" stroke="'.$stroke_a.'"';

			if ($sw > 0 AND $stroke !== '')
			{
				$line .= ' stroke-width="'.$sw.'" stroke-linejoin="round" stroke-linecap="round"';
			}

			if ($opacity < 0.999)
			{
				$line .= ' opacity="'.$opacity.'"';
			}

			return '<polyline points="'.implode(' ', $pair).'"'.$line.'/>';
		}

		if ($kind === 'cubic')
		{
			$d = self::cubic_d($pts, ! empty($s['closed']));

			if ($d === '')
			{
				return '';
			}

			return '<path d="'.$d.'"'.$attr.'/>';
		}

		return '';
	}

	/** pts: M, затем тройки C. closed → Z. */
	public static function cubic_d(array $pts, $closed = FALSE)
	{
		if (count($pts) < 4)
		{
			return '';
		}

		$d = 'M '.$pts[0][0].' '.$pts[0][1];
		$i = 1;
		$n = count($pts);

		while ($i + 2 < $n)
		{
			$d .= ' C '.$pts[$i][0].' '.$pts[$i][1]
				.' '.$pts[$i + 1][0].' '.$pts[$i + 1][1]
				.' '.$pts[$i + 2][0].' '.$pts[$i + 2][1];
			$i += 3;
		}

		if ($closed)
		{
			$d .= ' Z';
		}

		return $d;
	}

	public static function samples()
	{
		return [
			'sunset'  => 'Закат над морем',
			'house'   => 'Домик в лесу',
			'balloon' => 'Воздушный шар',
		];
	}

	/** Готовые сцены для стенда и пустой витрины — без модели. */
	public static function sample($name)
	{
		$name = (string) $name;

		if ($name === 'house')
		{
			return self::clean([
				'w' => 960, 'h' => 640, 'bg' => '#cde7f5',
				'shapes' => [
					['id' => 'sky', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'w' => 960, 'h' => 420, 'fill' => '#cde7f5'],
					['id' => 'sun', 'kind' => 'circle', 'x' => 780, 'y' => 110, 'r' => 54, 'fill' => '#ffe066', 'stroke' => '#f4a261', 'sw' => 3],
					['id' => 'grass', 'kind' => 'rect', 'x' => 0, 'y' => 420, 'w' => 960, 'h' => 220, 'fill' => '#74c69d'],
					['id' => 'hill', 'kind' => 'cubic', 'closed' => 1, 'fill' => '#40916c', 'pts' => [
						[0, 440], [120, 300], [280, 360], [420, 280],
						[520, 340], [700, 240], [960, 400],
						[960, 400], [960, 640], [960, 640],
						[0, 640], [0, 640], [0, 440],
					]],
					['id' => 'trunk', 'kind' => 'rect', 'x' => 150, 'y' => 340, 'w' => 28, 'h' => 120, 'fill' => '#6d4c41'],
					['id' => 'crown', 'kind' => 'circle', 'x' => 164, 'y' => 300, 'r' => 72, 'fill' => '#2d6a4f'],
					['id' => 'wall', 'kind' => 'rect', 'x' => 430, 'y' => 300, 'w' => 220, 'h' => 180, 'fill' => '#faedcd', 'stroke' => '#d4a373', 'sw' => 3],
					['id' => 'roof', 'kind' => 'poly', 'pts' => [[410, 300], [540, 190], [670, 300]], 'fill' => '#bc4749', 'stroke' => '#7f1d1d', 'sw' => 2],
					['id' => 'door', 'kind' => 'rect', 'x' => 510, 'y' => 380, 'w' => 54, 'h' => 100, 'fill' => '#9c6644', 'rx' => 6],
					['id' => 'win', 'kind' => 'rect', 'x' => 460, 'y' => 340, 'w' => 40, 'h' => 36, 'fill' => '#90e0ef', 'stroke' => '#457b9d', 'sw' => 2],
					['id' => 'path', 'kind' => 'line', 'pts' => [[537, 480], [560, 560], [620, 640]], 'stroke' => '#d4a373', 'sw' => 16],
				],
			]);
		}

		if ($name === 'balloon')
		{
			return self::clean([
				'w' => 960, 'h' => 640, 'bg' => '#bde0fe',
				'shapes' => [
					['id' => 'sky', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'w' => 960, 'h' => 640, 'fill' => '#bde0fe'],
					['id' => 'cloud1', 'kind' => 'ellipse', 'x' => 180, 'y' => 120, 'rx' => 90, 'ry' => 40, 'fill' => '#ffffff', 'opacity' => 0.9],
					['id' => 'cloud2', 'kind' => 'ellipse', 'x' => 720, 'y' => 90, 'rx' => 110, 'ry' => 46, 'fill' => '#ffffff', 'opacity' => 0.85],
					['id' => 'ground', 'kind' => 'cubic', 'closed' => 1, 'fill' => '#95d5b2', 'pts' => [
						[0, 520], [200, 470], [400, 540], [600, 480],
						[700, 500], [860, 450], [960, 510],
						[960, 510], [960, 640], [960, 640],
						[0, 640], [0, 640], [0, 520],
					]],
					['id' => 'balloon', 'kind' => 'ellipse', 'x' => 480, 'y' => 220, 'rx' => 90, 'ry' => 110, 'fill' => '#e63946', 'stroke' => '#9b2226', 'sw' => 3],
					['id' => 'stripe', 'kind' => 'ellipse', 'x' => 480, 'y' => 220, 'rx' => 40, 'ry' => 110, 'fill' => '#ffb703', 'opacity' => 0.55],
					['id' => 's1', 'kind' => 'line', 'pts' => [[430, 320], [450, 400]], 'stroke' => '#6d4c41', 'sw' => 3],
					['id' => 's2', 'kind' => 'line', 'pts' => [[480, 330], [480, 400]], 'stroke' => '#6d4c41', 'sw' => 3],
					['id' => 's3', 'kind' => 'line', 'pts' => [[530, 320], [510, 400]], 'stroke' => '#6d4c41', 'sw' => 3],
					['id' => 'basket', 'kind' => 'rect', 'x' => 448, 'y' => 400, 'w' => 64, 'h' => 36, 'fill' => '#bc6c25', 'rx' => 4],
				],
			]);
		}

		return self::clean([
			'w' => 960, 'h' => 640, 'bg' => '#1d3557',
			'shapes' => [
				['id' => 'sky', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'w' => 960, 'h' => 400, 'fill' => '#1d3557'],
				['id' => 'glow', 'kind' => 'circle', 'x' => 480, 'y' => 360, 'r' => 180, 'fill' => '#e76f51', 'opacity' => 0.35],
				['id' => 'sun', 'kind' => 'circle', 'x' => 480, 'y' => 340, 'r' => 70, 'fill' => '#ffb703'],
				['id' => 'sea', 'kind' => 'rect', 'x' => 0, 'y' => 400, 'w' => 960, 'h' => 240, 'fill' => '#1b4965'],
				['id' => 'wave1', 'kind' => 'cubic', 'fill' => '', 'stroke' => '#5fa8d3', 'sw' => 4, 'pts' => [
					[0, 460], [160, 420], [320, 500], [480, 450],
					[560, 430], [720, 510], [960, 440],
				]],
				['id' => 'wave2', 'kind' => 'cubic', 'fill' => '', 'stroke' => '#cae9ff', 'sw' => 3, 'opacity' => 0.7, 'pts' => [
					[0, 520], [200, 560], [400, 500], [600, 540],
					[700, 560], [840, 500], [960, 530],
				]],
				['id' => 'boat', 'kind' => 'poly', 'pts' => [[380, 430], [560, 430], [530, 470], [410, 470]], 'fill' => '#6d4c41'],
				['id' => 'sail', 'kind' => 'poly', 'pts' => [[470, 300], [470, 430], [540, 400]], 'fill' => '#f1faee'],
				['id' => 'mast', 'kind' => 'line', 'pts' => [[470, 290], [470, 430]], 'stroke' => '#3d2914', 'sw' => 4],
			],
		]);
	}
}
