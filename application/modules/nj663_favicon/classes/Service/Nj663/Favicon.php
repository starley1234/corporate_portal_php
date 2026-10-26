<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-663 «Генератор favicon»: один исходник → полный комплект иконок сайта.
 *
 * Путь запроса:
 *   1. браузер рисует базовое полотно 512×512 (загруженный файл, буква или
 *      эмодзи — эмодзи цветными умеет только браузер, GD не умеет) и отдаёт
 *      готовый PNG; без JS работает загрузка файла — тогда полотно соберёт
 *      сервер (вписать, отступ, фон, скругление);
 *   2. resample() — каскад вниз пополам: 512 → 384 → 256 … → 16. Мелкие
 *      иконки остаются резкими, а не мылом от одного ресайза;
 *   3. write_pack() — PNG всех размеров + favicon.ico (16/32/48, настоящий
 *      мультисайз-формат) + site.webmanifest + zip-архив;
 *   4. строка в nj_663_packs: код набора, что рисовали, список файлов и
 *      готовый HTML-сниппет (повторное скачивание по коду);
 *   5. sweep() — старые наборы выметаются с диска и из журнала.
 *
 * Чистые функции (цвета, имена, HTML-сниппет, сборка ICO) не трогают ни GD,
 * ни базу и покрыты стендом .tools/nj663_favicon_stand.php.
 */
class Service_Nj663_Favicon
{
	/** Алфавит кода набора: без похожих 0/O/1/l, чтобы код можно было диктовать. */
	const CODE_ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';
	const CODE_LEN = 10;

	/** Адрес выдачи файлов (совпадает с маршрутом nj663_file в init.php). */
	const FILE_URL = '/favicon-gen/f/';

	// ------------------------------------------------------------------
	// Конфиг
	// ------------------------------------------------------------------

	public static function config()
	{
		$config = Kohana::$config->load('nj663_favicon');

		return $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;
	}

	public static function base_size()
	{
		return max(16, (int) Arr::get(self::config(), 'base_size', 512));
	}

	/** Размеры PNG по возрастанию. */
	public static function sizes()
	{
		$sizes = [];

		foreach ((array) Arr::get(self::config(), 'sizes', []) as $size)
		{
			$size = (int) $size;

			if ($size >= 16 AND $size <= 512)
			{
				$sizes[$size] = $size;
			}
		}

		if ( ! $sizes)
		{
			$sizes = [16, 32, 48, 96, 180, 192, 512];
		}

		$sizes[self::base_size()] = self::base_size();
		ksort($sizes);

		return array_values($sizes);
	}

	/** Размеры внутри favicon.ico. */
	public static function ico_sizes()
	{
		$sizes = [];
		$have  = self::sizes();

		foreach ((array) Arr::get(self::config(), 'ico_sizes', [16, 32, 48]) as $size)
		{
			$size = (int) $size;

			if (in_array($size, $have, TRUE))
			{
				$sizes[] = $size;
			}
		}

		return $sizes ? array_values(array_unique($sizes)) : [16, 32, 48];
	}

	/** Дубли размеров под имена, которые ждут браузеры: apple-touch-icon и т. п. */
	public static function aliases()
	{
		$out = [];

		foreach ((array) Arr::get(self::config(), 'aliases', []) as $name => $size)
		{
			$size = (int) $size;

			if ($size >= 16 AND preg_match('/^[a-z0-9.-]+\.png$/', (string) $name) === 1)
			{
				$out[(string) $name] = $size;
			}
		}

		return $out;
	}

	public static function defaults()
	{
		return (array) Arr::get(self::config(), 'defaults', []);
	}

	public static function limits()
	{
		return (array) Arr::get(self::config(), 'limits', []);
	}

	/**
	 * Шрифты символа: ключ → ['label' => подпись, 'css' => стек для canvas].
	 *
	 * Только системные стеки: браузер рисует букву на канвасе и отдаёт
	 * готовый PNG, поэтому качать шрифты с Google Fonts не нужно — а значит
	 * генератор работает без внешней сети и не подсаживает приватность.
	 */
	public static function fonts()
	{
		return (array) Arr::get(self::config(), 'fonts', []);
	}

	/** Ключ шрифта из белого списка или первый доступный. */
	public static function font_key($key)
	{
		$fonts = self::fonts();
		$key   = (string) $key;

		return isset($fonts[$key]) ? $key : (string) key($fonts);
	}

	/** CSS-стек для ctx.font по ключу шрифта. */
	public static function font_css($key)
	{
		$fonts = self::fonts();
		$key   = self::font_key($key);

		return (string) Arr::get($fonts[$key], 'css', 'sans-serif');
	}

	/** Человекочитаемое название шрифта. */
	public static function font_label($key)
	{
		$fonts = self::fonts();
		$key   = self::font_key($key);

		return (string) Arr::get($fonts[$key], 'label', $key);
	}

	// ------------------------------------------------------------------
	// Чистые функции (без GD и без БД)
	// ------------------------------------------------------------------

	/**
	 * Все PNG комплекта: ['favicon-32x32.png' => 32, 'apple-touch-icon.png' => 180, …].
	 *
	 * @return array имя файла → размер стороны
	 */
	public static function targets()
	{
		$out = [];

		foreach (self::sizes() as $size)
		{
			$out['favicon-'.$size.'x'.$size.'.png'] = $size;
		}

		foreach (self::aliases() as $name => $size)
		{
			$out[$name] = $size;
		}

		return $out;
	}

