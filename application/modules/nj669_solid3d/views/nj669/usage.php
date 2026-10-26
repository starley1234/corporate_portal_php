<?php defined('SYSPATH') or die('No direct script access.');
$card = 'rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900';
$in = 'n2j-input mt-1 w-full py-1.5 text-sm min-h-0';
$lab = 'n2j-label text-xs';
$muted = 'text-xs text-gray-500 dark:text-gray-400';
$btn = 'n2j-btn-secondary n2j-btn-sm';
$statuses = ['queued' => 'В очереди', 'running' => 'В работе', 'done' => 'Готово', 'error' => 'Ошибка'];
$badge = ['queued' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200', 'running' => 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200', 'done' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200', 'error' => 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200'];
$is_true = function ($v) { return in_array($v, [TRUE, 't', 'true', 1, '1'], TRUE); };
$t = $totals ?? [];
$sum = function ($in, $out) { return Service_Nj669_Solid3d::tokens_text($in, $out); };
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<?php echo View::factory('nj669/head', ['error' => $error]); ?>
<div class="flex flex-wrap items-end justify-between gap-2">
	<div>
		<h1 class="n2j-h1">Генерации 3D-деталей</h1>
		<p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">Сборки<?php echo $moderator ? ' всех пользователей' : ' вашего аккаунта'; ?>. Токены — вход → выход языковой модели.</p>
	</div>
</div>

<?php if ($t): ?>
<dl class="mt-3 grid gap-2 sm:grid-cols-3">
	<div class="<?php echo $card; ?> px-3 py-2">
		<dt class="<?php echo $muted; ?>">Генерация деталей · <?php echo (int) $t['runs']; ?> зап.</dt>
		<dd class="mt-0.5 font-mono text-sm font-semibold text-gray-900 dark:text-white"><?php echo $sum($t['tokens_in'], $t['tokens_out']); ?></dd>
	</div>
	<div class="<?php echo $card; ?> px-3 py-2">
		<dt class="<?php echo $muted; ?>">Помощник: идеи и карточка · <?php echo (int) $t['assist_runs']; ?> зап.</dt>
		<dd class="mt-0.5 font-mono text-sm font-semibold text-gray-900 dark:text-white"><?php echo $sum($t['assist_tokens_in'], $t['assist_tokens_out']); ?></dd>
	</div>
	<div class="<?php echo $card; ?> px-3 py-2">
		<dt class="<?php echo $muted; ?>">ИИ-проверка ракурсов</dt>
		<dd class="mt-0.5 font-mono text-sm font-semibold text-gray-900 dark:text-white"><?php echo $sum($t['vlm_tokens_in'], $t['vlm_tokens_out']); ?></dd>
	</div>
</dl>
<?php endif; ?>

<form method="get" action="/solid3d/usage" class="mt-3 flex flex-wrap items-end gap-2" autocomplete="off" data-n2j-no-remember>
	<label class="min-w-[200px] flex-1 max-w-md <?php echo $lab; ?>">Деталь и запрос
		<input id="usage-q" name="q" type="search" maxlength="100" autocomplete="off" data-n2j-no-remember value="<?php echo HTML::chars($q); ?>" class="<?php echo $in; ?>">
	</label>
	<label class="<?php echo $lab; ?>">Состояние
		<select id="usage-status" name="status" autocomplete="off" class="n2j-input n2j-select mt-1 w-full py-1.5 text-sm min-h-0 w-44">
			<?php foreach (['' => 'Все', 'queued' => 'В очереди', 'running' => 'В работе', 'done' => 'Готово', 'error' => 'Ошибка'] as $key => $label): ?><option value="<?php echo $key; ?>" <?php if ($key === $status): ?>selected<?php endif; ?>><?php echo $label; ?></option><?php endforeach; ?>
		</select>
	</label>
	<button class="<?php echo $btn; ?>">Найти</button>
</form>

