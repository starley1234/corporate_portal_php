<?php defined('SYSPATH') or die('No direct script access.'); ?>

	<!-- ================= СВОДКА ================= -->
	<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
		<?php
		// Карточки — ссылки на разбор: владелец (16.10.2026) спросил «в очереди 10 —
		// как посмотреть, кто и почему?». Теперь каждая цифра ведёт на свой список,
		// а «отправлено за сутки» считает каналы: рассылка в колокольчик идёт мимо
		// очереди и показана отдельной карточкой «в ленту».
		$kpi = [
			['fas fa-hourglass-half text-amber-500', 'В очереди', (int) Arr::get($stats, 'pending', 0), '/prx597/admin?tab=queue#queue', 'кто ждёт отправки и почему'],
			['fas fa-hourglass-end text-amber-500', 'Отложено', (int) Arr::get($stats, 'deferred', 0), '/prx597/admin?tab=queue#queue', 'пауза: тихие часы, лимит или повтор'],
			['fas fa-skull text-rose-500', 'В DLQ', (int) Arr::get($stats, 'dead', 0), '/prx597/admin?tab=journal', 'не дошло ни с одной попытки'],
			['fas fa-circle-check text-emerald-500', 'Отправлено за сутки', (int) Arr::get($stats, 'sent_today', 0), '/prx597/admin?tab=queue#sent', 'каналы: письма и мессенджеры'],
			['fas fa-bell text-blue-500', 'В ленту за сутки', (int) Arr::get($stats, 'inbox_24h', 0), '/prx597/admin?tab=queue#feed', 'колокольчик: рассылки и сообщения'],
		];
		foreach ($kpi as $row): ?>
		<a href="<?php echo $row[3]; ?>" class="prx-card block transition hover:border-blue-300 hover:bg-blue-50/40 dark:hover:border-blue-700 dark:hover:bg-blue-950/20">
			<i class="<?php echo $row[0]; ?> mb-2 text-xl"></i>
			<div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $row[2]; ?></div>
			<div class="text-xs font-semibold text-gray-600 dark:text-gray-300"><?php echo $row[1]; ?></div>
			<div class="mt-0.5 text-xs text-gray-400 dark:text-gray-500"><?php echo $row[4]; ?> <i class="fas fa-arrow-right ml-0.5 text-[10px]"></i></div>
		</a>
		<?php endforeach; ?>
	</div>

	<div class="mt-4 grid gap-4 lg:grid-cols-2">
		<!-- каналы -->
		<div class="prx-card p-0">
			<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
				<h2 class="prx-h3">Каналы</h2>
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
		<div class="prx-card p-0">
			<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
				<h2 class="prx-h3">Привязки и боты</h2>
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
						<span class="prx-count">режим: <?php echo HTML::chars($bot['mode']); ?></span>
					</div>
					<code class="mt-1 block break-all text-xs text-gray-400"><?php echo HTML::chars($bot['url']); ?></code>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>

	<!-- объявление -->
	<div class="prx-card p-0 mt-4">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prx-h3">Объявление всем</h2>
			<p class="prx-hint mt-0.5">Только внутренние уведомления — тем, у кого включён колокольчик. Без почты и мессенджеров.</p>
		</div>
		<form autocomplete="off" method="post" action="/prx597/admin" class="grid gap-3 px-4 py-4 sm:grid-cols-2">
			<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
			<input type="hidden" name="act" value="notify_all">
			<input type="hidden" name="tab" value="summary">
			<label class="sm:col-span-2 block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Заголовок</span>
				<input type="text" name="title" required maxlength="180" class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="sm:col-span-2 block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Текст</span>
				<textarea autocomplete="off" name="body" rows="2" maxlength="1000" class="prx-textarea mt-1 w-full"></textarea>
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Ссылка</span>
				<input type="text" name="href" value="/prx597" class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Не больше получателей</span>
				<input type="number" name="limit" value="200" min="1" max="5000" class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<div class="sm:col-span-2">
				<button type="submit" class="prx-btn-cta"><i class="fas fa-bullhorn mr-2"></i>Отправить</button>
			</div>
		</form>
	</div>

	<!-- обслуживание -->
	<div class="prx-card p-0 mt-4">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prx-h3">Обслуживание</h2>
		</div>
		<div class="flex flex-wrap gap-2 px-4 py-4">
			<form autocomplete="off" method="post" action="/prx597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="digest">
				<input type="hidden" name="tab" value="summary">
				<button type="submit" class="prx-btn-secondary prx-btn-sm">Разослать сводки</button>
			</form>
			<form autocomplete="off" method="post" action="/prx597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="resend_failed">
				<input type="hidden" name="tab" value="summary">
				<button type="submit" class="prx-btn-secondary prx-btn-sm">Вернуть упавшие в очередь</button>
			</form>
			<form autocomplete="off" method="post" action="/prx597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="reset_limits">
				<input type="hidden" name="tab" value="summary">
				<button data-prx-arm="Сбросить?" type="submit" class="prx-btn-secondary prx-btn-sm">Сбросить счётчики лимитов</button>
			</form>
			<form autocomplete="off" method="post" action="/prx597/admin">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="cleanup">
				<input type="hidden" name="tab" value="summary">
				<button data-prx-arm="Удалить старое?" type="submit" class="prx-btn-danger prx-btn-sm">Очистить старое</button>
			</form>
			<a href="/prx597/admin/preview" target="_blank" class="prx-btn-secondary prx-btn-sm">Предпросмотр письма-сводки</a>
		</div>
	</div>