	/** Белый список имён файлов набора: по нему же проверяется выдача. */
	public static function file_names()
	{
		$names = array_keys(self::targets());

		$names[] = 'favicon.ico';
		$names[] = 'site.webmanifest';
		$names[] = (string) Arr::get(self::config(), 'zip_name', 'favicon-pack.zip');

		return array_values(array_unique($names));
	}

	/**
	 * Имя файла из белого списка или NULL. Путь traversal («../../../etc/passwd»)
	 * возможен ровно до этой проверки — выдача по имени из запроса обязана
	 * проходить через неё.
	 */
	public static function safe_file_name($name)
	{
		$name = (string) $name;

		return in_array($name, self::file_names(), TRUE) ? $name : NULL;
	}

	/**
	 * Цвет #rgb/#rrggbb → '#rrggbb'; пустая строка = «цвет не задан»
	 * (для фона это означает «прозрачный»).
	 */
	public static function normalize_color($value, $default = '')
	{
		$value = mb_strtolower(trim((string) $value));
		$value = ltrim($value, '#');

		if ($value === '')
		{
			return $default;
		}

		if (preg_match('/^[0-9a-f]{3}$/', $value) === 1)
		{
			$value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
		}

		return preg_match('/^[0-9a-f]{6}$/', $value) === 1 ? '#'.$value : $default;
	}

	/**
	 * Путь, с которого сайт отдаёт иконки: '/', '/assets/icons/' и т. п.
	 * Протокол, хост и «..» отбрасываются — в сниппет уходит только путь.
	 */
	public static function normalize_base_path($path)
	{
		$path = trim((string) $path);

		if ($path === '')
		{
			return '/';
		}

		// 'https://site.ru/icons/' → '/icons/'. Скобки обязательны: без них
		// ($path = is_array($parts) AND …) присвоение съедает условие, и в путь
		// уходит «1» (присваивание слева от AND — ловушка из AGENTS.md).
		if (strpos($path, '//') !== FALSE)
		{
			$parts = parse_url($path);
			$path  = (is_array($parts) AND isset($parts['path'])) ? (string) $parts['path'] : '/';
		}

		$path = str_replace('..', '', $path);
		$path = preg_replace('#/+#', '/', $path);
		$path = '/'.trim((string) $path, '/');

		$max = (int) Arr::get(self::limits(), 'base_path_max', 120);

		return mb_substr($path !== '/' ? rtrim($path, '/').'/' : '/', 0, $max);
	}

	/** Готовый HTML для <head>. */
	public static function html_snippet($base_path, $theme = '')
	{
		$base  = self::normalize_base_path($base_path);
		$theme = self::normalize_color($theme, '');

		$lines = [
			'<link rel="apple-touch-icon" sizes="180x180" href="'.$base.'apple-touch-icon.png">',
			'<link rel="icon" type="image/png" sizes="32x32" href="'.$base.'favicon-32x32.png">',
			'<link rel="icon" type="image/png" sizes="16x16" href="'.$base.'favicon-16x16.png">',
			'<link rel="manifest" href="'.$base.'site.webmanifest">',
			'<link rel="shortcut icon" href="'.$base.'favicon.ico">',
		];

		if ($theme !== '')
		{
			$lines[] = '<meta name="theme-color" content="'.$theme.'">';
		}

		return implode("\n", $lines)."\n";
	}

	/** site.webmanifest — чтобы Android/Chrome взяли свои размеры. */
	public static function manifest_json($base_path, $title = '', $theme = '')
	{
		$base  = self::normalize_base_path($base_path);
		$icons = [];

		foreach ([192, 512] as $size)
		{
			$icons[] = [
				'src'   => $base.'android-chrome-'.$size.'x'.$size.'.png',
				'sizes' => $size.'x'.$size,
				'type'  => 'image/png',
			];
		}

		$manifest = [
			'name'             => $title !== '' ? $title : 'Сайт',
			'short_name'       => mb_substr($title !== '' ? $title : 'Сайт', 0, 12),
			'icons'            => $icons,
			'theme_color'      => self::normalize_color($theme, '#ffffff') ?: '#ffffff',
			'background_color' => '#ffffff',
			'display'          => 'standalone',
		];

		return json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
	}

