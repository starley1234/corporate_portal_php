<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /dialog/read/<id> — страница ЧТЕНИЯ разговора (решение владельца 09.10.2026).
 *
 * Отличается от /dialog/<id> назначением, а не данными: та страница — пульт
 * (статус-ряд, настройки, материалы, публикация), эта — текст. Поэтому здесь
 * другая вёрстка и другой масштаб:
 *
 *   · колонка уже (max-w-3xl) — длинную строку читать неудобно;
 *   · шрифт крупнее: реплики text-lg (на пульте text-sm), тема — до 4xl;
 *   · интерлиньяж выше (leading-relaxed/loose) и абзацы разделены отступом;
 *   · управлений нет: ни настроек, ни кнопок хода — только ссылки;
 *   · найденные источники собраны под репликами обмена мелким списком, чтобы
 *     доказательства не рвали чтение.
 *
 * Кого пускает контроллер: опубликованный диалог — всех, неопубликованный —
 * владельца и админа (черновик тоже удобно читать в «читательском» виде).
 *
 * @var array  $dialog    строка диалога
 * @var array  $progress  живые данные хода
 * @var array  $messages  реплики (агентов и владельца)
 * @var array  $searches  поиски в интернете
 * @var array  $events    лента событий (только владельцу)
 * @var bool   $own       свой ли это диалог
 * @var bool   $can_start есть ли право запускать/продолжать разговор
 * @var string $csrf      токен форм
 * @var array  $flash     ['tone' => notice|error, 'text' => …]
 */
$search_by_turn = [];

foreach ($searches as $s)
{
	$search_by_turn[(int) $s['turn']][] = $s;
}

$pairs = (int) $dialog['pairs'];

