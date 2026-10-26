<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /dialog/all — сводка ВСЕХ диалогов сайта (страница админа, решение владельца
 * 09.10.2026).
 *
 * Чем отличается от каталога (/dialog/list): каталог показывает только
 * ОПУБЛИКОВАННОЕ и только людям; эта страница — рабочая: все диалоги подряд,
 * с владельцем, статусом и ходом, чтобы было видно, что висит в очереди, что
 * остановлено владельцем и что вообще происходит на сервисе.
 *
 * Действий на странице НЕТ — только ссылки. Удаление и публикация остаются на
 * странице диалога: по строчке таблицы такие решения не принимают, нужно
 * видеть сам разговор.
 *
 * @var array  $rows        диалоги текущей страницы
 * @var array  $total       счётчики сводки (all | public | running | done | stopped)
 * @var string $q           поиск (тема, вводная, владелец, номер)
 * @var string $status      фильтр статуса ('' — все)
 * @var array  $status_list словарь статусов (ключ => подпись)
 * @var int    $page        текущая страница
 * @var int    $pages       всего страниц
 * @var int    $per         строк на странице
 * @var array  $per_options варианты «строк на странице»
 * @var string $csrf        токен форм
 * @var array  $flash       ['tone' => …, 'text' => …]
 */
$tone = [
	'live'  => 'bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200',
	'ok'    => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
	'warn'  => 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
	'error' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
	'plain' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
];