	/**
	 * Сборка favicon.ico из кадров RGBA.
	 *
	 * Формат: ICONDIR (6 байт) + каталог (16 байт на кадр) + кадры. Кадр —
	 * BITMAPINFOHEADER (40 байт, высота удвоена: XOR-картинка плюс AND-маска),
	 * затем пиксели BGRA СНИЗУ ВВЕРХ и пустая AND-маска (прозрачность уже в
	 * альфе пикселей). Именно такой ICO понимают и Windows, и все браузеры.
	 *
	 * @param array $frames [['size' => 16, 'rgba' => '<байты RGBA сверху вниз>'], …]
	 * @return string содержимое .ico
	 */
	public static function ico_bytes(array $frames)
	{
		$good = [];

		foreach ($frames as $frame)
		{
			if ( ! is_array($frame) OR ! isset($frame['size'], $frame['rgba']))
			{
				continue;
			}

			$size = (int) $frame['size'];
			$rgba = (string) $frame['rgba'];

			if ($size >= 1 AND $size <= 256 AND strlen($rgba) === $size * $size * 4)
			{
				$good[] = ['size' => $size, 'rgba' => $rgba];
			}
		}

		if ( ! $good)
		{
			return '';
		}

		$header = pack('v3', 0, 1, count($good));
		$offset = 6 + 16 * count($good);
		$dir    = '';
		$data   = '';

		foreach ($good as $frame)
		{
			$size    = $frame['size'];
			$rgba    = $frame['rgba'];
			$row     = $size * 4;
			$xor     = '';

			// RGBA сверху вниз → BGRA снизу вверх.
			for ($y = $size - 1; $y >= 0; $y--)
			{
				$line = substr($rgba, $y * $row, $row);

				for ($x = 0; $x < $size; $x++)
				{
					$p = $x * 4;

					$xor .= $line[$p + 2].$line[$p + 1].$line[$p].$line[$p + 3];
				}
			}

			// AND-маска: 1 бит на пиксель, строки по 4 байта, вся нулевая
			// (прозрачность несем в альфе, маска — дань формату).
			$and  = str_repeat("\x00", ((int) ceil($size / 32) * 4) * $size);
			$dib  = pack('VlVvvVVVVVV', 40, $size, $size * 2, 1, 32, 0, strlen($xor) + strlen($and), 0, 0, 0, 0);
			$blob = $dib.$xor.$and;

			$dir .= pack('C4v2V2',
				$size === 256 ? 0 : $size,   // ширина (0 = 256)
				$size === 256 ? 0 : $size,   // высота
				0,                           // цветов в палитре
				0,                           // зарезервировано
				1,                           // плоскости
				32,                          // бит на пиксель
				strlen($blob),
				$offset);

			$offset += strlen($blob);
			$data   .= $blob;
		}

		return $header.$dir.$data;
	}

	/** Код набора: случайная строка, она же имя каталога на диске. */
	public static function new_code()
	{
		$alphabet = self::CODE_ALPHABET;
		$len      = (int) Arr::get(self::limits(), 'code_len', self::CODE_LEN);
		$max      = strlen($alphabet) - 1;
		$code     = '';

		for ($i = 0; $i < $len; $i++)
		{
			$code .= $alphabet[random_int(0, $max)];
		}

		return $code;
	}

	public static function is_code($code)
	{
		return preg_match('/^[23456789abcdefghjkmnpqrstuvwxyz]{6,24}$/', (string) $code) === 1;
	}

	public static function clamp_int($value, $min, $max, $default = 0)
	{
		if ( ! is_numeric($value))
		{
			return (int) $default;
		}

		return min($max, max($min, (int) round((float) $value)));
	}

	/** MIME по имени файла — для заголовка Content-Type при выдаче. */
	public static function media_type($name)
	{
		$name = (string) $name;

		if (substr($name, -4) === '.png')
		{
			return 'image/png';
		}

		if (substr($name, -4) === '.ico')
		{
			return 'image/x-icon';
		}

		if (substr($name, -4) === '.zip')
		{
			return 'application/zip';
		}

		if (substr($name, -12) === '.webmanifest')
		{
			return 'application/manifest+json';
		}

		return 'application/octet-stream';
	}

	// ------------------------------------------------------------------
	// Генерация
	// ------------------------------------------------------------------

	/**
	 * Собрать комплект иконок из байтов картинки.
	 *
	 * @param string $source_bytes PNG/JPEG/GIF/WebP — базовое полотно или исходник
	 * @param array  $options      bg, bg_alpha, radius, pad, fit, base_path, title, kind, label
	 * @return array ['ok', 'error', 'code', 'files', 'html', 'manifest', 'bytes']
	 */
	public static function generate($source_bytes, array $options = [])
	{
		$out = ['ok' => FALSE, 'error' => '', 'code' => '', 'files' => [], 'html' => '', 'manifest' => '', 'bytes' => 0];

		$source_bytes = (string) $source_bytes;
		$max_bytes    = max(1, (int) Arr::get(self::limits(), 'max_input_mb', 8)) * 1024 * 1024;

		if ($source_bytes === '')
		{
			$out['error'] = 'Пустой исходник: загрузите картинку или напечатайте букву.';

			return $out;
		}

		if (strlen($source_bytes) > $max_bytes)
		{
			$out['error'] = 'Картинка больше '.(int) Arr::get(self::limits(), 'max_input_mb', 8).' МБ.';

			return $out;
		}

		$src = @imagecreatefromstring($source_bytes);

		if ( ! $src)
		{
			$out['error'] = 'Не удалось прочитать картинку: нужен PNG, JPEG, GIF или WebP.';

			return $out;
		}

		$opt = self::_options($options);

		try
		{
			$base = self::compose($src, $opt);
		}
		catch (Throwable $e)
		{
			$out['error'] = 'Не удалось собрать полотно: '.$e->getMessage();

			return $out;
		}

		imagedestroy($src);

		$code       = self::new_code();
		$opt['code'] = $code;   // ссылки на файлы внутри комплекта знают свой код
		$dir        = self::pack_dir($code);

		if ($dir === NULL)
		{
			imagedestroy($base);
			$out['error'] = 'Не удалось создать каталог набора.';

			return $out;
		}

		$result = self::write_pack($dir, $base, $opt);
		imagedestroy($base);

		if ($result === NULL)
		{
			self::delete_dir($dir);
			$out['error'] = 'Не удалось записать файлы набора.';

			return $out;
		}

		$out['code']     = $code;
		$out['files']    = $result['files'];
		$out['html']     = $result['html'];
		$out['manifest'] = $result['manifest'];
		$out['bytes']    = $result['bytes'];
		$out['ok']       = TRUE;

		try
		{
			$out['id'] = (int) self::save_pack($code, $opt, $result);
		}
		catch (Throwable $e)
		{
			// Журнал — не главное: файлы уже готовы и отдаются по коду.
			if (class_exists('Log_Plain'))
			{
				Log_Plain::event(Log::ERROR, 'nj663: набор :code не записан в журнал: :e',
					[':code' => $code, ':e' => mb_substr($e->getMessage(), 0, 200)]);
			}
		}

		// Заодно выметаем просроченные наборы: место на диске не резиновое.
		try
		{
			self::sweep();
		}
		catch (Throwable $e)
		{
			// Тихая уборка: неудавшаяся чистка не отменяет результат.
		}

		return $out;
	}

