<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Озвучивание текста (nj665): форма, плеер и история озвучек.
 *
 * Форма работает и без JS (обычный POST на /voice), но со скриптом
 * перехватывается: синтез уходит в /voice/api/synthesize, плеер и строка
 * истории появляются без перезагрузки — текст в поле остаётся, его правят
 * и озвучивают заново.
 */

$models      = isset($models) ? (array) $models : [];
$voices      = isset($voices) ? (array) $voices : [];
$formats     = isset($formats) ? (array) $formats : [];
$languages   = isset($languages) ? (array) $languages : [];
$history     = isset($history) ? (array) $history : [];
$result      = isset($result) ? (array) $result : NULL;
$limits      = isset($limits) ? (array) $limits : [];
$max_chars   = (int) Arr::get($limits, 'max_chars', 4000);
$post        = isset($post) ? (array) $post : [];
$text_value  = (string) Arr::get($post, 'text', '');

$format_titles = [];

foreach ($formats as $fmt)
{
	$format_titles[$fmt] = (string) Arr::get(Arr::get(Service_Nj665_Voice::format_map(), $fmt, []), 'title', $fmt);
}

$bytes_human = function ($bytes) {
	$bytes = (int) $bytes;

	if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', ' ').' МБ';
	if ($bytes >= 1024)    return number_format($bytes / 1024, 0, ',', ' ').' КБ';

	return $bytes.' б';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<a href="/catalog" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сервисы</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-medium text-gray-700 dark:text-gray-200">Озвучивание текста</span>
	</nav>

	<h1 class="n2j-h1 mt-2"><i class="fas fa-volume-high mr-2 text-blue-500"></i>Озвучивание текста</h1>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Напечатайте текст — получите аудиофайл: выберите модель, голос, скорость и формат, при желании подскажите «как говорить» (тихо, радостно, как диктор новостей). Файл играет прямо в браузере и скачивается одной кнопкой.</p>

	<?php if (empty($enabled)): ?>
	<div class="n2j-flash n2j-flash-warn mt-4 flex items-start gap-3 text-xs">
		<i class="fas fa-triangle-exclamation mt-0.5 shrink-0"></i>
		<span>Сервис не настроен: в <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/50">.env</code> не заданы <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/50">NJ665_API_URL</code> и <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/50">NJ665_API_KEY</code>. Страница работает, но синтез недоступен.</span>
	</div>
	<?php endif; ?>

	<?php if ((string) $error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars((string) $error); ?></span>
	</div>
	<?php endif; ?>

	<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

		<!-- ============ Форма + плеер + история (2/3) ============ -->
		<div class="min-w-0 space-y-5 md:col-span-2">

			<?php if (empty($logged)): ?>
			<?php echo Gate::wall('voice', [
				'icon'   => 'fas fa-volume-high',
				'title'  => 'Озвучивание текста — по бесплатному аккаунту',
				'text'   => 'Синтез речи считается на внешнем сервисе и стоит времени GPU, поэтому запускать его могут только зарегистрированные. Аккаунт бесплатный и привязывает историю озвучек к вам.',
				'points' => [
					'До '.$max_chars.' символов за раз и любой из форматов модели',
					'История озвучек: вернуться к файлу с любого устройства',
					'JSON API для своих скриптов и агентов',
					'Лимит: '.(string) $rate_text,
				],
				'stats'  => ['mp3, wav, opus, flac, aac', 'OmniVoice API v1'],
				'cta'    => 'Войти и озвучить текст',
			]); ?>
			<?php endif; ?>

			<form id="nj665-form" method="post" action="/voice" class="n2j-card">
				<input type="hidden" name="csrf" id="nj665-csrf" value="<?php echo HTML::chars((string) $csrf); ?>">

				<label for="nj665-text" class="n2j-label">Текст для озвучивания</label>
				<textarea id="nj665-text" name="text" rows="7"
					class="n2j-textarea mt-2 w-full resize-y"
					placeholder="Например: Добрый день. Это тестовая озвучка текста сервисом n2j.ru."><?php echo HTML::chars($text_value); ?></textarea>

				<div class="mt-1.5 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
					<span id="nj665-hint">Максимум <?php echo (int) $max_chars; ?> символов — длинный текст режется по границе.</span>
					<span id="nj665-count" class="font-semibold">0 / <?php echo (int) $max_chars; ?></span>
				</div>

				<div class="mt-4 grid gap-3 sm:grid-cols-2">

					<div>
						<label for="nj665-model" class="n2j-label">Модель</label>
						<select id="nj665-model" name="model"
							class="n2j-input n2j-select mt-1 w-full">
							<?php if ( ! $models): ?>
							<option value=""><?php echo HTML::chars((string) $model); ?></option>
							<?php endif; ?>
							<?php foreach ($models as $row): ?>
							<option value="<?php echo HTML::chars((string) Arr::get($row, 'id')); ?>"<?php echo Arr::get($row, 'id') === $model ? ' selected' : ''; ?>><?php echo HTML::chars((string) Arr::get($row, 'id')); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div>
						<label for="nj665-voice" class="n2j-label">Голос</label>
						<select id="nj665-voice" name="voice"
							class="n2j-input n2j-select mt-1 w-full">
							<?php if ( ! $voices): ?>
							<option value=""><?php echo $voices ? '' : 'голоса недоступны'; ?></option>
							<?php endif; ?>
							<?php foreach ($voices as $one): ?>
							<option value="<?php echo HTML::chars((string) $one); ?>"<?php echo $one === $voice ? ' selected' : ''; ?>><?php echo HTML::chars((string) $one); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div>
						<label for="nj665-format" class="n2j-label">Формат файла</label>
						<select id="nj665-format" name="format"
							class="n2j-input n2j-select mt-1 w-full">
							<?php foreach ($formats as $fmt): ?>
							<option value="<?php echo HTML::chars((string) $fmt); ?>"<?php echo $fmt === $format ? ' selected' : ''; ?>><?php echo HTML::chars((string) Arr::get($format_titles, $fmt, $fmt)); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div>
						<label for="nj665-language" class="n2j-label">Язык</label>
						<select id="nj665-language" name="language"
							class="n2j-input n2j-select mt-1 w-full">
							<?php foreach ($languages as $code => $title): ?>
							<option value="<?php echo HTML::chars((string) $code); ?>"<?php echo (string) $code === (string) $language ? ' selected' : ''; ?>><?php echo HTML::chars((string) $title); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

				</div>

				<div class="mt-4">
					<label for="nj665-speed" class="flex items-center justify-between text-xs font-semibold text-gray-700 dark:text-gray-300">
						<span>Скорость речи</span>
						<span id="nj665-speed-value" class="font-bold text-blue-600 dark:text-blue-400"><?php echo number_format((float) $speed, 2, '.', ''); ?>×</span>
					</label>
					<input id="nj665-speed" name="speed" type="range"
						min="<?php echo (float) Arr::get($limits, 'speed_min', 0.5); ?>"
						max="<?php echo (float) Arr::get($limits, 'speed_max', 2.0); ?>"
						step="0.05" value="<?php echo (float) $speed; ?>"
						class="mt-2 w-full accent-blue-600">
					<div class="flex justify-between text-xs text-gray-400 dark:text-gray-500">
						<span><?php echo (float) Arr::get($limits, 'speed_min', 0.5); ?>× медленно</span>
						<span><?php echo (float) Arr::get($limits, 'speed_max', 2.0); ?>× быстро</span>
					</div>
				</div>

				<div class="mt-4">
					<label for="nj665-instructions" class="n2j-label">Как говорить <span class="font-normal text-gray-400">(необязательно)</span></label>
					<input id="nj665-instructions" name="instructions" type="text" maxlength="<?php echo (int) Arr::get($limits, 'max_instructions', 500); ?>"
						value="<?php echo HTML::chars((string) Arr::get($post, 'instructions', '')); ?>"
						placeholder="Говори спокойно и уверенно, как диктор новостей"
						class="n2j-input mt-1 w-full">
					<p class="n2j-hint">Инструкция уезжает в модель как есть: тон, темп, характер. Понимает не каждая модель — если голос не меняется, оставьте поле пустым.</p>
				</div>

				<div class="mt-5 flex flex-wrap items-center gap-3">
					<button id="nj665-submit" type="submit"
						class="n2j-btn-process">
						<i class="fas fa-wand-magic-sparkles"></i><span>Озвучить</span>
					</button>
					<span id="nj665-status" class="text-xs text-gray-500 dark:text-gray-400" role="status" aria-live="polite"></span>
				</div>
			</form>

			<!-- ============ Результат ============ -->
			<div id="nj665-result" class="<?php echo $result ? '' : 'hidden '; ?>rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/30">
				<div class="flex flex-wrap items-center gap-2 text-sm font-semibold text-emerald-800 dark:text-emerald-300">
					<i class="fas fa-circle-check"></i><span id="nj665-result-title">Готово</span>
					<span id="nj665-result-meta" class="ml-auto text-xs font-normal text-emerald-700 dark:text-emerald-400"></span>
				</div>

				<div class="mt-3">
					<audio id="nj665-audio" controls preload="metadata" class="w-full"
						<?php if ($result): ?>src="<?php echo HTML::chars((string) Arr::get($result, 'url')); ?>"<?php endif; ?>></audio>
					<?php if ($result AND ! Service_Nj665_Voice::is_playable((string) Arr::get($result, 'format'))): ?>
					<p class="mt-2 text-xs text-amber-700 dark:text-amber-400">Формат без контейнера — браузер его не играет. Скачайте файл и откройте в плеере.</p>
					<?php endif; ?>
				</div>

				<div class="mt-3 flex flex-wrap items-center gap-2">
					<a id="nj665-download" href="<?php echo $result ? HTML::chars((string) Arr::get($result, 'url')).'?dl=1' : '#'; ?>"
						class="n2j-btn-secondary n2j-btn-sm">
						<i class="fas fa-download"></i><span>Скачать файл</span>
					</a>
					<button id="nj665-copy" type="button"
						class="n2j-btn-secondary n2j-btn-sm">
						<i class="fas fa-link"></i><span>Скопировать ссылку</span>
					</button>
					<span id="nj665-copy-note" class="text-xs text-emerald-700 dark:text-emerald-400"></span>
				</div>
			</div>

			<!-- ============ История ============ -->
			<div class="n2j-card">
				<div class="flex items-center gap-2">
					<h2 class="n2j-h3"><i class="fas fa-clock-rotate-left mr-1.5 text-gray-400"></i>Последние озвучки</h2>
					<span class="ml-auto text-xs text-gray-400">хранятся <?php echo (int) Arr::get(Service_Nj665_Voice::config(), 'ttl_days', 30); ?> дней · удаление стирает файл сразу</span>
				</div>

				<ul id="nj665-history" class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
					<?php if ( ! $history): ?>
					<li id="nj665-history-empty" class="py-3 text-xs text-gray-500 dark:text-gray-400">Пока ничего нет — озвучьте текст, и файл появится здесь.</li>
					<li id="nj665-history-note" class="hidden py-2 text-xs text-gray-500 dark:text-gray-400"></li>
					<?php endif; ?>

					<?php foreach ($history as $row): ?>
					<li class="py-3" data-code="<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>">
						<div class="flex flex-wrap items-center gap-2">
							<span class="min-w-0 flex-1 truncate text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) Arr::get($row, 'title')); ?></span>
							<span class="text-xs text-gray-400"><?php echo HTML::chars((string) Arr::get($row, 'voice')); ?> · <?php echo HTML::chars((string) Arr::get($row, 'format')); ?> · <?php echo $bytes_human(Arr::get($row, 'bytes')); ?></span>
							<span class="text-xs text-gray-400"><?php echo HTML::chars(mb_substr((string) Arr::get($row, 'created_at'), 0, 16)); ?></span>
						</div>
						<div class="mt-2 flex flex-wrap items-center gap-3">
							<audio controls preload="none" src="/voice/f/<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>" class="w-full max-w-md"></audio>
							<a href="/voice/f/<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>?dl=1" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Скачать</a>
							<a href="/voice/h/<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>" class="text-xs text-gray-500 hover:underline dark:text-gray-400">Ссылка</a>
							<button type="button" data-n2j-arm="Точно удалить?" data-nj665-del="<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>"
								class="n2j-btn-danger n2j-btn-sm ml-auto">
								<i class="fas fa-trash-can"></i><span data-n2j-arm-label>Удалить</span>
							</button>
							<?php if (Arr::get($row, 'status') === 'error'): ?>
							<span class="text-xs text-rose-500"><?php echo HTML::chars((string) Arr::get($row, 'error')); ?></span>
							<?php endif; ?>
						</div>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>

		</div>

		<!-- ============ Боковая колонка (1/3) ============ -->
		<div class="min-w-0 space-y-5">

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-circle-question mr-1.5 text-blue-500"></i>Как это работает</h2>
				<ol class="mt-2 space-y-2 text-xs text-gray-600 dark:text-gray-300">
					<li><b>1.</b> Текст уходит в OmniVoice API v1 (<code class="rounded bg-gray-100 px-1 dark:bg-gray-800">POST /api/v1/audio/speech</code>).</li>
					<li><b>2.</b> Ответ приходит потоком, JSON с base64 или ссылкой — сервис понимает все три вида.</li>
					<li><b>3.</b> Файл сохраняется в хранилище сайта, формат проверяется по содержимому.</li>
					<li><b>4.</b> Ссылку можно открыть, скачать или отдать в свой скрипт.</li>
				</ol>
				<p class="n2j-hint mt-3">Лимит: <?php echo HTML::chars((string) $rate_text); ?>. Длина текста — до <?php echo (int) $max_chars; ?> символов.</p>
			</div>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-code mr-1.5 text-gray-400"></i>JSON API</h2>
				<ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
					<li><a class="text-blue-600 hover:underline dark:text-blue-400" href="/voice/api/voices">/voice/api/voices</a> — модели, голоса, форматы</li>
					<li><span class="text-gray-500">POST</span> <span class="text-blue-600 dark:text-blue-400">/voice/api/synthesize</span> — синтез</li>
					<li><a class="text-blue-600 hover:underline dark:text-blue-400" href="/voice/api/history">/voice/api/history</a> — ваши озвучки</li>
				</ul>
				<p class="n2j-hint mt-2">В POST нужны <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">csrf</code> (токен со страницы) и авторизация на сайте.</p>
			</div>

			<?php if ( ! empty($is_admin)): ?>
			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-key mr-1.5 text-amber-500"></i>Ключ внешнего API</h2>
				<?php if (Arr::get((array) $key_info, 'ok')): ?>
				<pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-gray-50 p-2 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300"><?php echo HTML::chars(json_encode(Arr::get((array) $key_info, 'data', []), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
				<?php else: ?>
				<p class="n2j-hint mt-2"><?php echo HTML::chars((string) Arr::get((array) $key_info, 'error', 'Состояние ключа недоступно.')); ?></p>
				<?php endif; ?>
			</div>
			<?php endif; ?>

		</div>
	</div>
</div>

<?php echo View::factory('665/script', [
	'limits'   => $limits,
	'model'    => (string) $model,
	'formats'  => $formats,
]); ?>
