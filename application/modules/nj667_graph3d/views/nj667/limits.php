<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Лимиты пользователей» (nj667): индивидуальные квоты модератором.
 *
 * Плотная таблица, а не карточки: список рассчитан на сотни людей, и модератору
 * нужно сравнивать их взглядом, а не листать карточки. Поиск, порядок и размер
 * страницы живут в адресе — ссылку на нужный срез можно передать коллеге.
 *
 * В полях — текущие effective_limits пользователя; память форм их не заменяет.
 * Пустое поле = наследуем лимит плана, ноль = операция запрещена. Значение
 * применяет серверный резолвер квот (Service_Nj667_Graph3d::quota_limits_resolve).
 */

$users = isset($users) ? (array) $users : [];
$query = isset($query) ? (string) $query : '';
$page  = isset($page) ? max(1, (int) $page) : 1;
$limit = isset($limit) ? max(1, (int) $limit) : 30;
$total = isset($total) ? max(0, (int) $total) : 0;
$error = isset($error) ? (string) $error : '';
$saved = ! empty($saved);
$csrf  = isset($csrf) ? (string) $csrf : '';
$sorts = isset($sorts) ? (array) $sorts : Service_Nj667_Graph3d::quota_sorts();
$sort  = isset($sort) ? (string) $sort : 'new';

if ( ! isset($sorts[$sort]))
{
	$sort = 'new';
}

$pages  = max(1, (int) ceil($total / $limit));
$page   = min($page, $pages);
$from   = $total > 0 ? ($page - 1) * $limit + 1 : 0;
$to     = min($total, $page * $limit);
$base   = '/graph3d/limits';
$per    = [30 => '30', 50 => '50', 100 => '100', 200 => '200'];

// Ссылка на тот же срез: поиск, порядок и размер страницы не теряются.
$link = function (array $over = []) use ($base, $query, $page, $sort, $limit)
{
	$params = array_merge(['q' => $query, 'sort' => $sort, 'per' => $limit, 'page' => $page], $over);
	$params = array_filter($params, function ($value) { return $value !== '' AND $value !== NULL; });

	return $base.'?'.http_build_query($params);
};

// Полоска заполнения: ширина — шагами (без inline-стилей), цвет — по остатку.
$bar = function ($used, $limit)
{
	$limit = max(0, (int) $limit);
	$used  = max(0, (int) $used);
	$pct   = $limit > 0 ? min(100, (int) round($used * 100 / $limit)) : ($used > 0 ? 100 : 0);
	$tone  = ($limit > 0 AND $used >= $limit) ? 'bg-rose-500' : ($pct >= 80 ? 'bg-amber-500' : 'bg-emerald-500');
	$width = $pct >= 100 ? 'w-full' : ($pct >= 75 ? 'w-3/4' : ($pct >= 50 ? 'w-1/2' : ($pct >= 25 ? 'w-1/4' : 'w-0')));

	return '<span class="inline-flex items-center gap-2 whitespace-nowrap">'
		.'<span class="font-semibold tabular-nums text-gray-900 dark:text-white">'.$used.'</span>'
		.'<span class="text-gray-400">/</span>'
		.'<span class="tabular-nums text-gray-500 dark:text-gray-400">'.$limit.'</span>'
		.'<span class="hidden h-1.5 w-14 overflow-hidden rounded-full bg-gray-200 sm:inline-block dark:bg-gray-700">'
		.'<span class="block h-full '.$tone.' '.$width.'"></span></span>'
		.'</span>';
};