	/**
	 * Записать комплект в каталог: PNG всех размеров, ICO, webmanifest, zip.
	 *
	 * @return array|NULL ['files' => [...], 'html' => '', 'manifest' => '', 'bytes' => int]
	 */
	protected static function write_pack($dir, $base, array $opt)
	{
		$targets   = self::targets();
		$base_size = self::base_size();
		$images    = [$base_size => $base];    // размер → GdImage
		$sizes     = array_values(array_unique(array_merge(self::sizes(), [$base_size])));
		rsort($sizes);

		// Каскад вниз: каждый размер — из ближайшего большого, а не сразу из 512.
		foreach ($sizes as $size)
		{
			if ($size >= $base_size OR isset($images[$size]))
			{
				continue;
			}

			$from = $base_size;

			foreach (array_keys($images) as $have)
			{
				if ($have > $size AND $have < $from)
				{
					$from = $have;
				}
			}

			$images[$size] = self::resample($images[$from], $size);
		}

		$files = [];

		foreach ($targets as $name => $size)
		{
			$bytes = self::png_bytes($images[$size]);

			if ($bytes === '' OR @file_put_contents($dir.DIRECTORY_SEPARATOR.$name, $bytes) === FALSE)
			{
				foreach ($images as $im)
				{
					imagedestroy($im);
				}

				return NULL;
			}

			$files[$name] = [
				'name'  => (string) $name,
				'label' => (string) $size.'×'.$size,
				'bytes' => strlen($bytes),
				'size'  => (int) $size,
				'url'   => self::file_url($opt['code'], (string) $name),
			];
		}

		// --- favicon.ico: настоящий мультисайз (16/32/48) ----------------
		$frames = [];

		foreach (self::ico_sizes() as $size)
		{
			if (isset($images[$size]))
			{
				$frames[] = ['size' => $size, 'rgba' => self::rgba_of($images[$size], $size)];
			}
		}

		$ico = self::ico_bytes($frames);

		if ($ico !== '')
		{
			@file_put_contents($dir.DIRECTORY_SEPARATOR.'favicon.ico', $ico);

			$files['favicon.ico'] = [
				'name'  => 'favicon.ico',
				'label' => implode(', ', self::ico_sizes()).' в одном файле',
				'bytes' => strlen($ico),
				'size'  => (int) Arr::get(self::ico_sizes(), 0, 16),
				'url'   => self::file_url($opt['code'], 'favicon.ico'),
			];
		}

		foreach ($images as $im)
		{
			imagedestroy($im);
		}

		// --- webmanifest и HTML-сниппет ---------------------------------
		$html     = self::html_snippet($opt['base_path'], $opt['bg']);
		$manifest = self::manifest_json($opt['base_path'], $opt['title'], $opt['bg']);

		@file_put_contents($dir.DIRECTORY_SEPARATOR.'site.webmanifest', $manifest);
		$files['site.webmanifest'] = [
			'name'  => 'site.webmanifest',
			'label' => 'манифест для Android/Chrome',
			'bytes' => strlen($manifest),
			'size'  => 0,
			'url'   => self::file_url($opt['code'], 'site.webmanifest'),
		];

		// --- zip: весь комплект одним архивом ---------------------------
		$zip_name = (string) Arr::get(self::config(), 'zip_name', 'favicon-pack.zip');
		$zip_size = self::write_zip($dir, $zip_name, $files, $html);

		if ($zip_size > 0)
		{
			$files[$zip_name] = [
				'name'  => $zip_name,
				'label' => 'все файлы одним архивом',
				'bytes' => $zip_size,
				'size'  => 0,
				'url'   => self::file_url($opt['code'], $zip_name),
			];
		}

		$bytes = 0;

		foreach ($files as $file)
		{
			$bytes += (int) $file['bytes'];
		}

		return [
			'files'    => array_values($files),
			'html'     => $html,
			'manifest' => $manifest,
			'bytes'    => $bytes,
		];
	}

