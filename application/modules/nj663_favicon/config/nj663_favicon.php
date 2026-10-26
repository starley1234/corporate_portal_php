<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг «Генератор favicon» (nj663_favicon).
 *
 * Здесь только значения, которые можно менять без пересборки: набор
 * размеров, умолчания формы, лимиты и срок жизни наборов. Пути, секреты и
 * прочее окружение — через .env (их у модуля нет: всё считает локальный GD).
 */
return [

	// Базовое полотно: из него ресемплируются все размеры (шаг вниз пополам —
	// мелкие иконки 16/32 остаются резкими, а не «мылом» с одного маха).
	'base_size' => 512,

	// PNG всех размеров. 180 — apple-touch-icon, 192/512 — android-chrome.
	'sizes' => [16, 32, 48, 64, 96, 128, 180, 192, 256, 384, 512],

	// Что кладём внутрь favicon.ico (классический мультисайз-формат).
	'ico_sizes' => [16, 32, 48],

	// Дубли нужных размеров под имена, которые ждут браузеры и генераторы.
	'aliases' => [
		'apple-touch-icon.png'     => 180,
		'android-chrome-192x192.png' => 192,
		'android-chrome-512x512.png' => 512,
	],

	// Уровень сжатия PNG (0–9) и имя zip-архива.
	'png_compression' => 9,
	'zip_name'        => 'favicon-pack.zip',

	// Лимиты на один запрос.
	'limits' => [
		'max_input_mb'    => 8,
		'max_text_chars'  => 4,     // буква, цифра или пара эмодзи
		'radius_max'      => 50,    // процент от стороны
		'pad_max'         => 30,    // процент от стороны
		'base_path_max'   => 120,
		'code_len'        => 10,
		// Размер символа, % стороны полотна (512 → 20 % это 102 px, 200 % это
		// 1024 px). Верх взят с запасом: у буквы высота строчной ~0,7 кегля,
		// поэтому «во весь квадрат» — это примерно 140 %, а 200 % позволяет
		// намеренно пустить символ за край (такая иконка тоже нужна).
		// Ползунок и приёмка запроса читают одни и те же числа — иначе
		// сервер молча срезал бы то, что человек выбрал ползунком.
		'font_share_min'  => 20,
		'font_share_max'  => 200,
	],

	// Умолчания формы (их же рисует превью в браузере).
	'defaults' => [
		'fit'      => 'contain',   // contain | cover | stretch
		'bg'       => '#4f46e5',   // фон подложки
		'bg_alpha' => FALSE,       // TRUE — фон прозрачный (галочка «без фона»)
		'radius'   => 22,          // скругление, % стороны
		'pad'      => 8,           // внутренний отступ, % стороны (для картинки)
		'text'     => 'N',
		'fg'       => '#ffffff',
		'font'       => 'sans',    // ключ из 'fonts' ниже
		'font_share' => 56,        // размер символа, % стороны (512 → 287 px)
		'bold'       => FALSE,
		'italic'     => FALSE,
		'base_path' => '/',
	],

	// Шрифты символа: только системные стеки — ничего не качаем с Google
	// Fonts, буква рисуется канвасом браузера и сразу уходит на сервер
	// готовым PNG. Ключ → подпись и CSS-стек для ctx.font.
	'fonts' => [
		'sans'      => ['label' => 'Без засечек',   'css' => 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif'],
		'serif'     => ['label' => 'С засечками',   'css' => 'Georgia,"Times New Roman",Times,serif'],
		'rounded'   => ['label' => 'Закруглённый',  'css' => '"Trebuchet MS",Verdana,Geneva,sans-serif'],
		'mono'      => ['label' => 'Моноширинный',  'css' => '"Courier New",Courier,monospace'],
		'condensed' => ['label' => 'Узкий',         'css' => '"Arial Narrow","Helvetica Neue",Arial,sans-serif'],
		'heavy'     => ['label' => 'Плотный',       'css' => 'Impact,"Arial Black",Haettenschweiler,sans-serif'],
		'script'    => ['label' => 'Рукописный',    'css' => '"Comic Sans MS","Segoe Script",cursive'],
	],

	// Сколько живёт набор (файлы + строка журнала) и сколько наборов
	// вычищается за один проход (выметание вызывается на каждой генерации).
	'ttl_days'     => 30,
	'sweep_limit'  => 20,

	// Защита от «покрутить генератор»:GD считает локально, но место на диске
	// и процессор не резиновые.
	'rate' => [
		'per_minute' => (int) (getenv('NJ663_RATE_PER_MINUTE') ?: 10),
		'per_hour'   => (int) (getenv('NJ663_RATE_PER_HOUR') ?: 60),
	],
];
