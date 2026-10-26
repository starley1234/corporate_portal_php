<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Центр уведомлений (prl597): вкладка «Лента» и вкладка «Настройки».
 *
 * Настройки — это матрица «событие × канал» плюс привязка мессенджеров:
 * человек сам решает, что ему присылать и куда. Обязательные события
 * (безопасность) выключить нельзя — в матрице они показаны запертыми.
 *
 * Переменные: $tab, $items, $unread, $prefs, $matrix, $binding, $flash,
 *             $csrf, $email, $channels.
 */

$csrf_h = HTML::chars((string) $csrf);

/** Цвет и иконка канала для «чипа». */
$chip = function ($key) use ($binding) {
	$row = $binding[$key] ?? NULL;
	// Скобки обязательны: AND слабее «=» — без них в $on попал бы
	// результат первого сравнения, а не всей цепочки.
	$on  = ($row !== NULL AND ! empty($row['enabled']) AND (empty($row['bind']) OR $row['status'] === 'active'));

	return [
		'on'     => $on,
		'label'  => $row['label'] ?? Service_Prl597_Registry::channel_label($key),
		'icon'   => $row['icon'] ?? Service_Prl597_Registry::channel_icon($key),
		'status' => $row['status'] ?? 'none',
		'dest'   => $row['destination'] ?? '',
	];
};
?>