$who = [
	'a'    => ['badge' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200', 'icon' => 'fa-cubes'],
	'b'    => ['badge' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200', 'icon' => 'fa-lightbulb'],
	'user' => ['badge' => 'bg-amber-100 text-amber-900 dark:bg-amber-900/60 dark:text-amber-200', 'icon' => 'fa-user-pen'],
];

$style_of = function ($code) use ($who) {
	return $who[(string) $code === 'b' ? 'b' : ((string) $code === 'user' ? 'user' : 'a')];
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-nj662-view data-nj662-run="0">

	<?php if ( ! empty($flash['text'])): ?>
	<div class="mx-auto mb-5 flex max-w-3xl items-center gap-3 rounded-xl border <?php echo $flash['tone'] === 'error'
			? 'border-rose-300/80 bg-rose-50/90 text-rose-900 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-200'
			: 'border-emerald-300/80 bg-emerald-50/90 text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200'; ?> px-4 py-3 text-sm shadow-xs">
		<i class="fas <?php echo $flash['tone'] === 'error' ? 'fa-circle-exclamation text-rose-600 dark:text-rose-400' : 'fa-circle-check text-emerald-600 dark:text-emerald-400'; ?> shrink-0 text-base" aria-hidden="true"></i>
		<span class="flex-1"><?php echo HTML::chars((string) $flash['text']); ?></span>
	</div>
	<?php endif; ?>

	<div class="mx-auto max-w-3xl">

		<!-- ============ Хлебные крошки и переключатель вида ============ -->
		<nav class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
			<a href="/dialog/list" class="transition hover:text-blue-600 dark:hover:text-blue-400">Опубликованные диалоги</a>
			<span class="text-gray-300 dark:text-gray-600">/</span>
			<span class="font-semibold text-gray-700 dark:text-gray-300">Разговор #<?php echo (int) $dialog['id']; ?></span>
			<?php if ($own): ?>
			<a href="/dialog/<?php echo (int) $dialog['id']; ?>" class="ml-auto inline-flex items-center gap-1.5 font-semibold text-blue-600 transition hover:underline dark:text-blue-400">
				<i class="fas fa-sliders text-[11px]" aria-hidden="true"></i>Управление диалогом
			</a>
			<?php endif; ?>
		</nav>

		<!-- ============ Заголовок ============ -->
		<header class="mt-4">
			<h1 class="n2j-h1"><?php echo HTML::chars((string) $dialog['topic']); ?></h1>

			<p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-gray-500 dark:text-gray-400">
				<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 font-semibold <?php echo $who['a']['badge']; ?>">
					<i class="fas <?php echo $who['a']['icon']; ?> text-xs" aria-hidden="true"></i><?php echo HTML::chars((string) $dialog['a_name']); ?>
				</span>
				<span class="text-gray-300 dark:text-gray-600" aria-hidden="true">×</span>
				<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 font-semibold <?php echo $who['b']['badge']; ?>">
					<i class="fas <?php echo $who['b']['icon']; ?> text-xs" aria-hidden="true"></i><?php echo HTML::chars((string) $dialog['b_name']); ?>
				</span>
				<span class="text-gray-300 dark:text-gray-600" aria-hidden="true">·</span>
				<span class="font-semibold text-gray-600 dark:text-gray-300"><?php echo HTML::chars($progress['turn_word']); ?></span>
				<?php if ((int) $dialog['searches_used'] > 0): ?>
				<span class="text-gray-300 dark:text-gray-600" aria-hidden="true">·</span>
				<span title="поиски агентов в интернете"><i class="fas fa-magnifying-glass text-[11px]" aria-hidden="true"></i> <?php echo (int) $dialog['searches_used']; ?></span>
				<?php endif; ?>
				<?php if (trim((string) $dialog['published_at']) !== ''): ?>
				<span class="text-gray-300 dark:text-gray-600" aria-hidden="true">·</span>
				<span>опубликован <?php echo HTML::chars(date('d.m.Y', strtotime((string) $dialog['published_at']))); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( ! Service_Nj662_Dialog::as_bool($dialog['is_public'])): ?>
			<p class="n2j-flash n2j-flash-warn mt-3 flex items-center gap-2 text-xs">
				<i class="fas fa-eye-slash" aria-hidden="true"></i>Разговор не опубликован: страницу видите только вы<?php echo $own ? '' : ' (админ)'; ?>.
			</p>
			<?php endif; ?>
		</header>

		<?php if (trim((string) $dialog['brief']) !== ''): ?>
		<section class="n2j-card mt-5">
			<h2 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Что было известно на старте</h2>
			<p class="mt-2 whitespace-pre-line text-base leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $dialog['brief']); ?></p>
		</section>
		<?php endif; ?>

		<?php if (trim((string) $dialog['summary']) !== ''): ?>
		<section class="mt-4 rounded-xl border border-emerald-200/80 bg-emerald-50/50 p-4 shadow-xs sm:p-5 dark:border-emerald-900/60 dark:bg-emerald-950/20">
			<h2 class="flex items-center gap-2 text-sm font-bold text-emerald-900 dark:text-emerald-200">
				<i class="fas fa-compress" aria-hidden="true"></i>Коротко о разговоре
			</h2>
			<p class="mt-2 whitespace-pre-line text-base leading-relaxed text-emerald-900/90 dark:text-emerald-100/90"><?php echo HTML::chars((string) $dialog['summary']); ?></p>
		</section>
		<?php endif; ?>

		<!-- ============ Разговор ============ -->
		<section class="mt-6">
			<h2 class="n2j-h3 flex items-center gap-2">
				<i class="fas fa-comment-dots text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Разговор
			</h2>

			<?php if (empty($messages)): ?>
			<p class="mt-3 text-base text-gray-500 dark:text-gray-400">Агенты ещё не сказали ни слова.</p>
			<?php else: ?>
			<div class="mt-4">
				<?php $shown_turn = 0; ?>
				<?php foreach ($messages as $m): ?>
					<?php if ((int) $m['turn'] !== $shown_turn): ?>
						<?php $shown_turn = (int) $m['turn']; ?>
						<div class="mt-8 flex items-center gap-3">
							<span class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500"><?php echo HTML::chars(Service_Nj662_Dialog::pair_word($shown_turn, $pairs)); ?></span>
							<span class="h-px flex-1 bg-gray-200 dark:bg-gray-700/60"></span>
						</div>
					<?php endif; ?>

					<?php $style = $style_of($m['who']); ?>
					<article class="mt-4">
						<p class="flex flex-wrap items-center gap-2 text-sm">
							<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-semibold <?php echo $style['badge']; ?>">
								<i class="fas <?php echo $style['icon']; ?> text-xs" aria-hidden="true"></i><?php echo HTML::chars((string) $m['author_name']); ?>
							</span>
							<?php if ((string) $m['who'] !== 'user' AND trim((string) $m['model']) !== ''): ?>
							<span class="text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars(Service_Nj662_Models::label((string) $m['model'])); ?></span>
							<?php endif; ?>
							<span class="ml-auto text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $m['created_at']))); ?></span>
						</p>
						<div class="mt-2 space-y-3 text-lg leading-relaxed text-gray-800 dark:text-gray-100">
							<?php foreach (preg_split('/\n{2,}/u', trim((string) $m['content'])) as $para): ?>
							<p><?php echo nl2br(HTML::chars(trim($para))); ?></p>
							<?php endforeach; ?>
						</div>
					</article>

				<?php endforeach; ?>
			</div>

			<?php // Поиски показываем отдельным списком в конце каждого обмена. ?>
			<?php if ($search_by_turn): ?>
			<div class="mt-8 rounded-xl border border-amber-200/80 bg-amber-50/60 p-4 shadow-xs sm:p-5 dark:border-amber-900/60 dark:bg-amber-950/20">
				<h3 class="flex items-center gap-2 text-sm font-bold text-amber-900 dark:text-amber-200">
					<i class="fas fa-magnifying-glass" aria-hidden="true"></i>Что агенты искали в интернете
				</h3>
				<ul class="mt-3 space-y-3">
					<?php foreach ($search_by_turn as $turn => $items): ?>
					<?php foreach ($items as $s): ?>
					<li class="text-sm leading-relaxed">
						<p class="flex flex-wrap items-center gap-2 text-xs text-amber-900/80 dark:text-amber-200/80">
							<span class="font-semibold"><?php echo HTML::chars(Service_Nj662_Dialog::pair_word((int) $turn, $pairs)); ?></span>
							<span class="font-mono text-xs"><?php echo HTML::chars((string) $s['query']); ?></span>
							<span>находок: <?php echo (int) $s['found']; ?></span>
						</p>
						<?php if ( ! empty($s['results'])): ?>
						<ul class="mt-1.5 space-y-1">
							<?php foreach (array_slice((array) $s['results'], 0, 5) as $r): ?>
							<li class="text-sm leading-relaxed text-gray-700 dark:text-gray-200">
								<a href="<?php echo HTML::chars((string) Arr::get($r, 'url', '#')); ?>" target="_blank" rel="nofollow noopener noreferrer" class="font-semibold text-amber-900 underline decoration-amber-300 hover:text-amber-700 dark:text-amber-200"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) Arr::get($r, 'title', ''), 90)); ?></a>
								<span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) Arr::get($r, 'body', ''), 140)); ?></span>
							</li>
							<?php endforeach; ?>
						</ul>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
			<?php endif; ?>
		</section>

		<?php if ($own AND ! empty($events)): ?>
		<details class="n2j-card mt-6">
			<summary class="cursor-pointer text-sm font-bold text-gray-900 dark:text-white">
				<i class="fas fa-clock-rotate-left text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Что происходило
			</summary>
			<ol class="mt-3 space-y-2">
				<?php foreach ($events as $e): ?>
				<li class="flex items-start gap-2.5">
					<i class="fas fa-circle-small mt-1 text-[10px] text-gray-400" aria-hidden="true"></i>
					<div class="min-w-0 flex-1">
						<p class="text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $e['message']); ?></p>
						<p class="n2j-hint mt-0.5"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $e['created_at']))); ?></p>
					</div>
				</li>
				<?php endforeach; ?>
			</ol>
		</details>
		<?php endif; ?>

		<!-- ============ Что дальше ============ -->
		<section class="mt-8 rounded-xl border border-blue-200/80 bg-blue-50/40 p-4 shadow-xs sm:p-5 dark:border-blue-900/60 dark:bg-blue-950/20">
			<h2 class="n2j-h3 flex items-center gap-2">
				<i class="fas fa-comments text-blue-600 dark:text-blue-400" aria-hidden="true"></i><?php echo $can_start ? 'Попробовать самому' : 'Другие разговоры'; ?>
			</h2>
			<p class="mt-1.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
				<?php echo $can_start
					? 'Так же обсуждают любую вашу тему: два агента, серверная очередь обменов, поиск в интернете по желанию и материалы, на которые агенты опираются.'
					: 'Опубликованных разговоров станет больше: два агента обсуждают тему по делу, а готовый разбор можно читать целиком.'; ?>
			</p>
			<p class="mt-3 flex flex-wrap gap-2">
				<?php if ($can_start): ?>
				<a href="/dialog" class="n2j-btn-process">
					<i class="fas fa-wand-magic-sparkles text-[13px]" aria-hidden="true"></i>Начать свой диалог
				</a>
				<?php endif; ?>
				<a href="/dialog/list" class="n2j-btn-secondary">
					<i class="fas fa-book-open text-[13px]" aria-hidden="true"></i>Другие разговоры
				</a>
				<?php if ($own): ?>
				<a href="/dialog/<?php echo (int) $dialog['id']; ?>" class="n2j-btn-secondary">
					<i class="fas fa-sliders text-[13px]" aria-hidden="true"></i>Настройки и публикация
				</a>
				<?php endif; ?>
			</p>
		</section>
	</div>
</div>
