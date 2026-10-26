<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /airadar/report/<id> — полный отчёт прогона.
 *
 * Публикация (владелец, 08.10.2026): отчёт по умолчанию в каталоге
 * (/airadar/catalog), снять и вернуть можно здесь же — вторым нажатием
 * (data-n2j-arm, общий app.js; пояснение — в подсказке кнопки), без window.confirm.
 *
 * @var array  $report    отчёт (id, title, summary, highlights, sections, stats…)
 * @var string $csrf      токен форм
 * @var array  $flash     ['notice' => …, 'error' => …]
 * @var bool   $published отчёт в каталоге? (пусто = да: значение приходит от контроллера)
 * @var bool   $is_owner  владелец? ему — «прислать письмом» и переключатель публикации
 */
// Service::report() отдаёт уже декодированные массивы; на случай сырой строки
// из БД приводим к одному виду (строка JSON → массив)
$soft = function ($v)
{
	if (is_array($v))
	{
		return $v;
	}

	$decoded = is_string($v) ? json_decode($v, TRUE) : NULL;

	return is_array($decoded) ? $decoded : [];
};

$sections = $soft(Arr::get($report, 'sections', []));
$stats = $soft(Arr::get($report, 'stats', []));
$highlights = $soft(Arr::get($report, 'highlights', []));
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/airadar" class="transition hover:text-blue-600 dark:hover:text-blue-400">ИИ-радар</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<a href="/airadar/catalog" class="transition hover:text-blue-600 dark:hover:text-blue-400">Отчёты</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300">#<?php echo (int) $report['id']; ?></span>
	</nav>

	<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1">🛰 <?php echo HTML::chars((string) Arr::get($report, 'title', '')); ?></h1>
			<p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-500 dark:text-gray-400"><span><?php echo HTML::chars(date('d.m.y H:i', strtotime((string) $report['created_at']))); ?></span>
				<?php if ( ! empty($published)): ?>
				<span class="n2j-badge n2j-badge-ok"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>опубликован</span>
				<?php else: ?>
				<span class="n2j-badge n2j-badge-run">снят с публикации</span>
				<?php endif; ?>
				<span class="sr-only">·</span>
				· материалов: <?php echo (int) Arr::get($stats, 'count_items', 0); ?>
				· новых фактов: <?php echo (int) Arr::get($stats, 'facts_new', 0); ?>
				· идей: <?php echo (int) Arr::get($stats, 'ideas_new', 0); ?>
				· лучший скор: <?php echo (int) Arr::get($stats, 'max_score', 0); ?>/100
				<?php echo ! empty($report['emailed']) ? ' · <span class="font-semibold text-emerald-600 dark:text-emerald-300">письмо отправлено</span>' : ''; ?></p>
		</div>
		<?php if ( ! empty($is_owner)): ?>
		<div class="flex flex-wrap items-center gap-2">
			<form method="post" action="/airadar/mail" class="inline">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<button data-n2j-arm="Точно прислать?" title="Прислать этот отчёт письмом сейчас?" type="submit" class="n2j-btn-secondary"><i class="fas fa-envelope mr-2" aria-hidden="true"></i>Прислать письмом</button>
			</form>

			<?php // Публикация — в два клика по той же кнопке: первый взводит, второй отправляет. ?>
			<form method="post" action="/airadar/report/<?php echo (int) $report['id']; ?>/publish" class="inline">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<input type="hidden" name="published" value="<?php echo ! empty($published) ? '0' : '1'; ?>">
				<button data-n2j-arm="<?php echo ! empty($published) ? 'Точно снять?' : 'Точно вернуть?'; ?>" title="<?php echo ! empty($published) ? 'Снять отчёт с публикации — он пропадёт из каталога?' : 'Вернуть отчёт в каталог?'; ?>" type="submit" class="n2j-btn-secondary"><i class="fas <?php echo ! empty($published) ? 'fa-eye-slash' : 'fa-eye'; ?> mr-2" aria-hidden="true"></i><?php echo ! empty($published) ? 'Снять с публикации' : 'Вернуть в каталог'; ?></button>
			</form>
		</div>
		<?php endif; ?>
	</div>

	<?php if ($flash['notice'] !== ''): ?>
		<p class="n2j-flash n2j-flash-ok mt-5 block"><?php echo HTML::chars($flash['notice']); ?></p>
	<?php endif; ?>
	<?php if ($flash['error'] !== ''): ?>
		<p class="n2j-flash n2j-flash-err mt-5 block"><?php echo HTML::chars($flash['error']); ?></p>
	<?php endif; ?>

	<?php $summary = trim((string) Arr::get($report, 'summary', '')); ?>
	<?php if ($summary !== ''): ?>
		<p class="mt-5 whitespace-pre-line rounded-xl border border-blue-200 bg-blue-50/60 p-4 text-sm leading-relaxed text-gray-700 dark:border-blue-900 dark:bg-blue-950/20 dark:text-gray-300"><?php echo HTML::chars($summary); ?></p>
	<?php endif; ?>

	<?php if ($highlights): ?>
		<section class="n2j-card mt-5">
			<h2 class="n2j-h3">Главное</h2>
			<ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
				<?php foreach ($highlights as $h): ?><li><?php echo HTML::chars((string) $h); ?></li><?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php foreach ((array) Arr::get($sections, 'sections', []) as $section): ?>
		<section class="n2j-card mt-5">
			<h2 class="n2j-h3"><?php echo HTML::chars((string) Arr::get($section, 'title', '')); ?></h2>
			<div class="mt-3 space-y-3">
				<?php foreach ((array) Arr::get($section, 'items', []) as $item): ?>
					<div class="rounded-lg border border-gray-100 p-3 dark:border-gray-700">
						<div class="flex flex-wrap items-baseline gap-2">
							<a href="<?php echo HTML::chars((string) Arr::get($item, 'url', '')); ?>" rel="noopener nofollow" class="text-sm font-semibold text-gray-900 hover:text-blue-700 dark:text-white dark:hover:text-blue-300"><?php echo HTML::chars((string) Arr::get($item, 'title', '')); ?></a>
							<span class="n2j-badge n2j-badge-ok"><?php echo (int) Arr::get($item, 'score', 0); ?>/100</span>
							<span class="text-xs uppercase tracking-wide text-gray-400"><?php echo HTML::chars((string) Arr::get($item, 'source', '')); ?></span>
						</div>
						<?php if ((string) Arr::get($item, 'why', '') !== ''): ?><p class="n2j-hint"><?php echo HTML::chars((string) Arr::get($item, 'why', '')); ?></p><?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endforeach; ?>

	<?php $facts = (array) Arr::get($sections, 'facts_new', []); ?>
	<?php if ($facts): ?>
		<section class="n2j-card mt-5">
			<h2 class="n2j-h3">Новые факты в базу знаний</h2>
			<ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
				<?php foreach ($facts as $fact): ?>
					<li class="flex flex-wrap items-baseline gap-2">
						<span class="min-w-0 flex-1">• <?php echo HTML::chars((string) Arr::get($fact, 'text', '')); ?></span>
						<?php foreach (array_slice(explode(',', (string) Arr::get($fact, 'tags', '')), 0, 4) as $tag): if (trim($tag) === '') continue; ?>
							<span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars(trim($tag)); ?></span>
						<?php endforeach; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="n2j-hint mt-3">Всего в базе: <?php echo (int) Arr::get($stats, 'total_facts', 0); ?> фактов · <a href="/airadar/facts" class="text-blue-700 hover:text-blue-900 dark:text-blue-300">открыть базу →</a></p>
		</section>
	<?php endif; ?>

	<?php $ideas = (array) Arr::get($sections, 'ideas', []); ?>
	<?php if ($ideas): ?>
		<section class="mt-5 rounded-xl border border-blue-200 bg-blue-50/40 p-5 dark:border-blue-900 dark:bg-blue-950/20">
			<h2 class="n2j-h3">💡 Идеи для N2J</h2>
			<div class="mt-3 space-y-3">
				<?php foreach ($ideas as $idea): ?>
					<div class="rounded-lg border border-blue-100 bg-white p-3 dark:border-blue-900 dark:bg-gray-800">
						<p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($idea, 'title', '')); ?> <span class="text-xs font-bold text-blue-600 dark:text-blue-300">(<?php echo (int) Arr::get($idea, 'score', 0); ?>)</span></p>
						<?php if ((string) Arr::get($idea, 'body', '') !== ''): ?><p class="mt-1 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($idea, 'body', '')); ?></p><?php endif; ?>
						<?php if ((string) Arr::get($idea, 'benefit', '') !== ''): ?><p class="mt-1 text-xs text-gray-600 dark:text-gray-300"><b>Польза:</b> <?php echo HTML::chars((string) Arr::get($idea, 'benefit', '')); ?></p><?php endif; ?>
						<?php if ((string) Arr::get($idea, 'fit', '') !== ''): ?><p class="mt-1 text-xs text-blue-700 dark:text-blue-300"><b>Куда на n2j:</b> <?php echo HTML::chars((string) Arr::get($idea, 'fit', '')); ?></p><?php endif; ?>
						<?php if ((string) Arr::get($idea, 'source_url', '') !== ''): ?><a href="<?php echo HTML::chars((string) Arr::get($idea, 'source_url', '')); ?>" rel="noopener nofollow" class="mt-1 inline-block text-xs text-blue-700 hover:text-blue-900 dark:text-blue-300">источник</a><?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="n2j-hint mt-3"><a href="/airadar/ideas" class="text-blue-700 hover:text-blue-900 dark:text-blue-300">Управлять идеями →</a></p>
		</section>
	<?php endif; ?>
</div>
