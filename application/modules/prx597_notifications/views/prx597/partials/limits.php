<?php defined('SYSPATH') or die('No direct script access.'); ?>

	<!-- ================= ЛИМИТЫ ================= -->
	<form autocomplete="off" method="post" action="/prx597/admin">
		<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
		<input type="hidden" name="act" value="limits_save">
		<input type="hidden" name="tab" value="limits">

		<div class="prx-card p-0 mt-5">
			<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
				<h2 class="prx-h3">Лимиты на пользователя и на систему</h2>
				<p class="prx-hint mt-0.5">0 — не ограничивать. Общий лимит защищает всю платформу от спама, пользовательский — конкретного человека.</p>
			</div>
			<div class="overflow-x-auto">
				<table class="prx-table min-w-[560px]">
					<thead>
						<tr class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
							<th class="px-4 py-2 text-left font-semibold">Канал</th>
							<th class="px-3 py-2 text-center font-semibold">Человеку в час</th>
							<th class="px-3 py-2 text-center font-semibold">Человеку в сутки</th>
							<th class="px-3 py-2 text-center font-semibold">Всего в час</th>
							<th class="px-3 py-2 text-center font-semibold">На событие в сутки</th>
						</tr>
					</thead>
					<tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
						<?php foreach (Service_Prx597_Registry::channels(FALSE) as $key => $info): ?>
						<tr>
							<td class="px-4 py-2.5 font-semibold text-gray-800 dark:text-gray-100"><i class="<?php echo HTML::chars($info['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars($info['label']); ?></td>
							<?php foreach (['per_user_hour', 'per_user_day', 'per_hour', 'per_event_day'] as $scope): ?>
							<td class="px-3 py-2.5 text-center">
								<input type="number" min="0" max="100000" name="limit[<?php echo $scope; ?>][<?php echo HTML::chars((string) $key); ?>]"
								       value="<?php echo (int) Arr::path($limits, $scope.'.'.$key, 0); ?>"
								       class="prx-input w-24 py-1 text-center text-sm min-h-0" autocomplete="off">
							</td>
							<?php endforeach; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
				<button type="submit" class="prx-btn-cta"><i class="fas fa-floppy-disk mr-2"></i>Сохранить лимиты</button>
			</div>
		</div>
	</form>

	<form autocomplete="off" method="post" action="/prx597/admin">
		<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
		<input type="hidden" name="act" value="settings_save">
		<input type="hidden" name="tab" value="limits">

		<div class="mt-4 grid gap-4 lg:grid-cols-2">
			<div class="prx-card p-0">
				<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
					<h2 class="prx-h3">Антиспам и доставка</h2>
				</div>
				<div class="grid gap-3 px-4 py-4 sm:grid-cols-2">
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Окно склейки повторов, сек</span>
						<input type="number" name="dedup_window" min="0" max="86400" value="<?php echo (int) $limits_extra['dedup_window']; ?>" class="prx-input mt-1 w-full" autocomplete="off">
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Попыток на сообщение</span>
						<input type="number" name="max_attempts" min="1" max="20" value="<?php echo (int) $limits_extra['max_attempts']; ?>" class="prx-input mt-1 w-full" autocomplete="off">
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Размер пачки за проход</span>
						<input type="number" name="batch_limit" min="1" max="500" value="<?php echo (int) $limits_extra['batch_limit']; ?>" class="prx-input mt-1 w-full" autocomplete="off">
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Драйвер MAX</span>
						<select name="max_driver" class="prx-input prx-select mt-1 w-full">
							<option value="bot" <?php echo $limits_extra['max_driver'] === 'bot' ? 'selected' : ''; ?>>бот (личные сообщения)</option>
							<option value="business" <?php echo $limits_extra['max_driver'] === 'business' ? 'selected' : ''; ?>>бизнес-API (официальный канал)</option>
						</select>
					</label>
				</div>
			</div>

			<div class="prx-card p-0">
				<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
					<h2 class="prx-h3">Сроки хранения, дней</h2>
				</div>
				<div class="grid gap-3 px-4 py-4 sm:grid-cols-3">
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Лента</span>
						<input type="number" name="inbox_days" min="1" max="3650" value="<?php echo (int) $cleanup['inbox']; ?>" class="prx-input mt-1 w-full" autocomplete="off">
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Очередь</span>
						<input type="number" name="outbox_days" min="1" max="3650" value="<?php echo (int) $cleanup['outbox']; ?>" class="prx-input mt-1 w-full" autocomplete="off">
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Журнал</span>
						<input type="number" name="log_days" min="1" max="3650" value="<?php echo (int) $cleanup['log']; ?>" class="prx-input mt-1 w-full" autocomplete="off">
					</label>
				</div>
			</div>
		</div>
		<div class="mt-4">
			<button type="submit" class="prx-btn-cta"><i class="fas fa-floppy-disk mr-2"></i>Сохранить настройки</button>
		</div>
	</form>

	<!-- Шкала важности. Одна на площадку: по ней решают тихие часы и сводка,
	     поэтому администратор видит не только порядок, но и что значит число.
	     Порядок меняется стрелками (кнопки, не drag&drop) — одной формой на
	     всю шкалу: каждая кнопка несёт пару «категория:направление». -->
	<div class="prx-card p-0 mt-4">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prx-h3">Важность событий по категориям</h2>
			<p class="prx-hint mt-0.5">1 — будим сразу, 9 — фон. Чем выше категория в списке, тем важнее её события: важность ≤ <?php echo (int) $priority_skip; ?> не ждёт тихих часов, важность ≥ <?php echo (int) $priority_digest; ?> может уехать в суточную сводку.</p>
		</div>
		<form autocomplete="off" method="post" action="/prx597/admin">
			<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
			<input type="hidden" name="act" value="priority_move">
			<input type="hidden" name="tab" value="limits">
			<div class="divide-y divide-gray-100 dark:divide-gray-700/60">
				<?php $prio_last = count($priority) - 1; foreach ($priority as $i => $prio): ?>
				<div class="flex items-center gap-3 px-4 py-2.5">
					<span class="w-7 text-center text-sm font-bold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $prio['priority']; ?></span>
					<span class="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-gray-100"><?php echo HTML::chars((string) $prio['label']); ?></span>
					<code class="text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars((string) $prio['category']); ?></code>
					<span class="flex items-center gap-1">
						<button type="submit" name="move" value="<?php echo HTML::chars((string) $prio['category']); ?>:-1" <?php echo $i === 0 ? 'disabled' : ''; ?> title="Важнее" class="prx-btn-icon"><i class="fas fa-arrow-up"></i></button>
						<button type="submit" name="move" value="<?php echo HTML::chars((string) $prio['category']); ?>:1" <?php echo $i === $prio_last ? 'disabled' : ''; ?> title="Менее важно" class="prx-btn-icon"><i class="fas fa-arrow-down"></i></button>
					</span>
				</div>
				<?php endforeach; ?>
			</div>
			<div class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
				Числа берутся из конфига модуля и не меняются: стрелки переставляют категории по этой лестнице, поэтому пороги тихих часов и сводки остаются на месте.
			</div>
		</form>
	</div>
