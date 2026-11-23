<?php defined('SYSPATH') or die('No direct script access.'); ?>

	<!-- ================= ЖУРНАЛ ================= -->
	<?php if ($dead): ?>
	<div class="mt-5 rounded-xl border border-rose-200 bg-rose-50/60 shadow-xs dark:border-rose-900 dark:bg-rose-950/20">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-rose-200 px-4 py-3.5 dark:border-rose-900">
			<h2 class="prx-h3 text-rose-800 dark:text-rose-300"><i class="fas fa-skull mr-1.5"></i>DLQ — не дошло ни с какой попытки</h2>
			<form autocomplete="off" method="post" action="/prx597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="retry_dead">
				<input type="hidden" name="tab" value="journal">
				<button type="submit" class="prx-btn-process prx-btn-sm">Вернуть в очередь</button>
			</form>
		</div>
		<div class="overflow-x-auto">
			<table class="prx-table min-w-[720px]">
				<thead>
					<tr class="bg-rose-100/60 text-xs uppercase text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">
						<th class="px-4 py-2 text-left font-semibold">Когда</th>
						<th class="px-3 py-2 text-left font-semibold">Событие</th>
						<th class="px-3 py-2 text-left font-semibold">Кому</th>
						<th class="px-3 py-2 text-left font-semibold">Канал</th>
						<th class="px-3 py-2 text-center font-semibold">Попыток</th>
						<th class="px-3 py-2 text-left font-semibold">Последняя ошибка</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-rose-100 dark:divide-rose-900/50">
					<?php foreach ($dead as $row): ?>
					<tr>
						<td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) Arr::get($row, 'created_at', '')); ?></td>
						<td class="px-3 py-2 text-xs"><code><?php echo HTML::chars((string) Arr::get($row, 'event_alias', '')); ?></code></td>
						<td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($row, 'destination', '')); ?></td>
						<td class="px-3 py-2 text-xs"><?php echo HTML::chars(Service_Prx597_Registry::channel_label((string) Arr::get($row, 'channel', ''))); ?></td>
						<td class="px-3 py-2 text-center text-xs"><?php echo (int) Arr::get($row, 'attempts', 0); ?></td>
						<td class="px-3 py-2 text-xs text-rose-700 dark:text-rose-300"><?php echo HTML::chars((string) Arr::get($row, 'last_error', '')); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php endif; ?>
	<div class="prx-card p-0 mt-5">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prx-h3">Журнал доставки</h2>
			<p class="prx-hint mt-0.5">Последние попытки: что, кому, через что и с каким результатом.</p>
		</div>
		<div class="overflow-x-auto">
			<table class="prx-table min-w-[860px]">
				<thead>
					<tr class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
						<th class="px-4 py-2 text-left font-semibold">Когда</th>
						<th class="px-3 py-2 text-left font-semibold">Событие</th>
						<th class="px-3 py-2 text-center font-semibold">Кому</th>
						<th class="px-3 py-2 text-left font-semibold">Канал</th>
						<th class="px-3 py-2 text-center font-semibold">Попытка</th>
						<th class="px-3 py-2 text-center font-semibold">Статус</th>
						<th class="px-3 py-2 text-left font-semibold">Ответ провайдера</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
					<?php foreach ($journal as $row):
						$ok_b   = Service_Prx597_Base::_truthy(Arr::get($row, 'ok', FALSE));
						$reason = (string) Arr::get($row, 'response_code', '');
						$b      = $badge($ok_b ? 'sent' : ($reason === 'skipped' ? 'skipped' : 'failed'));
					?>
					<tr>
						<td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) Arr::get($row, 'created_at', '')); ?></td>
						<td class="px-3 py-2 text-xs"><code><?php echo HTML::chars((string) Arr::get($row, 'event_alias', '')); ?></code></td>
						<td class="px-3 py-2 text-center text-xs text-gray-600 dark:text-gray-300"><?php echo (int) Arr::get($row, 'user_id', 0); ?></td>
						<td class="px-3 py-2 text-xs"><?php echo HTML::chars(Service_Prx597_Registry::channel_label((string) Arr::get($row, 'channel', ''))); ?></td>
						<td class="px-3 py-2 text-center text-xs"><?php echo (int) Arr::get($row, 'attempt', 0); ?></td>
						<td class="px-3 py-2 text-center">
							<span class="prx-badge <?php echo $b[0]; ?>"><span class="prx-dot"></span><?php echo $b[1]; ?></span>
						</td>
						<td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
							<?php if ($reason !== ''): ?><code class="mr-1"><?php echo HTML::chars($reason); ?></code><?php endif; ?>
							<?php echo HTML::chars(mb_substr((string) Arr::get($row, 'message', ''), 0, 160)); ?>
						</td>
					</tr>
					<?php endforeach; ?>
					<?php if ( ! $journal): ?>
					<tr><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">Журнал пуст — доставок ещё не было.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
