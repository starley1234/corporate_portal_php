<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление центром уведомлений (prx597): /prx597/admin.
 *
 * Вкладки: сводка, очередь, сообщение, события, шаблоны, каналы, лимиты, журнал.
 * Опасные кнопки (чистка, DLQ, сброс счётчиков) — вторым нажатием:
 * data-prx-arm на кнопке (общий app.js).
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
		'sent'    => ['prx-badge-ok', 'отправлено'],
		'pending' => ['prx-badge-wait', 'в очереди'],
		'sending' => ['prx-badge-run', 'отправляется'],
		'deferred'=> ['prx-badge-run', 'отложено'],
		'skipped' => ['prx-badge-wait', 'пропущено'],
		'failed'  => ['prx-badge-err', 'ошибка'],
		'dead'    => ['prx-badge-err', 'в DLQ'],
	];

	return $map[$status] ?? $map['skipped'];
};
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php echo View::factory('partials/page_header', [
		'crumbs'  => [['Администрирование', '/admin'], ['Уведомления', '/prx597_notifications'], ['Управление']],
		'title'   => 'Управление',
		'pill'    => ['prx597 · рассылка', 'fa-bell-concierge'],
		'actions' => [
			['html' => '<form autocomplete="off" method="post" action="/prx597/admin"><input type="hidden" name="csrf" value="'.$csrf_h.'"><input type="hidden" name="act" value="run"><input type="hidden" name="tab" value="'.HTML::chars($tab).'"><button type="submit" class="prx-btn-process prx-btn-sm"><i class="fas fa-play mr-1.5"></i>Доставить сейчас</button></form>'],
			['label' => 'Мои уведомления', 'href' => '/prx597_notifications', 'icon' => 'fa-bell', 'role' => 'secondary'],
		],
	]); ?>

	<?php if ((string) $flash !== ''): ?>
	<div class="prx-flash prx-flash-info mt-3" role="status">
		<i class="fas fa-circle-info shrink-0"></i><span><?php echo HTML::chars($flash); ?></span>
	</div>
	<?php endif; ?>

	<div class="mt-4 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-700">
		<?php foreach ($tabs as $key => $info): ?>
		<a href="/prx597/admin?tab=<?php echo $key; ?>" class="inline-flex min-h-[40px] items-center gap-2 whitespace-nowrap border-b-2 px-3 text-sm font-semibold transition <?php echo $tab === $key ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400'; ?>">
			<i class="<?php echo $info[0]; ?>"></i><?php echo $info[1]; ?>
			<?php if ($key === 'journal' AND ! empty($stats['failed'])): ?>
			<span class="prx-badge prx-badge-err"><span class="prx-dot"></span><?php echo (int) $stats['failed']; ?></span>
			<?php endif; ?>
		</a>
		<?php endforeach; ?>
	</div>

<?php if ($tab === 'summary'): ?>
<?php include __DIR__.'/partials/summary.php'; ?>
<?php endif; ?>

<?php if ($tab === 'queue'): ?>
<?php include __DIR__.'/partials/queue.php'; ?>
<?php endif; ?>

<?php if ($tab === 'events'): ?>
<?php include __DIR__.'/partials/events.php'; ?>
<?php endif; ?>

<?php if ($tab === 'message'): ?>
<?php include __DIR__.'/partials/message.php'; ?>
<?php endif; ?>

<?php if ($tab === 'templates'): ?>
<?php include __DIR__.'/partials/templates.php'; ?>
<?php endif; ?>

<?php if ($tab === 'channels'): ?>
<?php include __DIR__.'/partials/channels.php'; ?>
<?php endif; ?>

<?php if ($tab === 'limits'): ?>
<?php include __DIR__.'/partials/limits.php'; ?>
<?php endif; ?>

<?php if ($tab === 'journal'): ?>
<?php include __DIR__.'/partials/journal.php'; ?>
<?php endif; ?>

</div>

<?php echo View::factory('prx597/script'); ?>
