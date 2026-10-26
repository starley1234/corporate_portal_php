<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /forge/artifacts — публичный каталог артефактов «Кузницы систем».
 *
 * Владелец (08.10.2026): «нужно, чтобы модуль формировал ценные и полезные
 * артефакты и был интересен» — каталог и есть витрина этой ценности: читаемый
 * список, поиск, фильтр по типу, свежие и лучшие сверху, у каждого артефакта
 * ценность с расшифровкой словом и разбор Критика на странице.
 *
 * Как устроено:
 *   · сортировка — по ценности, а не по времени: спецификация на 88 должна быть
 *     выше черновика на 30, даже если черновик написан минуту назад;
 *   · поиск — GET-форма: ссылку на подборку можно передать и запомнить;
 *   · фильтр по типам — ссылки-чипы с количеством (видно, что вообще есть);
 *   · список закрывает общая пагинация платформы (partials/pagination).
 *
 * Дизайн — по эталону /pages/required_design_sample: карточки rounded-xl,
 * контролы rounded-lg min-h-[44px], бейджи rounded-md, полос хода нет.
 *
 * @var array  $rows        артефакты текущей страницы
 * @var string $q           поисковый запрос ('' — весь каталог)
 * @var string $type        выбранный тип ('' — все)
 * @var array  $type_counts сколько артефактов каждого типа
 * @var int    $page        текущая страница
 * @var int    $pages       всего страниц
 * @var int    $total       всего артефактов (с учётом поиска и типа)
 * @var int    $per         строк на странице
 * @var array  $per_options варианты «строк на странице»
 * @var array  $types       словарь типов
 * @var string $csrf        токен форм (переключатель публикации)
 * @var bool   $is_owner    владелец? ему — переключатель публикации
 * @var array  $flash       ['tone' => …, 'text' => …]
 */
