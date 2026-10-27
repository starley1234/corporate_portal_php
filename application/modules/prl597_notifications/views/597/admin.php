<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление центром уведомлений (prl597): /prl597/admin.
 *
 * Вкладки: сводка, очередь, сообщение, события, шаблоны, каналы, лимиты, журнал.
 * Опасные кнопки (чистка, DLQ, сброс счётчиков) — вторым нажатием:
 * data-prl-arm на кнопке (общий app.js).
 *
 * Переменные: $tab, $stats, $events, $events_on, $templates, $channels,
 *             $limits, $limits_extra, $journal, $dead, $cleanup, $flash, $csrf,
 *             $queue (очередь с причинами), $sent (отправлено за сутки),
 *             $feed (лента за сутки), $ai_help (подсказка ИИ для шаблонов).
 */

$csrf_h = HTML::chars((string) $csrf);
$tabs = [
	'summary'   => ['fas fa-gauge-high', 'Сводка'],
	'queue'     => ['fas fa-list-check', 'Очередь и отправка'],
	'message'   => ['fas fa-envelope', 'Сообщение пользователю'],
	'events'    => ['fas fa-bolt', 'События'],
	'templates' => ['fas fa-file-lines', 'Шаблоны'],
	'channels'  => ['fas fa-tower-broadcast', 'Каналы'],
	'limits'    => ['fas fa-shield-halved', 'Лимиты'],
	'journal'   => ['fas fa-clipboard-list', 'Журнал'],
];

/** Плашка состояния: было ли отправлено, отложено или упало. */
$badge = function ($status) {
	$map = [
		'sent'    => ['prl-badge-ok', 'отправлено'],
		'pending' => ['prl-badge-wait', 'в очереди'],
		'sending' => ['prl-badge-run', 'отправляется'],
		'deferred'=> ['prl-badge-run', 'отложено'],
		'skipped' => ['prl-badge-wait', 'пропущено'],
		'failed'  => ['prl-badge-err', 'ошибка'],
		'dead'    => ['prl-badge-err', 'в DLQ'],
	];

	return $map[$status] ?? $map['skipped'];
};
?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
		<h1 class="prl-h1"><i class="fas fa-bell-concierge mr-2 text-blue-500"></i>Управление</h1>
		<div class="flex flex-wrap gap-2">
			<form autocomplete="off" method="post" action="/prl597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="run">
				<input type="hidden" name="tab" value="<?php echo HTML::chars($tab); ?>">
				<button type="submit" class="prl-btn-process prl-btn-sm"><i class="fas fa-play mr-1.5"></i>Доставить сейчас</button>
			</form>
			<a href="/prl597" class="prl-btn-secondary prl-btn-sm"><i class="fas fa-bell mr-1.5"></i>Мои уведомления</a>
		</div>
	</div>

	<?php if ((string) $flash !== ''): ?>
	<div class="prl-flash prl-flash-info mt-3" role="status">
		<i class="fas fa-circle-info shrink-0"></i><span><?php echo HTML::chars($flash); ?></span>
	</div>
	<?php endif; ?>

	<div class="mt-4 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-700">
		<?php foreach ($tabs as $key => $info): ?>
		<a href="/prl597/admin?tab=<?php echo $key; ?>" class="inline-flex min-h-[40px] items-center gap-2 whitespace-nowrap border-b-2 px-3 text-sm font-semibold transition <?php echo $tab === $key ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400'; ?>">
			<i class="<?php echo $info[0]; ?>"></i><?php echo $info[1]; ?>
			<?php if ($key === 'journal' AND ! empty($stats['failed'])): ?>
			<span class="prl-badge prl-badge-err"><span class="prl-dot"></span><?php echo (int) $stats['failed']; ?></span>
			<?php endif; ?>
		</a>
		<?php endforeach; ?>
	</div>

	<?php if ($tab === 'summary'): ?>
	<!-- ================= СВОДКА ================= -->
	<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
		<?php
		// Карточки — ссылки на разбор: владелец (16.10.2026) спросил «в очереди 10 —
		// как посмотреть, кто и почему?». Теперь каждая цифра ведёт на свой список,
		// а «отправлено за сутки» считает каналы: рассылка в колокольчик идёт мимо
		// очереди и показана отдельной карточкой «в ленту».
		$kpi = [
			['fas fa-hourglass-half text-amber-500', 'В очереди', (int) Arr::get($stats, 'pending', 0), '/prl597/admin?tab=queue#queue', 'кто ждёт отправки и почему'],
			['fas fa-hourglass-end text-amber-500', 'Отложено', (int) Arr::get($stats, 'deferred', 0), '/prl597/admin?tab=queue#queue', 'пауза: тихие часы, лимит или повтор'],
			['fas fa-skull text-rose-500', 'В DLQ', (int) Arr::get($stats, 'dead', 0), '/prl597/admin?tab=journal', 'не дошло ни с одной попытки'],
			['fas fa-circle-check text-emerald-500', 'Отправлено за сутки', (int) Arr::get($stats, 'sent_today', 0), '/prl597/admin?tab=queue#sent', 'каналы: письма и мессенджеры'],
			['fas fa-bell text-blue-500', 'В ленту за сутки', (int) Arr::get($stats, 'inbox_24h', 0), '/prl597/admin?tab=queue#feed', 'колокольчик: рассылки и сообщения'],
		];
		foreach ($kpi as $row): ?>
		<a href="<?php echo $row[3]; ?>" class="prl-card block transition hover:border-blue-300 hover:bg-blue-50/40 dark:hover:border-blue-700 dark:hover:bg-blue-950/20">
			<i class="<?php echo $row[0]; ?> mb-2 text-xl"></i>
			<div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $row[2]; ?></div>
			<div class="text-xs font-semibold text-gray-600 dark:text-gray-300"><?php echo $row[1]; ?></div>
			<div class="mt-0.5 text-xs text-gray-400 dark:text-gray-500"><?php echo $row[4]; ?> <i class="fas fa-arrow-right ml-0.5 text-[10px]"></i></div>
		</a>
		<?php endforeach; ?>
	</div>

	<div class="mt-4 grid gap-4 lg:grid-cols-2">
		<!-- каналы -->
		<div class="prl-card p-0">
			<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
				<h2 class="prl-h3">Каналы</h2>
			</div>
			<ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
				<?php foreach ($channels as $row): ?>
				<li class="flex items-center justify-between gap-3 px-4 py-3">
					<span class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
						<i class="<?php echo HTML::chars($row['icon']); ?> text-blue-500"></i><?php echo HTML::chars($row['label']); ?>
						<code class="text-xs font-normal text-gray-400"><?php echo HTML::chars($row['key']); ?></code>
					</span>
					<span class="text-xs font-semibold <?php echo $row['enabled'] ? 'text-emerald-600' : 'text-gray-400'; ?>">
						<i class="fas <?php echo $row['enabled'] ? 'fa-circle-check' : 'fa-circle-xmark'; ?> mr-1"></i><?php echo $row['enabled'] ? 'настроен' : 'не настроен / выключен'; ?>
					</span>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<!-- привязки и боты -->
		<div class="prl-card p-0">
			<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
				<h2 class="prl-h3">Привязки и боты</h2>
			</div>
			<ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
				<li class="flex items-center justify-between px-4 py-3 text-sm">
					<span class="text-gray-600 dark:text-gray-300">Всего привязок</span>
					<strong class="text-gray-900 dark:text-white"><?php echo (int) Arr::get($stats, 'bindings', 0); ?></strong>
				</li>
				<li class="flex items-center justify-between px-4 py-3 text-sm">
					<span class="text-gray-600 dark:text-gray-300">Из них активных</span>
					<strong class="text-gray-900 dark:text-white"><?php echo (int) Arr::get($stats, 'bindings_active', 0); ?></strong>
				</li>
				<?php foreach (['telegram', 'max'] as $key): if ( ! isset($channels[$key]) OR empty($channels[$key]['bot'])) continue; $bot = $channels[$key]['bot']; ?>
				<li class="px-4 py-3 text-sm">
					<div class="flex items-center justify-between">
						<span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($channels[$key]['label']); ?></span>
						<span class="prl-count">режим: <?php echo HTML::chars($bot['mode']); ?></span>
					</div>
					<code class="mt-1 block break-all text-xs text-gray-400"><?php echo HTML::chars($bot['url']); ?></code>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>

	<!-- объявление -->
	<div class="prl-card p-0 mt-4">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prl-h3">Объявление всем</h2>
			<p class="prl-hint mt-0.5">Только внутренние уведомления — тем, у кого включён колокольчик. Без почты и мессенджеров.</p>
		</div>
		<form autocomplete="off" method="post" action="/prl597/admin" class="grid gap-3 px-4 py-4 sm:grid-cols-2">
			<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
			<input type="hidden" name="act" value="notify_all">
			<input type="hidden" name="tab" value="summary">
			<label class="sm:col-span-2 block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Заголовок</span>
				<input type="text" name="title" required maxlength="180" class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="sm:col-span-2 block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Текст</span>
				<textarea autocomplete="off" name="body" rows="2" maxlength="1000" class="prl-textarea mt-1 w-full"></textarea>
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Ссылка</span>
				<input type="text" name="href" value="/prl597" class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Не больше получателей</span>
				<input type="number" name="limit" value="200" min="1" max="5000" class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<div class="sm:col-span-2">
				<button type="submit" class="prl-btn-cta"><i class="fas fa-bullhorn mr-2"></i>Отправить</button>
			</div>
		</form>
	</div>

	<!-- обслуживание -->
	<div class="prl-card p-0 mt-4">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prl-h3">Обслуживание</h2>
		</div>
		<div class="flex flex-wrap gap-2 px-4 py-4">
			<form autocomplete="off" method="post" action="/prl597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="digest">
				<input type="hidden" name="tab" value="summary">
				<button type="submit" class="prl-btn-secondary prl-btn-sm">Разослать сводки</button>
			</form>
			<form autocomplete="off" method="post" action="/prl597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="resend_failed">
				<input type="hidden" name="tab" value="summary">
				<button type="submit" class="prl-btn-secondary prl-btn-sm">Вернуть упавшие в очередь</button>
			</form>
			<form autocomplete="off" method="post" action="/prl597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="reset_limits">
				<input type="hidden" name="tab" value="summary">
				<button data-prl-arm="Сбросить?" type="submit" class="prl-btn-secondary prl-btn-sm">Сбросить счётчики лимитов</button>
			</form>
			<form autocomplete="off" method="post" action="/prl597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="cleanup">
				<input type="hidden" name="tab" value="summary">
				<button data-prl-arm="Удалить старое?" type="submit" class="prl-btn-danger prl-btn-sm">Очистить старое</button>
			</form>
			<a href="/prl597/admin/preview" target="_blank" class="prl-btn-secondary prl-btn-sm">Предпросмотр письма-сводки</a>
		</div>
	</div>
	<?php endif; ?>

