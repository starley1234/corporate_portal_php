<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Многоголосая озвучка» (nj666): новая книга и список моих книг.
 *
 * Форма работает и без JS (обычный POST на /multivoice): сервер создаёт книгу
 * и сразу открывает её страницу. Со скриптом — то же самое, но книга
 * появляется в списке без перезагрузки (владелец, 09.10.2026).
 */

$logged    = isset($logged) ? (bool) $logged : FALSE;
$can_run   = isset($can_run) ? (bool) $can_run : FALSE;
$enabled   = isset($enabled) ? (bool) $enabled : TRUE;
$books     = isset($books) ? (array) $books : [];
$modes     = isset($modes) ? (array) $modes : [];
$languages = isset($languages) ? (array) $languages : [];
$defaults  = isset($defaults) ? (array) $defaults : [];
$limits    = isset($limits) ? (array) $limits : [];
$rate_text = isset($rate_text) ? (string) $rate_text : '';
$error     = isset($error) ? (string) $error : '';
$csrf      = isset($csrf) ? (string) $csrf : '';
$post      = isset($post) ? (array) $post : [];

$max_chars  = (int) Arr::get($limits, 'max_chars', 600000);
$min_chars  = (int) Arr::get($limits, 'min_chars', 200);
$block_min  = (float) Arr::get($limits, 'block_min_min', 1.0);
$block_max  = (float) Arr::get($limits, 'block_max_min', 60.0);

