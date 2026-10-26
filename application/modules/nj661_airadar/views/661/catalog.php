<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /airadar/catalog — каталог опубликованных отчётов ИИ-радара.
 *
 * Владелец (08.10.2026): «такую же страницу-каталог сделать в /airadar: читаемый
 * список, свежие сверху, пагинация, поиск; отчёт публикуется по умолчанию, но я
 * могу снять его с публикации».
 *
 * Страница ПУБЛИЧНАЯ — как каталог обзоров в трендах (владелец, 08.10.2026:
 * «каталог опубликованных обзоров — для людей и СЕО»): читаемые абзацы,
 * дата-время, числа прогона, пагинация общим партиалом `partials/pagination`.
 * Личный инструмент владельца живёт рядом, за замком (дашборд, материалы, идеи,
 * переключатель публикации), поэтому служебные кнопки показываем только ему.
 *
 * Дизайн — по эталону /pages/required_design_sample: карточки rounded-xl p-4 →
 * sm:p-6, поля и кнопки rounded-lg min-h-[44px], бейджи rounded-md, полос хода
 * нет (ход прогона — на дашборде статус-рядом).
 *
 * @var array  $rows        отчёты текущей страницы (id, title, summary, stats…)
 * @var string $q           поисковый запрос ('' — весь каталог)
 * @var int    $page        текущая страница
 * @var int    $pages       всего страниц
 * @var int    $total       всего опубликованных отчётов (с учётом поиска)
 * @var int    $per         строк на странице
 * @var array  $per_options варианты «строк на странице»
 * @var string $csrf        токен формы (публикация отчёта — в два клика)
 * @var array  $flash       ['notice' => …, 'error' => …]
 * @var bool   $is_owner    владелец? ему — кнопка дашборда и переключатель публикации
 */

// Лид отчёта в списке: 320 знаков хватает, чтобы понять, о чём прогон.
$excerpt = function ($text, $len = 320) {
	$text = trim(preg_replace('/\s+/u', ' ', (string) $text));

	if ($text === '')
	{
		return '';
	}

	return mb_strlen($text) > $len ? mb_substr($text, 0, $len).'…' : $text;
};