$type_url = function ($t) use ($q) {
	return '/forge/artifacts?'.http_build_query(array_filter(['q' => $q, 'type' => $t]));
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php if ( ! empty($flash['text'])): ?>
	<div class="n2j-flash <?php echo $flash['tone'] === 'error' ? 'n2j-flash-err' : 'n2j-flash-ok'; ?> mb-5">
		<i class="fas <?php echo $flash['tone'] === 'error' ? 'fa-circle-exclamation text-rose-600 dark:text-rose-400' : 'fa-circle-check text-emerald-600 dark:text-emerald-400'; ?> shrink-0 text-base"></i>
		<span class="flex-1"><?php echo HTML::chars((string) $flash['text']); ?></span>
	</div>
	<?php endif; ?>

	<!-- ============ Шапка каталога ============ -->
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/forge" class="transition hover:text-blue-600 dark:hover:text-blue-400">Кузница систем</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300">Артефакты</span>
	</nav>

	<div class="mt-3 flex flex-wrap items-center gap-3">
		<h1 class="n2j-h1 flex items-center gap-3">
			<i class="fas fa-cubes text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Каталог артефактов
		</h1>
		<span class="n2j-badge n2j-badge-wait">найдено: <?php echo (int) $total; ?></span>
	</div>
	<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
		Готовые документы синтеза систем: спецификации, схемы данных, контракты API, алгоритмы, промпты, планы проверки, риски, метрики, оценки и офферы. Отсортировано по ценности: сначала то, по чему можно начинать работу. У каждого артефакта видно, какая роль и на каком мире его выпустила, а Критик оставил разбор.
	</p>

	<div class="mt-3 flex flex-wrap items-center gap-2">
		<?php if ($is_owner): ?>
		<a href="/forge" class="n2j-btn-process">
			<i class="fas fa-hammer" aria-hidden="true"></i>Дашборд и запуск цикла
		</a>
		<?php endif; ?>
	</div>

	<!-- ============ Поиск ============ -->
	<form method="get" action="/forge/artifacts" class="mt-5 flex flex-wrap items-center gap-2" role="search">
		<?php if ($type !== ''): ?>
		<input type="hidden" name="type" value="<?php echo HTML::chars($type); ?>">
		<?php endif; ?>
		<label for="erp222-cat-q" class="sr-only">Поиск по артефактам</label>
		<div class="relative min-w-0 flex-1 sm:max-w-md">
			<i class="fas fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-gray-400" aria-hidden="true"></i>
			<input id="erp222-cat-q" type="search" name="q" value="<?php echo HTML::chars($q); ?>" autocomplete="off"
				   placeholder="Поиск: заголовок, сводка, текст артефакта"
				   class="n2j-input w-full pl-9">
		</div>
		<button type="submit" class="n2j-btn-process">
			<i class="fas fa-magnifying-glass text-[11px]" aria-hidden="true"></i>Найти
		</button>
		<?php if ($q !== '' OR $type !== ''): ?>
		<a href="/forge/artifacts" class="n2j-btn-secondary">Сбросить</a>
		<?php endif; ?>
	</form>

	<!-- ============ Фильтр по типам ============ -->
	<div class="mt-3 flex flex-wrap items-center gap-1.5">
		<a href="<?php echo HTML::chars($type_url('')); ?>" class="n2j-chip<?php echo $type === '' ? ' is-on' : ''; ?>">Все типы</a>
		<?php foreach ($types as $code => $t): ?>
			<?php $n = (int) Arr::get($type_counts, $code, 0); ?>
			<a href="<?php echo HTML::chars($type_url($code)); ?>" class="n2j-chip<?php echo $type === $code ? ' is-on' : ''; ?>">
				<i class="<?php echo HTML::chars((string) Arr::get($t, 'icon', 'fas fa-file-lines')); ?> text-[11px]" aria-hidden="true"></i><?php echo HTML::chars((string) $t['title']); ?><?php if ($n > 0): ?><span class="<?php echo $type === $code ? 'text-white/80' : 'text-gray-400 dark:text-gray-500'; ?>"><?php echo $n; ?></span><?php endif; ?>
			</a>
		<?php endforeach; ?>
	</div>

	<!-- ============ Список ============ -->
	<div class="n2j-card p-0 mt-5">

		<?php if (empty($rows)): ?>
		<div class="px-4 py-10 text-center sm:px-6">
			<i class="fas fa-cubes text-2xl text-gray-300 dark:text-gray-600" aria-hidden="true"></i>
			<?php if ($q !== '' OR $type !== ''): ?>
			<p class="mt-3 text-sm text-gray-600 dark:text-gray-300">По этому запросу артефактов нет.</p>
			<a href="/forge/artifacts" class="n2j-btn-secondary mt-3">Показать все артефакты</a>
			<?php else: ?>
			<p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Опубликованных артефактов пока нет: Кузница ещё не выпускала документов.</p>
			<?php endif; ?>
		</div>
		<?php else: ?>

		<div class="divide-y divide-gray-100 dark:divide-gray-700/60">
			<?php foreach ($rows as $a): ?>
			<article class="p-4 transition-colors hover:bg-gray-50/70 sm:p-6 dark:hover:bg-gray-700/20">
				<div class="flex flex-wrap items-center gap-x-2.5 gap-y-2 text-xs">
					<span class="inline-flex items-center gap-1.5 rounded-md bg-blue-50 px-2.5 py-1 font-semibold text-blue-800 dark:bg-blue-950/60 dark:text-blue-300">
						<i class="<?php echo HTML::chars((string) Arr::path($types, $a['type'].'.icon', 'fas fa-file-lines')); ?> text-[11px]" aria-hidden="true"></i><?php echo HTML::chars($a['type_title']); ?>
					</span>
					<span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2.5 py-1 font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300" title="Ценность артефакта: <?php echo HTML::chars($a['score_word']); ?>">
						<?php echo (int) $a['value_score']; ?><span class="font-semibold text-emerald-600/80 dark:text-emerald-400/80"><?php echo HTML::chars($a['score_word']); ?></span>
					</span>
					<span class="font-mono text-gray-400 dark:text-gray-500">#<?php echo (int) $a['id']; ?></span>
					<?php if ($is_owner AND ! $a['published']): ?>
					<span class="rounded-md bg-amber-100 px-2.5 py-1 font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">снят с публикации</span>
					<?php endif; ?>
					<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $a['created_at']))); ?></span>
				</div>

				<h2 class="n2j-h3 mt-2.5">
					<a href="/forge/artifact/<?php echo (int) $a['id']; ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400"><?php echo HTML::chars((string) $a['title']); ?></a>
				</h2>

				<?php $lead = trim((string) $a['summary']); ?>
				<?php if ($lead !== ''): ?>
				<p class="mt-2 max-w-4xl text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars(mb_strlen($lead) > 320 ? mb_substr($lead, 0, 320).'…' : $lead); ?></p>
				<?php endif; ?>

				<div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
					<span><i class="fas fa-user-tie mr-1 text-[11px]" aria-hidden="true"></i><?php echo HTML::chars($a['role_title']); ?></span>
					<?php if (trim((string) Arr::get($a, 'world_title', '')) !== ''): ?>
					<a href="/forge/world/<?php echo HTML::chars((string) Arr::get($a, 'world_code', '')); ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400"><i class="fas fa-globe mr-1 text-[11px]" aria-hidden="true"></i><?php echo HTML::chars((string) $a['world_title']); ?></a>
					<?php endif; ?>
					<?php if ( ! empty($a['critique'])): ?>
					<span class="text-amber-600 dark:text-amber-400"><i class="fas fa-scale-balanced mr-1 text-[11px]" aria-hidden="true"></i>разобран Критиком</span>
					<?php endif; ?>
				</div>

				<div class="mt-3 flex flex-wrap items-center gap-3 text-xs">
					<a href="/forge/artifact/<?php echo (int) $a['id']; ?>" class="inline-flex min-h-[36px] items-center gap-1.5 font-semibold text-blue-600 transition hover:underline dark:text-blue-400">
						Открыть артефакт<i class="fas fa-arrow-right text-[11px]" aria-hidden="true"></i>
					</a>
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
			'url_base'    => '/forge/artifacts',
			'params'      => array_filter(['q' => $q, 'type' => $type]),
			'anchor'      => '',
		]); ?>

		<?php endif; ?>
	</div>

	<!-- ============ Что за сервис ============ -->
	<section class="n2j-card mt-5">
		<h2 class="n2j-h3">Откуда берутся артефакты</h2>
		<p class="mt-1.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
			Кузница систем запускает цикл синтеза: каждая роль по очереди проектирует свой кусок — Архитектор описывает границы и данные, Инженер пишет контракты и алгоритмы, Аналитик считает метрики и стоимость, Критик разбирает сделанное и снижает ценность слабого, Редактор сводит это в промпты, планы проверки и оффер. Ценность артефакта складывается из четырёх осей: полезность, конкретность, новизна и реализуемость.
		</p>
		<div class="mt-3 flex flex-wrap gap-2">
			<?php foreach (['spec', 'schema', 'api', 'algo', 'prompt'] as $t): ?>
			<span class="n2j-badge n2j-badge-wait" title="<?php echo HTML::chars((string) Arr::path($types, $t.'.about', '')); ?>"><?php echo HTML::chars((string) Arr::path($types, $t.'.title', $t)); ?>: <?php echo HTML::chars((string) Arr::path($types, $t.'.proof', '')); ?></span>
			<?php endforeach; ?>
		</div>
	</section>
</div>
