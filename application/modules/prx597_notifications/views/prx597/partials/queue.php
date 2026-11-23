<?php defined('SYSPATH') or die('No direct script access.'); ?>

	<!-- ================= ОЧЕРЕДЬ И ОТПРАВКА ================= -->
	<?php
	$queue = isset($queue) && is_array($queue) ? $queue : [];
	$sent  = isset($sent) && is_array($sent) ? $sent : [];
	$feed  = isset($feed) && is_array($feed) ? $feed : [];
	$feed_events = isset($feed_events) && is_array($feed_events) ? $feed_events : [];

	// Сколько чего лежит в очереди: ждёт отправки и уже не уйдёт. Числа берём
	// из той же сводки, что и карточки, — чтобы кнопка не обещала другого.
	$outbox   = (array) Arr::get($stats, 'outbox', []);
	$q_wait   = (int) Arr::get($outbox, 'pending', 0) + (int) Arr::get($outbox, 'sending', 0);
	$q_failed = (int) Arr::get($outbox, 'failed', 0) + (int) Arr::get($outbox, 'dead', 0);

	// Подписи получателей считаем один раз: один и тот же человек встречается
	// и в очереди, и в журнале, и в ленте.
	$ids = [];

	foreach (array_merge($queue, $sent, $feed) as $row)
	{
		$ids[(int) Arr::get($row, 'user_id', 0)] = TRUE;
	}

	$labels = [];

	foreach (array_keys($ids) as $id)
	{
		$labels[$id] = Service_Prx597_Engine::user_label($id);
	}

	$who = function ($id) use ($labels) {
		$id = (int) $id;

		return isset($labels[$id]) ? $labels[$id] : '#'.$id;
	};
	?>

	<!-- что ждёт отправки и почему -->
	<section id="queue" class="prx-card p-0 mt-5 scroll-mt-4">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<div>
				<h2 class="prx-h3">Очередь доставки</h2>
				<p class="prx-hint mt-0.5">Кто ждёт отправки, в каком канале и почему. Причину пишет движок, когда откладывает сообщение: тихие часы, лимит, недоступный канал или повтор после ошибки.</p>
			</div>
			<span class="prx-count"><?php echo count($queue); ?> в работе</span>
		</div>
		<div class="overflow-x-auto">
			<table class="prx-table min-w-[880px]">
				<thead>
					<tr class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
						<th class="px-4 py-2 text-left font-semibold">Кому</th>
						<th class="px-3 py-2 text-left font-semibold">Событие</th>
						<th class="px-3 py-2 text-left font-semibold">Канал</th>
						<th class="px-3 py-2 text-center font-semibold">Статус</th>
						<th class="px-3 py-2 text-center font-semibold">Попыток</th>
						<th class="px-3 py-2 text-left font-semibold">Следующая попытка</th>
						<th class="px-3 py-2 text-left font-semibold">Почему ждёт</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
					<?php foreach ($queue as $row):
						$row_status = (string) Arr::get($row, 'status', 'pending');
						$b = $badge($row_status);
					?>
					<tr>
						<td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200">
							<?php echo HTML::chars($who(Arr::get($row, 'user_id', 0))); ?>
							<span class="block text-gray-400">#<?php echo (int) Arr::get($row, 'user_id', 0); ?></span>
						</td>
						<td class="px-3 py-2 text-xs">
							<code><?php echo HTML::chars((string) Arr::get($row, 'event_alias', '')); ?></code>
							<?php if (trim((string) Arr::get($row, 'subject', '')) !== ''): ?>
							<span class="block text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) Arr::get($row, 'subject', ''), 0, 80)); ?></span>
							<?php endif; ?>
						</td>
						<td class="px-3 py-2 text-xs"><?php echo HTML::chars(Service_Prx597_Registry::channel_label((string) Arr::get($row, 'channel', ''))); ?></td>
						<td class="px-3 py-2 text-center">
							<span class="prx-badge <?php echo $b[0]; ?>"><span class="prx-dot"></span><?php echo $b[1]; ?></span>
						</td>
						<td class="px-3 py-2 text-center text-xs"><?php echo (int) Arr::get($row, 'attempts', 0); ?></td>
						<td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) Arr::get($row, 'next_attempt_at', '')); ?></td>
						<td class="px-3 py-2 text-xs text-amber-700 dark:text-amber-300"><?php echo HTML::chars((string) Arr::get($row, 'reason', '')); ?></td>
					</tr>
					<?php endforeach; ?>
					<?php if ( ! $queue): ?>
					<tr><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">Очередь пуста — всё, что поставлено, уже доставлено или пропущено.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
			<form method="post" action="/prx597/admin" autocomplete="off">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="run">
				<input type="hidden" name="tab" value="queue">
				<button type="submit" class="prx-btn-process prx-btn-sm"><i class="fas fa-play mr-1.5"></i>Доставить сейчас</button>
			</form>
			<a href="/prx597/admin?tab=journal" class="prx-btn-secondary prx-btn-sm">Полный журнал и DLQ</a>
		</div>

		<!-- уборка неотправленного -->
		<div class="border-t border-gray-100 bg-gray-50/60 px-4 py-3 dark:border-gray-700/60 dark:bg-gray-900/20">
			<h3 class="text-sm font-bold text-gray-900 dark:text-white">Убрать из очереди неотправленное</h3>
			<p class="prx-hint mt-0.5">
				Убирает задания, которые ещё не ушли: ждущие отправки (<?php echo $q_wait; ?>) и те, что уже не уйдут — исчерпали попытки или ждут привязки (<?php echo $q_failed; ?>).
				Записи в ленте пользователей остаются, история отправленного не трогается. Ждут отправки: <?php echo $q_wait; ?>
			</p>
			<div class="mt-2 flex flex-wrap items-center gap-2">
				<form autocomplete="off" method="post" action="/prx597/admin">
					<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
					<input type="hidden" name="act" value="purge_queue">
					<input type="hidden" name="tab" value="queue">
					<input type="hidden" name="scope" value="waiting">
					<button data-prx-arm="Отменить ожидающие?" type="submit" <?php echo $q_wait > 0 ? '' : 'disabled'; ?>
					        class="prx-btn-danger-soft prx-btn-sm">
						<i class="fas fa-ban mr-1.5"></i>Отменить ожидающие (<?php echo $q_wait; ?>)
					</button>
				</form>
				<form autocomplete="off" method="post" action="/prx597/admin">
					<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
					<input type="hidden" name="act" value="purge_queue">
					<input type="hidden" name="tab" value="queue">
					<input type="hidden" name="scope" value="failed">
					<button data-prx-arm="Убрать недоставленные?" type="submit" <?php echo $q_failed > 0 ? '' : 'disabled'; ?>
					        class="prx-btn-danger prx-btn-sm">
						<i class="fas fa-trash-can mr-1.5"></i>Убрать недоставленные (<?php echo $q_failed; ?>)
					</button>
				</form>
			</div>
		</div>
	</section>

	<!-- что ушло по каналам за сутки -->
	<section id="sent" class="prx-card p-0 mt-4 scroll-mt-4">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<div>
				<h2 class="prx-h3">Отправлено за сутки (каналы)</h2>
				<p class="prx-hint mt-0.5">Что реально ушло через доставку: письма, Telegram, MAX, внутренние уведомления из очереди. Цифра «Отправлено за сутки» в сводке — это число строк этой таблицы.</p>
			</div>
			<span class="prx-count"><?php echo count($sent); ?> за 24 часа</span>
		</div>
		<div class="overflow-x-auto">
			<table class="prx-table min-w-[820px]">
				<thead>
					<tr class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
						<th class="px-4 py-2 text-left font-semibold">Когда</th>
						<th class="px-3 py-2 text-left font-semibold">Кому</th>
						<th class="px-3 py-2 text-left font-semibold">Событие</th>
						<th class="px-3 py-2 text-left font-semibold">Канал</th>
						<th class="px-3 py-2 text-left font-semibold">Ответ провайдера</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
					<?php foreach ($sent as $row): ?>
					<tr>
						<td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) Arr::get($row, 'created_at', '')); ?></td>
						<td class="px-3 py-2 text-xs text-gray-700 dark:text-gray-200"><?php echo HTML::chars($who(Arr::get($row, 'user_id', 0))); ?></td>
						<td class="px-3 py-2 text-xs"><code><?php echo HTML::chars((string) Arr::get($row, 'event_alias', '')); ?></code>
							<?php if (trim((string) Arr::get($row, 'subject', '')) !== ''): ?>
							<span class="block text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) Arr::get($row, 'subject', ''), 0, 80)); ?></span>
							<?php endif; ?>
						</td>
						<td class="px-3 py-2 text-xs"><?php echo HTML::chars(Service_Prx597_Registry::channel_label((string) Arr::get($row, 'channel', ''))); ?></td>
						<td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
							<?php if (trim((string) Arr::get($row, 'response_code', '')) !== ''): ?><code class="mr-1"><?php echo HTML::chars((string) Arr::get($row, 'response_code', '')); ?></code><?php endif; ?>
							<?php echo HTML::chars(mb_substr((string) Arr::get($row, 'message', ''), 0, 120)); ?>
						</td>
					</tr>
					<?php endforeach; ?>
					<?php if ( ! $sent): ?>
					<tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">За сутки по каналам ничего не уходило.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</section>

	<!-- что легло в ленту за сутки -->
	<section id="feed" class="prx-card p-0 mt-4 scroll-mt-4">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<div>
				<h2 class="prx-h3">В ленту за сутки (колокольчик)</h2>
				<p class="prx-hint mt-0.5">Что увидели пользователи: события модулей, «Объявление всем» и «Сообщение от системы». Такие записи пишутся прямо в ленту, минуя очередь доставки, — поэтому в журнале каналов их нет, а здесь видно, кому и что ушло.</p>
			</div>
			<span class="prx-count"><?php echo count($feed); ?> за 24 часа</span>
		</div>
		<div class="overflow-x-auto">
			<table class="prx-table min-w-[820px]">
				<thead>
					<tr class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
						<th class="px-4 py-2 text-left font-semibold">Когда</th>
						<th class="px-3 py-2 text-left font-semibold">Кому</th>
						<th class="px-3 py-2 text-left font-semibold">Событие</th>
						<th class="px-3 py-2 text-left font-semibold">Заголовок и текст</th>
						<th class="px-3 py-2 text-center font-semibold">Прочитано</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
					<?php foreach ($feed as $row): ?>
					<tr>
						<td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) Arr::get($row, 'created_at', '')); ?></td>
						<td class="px-3 py-2 text-xs text-gray-700 dark:text-gray-200"><?php echo HTML::chars($who(Arr::get($row, 'user_id', 0))); ?></td>
						<td class="px-3 py-2 text-xs"><code><?php echo HTML::chars((string) Arr::get($row, 'kind', '')); ?></code></td>
						<td class="px-3 py-2 text-xs text-gray-700 dark:text-gray-200">
							<?php echo HTML::chars(mb_substr((string) Arr::get($row, 'title', ''), 0, 120)); ?>
							<?php if (trim((string) Arr::get($row, 'body', '')) !== ''): ?>
							<span class="block text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr(trim(preg_replace('~\s+~u', ' ', (string) Arr::get($row, 'body', ''))), 0, 120)); ?></span>
							<?php endif; ?>
						</td>
						<td class="px-3 py-2 text-center text-xs">
							<?php echo trim((string) Arr::get($row, 'read_at', '')) === '' ? '<span class="text-gray-400 dark:text-gray-500">нет</span>' : '<i class="fas fa-check text-emerald-600 dark:text-emerald-400"></i>'; ?>
						</td>
					</tr>
					<?php endforeach; ?>
					<?php if ( ! $feed): ?>
					<tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">За сутки в ленту ничего не попало.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<!-- чем наполнена лента и уборка мусора -->
		<div class="border-t border-gray-100 bg-gray-50/60 px-4 py-3 dark:border-gray-700/60 dark:bg-gray-900/20">
			<h3 class="text-sm font-bold text-gray-900 dark:text-white">Чем наполнена лента за 7 дней</h3>
			<?php if ( ! empty($source_alert)): ?>
			<div class="prx-flash prx-flash-warn mt-2 text-xs block">
				<i class="fas fa-triangle-exclamation mr-1"></i>
				Предохранитель: событие <span class="font-mono"><?php echo HTML::chars((string) Arr::get($source_alert, 'alias', '')); ?></span>
				шло <b><?php echo (int) Arr::get($source_alert, 'count', 0); ?></b> раз за час при лимите <?php echo (int) Arr::get($source_alert, 'limit', 0); ?> в канале
				<?php echo HTML::chars(Service_Prx597_Base::channel_label((string) Arr::get($source_alert, 'channel', ''))); ?> —
				остальное отложено до конца часа. Если это не нужно людям, выключите событие во вкладке
				<a href="/prx597/admin?tab=events" class="font-semibold underline">«События»</a>.
			</div>
			<?php endif; ?>
			<p class="prx-hint mt-0.5">
				Если событие шлёт мусор, его записи можно убрать из лент всех получателей сразу. Чтобы оно не приходило снова — выключите его во вкладке
				<a href="/prx597/admin?tab=events" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">«События»</a>.
			</p>
			<?php if ($feed_events): ?>
			<div class="mt-2 flex flex-wrap gap-2">
				<?php foreach ($feed_events as $fe):
					$fe_alias = (string) Arr::get($fe, 'event_alias', '');
					$fe_count = (int) Arr::get($fe, 'c', 0);
					$fe_hour  = (int) Arr::get($fe, 'c_hour', 0);
					$fe_hot   = $fe_hour >= max(1, (int) $feed_cap);
				?>
				<form autocomplete="off" method="post" action="/prx597/admin">
					<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
					<input type="hidden" name="act" value="purge_feed">
					<input type="hidden" name="tab" value="queue">
					<input type="hidden" name="alias" value="<?php echo HTML::chars($fe_alias); ?>">
					<button data-prx-arm="Убрать из ленты?" type="submit" class="prx-btn-secondary prx-btn-sm">
						<i class="fas fa-eraser"></i>
						<span class="font-mono"><?php echo HTML::chars($fe_alias); ?></span>
						<span class="prx-count"><?php echo $fe_count; ?></span>
						<?php if ($fe_hour > 0): ?>
						<span class="<?php echo $fe_hot ? 'prx-badge prx-badge-err' : 'prx-count'; ?>"><?php echo $fe_hot ? '<span class="prx-dot"></span>' : ''; ?>за час: <?php echo $fe_hour; ?></span>
						<?php endif; ?>
					</button>
				</form>
				<?php endforeach; ?>
			</div>
			<?php else: ?>
			<p class="prx-hint mt-2">За неделю лента пуста.</p>
			<?php endif; ?>
		</div>
	</section>
