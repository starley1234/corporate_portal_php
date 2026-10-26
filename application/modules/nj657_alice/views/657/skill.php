<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/** @var array $form @var bool $is_new @var array $presets @var string $default_model @var string $webhook_base @var string $csrf @var array $errors @var bool $saved */
$label = 'n2j-label';
$input = 'n2j-input mt-1.5 w-full';
$area  = 'n2j-textarea mt-1.5 w-full leading-relaxed';
$check = 'mt-1 h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900';
$hint  = 'mt-1.5 text-xs leading-relaxed text-gray-400 dark:text-gray-500';

$webhook_full = $form['webhook_token'] !== '' ? $webhook_base.'/'.$form['webhook_token'] : $webhook_base;
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-microphone-lines text-blue-600 dark:text-blue-400" aria-hidden="true"></i><?php echo $is_new ? 'Новый навык Алисы' : 'Навык: '.HTML::chars($form['title']); ?></h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Промпт, модель и память — всё здесь. Справа «Окно памяти» подсказывает, как настройки влияют на разговор.</p>
		</div>
		<div class="flex items-center gap-2">
			<a href="/alice/admin" class="n2j-btn-secondary">К списку</a>
		</div>
	</div>

	<?php if ($saved): ?>
		<p class="n2j-flash n2j-flash-ok mt-5 block">Настройки сохранены. Скажите Алисе пару фраз и загляните в <a class="font-semibold underline" href="/alice/admin/history">журнал</a> или в <a class="font-semibold underline" href="/alice/admin/test">тестовую консоль</a>.</p>
	<?php endif; ?>

	<?php if ($errors): ?>
		<div class="n2j-flash n2j-flash-err mt-5 block" role="alert">
			<p class="font-semibold">Проверьте заполнение:</p>
			<ul class="mt-1 list-disc space-y-0.5 pl-5">
				<?php foreach ($errors as $err): ?><li><?php echo HTML::chars($err); ?></li><?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="post" action="/alice/admin/save" class="mt-6 xl:flex xl:items-start xl:gap-10">
		<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
		<?php if ( ! $is_new): ?><input type="hidden" name="code" value="<?php echo HTML::chars(Arr::get($form, 'code', '')); ?>"><?php endif; ?>

		<div class="min-w-0 flex-1 space-y-5">
			<section class="n2j-card" aria-labelledby="skill-main-title">
				<h2 id="skill-main-title" class="n2j-h3">Навык</h2>
				<div class="mt-4 grid gap-4 sm:grid-cols-2">
					<div class="sm:col-span-2">
						<label class="<?php echo $label; ?>" for="f-title">Название</label>
						<input id="f-title" name="title" class="<?php echo $input; ?>" maxlength="100" required value="<?php echo HTML::chars($form['title']); ?>" placeholder="Мой диалог">
						<p class="<?php echo $hint; ?>">Показывается только в панели управления.</p>
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-skill-id">ID навыка Яндекса</label>
						<input id="f-skill-id" name="yandex_skill_id" class="<?php echo $input; ?>" maxlength="64" value="<?php echo HTML::chars($form['yandex_skill_id']); ?>" placeholder="7c7cc605-8b9b-4c44-a5d1-...">
						<p class="<?php echo $hint; ?>">UUID из консоли Диалогов. Запросы с этим ID уйдут в данный навык. Пусто — ловит запросы без привязки.</p>
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-token">Токен вебхука</label>
						<div class="mt-1.5 flex gap-2">
							<input id="f-token" name="webhook_token" class="n2j-input min-w-0 flex-1 font-mono" maxlength="64" value="<?php echo HTML::chars($form['webhook_token']); ?>" data-webhook-base="<?php echo HTML::chars($webhook_base); ?>" autocomplete="off">
							<button type="button" id="nj657-token-gen" class="n2j-btn-secondary n2j-btn-sm shrink-0" title="Сгенерировать новый токен">Новый</button>
						</div>
						<p class="<?php echo $hint; ?>">Адрес вебхука с токеном: <code id="nj657-webhook-full" class="break-all font-semibold text-blue-700 dark:text-blue-300"><?php echo HTML::chars($webhook_full); ?></code></p>
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-activation">Имена активации</label>
						<textarea id="f-activation" name="activation_names" rows="2" class="<?php echo $area; ?>" placeholder="Мой диалог&#10;мой второй навык"><?php echo HTML::chars($form['activation_names']); ?></textarea>
						<p class="<?php echo $hint; ?>">По одному в строке. В первой реплике сессии отрезается от вопроса («запусти Мой диалог и скажи…» → «скажи…»).</p>
					</div>
					<div class="flex items-end gap-5">
						<label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200"><input type="checkbox" name="is_active" value="1" class="<?php echo $check; ?>" <?php echo $form['is_active'] ? 'checked' : ''; ?>>Навык включён</label>
						<label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200" title="Отвечает на запросы без ID и токена"><input type="checkbox" name="is_default" value="1" class="<?php echo $check; ?>" <?php echo $form['is_default'] ? 'checked' : ''; ?>>По умолчанию</label>
					</div>
				</div>
			</section>

			<section class="n2j-card" aria-labelledby="skill-ai-title">
				<h2 id="skill-ai-title" class="n2j-h3">Ответы ИИ</h2>
				<div class="mt-4">
					<label class="<?php echo $label; ?>" for="f-system">Системный промпт (роль и характер)</label>
					<textarea id="f-system" name="system_text" rows="6" class="<?php echo $area; ?>" placeholder="Ты опытный Врач, твоя задача проконсультировать пользователя…"><?php echo HTML::chars($form['system_text']); ?></textarea>
					<div class="mt-2 flex flex-wrap gap-1.5">
						<?php foreach ($presets as $preset): ?>
							<button type="button" class="n2j-btn-secondary n2j-btn-sm nj657-preset" data-text="<?php echo HTML::chars($preset['text']); ?>"><?php echo HTML::chars($preset['title']); ?></button>
						<?php endforeach; ?>
					</div>
					<p class="<?php echo $hint; ?>">К промпту автоматически добавляется указание отвечать кратко и без разметки — голосовой канал. Пусто — модель без роли.</p>
				</div>
				<div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
					<div class="sm:col-span-2">
						<label class="<?php echo $label; ?>" for="f-model">Модель</label>
						<input id="f-model" name="model" class="n2j-input mt-1.5 w-full font-mono" maxlength="120" value="<?php echo HTML::chars($form['model']); ?>" placeholder="<?php echo HTML::chars($default_model); ?>">
						<p class="<?php echo $hint; ?>">Формат «провайдер::модель». Пусто — модель платформы по умолчанию (<?php echo $default_model !== '' ? HTML::chars($default_model) : 'см. конфиг llm'; ?>).</p>
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-temp">Температура</label>
						<input id="f-temp" name="temperature" type="number" min="0" max="2" step="0.05" class="<?php echo $input; ?>" value="<?php echo HTML::chars($form['temperature']); ?>">
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-tokens">Лимит токенов</label>
						<input id="f-tokens" name="max_tokens" type="number" min="1" max="4000" step="1" class="<?php echo $input; ?>" value="<?php echo HTML::chars((string) $form['max_tokens']); ?>">
					</div>
					<div class="sm:col-span-2">
						<label class="<?php echo $label; ?>" for="f-maxchars">Максимум символов ответа</label>
						<input id="f-maxchars" name="answer_max_chars" type="number" min="100" max="4000" step="1" class="<?php echo $input; ?>" value="<?php echo HTML::chars((string) $form['answer_max_chars']); ?>">
						<p class="<?php echo $hint; ?>">Длинный ответ обрежется по границе предложения — Алисе хватает и 500–1000.</p>
					</div>
					<div class="sm:col-span-2">
						<label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200"><input type="checkbox" name="end_session" value="1" class="<?php echo $check; ?>" <?php echo $form['end_session'] ? 'checked' : ''; ?>>Закрывать сессию после каждого ответа</label>
						<p class="<?php echo $hint; ?>">Без галочки разговор продолжается и модель помнит контекст; с галочкой каждая реплика — отдельный вопрос-ответ.</p>
					</div>
				</div>
			</section>

			<section class="n2j-card" aria-labelledby="skill-dialog-title">
				<h2 id="skill-dialog-title" class="n2j-h3">Сценарии и кнопки</h2>
				<div class="mt-4 grid gap-4 sm:grid-cols-2">
					<div>
						<label class="<?php echo $label; ?>" for="f-welcome">Приветствие (новая сессия)</label>
						<textarea id="f-welcome" name="welcome_text" rows="3" class="<?php echo $area; ?>" placeholder="Здравствуйте! Спросите меня о чём угодно."><?php echo HTML::chars($form['welcome_text']); ?></textarea>
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-welcome-tts">Приветствие для речи (TTS, с ударениями)</label>
						<textarea id="f-welcome-tts" name="welcome_tts" rows="3" class="<?php echo $area; ?>" placeholder="Здр+авствуйте! Спрос+ите меня о чём уг+одно."><?php echo HTML::chars($form['welcome_tts']); ?></textarea>
						<p class="<?php echo $hint; ?>">Плюс перед гласной — ударение. Пусто — озвучится текст приветствия.</p>
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-help">Ответ на «помощь»</label>
						<textarea id="f-help" name="help_text" rows="3" class="<?php echo $area; ?>"><?php echo HTML::chars($form['help_text']); ?></textarea>
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-farewell">Прощание («выход», «стоп»)</label>
						<textarea id="f-farewell" name="farewell_text" rows="3" class="<?php echo $area; ?>"><?php echo HTML::chars($form['farewell_text']); ?></textarea>
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-fallback">Ответ при сбое ИИ</label>
						<textarea id="f-fallback" name="fallback_text" rows="3" class="<?php echo $area; ?>"><?php echo HTML::chars($form['fallback_text']); ?></textarea>
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-buttons">Кнопки (по одной в строке, до 5)</label>
						<textarea id="f-buttons" name="buttons_text" rows="3" class="<?php echo $area; ?>" placeholder="Ещё вопрос&#10;Помощь"><?php echo HTML::chars($form['buttons_text']); ?></textarea>
						<p class="<?php echo $hint; ?>">Показываются на экранах (колонка с дисплеем, приложение). Нажатие отправляет название кнопки как новую реплику.</p>
					</div>
				</div>
			</section>

			<section class="n2j-card" aria-labelledby="skill-mem-title">
				<h2 id="skill-mem-title" class="n2j-h3">Память и устройства</h2>
				<div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
					<div>
						<label class="<?php echo $label; ?>" for="f-depth">Глубина памяти (реплик)</label>
						<input id="f-depth" name="history_depth" type="number" min="0" max="30" step="1" class="<?php echo $input; ?>" value="<?php echo HTML::chars((string) $form['history_depth']); ?>">
					</div>
					<div>
						<label class="<?php echo $label; ?>" for="f-window">Окно памяти (минут)</label>
						<input id="f-window" name="history_window_min" type="number" min="1" max="10080" step="1" class="<?php echo $input; ?>" value="<?php echo HTML::chars((string) $form['history_window_min']); ?>">
					</div>
					<div class="sm:col-span-2">
						<label class="<?php echo $label; ?>" for="f-mqtt">MQTT-топик ответа</label>
						<input id="f-mqtt" name="mqtt_topic" class="<?php echo $input; ?>" maxlength="200" value="<?php echo HTML::chars($form['mqtt_topic']); ?>" placeholder="hasp/esp32_ips_480x480/command/p1b1.text">
						<p class="<?php echo $hint; ?>">Каждый ответ навыка публикуется в этот топик — для домашних дисплеев. Пусто — не публиковать.</p>
					</div>
				</div>
			</section>

			<div class="flex flex-wrap items-center gap-3">
				<button type="submit" class="n2j-btn-cta">Сохранить навык</button>
				<a href="/alice/admin" class="min-h-[44px] inline-flex items-center px-2 text-sm font-semibold text-gray-500 transition hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">Отмена</a>
			</div>

			<?php if ( ! $is_new): ?>
				<div class="rounded-xl border border-rose-200 bg-rose-50/50 p-5 dark:border-rose-900/60 dark:bg-rose-950/20">
					<h2 class="text-sm font-semibold text-rose-800 dark:text-rose-300">Удаление навыка</h2>
					<p class="mt-1 text-xs leading-relaxed text-rose-700 dark:text-rose-400">Навык и весь его журнал диалогов удаляются безвозвратно. Навык в консоли Яндекса останется — ему нужно будет указать другой вебхук.</p>
					<form method="post" action="/alice/admin/delete" class="mt-3">
						<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<input type="hidden" name="code" value="<?php echo HTML::chars(Arr::get($form, 'code', '')); ?>">
						<button data-n2j-arm="Точно удалить?" type="submit" class="n2j-btn-danger">Удалить навык</button>
					</form>
				</div>
			<?php endif; ?>
		</div>

		<aside class="mt-6 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
			<section class="rounded-xl border border-blue-200 bg-blue-50/60 p-5 dark:border-blue-900/60 dark:bg-blue-950/30">
				<h2 class="text-sm font-semibold text-blue-900 dark:text-blue-200"><i class="fas fa-plug-circle-bolt mr-2" aria-hidden="true"></i>Подключение</h2>
				<p class="mt-2 text-xs leading-relaxed text-blue-800 dark:text-blue-300">В консоли Яндекс.Диалогов вставьте в поле Webhook URL адрес:</p>
				<code class="mt-2 block break-all rounded-lg bg-white px-3 py-2 text-xs font-semibold text-gray-900 dark:bg-gray-900 dark:text-blue-300" id="nj657-webhook-copy"><?php echo HTML::chars($webhook_full); ?></code>
				<button type="button" id="nj657-copy" class="n2j-btn-secondary n2j-btn-sm mt-2"><i class="fas fa-copy mr-1.5" aria-hidden="true"></i>Скопировать</button>
				<p class="mt-2 text-xs leading-relaxed text-blue-700 dark:text-blue-400">Токен в адресе — защита от чужих запросов: без него навык подбирается по ID из консоли, а если и его нет — отвечает навык «по умолчанию».</p>
			</section>
			<section class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-brain mr-2 text-blue-500" aria-hidden="true"></i>Окно памяти</h2>
				<p class="mt-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Перед каждым вопросом модель получает последние реплики этого же пользователя: сколько — «глубина», насколько свежие — «окно». Разговор постарше окна начинается с чистого листа.</p>
				<p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Глубина 4–8 реплик обычно достаточна; больше — дороже и медленнее.</p>
			</section>
			<section class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-vial mr-2 text-blue-500" aria-hidden="true"></i>Проверка без колонки</h2>
				<p class="mt-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Сохраните навык и прогоните фразу в тестовой консоли: тот же пайплайн, что и у Алисы — маршрутизация, память, модель и сборка ответа.</p>
				<a href="/alice/admin/test" class="n2j-btn-secondary n2j-btn-sm mt-3">Открыть консоль</a>
			</section>
		</aside>
	</form>
</div>
<?php echo View::factory('657/script'); ?>
