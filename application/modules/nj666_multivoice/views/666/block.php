<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Просмотр и правка одного блока книги (nj666).
 *
 * Реплики правятся как есть: speaker + текст, без повторного разреза по
 * кавычкам. После смены turns статус и файл озвучки сбрасываются
 * (владелец, 11.10.2026).
 */

$logged     = isset($logged) ? (bool) $logged : FALSE;
$can_run    = isset($can_run) ? (bool) $can_run : FALSE;
$enabled    = isset($enabled) ? (bool) $enabled : TRUE;
$book       = isset($book) ? (array) $book : [];
$block      = isset($block) ? (array) $block : [];
$characters = isset($characters) ? (array) $characters : [];
$turns      = isset($turns) ? (array) $turns : [];
$error      = isset($error) ? (string) $error : '';
$csrf       = isset($csrf) ? (string) $csrf : '';

$code   = (string) Arr::get($book, 'code');
$no     = (int) Arr::get($block, 'no', 0);
$title  = (string) Arr::get($block, 'title', '');
$status = (string) Arr::get($block, 'status', 'new');
$ready  = $status === 'voiced';
$fail   = $status === 'error';

$nar_id = 0;

foreach ($characters as $one)
{
	if ((string) Arr::get($one, 'kind') === 'narrator' AND $nar_id === 0)
	{
		$nar_id = (int) Arr::get($one, 'id');
	}
}

if ( ! $turns)
{
	$turns = [['speaker' => 'narrator', 'text' => '']];
}
else
{
	$last = end($turns);

	if (trim((string) Arr::get($last, 'text', '')) !== '')
	{
		$turns[] = ['speaker' => 'narrator', 'text' => ''];
	}
}

$speaker_of = function ($turn) use ($nar_id) {
	$speaker = trim((string) Arr::get($turn, 'speaker', 'narrator'));

	if ($speaker === '' OR $speaker === '0' OR ($nar_id > 0 AND $speaker === (string) $nar_id))
	{
		return 'narrator';
	}

	return $speaker;
};