<div class="<?php echo $card; ?> mt-3 overflow-x-auto">
	<table class="n2j-table table-fixed">
		<colgroup>
			<col class="w-[76px]">
			<col>
			<col class="w-[34%]">
			<col class="w-[120px]">
			<col class="w-[120px]">
			<?php if ($moderator): ?><col class="w-[150px]"><?php endif; ?>
			<col class="w-[72px]">
		</colgroup>
		<thead class="border-b border-gray-200 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400">
			<tr><th class="px-3 py-2">Превью</th><th class="px-3 py-2">Деталь и дата</th><th class="px-3 py-2">Запрос</th><th class="px-3 py-2">Состояние</th><th class="px-3 py-2">Токены</th><?php if ($moderator): ?><th class="px-3 py-2">Модели</th><?php endif; ?><th class="px-3 py-2 text-right">Задача</th></tr>
		</thead>
		<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
		<?php foreach ($items as $r): $done = $r['status'] === 'done'; ?>
			<tr class="align-top">
				<td class="px-3 py-2">
					<?php if ($done): ?>
					<a href="/solid3d/p/<?php echo HTML::chars($r['code']); ?>?revision=<?php echo (int) $r['id']; ?>" class="flex h-14 w-14 items-center justify-center rounded-md bg-blue-50/60 p-1 dark:bg-gray-800/70">
						<img class="max-h-full max-w-full object-contain" loading="lazy" src="/solid3d/f/<?php echo HTML::chars($r['code']); ?>/<?php echo (int) $r['id']; ?>/png" alt="Превью: <?php echo HTML::chars($r['title']); ?>">
					</a>
					<?php else: ?>
					<span class="flex h-14 w-14 items-center justify-center rounded-md bg-gray-100 text-gray-400 dark:bg-gray-800" aria-hidden="true"><i class="fas fa-cube"></i></span>
					<?php endif; ?>
				</td>
				<td class="min-w-0 px-3 py-2">
					<a class="block truncate font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/solid3d/p/<?php echo HTML::chars($r['code']); ?>?revision=<?php echo (int) $r['id']; ?>"><?php echo HTML::chars($r['title']); ?></a>
					<p class="n2j-hint mt-0.5">№<?php echo (int) $r['id']; ?> · <?php echo HTML::chars(Service_Nj669_Solid3d::when($r['created_at'])); ?></p>
				</td>
				<td class="min-w-0 px-3 py-2 text-xs text-gray-700 dark:text-gray-300"><p class="line-clamp-3 break-words"><?php echo HTML::chars(mb_substr((string) $r['prompt'], 0, 300)); ?></p></td>
				<td class="px-3 py-2">
					<span class="rounded-md px-1.5 py-0.5 text-xs font-semibold <?php echo $badge[$r['status']] ?? $badge['queued']; ?>"><?php echo HTML::chars($statuses[$r['status']] ?? $r['status']); ?></span>
					<?php if (in_array($r['vlm_status'] ?? 'none', ['queued', 'running'], TRUE)): ?><p class="mt-1 text-xs text-blue-700 dark:text-blue-300">ИИ-проверка идёт</p>
					<?php elseif (($r['vlm_status'] ?? '') === 'done'): ?><p class="mt-1 text-xs <?php echo $is_true($r['visual_ok'] ?? NULL) ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'; ?>">ИИ: <?php echo $is_true($r['visual_ok'] ?? NULL) ? 'соответствует' : 'есть замечания'; ?></p>
					<?php elseif (($r['vlm_status'] ?? '') === 'error'): ?><p class="mt-1 text-xs text-rose-700 dark:text-rose-400">ИИ: ошибка проверки</p><?php endif; ?>
					<?php if ($r['error'] !== ''): ?><p class="mt-1 line-clamp-2 break-words text-xs text-rose-700 dark:text-rose-400"><?php echo HTML::chars($r['error']); ?></p><?php endif; ?>
				</td>
				<td class="px-3 py-2 font-mono text-xs leading-5 text-gray-700 dark:text-gray-300">
					<div><?php echo $sum($r['tokens_in'] ?? 0, $r['tokens_out'] ?? 0); ?></div>
					<?php if ((int) ($r['vlm_tokens_in'] ?? 0) + (int) ($r['vlm_tokens_out'] ?? 0) > 0): ?><div class="<?php echo $muted; ?>">ИИ: <?php echo $sum($r['vlm_tokens_in'], $r['vlm_tokens_out']); ?></div><?php endif; ?>
				</td>
				<?php if ($moderator): ?><td class="min-w-0 px-3 py-2 text-xs text-gray-700 dark:text-gray-300"><p class="truncate"><?php echo HTML::chars($r['model'] ?: 'По умолчанию'); ?></p><p class="truncate <?php echo $muted; ?>"><?php echo HTML::chars($r['vision_model'] ?: 'Общая VLM'); ?></p></td><?php endif; ?>
				<td class="px-3 py-2 text-right text-xs text-gray-700 dark:text-gray-300">
					<div><?php echo (int) $r['job_id']; ?></div>
					<?php if ((int) $r['attempts'] > 0): ?><div class="<?php echo $muted; ?>">попыток <?php echo (int) $r['attempts']; ?></div><?php endif; ?>
					<?php if ($moderator): ?><a class="text-blue-600 hover:underline dark:text-blue-400" href="/nj120/monitor">Монитор</a><?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( ! $items): ?><p class="p-4 text-sm text-gray-500 dark:text-gray-400">Запусков пока нет.</p><?php endif; ?>
</div>
<nav class="mt-3 flex items-center gap-2 text-sm" aria-label="Страницы">
	<?php if ($page > 1): ?><a class="<?php echo $btn; ?>" href="?page=<?php echo (int) $page - 1; ?>&amp;q=<?php echo rawurlencode($q); ?>&amp;status=<?php echo rawurlencode($status); ?>">← Назад</a><?php endif; ?>
	<span class="<?php echo $muted; ?>">Страница <?php echo (int) $page; ?></span>
	<?php if (count($items) === 24): ?><a class="<?php echo $btn; ?>" href="?page=<?php echo (int) $page + 1; ?>&amp;q=<?php echo rawurlencode($q); ?>&amp;status=<?php echo rawurlencode($status); ?>">Дальше →</a><?php endif; ?>
</nav>
</div>
