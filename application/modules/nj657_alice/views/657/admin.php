<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/** @var array $stats @var int $total_dialogs @var int $day_dialogs @var string $webhook_url @var bool $mqtt_enabled @var string $csrf @var string $notice @var string $error */
$word = function ($n, $one, $few, $many)
{
	$n = (int) $n;
	$mod10 = $n % 10;
	$mod100 = $n % 100;

	if ($mod10 === 1 AND $mod100 !== 11)
	{
		return $one;
	}

	if ($mod10 >= 2 AND $mod10 <= 4 AND ($mod100 < 12 OR $mod100 > 14))
	{
		return $few;
	}

	return $many;
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-microphone-lines text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Навыки Алисы · управление</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Каждый навык — отдельный диалог из консоли Яндекс.Диалогов: своя роль, память, кнопки и вебхук. Общий адрес: <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-semibold text-gray-800 dark:bg-gray-900 dark:text-blue-300"><?php echo HTML::chars($webhook_url); ?></code></p>
		</div>
		<a href="/alice/admin/skill" class="n2j-btn-cta"><i class="fas fa-plus mr-2" aria-hidden="true"></i>Новый навык</a>
	</div>

	<?php if ($notice): ?>
		<p class="n2j-flash n2j-flash-ok mt-5 block"><?php echo HTML::chars($notice); ?></p>
	<?php endif; ?>
	<?php if ($error): ?>
		<p class="n2j-flash n2j-flash-err mt-5 block"><?php echo HTML::chars($error); ?></p>
	<?php endif; ?>

	<div class="mt-6 grid gap-3 sm:grid-cols-3">
		<div class="n2j-card"><p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Навыков</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo count($stats); ?></p></div>
		<div class="n2j-card"><p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Реплик за сутки</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($day_dialogs, 0, ',', ' '); ?></p></div>
		<div class="n2j-card"><p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Реплик всего</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($total_dialogs, 0, ',', ' '); ?></p></div>
	</div>

	<div class="mt-6 space-y-4">
		<?php if ( ! $stats): ?>
			<div class="rounded-xl border border-dashed border-blue-200 bg-blue-50/60 p-6 text-center dark:border-blue-900 dark:bg-blue-950/20">
				<i class="fas fa-microphone-lines text-2xl text-blue-500" aria-hidden="true"></i>
				<p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Навыков ещё нет</p>
				<p class="n2j-hint mx-auto max-w-md">Создайте первый навык, задайте роль пресетом и укажите вебхук в консоли Яндекс.Диалогов — Алиса начнёт отвечать сразу.</p>
			</div>
		<?php else: ?>
			<?php foreach ($stats as $row): ?>
				<div class="n2j-card">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div class="min-w-0">
							<div class="flex flex-wrap items-center gap-2">
								<h2 class="n2j-h3"><?php echo HTML::chars($row['title']); ?></h2>
								<?php if ($row['is_default']): ?><span class="n2j-badge n2j-badge-info">по умолчанию</span><?php endif; ?>
								<?php if ( ! $row['is_active']): ?><span class="n2j-badge n2j-badge-run">выключен</span><?php endif; ?>
								<?php if ($row['mqtt_topic'] !== ''): ?><span class="n2j-badge n2j-badge-wait" title="<?php echo HTML::chars($row['mqtt_topic']); ?>"><i class="fas fa-house-signal mr-1" aria-hidden="true"></i>MQTT<?php if ( ! $mqtt_enabled): ?> (выключен в окружении)<?php endif; ?></span><?php endif; ?>
							</div>
							<p class="n2j-hint">
								<?php echo (int) $row['dialogs'] > 0
									? number_format((int) $row['dialogs'], 0, ',', ' ').' '.$word((int) $row['dialogs'], 'реплика', 'реплики', 'реплик').' всего · за сутки: '.(int) $row['day_dialogs']
									: 'реплик ещё не было'; ?>
								<?php if ($row['last_at']): ?> · последняя: <?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $row['last_at']))); ?><?php endif; ?>
							</p>
						</div>
						<div class="flex flex-wrap items-center gap-2">
							<form method="post" action="/alice/admin/toggle" class="inline">
								<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
								<input type="hidden" name="code" value="<?php echo HTML::chars($row['code']); ?>">
								<?php if ($row['is_active']): ?>
									<input type="hidden" name="active" value="0">
									<button type="submit" class="n2j-btn-secondary n2j-btn-sm">Выключить</button>
								<?php else: ?>
									<input type="hidden" name="active" value="1">
									<button type="submit" class="n2j-btn-secondary n2j-btn-sm">Включить</button>
								<?php endif; ?>
							</form>
							<a href="/alice/admin/skill/<?php echo HTML::chars($row['code']); ?>" class="n2j-btn-secondary n2j-btn-sm">Настроить</a>
						</div>
					</div>
					<div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
						<span>Вебхук: <code class="break-all font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($row['webhook_token'] !== '' ? $webhook_url.'/'.$row['webhook_token'] : $webhook_url); ?></code></span>
						<span>Привязка: <?php echo $row['yandex_skill_id'] !== '' ? HTML::chars($row['yandex_skill_id']) : 'любой запрос (по умолчанию)'; ?></span>
						<a class="font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300" href="/alice/admin/history?skill=<?php echo (int) $row['id']; ?>">журнал →</a>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>
<?php echo View::factory('657/script'); ?>