$numbers = function ($value) { return number_format((int) $value, 0, ',', ' '); };
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" id="nj666-block" data-code="<?php echo HTML::chars($code); ?>" data-no="<?php echo $no; ?>">

	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<a href="/multivoice" class="transition hover:text-blue-600 dark:hover:text-blue-400">Многоголосая озвучка</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<a href="/multivoice/b/<?php echo HTML::chars($code); ?>" class="transition hover:text-blue-600 dark:hover:text-blue-400"><?php echo HTML::chars((string) Arr::get($book, 'title')); ?></a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-medium text-gray-700 dark:text-gray-200">Блок <?php echo sprintf('%02d', $no); ?></span>
	</nav>

	<div class="mt-3 flex flex-wrap items-start gap-3">
		<div class="min-w-0 flex-1">
			<h1 class="n2j-h1">
				<i class="fas fa-list-ol mr-2 text-blue-500"></i>Блок <?php echo sprintf('%02d', $no); ?>
			</h1>
			<p class="n2j-hint">
				<?php echo $numbers(Arr::get($block, 'chars', 0)); ?> знаков
				<?php if ($fail AND (string) Arr::get($block, 'error') !== ''): ?>
				· <span class="text-rose-600 dark:text-rose-400"><?php echo HTML::chars((string) Arr::get($block, 'error')); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<a href="/multivoice/b/<?php echo HTML::chars($code); ?>"
			class="n2j-btn-secondary">
			<i class="fas fa-arrow-left"></i><span>К книге</span>
		</a>
	</div>

	<p id="nj666-status" class="n2j-hint mt-2" role="status" aria-live="polite"></p>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php endif; ?>

	<form id="nj666-block-form" method="post" action="/multivoice/b/<?php echo HTML::chars($code); ?>/k/<?php echo $no; ?>" class="mt-5 space-y-5">
		<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

		<div class="n2j-card">
			<label for="nj666-block-title" class="n2j-label">Заголовок блока</label>
			<input id="nj666-block-title" name="title" type="text" maxlength="300" value="<?php echo HTML::chars($title); ?>"
				class="n2j-input mt-1 w-full"
				autocomplete="off">
			<p class="n2j-hint mt-1.5">Смена заголовка озвучку не сбрасывает. Правка реплик — сбрасывает статус и файл.</p>
		</div>

		<div class="n2j-card p-0">
			<div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
				<h2 class="n2j-h3"><i class="fas fa-comments mr-1.5 text-blue-500"></i>Реплики</h2>
				<span class="n2j-badge n2j-badge-wait"><?php echo count($turns); ?></span>
			</div>

			<ol id="nj666-turns" class="divide-y divide-gray-100 dark:divide-gray-700">
				<?php foreach ($turns as $i => $turn): ?>
				<?php
					$i        = (int) $i;
					$speaker  = $speaker_of($turn);
					$para     = (int) Arr::get($turn, 'p', 0);
					$text     = (string) Arr::get($turn, 'text', '');
				?>
				<li class="nj666-turn px-4 py-3">
					<div class="flex flex-wrap items-center gap-2">
						<span class="nj666-turn-no w-8 shrink-0 text-center text-xs font-bold text-gray-400"><?php echo $i + 1; ?></span>
						<select class="n2j-input n2j-select nj666-turn-speaker min-w-0 flex-1 py-1.5 text-xs min-h-0"
							id="nj666-t-sp-<?php echo $i; ?>" name="turns[<?php echo $i; ?>][speaker]" autocomplete="off">
							<option value="narrator"<?php echo $speaker === 'narrator' ? ' selected' : ''; ?>>Рассказчик</option>
							<?php foreach ($characters as $one): ?>
							<?php if ((string) Arr::get($one, 'kind') === 'narrator') continue; ?>
							<?php $cid = (int) Arr::get($one, 'id'); ?>
							<option value="<?php echo $cid; ?>"<?php echo $speaker === (string) $cid ? ' selected' : ''; ?>><?php echo HTML::chars((string) Arr::get($one, 'name')); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ($para > 0): ?>
						<input type="hidden" class="nj666-turn-p" name="turns[<?php echo $i; ?>][p]" value="<?php echo $para; ?>">
						<span class="text-xs text-gray-400" title="номер абзаца в исходнике">абз. <?php echo $para; ?></span>
						<?php endif; ?>
						<button type="button" class="n2j-btn-secondary n2j-btn-sm nj666-turn-del"
							title="Убрать реплику из формы">
							<i class="fas fa-times"></i>
						</button>
					</div>
					<textarea class="n2j-textarea nj666-turn-text mt-2 w-full leading-relaxed"
						id="nj666-t-tx-<?php echo $i; ?>" name="turns[<?php echo $i; ?>][text]" rows="3"><?php echo HTML::chars($text); ?></textarea>
				</li>
				<?php endforeach; ?>
			</ol>

			<div class="border-t border-gray-100 px-4 py-3 dark:border-gray-800">
				<button type="button" id="nj666-add-turn"
					class="n2j-btn-secondary n2j-btn-sm">
					<i class="fas fa-plus"></i><span>Добавить реплику</span>
				</button>
			</div>
		</div>

		<div class="flex flex-wrap items-center gap-2">
			<button type="submit" id="nj666-block-save"
				class="n2j-btn-cta">
				<i class="fas fa-floppy-disk"></i><span>Сохранить</span>
			</button>
			<button type="button" id="nj666-block-voice"<?php echo ($can_run AND $enabled) ? '' : ' disabled'; ?>
				class="n2j-btn-cta"
				data-id="<?php echo (int) Arr::get($block, 'id'); ?>">
				<i class="fas fa-microphone-lines"></i><span><?php echo $ready ? 'Озвучить заново' : 'Озвучить блок'; ?></span>
			</button>
			<?php if ( ! $can_run): ?>
			<span class="text-xs text-amber-700 dark:text-amber-400">Озвучка — для vip и администраторов.</span>
			<?php endif; ?>
		</div>
	</form>

	<?php if ($ready): ?>
	<div class="n2j-card mt-5">
		<h2 class="n2j-h3"><i class="fas fa-volume-high mr-1.5 text-blue-500"></i>Озвучка</h2>
		<audio controls preload="none" class="mt-2 w-full" src="/multivoice/f/<?php echo HTML::chars($code); ?>/<?php echo $no; ?>"></audio>
	</div>
	<?php endif; ?>

</div>

<?php echo View::factory('666/script-block', [
	'book'  => $book,
	'block' => $block,
	'csrf'  => $csrf,
]); ?>
