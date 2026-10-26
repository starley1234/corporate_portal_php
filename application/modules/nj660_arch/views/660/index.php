<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php /** @var array $rows @var array $presets @var array $zoo @var callable $fmt @var string $notice @var string $error */
// Kohana превращает неопределённую переменную в ErrorException, поэтому
// опциональные флеш-переменные получают дефолты (контракт — контроллер)
$notice = isset($notice) ? $notice : '';
$error  = isset($error)  ? $error  : ''; ?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-diagram-project text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Конструктор архитектуры ИИ</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Соберите нейросеть из современных блоков — MoE-эксперты, GQA/MLA-внимание, RoPE, Mamba-слои, мультимодальные входы, хуки в середину модели. Конструктор считает параметры, память и бюджет обучения на лету; реальная LLM проводит экспертизу и «оживляет» проект; PyTorch-скелет экспортируется одним файлом.</p>
		</div>
		<a href="/arch/new" class="n2j-btn-cta"><i class="fas fa-plus mr-2" aria-hidden="true"></i>Новая архитектура</a>
	</div>

	<?php if ($notice !== ''): ?>
		<p class="n2j-flash n2j-flash-ok mt-5 block"><?php echo HTML::chars($notice); ?></p>
	<?php endif; ?>
	<?php if ($error !== ''): ?>
		<p class="n2j-flash n2j-flash-err mt-5 block"><?php echo HTML::chars($error); ?></p>
	<?php endif; ?>

	<section class="mt-8" aria-labelledby="arch-presets">
		<h2 id="arch-presets" class="n2j-h3">Начать с заготовки</h2>
		<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Готовые рецепты — форкайте и переделывайте под свою задачу.</p>
		<div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
			<?php foreach ($presets as $key => $preset): ?>
				<a href="/arch/new?preset=<?php echo HTML::chars($key); ?>" class="n2j-card group transition hover:-translate-y-0.5 hover:border-blue-400">
					<p class="text-sm font-bold text-gray-900 group-hover:text-blue-700 dark:text-white dark:group-hover:text-blue-300"><?php echo HTML::chars($preset['title']); ?></p>
					<p class="n2j-hint mt-1.5"><?php echo HTML::chars($preset['note']); ?></p>
					<p class="mt-3 text-xs font-semibold text-blue-700 dark:text-blue-300">открыть в конструкторе →</p>
				</a>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="mt-10" aria-labelledby="arch-mine">
		<h2 id="arch-mine" class="n2j-h3">Мои проекты</h2>
		<?php if ( ! $rows): ?>
			<div class="mt-4 rounded-xl border border-dashed border-blue-200 bg-blue-50/60 p-6 text-center dark:border-blue-900 dark:bg-blue-950/20">
				<i class="fas fa-seedling text-2xl text-blue-500" aria-hidden="true"></i>
				<p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Пока ни одной архитектуры</p>
				<p class="n2j-hint mx-auto max-w-md">Возьмите заготовку выше или соберите с нуля — черновики сохраняются и без регистрации (в вашем браузере), вход нужен только для LLM-экспертизы и симуляции.</p>
			</div>
		<?php else: ?>
			<div class="mt-4 space-y-2">
				<?php foreach ($rows as $row): ?>
					<div class="n2j-card p-0 flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
						<a href="/arch/view/<?php echo HTML::chars($row['code']); ?>" class="text-sm font-semibold text-gray-900 hover:text-blue-700 dark:text-white dark:hover:text-blue-300"><?php echo HTML::chars($row['title']); ?></a>
						<span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars($row['family']); ?></span>
						<span class="text-xs text-gray-500 dark:text-gray-400"><?php echo $fmt(Arr::get($row, 'params', 0)); ?> параметров · глубина <?php echo (int) Arr::get($row['totals'], 'depth', 0); ?> · хуков <?php echo (int) Arr::get($row['totals'], 'hooks', 0); ?></span>
						<a href="/arch/edit/<?php echo HTML::chars($row['code']); ?>" class="ml-auto text-xs font-semibold text-gray-500 transition hover:text-blue-700 dark:text-gray-400 dark:hover:text-blue-300">править →</a>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<section class="mt-10" aria-labelledby="arch-zoo">
		<h2 id="arch-zoo" class="n2j-h3">Зоопарк: как устроены известные модели</h2>
		<p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Справочные данные из публикаций — для сравнения со своим проектом (конструктор показывает «× ближайший аналог»).</p>
		<div class="n2j-card p-0 mt-4 overflow-x-auto">
			<table class="n2j-table">
				<thead>
					<tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
						<th class="px-4 py-3 font-semibold">Модель</th>
						<th class="px-4 py-3 font-semibold">Семейство</th>
						<th class="px-4 py-3 text-right font-semibold">Параметров</th>
						<th class="px-4 py-3 text-right font-semibold">Активных</th>
						<th class="hidden px-4 py-3 font-semibold lg:table-cell">Что важно</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-700">
					<?php foreach ($zoo as $z): ?>
						<tr class="transition hover:bg-gray-50 dark:hover:bg-gray-900/60">
							<td class="px-4 py-2.5 font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($z['title']); ?></td>
							<td class="px-4 py-2.5 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($z['family']); ?></td>
							<td class="px-4 py-2.5 text-right tabular-nums text-gray-800 dark:text-gray-200"><?php echo $fmt($z['total']); ?></td>
							<td class="px-4 py-2.5 text-right tabular-nums text-gray-500 dark:text-gray-400"><?php echo $fmt($z['active']); ?></td>
							<td class="hidden px-4 py-2.5 text-gray-500 lg:table-cell dark:text-gray-400"><?php echo HTML::chars($z['note']); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="n2j-hint mt-3">Активные параметры — сколько «работает» на один проход (у MoE меньше полного размера). Числа — из карточек моделей, для ориентира.</p>
	</section>
</div>
