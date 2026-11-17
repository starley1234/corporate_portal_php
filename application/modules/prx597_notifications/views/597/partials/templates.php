<?php defined('SYSPATH') or die('No direct script access.'); ?>

	<?php
	// Стенды и старые вызовы могут не передать данные ИИ — живём на умолчаниях.
	$ai_ready = isset($ai_ready) ? (bool) $ai_ready : Service_Prx597_Ai::ready($ai_default_why);
	$ai_why   = isset($ai_why) ? (string) $ai_why : (string) $ai_default_why;
	$ai_model = isset($ai_model) ? (string) $ai_model : Service_Prx597_Ai::model_label();
	$ai_tones = (isset($ai_tones) AND is_array($ai_tones) AND $ai_tones) ? $ai_tones : Service_Prx597_Ai::tones();

	// «Имеющееся сообщение» можно не искать в своей почте: последние отправки и
	// свежая лента уже рядом — из них и собираем шаблон.
	$ai_sources = [];

	foreach (array_slice(isset($feed) ? (array) $feed : [], 0, 10) as $row)
	{
		$label = trim((string) Arr::get($row, 'title', ''));
		$text  = trim($label.((string) Arr::get($row, 'body', '') !== '' ? "\n\n".trim((string) Arr::get($row, 'body', '')) : ''));

		if ($text !== '')
		{
			$ai_sources[] = ['label' => 'В ленте: '.mb_substr($label !== '' ? $label : 'без заголовка', 0, 70), 'text' => $text];
		}
	}

	foreach (array_slice(isset($sent) ? (array) $sent : [], 0, 10) as $row)
	{
		$label = trim((string) Arr::get($row, 'subject', ''));
		$text  = trim($label.((string) Arr::get($row, 'message', '') !== '' ? "\n".trim((string) Arr::get($row, 'message', '')) : ''));

		if ($text !== '')
		{
			$ai_sources[] = ['label' => 'Отправлено ('.Service_Prx597_Registry::channel_label((string) Arr::get($row, 'channel', '')).'): '.mb_substr($label !== '' ? $label : 'без темы', 0, 60), 'text' => $text];
		}
	}
	?>
	<!-- ================= ШАБЛОНЫ ================= -->
	<?php
	// Счётчики заполненности: событий десятки, глазами такое не окинешь, а
	// пустой шаблон — это «голое» уведомление в почте пользователя.
	$tpl_filled = 0;
	$tpl_total  = 0;
	$ch_keys    = array_keys((array) Service_Prx597_Registry::channels(FALSE));

	foreach ($events_on as $event)
	{
		foreach ($ch_keys as $ch_key)
		{
			$tpl_total++;

			if (isset($templates[$event['alias']][$ch_key]) AND trim((string) $templates[$event['alias']][$ch_key]['body']) !== '')
			{
				$tpl_filled++;
			}
		}
	}
	?>
	<div class="mt-5 space-y-4">
		<!-- Поиск по событиям и фильтр «без текста» -->
		<div class="prx-card p-0 flex flex-wrap items-center gap-3 px-4 py-3">
			<label class="flex min-w-[220px] flex-1 items-center gap-2">
				<i class="fas fa-magnifying-glass text-gray-400"></i>
				<input type="search" data-prx597-tpl-filter class="prx-input w-full" placeholder="Найти событие: тренды, сборка, prx667…" autocomplete="off">
			</label>
			<label class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
				<input type="checkbox" data-prx597-tpl-gaps class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"> только без текста
			</label>
			<span class="text-xs text-gray-500 dark:text-gray-400">заполнено <strong class="text-gray-700 dark:text-gray-200" data-prx597-tpl-count><?php echo (int) $tpl_filled; ?></strong> из <?php echo (int) $tpl_total; ?></span>
			<span class="text-xs text-amber-600 dark:text-amber-400" data-prx597-tpl-hidden></span>
		</div>
		<!-- ИИ-помощник: собрать шаблон из имеющегося сообщения -->
		<div class="rounded-xl border border-blue-200 bg-blue-50/50 shadow-xs dark:border-blue-800/60 dark:bg-blue-950/20">
			<div class="flex flex-wrap items-center justify-between gap-2 border-b border-blue-100 px-4 py-3.5 dark:border-blue-800/50">
				<div>
					<h2 class="prx-h3"><i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500"></i>Составить шаблон с ИИ</h2>
					<p class="prx-hint mt-0.5">Вставьте сообщение, которое уже писали людям, — ИИ перепишет его в шаблон выбранного события и канала: сохранит подстановки, добавит тему письма и уберёт лишнее. Ничего не сохраняется, пока вы не нажмёте «Сохранить» в нужной карточке.</p>
				</div>
				<span class="prx-badge <?php echo $ai_ready ? 'prx-badge-ok' : 'prx-badge-err'; ?>"><span class="prx-dot"></span><?php echo $ai_ready ? 'ИИ готов' : 'ИИ недоступен'; ?></span>
			</div>
			<?php if ( ! $ai_ready): ?>
			<div class="px-4 py-4 text-sm text-amber-700 dark:text-amber-300">
				<i class="fas fa-triangle-exclamation mr-1.5"></i><?php echo HTML::chars((string) $ai_why); ?>
				<span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Шаблоны можно писать руками — редактор ниже работает как обычно.</span>
			</div>
			<?php else: ?>
			<form autocomplete="off" data-prx597-ai class="grid gap-3 px-4 py-4 lg:grid-cols-2">
				<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
				<label class="block">
					<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Событие</span>
					<select name="event" class="prx-input prx-select mt-1 w-full">
						<?php foreach ($events_on as $event): ?>
						<option value="<?php echo HTML::chars($event['alias']); ?>" <?php echo $event['alias'] === 'system.message' ? 'selected' : ''; ?>><?php echo HTML::chars($event['title']); ?> — <?php echo HTML::chars($event['alias']); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="grid gap-3 sm:grid-cols-2">
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Канал</span>
						<select name="channel" class="prx-input prx-select mt-1 w-full">
							<?php foreach (Service_Prx597_Ai::channel_labels() as $ch_key => $ch_label): ?>
							<option value="<?php echo HTML::chars($ch_key); ?>"><?php echo HTML::chars($ch_label); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="block">
						<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Тон</span>
						<select name="tone" class="prx-input prx-select mt-1 w-full">
							<?php foreach ($ai_tones as $t_key => $t_info): ?>
							<option value="<?php echo HTML::chars($t_key); ?>"><?php echo HTML::chars($t_info[0]); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<label class="block lg:col-span-2">
					<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">О чём уведомляем (необязательно)</span>
					<input type="text" name="about" maxlength="400" placeholder="Например: обзор собран и ждёт публикации" class="prx-input mt-1 w-full" autocomplete="off">
				</label>
				<?php if ($ai_sources): ?>
				<label class="block lg:col-span-2">
					<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Взять готовое сообщение (за сутки)</span>
					<select data-prx597-ai-recent class="prx-input prx-select mt-1 w-full">
						<option value="">— выбрать из отправленного или из ленты —</option>
						<?php foreach ($ai_sources as $i => $src): ?>
						<option value="<?php echo (int) $i; ?>" data-text="<?php echo HTML::chars($src['text']); ?>"><?php echo HTML::chars($src['label']); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php endif; ?>
				<label class="block lg:col-span-2">
					<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Имеющееся сообщение — из него собираем шаблон</span>
					<textarea name="source" rows="6" maxlength="4000" required placeholder="Вставьте текст, который вы уже отправляли или собирались отправить людям" class="prx-textarea mt-1 w-full" autocomplete="off"></textarea>
				</label>
				<div class="flex flex-wrap items-center gap-2 lg:col-span-2">
					<button type="submit" class="prx-btn-process" data-prx597-ai-run><i class="fas fa-wand-magic-sparkles mr-2"></i>Собрать шаблон</button>
					<span class="text-xs text-gray-400">модель: <?php echo HTML::chars((string) $ai_model); ?></span>
				</div>
			</form>
			<div class="hidden border-t border-blue-100 px-4 py-4 dark:border-blue-800/50" data-prx597-ai-out>
				<div class="mb-2 flex flex-wrap items-center justify-between gap-2">
					<span class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-file-lines mr-1.5 text-blue-500"></i>Что собрал ИИ</span>
					<span class="text-xs text-gray-400" data-prx597-ai-meta></span>
				</div>
				<div class="space-y-2 text-sm">
					<div><span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Тема:</span>
						<span class="block rounded-lg bg-white px-3 py-2 font-medium text-gray-900 dark:bg-gray-900 dark:text-gray-100" data-prx597-ai-subject></span></div>
					<div><span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Текст:</span>
						<div class="block whitespace-pre-wrap rounded-lg bg-white px-3 py-2 text-gray-800 dark:bg-gray-900 dark:text-gray-100" data-prx597-ai-body></div></div>
					<p class="prx-hint" data-prx597-ai-notes></p>
				</div>
				<div class="mt-3 flex flex-wrap items-center gap-2">
					<button type="button" class="prx-btn-process prx-btn-sm" data-prx597-ai-apply><i class="fas fa-arrow-down mr-1.5"></i>Подставить в шаблон события и канала</button>
					<button type="button" class="prx-btn-secondary prx-btn-sm" data-prx597-ai-again><i class="fas fa-rotate mr-1.5"></i>Собрать заново</button>
					<span class="text-xs text-gray-400" data-prx597-ai-flash></span>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<?php foreach ($events_on as $event):
			$mine = 0;

			foreach ($ch_keys as $ch_key)
			{
				if (isset($templates[$event['alias']][$ch_key]) AND trim((string) $templates[$event['alias']][$ch_key]['body']) !== '')
				{
					$mine++;
				}
			}
		?>
		<details open class="prx-card p-0" data-prx597-tpl-card data-prx597-tpl-search="<?php echo HTML::chars(mb_strtolower($event['title'].' '.$event['alias'].' '.Arr::get($event, 'category', ''))); ?>" data-prx597-tpl-filled="<?php echo (int) $mine; ?>">
			<summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-4 py-3.5">
				<h2 class="prx-h3">
					<i class="<?php echo HTML::chars($event['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars($event['title']); ?>
					<code class="ml-2 text-xs font-normal text-gray-400"><?php echo HTML::chars($event['alias']); ?></code>
					<span class="prx-badge <?php echo $mine === 0 ? 'prx-badge-run' : 'prx-badge-ok'; ?> ml-2"><span class="prx-dot"></span><?php echo $mine === 0 ? 'нет текстов' : $mine.' из '.count($ch_keys).' каналов'; ?></span>
				</h2>
				<span class="text-xs text-gray-400">подстановки:
					<?php foreach (array_merge(Service_Prx597_Base::_csv(Arr::get($event, 'placeholders', '')), ['user_name', 'site_url', 'href']) as $ph): ?>
					<button type="button" data-prx597-insert="{{<?php echo HTML::chars($ph); ?>}}" class="ml-0.5 rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-600 transition hover:bg-blue-100 hover:text-blue-700 dark:bg-gray-700 dark:text-gray-300" title="Вставить в поле шаблона">{{<?php echo HTML::chars($ph); ?>}}</button>
					<?php endforeach; ?>
				</span>
			</div>
			<div class="grid gap-4 px-4 py-4 lg:grid-cols-2">
				<?php foreach (Service_Prx597_Registry::channels(FALSE) as $key => $info):
					$tpl = $templates[$event['alias']][$key] ?? NULL;
				?>
				<form autocomplete="off" method="post" action="/prx597/admin" class="rounded-lg border border-gray-100 p-3 dark:border-gray-700/60" data-prx597-tpl-event="<?php echo HTML::chars($event['alias']); ?>" data-prx597-tpl-channel="<?php echo HTML::chars((string) $key); ?>">
					<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
					<input type="hidden" name="act" value="template_save">
					<input type="hidden" name="tab" value="templates">
					<input type="hidden" name="event" value="<?php echo HTML::chars($event['alias']); ?>">
					<input type="hidden" name="channel" value="<?php echo HTML::chars((string) $key); ?>">
					<div class="mb-2 flex items-center justify-between">
						<span class="text-sm font-semibold text-gray-800 dark:text-gray-100"><i class="<?php echo HTML::chars($info['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars($info['label']); ?>
						<?php if ($tpl === NULL OR trim((string) Arr::get((array) $tpl, 'body', '')) === ''): ?>
						<span class="prx-badge prx-badge-wait ml-1.5"><span class="prx-dot"></span>нет текста</span>
						<?php endif; ?>
						</span>
						<label class="inline-flex items-center gap-1.5 text-xs"><input type="checkbox" name="is_active" value="1" <?php echo ($tpl === NULL OR ! empty($tpl['is_active'])) ? 'checked' : ''; ?> class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"> вкл.</label>
					</div>
					<?php if ((string) $key !== 'in_app'): ?>
					<input type="text" name="subject" value="<?php echo HTML::chars((string) Arr::get((array) $tpl, 'subject', '')); ?>" placeholder="Тема ({{title}})"
					       class="prx-input mb-2 w-full py-1.5 text-sm min-h-0" autocomplete="off">
					<?php else: ?>
					<input type="hidden" name="subject" value="<?php echo HTML::chars((string) Arr::get((array) $tpl, 'subject', '')); ?>">
					<?php endif; ?>
					<textarea autocomplete="off" name="body" rows="3" placeholder="Текст: {{title}}, {{user_name}}, {{site_url}}" class="prx-textarea w-full"><?php echo HTML::chars((string) Arr::get((array) $tpl, 'body', '')); ?></textarea>
					<div class="mt-2 flex flex-wrap items-center gap-2">
						<select name="format" class="prx-input prx-select py-1 text-xs min-h-0 w-auto">
							<?php foreach (['text' => 'обычный текст', 'html' => 'HTML', 'md' => 'Markdown'] as $fmt => $label): ?>
							<option value="<?php echo $fmt; ?>" <?php echo (string) Arr::get((array) $tpl, 'format', 'text') === $fmt ? 'selected' : ''; ?>><?php echo $label; ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" data-prx597-tpl-preview class="prx-btn-secondary prx-btn-sm"><i class="fas fa-eye mr-1"></i>Предпросмотр</button>
						<button type="submit" class="prx-btn-cta prx-btn-sm">Сохранить</button>
						<?php if ($tpl !== NULL): ?>
						<span class="text-xs text-gray-400">изменён: <?php echo HTML::chars((string) Arr::get($tpl, 'updated_at', '')); ?></span>
						<?php endif; ?>
					</div>
					<div data-prx597-tpl-out class="mt-2 hidden rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-200"></div>
				</form>
				<?php endforeach; ?>
			</div>
		</details>
		<?php endforeach; ?>
	</div>
