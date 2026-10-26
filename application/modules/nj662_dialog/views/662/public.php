<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Публичное чтение диалога (/dialog/<id> для гостя и чужого аккаунта).
 *
 * Опубликованный разговор — это результат, а не «личная переписка»: страница
 * открыта всем и индексируется (адрес объявлен в карте сайта nj602), потому что
 * владелец публикует её сам и в любой момент может снять с публикации.
 *
 * Что видно, а что нет: видно тему, вводную, участников и весь разговор со
 * ссылками на найденные источники; НЕ видно настроек, материалов диалога и
 * журнала поисков — это рабочие вещи владельца.
 *
 * @var array  $dialog    строка диалога
 * @var array  $progress  живые данные хода
 * @var array  $messages  реплики
 * @var array  $searches  поиски в интернете
 * @var array  $flash     ['tone' => …, 'text' => …]
 */
$search_by_turn = [];

foreach ($searches as $s)
{
	$search_by_turn[(int) $s['turn']][] = $s;
}

$pairs = (int) $dialog['pairs'];

$who_class = [
	'a' => ['wrap' => 'border-blue-200/80 bg-blue-50/50 dark:border-blue-900/60 dark:bg-blue-950/20', 'badge' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200', 'icon' => 'fa-cubes'],
	'b' => ['wrap' => 'border-emerald-200/80 bg-emerald-50/50 dark:border-emerald-900/60 dark:bg-emerald-950/20', 'badge' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200', 'icon' => 'fa-lightbulb'],
	// Реплика владельца (когда он правил направление разговора).
	'user' => ['wrap' => 'border-amber-300/80 bg-amber-50/60 dark:border-amber-800/70 dark:bg-amber-950/20', 'badge' => 'bg-amber-100 text-amber-900 dark:bg-amber-900/60 dark:text-amber-200', 'icon' => 'fa-user-pen'],
];

$style_of = function ($code) use ($who_class) {
	return $who_class[(string) $code === 'b' ? 'b' : ((string) $code === 'user' ? 'user' : 'a')];
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-nj662-view data-nj662-run="0">

	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/dialog/list" class="transition hover:text-blue-600 dark:hover:text-blue-400">Опубликованные диалоги</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300">Разговор #<?php echo (int) $dialog['id']; ?></span>
	</nav>

	<header class="mt-3">
		<h1 class="n2j-h1"><?php echo HTML::chars((string) $dialog['topic']); ?></h1>
		<p class="n2j-hint mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
			<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 font-semibold <?php echo $who_class['a']['badge']; ?>">
				<i class="fas fa-cubes text-[10px]" aria-hidden="true"></i><?php echo HTML::chars((string) $dialog['a_name']); ?>
			</span>
			<span class="text-gray-300 dark:text-gray-600">×</span>
			<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 font-semibold <?php echo $who_class['b']['badge']; ?>">
				<i class="fas fa-lightbulb text-[10px]" aria-hidden="true"></i><?php echo HTML::chars((string) $dialog['b_name']); ?>
			</span>
			<span class="text-gray-300 dark:text-gray-600">·</span>
			<span><?php echo HTML::chars($progress['turn_word']); ?></span>
			<?php if ((int) $dialog['searches_used'] > 0): ?>
			<span class="text-gray-300 dark:text-gray-600">·</span>
			<span title="поиски агентов в интернете"><i class="fas fa-magnifying-glass text-[10px]" aria-hidden="true"></i> <?php echo (int) $dialog['searches_used']; ?></span>
			<?php endif; ?>
			<?php if (trim((string) $dialog['published_at']) !== ''): ?>
			<span class="text-gray-300 dark:text-gray-600">·</span>
			<span>опубликован <?php echo HTML::chars(date('d.m.Y', strtotime((string) $dialog['published_at']))); ?></span>
			<?php endif; ?>
		</p>

		<?php if (trim((string) $dialog['brief']) !== ''): ?>
		<div class="n2j-card mt-4">
			<h2 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Что было известно на старте</h2>
			<p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $dialog['brief']); ?></p>
		</div>
		<?php endif; ?>
	</header>

	<?php if (trim((string) $dialog['summary']) !== ''): ?>
	<section class="mt-4 rounded-xl border border-emerald-200/80 bg-emerald-50/50 p-4 shadow-xs sm:p-5 dark:border-emerald-900/60 dark:bg-emerald-950/20">
		<h2 class="flex items-center gap-2 text-sm font-bold text-emerald-900 dark:text-emerald-200">
			<i class="fas fa-compress" aria-hidden="true"></i>Коротко о разговоре
		</h2>
		<p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-emerald-900/90 dark:text-emerald-100/90"><?php echo HTML::chars((string) $dialog['summary']); ?></p>
	</section>
	<?php endif; ?>

	<section class="n2j-card mt-4">
		<h2 class="n2j-h3 flex items-center gap-2">
			<i class="fas fa-comment-dots text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Разговор
		</h2>

		<?php if (empty($messages)): ?>
		<p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Агенты ещё не сказали ни слова.</p>
		<?php else: ?>
		<div class="mt-4 space-y-3">
			<?php $shown_turn = 0; ?>
			<?php foreach ($messages as $m): ?>
				<?php if ((int) $m['turn'] !== $shown_turn): ?>
					<?php $shown_turn = (int) $m['turn']; ?>
					<div class="flex items-center gap-3 pt-2">
						<span class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500"><?php echo HTML::chars(Service_Nj662_Dialog::pair_word($shown_turn, $pairs)); ?></span>
						<span class="h-px flex-1 bg-gray-100 dark:bg-gray-700/60"></span>
					</div>
					<?php foreach (Arr::get($search_by_turn, $shown_turn, []) as $s): ?>
					<div class="rounded-lg border border-amber-200/80 bg-amber-50/60 px-3.5 py-3 dark:border-amber-900/60 dark:bg-amber-950/20">
						<p class="flex flex-wrap items-center gap-2 text-xs text-amber-900 dark:text-amber-200">
							<i class="fas fa-magnifying-glass" aria-hidden="true"></i>
							<span class="font-semibold">Искали в интернете:</span>
							<span class="font-mono text-xs"><?php echo HTML::chars((string) $s['query']); ?></span>
						</p>
						<?php if ( ! empty($s['results'])): ?>
						<ul class="mt-2 space-y-1">
							<?php foreach (array_slice((array) $s['results'], 0, 5) as $r): ?>
							<li class="text-xs leading-relaxed">
								<a href="<?php echo HTML::chars((string) Arr::get($r, 'url', '#')); ?>" target="_blank" rel="nofollow noopener noreferrer" class="font-semibold text-amber-900 underline decoration-amber-300 hover:text-amber-700 dark:text-amber-200"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) Arr::get($r, 'title', ''), 90)); ?></a>
								<span class="text-amber-700/80 dark:text-amber-300/80"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) Arr::get($r, 'body', ''), 140)); ?></span>
							</li>
							<?php endforeach; ?>
						</ul>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				<?php endif; ?>

				<?php $style = $style_of($m['who']); ?>
				<article class="rounded-xl border px-4 py-3.5 <?php echo $style['wrap']; ?>">
					<p class="flex flex-wrap items-center gap-2 text-xs">
						<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 font-semibold <?php echo $style['badge']; ?>">
							<i class="fas <?php echo $style['icon']; ?> text-xs" aria-hidden="true"></i><?php echo HTML::chars((string) $m['author_name']); ?>
						</span>
						<span class="ml-auto text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $m['created_at']))); ?></span>
					</p>
					<div class="mt-2.5 space-y-2 text-sm leading-relaxed text-gray-800 dark:text-gray-100">
						<?php foreach (preg_split('/\n{2,}/u', trim((string) $m['content'])) as $para): ?>
						<p><?php echo nl2br(HTML::chars(trim($para))); ?></p>
						<?php endforeach; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</section>

	<?php // Читательский вид: тот же разговор крупнее и без всяких управлений. ?>
	<p class="mt-4">
		<a href="/dialog/read/<?php echo (int) $dialog['id']; ?>" class="n2j-btn-secondary">
			<i class="fas fa-book-open-reader text-[13px]" aria-hidden="true"></i>Читать этот разговор
		</a>
	</p>

	<section class="mt-5 rounded-xl border border-blue-200/80 bg-blue-50/40 p-4 shadow-xs sm:p-5 dark:border-blue-900/60 dark:bg-blue-950/20">
		<h2 class="n2j-h3 flex items-center gap-2">
			<i class="fas fa-comments text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Попробовать самому
		</h2>
		<p class="mt-1.5 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
			Так же обсуждают любую вашу тему: два агента, серверная очередь обменов, поиск в интернете по желанию и материалы, на которые агенты опираются.
		</p>
		<p class="mt-3 flex flex-wrap gap-2">
			<a href="/dialog" class="n2j-btn-process">
				<i class="fas fa-wand-magic-sparkles text-[13px]" aria-hidden="true"></i>Начать свой диалог
			</a>
			<a href="/dialog/list" class="n2j-btn-secondary">
				<i class="fas fa-book-open text-[13px]" aria-hidden="true"></i>Другие разговоры
			</a>
		</p>
	</section>
</div>