<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-medium text-gray-700 dark:text-gray-200">Уведомления</span>
	</nav>

	<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
		<h1 class="prl-h1"><i class="fas fa-bell mr-2 text-blue-500"></i>Уведомления</h1>
		<span class="rounded-full px-2 py-0.5 text-xs font-bold uppercase <?php echo $unread ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-500 dark:bg-gray-800'; ?>">
			<?php echo $unread ? 'непрочитанных: '.(int) $unread : 'всё прочитано'; ?>
		</span>
	</div>

	<?php if ((string) $flash !== ''): ?>
	<div class="mt-3 flex items-center gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs font-semibold sm:text-sm dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300">
		<i class="fas fa-circle-info shrink-0"></i><span><?php echo HTML::chars($flash); ?></span>
	</div>
	<?php endif; ?>

	<?php
	// Владелец (16.10.2026): «по умолчанию у пользователей должны быть отключены
	// уведомления» — наружу. Внутри сайта колокольчик работает всегда, поэтому
	// говорим ровно об этом и ведём в настройки.
	$prl597_channels_on = 0;
	$prl597_ext_ready   = 0;

	foreach (array_keys($channels) as $prl597_ch_key)
	{
		if ($prl597_ch_key !== 'in_app')
		{
			$prl597_ext_ready++;
		}

		if ( ! empty($prefs[$prl597_ch_key.'_enabled']))
		{
			$prl597_channels_on++;
		}
	}

	$prl597_ext_on = $prl597_channels_on - ( ! empty($prefs['in_app_enabled']) ? 1 : 0);
	?>
	<?php if ($prl597_ext_ready > 0 AND $prl597_ext_on <= 0): ?>
	<div class="mt-3 flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs sm:text-sm dark:border-amber-800/60 dark:bg-amber-950/30 dark:text-amber-200">
		<i class="fas fa-bell-slash shrink-0"></i>
		<span class="min-w-0 flex-1">На сайте уведомления видны всегда, а письма и мессенджеры выключены — так у всех по умолчанию. Включите нужное, если хотите получать их вне сайта.</span>
		<?php if ($tab !== 'settings'): ?>
		<a href="/prl597?tab=settings" class="prl-btn-secondary prl-btn-sm shrink-0">Настроить</a>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ================= вкладки ================= -->
	<div class="mt-4 flex gap-1 border-b border-gray-200 dark:border-gray-700">
		<?php foreach (['feed' => ['fas fa-list', 'Лента'], 'settings' => ['fas fa-sliders', 'Настройки']] as $key => $info): ?>
		<a href="/prl597?tab=<?php echo $key; ?>" class="inline-flex min-h-[40px] items-center gap-2 border-b-2 px-3 text-sm font-semibold transition <?php echo $tab === $key ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400'; ?>">
			<i class="<?php echo $info[0]; ?>"></i><?php echo $info[1]; ?>
		</a>
		<?php endforeach; ?>
	</div>

	<?php if ($tab === 'feed'): ?>
	<!-- ================= ЛЕНТА ================= -->
	<div class="mt-4">
		<div class="prl-card p-0">
			<div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
				<h2 class="prl-h3">Последние события</h2>
				<div class="flex flex-wrap gap-2">
					<form method="post" action="/prl597">
						<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
						<input type="hidden" name="act" value="read_all">
						<button type="submit" class="prl-btn-secondary prl-btn-sm">Прочитано всё</button>
					</form>
					<form method="post" action="/prl597">
						<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
						<input type="hidden" name="act" value="archive">
						<button type="submit" class="prl-btn-secondary prl-btn-sm">В архив</button>
					</form>
					<form method="post" action="/prl597">
						<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
						<input type="hidden" name="act" value="clear_read">
						<button data-prl-arm="Удалить?" type="submit" class="prl-btn-secondary prl-btn-sm">Очистить прочитанные</button>
					</form>
				</div>
			</div>

			<?php if ( ! $items): ?>
			<div class="px-5 py-10 text-center">
				<i class="fas fa-bell-slash mb-2 text-2xl text-gray-300 dark:text-gray-600"></i>
				<p class="text-sm text-gray-500 dark:text-gray-400">Уведомлений пока нет. Как только что-то произойдёт — появится здесь и в колокольчике.</p>
			</div>
			<?php else: ?>
			<ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
				<?php foreach ($items as $n): ?>
				<li class="flex items-start gap-3 px-4 py-3.5 sm:px-5 <?php echo empty($n['read_at']) ? 'bg-blue-50/40 dark:bg-blue-950/10' : ''; ?>">
					<i class="<?php echo HTML::chars((string) Arr::get($n, 'icon', 'fas fa-bell')); ?> mt-0.5 w-5 shrink-0 text-center text-sm text-blue-500"></i>
					<div class="min-w-0 flex-1">
						<a href="<?php echo HTML::chars((string) $n['href']); ?>" class="text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-white">
							<?php echo HTML::chars((string) $n['title']); ?>
						</a>
						<?php if ((string) $n['body'] !== ''): ?>
						<p class="prl-hint mt-0.5 whitespace-pre-line"><?php echo HTML::chars((string) $n['body']); ?></p>
						<?php endif; ?>
						<p class="prl-hint flex flex-wrap items-center gap-x-3 gap-y-1">
							<?php if (Arr::get($n, 'event_alias', '') === 'system.admin_message'): ?>
							<span>От системы</span>
							<?php endif; ?>
							<span><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $n['created_at']))); ?></span>
							<span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-500 dark:bg-gray-700 dark:text-gray-300"><?php echo HTML::chars(Service_Prl597_Registry::category_label((string) Arr::get($n, 'category', 'system'))); ?></span>
						</p>
					</div>
					<?php if (empty($n['read_at'])): ?>
					<form method="post" action="/prl597">
						<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
						<input type="hidden" name="act" value="read">
						<input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
						<button type="submit" title="Отметить прочитанным" class="prl-btn-secondary prl-btn-sm"><i class="fas fa-check"></i></button>
					</form>
					<?php endif; ?>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</div>
	</div>

	<?php else: ?>
	<!-- ================= НАСТРОЙКИ ================= -->
	<?php foreach (['telegram', 'max'] as $key): ?>
	<?php if (isset($binding[$key]) AND $binding[$key]['status'] === 'active'): ?>
	<form method="post" action="/prl597" id="prl597-unbind-<?php echo HTML::chars($key); ?>">
		<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
		<input type="hidden" name="act" value="unbind">
		<input type="hidden" name="channel" value="<?php echo HTML::chars($key); ?>">
	</form>
	<?php endif; ?>
	<?php endforeach; ?>
	<form method="post" action="/prl597" id="prl597-prefs-form">
		<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
		<input type="hidden" name="act" value="prefs">

		<div class="mt-4 grid gap-4 lg:grid-cols-3">

			<!-- каналы -->
			<div class="lg:col-span-1">
				<div class="prl-card p-0">
					<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
						<h2 class="prl-h3">Куда присылать</h2>
						<p class="prl-hint mt-0.5">Колокольчик на сайте включён всегда — лента не беспокоит. Письма и мессенджеры выключены по умолчанию: ничего не приходит, пока вы не отметите сами. Галочка здесь разрешает способ доставки, а что именно присылать — в таблице событий.</p>
					</div>
					<div class="space-y-3 px-4 py-4">
						<?php foreach ($channels as $key => $info):
							$field = $key.'_enabled';
							$state = $chip($key);
						?>
						<label class="flex items-start gap-3">
							<input type="hidden" name="<?php echo HTML::chars($field); ?>" value="0">
							<input type="checkbox" name="<?php echo HTML::chars($field); ?>" value="1" <?php echo ! empty($prefs[$field]) ? 'checked' : ''; ?>
							       class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600">
							<span class="min-w-0 flex-1">
								<span class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
									<i class="<?php echo HTML::chars((string) $info['icon']); ?> text-blue-500"></i>
									<?php echo HTML::chars((string) $info['label']); ?>
								</span>
								<span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
									<?php if ($key === 'email'): ?>
										<?php echo HTML::chars($state['dest'] !== '' ? $state['dest'] : 'Укажите почту в профиле'); ?>
									<?php elseif ( ! empty($info['bind'])): ?>
										<?php if ($state['status'] === 'active'): ?>
											<span class="text-emerald-600 dark:text-emerald-400"><i class="fas fa-link mr-1"></i>привязано: <?php echo HTML::chars($state['dest']); ?></span>
										<?php elseif ($state['status'] === 'pending'): ?>
											<span class="text-amber-600 dark:text-amber-400">ждите подтверждения в боте</span>
										<?php else: ?>
											<span class="text-gray-400">не привязано</span>
										<?php endif; ?>
									<?php else: ?>
										<?php echo HTML::chars($key === 'in_app' ? 'колокольчик в шапке сайта' : $state['dest']); ?>
									<?php endif; ?>
								</span>
							</span>
						</label>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- мессенджеры -->
				<?php if (isset($binding['telegram']) OR isset($binding['max'])): ?>
				<div class="prl-card p-0 mt-4">
					<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
						<h2 class="prl-h3">Привязка мессенджеров</h2>
						<p class="prl-hint mt-0.5">Код привязки живёт 15 минут</p>
					</div>
					<div class="space-y-3 px-4 py-4">
						<?php foreach (['telegram', 'max'] as $key):
							if ( ! isset($binding[$key])) continue;
							$b = $binding[$key];
						?>
						<div class="rounded-lg border border-gray-100 px-3 py-3 dark:border-gray-700/60" data-prl597-bind="<?php echo HTML::chars($key); ?>">
							<div class="flex items-center justify-between gap-2">
								<span class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
									<i class="<?php echo HTML::chars($b['icon']); ?> text-blue-500"></i><?php echo HTML::chars($b['label']); ?>
								</span>
								<?php if ($b['status'] === 'active'): ?>
								<button type="submit" form="prl597-unbind-<?php echo HTML::chars($key); ?>" class="text-xs font-semibold text-rose-500 hover:underline">Отвязать</button>
								<?php endif; ?>
							</div>
							<p class="prl-hint" data-prl597-bind-state><?php echo $b['status'] === 'active' ? 'Привязано: '.HTML::chars($b['destination']) : 'Не привязано'; ?></p>
							<?php if ($b['enabled'] AND $b['bot_url'] !== ''): ?>
							<button type="button" data-prl597-bind-btn="<?php echo HTML::chars($key); ?>" data-url="<?php echo HTML::chars($b['bot_url']); ?>"
							        class="prl-btn-process prl-btn-sm mt-2">
								<i class="fas fa-link mr-1.5"></i>Получить код привязки
							</button>
							<?php else: ?>
							<p class="prl-hint">Канал не настроен администратором</p>
							<?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<?php endif; ?>

				<!-- режим и тихие часы -->
				<div class="prl-card p-0 mt-4">
					<?php if (array_diff(array_keys($channels), ['in_app'])): ?>
					<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
						<h2 class="prl-h3">Когда присылать</h2>
						<p class="prl-hint mt-0.5">Про письма и мессенджеры. Срочное — ошибки и безопасность — приходит сразу, тихие часы и сводка его не задерживают; в колокольчике на сайте всё видно мгновенно.</p>
					</div>
					<div class="space-y-3 px-4 py-4">
						<label class="block">
							<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Сводка вместо отдельных сообщений</span>
							<select name="digest_mode" class="prl-input prl-select mt-1 w-full">
								<?php foreach (['off' => 'Каждое сообщение отдельно', 'hourly' => 'Раз в час', 'daily' => 'Раз в сутки'] as $mode => $label): ?>
								<option value="<?php echo $mode; ?>" <?php echo (string) $prefs['digest_mode'] === $mode ? 'selected' : ''; ?>><?php echo $label; ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<div class="grid grid-cols-2 gap-3">
							<label class="block">
								<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Не беспокоить с (час)</span>
								<input type="number" name="quiet_from" min="-1" max="23" value="<?php echo (int) $prefs['quiet_from']; ?>"
								       class="prl-input mt-1 w-full">
							</label>
							<label class="block">
								<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">до (час)</span>
								<input type="number" name="quiet_to" min="-1" max="23" value="<?php echo (int) $prefs['quiet_to']; ?>"
								       class="prl-input mt-1 w-full">
							</label>
						</div>
						<p class="prl-hint">Для почты и мессенджеров. −1 — настройки администратора; одинаковые часы — без паузы. Критичные уведомления приходят всегда.</p>
						<label class="block">
							<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Часовой пояс</span>
							<input type="text" name="tz" value="<?php echo HTML::chars((string) $prefs['tz']); ?>" placeholder="Europe/Moscow"
							       class="prl-input mt-1 w-full">
						</label>
					</div>
					<?php endif; ?>
					<div class="border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
						<button type="submit" class="prl-btn-cta w-full">
							<i class="fas fa-floppy-disk mr-2"></i>Сохранить настройки
						</button>
					</div>
				</div>
			</div>

			<!-- матрица событий -->
			<?php
			// Сколько клеток «событие × канал» человек отметил сам. Ноль при
			// включённом канале — частая ловушка («включил почту, а писем нет»),
			// поэтому говорим об этом прямо в шапке таблицы.
			$prl597_cells_on = 0;

			foreach ((array) $matrix as $prl597_rows)
			{
				foreach ((array) $prl597_rows as $prl597_ev)
				{
					foreach ((array) ($prl597_ev['state'] ?? []) as $prl597_cell)
					{
						if ($prl597_cell)
						{
							$prl597_cells_on++;
						}
					}
				}
			}
			?>
			<div class="lg:col-span-2">
				<div class="prl-card p-0">
					<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
						<h2 class="prl-h3">Что присылать</h2>
						<?php if ($prl597_channels_on > 0 AND $prl597_cells_on === 0): ?>
						<p class="mt-2 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-950/30 dark:text-amber-200">
							<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
							<span>Способ доставки включён, но ни одно событие не отмечено — присылать пока нечего. Отметьте нужные клетки в таблице.</span>
						</p>
						<?php endif; ?>
						<p class="prl-hint mt-0.5">Для колокольчика клетки отмечены, для писем и мессенджеров пусты — пустая клетка значит «нет». Отметьте нужное, изменения сохраняются сразу: включая клетку, вы включаете и сам способ доставки слева. Обязательные события отключить нельзя.</p>
					</div>

					<?php if ( ! $matrix): ?>
					<div class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">Для вашего аккаунта пока нет доступных событий.</div>
					<?php else: ?>
					<?php foreach ($matrix as $category => $rows): ?>
					<div class="border-b border-gray-100 last:border-0 dark:border-gray-700/60">
						<h3 class="bg-gray-50 px-4 py-2 text-xs font-bold uppercase tracking-wide text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
							<?php echo HTML::chars(Service_Prl597_Registry::category_label($category)); ?>
						</h3>
						<div class="overflow-x-auto">
							<table class="prl-table min-w-[460px]">
								<thead>
									<tr class="text-xs text-gray-500 dark:text-gray-400">
										<th class="px-4 py-2 text-left font-semibold">Событие</th>
										<?php foreach ($channels as $key => $info): ?>
										<th class="px-2 py-2 text-center font-semibold" title="<?php echo HTML::chars((string) $info['label']); ?>">
											<i class="<?php echo HTML::chars((string) $info['icon']); ?>"></i>
										</th>
										<?php endforeach; ?>
									</tr>
								</thead>
								<tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
									<?php foreach ($rows as $event): ?>
									<tr>
										<td class="px-4 py-2.5">
											<span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($event['title']); ?></span>
											<?php if ($event['description'] !== ''): ?>
											<span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($event['description']); ?></span>
											<?php endif; ?>
										</td>
										<?php foreach ($channels as $key => $info):
											$allowed = in_array((string) $key, $event['channels'], TRUE);
											$on      = ($allowed AND (bool) Arr::get($event['state'], $key, FALSE));
										?>
										<td class="px-2 py-2.5 text-center">
											<?php if ( ! $allowed): ?>
											<span class="text-gray-300 dark:text-gray-600">—</span>
											<?php else: ?>
											<button type="button"
											        class="prl597-cell inline-flex h-6 w-6 items-center justify-center rounded <?php echo $on ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40' : 'bg-gray-100 text-gray-400 dark:bg-gray-700'; ?>"
											        data-event="<?php echo HTML::chars($event['alias']); ?>" data-channel="<?php echo HTML::chars((string) $key); ?>"
											        data-csrf="<?php echo $csrf_h; ?>" data-on="<?php echo $on ? '1' : '0'; ?>"
											        data-locked="<?php echo $event['mandatory'] ? '1' : '0'; ?>"
											        title="<?php echo $event['mandatory'] ? 'Обязательное событие' : ($on ? 'Выключить' : 'Включить'); ?>">
												<i class="fas <?php echo $on ? 'fa-bell' : 'fa-bell-slash'; ?> text-xs"></i>
											</button>
											<?php endif; ?>
										</td>
										<?php endforeach; ?>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
					<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</form>
	<?php endif; ?>
</div>

<?php echo View::factory('597/script'); ?>
