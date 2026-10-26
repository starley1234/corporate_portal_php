<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Карточка «Бюджет токенов» владельца: день и месяц, доля — числом и точками
 * (полос нет нигде). Данные — Service_Prl120_Budget::state(). Параметры:
 * $budget (state), $compact (без заголовка карточки), $admin_href (ссылка
 * на правку — только администратору).
 */
$budget     = isset($budget) ? (array) $budget : [];
$compact    = ! empty($compact);
$admin_href = isset($admin_href) ? (string) $admin_href : '';
$fmt        = ['Service_Prl120_Budget', 'fmt'];
$tone       = function ($pct, $limit) {
	if ($limit <= 0) return 'text-gray-500 dark:text-gray-400';
	if ($pct >= 100) return 'text-rose-600 dark:text-rose-400';
	if ($pct >= 90)  return 'text-amber-600 dark:text-amber-400';
	return 'text-emerald-600 dark:text-emerald-400';
};
$dots = function ($pct, $limit) {
	$on  = $limit > 0 ? min(10, (int) ceil($pct / 10)) : 0;
	$out = '';
	for ($i = 0; $i < 10; $i++)
	{
		$cls = $i < $on ? ($pct >= 100 ? 'bg-rose-500' : ($pct >= 90 ? 'bg-amber-500' : 'bg-emerald-500')) : 'bg-gray-200 dark:bg-gray-700';
		$out .= '<i class="inline-block h-1.5 w-1.5 rounded-full '.$cls.'"></i>';
	}
	return $out;
};
if ( ! $budget) { return; }
?>
<div class="<?php echo $compact ? '' : 'prl-card'; ?>" data-prl120-budget>
	<?php if ( ! $compact): ?>
	<div class="flex flex-wrap items-center justify-between gap-2">
		<h2 class="prl-h3"><i class="fas fa-coins mr-1.5 text-blue-500"></i>Бюджет токенов</h2>
		<span class="text-xs text-gray-400"><?php echo $budget['source'] === 'override' ? 'индивидуальный' : 'по роли '.HTML::chars($budget['role']); ?></span>
	</div>
	<?php endif; ?>
	<dl class="<?php echo $compact ? '' : 'mt-3 '; ?>grid grid-cols-2 gap-3 text-xs">
		<?php foreach (['daily' => 'Сегодня', 'monthly' => 'Этот месяц'] as $k => $label): $b = $budget[$k]; ?>
		<div>
			<dt class="font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400"><?php echo $label; ?></dt>
			<dd class="mt-0.5 text-lg font-black tabular-nums tracking-tight <?php echo $tone($b['pct'], $b['limit']); ?>">
				<?php echo $b['limit'] > 0 ? (int) $b['pct'].' %' : '∞'; ?>
			</dd>
			<dd class="text-gray-600 dark:text-gray-300">
				<?php echo $fmt($b['spent']); ?><?php echo $b['limit'] > 0 ? ' из '.$fmt($b['limit']) : ' · без лимита'; ?>
			</dd>
			<?php if ($b['limit'] > 0): ?>
			<dd class="mt-1 flex gap-0.5" title="<?php echo (int) $b['pct']; ?> % бюджета"><?php echo $dots($b['pct'], $b['limit']); ?></dd>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	</dl>
	<?php if ($budget['over']): ?>
	<p class="prl-flash prl-flash-err mt-3 block text-xs">Бюджет исчерпан — новые задачи очередь не принимает<?php echo $budget['role'] === 'anon' ? '. Войдите в аккаунт: у аккаунта бюджет больше' : ''; ?>.</p>
	<?php elseif ($budget['warn']): ?>
	<p class="prl-flash prl-flash-warn mt-3 block text-xs">Осталось меньше <?php echo 100 - (int) $budget['pct']; ?> % бюджета.</p>
	<?php endif; ?>
	<?php if ($budget['note'] !== ''): ?>
	<p class="prl-hint mt-2"><?php echo HTML::chars($budget['note']); ?></p>
	<?php endif; ?>
	<?php if ($admin_href !== ''): ?>
	<p class="mt-2 text-xs"><a href="<?php echo HTML::chars($admin_href); ?>" class="prl-link">Изменить бюджет</a></p>
	<?php endif; ?>
</div>