<?php if ($tab === 'queue'): ?>
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
		$labels[$id] = Service_Prl597_Engine::user_label($id);
	}

	$who = function ($id) use ($labels) {
		$id = (int) $id;

		return isset($labels[$id]) ? $labels[$id] : '#'.$id;
	};
	?>

	<!-- что ждёт отправки и почему -->
	<section id="queue" class="prl-card p-0 mt-5 scroll-mt-4">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<div>
				<h2 class="prl-h3">Очередь доставки</h2>
				<p class="prl-hint mt-0.5">Кто ждёт отправки, в каком канале и почему. Причину пишет движок, когда откладывает сообщение: тихие часы, лимит, недоступный канал или повтор после ошибки.</p>
			</div>
			<span class="prl-count"><?php echo count($queue); ?> в работе</span>
		</div>
		<div class="overflow-x-auto">
			<table class="prl-table min-w-[880px]">
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
						<td class="px-3 py-2 text-xs"><?php echo HTML::chars(Service_Prl597_Registry::channel_label((string) Arr::get($row, 'channel', ''))); ?></td>
						<td class="px-3 py-2 text-center">
							<span class="prl-badge <?php echo $b[0]; ?>"><span class="prl-dot"></span><?php echo $b[1]; ?></span>
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
			<form method="post" action="/prl597/admin" autocomplete="off">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="run">
				<input type="hidden" name="tab" value="queue">
				<button type="submit" class="prl-btn-process prl-btn-sm"><i class="fas fa-play mr-1.5"></i>Доставить сейчас</button>
			</form>
			<a href="/prl597/admin?tab=journal" class="prl-btn-secondary prl-btn-sm">Полный журнал и DLQ</a>
		</div>

		<!-- уборка неотправленного -->
		<div class="border-t border-gray-100 bg-gray-50/60 px-4 py-3 dark:border-gray-700/60 dark:bg-gray-900/20">
			<h3 class="text-sm font-bold text-gray-900 dark:text-white">Убрать из очереди неотправленное</h3>
			<p class="prl-hint mt-0.5">
				Убирает задания, которые ещё не ушли: ждущие отправки (<?php echo $q_wait; ?>) и те, что уже не уйдут — исчерпали попытки или ждут привязки (<?php echo $q_failed; ?>).
				Записи в ленте пользователей остаются, история отправленного не трогается. Ждут отправки: <?php echo $q_wait; ?>
			</p>
			<div class="mt-2 flex flex-wrap items-center gap-2">
				<form autocomplete="off" method="post" action="/prl597/admin">
					<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
					<input type="hidden" name="act" value="purge_queue">
					<input type="hidden" name="tab" value="queue">
					<input type="hidden" name="scope" value="waiting">
					<button data-prl-arm="Отменить ожидающие?" type="submit" <?php echo $q_wait > 0 ? '' : 'disabled'; ?>
					        class="prl-btn-danger-soft prl-btn-sm">
						<i class="fas fa-ban mr-1.5"></i>Отменить ожидающие (<?php echo $q_wait; ?>)
					</button>
				</form>
				<form autocomplete="off" method="post" action="/prl597/admin">
					<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
					<input type="hidden" name="act" value="purge_queue">
					<input type="hidden" name="tab" value="queue">
					<input type="hidden" name="scope" value="failed">
					<button data-prl-arm="Убрать недоставленные?" type="submit" <?php echo $q_failed > 0 ? '' : 'disabled'; ?>
					        class="prl-btn-danger prl-btn-sm">
						<i class="fas fa-trash-can mr-1.5"></i>Убрать недоставленные (<?php echo $q_failed; ?>)
					</button>
				</form>
			</div>
		</div>
	</section>

	<!-- что ушло по каналам за сутки -->
	<section id="sent" class="prl-card p-0 mt-4 scroll-mt-4">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<div>
				<h2 class="prl-h3">Отправлено за сутки (каналы)</h2>
				<p class="prl-hint mt-0.5">Что реально ушло через доставку: письма, Telegram, MAX, внутренние уведомления из очереди. Цифра «Отправлено за сутки» в сводке — это число строк этой таблицы.</p>
			</div>
			<span class="prl-count"><?php echo count($sent); ?> за 24 часа</span>
		</div>
		<div class="overflow-x-auto">
			<table class="prl-table min-w-[820px]">
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
						<td class="px-3 py-2 text-xs"><?php echo HTML::chars(Service_Prl597_Registry::channel_label((string) Arr::get($row, 'channel', ''))); ?></td>
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
	<section id="feed" class="prl-card p-0 mt-4 scroll-mt-4">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<div>
				<h2 class="prl-h3">В ленту за сутки (колокольчик)</h2>
				<p class="prl-hint mt-0.5">Что увидели пользователи: события модулей, «Объявление всем» и «Сообщение от системы». Такие записи пишутся прямо в ленту, минуя очередь доставки, — поэтому в журнале каналов их нет, а здесь видно, кому и что ушло.</p>
			</div>
			<span class="prl-count"><?php echo count($feed); ?> за 24 часа</span>
		</div>
		<div class="overflow-x-auto">
			<table class="prl-table min-w-[820px]">
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
			<div class="prl-flash prl-flash-warn mt-2 text-xs block">
				<i class="fas fa-triangle-exclamation mr-1"></i>
				Предохранитель: событие <span class="font-mono"><?php echo HTML::chars((string) Arr::get($source_alert, 'alias', '')); ?></span>
				шло <b><?php echo (int) Arr::get($source_alert, 'count', 0); ?></b> раз за час при лимите <?php echo (int) Arr::get($source_alert, 'limit', 0); ?> в канале
				<?php echo HTML::chars(Service_Prl597_Base::channel_label((string) Arr::get($source_alert, 'channel', ''))); ?> —
				остальное отложено до конца часа. Если это не нужно людям, выключите событие во вкладке
				<a href="/prl597/admin?tab=events" class="font-semibold underline">«События»</a>.
			</div>
			<?php endif; ?>
			<p class="prl-hint mt-0.5">
				Если событие шлёт мусор, его записи можно убрать из лент всех получателей сразу. Чтобы оно не приходило снова — выключите его во вкладке
				<a href="/prl597/admin?tab=events" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">«События»</a>.
			</p>
			<?php if ($feed_events): ?>
			<div class="mt-2 flex flex-wrap gap-2">
				<?php foreach ($feed_events as $fe):
					$fe_alias = (string) Arr::get($fe, 'event_alias', '');
					$fe_count = (int) Arr::get($fe, 'c', 0);
					$fe_hour  = (int) Arr::get($fe, 'c_hour', 0);
					$fe_hot   = $fe_hour >= max(1, (int) $feed_cap);
				?>
				<form autocomplete="off" method="post" action="/prl597/admin">
					<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
					<input type="hidden" name="act" value="purge_feed">
					<input type="hidden" name="tab" value="queue">
					<input type="hidden" name="alias" value="<?php echo HTML::chars($fe_alias); ?>">
					<button data-prl-arm="Убрать из ленты?" type="submit" class="prl-btn-secondary prl-btn-sm">
						<i class="fas fa-eraser"></i>
						<span class="font-mono"><?php echo HTML::chars($fe_alias); ?></span>
						<span class="prl-count"><?php echo $fe_count; ?></span>
						<?php if ($fe_hour > 0): ?>
						<span class="<?php echo $fe_hot ? 'prl-badge prl-badge-err' : 'prl-count'; ?>"><?php echo $fe_hot ? '<span class="prl-dot"></span>' : ''; ?>за час: <?php echo $fe_hour; ?></span>
						<?php endif; ?>
					</button>
				</form>
				<?php endforeach; ?>
			</div>
			<?php else: ?>
			<p class="prl-hint mt-2">За неделю лента пуста.</p>
			<?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ($tab === 'events'): ?>
	<!-- ================= СОБЫТИЯ ================= -->
	<div class="prl-card p-0 mt-5">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prl-h3">Реестр событий</h2>
			<p class="prl-hint mt-0.5">Что объявили модули в манифестах и что из этого включено. Источник: <code>notify.events</code> в <code>config/prl_module.php</code> — при появлении модуля подхватывается само.</p>
			<p class="mt-1 text-xs text-amber-600 dark:text-amber-400"><i class="fas fa-circle-info mr-1"></i>Включённое здесь событие уходит только тем, кто отметил его у себя в настройках: у всех по умолчанию уведомления выключены.</p>
		</div>
		<div class="overflow-x-auto">
			<table class="prl-table min-w-[900px]">
				<thead>
					<tr class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
						<th class="px-4 py-2 text-left font-semibold">Событие</th>
						<th class="px-3 py-2 text-left font-semibold">Категория</th>
						<th class="px-3 py-2 text-left font-semibold">Каналы</th>
						<th class="px-3 py-2 text-left font-semibold">Кому</th>
						<th class="px-3 py-2 text-center font-semibold">Обяз.</th>
						<th class="px-3 py-2 text-center font-semibold">Сводка</th>
						<th class="px-3 py-2 text-center font-semibold">Вкл.</th>
						<th class="px-3 py-2 text-center font-semibold">Действие</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
					<?php foreach ($events as $event): ?>
					<tr>
						<td class="px-4 py-2.5">
							<span class="font-semibold text-gray-800 dark:text-gray-100"><i class="<?php echo HTML::chars($event['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars($event['title']); ?></span>
							<code class="block text-xs text-gray-400"><?php echo HTML::chars($event['alias']); ?></code>
							<?php if ( ! empty($event['source'])): ?>
							<span class="block text-xs text-gray-400">источник: <?php echo HTML::chars($event['source']); ?></span>
							<?php endif; ?>
						</td>
						<td class="px-3 py-2.5 text-gray-600 dark:text-gray-300"><?php echo HTML::chars(Service_Prl597_Registry::category_label($event['category'])); ?></td>
						<td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars(implode(', ', array_map(['Service_Prl597_Registry', 'channel_label'], $event['channels']))); ?></td>
						<td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">
							<?php echo HTML::chars(implode(', ', $event['roles'])); ?>
							<?php if ($event['ability'] !== ''): ?><span class="block text-gray-400">право: <?php echo HTML::chars($event['ability']); ?></span><?php endif; ?>
						</td>
						<td class="px-3 py-2.5 text-center"><?php echo $event['mandatory'] ? '<i class="fas fa-lock text-amber-500"></i>' : '<span class="text-gray-300">—</span>'; ?></td>
						<td class="px-3 py-2.5 text-center"><?php echo $event['digest'] ? '<i class="fas fa-layer-group text-blue-500"></i>' : '<span class="text-gray-300">—</span>'; ?></td>
						<td class="px-3 py-2.5 text-center">
							<form autocomplete="off" method="post" action="/prl597/admin">
								<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
								<input type="hidden" name="act" value="event_toggle">
								<input type="hidden" name="tab" value="events">
								<input type="hidden" name="alias" value="<?php echo HTML::chars($event['alias']); ?>">
								<input type="hidden" name="enabled" value="<?php echo $event['enabled'] ? '0' : '1'; ?>">
								<button type="submit" class="inline-flex h-6 w-6 items-center justify-center rounded <?php echo $event['enabled'] ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40' : 'bg-gray-100 text-gray-400 dark:bg-gray-700'; ?>">
									<i class="fas <?php echo $event['enabled'] ? 'fa-bell' : 'fa-bell-slash'; ?> text-xs"></i>
								</button>
							</form>
						</td>
						<td class="px-3 py-2.5 text-center">
							<button type="button" data-prl597-event-edit='<?php echo HTML::chars(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'
							        class="prl-btn-icon text-xs">
								<i class="fas fa-pen"></i>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
			<button type="button" data-prl597-event-new class="prl-btn-cta prl-btn-sm"><i class="fas fa-plus mr-1.5"></i>Добавить своё событие</button>
			<span class="ml-2 text-xs text-gray-400">Доступных пользователям сейчас: <?php echo count($events_on); ?></span>
		</div>
	</div>

	<!-- форма правки события -->
	<div id="prl597-event-form" class="prl-panel mt-4 hidden border-blue-300 dark:border-blue-700">
		<form autocomplete="off" method="post" action="/prl597/admin" class="grid gap-3 px-4 py-4 sm:grid-cols-2">
			<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
			<input type="hidden" name="act" value="event_save">
			<input type="hidden" name="tab" value="events">
			<h3 class="prl-h3 sm:col-span-2">Событие</h3>

			<label class="block sm:col-span-2">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Код (алиас)</span>
				<input type="text" name="alias" id="prl_ev_alias" required placeholder="mymodule.something.happened"
				       class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Название</span>
				<input type="text" name="title" id="prl_ev_title" required class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Категория</span>
				<select name="category" id="prl_ev_category" class="prl-input prl-select mt-1 w-full">
					<?php foreach (Service_Prl597_Registry::categories() as $key => $label): ?>
					<option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($label); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="block sm:col-span-2">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Описание (увидят пользователи в настройках)</span>
				<input type="text" name="description" id="prl_ev_description" class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Иконка (Font Awesome)</span>
				<input type="text" name="icon" id="prl_ev_icon" value="fas fa-bell" class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Право (пусто — по ролям)</span>
				<input type="text" name="ability" id="prl_ev_ability" value="prl597.notify" class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<fieldset class="sm:col-span-2">
				<legend class="text-xs font-semibold text-gray-600 dark:text-gray-300">Каналы</legend>
				<div class="mt-1 flex flex-wrap gap-3" id="prl_ev_channels">
					<?php foreach (Service_Prl597_Registry::channels(FALSE) as $key => $info): ?>
					<label class="inline-flex items-center gap-1.5 text-sm">
						<input type="checkbox" name="channels[]" value="<?php echo HTML::chars((string) $key); ?>" class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
						<?php echo HTML::chars($info['label']); ?>
					</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
			<fieldset class="sm:col-span-2">
				<legend class="text-xs font-semibold text-gray-600 dark:text-gray-300">Роли</legend>
				<div class="mt-1 flex flex-wrap gap-3" id="prl_ev_roles">
					<?php foreach (['login' => 'вошедший', 'manager' => 'менеджер', 'admin' => 'администратор'] as $role => $label): ?>
					<label class="inline-flex items-center gap-1.5 text-sm">
						<input type="checkbox" name="roles[]" value="<?php echo $role; ?>" class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
						<?php echo HTML::chars($label); ?>
					</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
			<label class="block sm:col-span-2">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Подстановки (через запятую, в шаблонах — <code>{{код}}</code>)</span>
				<input type="text" name="placeholders" id="prl_ev_placeholders" placeholder="title,count,href" class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<div class="flex flex-wrap gap-4 sm:col-span-2">
				<label class="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" name="enabled" id="prl_ev_enabled" value="1" checked class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"> включено</label>
				<label class="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" name="mandatory" id="prl_ev_mandatory" value="1" class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"> обязательное (нельзя выключить)</label>
				<label class="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" name="digest" id="prl_ev_digest" value="1" class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"> склеивать в сводку</label>
			</div>
			<div class="flex gap-2 sm:col-span-2">
				<button type="submit" class="prl-btn-cta"><i class="fas fa-floppy-disk mr-2"></i>Сохранить</button>
				<button type="button" data-prl597-event-cancel class="prl-btn-secondary">Отмена</button>
			</div>
		</form>
	</div>
	<?php endif; ?>

	<?php if ($tab === 'message'): ?>
	<div class="prl-card p-0 mt-4">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prl-h3">Сообщение от системы</h2>
			<p class="prl-hint mt-0.5">Одному пользователю, только в колокольчик и ленту. Без почты и мессенджеров. Сообщение добавится даже при выключенных уведомлениях на сайте.</p>
		</div>
		<form autocomplete="off" method="post" action="/prl597/admin" class="prl597-busy grid gap-3 px-4 py-4 sm:grid-cols-2">
			<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
			<input type="hidden" name="act" value="notify_user">
			<input type="hidden" name="tab" value="message">
			<label class="sm:col-span-2 block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">ID или точный email пользователя</span>
				<input type="text" name="recipient" required maxlength="254" placeholder="Например: 42 или user@example.com" class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="sm:col-span-2 block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Заголовок</span>
				<input type="text" name="title" required maxlength="180" value="Сообщение от системы" class="prl-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="sm:col-span-2 block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Текст (до 2000 символов, без HTML)</span>
				<textarea autocomplete="off" name="body" required rows="5" maxlength="2000" class="prl-textarea mt-1 w-full"></textarea>
			</label>
			<div class="sm:col-span-2">
				<button type="submit" class="prl-btn-cta"><i class="fas fa-paper-plane mr-2"></i>Отправить пользователю</button>
			</div>
		</form>
	</div>
	<?php endif; ?>

	<?php if ($tab === 'templates'): ?>
	<?php
	// Стенды и старые вызовы могут не передать данные ИИ — живём на умолчаниях.
	$ai_ready = isset($ai_ready) ? (bool) $ai_ready : Service_Prl597_Ai::ready($ai_default_why);
	$ai_why   = isset($ai_why) ? (string) $ai_why : (string) $ai_default_why;
	$ai_model = isset($ai_model) ? (string) $ai_model : Service_Prl597_Ai::model_label();
	$ai_tones = (isset($ai_tones) AND is_array($ai_tones) AND $ai_tones) ? $ai_tones : Service_Prl597_Ai::tones();

	// «Имеющееся сообщение» можно не искать в своей почте: последние отправки и
	// свежая лента уже рядом — из них и собираем шаблон.
	$ai_sources = [];

	foreach (array_slice(isset($feed) ? (array) $feed : [], 0, 10) as $row)
	{
		$label = trim((string) Arr::get($row, 'title', ''));
		$text  = trim($label.((string) Arr::get($row, 'body', '') !== '' ? "\n\n".trim((string) Arr::get($row, 'body', '')) : ''));

		if ($text !== '')
		{
			$ai_sources[] = ['label' => 'В ленте: '.mb_substr($label !== '' ? $label : 'без заголовка', 0, 70), 'text' => $text];
		}
	}

	foreach (array_slice(isset($sent) ? (array) $sent : [], 0, 10) as $row)
	{
		$label = trim((string) Arr::get($row, 'subject', ''));
		$text  = trim($label.((string) Arr::get($row, 'message', '') !== '' ? "\n".trim((string) Arr::get($row, 'message', '')) : ''));

		if ($text !== '')
		{
			$ai_sources[] = ['label' => 'Отправлено ('.Service_Prl597_Registry::channel_label((string) Arr::get($row, 'channel', '')).'): '.mb_substr($label !== '' ? $label : 'без темы', 0, 60), 'text' => $text];
		}
	}
	?>
	<!-- ================= ШАБЛОНЫ ================= -->
	<?php
	// Счётчики заполненности: событий десятки, глазами такое не окинешь, а
	// пустой шаблон — это «голое» уведомление в почте пользователя.
	$tpl_filled = 0;
	$tpl_total  = 0;
	$ch_keys    = array_keys((array) Service_Prl597_Registry::channels(FALSE));

	foreach ($events_on as $event)
	{
		foreach ($ch_keys as $ch_key)
		{
			$tpl_total++;

			if (isset($templates[$event['alias']][$ch_key]) AND trim((string) $templates[$event['alias']][$ch_key]['body']) !== '')
			{
				$tpl_filled++;
			}
		}
	}
	?>
	<div class="mt-5 space-y-4">
		<!-- Поиск по событиям и фильтр «без текста» -->
		<div class="prl-card p-0 flex flex-wrap items-center gap-3 px-4 py-3">
			<label class="flex min-w-[220px] flex-1 items-center gap-2">
				<i class="fas fa-magnifying-glass text-gray-400"></i>
				<input type="search" data-prl597-tpl-filter class="prl-input w-full" placeholder="Найти событие: тренды, сборка, prl667…" autocomplete="off">
			</label>
			<label class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
				<input type="checkbox" data-prl597-tpl-gaps class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"> только без текста
			</label>
			<span class="text-xs text-gray-500 dark:text-gray-400">заполнено <strong class="text-gray-700 dark:text-gray-200" data-prl597-tpl-count><?php echo (int) $tpl_filled; ?></strong> из <?php echo (int) $tpl_total; ?></span>
			<span class="text-xs text-amber-600 dark:text-amber-400" data-prl597-tpl-hidden></span>
		</div>
		<!-- ИИ-помощник: собрать шаблон из имеющегося сообщения -->
		<div class="rounded-xl border border-blue-200 bg-blue-50/50 shadow-xs dark:border-blue-800/60 dark:bg-blue-950/20">
			<div class="flex flex-wrap items-center justify-between gap-2 border-b border-blue-100 px-4 py-3.5 dark:border-blue-800/50">
				<div>
					<h2 class="prl-h3"><i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500"></i>Составить шаблон с ИИ</h2>
					<p class="prl-hint mt-0.5">Вставьте сообщение, которое уже писали людям, — ИИ перепишет его в шаблон выбранного события и канала: сохранит подстановки, добавит тему письма и уберёт лишнее. Ничего не сохраняется, пока вы не нажмёте «Сохранить» в нужной карточке.</p>
				</div>
				<span class="prl-badge <?php echo $ai_ready ? 'prl-badge-ok' : 'prl-badge-err'; ?>"><span class="prl-dot"></span><?php echo $ai_ready ? 'ИИ готов' : 'ИИ недоступен'; ?></span>
			</div>
			<?php if ( ! $ai_ready): ?>
			<div class="px-4 py-4 text-sm text-amber-700 dark:text-amber-300">
				<i class="fas fa-triangle-exclamation mr-1.5"></i><?php echo HTML::chars((string) $ai_why); ?>
				<span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Шаблоны можно писать руками — редактор ниже работает как обычно.</span>
			</div>
			<?php else: ?>
			<form autocomplete="off" data-prl597-ai class="grid gap-3 px-4 py-4 lg:grid-cols-2">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<label class="block">
					<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Событие</span>
					<select name="event" class="prl-input prl-select mt-1 w-full">
						<?php foreach ($events_on as $event): ?>
						<option value="<?php echo HTML::chars($event['alias']); ?>" <?php echo $event['alias'] === 'system.message' ? 'selected' : ''; ?>><?php echo HTML::chars($event['title']); ?> — <?php echo HTML::chars($event['alias']); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="grid gap-3 sm:grid-cols-2">
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Канал</span>
						<select name="channel" class="prl-input prl-select mt-1 w-full">
							<?php foreach (Service_Prl597_Ai::channel_labels() as $ch_key => $ch_label): ?>
							<option value="<?php echo HTML::chars($ch_key); ?>"><?php echo HTML::chars($ch_label); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Тон</span>
						<select name="tone" class="prl-input prl-select mt-1 w-full">
							<?php foreach ($ai_tones as $t_key => $t_info): ?>
							<option value="<?php echo HTML::chars($t_key); ?>"><?php echo HTML::chars($t_info[0]); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<label class="block lg:col-span-2">
					<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">О чём уведомляем (необязательно)</span>
					<input type="text" name="about" maxlength="400" placeholder="Например: обзор собран и ждёт публикации" class="prl-input mt-1 w-full" autocomplete="off">
				</label>
				<?php if ($ai_sources): ?>
				<label class="block lg:col-span-2">
					<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Взять готовое сообщение (за сутки)</span>
					<select data-prl597-ai-recent class="prl-input prl-select mt-1 w-full">
						<option value="">— выбрать из отправленного или из ленты —</option>
						<?php foreach ($ai_sources as $i => $src): ?>
						<option value="<?php echo (int) $i; ?>" data-text="<?php echo HTML::chars($src['text']); ?>"><?php echo HTML::chars($src['label']); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php endif; ?>
				<label class="block lg:col-span-2">
					<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Имеющееся сообщение — из него собираем шаблон</span>
					<textarea name="source" rows="6" maxlength="4000" required placeholder="Вставьте текст, который вы уже отправляли или собирались отправить людям" class="prl-textarea mt-1 w-full" autocomplete="off"></textarea>
				</label>
				<div class="flex flex-wrap items-center gap-2 lg:col-span-2">
					<button type="submit" class="prl-btn-process" data-prl597-ai-run><i class="fas fa-wand-magic-sparkles mr-2"></i>Собрать шаблон</button>
					<span class="text-xs text-gray-400">модель: <?php echo HTML::chars((string) $ai_model); ?></span>
				</div>
			</form>
			<div class="hidden border-t border-blue-100 px-4 py-4 dark:border-blue-800/50" data-prl597-ai-out>
				<div class="mb-2 flex flex-wrap items-center justify-between gap-2">
					<span class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-file-lines mr-1.5 text-blue-500"></i>Что собрал ИИ</span>
					<span class="text-xs text-gray-400" data-prl597-ai-meta></span>
				</div>
				<div class="space-y-2 text-sm">
					<div><span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Тема:</span>
						<span class="block rounded-lg bg-white px-3 py-2 font-medium text-gray-900 dark:bg-gray-900 dark:text-gray-100" data-prl597-ai-subject></span></div>
					<div><span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Текст:</span>
						<div class="block whitespace-pre-wrap rounded-lg bg-white px-3 py-2 text-gray-800 dark:bg-gray-900 dark:text-gray-100" data-prl597-ai-body></div></div>
					<p class="prl-hint" data-prl597-ai-notes></p>
				</div>
				<div class="mt-3 flex flex-wrap items-center gap-2">
					<button type="button" class="prl-btn-process prl-btn-sm" data-prl597-ai-apply><i class="fas fa-arrow-down mr-1.5"></i>Подставить в шаблон события и канала</button>
					<button type="button" class="prl-btn-secondary prl-btn-sm" data-prl597-ai-again><i class="fas fa-rotate mr-1.5"></i>Собрать заново</button>
					<span class="text-xs text-gray-400" data-prl597-ai-flash></span>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<?php foreach ($events_on as $event):
			$mine = 0;

			foreach ($ch_keys as $ch_key)
			{
				if (isset($templates[$event['alias']][$ch_key]) AND trim((string) $templates[$event['alias']][$ch_key]['body']) !== '')
				{
					$mine++;
				}
			}
		?>
		<details open class="prl-card p-0" data-prl597-tpl-card data-prl597-tpl-search="<?php echo HTML::chars(mb_strtolower($event['title'].' '.$event['alias'].' '.Arr::get($event, 'category', ''))); ?>" data-prl597-tpl-filled="<?php echo (int) $mine; ?>">
			<summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-4 py-3.5">
				<h2 class="prl-h3">
					<i class="<?php echo HTML::chars($event['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars($event['title']); ?>
					<code class="ml-2 text-xs font-normal text-gray-400"><?php echo HTML::chars($event['alias']); ?></code>
					<span class="prl-badge <?php echo $mine === 0 ? 'prl-badge-run' : 'prl-badge-ok'; ?> ml-2"><span class="prl-dot"></span><?php echo $mine === 0 ? 'нет текстов' : $mine.' из '.count($ch_keys).' каналов'; ?></span>
				</h2>
				<span class="text-xs text-gray-400">подстановки:
					<?php foreach (array_merge(Service_Prl597_Base::_csv(Arr::get($event, 'placeholders', '')), ['user_name', 'site_url', 'href']) as $ph): ?>
					<button type="button" data-prl597-insert="{{<?php echo HTML::chars($ph); ?>}}" class="ml-0.5 rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-600 transition hover:bg-blue-100 hover:text-blue-700 dark:bg-gray-700 dark:text-gray-300" title="Вставить в поле шаблона">{{<?php echo HTML::chars($ph); ?>}}</button>
					<?php endforeach; ?>
				</span>
			</div>
			<div class="grid gap-4 px-4 py-4 lg:grid-cols-2">
				<?php foreach (Service_Prl597_Registry::channels(FALSE) as $key => $info):
					$tpl = $templates[$event['alias']][$key] ?? NULL;
				?>
				<form autocomplete="off" method="post" action="/prl597/admin" class="rounded-lg border border-gray-100 p-3 dark:border-gray-700/60" data-prl597-tpl-event="<?php echo HTML::chars($event['alias']); ?>" data-prl597-tpl-channel="<?php echo HTML::chars((string) $key); ?>">
					<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
					<input type="hidden" name="act" value="template_save">
					<input type="hidden" name="tab" value="templates">
					<input type="hidden" name="event" value="<?php echo HTML::chars($event['alias']); ?>">
					<input type="hidden" name="channel" value="<?php echo HTML::chars((string) $key); ?>">
					<div class="mb-2 flex items-center justify-between">
						<span class="text-sm font-semibold text-gray-800 dark:text-gray-100"><i class="<?php echo HTML::chars($info['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars($info['label']); ?>
						<?php if ($tpl === NULL OR trim((string) Arr::get((array) $tpl, 'body', '')) === ''): ?>
						<span class="prl-badge prl-badge-wait ml-1.5"><span class="prl-dot"></span>нет текста</span>
						<?php endif; ?>
						</span>
						<label class="inline-flex items-center gap-1.5 text-xs"><input type="checkbox" name="is_active" value="1" <?php echo ($tpl === NULL OR ! empty($tpl['is_active'])) ? 'checked' : ''; ?> class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"> вкл.</label>
					</div>
					<?php if ((string) $key !== 'in_app'): ?>
					<input type="text" name="subject" value="<?php echo HTML::chars((string) Arr::get((array) $tpl, 'subject', '')); ?>" placeholder="Тема ({{title}})"
					       class="prl-input mb-2 w-full py-1.5 text-sm min-h-0" autocomplete="off">
					<?php else: ?>
					<input type="hidden" name="subject" value="<?php echo HTML::chars((string) Arr::get((array) $tpl, 'subject', '')); ?>">
					<?php endif; ?>
					<textarea autocomplete="off" name="body" rows="3" placeholder="Текст: {{title}}, {{user_name}}, {{site_url}}" class="prl-textarea w-full"><?php echo HTML::chars((string) Arr::get((array) $tpl, 'body', '')); ?></textarea>
					<div class="mt-2 flex flex-wrap items-center gap-2">
						<select name="format" class="prl-input prl-select py-1 text-xs min-h-0 w-auto">
							<?php foreach (['text' => 'обычный текст', 'html' => 'HTML', 'md' => 'Markdown'] as $fmt => $label): ?>
							<option value="<?php echo $fmt; ?>" <?php echo (string) Arr::get((array) $tpl, 'format', 'text') === $fmt ? 'selected' : ''; ?>><?php echo $label; ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" data-prl597-tpl-preview class="prl-btn-secondary prl-btn-sm"><i class="fas fa-eye mr-1"></i>Предпросмотр</button>
						<button type="submit" class="prl-btn-cta prl-btn-sm">Сохранить</button>
						<?php if ($tpl !== NULL): ?>
						<span class="text-xs text-gray-400">изменён: <?php echo HTML::chars((string) Arr::get($tpl, 'updated_at', '')); ?></span>
						<?php endif; ?>
					</div>
					<div data-prl597-tpl-out class="mt-2 hidden rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-200"></div>
				</form>
				<?php endforeach; ?>
			</div>
		</details>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php if ($tab === 'channels'): ?>
	<!-- ================= КАНАЛЫ ================= -->
	<div class="mt-5 grid gap-4 lg:grid-cols-2">
		<?php foreach ($channels as $row): ?>
		<div class="prl-card p-0">
			<div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
				<h2 class="prl-h3"><i class="<?php echo HTML::chars($row['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars($row['label']); ?></h2>
				<span class="prl-badge <?php echo $row['enabled'] ? 'prl-badge-ok' : 'prl-badge-wait'; ?>"><span class="prl-dot"></span><?php echo $row['enabled'] ? 'настроен' : 'не настроен / выключен'; ?></span>
			</div>
			<div class="space-y-2 px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
				<?php if ($row['whoami'] !== NULL): ?>
				<?php if (isset($row['whoami']['error'])): ?>
				<p class="text-rose-600 dark:text-rose-400"><i class="fas fa-circle-exclamation mr-1"></i><?php echo HTML::chars($row['whoami']['error']); ?></p>
				<?php elseif ( ! empty($row['whoami'])): ?>
				<ul>
					<?php foreach ($row['whoami'] as $k => $v): ?>
					<li><span class="text-gray-400"><?php echo HTML::chars((string) $k); ?>:</span> <strong class="text-gray-700 dark:text-gray-200"><?php echo HTML::chars(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)); ?></strong></li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<?php else: ?>
				<p>Доставка внутри сайта, внешний сервис не нужен.</p>
				<?php endif; ?>

				<?php if ($row['bot'] !== NULL): ?>
				<p class="mt-2 border-t border-gray-100 pt-2 dark:border-gray-700/60">
					Режим: <strong class="text-gray-700 dark:text-gray-200"><?php echo HTML::chars($row['bot']['mode']); ?></strong>
				</p>
				<code class="block break-all"><?php echo HTML::chars($row['bot']['url']); ?></code>
				<code class="block break-all">привязка: <?php echo HTML::chars($row['bot']['bot_link']); ?></code>
				<p>Для показа пользователям нужны токен и имя бота (<code>PRL597_NOTIFICATIONS_TELEGRAM_BOT_NICK</code> или <code>PRL597_NOTIFICATIONS_MAX_BOT_NICK</code>), режим не «выключен». Проверьте привязку и доставку — статус «настроен» не гарантирует связь с сервисом.</p>
				<div class="mt-2 flex flex-wrap gap-2">
					<form autocomplete="off" method="post" action="/prl597/admin" class="flex flex-wrap items-end gap-2">
						<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
						<input type="hidden" name="act" value="bot_mode">
						<input type="hidden" name="tab" value="channels">
						<input type="hidden" name="channel" value="<?php echo HTML::chars($row['key']); ?>">
						<select name="mode" class="prl-input prl-select py-1 text-xs min-h-0 w-auto">
							<?php foreach (['off' => 'выключен', 'webhook' => 'вебхук', 'poll' => 'опрос'] as $mode => $label): ?>
							<option value="<?php echo $mode; ?>" <?php echo $row['bot']['mode'] === $mode ? 'selected' : ''; ?>><?php echo $label; ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="prl-btn-secondary prl-btn-sm">Задать режим</button>
					</form>
					<form autocomplete="off" method="post" action="/prl597/admin">
						<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
						<input type="hidden" name="act" value="webhook">
						<input type="hidden" name="tab" value="channels">
						<input type="hidden" name="channel" value="<?php echo HTML::chars($row['key']); ?>">
						<button type="submit" class="prl-btn-secondary prl-btn-sm">Зарегистрировать вебхук</button>
					</form>
				</div>
				<?php endif; ?>
			</div>
			<form autocomplete="off" method="post" action="/prl597/admin" class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="channel_test">
				<input type="hidden" name="tab" value="channels">
				<input type="hidden" name="channel" value="<?php echo HTML::chars($row['key']); ?>">
				<input type="text" name="to" placeholder="<?php echo $row['key'] === 'in_app' ? 'не нужно' : ($row['key'] === 'email' ? 'you@example.com' : 'chat_id или id пользователя'); ?>"
				       class="prl-input min-w-[160px] flex-1 py-1.5 text-xs min-h-0" autocomplete="off">
				<button type="submit" class="prl-btn-process prl-btn-sm"><i class="fas fa-paper-plane mr-1.5"></i>Пробное</button>
			</form>
		</div>
		<?php endforeach; ?>
	</div>

	<div class="prl-card mt-4 text-xs text-gray-500 dark:text-gray-400">
		<h3 class="mb-1 text-sm font-bold text-gray-900 dark:text-white">Подсказка</h3>
		<p>Токены ботов читаются из <code>.env</code>: <code>PRL597_NOTIFICATIONS_TELEGRAM_BOT_TOKEN</code>, <code>PRL597_NOTIFICATIONS_MAX_BOT_TOKEN</code>, <code>PRL597_NOTIFICATIONS_MAX_BUSINESS_KEY</code>. В базе они не хранятся — так секреты не утекут вместе с дампом.</p>
		<p class="mt-1">Для MAX администратор выбирает драйвер: обычный бот (<code>bot</code>) или бизнес-API (<code>business</code>) — переключатель на вкладке «Лимиты».</p>
		<form autocomplete="off" method="post" action="/prl597/admin" class="mt-3 flex flex-wrap items-center gap-2">
			<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
			<input type="hidden" name="act" value="test_email">
			<input type="hidden" name="tab" value="channels">
			<input type="email" name="to" placeholder="you@example.com — пусто = ваш аккаунт" class="prl-input min-w-[200px] flex-1 py-1.5 text-xs min-h-0" autocomplete="off">
			<button type="submit" class="prl-btn-secondary prl-btn-sm"><i class="fas fa-envelope mr-1.5"></i>Тестовое письмо</button>
		</form>
	</div>
	<?php endif; ?>

	<?php if ($tab === 'limits'): ?>
	<!-- ================= ЛИМИТЫ ================= -->
	<form autocomplete="off" method="post" action="/prl597/admin">
		<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
		<input type="hidden" name="act" value="limits_save">
		<input type="hidden" name="tab" value="limits">

		<div class="prl-card p-0 mt-5">
			<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
				<h2 class="prl-h3">Лимиты на пользователя и на систему</h2>
				<p class="prl-hint mt-0.5">0 — не ограничивать. Общий лимит защищает всю платформу от спама, пользовательский — конкретного человека.</p>
			</div>
			<div class="overflow-x-auto">
				<table class="prl-table min-w-[560px]">
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
						<?php foreach (Service_Prl597_Registry::channels(FALSE) as $key => $info): ?>
						<tr>
							<td class="px-4 py-2.5 font-semibold text-gray-800 dark:text-gray-100"><i class="<?php echo HTML::chars($info['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars($info['label']); ?></td>
							<?php foreach (['per_user_hour', 'per_user_day', 'per_hour', 'per_event_day'] as $scope): ?>
							<td class="px-3 py-2.5 text-center">
								<input type="number" min="0" max="100000" name="limit[<?php echo $scope; ?>][<?php echo HTML::chars((string) $key); ?>]"
								       value="<?php echo (int) Arr::path($limits, $scope.'.'.$key, 0); ?>"
								       class="prl-input w-24 py-1 text-center text-sm min-h-0" autocomplete="off">
							</td>
							<?php endforeach; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
				<button type="submit" class="prl-btn-cta"><i class="fas fa-floppy-disk mr-2"></i>Сохранить лимиты</button>
			</div>
		</div>
	</form>

	<form autocomplete="off" method="post" action="/prl597/admin">
		<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
		<input type="hidden" name="act" value="settings_save">
		<input type="hidden" name="tab" value="limits">

		<div class="mt-4 grid gap-4 lg:grid-cols-2">
			<div class="prl-card p-0">
				<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
					<h2 class="prl-h3">Антиспам и доставка</h2>
				</div>
				<div class="grid gap-3 px-4 py-4 sm:grid-cols-2">
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Окно склейки повторов, сек</span>
						<input type="number" name="dedup_window" min="0" max="86400" value="<?php echo (int) $limits_extra['dedup_window']; ?>" class="prl-input mt-1 w-full" autocomplete="off">
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Попыток на сообщение</span>
						<input type="number" name="max_attempts" min="1" max="20" value="<?php echo (int) $limits_extra['max_attempts']; ?>" class="prl-input mt-1 w-full" autocomplete="off">
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Размер пачки за проход</span>
						<input type="number" name="batch_limit" min="1" max="500" value="<?php echo (int) $limits_extra['batch_limit']; ?>" class="prl-input mt-1 w-full" autocomplete="off">
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Драйвер MAX</span>
						<select name="max_driver" class="prl-input prl-select mt-1 w-full">
							<option value="bot" <?php echo $limits_extra['max_driver'] === 'bot' ? 'selected' : ''; ?>>бот (личные сообщения)</option>
							<option value="business" <?php echo $limits_extra['max_driver'] === 'business' ? 'selected' : ''; ?>>бизнес-API (официальный канал)</option>
						</select>
					</label>
				</div>
			</div>

			<div class="prl-card p-0">
				<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
					<h2 class="prl-h3">Сроки хранения, дней</h2>
				</div>
				<div class="grid gap-3 px-4 py-4 sm:grid-cols-3">
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Лента</span>
						<input type="number" name="inbox_days" min="1" max="3650" value="<?php echo (int) $cleanup['inbox']; ?>" class="prl-input mt-1 w-full" autocomplete="off">
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Очередь</span>
						<input type="number" name="outbox_days" min="1" max="3650" value="<?php echo (int) $cleanup['outbox']; ?>" class="prl-input mt-1 w-full" autocomplete="off">
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Журнал</span>
						<input type="number" name="log_days" min="1" max="3650" value="<?php echo (int) $cleanup['log']; ?>" class="prl-input mt-1 w-full" autocomplete="off">
					</label>
				</div>
			</div>
		</div>
		<div class="mt-4">
			<button type="submit" class="prl-btn-cta"><i class="fas fa-floppy-disk mr-2"></i>Сохранить настройки</button>
		</div>
	</form>

	<!-- Шкала важности. Одна на площадку: по ней решают тихие часы и сводка,
	     поэтому администратор видит не только порядок, но и что значит число.
	     Порядок меняется стрелками (кнопки, не drag&drop) — одной формой на
	     всю шкалу: каждая кнопка несёт пару «категория:направление». -->
	<div class="prl-card p-0 mt-4">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prl-h3">Важность событий по категориям</h2>
			<p class="prl-hint mt-0.5">1 — будим сразу, 9 — фон. Чем выше категория в списке, тем важнее её события: важность ≤ <?php echo (int) $priority_skip; ?> не ждёт тихих часов, важность ≥ <?php echo (int) $priority_digest; ?> может уехать в суточную сводку.</p>
		</div>
		<form autocomplete="off" method="post" action="/prl597/admin">
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
						<button type="submit" name="move" value="<?php echo HTML::chars((string) $prio['category']); ?>:-1" <?php echo $i === 0 ? 'disabled' : ''; ?> title="Важнее" class="prl-btn-icon"><i class="fas fa-arrow-up"></i></button>
						<button type="submit" name="move" value="<?php echo HTML::chars((string) $prio['category']); ?>:1" <?php echo $i === $prio_last ? 'disabled' : ''; ?> title="Менее важно" class="prl-btn-icon"><i class="fas fa-arrow-down"></i></button>
					</span>
				</div>
				<?php endforeach; ?>
			</div>
			<div class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
				Числа берутся из конфига модуля и не меняются: стрелки переставляют категории по этой лестнице, поэтому пороги тихих часов и сводки остаются на месте.
			</div>
		</form>
	</div>
	<?php endif; ?>

	<?php if ($tab === 'journal'): ?>
	<!-- ================= ЖУРНАЛ ================= -->
	<?php if ($dead): ?>
	<div class="mt-5 rounded-xl border border-rose-200 bg-rose-50/60 shadow-xs dark:border-rose-900 dark:bg-rose-950/20">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-rose-200 px-4 py-3.5 dark:border-rose-900">
			<h2 class="prl-h3 text-rose-800 dark:text-rose-300"><i class="fas fa-skull mr-1.5"></i>DLQ — не дошло ни с какой попытки</h2>
			<form autocomplete="off" method="post" action="/prl597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="retry_dead">
				<input type="hidden" name="tab" value="journal">
				<button type="submit" class="prl-btn-process prl-btn-sm">Вернуть в очередь</button>
			</form>
		</div>
		<div class="overflow-x-auto">
			<table class="prl-table min-w-[720px]">
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
						<td class="px-3 py-2 text-xs"><?php echo HTML::chars(Service_Prl597_Registry::channel_label((string) Arr::get($row, 'channel', ''))); ?></td>
						<td class="px-3 py-2 text-center text-xs"><?php echo (int) Arr::get($row, 'attempts', 0); ?></td>
						<td class="px-3 py-2 text-xs text-rose-700 dark:text-rose-300"><?php echo HTML::chars((string) Arr::get($row, 'last_error', '')); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php endif; ?>

	<div class="prl-card p-0 mt-5">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prl-h3">Журнал доставки</h2>
			<p class="prl-hint mt-0.5">Последние попытки: что, кому, через что и с каким результатом.</p>
		</div>
		<div class="overflow-x-auto">
			<table class="prl-table min-w-[860px]">
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
						$ok_b   = Service_Prl597_Base::_truthy(Arr::get($row, 'ok', FALSE));
						$reason = (string) Arr::get($row, 'response_code', '');
						$b      = $badge($ok_b ? 'sent' : ($reason === 'skipped' ? 'skipped' : 'failed'));
					?>
					<tr>
						<td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) Arr::get($row, 'created_at', '')); ?></td>
						<td class="px-3 py-2 text-xs"><code><?php echo HTML::chars((string) Arr::get($row, 'event_alias', '')); ?></code></td>
						<td class="px-3 py-2 text-center text-xs text-gray-600 dark:text-gray-300"><?php echo (int) Arr::get($row, 'user_id', 0); ?></td>
						<td class="px-3 py-2 text-xs"><?php echo HTML::chars(Service_Prl597_Registry::channel_label((string) Arr::get($row, 'channel', ''))); ?></td>
						<td class="px-3 py-2 text-center text-xs"><?php echo (int) Arr::get($row, 'attempt', 0); ?></td>
						<td class="px-3 py-2 text-center">
							<span class="prl-badge <?php echo $b[0]; ?>"><span class="prl-dot"></span><?php echo $b[1]; ?></span>
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
	<?php endif; ?>
</div>

<?php echo View::factory('597/script'); ?>
