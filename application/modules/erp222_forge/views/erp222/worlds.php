<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /forge/worlds — миры «Кузницы систем»: список и создание.
 *
 * Мир — это постановка задачи: что строим, зачем и по каким правилам считаем
 * выпущенное ценным. Форма одна и короткая (название, задумка, домен, длина
 * первого цикла) — потому что любая лишняя настройка здесь только мешает:
 * остальное роли додумают сами и покажут это в артефактах.
 *
 * Дизайн — по эталону /pages/required_design_sample: карточки rounded-xl,
 * контролы rounded-lg min-h-[44px], подписи text-xs, пояснения рядом с полем,
 * никаких полос и графиков — только числа и списки.
 *
 * @var array $worlds миры с числами (artifacts, value_avg)
 * @var array $counts счётчики сервиса
 * @var array $roles  словарь ролей
 * @var string $csrf  токен форм
 * @var array $flash  ['tone' => …, 'text' => …]
 */
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php if ( ! empty($flash['text'])): ?>
	<div class="n2j-flash <?php echo $flash['tone'] === 'error' ? 'n2j-flash-err' : 'n2j-flash-ok'; ?> mb-5">
		<i class="fas <?php echo $flash['tone'] === 'error' ? 'fa-circle-exclamation text-rose-600 dark:text-rose-400' : 'fa-circle-check text-emerald-600 dark:text-emerald-400'; ?> shrink-0 text-base"></i>
		<span class="flex-1"><?php echo HTML::chars((string) $flash['text']); ?></span>
	</div>
	<?php endif; ?>

	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/forge" class="transition hover:text-blue-600 dark:hover:text-blue-400">Кузница систем</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300">Миры</span>
	</nav>

	<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3">
				<i class="fas fa-globe text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Миры
			</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
				Мир — это одна постановка задачи со своей историей: что строим, зачем, какие роли над этим думают и что они уже выпустили. Артефакты разных миров не смешиваются: спецификация на один продукт и оценка на другой не должны выглядеть как один план.
			</p>
		</div>
		<div class="flex flex-wrap items-center gap-2">
			<a href="/forge" class="n2j-btn-secondary">
				<i class="fas fa-hammer text-[13px]" aria-hidden="true"></i>Дашборд
			</a>
			<a href="/forge/artifacts" class="n2j-btn-secondary">
				<i class="fas fa-cubes text-[13px]" aria-hidden="true"></i>Каталог
			</a>
		</div>
	</div>

	<div class="mt-5 grid gap-5 lg:grid-cols-3">

		<!-- ============ Создание мира ============ -->
		<section class="n2j-card">
			<h2 class="n2j-h3">Новый мир</h2>
			<p class="n2j-hint mt-1.5">
				Задумку пишите как задание коллеге: что за система, для кого, что должно получиться. Модель опирается на этот текст — чем конкретнее, тем полезнее артефакты.
			</p>

			<form method="post" action="/forge/worlds" class="mt-4 space-y-4">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

				<div>
					<label for="erp222-title" class="n2j-label">Название мира</label>
					<input id="erp222-title" type="text" name="title" maxlength="200" required
						   placeholder="Например: учёт заявок сервисного центра"
						   class="n2j-input mt-1.5 w-full">
				</div>

				<div>
					<label for="erp222-brief" class="n2j-label">Задумка</label>
					<textarea id="erp222-brief" name="brief" rows="6" maxlength="4000"
							  placeholder="Кто пользователь, какую работу он делает руками сегодня, что должно измениться и по каким признакам вы поймёте, что получилось."
							  class="n2j-textarea mt-1.5 w-full leading-relaxed"></textarea>
					<p class="n2j-hint">Если оставить пустым, мир возьмёт формулировку из названия — но тогда артефакты будут общими.</p>
				</div>

				<div>
					<label for="erp222-domain" class="n2j-label">Домен</label>
					<input id="erp222-domain" type="text" name="domain" maxlength="64"
						   placeholder="например: логистика, образование, финансы"
						   class="n2j-input mt-1.5 w-full">
					<p class="n2j-hint">Домен помогает подобрать формулировки и метрики, привычные в этой области.</p>
				</div>

				<label class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50/60 p-3.5 dark:border-gray-700/70 dark:bg-gray-900/30">
					<input type="checkbox" name="run_now" value="1" checked class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800">
					<span class="text-xs leading-relaxed text-gray-600 dark:text-gray-300">
						<b class="text-gray-900 dark:text-white">Сразу запустить первый цикл.</b> Шагов — <input type="number" name="steps" min="1" max="24" value="6" class="n2j-input mx-1 w-16 py-0.5 text-center text-xs font-bold min-h-0"> — цикл из 6 шагов обходит все пять ролей и заканчивается разбором Критика.
					</span>
				</label>

				<button type="submit" class="n2j-btn-cta w-full">
					<i class="fas fa-plus text-[13px]" aria-hidden="true"></i>Создать мир
				</button>
			</form>

			<div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-700/60">
				<h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Роли в каждом мире</h3>
				<ul class="mt-2 space-y-2">
					<?php foreach ($roles as $code => $role): ?>
					<li class="flex items-start gap-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
						<i class="<?php echo HTML::chars((string) Arr::get($role, 'icon', 'fas fa-user')); ?> mt-0.5 w-4 shrink-0 text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
						<span><b class="text-gray-900 dark:text-white"><?php echo HTML::chars((string) $role['title']); ?></b> — <?php echo HTML::chars((string) $role['focus']); ?></span>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>

		<!-- ============ Список миров ============ -->
		<section class="lg:col-span-2">
			<?php if (empty($worlds)): ?>
			<div class="n2j-card n2j-empty">
				<div class="n2j-empty-icon"><i class="fas fa-globe" aria-hidden="true"></i></div>
				<h2 class="n2j-h3 mt-4">Миров ещё нет</h2>
				<p class="n2j-empty-text">Создайте первый мир слева — и запустите цикл: роли начнут выпускать документы.</p>
			</div>
			<?php else: ?>
			<div class="grid gap-3 sm:grid-cols-2">
				<?php foreach ($worlds as $w): ?>
				<article class="n2j-card flex flex-col transition hover:border-blue-300 dark:hover:border-blue-800">
					<div class="flex flex-wrap items-center gap-2 text-xs">
						<?php if ((string) $w['status'] === 'active'): ?>
						<span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>активен</span>
						<?php else: ?>
						<span class="rounded-md bg-amber-100 px-2.5 py-1 font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">на паузе</span>
						<?php endif; ?>
						<?php if (trim((string) $w['domain']) !== ''): ?>
						<span class="rounded-md bg-gray-100 px-2.5 py-1 font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300"><?php echo HTML::chars((string) $w['domain']); ?></span>
						<?php endif; ?>
					</div>

					<h2 class="n2j-h3 mt-2.5">
						<a href="/forge/world/<?php echo HTML::chars((string) $w['code']); ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400"><?php echo HTML::chars((string) $w['title']); ?></a>
					</h2>

					<?php $brief = trim((string) $w['brief']); ?>
					<?php if ($brief !== ''): ?>
					<p class="n2j-hint mt-1.5 flex-1"><?php echo HTML::chars(mb_strlen($brief) > 180 ? mb_substr($brief, 0, 180).'…' : $brief); ?></p>
					<?php endif; ?>

					<dl class="mt-3 grid grid-cols-3 gap-2 border-t border-gray-100 pt-3 text-center dark:border-gray-700/60">
						<div>
							<dt class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Артефактов</dt>
							<dd class="text-lg font-bold text-gray-900 dark:text-white"><?php echo (int) Arr::get($w, 'artifacts', 0); ?></dd>
						</div>
						<div>
							<dt class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Ценность</dt>
							<dd class="text-lg font-bold text-gray-900 dark:text-white"><?php echo (int) Arr::get($w, 'value_avg', 0); ?><span class="text-xs font-semibold text-gray-400">/100</span></dd>
						</div>
						<div>
							<dt class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Шагов</dt>
							<dd class="text-lg font-bold text-gray-900 dark:text-white"><?php echo (int) $w['steps_done']; ?></dd>
						</div>
					</dl>

					<p class="n2j-hint mt-3 flex items-center justify-between gap-3">
						<span><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $w['updated_at']))); ?></span>
						<a href="/forge/world/<?php echo HTML::chars((string) $w['code']); ?>" class="inline-flex min-h-[36px] items-center gap-1.5 text-xs font-semibold text-blue-600 transition hover:underline dark:text-blue-400">Открыть<i class="fas fa-arrow-right text-[11px]" aria-hidden="true"></i></a>
					</p>
				</article>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</section>
	</div>
</div>
