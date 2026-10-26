<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Генератор favicon (nj663_favicon) — страница /favicon-gen.
 *
 * Компоновка — как в RedKetchup (эталон владельца, 09.10.2026):
 *   СЛЕВА  — элементы управления (две вкладки: «Буква или эмодзи» по
 *            умолчанию и «Из картинки»); под ними — справка;
 *   СПРАВА — живое превью, ползунки формы, кнопка сборки и результат
 *            (файлы, превью, HTML-код). Раньше результат стоял слева под
 *            формой, а справа висела справка — человек крутил настройки и
 *            не видел, что получается.
 *
 * Поля намеренно узкие: по эталону это компактная панель, а не анкета.
 * «Путь на сайте» и «Название» убраны совсем (09.10.2026): путь всегда «/»,
 * название в manifest подставляется из заголовка сайта.
 *
 * Считает браузер (рисует полотно 512) и сервер (размножает по размерам,
 * собирает ICO/манифест/zip). Без JS работает загрузка файла: форма
 * постится на эту же страницу, результат рисует PHP (см. $result).
 *
 * @var array  $defaults  умолчания формы из конфига
 * @var array  $limits    лимиты (радиус, отступ, размер файла)
 * @var array  $sizes     размеры PNG
 * @var array  $ico_sizes размеры внутри favicon.ico
 * @var array  $targets   имя файла → размер
 * @var array  $fonts     шрифты символа: ключ → ['label', 'css']
 * @var string $rate_text человекочитаемый лимит
 * @var string $csrf      токен
 * @var array|NULL $result результат генерации без JS (серверный POST)
 */
$defaults  = isset($defaults) ? (array) $defaults : [];
$limits    = isset($limits) ? (array) $limits : [];
$sizes     = isset($sizes) ? (array) $sizes : [];
$ico_sizes = isset($ico_sizes) ? (array) $ico_sizes : [];
$targets   = isset($targets) ? (array) $targets : [];
$fonts     = isset($fonts) ? (array) $fonts : [];
$rate_text = isset($rate_text) ? (string) $rate_text : '';
$result    = isset($result) ? $result : NULL;

$bg        = (string) Arr::get($defaults, 'bg', '#4f46e5');
$fg        = (string) Arr::get($defaults, 'fg', '#ffffff');
$radius    = (int) Arr::get($defaults, 'radius', 22);
$pad       = (int) Arr::get($defaults, 'pad', 8);
$font_key  = (string) Arr::get($defaults, 'font', 'sans');
$font_size = (int) Arr::get($defaults, 'font_share', 56);
$bold      = (bool) Arr::get($defaults, 'bold', FALSE);
$italic    = (bool) Arr::get($defaults, 'italic', FALSE);
$text      = (string) Arr::get($defaults, 'text', 'N');
$base_size = (int) Arr::get($defaults, 'base_size', 512);
$max_mb    = (int) Arr::get($limits, 'max_input_mb', 8);
$max_chars = (int) Arr::get($limits, 'max_text_chars', 4);

// Пределы ползунка «Размер шрифта» — из конфига, а не числами в разметке:
// сервер принимает запрос ровно в этих же границах (Service::_options).
$share_min = (int) Arr::get($limits, 'font_share_min', 20);
$share_max = (int) Arr::get($limits, 'font_share_max', 200);

$label  = 'n2j-label';
$input  = 'n2j-input mt-1 w-full';
$select = 'n2j-input n2j-select mt-1 w-full cursor-pointer';
$hint   = 'mt-1 text-xs leading-relaxed text-gray-400 dark:text-gray-500';

// Результат, собранный сервером (форма без JS).
$files = $result ? (array) Arr::get($result, 'files', []) : [];
$html  = $result ? (string) Arr::get($result, 'html', '') : '';
$code  = $result ? (string) Arr::get($result, 'code', '') : '';

$tab_on  = 'nj663-tab n2j-chip flex-1 justify-center is-on';
$tab_off = 'nj663-tab n2j-chip flex-1 justify-center';
?>