$statuses = [
	'new'      => ['Новая', 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'],
	'planning' => ['Разбирается', 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'],
	'planned'  => ['Разобрана', 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300'],
	'voicing'  => ['Озвучивается', 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'],
	'done'     => ['Готова', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'],
	'error'    => ['Ошибка', 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300'],
];

$numbers = function ($value) { return number_format((int) $value, 0, ',', ' '); };
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<a href="/catalog" class="transition hover:text-blue-600 dark:hover:text-blue-400">Сервисы</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-medium text-gray-700 dark:text-gray-200">Многоголосая озвучка</span>
	</nav>

	<h1 class="n2j-h1 mt-2"><i class="fas fa-users-between-lines mr-2 text-blue-500"></i>Многоголосая озвучка</h1>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Загрузите большую книгу или длинный текст — модель найдёт, кто говорит, разведёт персонажей по разным голосам, не перепутает их по ходу книги и нарежет текст на блоки с логическим завершением. Готовое скачивается одним архивом: проект (чтобы продолжить) и продакшен (чтобы слушать).</p>

	<?php if (empty($enabled)): ?>
	<div class="n2j-flash n2j-flash-warn mt-4 flex items-start gap-3 text-xs">
		<i class="fas fa-triangle-exclamation mt-0.5 shrink-0"></i>
		<span>Синтез не настроен: в <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/50">.env</code> не заданы <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/50">NJ665_API_URL</code> и <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/50">NJ665_API_KEY</code>. Разбор текста будет работать, но озвучка — нет.</span>
	</div>
	<?php endif; ?>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php endif; ?>

	<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

		<!-- ==================== Новая книга (2/3) ==================== -->
		<div class="min-w-0 space-y-5 md:col-span-2">

			<?php if (empty($logged)): ?>
			<?php echo Gate::wall('multivoice', [
				'icon'   => 'fas fa-users-between-lines',
				'title'  => 'Многоголосая озвучка — по бесплатному аккаунту',
				'text'   => 'Книгу разбирает модель, а озвучивают внешние GPU — это не бесплатно, поэтому запускать могут только зарегистрированные. Аккаунт бесплатный и хранит ваши книги.',
				'points' => [
					'До '.Service_Nj666_Multivoice::human_time(Service_Nj666_Multivoice::estimate_seconds($max_chars)).' звучания на книгу',
					'Персонажи, замочки и повторная озвучка любого блока',
					'Один архив: project/ (продолжить) и production/ (слушать)',
					'Лимит: '.$rate_text,
					'Запуск — привилегированным (vip) и администраторам',
				],
				'stats'  => ['OmniVoice API v1', 'до '.$numbers($max_chars).' знаков'],
				'cta'    => 'Войти и озвучить книгу',
			]); ?>
			<?php endif; ?>

			<form id="nj666-form" method="post" action="/multivoice" class="n2j-card">
				<input type="hidden" name="csrf" id="nj666-csrf" value="<?php echo HTML::chars($csrf); ?>">

				<h2 class="n2j-h3"><i class="fas fa-book-open mr-1.5 text-blue-500"></i>Новая книга</h2>

				<div class="mt-3 grid gap-3 sm:grid-cols-2">
					<div>
						<label for="nj666-title" class="n2j-label">Название <span class="font-normal text-gray-400">(необязательно)</span></label>
						<input id="nj666-title" name="title" type="text" maxlength="300"
							value="<?php echo HTML::chars((string) Arr::get($post, 'title', '')); ?>"
							placeholder="Например: Мастер и Маргарита"
							class="n2j-input mt-1 w-full">
					</div>
					<div>
						<label for="nj666-author" class="n2j-label">Автор <span class="font-normal text-gray-400">(для режима «утка го»)</span></label>
						<input id="nj666-author" name="author" type="text" maxlength="200"
							value="<?php echo HTML::chars((string) Arr::get($post, 'author', '')); ?>"
							placeholder="Например: Михаил Булгаков"
							class="n2j-input mt-1 w-full">
					</div>
				</div>

				<div class="mt-3">
					<label for="nj666-text" class="n2j-label">Текст книги</label>
					<textarea id="nj666-text" name="text" rows="12"
						class="n2j-textarea mt-2 w-full resize-y leading-relaxed"
						placeholder="Вставьте текст книги целиком — хоть всю главу, хоть весь роман. Прямую речь модель разберёт сама."><?php echo HTML::chars((string) Arr::get($post, 'text', '')); ?></textarea>
					<div class="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
						<span id="nj666-hint">От <?php echo (int) $min_chars; ?> знаков. Чем больше текста — тем дольше разбор: он идёт в фоне, страницу можно закрыть.</span>
						<span><span id="nj666-count" class="font-semibold">0</span> / <?php echo $numbers($max_chars); ?></span>
					</div>
				</div>

				<div class="mt-4 grid gap-3 sm:grid-cols-2">

					<div class="sm:col-span-2">
						<span class="block text-xs font-semibold text-gray-700 dark:text-gray-300">Как искать персонажей</span>
						<div class="mt-2 space-y-2">
							<?php foreach ($modes as $key => $title): ?>
							<label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 px-3 py-2 text-xs text-gray-700 transition hover:border-blue-400 dark:border-gray-700 dark:text-gray-300">
								<input type="radio" name="mode" value="<?php echo HTML::chars((string) $key); ?>" class="mt-0.5"
									<?php echo (string) Arr::get($post, 'mode', Arr::get($defaults, 'mode', 'auto')) === (string) $key ? 'checked' : ''; ?>>
								<span><b class="text-gray-900 dark:text-white"><?php echo $key === 'search' ? '«Утка го»' : 'Автоматически'; ?></b> — <?php echo HTML::chars((string) $title); ?></span>
							</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div>
						<label for="nj666-min" class="n2j-label">Длина блока: от <b id="nj666-min-value"><?php echo (float) Arr::get($post, 'block_min_min', Arr::get($defaults, 'block_min_min', 7.0)); ?></b> мин</label>
						<input id="nj666-min" name="block_min_min" type="range" min="<?php echo (float) $block_min; ?>" max="<?php echo (float) $block_max; ?>" step="0.5"
							value="<?php echo (float) Arr::get($post, 'block_min_min', Arr::get($defaults, 'block_min_min', 7.0)); ?>"
							class="mt-2 w-full accent-blue-600">
					</div>

					<div>
						<label for="nj666-max" class="n2j-label">до <b id="nj666-max-value"><?php echo (float) Arr::get($post, 'block_max_min', Arr::get($defaults, 'block_max_min', 15.0)); ?></b> мин</label>
						<input id="nj666-max" name="block_max_min" type="range" min="<?php echo (float) $block_min; ?>" max="<?php echo (float) $block_max; ?>" step="0.5"
							value="<?php echo (float) Arr::get($post, 'block_max_min', Arr::get($defaults, 'block_max_min', 15.0)); ?>"
							class="mt-2 w-full accent-blue-600">
						<p class="n2j-hint">Блок заканчивается там, где кончилась сцена, — в этих пределах.</p>
					</div>

					<div class="sm:col-span-2">
						<label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 px-3 py-2 text-xs text-gray-700 transition hover:border-blue-400 dark:border-gray-700 dark:text-gray-300">
							<input id="nj666-single" name="single_block" value="1" type="checkbox" class="mt-0.5"
								<?php echo Arr::get($post, 'single_block') ? 'checked' : ''; ?>>
							<span><b class="text-gray-900 dark:text-white">Вся книга одним файлом</b> — один блок, одна дорожка. Удобно для рассказа или статьи.</span>
						</label>
					</div>

					<div>
						<label for="nj666-language" class="n2j-label">Язык</label>
						<select id="nj666-language" name="language"
							class="n2j-input n2j-select mt-1 w-full">
							<?php foreach ($languages as $code => $title): ?>
							<option value="<?php echo HTML::chars((string) $code); ?>"<?php echo (string) $code === (string) Arr::get($post, 'language', 'ru') ? ' selected' : ''; ?>><?php echo HTML::chars((string) $title); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div>
						<label for="nj666-speed" class="flex items-center justify-between text-xs font-semibold text-gray-700 dark:text-gray-300">
							<span>Скорость речи</span>
							<span id="nj666-speed-value" class="font-bold text-blue-600 dark:text-blue-400"><?php echo number_format((float) Arr::get($post, 'speed', Arr::get($defaults, 'speed', 1.0)), 2, '.', ''); ?>×</span>
						</label>
						<input id="nj666-speed" name="speed" type="range" min="0.5" max="2" step="0.05"
							value="<?php echo (float) Arr::get($post, 'speed', Arr::get($defaults, 'speed', 1.0)); ?>"
							class="mt-2 w-full accent-blue-600">
					</div>

				</div>

				<?php if ($logged AND ! $can_run): ?>
				<div class="n2j-flash n2j-flash-warn mt-4 flex items-start gap-3 text-xs">
					<i class="fas fa-lock mt-0.5 shrink-0"></i>
					<span>Запускать разбор и озвучку книги могут привилегированные аккаунты (роль <b>vip</b>) и администраторы: на книгу уходят десятки вызовов модели и часы синтеза. Свои уже готовые книги вы слушаете и скачиваете как обычно.</span>
				</div>
				<?php endif; ?>

				<div class="mt-5 flex flex-wrap items-center gap-3">
					<button id="nj666-submit" type="submit"<?php echo ($logged AND ! $can_run) ? ' disabled' : ''; ?>
						class="n2j-btn-cta">
						<i class="fas fa-wand-magic-sparkles"></i><span>Создать книгу</span>
					</button>
					<span id="nj666-status" class="n2j-hint mt-0" role="status" aria-live="polite"></span>
				</div>

				<p class="n2j-hint mt-3">Книга создаётся сразу, а разбор уходит в очередь: вы увидите, как появляются персонажи и блоки. Голоса можно поправить до озвучки.</p>
			</form>

			<!-- ==================== Мои книги ==================== -->
			<div id="nj666-books" class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-layer-group mr-1.5 text-blue-500"></i>Мои книги <span class="font-normal text-gray-400">(<?php echo count($books); ?>)</span></h2>

				<?php if ( ! $books): ?>
				<p class="n2j-hint mt-2">Пока пусто. Создайте книгу — она появится здесь, и к ней можно вернуться в любой момент.</p>
				<?php else: ?>
				<ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
					<?php foreach ($books as $one): ?>
					<?php
						$status = (string) Arr::get($one, 'status', 'new');
						$badge  = Arr::get($statuses, $status, Arr::get($statuses, 'new'));
					?>
					<li class="py-2.5" data-code="<?php echo HTML::chars((string) Arr::get($one, 'code')); ?>">
						<div class="flex flex-wrap items-center gap-2">
							<a href="/multivoice/b/<?php echo HTML::chars((string) Arr::get($one, 'code')); ?>"
								class="truncate text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-white">
								<?php echo HTML::chars((string) Arr::get($one, 'title')); ?>
							</a>
							<span class="rounded-full px-2 py-0.5 text-xs font-semibold <?php echo HTML::chars((string) Arr::get($badge, 1)); ?>"><?php echo HTML::chars((string) Arr::get($badge, 0)); ?></span>
							<span class="ml-auto text-xs text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) Arr::get($one, 'updated_at'), 'd.m.Y H:i')); ?></span>
							<!-- Иконка: мягко в БД, файлы сразу. Второе нажатие (data-n2j-arm), не confirm() (владелец, 11.10.2026). -->
							<button type="button" class="n2j-btn-icon-danger nj666-del-book shrink-0"
								data-code="<?php echo HTML::chars((string) Arr::get($one, 'code')); ?>" data-n2j-arm="Удалить?"
								title="Удалить книгу" aria-label="Удалить книгу">
								<i class="fas fa-trash-alt text-xs" aria-hidden="true"></i>
							</button>
						</div>
						<div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
							<span><i class="fas fa-text-width mr-1 text-gray-300"></i><?php echo $numbers(Arr::get($one, 'chars', 0)); ?> знаков</span>
							<span><i class="fas fa-clock mr-1 text-gray-300"></i>≈ <?php echo HTML::chars(Service_Nj666_Multivoice::human_time(Service_Nj666_Multivoice::estimate_seconds((int) Arr::get($one, 'chars', 0), (float) Arr::get($one, 'speed', 1.0)))); ?></span>
							<?php if ((string) Arr::get($one, 'stage', '') !== ''): ?>
							<span class="truncate"><i class="fas fa-circle-info mr-1 text-gray-300"></i><?php echo HTML::chars((string) Arr::get($one, 'stage')); ?></span>
							<?php endif; ?>
						</div>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</div>

		</div>

		<!-- ==================== Как это работает (1/3) ==================== -->
		<div class="min-w-0 space-y-4">

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-diagram-project mr-1.5 text-blue-500"></i>Как это работает</h2>
				<ol class="mt-3 space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
					<li class="flex gap-2"><span class="font-bold text-blue-500">1</span><span><b>Разбор.</b> Модель идёт по тексту фрагментами и ведёт список персонажей: встретила «Пётр Иванович» — посмотрит, не был ли он уже под другим именем.</span></li>
					<li class="flex gap-2"><span class="font-bold text-blue-500">2</span><span><b>Голоса.</b> Каждому персонажу подбирается голос по полу и возрасту. Вы правите как угодно и ставите <i class="fas fa-lock text-[10px]"></i> замочек — тогда модель голос не тронет.</span></li>
					<li class="flex gap-2"><span class="font-bold text-blue-500">3</span><span><b>Блоки.</b> Текст режется на куски заданной длины так, чтобы блок кончался на логической паузе, а не на полуслове.</span></li>
					<li class="flex gap-2"><span class="font-bold text-blue-500">4</span><span><b>Озвучка.</b> Каждый блок уезжает в синтез одним запросом: реплики и голоса внутри него чередуются.</span></li>
					<li class="flex gap-2"><span class="font-bold text-blue-500">5</span><span><b>Архив.</b> <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">project/</code> — книга, персонажи и реплики (продолжить позже), <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">production/</code> — нумерованные файлы и плейлист.</span></li>
				</ol>
			</div>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-lightbulb mr-1.5 text-amber-500"></i>Полезно знать</h2>
				<ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
					<li><i class="fas fa-check mr-1.5 text-emerald-500"></i>У известной книги режим «утка го» найдёт состав действующих лиц в интернете — до разбора.</li>
					<li><i class="fas fa-check mr-1.5 text-emerald-500"></i>Рассказчик создаётся сам: авторский текст читает он.</li>
					<li><i class="fas fa-check mr-1.5 text-emerald-500"></i>Любой блок можно переозвучить отдельно — например, после смены голоса.</li>
					<li><i class="fas fa-check mr-1.5 text-emerald-500"></i>Лимит: <?php echo HTML::chars($rate_text); ?>.</li>
				</ul>
			</div>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-code mr-1.5 text-gray-400"></i>JSON API</h2>
				<ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
					<li><a class="text-blue-600 hover:underline dark:text-blue-400" href="/multivoice/api/voices">/multivoice/api/voices</a> — голоса с полом и возрастом</li>
					<li><span class="text-gray-500">POST</span> <span class="text-blue-600 dark:text-blue-400">/multivoice/api/create</span> — новая книга</li>
					<li><span class="text-gray-500">POST</span> <span class="text-blue-600 dark:text-blue-400">/multivoice/api/plan</span> — запустить разбор</li>
					<li><span class="text-gray-500">POST</span> <span class="text-blue-600 dark:text-blue-400">/multivoice/api/voice</span> — запустить озвучку</li>
				</ul>
				<p class="n2j-hint mt-2">В POST нужны <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">csrf</code> со страницы и авторизация на сайте.</p>
			</div>

		</div>
	</div>
</div>

<?php echo View::factory('666/script', [
	'limits' => $limits,
]); ?>