$numbers = function ($n) { return number_format((int) $n, 0, ',', ' '); };
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-nj662-view data-nj662-run="0">

	<?php if ( ! empty($flash['text'])): ?>
	<div class="n2j-flash <?php echo $flash['tone'] === 'error' ? 'n2j-flash-err' : 'n2j-flash-ok'; ?> mb-5">
		<i class="fas <?php echo $flash['tone'] === 'error' ? 'fa-circle-exclamation text-rose-600 dark:text-rose-400' : 'fa-circle-check text-emerald-600 dark:text-emerald-400'; ?> shrink-0 text-base" aria-hidden="true"></i>
		<span class="flex-1"><?php echo HTML::chars((string) $flash['text']); ?></span>
	</div>
	<?php endif; ?>

	<!-- ============ Шапка ============ -->
	<header>
		<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
			<a href="/dialog" class="transition hover:text-blue-600 dark:hover:text-blue-400">Диалог двух ИИ</a>
			<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
			<span class="font-semibold text-gray-700 dark:text-gray-300">Все диалоги</span>
		</nav>

		<div class="mt-3 flex flex-wrap items-center gap-3">
			<h1 class="n2j-h1 flex items-center gap-3">
				<i class="fas fa-table-list text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Все диалоги сайта
			</h1>
			<span class="n2j-badge n2j-badge-wait">всего: <?php echo $numbers(Arr::get($total, 'all', 0)); ?></span>
		</div>
		<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
			Рабочая сводка: опубликованные и неопубликованные разговоры всех владельцев. Решения принимаются на странице диалога — здесь только просмотр и переходы.
		</p>
	</header>

	<!-- ============ Счётчики ============ -->
	<div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Всего диалогов</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $numbers(Arr::get($total, 'all', 0)); ?></p>
			<p class="n2j-hint mt-0.5">без учёта фильтров ниже</p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Опубликовано</p>
			<p class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400"><?php echo $numbers(Arr::get($total, 'public', 0)); ?></p>
			<p class="n2j-hint mt-0.5"><a href="/dialog/list" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">каталог</a></p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Идут сейчас</p>
			<p class="mt-1 text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $numbers(Arr::get($total, 'running', 0)); ?></p>
			<p class="n2j-hint mt-0.5">обмен в очереди</p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Остановлено</p>
			<p class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400"><?php echo $numbers(Arr::get($total, 'stopped', 0)); ?></p>
			<p class="n2j-hint mt-0.5">кнопкой «Стоп»</p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Завершено</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $numbers(Arr::get($total, 'done', 0)); ?></p>
			<p class="n2j-hint mt-0.5">сделаны все обмены</p>
		</div>
	</div>

	<!-- ============ Фильтры ============ -->
	<form method="get" action="/dialog/all" class="mt-5 flex flex-wrap items-center gap-2">
		<label for="nj662-all-q" class="sr-only">Поиск по диалогам</label>
		<input id="nj662-all-q" type="search" name="q" value="<?php echo HTML::chars($q); ?>" maxlength="120"
			placeholder="Тема, владелец (почта или логин) или номер диалога"
			class="n2j-input min-w-0 flex-1">

		<label for="nj662-all-status" class="sr-only">Статус</label>
		<select id="nj662-all-status" name="status"
			class="n2j-input n2j-select cursor-pointer w-auto">
			<option value="">любой статус</option>
			<?php foreach ($status_list as $key => $word): ?>
			<option value="<?php echo HTML::chars((string) $key); ?>" <?php echo $status === (string) $key ? 'selected' : ''; ?>><?php echo HTML::chars((string) $word); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="n2j-btn-process">
			<i class="fas fa-magnifying-glass text-[13px]" aria-hidden="true"></i>Показать
		</button>
		<?php if ($q !== '' OR $status !== ''): ?>
		<a href="/dialog/all" class="inline-flex min-h-[44px] items-center rounded-lg px-3 text-sm font-semibold text-gray-500 transition hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-100">Сбросить</a>
		<?php endif; ?>
	</form>

	<!-- ============ Таблица ============ -->
	<?php if (empty($rows)): ?>
	<section class="mt-5 rounded-xl border border-dashed border-gray-300 bg-white/60 px-4 py-10 text-center dark:border-gray-600 dark:bg-gray-800/60">
		<i class="fas fa-comments text-2xl text-gray-300 dark:text-gray-600" aria-hidden="true"></i>
		<p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
			<?php if ($q !== '' OR $status !== ''): ?>
			Под этот фильтр диалогов нет. <a href="/dialog/all" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Показать все</a>.
			<?php else: ?>
			Диалогов ещё нет: никто не запускал разговор двух агентов.
			<?php endif; ?>
		</p>
	</section>
	<?php else: ?>

	<section class="n2j-card p-0 mt-5 overflow-hidden">
		<div class="overflow-x-auto">
			<table class="n2j-table min-w-[42rem]">
				<thead class="border-b border-gray-100 bg-gray-50/70 text-xs uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/40 dark:text-gray-400">
					<tr>
						<th scope="col" class="px-4 py-2.5 font-bold">#</th>
						<th scope="col" class="px-4 py-2.5 font-bold">Диалог</th>
						<th scope="col" class="px-4 py-2.5 font-bold">Владелец</th>
						<th scope="col" class="px-4 py-2.5 font-bold">Статус</th>
						<th scope="col" class="px-4 py-2.5 font-bold">Ход</th>
						<th scope="col" class="px-4 py-2.5 font-bold">Обновлён</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
					<?php foreach ($rows as $d): ?>
					<?php
						$prog  = Service_Nj662_Dialog::progress($d);
						$own_r = trim((string) Arr::get($d, 'owner_email', Arr::get($d, 'owner_login', '')));
					?>
					<tr class="align-top transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/20">
						<td class="px-4 py-3 font-mono text-xs text-gray-400 dark:text-gray-500"><?php echo (int) $d['id']; ?></td>

						<td class="max-w-md px-4 py-3">
							<a href="/dialog/<?php echo (int) $d['id']; ?>" class="font-semibold leading-snug text-gray-900 transition hover:text-blue-600 hover:underline dark:text-white dark:hover:text-blue-400"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) $d['topic'], 120)); ?></a>
							<p class="n2j-hint mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
								<span><?php echo HTML::chars((string) $d['a_name']); ?> × <?php echo HTML::chars((string) $d['b_name']); ?></span>
								<span class="text-gray-300 dark:text-gray-600">·</span>
								<span>реплик: <?php echo (int) $d['replies']; ?></span>
								<?php if ((int) $d['owner_replies'] > 0): ?>
								<span class="text-gray-300 dark:text-gray-600">·</span>
								<span title="реплик владельца в разговоре" class="font-semibold text-amber-600 dark:text-amber-400"><i class="fas fa-user-pen text-[10px]" aria-hidden="true"></i> <?php echo (int) $d['owner_replies']; ?></span>
								<?php endif; ?>
								<?php if (Service_Nj662_Dialog::as_bool($d['is_public'])): ?>
								<span class="rounded bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">опубликован</span>
								<?php else: ?>
								<span class="rounded bg-gray-100 px-1.5 py-0.5 font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">не опубликован</span>
								<?php endif; ?>
							</p>
							<p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
								<a href="/dialog/read/<?php echo (int) $d['id']; ?>" class="font-semibold text-blue-600 transition hover:underline dark:text-blue-400">читать</a>
								<span class="text-gray-300 dark:text-gray-600">·</span>
								<a href="/dialog/<?php echo (int) $d['id']; ?>" class="font-semibold text-blue-600 transition hover:underline dark:text-blue-400">управление</a>
							</p>
						</td>

						<td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
							<?php if ($own_r !== ''): ?>
							<span class="block max-w-[14rem] truncate" title="<?php echo HTML::chars($own_r); ?>"><?php echo HTML::chars($own_r); ?></span>
							<span class="text-xs text-gray-400 dark:text-gray-500">id <?php echo (int) $d['user_id']; ?></span>
							<?php else: ?>
							<span class="text-gray-400 dark:text-gray-500">—</span>
							<?php endif; ?>
						</td>

						<td class="px-4 py-3">
							<span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold <?php echo $tone[$prog['tone']]; ?>">
								<?php if ($prog['live']): ?><i class="fas fa-spinner animate-spin text-[10px]" aria-hidden="true"></i><?php endif; ?><?php echo HTML::chars($prog['status_word']); ?>
							</span>
							<?php if (trim((string) $d['error']) !== ''): ?>
							<span class="mt-1 block max-w-[12rem] text-xs leading-snug text-rose-600 dark:text-rose-400"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) $d['error'], 90)); ?></span>
							<?php endif; ?>
						</td>

						<td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
							<span class="whitespace-nowrap"><?php echo HTML::chars($prog['turn_word']); ?></span>
							<span class="mt-0.5 block text-xs text-gray-400 dark:text-gray-500">поиск: <?php echo (int) $d['searches_used']; ?> из <?php echo (int) $d['ddg']; ?></span>
							<span class="mt-0.5 block text-xs text-gray-400 dark:text-gray-500">токенов: <?php echo $numbers($d['tokens_total']); ?></span>
						</td>

						<td class="whitespace-nowrap px-4 py-3 text-xs text-gray-400 dark:text-gray-500">
							<?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $d['updated_at']))); ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

	<?php echo View::factory('partials/pagination', [
		'total'       => (int) Arr::get($total, 'all', 0),
		'page'        => (int) $page,
		'pages'       => (int) $pages,
		'per'         => (int) $per,
		'per_options' => $per_options,
		'url_base'    => '/dialog/all',
		'params'      => array_filter(['q' => $q, 'status' => $status]),
	]); ?>

	<?php endif; ?>
</div>