	/**
	 * Сборка базового полотна: фон, отступ, вписывание, скругление.
	 *
	 * @param  GdImage $src исходник
	 * @param  array   $opt нормализованные опции
	 * @return GdImage      полотно base_size × base_size с альфой
	 */
	protected static function compose($src, array $opt)
	{
		$base      = self::base_size();
		$im        = imagecreatetruecolor($base, $base);
		imagealphablending($im, FALSE);
		imagesavealpha($im, TRUE);

		$clear = imagecolorallocatealpha($im, 0, 0, 0, 127);
		imagefilledrectangle($im, 0, 0, $base - 1, $base - 1, $clear);

		$compose = ! empty($opt['compose']);

		// Фон: цветная подложка под картинкой (галочка «без фона» выключает).
		if ($compose AND $opt['bg'] !== '' AND ! $opt['bg_alpha'])
		{
			list($r, $g, $b) = self::rgb_of($opt['bg']);
			imagefilledrectangle($im, 0, 0, $base - 1, $base - 1,
				imagecolorallocatealpha($im, $r, $g, $b, 0));
		}

		// Внутренняя рамка: отступ от краёв в процентах стороны.
		$inner  = max(1, (int) round($base * (100 - 2 * ($compose ? $opt['pad'] : 0)) / 100));
		$sw     = imagesx($src);
		$sh     = imagesy($src);

		if ($opt['fit'] === 'stretch')
		{
			$dw = $inner;
			$dh = $inner;
		}
		else
		{
			$scale = $opt['fit'] === 'cover'
				? max($inner / $sw, $inner / $sh)
				: min($inner / $sw, $inner / $sh);

			$dw = max(1, (int) round($sw * $scale));
			$dh = max(1, (int) round($sh * $scale));
		}

		$dx = (int) floor(($base - $dw) / 2);
		$dy = (int) floor(($base - $dh) / 2);

		imagealphablending($im, TRUE);
		imagecopyresampled($im, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
		imagealphablending($im, FALSE);

		// Скругление углов всего квадрата (и подложки, и картинки).
		if ($compose AND $opt['radius'] > 0)
		{
			self::round_corners($im, $opt['radius']);
		}

		return $im;
	}

	/** Ресемплинг в квадрат $size с сохранением прозрачности. */
	protected static function resample($src, $size)
	{
		$dst = imagecreatetruecolor($size, $size);
		imagealphablending($dst, FALSE);
		imagesavealpha($dst, TRUE);

		$clear = imagecolorallocatealpha($dst, 0, 0, 0, 127);
		imagefilledrectangle($dst, 0, 0, $size - 1, $size - 1, $clear);
		imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));

