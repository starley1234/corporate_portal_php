<?php defined('SYSPATH') or die('No direct script access.');
$existing = isset($existing) ? $existing : NULL;
$scenario = isset($scenario) ? (array) $scenario : [];
$error = isset($error) ? (string) $error : '';
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-center justify-between gap-2">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-circle-play mr-2 text-blue-500"></i>Демо за 5 минут</h1>
			<p class="mt-1 max-w-3xl n2j-ds-body text-gray-600 dark:text-gray-300">Один клик загружает полноценный кейс: онтологию, идеальный регламент, реальный журнал с Telegram и обходом юриста, готовый AI-аудит с ценой потерь.</p>
		</div>
		<a href="/process" class="n2j-ds-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">← К процессам</a>
	</div>

	<?php if ($error !== ''): ?>
	<div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 n2j-ds-body font-semibold text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300"><?php echo HTML::chars($error); ?></div>
	<?php endif; ?>

	<div class="mt-5 grid gap-4 lg:grid-cols-3">
		<section class="n2j-card lg:col-span-2">
			<span class="inline-flex rounded-md bg-blue-50 px-2 py-0.5 n2j-ds-xs font-semibold uppercase tracking-wide text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">Сценарий: закупка ТМЦ</span>
			<h2 class="mt-2 n2j-ds-h2 font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($scenario, 'title', 'Закупка ТМЦ и согласование договора')); ?></h2>
			<p class="mt-2 n2j-ds-body leading-relaxed text-gray-600 dark:text-gray-300">План — 5 шагов и 3 рабочих дня. Факт — 9 шагов и 14 дней: согласование ушло в Telegram, юриста обошли в 35 % кейсов, СБ подключилась стихийно и держала заявку 7,5 рабочих дней, а счёт «выбивали» вручную. Аудит уже посчитан: 18 400 ₽ потерь на одну процедуру.</p>

			<div class="mt-4 grid gap-2 sm:grid-cols-2">
				<?php foreach ([
					['План', '5 шагов · 1 440 мин · роли, системы, артефакты', 'text-blue-700 dark:text-blue-300'],
					['Факт', '9 шагов · 40 кейсов · журнал событий', 'text-emerald-700 dark:text-emerald-300'],
					['Разрывы', 'Telegram, обход юриста, петля возвратов', 'text-rose-700 dark:text-rose-300'],
					['Экономика', '18 400 ₽ потерь · простой СБ 54 %', 'text-amber-700 dark:text-amber-300'],
				] as $tile): ?>
				<div class="n2j-card p-3">
					<div class="n2j-ds-xs font-bold uppercase tracking-wide <?php echo $tile[2]; ?>"><?php echo HTML::chars($tile[0]); ?></div>
					<div class="mt-0.5 n2j-ds-sm font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($tile[1]); ?></div>
				</div>
				<?php endforeach; ?>
			</div>

			<form method="post" action="/process/demo" class="mt-5">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<label class="inline-flex items-center gap-2 n2j-ds-sm text-gray-600 dark:text-gray-300">
					<input type="checkbox" name="fresh" value="1" class="rounded border-gray-300 text-blue-600">
					Пересобрать демо заново (удалить прошлую демо-версию)
				</label>
				<div class="mt-3 flex flex-wrap gap-2">
					<button type="submit" class="n2j-btn-process"><i class="fas fa-bolt mr-2"></i>Загрузить демо</button>
					<?php if ($existing): ?>
					<a href="/process/<?php echo HTML::chars($existing['code']); ?>" class="n2j-btn-secondary">Открыть существующее демо</a>
					<?php endif; ?>
				</div>
				<p class="mt-2 n2j-ds-xs text-gray-500 dark:text-gray-400">Повторный запуск без галочки вернёт уже созданный демо-процесс. Демо не тратит вызовы модели — данные детерминированы.</p>
			</form>
		</section>

		<aside class="space-y-4">
			<section class="n2j-card">
				<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-list-check mr-1 text-gray-400"></i>Сценарий показа</h2>
				<ol class="mt-2 space-y-2 n2j-ds-sm leading-relaxed text-gray-600 dark:text-gray-300">
					<li><span class="font-bold text-blue-700 dark:text-blue-300">1.</span> «Собрать схему»: вставьте 4 строки регламента — покажите, как текст становится BPMN с дорожками.</li>
					<li><span class="font-bold text-blue-700 dark:text-blue-300">2.</span> На холсте выберите шаг и попросите Copilot: «добавь проверку СБ после юриста». Схема дополнится, ручная раскладка сохранится.</li>
					<li><span class="font-bold text-blue-700 dark:text-blue-300">3.</span> Переключите «План / Факт» — увидите Telegram, петлю юриста и простой СБ.</li>
					<li><span class="font-bold text-blue-700 dark:text-blue-300">4.</span> Откройте «AI-аудит»: шлюз «без юриста 35 %», 54 % срока в ожидании СБ, 18 400 ₽ потерь.</li>
					<li><span class="font-bold text-blue-700 dark:text-blue-300">5.</span> Нажмите «Выпустить регламент» и скачайте DOCX/PDF с таблицей шагов и матрицей RACI.</li>
				</ol>
			</section>

			<section class="rounded-xl border border-gray-200 bg-gray-50 p-4 n2j-ds-sm leading-relaxed text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
				<p class="font-bold">Что проверить перед показом</p>
				<ul class="mt-1 list-disc space-y-1 pl-4">
					<li>Демо доступно аккаунтам с правом <span class="font-mono">nj670.start</span>.</li>
					<li>Экспорт работает и без воркера: DOCX собирается на сайте, PDF — при наличии шрифта DejaVu.</li>
					<li>AI-достройка холста (шаг 2) требует запущенного воркера. Без него покажите добавление шага из палитры — результат тот же, без модели.</li>
					<li>Числа в демо фиксированы — их можно называть в презентации без оговорок.</li>
				</ul>
			</section>

			<section class="n2j-card">
				<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white">CLI-вариант</h2>
				<pre class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-3 n2j-ds-xs leading-relaxed text-gray-100">bash bin/console processhub:seed-demo \
  --scenario=procurement \
  --user=admin@n2j.ru</pre>
				<p class="mt-2 n2j-ds-xs text-gray-500 dark:text-gray-400">Команда печатает код процесса и годится для стенда и автотестов; флаг <span class="font-mono">--fresh=1</span> пересобирает демо с нуля.</p>
			</section>
		</aside>
	</div>
</div>