$num = function ($value) {
	return number_format((int) $value, 0, ',', ' ');
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-nj661-view>

	<?php if ( ! empty($flash['notice'])): ?>
		<p class="n2j-flash n2j-flash-ok mb-5 block"><?php echo HTML::chars($flash['notice']); ?></p>
	<?php endif; ?>
	<?php if ( ! empty($flash['error'])): ?>
		<p class="n2j-flash n2j-flash-err mb-5 block"><?php echo HTML::chars($flash['error']); ?></p>
	<?php endif; ?>

	<!-- ============ Шапка каталога ============ -->
	<header>
		<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
			<a href="/airadar" class="transition hover:text-blue-600 dark:hover:text-blue-400">ИИ-радар</a>
			<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
			<span class="font-semibold text-gray-700 dark:text-gray-300">Отчёты</span>
		</nav>

		<div class="mt-3 flex flex-wrap items-center gap-3">
			<h1 class="n2j-h1 flex items-center gap-3">
				<i class="fas fa-book-open text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Отчёты ИИ-радара
			</h1>
			<span class="n2j-badge n2j-badge-wait">опубликовано: <?php echo (int) $total; ?></span>
		</div>
		<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
			Что конвейер нашёл и разобрал: свежие сверху, с числами прогона и сводкой. Отчёт публикуется по умолчанию — пробные прогоны можно снять с публикации, и каталог останется читаемым.
		</p>

		<div class="mt-3 flex flex-wrap items-center gap-2">
			<?php if ($is_owner): ?>
			<a href="/airadar" class="n2j-btn-process">
				<i class="fas fa-satellite-dish" aria-hidden="true"></i>Дашборд и «Собрать сейчас»
			</a>
			<a href="/airadar/items" class="n2j-btn-secondary">
				<i class="fas fa-stream text-[13px]" aria-hidden="true"></i>Материалы
			</a>
			<?php else: ?>
			<?php // Гостю — второй публичный каталог: у «Трендов GitHub» та же природа. ?>
			<a href="/trends" class="n2j-btn-secondary">
				<i class="fas fa-fire text-[13px]" aria-hidden="true"></i>Обзоры трендов GitHub
			</a>
			<?php endif; ?>
		</div>
	</header>

	<!-- ============ Поиск ============ -->
	<form method="get" action="/airadar/catalog" class="mt-5 flex flex-wrap items-center gap-2" role="search">
		<label for="nj661-cat-q" class="sr-only">Поиск по отчётам</label>
		<div class="relative min-w-0 flex-1 sm:max-w-md">
			<i class="fas fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-gray-400" aria-hidden="true"></i>
			<input id="nj661-cat-q" type="search" name="q" value="<?php echo HTML::chars($q); ?>" autocomplete="off"
				   placeholder="Поиск: заголовок, сводка, разделы"
				   class="n2j-input w-full pl-9">
		</div>
		<button type="submit" class="n2j-btn-process">
			<i class="fas fa-magnifying-glass text-[11px]" aria-hidden="true"></i>Найти
		</button>
		<?php if ($q !== ''): ?>
		<a href="/airadar/catalog" class="n2j-btn-secondary">Сбросить</a>
		<?php endif; ?>
	</form>

	<!-- ============ Список отчётов ============ -->
	<div class="n2j-card p-0 mt-5">

		<?php if (empty($rows)): ?>
		<div class="px-4 py-8 text-center sm:px-6">
			<i class="fas fa-book-open text-2xl text-gray-300 dark:text-gray-600" aria-hidden="true"></i>
			<?php if ($q !== ''): ?>
			<p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
				По запросу «<span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($q); ?></span>» опубликованных отчётов нет.
			</p>
			<a href="/airadar/catalog" class="n2j-btn-secondary mt-3">Показать все отчёты</a>
			<?php else: ?>
			<p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Опубликованных отчётов пока нет — конвейер ещё не выкладывал отчёт.</p>
			<?php if ($is_owner): ?>
			<a href="/airadar" class="n2j-btn-process mt-3">Собрать сейчас</a>
			<?php else: ?>
			<a href="/trends" class="n2j-btn-secondary mt-3">Обзоры трендов GitHub</a>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php else: ?>

		<div class="divide-y divide-gray-100 dark:divide-gray-700">
			<?php foreach ($rows as $row):
				$stats  = (array) $row['stats'];
				$lead   = $excerpt($row['summary']);
				$title  = trim((string) $row['title']);
				// Числа прогона: кандидаты и принятые материалы приходят из stats
				// воркера, count_items/max_score добавляет сборка отчёта — берём
				// то, что есть, чтобы строка чисел не пустовала.
				$marks  = [];
				$marks[] = 'кандидатов: '.$num($stats['candidates'] ?? 0);
				$marks[] = 'материалов: '.$num($stats['count_items'] ?? ($stats['accepted'] ?? 0));
				$marks[] = 'фактов: '.$num($stats['facts_new'] ?? 0);
				$marks[] = 'идей: '.$num($stats['ideas_new'] ?? 0);
			?>
			<article class="p-4 transition-colors hover:bg-gray-50/70 sm:p-6 dark:hover:bg-gray-700/20">
				<div class="flex flex-wrap items-center gap-x-2.5 gap-y-2 text-xs">
					<span class="inline-flex items-center gap-1.5 rounded-md bg-blue-50 px-2.5 py-1 font-semibold text-blue-800 dark:bg-blue-950/60 dark:text-blue-300">
						<i class="fas fa-clock text-[11px]" aria-hidden="true"></i><time datetime="<?php echo HTML::chars(date('Y-m-d\TH:i', strtotime((string) $row['created_at']))); ?>"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $row['created_at']))); ?></time>
					</span>
					<span class="font-mono text-gray-400 dark:text-gray-500">#<?php echo (int) $row['id']; ?></span>
					<?php if ( ! empty($row['emailed'])): ?>
					<span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
						<i class="fas fa-envelope-circle-check text-[11px]" aria-hidden="true"></i>письмо отправлено
					</span>
					<?php endif; ?>
					<?php if ($is_owner AND ! $row['published']): ?>
					<span class="inline-flex items-center gap-1.5 rounded-md bg-amber-100 px-2.5 py-1 font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">снят с публикации</span>
					<?php endif; ?>
				</div>

				<h2 class="n2j-h3 mt-2.5">
					<a href="/airadar/report/<?php echo (int) $row['id']; ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400">
						<?php echo HTML::chars($title !== '' ? $title : 'Отчёт прогона #'.(int) $row['id']); ?>
					</a>
				</h2>

				<?php if ($lead !== ''): ?>
				<p class="mt-2 max-w-4xl text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($lead); ?></p>
				<?php endif; ?>

				<p class="n2j-hint mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1 font-mono">
					<?php foreach ($marks as $mark): ?><span><?php echo HTML::chars($mark); ?></span><?php endforeach; ?>
				</p>

				<div class="mt-3.5 flex flex-wrap items-center gap-3 text-xs">
					<a href="/airadar/report/<?php echo (int) $row['id']; ?>" class="inline-flex min-h-[36px] items-center gap-1.5 font-semibold text-blue-700 transition hover:underline dark:text-blue-400">
						Читать отчёт<i class="fas fa-arrow-right text-[11px]" aria-hidden="true"></i>
					</a>

					<?php // Публикация — в два клика по той же кнопке (без window.confirm), как удаление в трендах. ?>
					<?php if ($is_owner): ?>
					<form method="post" action="/airadar/report/<?php echo (int) $row['id']; ?>/publish" class="inline-flex">
						<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<input type="hidden" name="published" value="<?php echo $row['published'] ? '0' : '1'; ?>">
						<button data-n2j-arm="<?php echo $row['published'] ? 'Точно снять?' : 'Точно вернуть?'; ?>" title="Отчёт <?php echo (int) $row['id']; ?>: <?php echo $row['published'] ? 'снять с публикации — он пропадёт из каталога' : 'вернуть в каталог'; ?>?" type="submit" class="n2j-btn-secondary n2j-btn-sm">
							<i class="fas <?php echo $row['published'] ? 'fa-eye-slash' : 'fa-eye'; ?> text-[11px]" aria-hidden="true"></i><?php echo $row['published'] ? 'Снять с публикации' : 'Вернуть в каталог'; ?>
						</button>
					</form>
					<?php endif; ?>
				</div>
			</article>
			<?php endforeach; ?>
		</div>

		<?php echo View::factory('partials/pagination', [
			'total'       => $total,
			'page'        => $page,
			'pages'       => $pages,
			'per'         => $per,
			'per_options' => $per_options,
			'url_base'    => '/airadar/catalog',
			'params'      => ($q !== '' ? ['q' => $q] : []),
			'anchor'      => '',
		]); ?>

		<?php endif; ?>
	</div>
</div>