<input type="hidden" id="nj663-csrf" value="<?php echo HTML::chars(isset($csrf) ? (string) $csrf : ''); ?>">

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
		<h1 class="n2j-h1">Генератор favicon</h1>
		<span class="inline-flex items-center gap-1.5 rounded-full bg-amber-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
			<i class="fas fa-icons text-[11px]"></i>Полный комплект иконок
		</span>
	</div>
	<p class="mt-1.5 max-w-4xl text-xs sm:text-sm text-gray-600 dark:text-gray-300">
		Буква, цифра или эмодзи — либо своя картинка. На выходе PNG всех размеров, apple-touch-icon,
		многоразмерный favicon.ico, site.webmanifest, zip-архив и готовый код для
		<code class="rounded bg-gray-100 px-1 dark:bg-gray-800">&lt;head&gt;</code>.
		Исходник не уходит никуда: всё считается в вашем браузере и на этом сервере.
	</p>

	<div class="mt-5 grid gap-4 lg:grid-cols-12 lg:items-start">

		<!-- ===================== СЛЕВА: элементы управления ===================== -->
		<div class="space-y-4 lg:col-span-7">

			<form id="nj663-form" method="post" action="/favicon-gen" enctype="multipart/form-data"
				  class="n2j-card">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars(isset($csrf) ? (string) $csrf : ''); ?>">

				<!-- вкладки: «Буква или эмодзи» — по умолчанию -->
				<div class="flex gap-2" role="tablist">
					<button type="button" data-tab="text" class="<?php echo $tab_on; ?>">Буква или эмодзи</button>
					<button type="button" data-tab="image" class="<?php echo $tab_off; ?>">Из картинки</button>
				</div>

				<!-- ==== вкладка: буква или эмодзи ==== -->
				<div class="nj663-pane mt-4" data-pane="text">
					<label class="<?php echo $label; ?>" for="nj663-text">Буква, цифра или эмодзи</label>
					<input id="nj663-text" name="text" type="text" maxlength="<?php echo (int) $max_chars; ?>"
						   value="<?php echo HTML::chars($text); ?>"
						   class="<?php echo $input; ?> text-base" placeholder="N, 7, 🚀">

					<div class="mt-3 grid grid-cols-2 gap-3">
						<div>
							<label class="<?php echo $label; ?>" for="nj663-font">Шрифт</label>
							<select id="nj663-font" name="font" class="<?php echo $select; ?>">
								<?php foreach ($fonts as $key => $info): ?>
								<option value="<?php echo HTML::chars((string) $key); ?>" <?php echo (string) $key === $font_key ? 'selected' : ''; ?>>
									<?php echo HTML::chars((string) Arr::get($info, 'label', $key)); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div>
							<span class="<?php echo $label; ?>">Начертание</span>
							<div class="mt-1 flex gap-2">
								<button type="button" id="nj663-bold" data-on="<?php echo $bold ? '1' : '0'; ?>"
										title="Жирный"
										class="n2j-chip flex-1 justify-center font-bold<?php echo $bold ? ' is-on' : ''; ?>">Ж</button>
								<button type="button" id="nj663-italic" data-on="<?php echo $italic ? '1' : '0'; ?>"
										title="Курсив"
										class="n2j-chip flex-1 justify-center italic<?php echo $italic ? ' is-on' : ''; ?>">К</button>
							</div>
						</div>
					</div>

					<div class="mt-3 grid grid-cols-2 gap-3">
						<div>
							<label class="<?php echo $label; ?>" for="nj663-font-share">
								Размер шрифта: <span id="nj663-font-share-out"><?php echo (int) round($base_size * $font_size / 100); ?></span> px
							</label>
							<input id="nj663-font-share" name="font_share" type="range"
								   min="<?php echo (int) $share_min; ?>" max="<?php echo (int) $share_max; ?>" step="1"
								   value="<?php echo (int) $font_size; ?>" class="mt-2 w-full accent-amber-600">
							<p class="n2j-hint">
								<?php echo (int) round($base_size * $share_min / 100); ?>–<?php echo (int) round($base_size * $share_max / 100); ?> px:
								верх уходит за край квадрата — так символ ложится во всю иконку.
							</p>
						</div>
						<div>
							<label class="<?php echo $label; ?>" for="nj663-fg">Цвет символа</label>
							<input id="nj663-fg" name="fg" type="color" value="<?php echo HTML::chars($fg); ?>"
								   class="mt-1 h-[38px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white p-1 dark:border-gray-600 dark:bg-gray-900">
						</div>
					</div>
				</div>

				<!-- ==== вкладка: картинка ==== -->
				<div class="nj663-pane mt-4 hidden" data-pane="image">
					<label class="flex border-dashed flex-col items-center justify-center gap-1.5 rounded-lg border-2 border-gray-300 bg-gray-50 px-3 py-5 text-center text-sm text-gray-600 transition dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300"
						   id="nj663-drop" for="nj663-file">
						<i class="fas fa-cloud-arrow-up text-lg text-amber-600"></i>
						<span class="font-semibold">Выберите файл, перетащите его сюда</span>
						<span class="text-xs text-gray-400">или вставьте из буфера (Ctrl+V)</span>
					</label>
					<input id="nj663-file" name="image" type="file" accept="image/png,image/jpeg,image/gif,image/webp" class="hidden">
					<p class="<?php echo $hint; ?>">PNG, JPEG, GIF или WebP, до <?php echo (int) $max_mb; ?> МБ. Лучше всего — квадратная картинка от 512×512.</p>

					<div class="mt-3 grid grid-cols-2 gap-3">
						<div>
							<label class="<?php echo $label; ?>" for="nj663-fit">Как вписать</label>
							<select id="nj663-fit" name="fit" class="<?php echo $select; ?>">
								<option value="contain">Целиком (с полями)</option>
								<option value="cover">Заполнить (обрезка)</option>
								<option value="stretch">Растянуть</option>
							</select>
						</div>
						<div class="flex items-end">
							<p class="n2j-hint pb-2" id="nj663-image-note">Картинка не выбрана</p>
						</div>
					</div>
				</div>

				<!-- ==== общие: фон ==== -->
				<div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700/60">
					<div class="grid grid-cols-2 gap-3">
						<div>
							<label class="<?php echo $label; ?>" for="nj663-bg">Фон</label>
							<input id="nj663-bg" name="bg" type="color" value="<?php echo HTML::chars($bg); ?>"
								   class="mt-1 h-[38px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white p-1 dark:border-gray-600 dark:bg-gray-900">
						</div>
						<label class="flex items-end gap-2 pb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
							<input id="nj663-alpha" name="bg_alpha" type="checkbox" value="1"
								   class="h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-900">
							Прозрачный
						</label>
					</div>
				</div>
			</form>

			<!-- ============================ справка ============================ -->
			<div class="n2j-card">
				<h2 class="text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">Что получите</h2>
				<ul class="mt-3 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
					<li><i class="fas fa-check text-amber-600"></i> PNG: <?php echo HTML::chars(implode(', ', array_map(function ($s) { return (int) $s.'×'.$s; }, $sizes))); ?></li>
					<li><i class="fas fa-check text-amber-600"></i> apple-touch-icon.png (180) и android-chrome 192/512</li>
					<li><i class="fas fa-check text-amber-600"></i> favicon.ico: <?php echo HTML::chars(implode(', ', $ico_sizes)); ?> — одним файлом</li>
					<li><i class="fas fa-check text-amber-600"></i> site.webmanifest для Android и Chrome</li>
					<li><i class="fas fa-check text-amber-600"></i> zip-архив и готовый HTML-код</li>
				</ul>
				<p class="n2j-hint mt-3">Всего файлов в комплекте: <?php echo (int) (count($targets) + 3); ?>.</p>
			</div>

			<div class="n2j-card">
				<h2 class="text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">Как подключить</h2>
				<ol class="mt-3 list-decimal space-y-1.5 pl-5 text-sm text-gray-600 dark:text-gray-300">
					<li>Скачайте zip и распакуйте в корень сайта.</li>
					<li>Скопируйте HTML-код в <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">&lt;head&gt;</code>.</li>
					<li>Проверьте, что браузер отдаёт иконки по указанному пути.</li>
				</ol>
				<p class="n2j-hint mt-3">Код собирается под путь <code>/</code> — файлы кладите в корень сайта.</p>
			</div>

			<div class="n2j-card">
				<h2 class="text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">Для разработчика</h2>
				<ul class="mt-3 space-y-2 text-sm">
					<li><a href="/favicon-gen/api/sizes" class="text-amber-600 hover:underline dark:text-amber-400">/favicon-gen/api/sizes</a> <span class="text-gray-500 dark:text-gray-400">— справочник размеров</span></li>
					<li><span class="font-mono text-xs text-gray-500 dark:text-gray-400">POST /favicon-gen/api/generate</span> <span class="text-gray-500 dark:text-gray-400">— собрать комплект (поле image_b64)</span></li>
				</ul>
				<p class="n2j-hint mt-3">Комплект живёт 30 дней, потом выметается. Ссылка на набор — ваш ключ: посторонний её не угадает.</p>
			</div>
		</div>

		<!-- ===================== СПРАВА: превью и результат ===================== -->
		<div class="space-y-4 lg:col-span-5">

			<div class="n2j-card">
				<h2 class="text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">Превью</h2>

				<div class="mt-3 flex flex-wrap items-center gap-4">
					<canvas id="nj663-canvas" width="512" height="512"
							class="n2j-checker h-32 w-32 rounded-xl border border-gray-200 dark:border-gray-700"></canvas>
					<div class="min-w-0 space-y-2">
						<div class="flex items-end gap-3">
							<canvas id="nj663-mini-32" width="32" height="32" class="h-8 w-8 rounded"></canvas>
							<canvas id="nj663-mini-16" width="16" height="16" class="h-4 w-4 rounded-sm"></canvas>
							<span class="text-xs text-gray-400 dark:text-gray-500">32 и 16 px — как в браузере</span>
						</div>
						<div class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900">
							<canvas id="nj663-mini-tab" width="16" height="16" class="h-4 w-4"></canvas>
							<span class="truncate text-sm text-gray-600 dark:text-gray-300">Название сайта — вкладка</span>
						</div>
					</div>
				</div>

				<!-- форма: как в RedKetchup — ползунки под превью -->
				<div class="mt-4">
					<div class="flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
						<span>Скругление</span>
						<span><i class="fas fa-square text-[10px]"></i> <span id="nj663-radius-out"><?php echo (int) $radius; ?></span>% <i class="fas fa-circle text-[10px]"></i></span>
					</div>
					<input id="nj663-radius" name="radius" type="range" min="0" max="<?php echo (int) Arr::get($limits, 'radius_max', 50); ?>" step="1"
						   value="<?php echo (int) $radius; ?>" class="mt-1.5 w-full accent-amber-600">
				</div>

				<div class="mt-3 hidden" id="nj663-pad-row">
					<div class="flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
						<span>Отступ от края</span>
						<span><span id="nj663-pad-out"><?php echo (int) $pad; ?></span>%</span>
					</div>
					<input id="nj663-pad" name="pad" type="range" min="0" max="<?php echo (int) Arr::get($limits, 'pad_max', 30); ?>" step="1"
						   value="<?php echo (int) $pad; ?>" class="mt-1.5 w-full accent-amber-600">
				</div>

				<div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700/60">
					<button type="submit" id="nj663-go" form="nj663-form" class="n2j-btn-process w-full">
						<i class="fas fa-wand-magic-sparkles"></i>Собрать комплект
					</button>
					<p id="nj663-status" class="mt-2 text-center text-sm text-gray-500 dark:text-gray-400"></p>
					<?php if ($rate_text !== ''): ?>
						<p class="n2j-hint text-center">Лимит: <?php echo HTML::chars($rate_text); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<!-- ============================ результат ============================ -->
			<div id="nj663-result" class="n2j-card">
				<?php if ( ! empty($error)): ?>
					<div class="n2j-flash n2j-flash-err mb-4 font-medium block">
						<i class="fas fa-circle-exclamation"></i> <?php echo HTML::chars((string) $error); ?>
					</div>
				<?php endif; ?>

				<?php if ( ! $files): ?>
					<h2 class="text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-white">Файлы и код</h2>
					<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Здесь появятся файлы, превью и готовый HTML-код. Пока — пусто: сверху уже видно, как иконка будет выглядеть в строке браузера.</p>
				<?php endif; ?>

				<?php if ($files): ?>
					<?php echo View::factory('663/result', ['files' => $files, 'html' => $html, 'code' => $code]); ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<?php echo View::factory('663/script', [
	'limits'   => $limits,
	'fonts'    => $fonts,
	'defaults' => $defaults,
	'base_size'=> $base_size,
]); ?>
