<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prl120/budgets — бюджеты токенов (администратор): умолчания по ролям,
 * форма индивидуального бюджета, кто сегодня ближе к потолку, список выданных.
 */
$config    = isset($config) ? (array) $config : [];
$current   = isset($current) ? (array) $current : [];
$override  = (isset($override) AND is_array($override)) ? $override : [];
$key       = isset($key) ? (string) $key : '';
$overrides = isset($overrides) ? (array) $overrides : [];
$top       = isset($top) ? (array) $top : [];
$flash     = isset($flash) ? (string) $flash : '';
$fmt       = ['Service_Prl120_Budget', 'fmt'];
$owner_href = function ($k) {
	return preg_match('/^u:([0-9]+)$/', $k, $m) ? '/prl595/admin/user/'.(int) $m[1] : '';
};
$pct_cls = function ($st) {
	if ($st['over']) return 'prl-badge prl-badge-err';
	if ($st['warn']) return 'prl-badge prl-badge-run';
	return 'prl-badge prl-badge-ok';
};
?>
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/prl120/usage" class="transition hover:text-emerald-600 dark:hover:text-emerald-400">Ресурсы и потребление</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300">Бюджеты токенов</span>
	</nav>

	<div class="mt-2 flex flex-wrap items-center justify-between gap-2">
		<h1 class="prl-h1"><i class="fas fa-coins mr-2 text-emerald-600 dark:text-emerald-400"></i>Бюджеты токенов</h1>
		<span class="text-xs text-gray-500 dark:text-gray-400">
			запрет постановки задач: <b><?php echo ! empty($config['enforce']) ? 'включён' : 'выключен (PRL120_BUDGET_ENFORCE=0)'; ?></b>
			· предупреждение при <?php echo (int) Arr::get($config, 'warn_pct', 90); ?> %
		</span>
	</div>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Токены = промпт + ответ по всем каналам воркеров за календарный день и месяц (`prl_120_usage`). Роль даёт умолчание, индивидуальный бюджет сильнее. При <?php echo (int) Arr::get($config, 'warn_pct', 90); ?> % владелец получает уведомление, при 100 % очередь отвечает «бюджет исчерпан».</p>

	<?php if ($flash !== ''): ?>
	<div class="prl-flash prl-flash-info mt-4 block text-xs font-semibold"><?php echo HTML::chars($flash); ?></div>
	<?php endif; ?>

	<div class="mt-5 grid gap-4 lg:grid-cols-3">
		<!-- Умолчания по ролям -->
		<div class="prl-card p-0">
			<div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700/60"><h2 class="prl-h3">Умолчания по ролям</h2></div>
			<table class="prl-table">
				<thead><tr class="text-left text-xs text-gray-400"><th class="px-4 py-2">Роль</th><th class="px-4 py-2">День</th><th class="px-4 py-2">Месяц</th></tr></thead>
				<tbody class="text-sm text-gray-700 dark:text-gray-200">
					<?php foreach ((array) Arr::get($config, 'rank', []) as $role): ?>
					<tr class="border-t border-gray-100 dark:border-gray-800">
						<td class="px-4 py-2 font-mono text-xs"><?php echo HTML::chars($role); ?></td>
						<td class="px-4 py-2 tabular-nums"><?php $v = (int) Arr::path($config, 'daily.'.$role, 0); echo $v > 0 ? $fmt($v) : '∞'; ?></td>
						<td class="px-4 py-2 tabular-nums"><?php $v = (int) Arr::path($config, 'monthly.'.$role, 0); echo $v > 0 ? $fmt($v) : '∞'; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="prl-hint px-4 py-3">Меняются через env `PRL120_BUDGET_DAILY_&lt;ROLE&gt;` / `PRL120_BUDGET_MONTHLY_&lt;ROLE&gt;` (config/prl120_budget.php).</p>
		</div>

		<!-- Индивидуальный бюджет -->
		<div class="prl-card lg:col-span-2">
			<h2 class="prl-h3">Индивидуальный бюджет</h2>
			<form method="post" action="/prl120/budgets" class="mt-3 grid gap-3 sm:grid-cols-2">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<label class="block text-xs sm:col-span-2">
					<span class="font-semibold text-gray-600 dark:text-gray-300">Владелец — <span class="font-mono">u:&lt;id&gt;</span> или кука гостя</span>
					<input type="text" name="key" value="<?php echo HTML::chars($key); ?>" required maxlength="64" class="prl-input mt-1 w-full font-mono" placeholder="u:12">
				</label>
				<label class="block text-xs">
					<span class="font-semibold text-gray-600 dark:text-gray-300">Токенов в день</span>
					<input type="number" name="daily" min="-1" step="1000" value="<?php echo (int) Arr::get($override, 'daily_tokens', 0); ?>" class="prl-input mt-1 w-full tabular-nums">
				</label>
				<label class="block text-xs">
					<span class="font-semibold text-gray-600 dark:text-gray-300">Токенов в месяц</span>
					<input type="number" name="monthly" min="-1" step="10000" value="<?php echo (int) Arr::get($override, 'monthly_tokens', 0); ?>" class="prl-input mt-1 w-full tabular-nums">
				</label>
				<label class="block text-xs sm:col-span-2">
					<span class="font-semibold text-gray-600 dark:text-gray-300">Заметка (видна владельцу на /tasks)</span>
					<input type="text" name="note" value="<?php echo HTML::chars((string) Arr::get($override, 'note', '')); ?>" maxlength="200" class="prl-input mt-1 w-full" placeholder="Расширено по договорённости до конца месяца">
				</label>
				<p class="prl-hint sm:col-span-2">0 — как у роли, −1 — без лимита. Обе «как у роли» ⇒ индивидуальная строка удаляется.</p>
				<div class="flex flex-wrap gap-2 sm:col-span-2">
					<button type="submit" class="prl-btn-cta prl-btn-sm">Сохранить бюджет</button>
					<?php if ($key !== '' AND $owner_href($key) !== ''): ?>
					<a href="<?php echo HTML::chars($owner_href($key)); ?>" class="prl-btn-secondary prl-btn-sm">Центр контроля владельца</a>
					<?php endif; ?>
				</div>
			</form>
			<?php if ($current): ?>
			<div class="mt-4 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
				<?php echo View::factory('120/_budget', ['budget' => $current, 'compact' => TRUE])->render(); ?>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- Кто сегодня ближе к потолку -->
	<div class="prl-card p-0 mt-4 overflow-x-auto">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
			<h2 class="prl-h3">Сегодня: кто ближе к потолку</h2>
			<span class="text-xs text-gray-400">топ‑<?php echo count($top); ?> по расходу за день</span>
		</div>
		<?php if ( ! $top): ?>
		<p class="prl-hint px-4 py-4">Сегодня задач с отчётом о токенах ещё не было.</p>
		<?php else: ?>
		<table class="prl-table whitespace-nowrap">
			<thead><tr class="text-left text-xs text-gray-400">
				<th class="px-4 py-2">Кто</th><th class="px-4 py-2">Роль</th><th class="px-4 py-2">Задач</th>
				<th class="px-4 py-2">День</th><th class="px-4 py-2">Месяц</th><th class="px-4 py-2">Доля</th><th class="px-4 py-2"></th>
			</tr></thead>
			<tbody class="text-sm text-gray-700 dark:text-gray-200">
				<?php foreach ($top as $r): $st = $r['state']; ?>
				<tr class="border-t border-gray-100 dark:border-gray-800">
					<td class="px-4 py-2 font-semibold">
						<?php if ($owner_href($r['visitor_id']) !== ''): ?><a href="<?php echo HTML::chars($owner_href($r['visitor_id'])); ?>" class="prl-link"><?php echo HTML::chars((string) $r['who']); ?></a>
						<?php else: ?><?php echo HTML::chars((string) $r['who']); ?><?php endif; ?>
					</td>
					<td class="px-4 py-2 font-mono text-xs"><?php echo HTML::chars($st['role']); ?><?php echo $st['source'] === 'override' ? ' · инд.' : ''; ?></td>
					<td class="px-4 py-2 tabular-nums"><?php echo (int) $r['jobs']; ?></td>
					<td class="px-4 py-2 tabular-nums"><?php echo $fmt($st['daily']['spent']); ?><?php echo $st['daily']['limit'] > 0 ? ' / '.$fmt($st['daily']['limit']) : ''; ?></td>
					<td class="px-4 py-2 tabular-nums"><?php echo $fmt($st['monthly']['spent']); ?><?php echo $st['monthly']['limit'] > 0 ? ' / '.$fmt($st['monthly']['limit']) : ''; ?></td>
					<td class="px-4 py-2"><span class="<?php echo $pct_cls($st); ?>"><?php echo ($st['daily']['limit'] > 0 OR $st['monthly']['limit'] > 0) ? (int) $st['pct'].' %' : '∞'; ?></span></td>
					<td class="px-4 py-2 text-right"><a href="/prl120/budgets?key=<?php echo rawurlencode($r['visitor_id']); ?>" class="prl-link text-xs">бюджет</a></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>

	<!-- Индивидуальные бюджеты -->
	<div class="prl-card p-0 mt-4 overflow-x-auto">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
			<h2 class="prl-h3">Выданные индивидуальные бюджеты</h2>
			<span class="text-xs text-gray-400"><?php echo count($overrides); ?></span>
		</div>
		<?php if ( ! $overrides): ?>
		<p class="prl-hint px-4 py-4">Индивидуальных бюджетов нет — все живут по ролям.</p>
		<?php else: ?>
		<table class="prl-table whitespace-nowrap">
			<thead><tr class="text-left text-xs text-gray-400">
				<th class="px-4 py-2">Кто</th><th class="px-4 py-2">День</th><th class="px-4 py-2">Месяц</th><th class="px-4 py-2">Сейчас</th><th class="px-4 py-2">Заметка</th><th class="px-4 py-2">Изменён</th><th class="px-4 py-2"></th>
			</tr></thead>
			<tbody class="text-sm text-gray-700 dark:text-gray-200">
				<?php foreach ($overrides as $r): $st = $r['state']; ?>
				<tr class="border-t border-gray-100 dark:border-gray-800">
					<td class="px-4 py-2 font-semibold"><?php echo HTML::chars((string) $r['who']); ?> <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars((string) $r['visitor_id']); ?></span></td>
					<td class="px-4 py-2 tabular-nums"><?php $v = (int) $r['daily_tokens']; echo $v === -1 ? '∞' : ($v === 0 ? 'по роли' : $fmt($v)); ?></td>
					<td class="px-4 py-2 tabular-nums"><?php $v = (int) $r['monthly_tokens']; echo $v === -1 ? '∞' : ($v === 0 ? 'по роли' : $fmt($v)); ?></td>
					<td class="px-4 py-2"><span class="<?php echo $pct_cls($st); ?>"><?php echo ($st['daily']['limit'] > 0 OR $st['monthly']['limit'] > 0) ? (int) $st['pct'].' %' : '∞'; ?></span></td>
					<td class="max-w-[16rem] truncate px-4 py-2 text-xs" title="<?php echo HTML::chars((string) $r['note']); ?>"><?php echo HTML::chars((string) $r['note']); ?></td>
					<td class="px-4 py-2 text-xs text-gray-400"><?php echo HTML::chars(substr((string) $r['updated_at'], 0, 16)); ?></td>
					<td class="px-4 py-2 text-right"><a href="/prl120/budgets?key=<?php echo rawurlencode($r['visitor_id']); ?>" class="prl-link text-xs">изменить</a></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
</div>
