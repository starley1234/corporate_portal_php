<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /dialog/list — публичный каталог опубликованных диалогов.
 *
 * Читаемый список для людей и поисковиков (владелец, 08.10.2026: «каталог
 * опубликованных обзоров — для людей и СЕО»; тот же приём, что /trends/catalog,
 * /airadar/catalog и /forge/artifacts): свежие сверху, поиск по теме, вводной и
 * репликам, пагинация общим партиалом partials/pagination.
 *
 * Дизайн — по эталону /pages/required_design_sample: карточки rounded-xl,
 * поле поиска и кнопки rounded-lg min-h-[44px], полос хода нет.
 *
 * @var array  $rows        диалоги текущей страницы
 * @var string $q           поисковый запрос ('' — весь каталог)
 * @var int    $page        текущая страница
 * @var int    $pages       всего страниц
 * @var int    $total       всего опубликованных (с учётом поиска)
 * @var int    $per         строк на странице
 * @var array  $per_options варианты «строк на странице»
 * @var int    $own         id владельца страницы (0 — гость): ему — ссылка на рабочий стол
 * @var bool   $logged      залогинен ли посетитель
 * @var string $csrf        токен форм
 * @var array  $flash       ['tone' => …, 'text' => …]
 */
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-nj662-view data-nj662-run="0">

	<?php if ( ! empty($flash['text'])): ?>
	<p class="n2j-flash n2j-flash-ok mb-5 block"><?php echo HTML::chars((string) $flash['text']); ?></p>
	<?php endif; ?>

	<!-- ============ Шапка каталога ============ -->
	<header>
		<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
			<a href="/dialog" class="transition hover:text-blue-600 dark:hover:text-blue-400">Диалог двух ИИ</a>
			<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
			<span class="font-semibold text-gray-700 dark:text-gray-300">Опубликованные</span>
		</nav>

		<div class="mt-3 flex flex-wrap items-center gap-3">
			<h1 class="n2j-h1 flex items-center gap-3">
				<i class="fas fa-book-open text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Разговоры двух ИИ
			</h1>
			<span class="n2j-badge n2j-badge-wait">опубликовано: <?php echo (int) $total; ?></span>
		</div>
		<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
			Здесь читают то, что владельцы решили открыть: два агента обсуждают тему по делу, при необходимости ищут в интернете и ссылаются на источники. Любой разговор можно завести со своей темой.
		</p>

		<?php if ($logged): ?>
		<a href="/dialog" class="n2j-btn-process mt-3">
			<i class="fas fa-comments text-[13px]" aria-hidden="true"></i>Мои диалоги
		</a>
		<?php endif; ?>
	</header>

	<!-- ============ Поиск ============ -->
	<form method="get" action="/dialog/list" class="mt-5 flex flex-wrap items-center gap-2">
		<label for="nj662-q" class="sr-only">Поиск по опубликованным диалогам</label>
		<input id="nj662-q" type="search" name="q" value="<?php echo HTML::chars($q); ?>" maxlength="120"
			placeholder="Тема, вопрос или слово из разговора"
			class="n2j-input min-w-0 flex-1">
		<button type="submit" class="n2j-btn-process">
			<i class="fas fa-magnifying-glass text-[13px]" aria-hidden="true"></i>Найти
		</button>
		<?php if ($q !== ''): ?>
		<a href="/dialog/list" class="inline-flex min-h-[44px] items-center rounded-lg px-3 text-sm font-semibold text-gray-500 transition hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-100">Сбросить</a>
		<?php endif; ?>
	</form>

	<!-- ============ Список ============ -->
	<?php if (empty($rows)): ?>
	<section class="mt-5 rounded-xl border border-dashed border-gray-300 bg-white/60 px-4 py-10 text-center dark:border-gray-600 dark:bg-gray-800/60">
		<i class="fas fa-comments text-2xl text-gray-300 dark:text-gray-600" aria-hidden="true"></i>
		<p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
			<?php if ($q !== ''): ?>
			По запросу «<?php echo HTML::chars($q); ?>» ничего не нашлось. Попробуйте короче или <a href="/dialog/list" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">посмотрите все разговоры</a>.
			<?php else: ?>
			Опубликованных разговоров пока нет. Первый появится здесь, как только владелец нажмёт «Опубликовать» — <a href="/dialog" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">начать свой</a>.
			<?php endif; ?>
		</p>
	</section>
	<?php else: ?>

	<div class="mt-5 space-y-3">
		<?php foreach ($rows as $d): ?>
		<?php
			$prog = Service_Nj662_Dialog::progress($d);
			$opening = trim((string) Arr::get($d, 'opening', ''));
		?>
		<article class="n2j-card transition hover:border-blue-300 hover:shadow-sm dark:hover:border-blue-800">
			<div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
				<span class="inline-flex items-center gap-1.5 rounded-md bg-blue-50 px-2 py-0.5 font-semibold text-blue-800 dark:bg-blue-950/50 dark:text-blue-300">
					<i class="fas fa-cubes text-[10px]" aria-hidden="true"></i><?php echo HTML::chars((string) $d['a_name']); ?>
				</span>
				<span class="text-gray-300 dark:text-gray-600">×</span>
				<span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300">
					<i class="fas fa-lightbulb text-[10px]" aria-hidden="true"></i><?php echo HTML::chars((string) $d['b_name']); ?>
				</span>
				<span class="text-gray-300 dark:text-gray-600">·</span>
				<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars($prog['turn_word']); ?></span>
				<span class="text-gray-300 dark:text-gray-600">·</span>
				<span class="text-gray-400 dark:text-gray-500">реплик: <?php echo (int) $d['replies']; ?></span>
				<?php if ((int) $d['searches'] > 0): ?>
				<span class="text-gray-300 dark:text-gray-600">·</span>
				<span class="text-gray-400 dark:text-gray-500" title="поиски агентов в интернете"><i class="fas fa-magnifying-glass text-[10px]" aria-hidden="true"></i> <?php echo (int) $d['searches']; ?></span>
				<?php endif; ?>
				<?php if (trim((string) $d['published_at']) !== ''): ?>
				<span class="ml-auto text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars(date('d.m.Y', strtotime((string) $d['published_at']))); ?></span>
				<?php endif; ?>
			</div>

			<h2 class="n2j-h3 mt-2">
				<a href="/dialog/<?php echo (int) $d['id']; ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400"><?php echo HTML::chars((string) $d['topic']); ?></a>
			</h2>

			<?php if (trim((string) $d['brief']) !== ''): ?>
			<p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) $d['brief'], 240)); ?></p>
			<?php elseif ($opening !== ''): ?>
			<p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400"><span class="font-semibold text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $d['a_name']); ?>:</span> <?php echo HTML::chars(Service_Nj662_Dialog::excerpt($opening, 240)); ?></p>
			<?php endif; ?>

			<p class="mt-3">
				<a href="/dialog/<?php echo (int) $d['id']; ?>" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 transition hover:underline dark:text-blue-400">
					Читать разговор<i class="fas fa-arrow-right text-[11px]" aria-hidden="true"></i>
				</a>
			</p>
		</article>
		<?php endforeach; ?>
	</div>

	<?php echo View::factory('partials/pagination', [
		'total'   => (int) $total,
		'page'    => (int) $page,
		'pages'   => (int) $pages,
		'per'     => (int) $per,
		'per_options' => $per_options,
		'url_base'    => '/dialog/list',
		'params'      => array_filter(['q' => $q]),
	]); ?>

	<?php endif; ?>
</div>
