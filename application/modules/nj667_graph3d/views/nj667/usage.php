<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Перечень генераций nj667: превью графа, промпт, токены.
 *
 * Глобальный /nj120/usage — агрегаты по всем каналам. Здесь — карточки
 * именно конструктора, чтобы видеть, на что ушли токены (владелец, 11.10.2026).
 */

$rows   = isset($rows) ? (array) $rows : [];
$spend  = isset($spend) ? (array) $spend : [];
$query  = isset($query) ? (string) $query : '';
$page   = isset($page) ? max(1, (int) $page) : 1;
$pages  = isset($pages) ? max(1, (int) $pages) : 1;
$total  = isset($total) ? (int) $total : 0;
$history = isset($history) ? (array) $history : [];
$days    = isset($days) ? (int) $days : 30;
$day     = isset($day) ? (string) $day : '';
$n      = function ($v) { return number_format((float) $v, 0, ',', ' '); };

$channel_title = [
	'nj667.generate' => 'сборка',
	'nj667.inpaint'  => 'достройка',
	'nj667.summary'  => 'саммари',
	'nj667.embed'    => 'вектор',
	'nj667.moderate' => 'пре-модерация',
	'nj667.ideas'    => 'идеи',
	'nj667.caption'  => 'карточка',
];

$qs = function ($extra = []) use ($query, $page, $day, $days) {
	$p = array_merge(['q' => $query, 'page' => $page, 'day' => $day, 'days' => $days], $extra);
	if ((string) Arr::get($p, 'q', '') === '') { unset($p['q']); }
	if ((int) Arr::get($p, 'page', 1) <= 1) { unset($p['page']); }
	if ((string) Arr::get($p, 'day', '') === '') { unset($p['day']); }
	if ((int) Arr::get($p, 'days', 30) === 30) { unset($p['days']); }
	return $p ? ('?'.http_build_query($p)) : '';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<h1 class="n2j-h1"><i class="fas fa-coins mr-2 text-blue-500"></i>Генерации и токены</h1>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Каждая задача воркера: превью графа, запрос, сколько токенов съела модель. Сводка по людям — ниже. Полные агрегаты по платформе — в <a href="/nj120/usage" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">/nj120/usage</a>.</p>

	<form method="get" action="/graph3d/usage" class="mt-5 flex max-w-xl flex-wrap gap-2">
		<input type="search" name="q" value="<?php echo HTML::chars($query); ?>" maxlength="200"
			placeholder="Поиск: промпт, проект, канал…"
			class="n2j-input min-w-[10rem] flex-1">
		<?php if ($day !== ''): ?><input type="hidden" name="day" value="<?php echo HTML::chars($day); ?>"><?php endif; ?>
		<?php if ($days !== 30): ?><input type="hidden" name="days" value="<?php echo (int) $days; ?>"><?php endif; ?>
		<button type="submit" class="n2j-btn-process shrink-0">Найти</button>
	</form>
	<p class="n2j-hint mt-2 flex flex-wrap items-center gap-2">
		<span><?php echo $n($total); ?> задач<?php echo $day !== '' ? ' за '.HTML::chars($day) : ''; ?></span>
		<?php if ($day !== ''): ?>
		<a href="/graph3d/usage<?php echo HTML::chars($qs(['day' => '', 'page' => 1])); ?>" class="n2j-link">показать все дни</a>
		<?php endif; ?>
	</p>

	<?php // история по дням: учёт nj_120_usage живёт дольше задач (nj120/005), поэтому тут видны и давние генерации ?>
	<section class="mt-5">
		<div class="flex flex-wrap items-baseline justify-between gap-2">
			<h2 class="n2j-h2">История по дням</h2>
			<span class="flex flex-wrap gap-1 text-xs">
				<?php foreach ([7, 30, 90, 366] as $d): ?>
				<a href="/graph3d/usage<?php echo HTML::chars($qs(['days' => $d, 'page' => 1])); ?>"
					class="n2j-chip<?php echo $d === $days ? ' is-on' : ''; ?>"<?php echo $d === $days ? ' aria-current="page"' : ''; ?>><?php echo $d === 366 ? 'год' : $d.' дн.'; ?></a>
				<?php endforeach; ?>
			</span>
		</div>
		<?php if ( ! $history): ?>
		<p class="n2j-hint mt-2">За выбранный период генераций нет.</p>
		<?php else: ?>
		<?php
			$max_tokens = 1;
			foreach ($history as $h)
			{
				$max_tokens = max($max_tokens, (int) Arr::get($h, 'prompt_tokens') + (int) Arr::get($h, 'completion_tokens'));
			}
			$sum = ['jobs' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'errors' => 0];
			foreach ($history as $h)
			{
				foreach ($sum as $k => $v) { $sum[$k] += (int) Arr::get($h, $k); }
			}
		?>
		<div class="n2j-card p-0 mt-2 max-h-96 overflow-auto">
			<table class="n2j-table">
				<thead>
					<tr class="text-left text-gray-400">
						<th class="px-4 py-2 font-semibold">День</th>
						<th class="px-4 py-2 font-semibold">Задач</th>
						<th class="hidden px-4 py-2 font-semibold sm:table-cell">Людей</th>
						<th class="hidden px-4 py-2 font-semibold sm:table-cell">Промпт</th>
						<th class="hidden px-4 py-2 font-semibold sm:table-cell">Ответ</th>
						<th class="px-4 py-2 text-right font-semibold sm:text-left">Всего</th>
						<th class="hidden px-4 py-2 font-semibold md:table-cell">Ошибок</th>
						<th class="hidden px-4 py-2 font-semibold md:table-cell">Доля</th>
					</tr>
				</thead>
				<tbody class="text-gray-700 dark:text-gray-300">
					<?php foreach ($history as $h): ?>
					<?php $t = (int) Arr::get($h, 'prompt_tokens') + (int) Arr::get($h, 'completion_tokens'); ?>
					<tr class="border-t border-gray-100 dark:border-gray-800<?php echo Arr::get($h, 'day') === $day ? ' bg-blue-50 dark:bg-blue-900/20' : ''; ?>">
						<td class="px-4 py-2 font-semibold">
							<a href="/graph3d/usage<?php echo HTML::chars($qs(['day' => (string) Arr::get($h, 'day'), 'page' => 1])); ?>" class="n2j-link"><?php echo HTML::chars((string) Arr::get($h, 'day')); ?></a>
							<span class="mt-0.5 block font-normal text-gray-400 sm:hidden">промпт <?php echo $n(Arr::get($h, 'prompt_tokens')); ?> · ответ <?php echo $n(Arr::get($h, 'completion_tokens')); ?><?php echo (int) Arr::get($h, 'errors') > 0 ? ' · ошибок '.$n(Arr::get($h, 'errors')) : ''; ?></span>
						</td>
						<td class="px-4 py-2"><?php echo $n(Arr::get($h, 'jobs')); ?></td>
						<td class="hidden px-4 py-2 sm:table-cell"><?php echo $n(Arr::get($h, 'people')); ?></td>
						<td class="hidden px-4 py-2 sm:table-cell"><?php echo $n(Arr::get($h, 'prompt_tokens')); ?></td>
						<td class="hidden px-4 py-2 sm:table-cell"><?php echo $n(Arr::get($h, 'completion_tokens')); ?></td>
						<td class="px-4 py-2 text-right font-semibold sm:text-left"><?php echo $n($t); ?></td>
						<td class="hidden px-4 py-2 md:table-cell<?php echo (int) Arr::get($h, 'errors') > 0 ? ' text-rose-600 dark:text-rose-400' : ''; ?>"><?php echo $n(Arr::get($h, 'errors')); ?></td>
						<td class="hidden px-4 py-2 text-gray-400 md:table-cell" title="доля от самого крупного дня"><?php echo (int) round(100 * $t / $max_tokens); ?>%</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="border-t border-gray-200 font-semibold text-gray-800 dark:border-gray-700 dark:text-gray-200">
						<td class="px-4 py-2">Итого</td>
						<td class="px-4 py-2"><?php echo $n($sum['jobs']); ?></td>
						<td class="hidden px-4 py-2 sm:table-cell"></td>
						<td class="hidden px-4 py-2 sm:table-cell"><?php echo $n($sum['prompt_tokens']); ?></td>
						<td class="hidden px-4 py-2 sm:table-cell"><?php echo $n($sum['completion_tokens']); ?></td>
						<td class="px-4 py-2 text-right sm:text-left"><?php echo $n($sum['prompt_tokens'] + $sum['completion_tokens']); ?></td>
						<td class="hidden px-4 py-2 md:table-cell"><?php echo $n($sum['errors']); ?></td>
						<td class="hidden px-4 py-2 md:table-cell"></td>
					</tr>
				</tfoot>
			</table>
		</div>
		<?php endif; ?>
	</section>

	<?php if ($spend): ?>
	<div class="n2j-card p-0 mt-5 overflow-x-auto">
		<table class="n2j-table">
			<thead>
				<tr class="text-left text-gray-400">
					<th class="px-4 py-2 font-semibold">Кто</th>
					<th class="px-4 py-2 font-semibold">Задач</th>
					<th class="hidden px-4 py-2 font-semibold sm:table-cell">Промпт</th>
					<th class="hidden px-4 py-2 font-semibold sm:table-cell">Ответ</th>
					<th class="px-4 py-2 text-right font-semibold sm:text-left">Всего</th>
				</tr>
			</thead>
			<tbody class="text-gray-700 dark:text-gray-300">
				<?php foreach ($spend as $row): ?>
				<tr class="border-t border-gray-100 dark:border-gray-800">
					<td class="px-4 py-2 font-semibold">
						<?php echo HTML::chars((string) Arr::get($row, 'who')); ?>
						<?php // на телефоне второстепенные колонки живут внутри первой строки — иначе таблице тесно ?>
						<span class="mt-0.5 block font-normal text-gray-400 sm:hidden">
							промпт <?php echo $n(Arr::get($row, 'prompt_tokens')); ?> · ответ <?php echo $n(Arr::get($row, 'completion_tokens')); ?>
						</span>
					</td>
					<td class="px-4 py-2"><?php echo $n(Arr::get($row, 'jobs')); ?></td>
					<td class="hidden px-4 py-2 sm:table-cell"><?php echo $n(Arr::get($row, 'prompt_tokens')); ?></td>
					<td class="hidden px-4 py-2 sm:table-cell"><?php echo $n(Arr::get($row, 'completion_tokens')); ?></td>
					<td class="px-4 py-2 text-right font-semibold sm:text-left"><?php echo $n((int) Arr::get($row, 'prompt_tokens') + (int) Arr::get($row, 'completion_tokens')); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

	<div class="mt-6 grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
		<?php if ( ! $rows): ?>
		<p class="text-sm text-gray-500 dark:text-gray-400 lg:col-span-2 xl:col-span-3"><?php echo $query !== '' ? 'Ничего не нашлось.' : 'Задач nj667 пока нет — появятся после первой сборки.'; ?></p>
		<?php endif; ?>

		<?php foreach ($rows as $row): ?>
		<?php
			$payload = Arr::get($row, 'payload');
			if (is_string($payload))
			{
				$decoded = json_decode($payload, TRUE);
				$payload = is_array($decoded) ? $decoded : [];
			}
			elseif ( ! is_array($payload))
			{
				$payload = [];
			}
			$prompt  = trim((string) Arr::get($payload, 'prompt', ''));
			$code    = (string) Arr::get($row, 'code', '');
			$channel = (string) Arr::get($row, 'channel');
			$title   = Arr::get($channel_title, $channel, $channel);
			$tokens  = (int) Arr::get($row, 'prompt_tokens') + (int) Arr::get($row, 'completion_tokens');
		?>
		<article class="n2j-card flex min-w-0 flex-col p-3">
			<div class="flex min-w-0 gap-3">
				<?php if ($code !== ''): ?>
				<a href="/graph3d/p/<?php echo HTML::chars($code); ?>" class="block min-w-0 shrink-0">
					<?php echo Service_Nj667_Graph3d::preview_img(['code' => $code], '', 'h-14 w-20 rounded-md border border-gray-200 object-contain dark:border-gray-700'); ?>
				</a>
				<?php else: ?>
				<div class="flex h-14 w-20 shrink-0 items-center justify-center rounded-md border border-dashed border-gray-200 text-center text-xs leading-tight text-gray-400 dark:border-gray-700">нет проекта</div>
				<?php endif; ?>
				<div class="min-w-0 flex-1">
					<div class="flex flex-wrap items-center gap-1.5">
						<span class="n2j-badge n2j-badge-info"><?php echo HTML::chars($title); ?></span>
						<span class="text-xs text-gray-400"><?php echo HTML::chars((string) Arr::get($row, 'status')); ?></span>
						<?php if (in_array(Arr::get($row, 'job_gone'), [TRUE, 't', 1, '1'], TRUE)): ?>
						<span class="text-xs text-gray-400" title="Задача убрана из монитора, учёт сохранён">· из архива</span>
						<?php endif; ?>
					</div>
					<p class="mt-1 line-clamp-2 text-sm font-semibold text-gray-900 dark:text-white sm:line-clamp-1">
						<?php echo HTML::chars((string) Arr::get($row, 'title') ?: ($code !== '' ? $code : 'задача #'.(int) Arr::get($row, 'id'))); ?>
					</p>
					<p class="n2j-hint mt-0.5"><?php echo HTML::chars((string) Arr::get($row, 'created_at')); ?></p>
				</div>
			</div>
			<?php if ($prompt !== ''): ?>
			<p class="mt-2 line-clamp-3 break-words text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($prompt); ?></p>
			<?php endif; ?>
			<p class="n2j-hint mt-2 break-words">
				токены: <b class="text-gray-800 dark:text-gray-200"><?php echo $n($tokens); ?></b>
				(<?php echo $n(Arr::get($row, 'prompt_tokens')); ?> + <?php echo $n(Arr::get($row, 'completion_tokens')); ?>)
				· LLM <?php echo $n(Arr::get($row, 'llm_calls')); ?>
				<?php if ((int) Arr::get($row, 'elapsed_ms') > 0): ?>
				· <?php echo $n((int) Arr::get($row, 'elapsed_ms') / 1000); ?> с
				<?php endif; ?>
			</p>
		</article>
		<?php endforeach; ?>
	</div>

	<?php if ($pages > 1): ?>
	<nav class="mt-8 flex flex-wrap items-center justify-center gap-2 text-sm" aria-label="Страницы">
		<?php if ($page > 1): ?>
		<a href="/graph3d/usage<?php echo HTML::chars($qs(['page' => $page - 1])); ?>" class="n2j-page" aria-label="Предыдущая страница">←</a>
		<?php endif; ?>
		<?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
		<a href="/graph3d/usage<?php echo HTML::chars($qs(['page' => $i])); ?>"
			class="n2j-page"<?php echo $i === $page ? ' aria-current="page"' : ''; ?>><?php echo (int) $i; ?></a>
		<?php endfor; ?>
		<?php if ($page < $pages): ?>
		<a href="/graph3d/usage<?php echo HTML::chars($qs(['page' => $page + 1])); ?>" class="n2j-page" aria-label="Следующая страница">→</a>
		<?php endif; ?>
	</nav>
	<?php endif; ?>
</div>