$pager = function () use ($page, $pages, $link)
{
	if ($pages <= 1)
	{
		return '';
	}

	$html = '<nav class="flex items-center gap-2 text-xs" aria-label="Страницы">';

	if ($page > 1)
	{
		$html .= '<a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="'.HTML::chars($link(['page' => $page - 1])).'">← Назад</a>';
	}

	$html .= '<span class="tabular-nums text-gray-500 dark:text-gray-400">'.$page.' / '.$pages.'</span>';

	if ($page < $pages)
	{
		$html .= '<a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="'.HTML::chars($link(['page' => $page + 1])).'">Дальше →</a>';
	}

	return $html.'</nav>';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-end justify-between gap-3">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-gauge-high mr-2 text-blue-500"></i>Лимиты пользователей</h1>
			<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Индивидуальные квоты поверх плана: пустое поле наследует план, ноль запрещает операцию. Настройки конструктора — в разделе <a href="/graph3d/settings" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Настройки</a>.</p>
		</div>
		<a href="/graph3d/settings" class="n2j-btn-secondary">Настройки модуля</a>
	</div>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php elseif ($saved): ?>
	<div class="n2j-flash n2j-flash-ok mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-check mt-0.5 shrink-0"></i>
		<span>Лимиты сохранены и уже учитываются сервером.</span>
	</div>
	<?php endif; ?>

	<form method="get" action="<?php echo HTML::chars($base); ?>" class="mt-5 flex flex-wrap items-end gap-2">
		<label class="min-w-[12rem] flex-1" for="nj667-limit-q">
			<span class="block text-xs font-semibold text-gray-700 dark:text-gray-300">Поиск</span>
			<input id="nj667-limit-q" type="search" name="q" value="<?php echo HTML::chars($query); ?>" maxlength="120"
				placeholder="Имя, email или ID пользователя"
				class="n2j-input mt-1 w-full">
		</label>
		<label for="nj667-limit-sort">
			<span class="block text-xs font-semibold text-gray-700 dark:text-gray-300">Порядок</span>
			<select id="nj667-limit-sort" name="sort" class="n2j-input n2j-select mt-1 w-auto">
				<?php foreach ($sorts as $key => $label): ?>
				<option value="<?php echo HTML::chars((string) $key); ?>" <?php echo (string) $key === $sort ? 'selected' : ''; ?>><?php echo HTML::chars((string) $label); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label for="nj667-limit-per">
			<span class="block text-xs font-semibold text-gray-700 dark:text-gray-300">На странице</span>
			<select id="nj667-limit-per" name="per" class="n2j-input n2j-select mt-1 w-auto">
				<?php foreach ($per as $value => $label): ?>
				<option value="<?php echo (int) $value; ?>" <?php echo (int) $value === $limit ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<button type="submit" class="n2j-btn-process">Найти</button>
		<?php if ($query !== ''): ?>
		<a href="<?php echo HTML::chars($base); ?>" class="inline-flex min-h-[40px] items-center px-2 text-sm font-semibold text-gray-500 hover:underline dark:text-gray-400">Сбросить</a>
		<?php endif; ?>
	</form>

	<div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
		<span>
			Найдено: <b class="tabular-nums text-gray-700 dark:text-gray-200"><?php echo number_format($total, 0, ',', ' '); ?></b>.
			<?php if ($total > 0): ?>Показаны <?php echo $from; ?>–<?php echo $to; ?>.<?php endif; ?>
			<?php if ($query !== ''): ?>Поиск: «<?php echo HTML::chars($query); ?>».<?php endif; ?>
		</span>
		<?php echo $pager(); ?>
	</div>

	<?php if ($error === '' AND ! $users): ?>
	<p class="mt-3 rounded-xl border border-gray-200 bg-gray-50 p-5 text-center text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
		Пользователи не найдены<?php echo $query !== '' ? ' по запросу «'.HTML::chars($query).'»' : ''; ?>.
	</p>
	<?php endif; ?>

	<?php if ($users): ?>
	<div class="n2j-card p-0 mt-2 overflow-x-auto">
		<table class="n2j-table min-w-[56rem] border-collapse">
			<thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
				<tr>
					<th scope="col" class="px-3 py-2 font-semibold">Пользователь</th>
					<th scope="col" class="px-3 py-2 font-semibold whitespace-nowrap">Проекты</th>
					<th scope="col" class="px-3 py-2 font-semibold whitespace-nowrap">LLM за сутки</th>
					<th scope="col" class="px-3 py-2 font-semibold">Свои лимиты</th>
					<th scope="col" class="px-3 py-2 font-semibold whitespace-nowrap">Изменено</th>
				</tr>
			</thead>
			<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
				<?php foreach ($users as $user): ?>
				<?php
				$user = (array) $user;
				$id = (int) Arr::get($user, 'id', 0);
				$limits = (array) Arr::get($user, 'effective_limits', []);
				$daily = (array) Arr::get($user, 'daily_usage', []);
				$projects = (int) Arr::get($user, 'active_count', 0);
				$daily_used = (int) Arr::get($daily, 'used', 0);
				$plan_projects = (int) Arr::get($limits, 'active_projects', 5);
				$plan_llm = (int) Arr::get($limits, 'llm_operations_per_day', 5);
				$identity = trim((string) Arr::get($user, 'username', ''));
				$email = trim((string) Arr::get($user, 'email', ''));
				$changed = trim((string) Arr::get($user, 'updated_at', ''));
				?>
				<tr class="odd:bg-white even:bg-gray-50/60 hover:bg-blue-50/40 dark:odd:bg-gray-900 dark:even:bg-gray-800/40 dark:hover:bg-blue-950/20">
					<td class="px-3 py-1.5 align-middle">
						<div class="flex flex-wrap items-baseline gap-x-2">
							<span class="max-w-[18rem] truncate font-semibold text-gray-900 dark:text-white"><?php echo $identity !== '' ? HTML::chars($identity) : 'Пользователь'; ?></span>
							<span class="shrink-0 text-xs tabular-nums text-gray-400">#<?php echo $id; ?></span>
						</div>
						<?php if ($email !== ''): ?>
						<div class="max-w-[18rem] truncate text-xs text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars($email); ?>"><?php echo HTML::chars($email); ?></div>
						<?php endif; ?>
					</td>
					<td class="px-3 py-1.5 align-middle"><?php echo $bar($projects, $plan_projects); ?></td>
					<td class="px-3 py-1.5 align-middle"><?php echo $bar($daily_used, $plan_llm); ?></td>
					<td class="px-3 py-1.5 align-middle">
						<form method="post" action="<?php echo HTML::chars($base); ?>" class="flex flex-wrap items-end gap-1.5" autocomplete="off" data-n2j-no-remember>
							<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
							<input type="hidden" name="user_id" value="<?php echo $id; ?>">
							<input type="hidden" name="q" value="<?php echo HTML::chars($query); ?>">
							<input type="hidden" name="page" value="<?php echo $page; ?>">
							<input type="hidden" name="sort" value="<?php echo HTML::chars($sort); ?>">
							<input type="hidden" name="per" value="<?php echo $limit; ?>">
							<label for="nj667-p-<?php echo $id; ?>" title="Активные проекты: пусто — по плану">
								<span class="sr-only">Активные проекты</span>
								<input id="nj667-p-<?php echo $id; ?>" type="number" min="0" max="100000" step="1" name="active_projects"
									value="<?php echo $plan_projects; ?>"
									placeholder="По плану"
									class="n2j-input h-8 w-[5.5rem] text-xs tabular-nums min-h-0">
							</label>
							<label for="nj667-l-<?php echo $id; ?>" title="LLM-операций в сутки: пусто — по плану">
								<span class="sr-only">LLM-операций в сутки</span>
								<input id="nj667-l-<?php echo $id; ?>" type="number" min="0" max="100000" step="1" name="llm_operations_per_day"
									value="<?php echo $plan_llm; ?>"
									placeholder="По плану"
									class="n2j-input h-8 w-[5.5rem] text-xs tabular-nums min-h-0">
							</label>
							<button type="submit" title="Сохранить лимиты строки" class="n2j-btn-cta n2j-btn-sm">
								<i class="fas fa-floppy-disk"></i><span class="hidden sm:inline">Сохранить</span>
							</button>
						</form>
					</td>
					<td class="px-3 py-1.5 align-middle whitespace-nowrap text-gray-500 dark:text-gray-400">
						<?php echo $changed !== '' ? HTML::chars(Date::formatted_time($changed, 'd.m.y H:i')) : '—'; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
		<span>Пустое поле наследует план, ноль запрещает операцию. Чтобы вернуть человека к плану, очистите оба поля и сохраните.</span>
		<?php echo $pager(); ?>
	</div>
	<?php endif; ?>
</div>
