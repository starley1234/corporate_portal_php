<?php defined('SYSPATH') or die('No direct script access.'); ?>

	<!-- ================= КАНАЛЫ ================= -->
	<div class="mt-5 grid gap-4 lg:grid-cols-2">
		<?php foreach ($channels as $row): ?>
		<div class="prx-card p-0">
			<div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
				<h2 class="prx-h3"><i class="<?php echo HTML::chars($row['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars($row['label']); ?></h2>
				<span class="prx-badge <?php echo $row['enabled'] ? 'prx-badge-ok' : 'prx-badge-wait'; ?>"><span class="prx-dot"></span><?php echo $row['enabled'] ? 'настроен' : 'не настроен / выключен'; ?></span>
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
				<p>Для показа пользователям нужны токен и имя бота (<code>PRX597_NOTIFICATIONS_TELEGRAM_BOT_NICK</code> или <code>PRX597_NOTIFICATIONS_MAX_BOT_NICK</code>), режим не «выключен». Проверьте привязку и доставку — статус «настроен» не гарантирует связь с сервисом.</p>
				<div class="mt-2 flex flex-wrap gap-2">
					<form autocomplete="off" method="post" action="/prx597/admin" class="flex flex-wrap items-end gap-2">
						<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
						<input type="hidden" name="act" value="bot_mode">
						<input type="hidden" name="tab" value="channels">
						<input type="hidden" name="channel" value="<?php echo HTML::chars($row['key']); ?>">
						<select name="mode" class="prx-input prx-select py-1 text-xs min-h-0 w-auto">
							<?php foreach (['off' => 'выключен', 'webhook' => 'вебхук', 'poll' => 'опрос'] as $mode => $label): ?>
							<option value="<?php echo $mode; ?>" <?php echo $row['bot']['mode'] === $mode ? 'selected' : ''; ?>><?php echo $label; ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="prx-btn-secondary prx-btn-sm">Задать режим</button>
					</form>
					<form autocomplete="off" method="post" action="/prx597/admin">
						<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
						<input type="hidden" name="act" value="webhook">
						<input type="hidden" name="tab" value="channels">
						<input type="hidden" name="channel" value="<?php echo HTML::chars($row['key']); ?>">
						<button type="submit" class="prx-btn-secondary prx-btn-sm">Зарегистрировать вебхук</button>
					</form>
				</div>
				<?php endif; ?>
			</div>
			<form autocomplete="off" method="post" action="/prx597/admin" class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<input type="hidden" name="act" value="channel_test">
				<input type="hidden" name="tab" value="channels">
				<input type="hidden" name="channel" value="<?php echo HTML::chars($row['key']); ?>">
				<input type="text" name="to" placeholder="<?php echo $row['key'] === 'in_app' ? 'не нужно' : ($row['key'] === 'email' ? 'you@example.com' : 'chat_id или id пользователя'); ?>"
				       class="prx-input min-w-[160px] flex-1 py-1.5 text-xs min-h-0" autocomplete="off">
				<button type="submit" class="prx-btn-process prx-btn-sm"><i class="fas fa-paper-plane mr-1.5"></i>Пробное</button>
			</form>
		</div>
		<?php endforeach; ?>
	</div>

	<div class="prx-card mt-4 text-xs text-gray-500 dark:text-gray-400">
		<h3 class="mb-1 text-sm font-bold text-gray-900 dark:text-white">Подсказка</h3>
		<p>Токены ботов читаются из <code>.env</code>: <code>PRX597_NOTIFICATIONS_TELEGRAM_BOT_TOKEN</code>, <code>PRX597_NOTIFICATIONS_MAX_BOT_TOKEN</code>, <code>PRX597_NOTIFICATIONS_MAX_BUSINESS_KEY</code>. В базе они не хранятся — так секреты не утекут вместе с дампом.</p>
		<p class="mt-1">Для MAX администратор выбирает драйвер: обычный бот (<code>bot</code>) или бизнес-API (<code>business</code>) — переключатель на вкладке «Лимиты».</p>
		<form autocomplete="off" method="post" action="/prx597/admin" class="mt-3 flex flex-wrap items-center gap-2">
			<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
			<input type="hidden" name="act" value="test_email">
			<input type="hidden" name="tab" value="channels">
			<input type="email" name="to" placeholder="you@example.com — пусто = ваш аккаунт" class="prx-input min-w-[200px] flex-1 py-1.5 text-xs min-h-0" autocomplete="off">
			<button type="submit" class="prx-btn-secondary prx-btn-sm"><i class="fas fa-envelope mr-1.5"></i>Тестовое письмо</button>
		</form>
	</div>
