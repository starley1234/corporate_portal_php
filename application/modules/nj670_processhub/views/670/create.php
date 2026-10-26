<?php defined('SYSPATH') or die('No direct script access.');
$post = isset($post) ? (array) $post : [];
$ontology = isset($ontology) ? (array) $ontology : [];
$limits = isset($limits) ? (array) $limits : [];
$error = isset($error) ? (string) $error : '';
$worker = isset($worker) ? (array) $worker : [];
$worker_online = ! empty($worker['online']);
$roles = (array) Arr::get($ontology, 'role', []);
$field = 'n2j-input mt-1 w-full n2j-ds-body';
$label = 'n2j-label';
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-center justify-between gap-2">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-wand-magic-sparkles mr-2 text-blue-500"></i>Новый процесс</h1>
			<p class="mt-1 max-w-3xl n2j-ds-body text-gray-600 dark:text-gray-300">Вставьте регламент списком или абзацем. Модель выделит шаги, роли, системы и артефакты; если очередь недоступна, схема соберётся по правилам — черновик будет честно помечен.</p>
		</div>
		<a href="/process" class="n2j-ds-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">← К списку процессов</a>
	</div>

	<?php if ($error !== ''): ?>
	<div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 n2j-ds-body font-semibold text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
		<i class="fas fa-circle-exclamation mr-1"></i><?php echo HTML::chars($error); ?>
	</div>
	<?php endif; ?>

	<form method="post" action="/process/create" enctype="multipart/form-data" autocomplete="off" class="mt-5 grid gap-4 lg:grid-cols-3">
		<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

		<div class="space-y-4 lg:col-span-2">
			<section class="n2j-card">
				<label for="nj670-title" class="<?php echo $label; ?>">Название процесса <span class="font-normal text-gray-400">(необязательно)</span></label>
				<input id="nj670-title" name="title" maxlength="255" value="<?php echo HTML::chars((string) Arr::get($post, 'title', '')); ?>" class="<?php echo $field; ?>" placeholder="Оставьте пусто — назовём по первой строке текста">

				<div class="mt-3 grid gap-3 sm:grid-cols-2">
					<div>
						<label for="nj670-department" class="<?php echo $label; ?>">Подразделение</label>
						<input id="nj670-department" name="department" maxlength="255" value="<?php echo HTML::chars((string) Arr::get($post, 'department', '')); ?>" class="<?php echo $field; ?>" placeholder="Отдел закупок">
					</div>
					<div>
						<label for="nj670-parent" class="<?php echo $label; ?>">Родительский процесс <span class="font-normal text-gray-400">(необязательно)</span></label>
						<select id="nj670-parent" name="parent_code" class="n2j-input n2j-select mt-1 w-full n2j-ds-body">
							<option value="">— самостоятельный —</option>
							<?php foreach ((array) $parents as $parent): ?>
							<option value="<?php echo HTML::chars($parent['code']); ?>" <?php echo (string) Arr::get($post, 'parent_code') === (string) $parent['code'] ? 'selected' : ''; ?>><?php echo HTML::chars($parent['title']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label for="nj670-owner" class="<?php echo $label; ?>">Владелец процесса</label>
						<select id="nj670-owner" name="owner_role_code" class="n2j-input n2j-select mt-1 w-full n2j-ds-body">
							<option value="">— не выбран —</option>
							<?php foreach ($roles as $role): ?>
							<option value="<?php echo HTML::chars($role['code']); ?>" <?php echo (string) Arr::get($post, 'owner_role_code') === (string) $role['code'] ? 'selected' : ''; ?>><?php echo HTML::chars($role['name']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="mt-3">
					<label for="nj670-description" class="<?php echo $label; ?>">Короткое описание <span class="font-normal text-gray-400">(необязательно)</span></label>
					<textarea id="nj670-description" name="description" rows="2" maxlength="5000" class="n2j-textarea mt-1 w-full n2j-ds-body" placeholder="Зачем процесс нужен и где начинается"><?php echo HTML::chars((string) Arr::get($post, 'description', '')); ?></textarea>
				</div>
			</section>

			<section class="n2j-card">
				<div class="flex flex-wrap items-center justify-between gap-2">
					<div>
						<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-file-import mr-1.5 text-blue-500"></i>Импорт BPMN</h2>
						<p class="mt-0.5 n2j-ds-xs text-gray-600 dark:text-gray-300">Схема из Storm, Camunda, Bizagi или draw.io: дорожки, задачи, шлюзы и условия перейдут в ProcessHub без перерисовки.</p>
					</div>
					<label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 n2j-ds-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
						<i class="fas fa-paperclip"></i><span id="nj670-bpmn-label">Выбрать .bpmn / .xml</span>
						<input type="file" name="bpmn" id="nj670-bpmn" class="hidden" accept=".bpmn,.xml">
					</label>
				</div>
				<p class="mt-2 n2j-ds-xs text-gray-500 dark:text-gray-400">Если приложить файл, текст регламента не нужен: кнопка «Создать» импортирует схему. Роли, которых нет в справочнике, останутся подписями дорожек — их можно завести в онтологии.</p>
			</section>

			<section class="n2j-card">
				<div class="flex flex-wrap items-center justify-between gap-2">
					<label for="nj670-text" class="<?php echo $label; ?>">Текст регламента <span class="text-rose-500" title="Обязательное поле">*</span></label>
					<span class="flex items-center gap-2 n2j-ds-xs text-gray-500 dark:text-gray-400">
						<button type="button" id="nj670-example" class="n2j-link">Вставить пример</button>
						<span aria-hidden="true" class="text-gray-300 dark:text-gray-600">·</span>
						<span><span id="nj670-count">0</span> / <?php echo (int) Arr::get($limits, 'max_text', 20000); ?></span>
					</span>
				</div>
				<textarea id="nj670-text" name="text" rows="12" maxlength="<?php echo (int) Arr::get($limits, 'max_text', 20000); ?>" class="n2j-textarea mt-1 w-full n2j-ds-body mt-2 leading-relaxed" placeholder="1. Инициатор создаёт служебную записку в 1С и прикладывает коммерческое предложение.
2. Руководитель подразделения согласует заявку в течение 4 часов.
3. Юрист проверяет проект договора в Диадоке и визирует его.
4. Бухгалтерия оплачивает счёт и подшивает платёжное поручение.
..."><?php echo HTML::chars((string) Arr::get($post, 'text', '')); ?></textarea>
				<p class="mt-2 n2j-ds-xs text-gray-500 dark:text-gray-400">Это единственное обязательное поле — если не выбран файл источника. Лучший результат — когда одна строка = одно действие. Если в тексте есть «если…», «в случае…» или «иначе…», схема получит шлюз и условный переход.</p>
			</section>

			<section class="n2j-card">
				<label for="nj670-source" class="<?php echo $label; ?>">Или файл источника</label>
				<input id="nj670-source" type="file" name="source" accept=".txt,.md,.pdf,.png,.jpg,.jpeg,.webp,.mp3,.m4a,.wav,.ogg" class="mt-2 block w-full cursor-pointer rounded-lg border border-dashed border-gray-300 bg-gray-50 px-3 py-2 n2j-ds-body text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-blue-700 file:px-3 file:py-1.5 file:text-[13px] file:font-semibold file:text-white dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
				<p class="mt-2 n2j-ds-xs text-gray-500 dark:text-gray-400">Текст и Markdown разбираются сразу. PDF, сканы и фото — воркером (текст PDF / vision-модель). Аудио — через ASR, если он настроен на воркере. Файл до 12 МБ.</p>
			</section>
		</div>

		<aside class="space-y-4">
			<section class="rounded-xl border <?php echo $worker_online ? 'border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900' : 'border-amber-200 bg-amber-50/70 dark:border-amber-900 dark:bg-amber-950/30'; ?> p-4 shadow-sm">
				<div class="flex items-start justify-between gap-2">
					<h2 class="n2j-ds-body font-bold <?php echo $worker_online ? 'text-gray-900 dark:text-white' : 'text-amber-900 dark:text-amber-200'; ?>"><i class="fas fa-circle mr-1 text-[7px] <?php echo $worker_online ? 'text-emerald-500' : 'text-amber-500'; ?>"></i>Воркер <span class="<?php echo $worker_online ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300'; ?>"><?php echo $worker_online ? 'на связи' : 'не отвечает'; ?></span></h2>
					<?php if ( ! empty($worker['queued'])): ?><span class="rounded-full bg-blue-50 px-2 py-0.5 n2j-ds-xs font-semibold text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">в очереди: <?php echo (int) $worker['queued']; ?></span><?php endif; ?>
				</div>
				<p class="mt-1 n2j-ds-xs leading-relaxed <?php echo $worker_online ? 'text-gray-600 dark:text-gray-300' : 'text-amber-900/90 dark:text-amber-100/90'; ?>">
					<?php if ($worker_online): ?>
					Схему соберёт модель<?php if ( ! empty($worker['worker'])): ?> (<?php echo HTML::chars((string) $worker['worker']); ?>)<?php endif; ?>. <?php echo HTML::chars((string) Arr::get($worker, 'hint', '')); ?>
					<?php else: ?>
					Текст разберём по правилам сразу — схема появится, но без модели. <?php echo HTML::chars((string) Arr::get($worker, 'hint', '')); ?>
					<?php endif; ?>
				</p>
				<?php if ( ! $worker_online): ?>
				<p class="mt-1 n2j-ds-xs leading-relaxed text-amber-900/80 dark:text-amber-200/80">Проверка: <span class="font-mono">bin/console processhub:doctor</span> на сайте и <span class="font-mono">NJ670_API_BASE</span> / <span class="font-mono">NJ670_SITE_TOKEN</span> в <span class="font-mono">workers/.env</span>.</p>
				<?php endif; ?>
			</section>

			<section class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 dark:border-blue-900/60 dark:bg-blue-950/20">
				<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-route mr-1 text-blue-500"></i>Что произойдёт дальше</h2>
				<ol class="mt-2 space-y-2 n2j-ds-sm leading-relaxed text-gray-600 dark:text-gray-300">
					<li><span class="font-bold text-blue-700 dark:text-blue-300">1.</span> Текст уйдёт в очередь синтеза (канал nj670.synthesize)<?php echo $worker_online ? '' : ' — или сразу в правила, если воркер не отвечает'; ?>.</li>
					<li><span class="font-bold text-blue-700 dark:text-blue-300">2.</span> Воркер сопоставит роли/системы со справочником и вернёт JSON-граф.</li>
					<li><span class="font-bold text-blue-700 dark:text-blue-300">3.</span> Схема ляжет на дорожки и откроется на холсте в статусе «На согласовании».</li>
					<li><span class="font-bold text-blue-700 dark:text-blue-300">4.</span> Вы поправите шаги, добавите факт и запустите аудит.</li>
				</ol>
				<?php if (empty($allow_rules)): ?>
				<p class="mt-3 rounded-lg bg-amber-100 px-2.5 py-2 n2j-ds-xs font-semibold text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">Правиловый фолбэк выключен: без воркера схема не соберётся.</p>
				<?php endif; ?>
			</section>

			<section class="n2j-card">
				<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white">Справочник компании</h2>
				<p class="mt-1 n2j-ds-xs text-gray-500 dark:text-gray-400">Модель будет выбирать только из этих сущностей. Дополнить можно на странице онтологии.</p>
				<?php foreach (['role' => 'Роли', 'system' => 'Системы', 'artifact' => 'Артефакты'] as $type => $title): ?>
				<div class="mt-2">
					<div class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-400"><?php echo HTML::chars($title); ?></div>
					<div class="mt-1 flex flex-wrap gap-1">
						<?php foreach (array_slice((array) Arr::get($ontology, $type, []), 0, 8) as $entity): ?>
						<span class="rounded bg-gray-100 px-1.5 py-0.5 n2j-ds-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200"><?php echo HTML::chars($entity['name']); ?></span>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endforeach; ?>
				<a href="/process/ontology" class="mt-3 inline-block n2j-ds-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Открыть онтологию →</a>
			</section>

			<button type="submit" class="n2j-btn-process w-full"><i class="fas fa-wand-magic-sparkles mr-2"></i>Собрать схему</button>

			<p class="n2j-ds-xs leading-relaxed text-gray-500 dark:text-gray-400">Сборка занимает 20–60 секунд. Модель может ошибаться: проверьте роли, системы и сроки на холсте перед публикацией регламента.</p>
		</aside>
	</form>

</div>

<script>
// Обязателен либо текст, либо файл источника. Атрибут required ставим из JS:
// при выключенном JS форма остаётся рабочей, а сервер всё равно проверит.
(function () {
	var text = document.getElementById('nj670-text');
	var file = document.getElementById('nj670-source');
	var bpmn = document.getElementById('nj670-bpmn');
	var bpmnLabel = document.getElementById('nj670-bpmn-label');
	var count = document.getElementById('nj670-count');
	var example = document.getElementById('nj670-example');

	if (!text || !file) { return; }

	// Счётчик знаков — числом, без полосы: видно, сколько осталось до лимита.
	function counter() {
		if (count) { count.textContent = String((text.value || '').length); }
	}

	text.addEventListener('input', counter);
	counter();

	// Пример заполняет только пустое поле: чужой текст не затираем.
	var SAMPLE = '1. Инициатор создаёт заявку в 1С и прикладывает коммерческое предложение — 20 мин.\n'
		+ '2. Руководитель подразделения согласует заявку, ожидание 4 ч.\n'
		+ '3. Юрист проверяет проект договора в Диадоке и визирует его — 40 мин.\n'
		+ '4. Если сумма больше 500 000 ₽, подключается финансовый директор, ожидание 1 день.\n'
		+ '5. Бухгалтерия оплачивает счёт и подшивает платёжное поручение — 15 мин.';

	if (example) {
		example.addEventListener('click', function () {
			if (text.value.trim() === '') { text.value = SAMPLE; }

			counter();
			text.focus();
		});
	}

	function sync() {
		// BPMN важнее текста: схема уже нарисована, регламент не нужен.
		var hasBpmn = !!(bpmn && bpmn.files && bpmn.files.length);
		text.required = !hasBpmn && !(file.files && file.files.length > 0);
		var section = text.closest('section');

		if (section) {
			section.classList.toggle('opacity-60', hasBpmn);
		}
	}

	file.addEventListener('change', sync);

	// Отправка формы — тоже работа воркера: кнопка честно показывает, что
	// запрос ушёл, а не притворяется мгновенной. Полосы прогресса нет.
	var form = text.form;

	if (form) {
		form.addEventListener('submit', function () {
			var submit = form.querySelector('button[type="submit"]');

			if (!submit) { return; }

			submit.disabled = true;
			submit.classList.add('cursor-wait', 'opacity-80');
			submit.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i>Собираю схему…';
		});
	}

	if (bpmn) {
		bpmn.addEventListener('change', function () {
			bpmnLabel.textContent = bpmn.files && bpmn.files[0] ? bpmn.files[0].name : 'Выбрать .bpmn / .xml';
			sync();
		});
	}

	sync();
})();
</script>
