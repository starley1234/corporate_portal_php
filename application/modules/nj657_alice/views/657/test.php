<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/** @var array $skills @var array|null $skill @var string $text @var bool $is_new @var array|null $result @var string $csrf */
$resp = $result !== NULL ? (array) Arr::get($result, 'response', []) : [];
$meta = $result !== NULL ? (array) Arr::get($result, 'meta', []) : [];
$body = (array) Arr::get($resp, 'response', []);
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-vial text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Тестовая консоль</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Прогоните фразу через весь пайплайн навыка — маршрутизация, память, модель, сборка ответа — без колонки и консоли Яндекса. Реплики попадают в журнал с пометкой «тест» и участвуют в памяти диалога.</p>
		</div>
	</div>

	<div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
		<main class="min-w-0 space-y-5">
			<section class="n2j-card" aria-labelledby="test-form-title">
				<h2 id="test-form-title" class="n2j-h3">Реплика пользователя</h2>
				<form method="post" action="/alice/admin/test" class="mt-4 space-y-3">
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<div class="grid gap-3 sm:grid-cols-[minmax(0,14rem)_1fr]">
						<div>
							<label class="n2j-label" for="t-skill">Навык</label>
							<select id="t-skill" name="skill" class="n2j-input n2j-select mt-1.5 w-full">
								<?php foreach ($skills as $row): ?>
									<option value="<?php echo HTML::chars($row['code']); ?>" <?php echo ($skill !== NULL AND $skill['code'] === $row['code']) ? 'selected' : ''; ?>><?php echo HTML::chars($row['title']); ?><?php echo $row['is_active'] ? '' : ' (выключен)'; ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div>
							<label class="n2j-label" for="t-text">Фраза</label>
							<input id="t-text" name="text" class="n2j-input mt-1.5 w-full" value="<?php echo HTML::chars($text); ?>" placeholder="Что такое любовь? Двумя словами." required>
						</div>
					</div>
					<div class="flex flex-wrap items-center gap-4">
						<label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200"><input type="checkbox" name="is_new" value="1" class="h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900" <?php echo $is_new ? 'checked' : ''; ?>>Первая реплика сессии (приветствие)</label>
						<button type="submit" class="n2j-btn-process"><i class="fas fa-play mr-2" aria-hidden="true"></i>Спросить</button>
					</div>
				</form>
			</section>

			<?php if ($result !== NULL): ?>
				<section class="rounded-xl border border-blue-200 bg-blue-50/60 p-5 dark:border-blue-900/60 dark:bg-blue-950/20" aria-labelledby="test-answer-title">
					<h2 id="test-answer-title" class="n2j-h3">Ответ пользователю</h2>
					<p class="mt-3 whitespace-pre-line rounded-xl bg-white p-4 text-base leading-relaxed text-gray-900 shadow-xs dark:bg-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($body, 'text', '')); ?></p>
					<?php $tts = (string) Arr::get($body, 'tts', ''); if ($tts !== '' AND $tts !== Arr::get($body, 'text')): ?>
						<p class="mt-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><span class="font-semibold">Озвучка (TTS):</span> <?php echo HTML::chars($tts); ?></p>
					<?php endif; ?>
					<?php $buttons = (array) Arr::get($body, 'buttons', []); if ($buttons): ?>
						<div class="mt-3 flex flex-wrap gap-2">
							<?php foreach ($buttons as $button): ?>
								<span class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($button, 'title', '')); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<p class="n2j-hint mt-3">Сессия: <?php echo Arr::get($body, 'end_session') ? 'закрывается' : 'продолжается'; ?>.</p>
				</section>

				<section class="n2j-card" aria-labelledby="test-debug-title">
					<h2 id="test-debug-title" class="n2j-h3">Что произошло внутри</h2>
					<dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
						<div class="flex justify-between gap-3 border-b border-gray-100 pb-1.5 dark:border-gray-700"><dt class="text-gray-500 dark:text-gray-400">Сценарий</dt><dd class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($meta, 'intent', '')); ?></dd></div>
						<div class="flex justify-between gap-3 border-b border-gray-100 pb-1.5 dark:border-gray-700"><dt class="text-gray-500 dark:text-gray-400">Модель</dt><dd class="font-mono text-xs font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($meta, 'model', '—')); ?></dd></div>
						<div class="flex justify-between gap-3 border-b border-gray-100 pb-1.5 dark:border-gray-700"><dt class="text-gray-500 dark:text-gray-400">Время ответа</dt><dd class="font-semibold text-gray-900 dark:text-white"><?php echo (int) Arr::get($meta, 'ms', 0); ?> мс</dd></div>
						<div class="flex justify-between gap-3 border-b border-gray-100 pb-1.5 dark:border-gray-700"><dt class="text-gray-500 dark:text-gray-400">Реплик в контексте</dt><dd class="font-semibold text-gray-900 dark:text-white"><?php echo (int) Arr::get($meta, 'context', 0); ?></dd></div>
					</dl>
					<?php if (Arr::get($meta, 'error')): ?>
						<p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-950/30 dark:text-rose-300">Ошибка ИИ: <?php echo HTML::chars((string) $meta['error']); ?></p>
					<?php endif; ?>
					<details class="mt-3">
						<summary class="cursor-pointer text-xs font-semibold text-gray-500 dark:text-gray-400">JSON, который ушёл бы Яндексу</summary>
						<pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-900 p-3 text-xs leading-relaxed text-gray-100 dark:bg-black/40"><?php echo HTML::chars(json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
					</details>
				</section>
			<?php endif; ?>
		</main>

		<aside class="space-y-4">
			<section class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-lightbulb mr-2 text-blue-500" aria-hidden="true"></i>Что проверить</h2>
				<ul class="mt-3 space-y-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
					<li>«помощь» и «выход» — сработали ли сценарии без модели.</li>
					<li>Первую реплику — отрезалось ли имя активации, если задано.</li>
					<li>Тот же вопрос дважды — второй раз в контексте уже есть история.</li>
					<li>Скорость: Алиса ждёт вебхук пару секунд; если модель отвечает дольше, берите более быструю в настройках навыка.</li>
				</ul>
			</section>
			<section class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-comments mr-2 text-blue-500" aria-hidden="true"></i>Дальше</h2>
				<p class="mt-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Прогнанные реплики видны в журнале с пометкой «тест» — там же фильтр по источнику.</p>
				<a href="/alice/admin/history?source=test" class="n2j-btn-secondary n2j-btn-sm mt-3">Открыть журнал тестов</a>
			</section>
		</aside>
	</div>
</div>