		return $dst;
	}

	/** Скругление: маска по четырём углам (внутренние пиксели не трогаем). */
	protected static function round_corners($im, $radius)
	{
		$w = imagesx($im);
		$h = imagesy($im);
		$r = (int) round(min($w, $h) * $radius / 100);

		if ($r < 1)
		{
			return;
		}

		$mask = imagecreatetruecolor($w, $h);
		imagealphablending($mask, FALSE);
		imagesavealpha($mask, TRUE);
		imagefilledrectangle($mask, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($mask, 0, 0, 0, 127));

		$white = imagecolorallocatealpha($mask, 255, 255, 255, 0);
		imagefilledrectangle($mask, $r, 0, $w - 1 - $r, $h - 1, $white);
		imagefilledrectangle($mask, 0, $r, $w - 1, $h - 1 - $r, $white);

		$d = $r * 2;
		imagefilledellipse($mask, $r, $r, $d, $d, $white);
		imagefilledellipse($mask, $w - 1 - $r, $r, $d, $d, $white);
		imagefilledellipse($mask, $r, $h - 1 - $r, $d, $d, $white);
		imagefilledellipse($mask, $w - 1 - $r, $h - 1 - $r, $d, $d, $white);

		$gone = imagecolorallocatealpha($im, 0, 0, 0, 127);
		$corners = [[0, 0], [$w - $r, 0], [0, $h - $r], [$w - $r, $h - $r]];

		foreach ($corners as $corner)
		{
			list($ox, $oy) = $corner;

			for ($y = 0; $y < $r; $y++)
			{
				for ($x = 0; $x < $r; $x++)
				{
					$px = $ox + $x;
					$py = $oy + $y;

					if ($px >= $w OR $py >= $h)
					{
						continue;
					}

					if (((imagecolorat($mask, $px, $py) >> 24) & 0x7F) === 127)
					{
						imagesetpixel($im, $px, $py, $gone);
					}
				}
			}
		}

		imagedestroy($mask);
	}

	/**
	 * Сырые байты RGBA (сверху вниз) — ими кормится ico_bytes().
	 * GD отдаёт альфу «наоборот»: 0 = непрозрачный, 127 = прозрачный.
	 */
	protected static function rgba_of($im, $size)
	{
		$out = '';

		for ($y = 0; $y < $size; $y++)
		{
			for ($x = 0; $x < $size; $x++)
			{
				$c     = imagecolorat($im, $x, $y);
				$alpha = 127 - (($c >> 24) & 0x7F);

				$out .= chr(($c >> 16) & 0xFF)
					.chr(($c >> 8) & 0xFF)
					.chr($c & 0xFF)
					.chr(min(255, (int) round($alpha * 255 / 127)));
			}
		}

		return $out;
	}

	protected static function png_bytes($im)
	{
		$level = self::clamp_int(Arr::get(self::config(), 'png_compression', 9), 0, 9, 9);

		ob_start();

		$ok = @imagepng($im, NULL, $level);
		$bytes = (string) ob_get_clean();

		return $ok ? $bytes : '';
	}

	/** Zip-архив комплекта + демо-страница с готовым <head>. */
	protected static function write_zip($dir, $zip_name, array $files, $html)
	{
		if ( ! class_exists('ZipArchive'))
		{
			return 0;
		}

		$zip_path = $dir.DIRECTORY_SEPARATOR.$zip_name;
		$zip      = new ZipArchive();

		if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE)
		{
			return 0;
		}

		foreach ($files as $file)
		{
			$path = $dir.DIRECTORY_SEPARATOR.$file['name'];

			if (is_file($path))
			{
				$zip->addFile($path, $file['name']);
			}
		}

		$zip->addFromString('index.html',
			"<!doctype html>\n<html lang=\"ru\">\n<head>\n<meta charset=\"utf-8\">\n"
			."<title>Фавиконки: пример подключения</title>\n".$html."</head>\n"
			."<body style=\"font:16px/1.5 system-ui;margin:40px\">\n"
			."<h1>Комплект иконок</h1>\n"
			."<p>Содержимое <code>&lt;head&gt;</code> этой страницы — готовый код для вашего сайта. "
			."Скопируйте его из исходника (Ctrl+U).</p>\n</body>\n</html>\n");

		$zip->close();

		return is_file($zip_path) ? (int) filesize($zip_path) : 0;
	}

	// ------------------------------------------------------------------
	// Журнал и выдача
	// ------------------------------------------------------------------

	/** Строка журнала: код, что рисовали, список файлов, сниппет. */
	protected static function save_pack($code, array $opt, array $result)
	{
		$uid = class_exists('Visitor') ? (string) Visitor::uid() : '';
		$ip  = class_exists('Service_Nj100_Ratelimit') ? (string) Service_Nj100_Ratelimit::client_ip() : '';

		$sql = 'INSERT INTO nj_663_packs
				(code, visitor_uid, client_ip, kind, source_json, options_json, files_json, bytes_total, html, base_path)
			VALUES ('
			.self::_q($code).', '
			.self::_q(mb_substr($uid, 0, 40)).', '
			.self::_q(mb_substr($ip, 0, 64)).', '
			.self::_q(mb_substr((string) Arr::get($opt, 'kind', 'upload'), 0, 16)).', '
			.self::_q(json_encode([
				'label' => (string) Arr::get($opt, 'label', ''),
				'text'  => (string) Arr::get($opt, 'text', ''),
			], JSON_UNESCAPED_UNICODE)).'::jsonb, '
			.self::_q(json_encode([
				'bg'         => $opt['bg'],
				'bg_alpha'   => $opt['bg_alpha'],
				'radius'     => $opt['radius'],
				'pad'        => $opt['pad'],
				'fit'        => $opt['fit'],
				'font'       => $opt['font'],
				'font_share' => $opt['font_share'],
				'bold'       => $opt['bold'],
				'italic'     => $opt['italic'],
				'fg'         => (string) Arr::get($opt, 'fg', ''),
			], JSON_UNESCAPED_UNICODE)).'::jsonb, '
			.self::_q(json_encode($result['files'], JSON_UNESCAPED_UNICODE)).'::jsonb, '
			.(int) $result['bytes'].', '
			.self::_q($result['html']).', '
			.self::_q($opt['base_path']).')
			RETURNING id';

		$row = self::_one($sql);

		return $row === NULL ? 0 : (int) Arr::get($row, 'id', 0);
	}

	/** Набор по коду (строка журнала). */
	public static function pack($code)
	{
		if ( ! self::is_code($code))
		{
			return NULL;
		}

		return self::_one('SELECT * FROM nj_663_packs WHERE code = '.self::_q($code).' LIMIT 1');
	}

	/** Файлы набора: из журнала, с проверкой, что файл ещё на диске. */
	public static function pack_files($code)
	{
		$pack = self::pack($code);

		if ($pack === NULL)
		{
			return NULL;
		}

		$files = json_decode((string) Arr::get($pack, 'files_json', '[]'), TRUE);
		$files = is_array($files) ? $files : [];
		$out   = [];

		foreach ($files as $file)
		{
			$name = self::safe_file_name(Arr::get($file, 'name', ''));

			if ($name === NULL)
			{
				continue;
			}

			$out[] = [
				'name'  => $name,
				'label' => (string) Arr::get($file, 'label', ''),
				'bytes' => (int) Arr::get($file, 'bytes', 0),
				'size'  => (int) Arr::get($file, 'size', 0),
				'url'   => self::file_url((string) $code, $name),
				'ready' => self::file_path($code, $name) !== NULL,
			];
		}

		return [
			'code'       => (string) $code,
			'kind'       => (string) Arr::get($pack, 'kind', ''),
			'html'       => (string) Arr::get($pack, 'html', ''),
			'base_path'  => (string) Arr::get($pack, 'base_path', '/'),
			'bytes'      => (int) Arr::get($pack, 'bytes_total', 0),
			'created_at' => (string) Arr::get($pack, 'created_at', ''),
			'files'      => $out,
		];
	}

	/** Путь к файлу на диске или NULL (имя только из белого списка). */
	public static function file_path($code, $name)
	{
		if ( ! self::is_code($code))
		{
			return NULL;
		}

		$name = self::safe_file_name($name);

		if ($name === NULL)
		{
			return NULL;
		}

		$path = self::storage_dir(FALSE).DIRECTORY_SEPARATOR.$code.DIRECTORY_SEPARATOR.$name;

		return is_file($path) ? $path : NULL;
	}

	/** Ссылка на файл набора (маршрут nj663_file). */
	public static function file_url($code, $name)
	{
		if ( ! self::is_code($code) OR self::safe_file_name($name) === NULL)
		{
			return '';
		}

		return self::FILE_URL.$code.'/'.$name;
	}

	/** Ссылка на страницу набора. */
	public static function pack_url($code)
	{
		return self::is_code($code) ? '/favicon-gen/p/'.$code : '';
	}

	public static function touch_download($code)
	{
		if ( ! self::is_code($code))
		{
			return;
		}

		try
		{
			self::_exec(Database::UPDATE, 'UPDATE nj_663_packs
				SET downloads = downloads + 1, last_access_at = now()
				WHERE code = '.self::_q($code));
		}
		catch (Throwable $e)
		{
			// Счётчик — не результат: молча пропускаем.
		}
	}

	// ------------------------------------------------------------------
	// Ограничитель и уборка
	// ------------------------------------------------------------------

	public static function rate_ok($ip = NULL)
	{
		if ( ! class_exists('Service_Nj100_Ratelimit'))
		{
			return TRUE;
		}

		$rate = (array) Arr::get(self::config(), 'rate', []);
		$ip   = $ip !== NULL ? (string) $ip : Service_Nj100_Ratelimit::client_ip();

		$counts = Service_Nj100_Ratelimit::window_counts('nj_663_packs', $ip, 'client_ip');

		return Service_Nj100_Ratelimit::allowed($counts,
			(int) Arr::get($rate, 'per_minute', 10),
			(int) Arr::get($rate, 'per_hour', 60));
	}

	public static function rate_text()
	{
		$rate = (array) Arr::get(self::config(), 'rate', []);

		return class_exists('Service_Nj100_Ratelimit')
			? Service_Nj100_Ratelimit::limit_text((int) Arr::get($rate, 'per_minute', 10),
				(int) Arr::get($rate, 'per_hour', 60), 'комплектов')
			: '';
	}

	/**
	 * Уборка: наборы старше ttl_days стираются с диска и из журнала.
	 * Вызывается на каждой генерации, поэтому дешёвая и ограниченная.
	 */
	public static function sweep($limit = NULL)
	{
		$ttl   = max(1, (int) Arr::get(self::config(), 'ttl_days', 30));
		$limit = (int) ($limit !== NULL ? $limit : Arr::get(self::config(), 'sweep_limit', 20));

		if ($limit < 1)
		{
			return 0;
		}

		$rows = self::_all('SELECT code FROM nj_663_packs
			WHERE created_at < now() - interval \''.$ttl.' days\'
			ORDER BY id LIMIT '.$limit);

		$gone = 0;

		foreach ($rows as $row)
		{
			$code = (string) Arr::get($row, 'code', '');

			if ( ! self::is_code($code))
			{
				continue;
			}

			self::delete_dir(self::storage_dir(FALSE).DIRECTORY_SEPARATOR.$code);
			self::_exec(Database::DELETE, 'DELETE FROM nj_663_packs WHERE code = '.self::_q($code));
			$gone++;
		}

		return $gone;
	}

	// ------------------------------------------------------------------
	// Внутреннее
	// ------------------------------------------------------------------

	/**
	 * Опции генерации из полей формы/запроса.
	 *
	 * Пустые поля не подменяются нулями: «не указано» = взять из конфига
	 * (иначе слайдер, оставленный как есть, сбрасывался бы в 0).
	 *
	 * @param array $body поля запроса
	 */
	public static function options_from_post(array $body)
	{
		$options = [
			'kind'      => (string) Arr::get($body, 'kind', 'upload'),
			'text'      => (string) Arr::get($body, 'text', ''),
			'label'     => (string) Arr::get($body, 'label', ''),
			'font'      => (string) Arr::get($body, 'font', ''),
			'bold'      => (bool) Arr::get($body, 'bold', FALSE),
			'italic'    => (bool) Arr::get($body, 'italic', FALSE),
			'title'     => (string) Arr::get($body, 'title', ''),
			'bg'        => (string) Arr::get($body, 'bg', ''),
			'bg_alpha'  => Arr::get($body, 'bg_alpha') !== NULL
							AND Arr::get($body, 'bg_alpha') !== FALSE
							AND Arr::get($body, 'bg_alpha') !== '0',
			'composed'  => (bool) Arr::get($body, 'composed', FALSE),
			'base_path' => (string) Arr::get($body, 'base_path', '/'),
		];

		// Числа и режим вписывания — только если пришли из формы.
		foreach (['radius', 'pad', 'font_share'] as $key)
		{
			if (Arr::get($body, $key) !== NULL AND Arr::get($body, $key) !== '')
			{
				$options[$key] = Arr::get($body, $key);
			}
		}

		$fit = (string) Arr::get($body, 'fit', '');

		if (in_array($fit, ['contain', 'cover', 'stretch'], TRUE))
		{
			$options['fit'] = $fit;
		}

		return $options;
	}

	/**
	 * Байты исходника из запроса: image_b64 (канвас браузера, обычн data URL)
	 * или загруженный файл полем image (работает без JS).
	 *
	 * @param  array $body поля запроса (NULL — взять $_POST)
	 * @return string|NULL NULL — прочитать не удалось, '' — исходника нет
	 */
	public static function source_bytes(array $body = NULL)
	{
		if ($body === NULL)
		{
			$body = (array) $_POST;
		}

		$b64 = trim((string) Arr::get($body, 'image_b64', ''));

		if ($b64 !== '')
		{
			if (strpos($b64, ',') !== FALSE AND strpos($b64, 'base64') !== FALSE)
			{
				$b64 = (string) substr($b64, strpos($b64, ',') + 1);
			}

			$raw = base64_decode($b64, TRUE);

			return $raw === FALSE ? NULL : $raw;
		}

		if ( ! isset($_FILES['image']) OR ! is_array($_FILES['image']))
		{
			return '';
		}

		$error = (int) Arr::get($_FILES['image'], 'error', UPLOAD_ERR_NO_FILE);

		if ($error !== UPLOAD_ERR_OK)
		{
			return $error === UPLOAD_ERR_NO_FILE ? '' : NULL;
		}

		$tmp = (string) Arr::get($_FILES['image'], 'tmp_name', '');

		if ($tmp === '' OR ! is_uploaded_file($tmp))
		{
			return NULL;
		}

		$max = max(1, (int) Arr::get(self::limits(), 'max_input_mb', 8)) * 1024 * 1024;

		if ((int) Arr::get($_FILES['image'], 'size', 0) > $max)
		{
			return NULL;
		}

		$bytes = (string) @file_get_contents($tmp);

		return $bytes === '' ? NULL : $bytes;
	}

	/** Нормализованные опции генерации (все ключи всегда на месте). */
	protected static function _options(array $options)
	{
		$defaults = self::defaults();
		$limits   = self::limits();

		$opt = [
			'code'      => (string) Arr::get($options, 'code', ''),
			// composed=TRUE — браузер уже собрал полотно на канвасе (эмодзи,
			// фон, отступ и скругление в нём). Сервер не должен применять их
			// второй раз: иначе картинка съедет и углы скругятся дважды.
			'compose'   => ! (bool) Arr::get($options, 'composed', FALSE),
			'kind'      => (string) Arr::get($options, 'kind', 'upload'),
			'label'     => mb_substr(trim((string) Arr::get($options, 'label', '')), 0, 120),
			'text'      => mb_substr(trim((string) Arr::get($options, 'text', '')), 0,
								(int) Arr::get($limits, 'max_text_chars', 4)),
			'font'      => self::font_key(Arr::get($options, 'font', Arr::get($defaults, 'font', ''))),
			// Границы — из конфига: те же, что стоят на ползунке в браузере.
			// Иначе сервер молча срезал бы крупный символ, выбранный человеком.
			'font_share'=> self::clamp_int(Arr::get($options, 'font_share', Arr::get($defaults, 'font_share', 56)),
								(int) Arr::get($limits, 'font_share_min', 20),
								(int) Arr::get($limits, 'font_share_max', 200),
								(int) Arr::get($defaults, 'font_share', 56)),
			'bold'      => (bool) Arr::get($options, 'bold', Arr::get($defaults, 'bold', FALSE)),
			'italic'    => (bool) Arr::get($options, 'italic', Arr::get($defaults, 'italic', FALSE)),
			'title'     => mb_substr(trim((string) Arr::get($options, 'title', '')), 0, 80),
			'bg'        => self::normalize_color(Arr::get($options, 'bg', Arr::get($defaults, 'bg', '')), ''),
			'bg_alpha'  => (bool) Arr::get($options, 'bg_alpha', Arr::get($defaults, 'bg_alpha', FALSE)),
			'radius'    => self::clamp_int(Arr::get($options, 'radius', Arr::get($defaults, 'radius', 0)),
								0, (int) Arr::get($limits, 'radius_max', 50), 0),
			'pad'       => self::clamp_int(Arr::get($options, 'pad', Arr::get($defaults, 'pad', 0)),
								0, (int) Arr::get($limits, 'pad_max', 30), 0),
			'fit'       => (string) Arr::get($options, 'fit', Arr::get($defaults, 'fit', 'contain')),
			'base_path' => self::normalize_base_path(Arr::get($options, 'base_path',
								Arr::get($defaults, 'base_path', '/'))),
		];

		if ( ! in_array($opt['fit'], ['contain', 'cover', 'stretch'], TRUE))
		{
			$opt['fit'] = 'contain';
		}

		return $opt;
	}

	/** '#rrggbb' → [r, g, b]. */
	public static function rgb_of($hex)
	{
		$hex = ltrim((string) $hex, '#');

		if (strlen($hex) !== 6)
		{
			return [0, 0, 0];
		}

		return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
	}

	/** Каталог хранения: public_html/storage/nj663 (как у остальных модулей). */
	public static function storage_dir($create = TRUE)
	{
		$dir = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj663';

		if ($create AND ! is_dir($dir))
		{
			@mkdir($dir, 0775, TRUE);
		}

		return $dir;
	}

	/** Каталог конкретного набора (создаётся при необходимости). */
	public static function pack_dir($code, $create = TRUE)
	{
		if ( ! self::is_code($code))
		{
			return NULL;
		}

		$dir = self::storage_dir($create).DIRECTORY_SEPARATOR.$code;

		if ($create AND ! is_dir($dir) AND ! @mkdir($dir, 0775, TRUE))
		{
			return NULL;
		}

		return is_dir($dir) ? $dir : NULL;
	}

	/** Удалить каталог с содержимым. */
	public static function delete_dir($dir)
	{
		$dir = (string) $dir;

		if ($dir === '' OR ! is_dir($dir))
		{
			return FALSE;
		}

		foreach (array_diff(scandir($dir), ['.', '..']) as $item)
		{
			$path = $dir.DIRECTORY_SEPARATOR.$item;

			if (is_dir($path))
			{
				self::delete_dir($path);
			}
			else
			{
				@unlink($path);
			}
		}

		return @rmdir($dir);
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
		return self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();
	}

	protected static function _exec($type, $sql)
	{
		return self::_db()->query($type, $sql, FALSE);
	}
}
