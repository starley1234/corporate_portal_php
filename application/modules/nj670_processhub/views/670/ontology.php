<?php defined('SYSPATH') or die('No direct script access.');
$grouped = isset($grouped) ? (array) $grouped : [];
$types = isset($types) ? (array) $types : [];
$error = isset($error) ? (string) $error : '';
$workspace = isset($workspace) ? (array) $workspace : [];
$feed = isset($feed) && $feed ? (array) $feed : NULL;
$feed_view = isset($feed_view) ? (array) $feed_view : [];
$feed_jobs = isset($feed_jobs) ? (array) $feed_jobs : [];
$feed_statuses = isset($feed_statuses) ? (array) $feed_statuses : [];
$worker = isset($worker) ? (array) $worker : [];
$feed_live = ($feed AND (int) Arr::get($feed, 'job_id', 0) > 0)
	? Service_Nj670_Process::job_state((int) $feed['job_id'])
	: [];
$field = 'n2j-input w-full py-1.5 n2j-ds-body min-h-0';
$feed_status = $feed ? (string) $feed['status'] : '';
$feed_done = $feed_status === Service_Nj670_Ontologyfeed::S_DONE;
$feed_error = $feed_status === Service_Nj670_Ontologyfeed::S_ERROR;
$accepted = (array) Arr::get($feed_view, 'accepted', []);
$can_accept = $feed_done && empty($feed['accepted_at']);
$feed_label = (string) Arr::get($feed_statuses, $feed_status, $feed_status);
$badge = $feed_done ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
	: ($feed_error ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300'
	: 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300');
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-center justify-between gap-2">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-sitemap mr-2 text-blue-500"></i>Онтология компании</h1>
			<p class="mt-1 max-w-3xl n2j-ds-body text-gray-600 dark:text-gray-300">Словарь, из которого собираются все схемы: роли, системы, артефакты, события, метрики, правила и подразделения. Код сущности неизменяем — на него ссылаются версии процессов.</p>
		</div>
		<div class="flex items-center gap-3 n2j-ds-sm font-semibold">
			<a href="/process/map" class="text-blue-600 hover:underline dark:text-blue-400"><i class="fas fa-building mr-1"></i>Карта организации</a>
			<a href="/process" class="text-blue-600 hover:underline dark:text-blue-400">← К процессам</a>
		</div>
	</div>

	<?php if ($error !== ''): ?>
	<div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 n2j-ds-body font-semibold text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300"><?php echo HTML::chars($error); ?></div>
	<?php endif; ?>

	<?php if ( ! empty($notice)): ?>
	<div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 n2j-ds-body font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"><?php echo HTML::chars($notice); ?></div>
	<?php endif; ?>

	<!-- ---------------------------------------------------------- разбор документа -->
	<section class="n2j-card p-0 mt-5 overflow-hidden">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
			<div>
				<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-gray-200"><i class="fas fa-wand-magic-sparkles mr-1.5"></i>Заполнить онтологию из документа</h2>
				<p class="mt-0.5 max-w-3xl n2j-ds-xs text-gray-600 dark:text-gray-300/70">Вставьте регламент, должностную инструкцию или выгрузку — модель предложит роли, системы, артефакты, события, метрики, правила, подразделения и черновики процессов. Ничего не создаётся без вашего подтверждения.</p>
			</div>
			<span class="n2j-badge <?php echo ! empty($worker['online']) ? 'n2j-badge-ok' : 'n2j-badge-run'; ?>" title="<?php echo ! empty($worker['online']) ? 'Воркер ответил в последние 5 минут' : 'Фоновый обработчик не отвечает: задача встанет в очередь и выполнится после запуска воркера'; ?>">
				<i class="fas fa-circle mr-1 text-[6px]"></i><?php echo ! empty($worker['online']) ? 'воркер на связи' : 'воркер не отвечает'; ?>
			</span>
		</div>

		<div class="grid gap-4 p-4 lg:grid-cols-3">
			<form method="post" action="/process/ontology" enctype="multipart/form-data" class="lg:col-span-2" id="nj670-feed-form">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<input type="hidden" name="action" value="extract">
				<label for="nj670-feed-text" class="n2j-ds-xs font-semibold text-gray-700 dark:text-gray-200">Текст регламента или выгрузки</label>
				<textarea id="nj670-feed-text" name="text" rows="6" maxlength="20000" class="n2j-textarea mt-1 w-full n2j-ds-sm" placeholder="Например: Отдел закупок формирует заявку в 1С, руководитель согласует, юрист проверяет договор, бухгалтерия оплачивает счёт. Проверка контрагента — служба безопасности, срок 3 дня."></textarea>
				<div class="mt-2 flex flex-wrap items-center gap-2">
					<label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 n2j-ds-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
						<i class="fas fa-paperclip"></i><span id="nj670-feed-file-label">Файл: PDF, фото, аудио, txt</span>
						<input type="file" name="source" id="nj670-feed-file" class="hidden" accept=".txt,.md,.pdf,.png,.jpg,.jpeg,.webp,.mp3,.m4a,.wav,.ogg">
					</label>
					<label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 n2j-ds-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200" title="CSV из выгрузки подставится в текст, чтобы модель увидела шаги">
						<i class="fas fa-table"></i>CSV в текст
						<input type="file" id="nj670-feed-csv" class="hidden" accept=".csv,.txt">
					</label>
					<button type="submit" class="n2j-btn-process ml-auto"><i class="fas fa-microchip mr-1.5"></i>Разобрать</button>
				</div>
				<p class="mt-2 n2j-ds-xs text-gray-500 dark:text-gray-400">Текст — от 40 символов. PDF/сканы читаются через OCR, аудио — через расшифровку. CSV можно подставить в поле, чтобы модель увидела последовательность шагов.</p>
			</form>

			<div class="rounded-lg border border-gray-200 bg-gray-50/60 p-3 n2j-ds-xs text-gray-600 dark:border-gray-800 dark:bg-gray-800/40 dark:text-gray-300">
				<h3 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Что будет предложено</h3>
				<ul class="mt-2 space-y-1">
					<li><i class="fas fa-user-tie mr-1 text-gray-400"></i>Роли и должности</li>
					<li><i class="fas fa-server mr-1 text-gray-400"></i>Системы и инструменты</li>
					<li><i class="fas fa-file-lines mr-1 text-gray-400"></i>Артефакты: документы, заявки, счета</li>
					<li><i class="fas fa-bolt mr-1 text-gray-400"></i>События, метрики и правила контроля</li>
					<li><i class="fas fa-building mr-1 text-gray-400"></i>Подразделения</li>
					<li><i class="fas fa-diagram-project mr-1 text-gray-400"></i>Черновики процессов с шагами</li>
				</ul>
				<p class="mt-2 n2j-ds-xs text-gray-500 dark:text-gray-400">Совпадения по коду помечаются «уже есть» — дубли не создаются.</p>
			</div>
		</div>

		<?php if ($feed): ?>
		<div class="border-t border-gray-100 px-4 py-3 dark:border-gray-800" id="nj670-feed-status" data-job="<?php echo (int) $feed['id']; ?>" data-status="<?php echo HTML::chars($feed_status); ?>">
			<div class="flex flex-wrap items-center gap-2 n2j-ds-xs">
				<span class="rounded-full px-2 py-0.5 font-bold <?php echo $badge; ?>"><?php echo HTML::chars($feed_label); ?></span>
				<span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $feed['source_name'] !== '' ? (string) $feed['source_name'] : 'вставленный текст'); ?></span>
				<?php if ( ! empty($feed['model'])): ?><span class="font-mono n2j-ds-xs text-gray-400"><?php echo HTML::chars((string) $feed['model']); ?></span><?php endif; ?>
				<span class="text-gray-400"><?php echo HTML::chars((string) $feed['created_at']); ?></span>
				<?php if ( ! empty($accepted['at'])): ?><span class="rounded bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">принято: <?php echo (int) Arr::get($accepted, 'entities', 0); ?> сущностей, <?php echo (int) Arr::get($accepted, 'processes', 0); ?> процессов</span><?php endif; ?>
			</div>

			<?php if ($feed_error): ?>
			<p class="mt-2 rounded-lg bg-rose-50 px-3 py-2 n2j-ds-sm font-medium text-rose-700 dark:bg-rose-950/40 dark:text-rose-300"><?php echo HTML::chars((string) $feed['error'] !== '' ? (string) $feed['error'] : 'Разбор не удался. Проверьте файл и повторите.'); ?></p>
			<?php elseif ( ! $feed_done): ?>
			<p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 n2j-ds-sm text-gray-600 dark:text-gray-300">
				<i class="fas fa-circle-notch fa-spin text-gray-400"></i>
				<span>Модель читает документ<span class="n2j-dots ml-1 text-gray-400" aria-hidden="true"><i></i><i></i><i></i></span></span>
				<span id="nj670-feed-stage" class="font-semibold text-gray-700 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($feed_live, 'stage', 'задача в очереди')); ?></span>
				<span id="nj670-feed-elapsed" class="font-mono text-gray-400" data-elapsed="<?php echo (int) Arr::get($feed_live, 'elapsed_sec', 0); ?>"></span>
				<span class="text-gray-400 dark:text-gray-500">Страница обновится сама — можно уйти и вернуться.</span>
			</p>
			<?php endif; ?>

			<?php if ($feed_done && $feed_view): ?>
			<div class="mt-4 border-t border-dashed border-gray-200 pt-3 dark:border-gray-700">
				<div class="flex flex-wrap items-center justify-between gap-2">
					<h3 class="n2j-ds-body font-bold text-gray-900 dark:text-white">Предложение модели</h3>
					<div class="flex flex-wrap items-center gap-1.5 n2j-ds-xs text-gray-500 dark:text-gray-400">
						<span class="rounded bg-gray-100 px-1.5 py-0.5 font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-400">новых сущностей: <?php echo (int) Arr::get($feed_view, 'new_count', 0); ?></span>
						<span class="rounded bg-gray-100 px-1.5 py-0.5 font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">процессов: <?php echo count((array) Arr::get($feed_view, 'processes', [])); ?></span>
						<?php foreach ((array) Arr::get($feed_view, 'stats', []) as $stat_type => $stat_count): ?>
							<?php if ((int) $stat_count > 0): ?><span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800"><?php echo HTML::chars((string) Arr::get($types, $stat_type, $stat_type)); ?>: <?php echo (int) $stat_count; ?></span><?php endif; ?>
						<?php endforeach; ?>
					</div>
				</div>
				<?php if ( ! empty($feed_view['note'])): ?><p class="mt-1 n2j-ds-xs italic text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $feed_view['note']); ?></p><?php endif; ?>

				<?php if ($can_accept): ?>
				<form method="post" action="/process/ontology" class="mt-3" id="nj670-accept-form">
				<?php else: ?>
				<div class="mt-3">
				<?php endif; ?>
					<?php if ($can_accept): ?>
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<input type="hidden" name="action" value="accept">
					<input type="hidden" name="job_id" value="<?php echo (int) $feed['id']; ?>">
					<?php endif; ?>

					<div class="grid gap-3 xl:grid-cols-2">
						<?php foreach ((array) Arr::get($feed_view, 'groups', []) as $type => $group): ?>
							<?php $items = (array) Arr::get($group, 'items', []); if (empty($items)) { continue; } ?>
							<section class="rounded-lg border border-gray-200 dark:border-gray-800">
								<div class="flex items-center justify-between border-b border-gray-100 px-3 py-2 dark:border-gray-800">
									<h4 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($group, 'label', $type)); ?></h4>
									<span class="n2j-ds-xs font-bold text-gray-400"><?php echo count($items); ?></span>
								</div>
								<ul class="divide-y divide-gray-100 n2j-ds-sm dark:divide-gray-800">
									<?php foreach ($items as $entity): ?>
									<?php $exists = ! empty($entity['exists']); ?>
									<li class="flex items-start gap-2 px-3 py-2">
										<?php if ($can_accept): ?>
										<input type="checkbox" name="entity[]" value="<?php echo HTML::chars($entity['class_type'].'|'.$entity['code']); ?>" class="mt-0.5 h-3.5 w-3.5 rounded border-gray-300 text-blue-600" <?php echo $exists ? 'disabled' : 'checked'; ?>>
										<?php else: ?>
										<i class="fas <?php echo $exists ? 'fa-circle-check text-emerald-500' : 'fa-plus text-gray-400'; ?> mt-0.5 w-3.5 text-center text-[11px]"></i>
										<?php endif; ?>
										<div class="min-w-0 flex-1">
											<div class="flex flex-wrap items-center gap-1.5">
												<span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $entity['name']); ?></span>
												<span class="font-mono n2j-ds-xs text-gray-400"><?php echo HTML::chars((string) $entity['code']); ?></span>
												<?php if ($exists): ?><span class="rounded bg-emerald-50 px-1.5 py-0.5 n2j-ds-xs font-bold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300" title="Совпало с существующим кодом">уже есть<?php echo trim((string) Arr::get($entity, 'existing_name', '')) !== '' ? ': '.HTML::chars((string) $entity['existing_name']) : ''; ?></span><?php endif; ?>
											</div>
											<?php
											$attrs = (array) Arr::get($entity, 'attributes', []);
											$pairs = [];
											foreach ($attrs as $k => $v) { if (is_scalar($v) AND $v !== '') { $pairs[] = $k.'='.(string) $v; } }
											?>
											<?php if ($pairs): ?><div class="n2j-ds-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(UTF8::substr(implode(' · ', $pairs), 0, 180)); ?></div><?php endif; ?>
											<?php if ( ! empty($entity['source'])): ?><div class="n2j-ds-xs italic text-gray-400">«<?php echo HTML::chars(UTF8::substr((string) $entity['source'], 0, 140)); ?>»</div><?php endif; ?>
										</div>
									</li>
									<?php endforeach; ?>
								</ul>
							</section>
						<?php endforeach; ?>
					</div>

					<?php $proposals = (array) Arr::get($feed_view, 'processes', []); ?>
					<?php if ($proposals): ?>
					<div class="mt-3">
						<div class="flex flex-wrap items-center justify-between gap-2">
							<h4 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300">Черновики процессов</h4>
							<label class="inline-flex items-center gap-1.5 n2j-ds-xs font-semibold text-gray-700 dark:text-gray-200">
								<input type="checkbox" name="make_processes" value="1" class="h-3.5 w-3.5 rounded border-gray-300 text-blue-600" <?php echo $can_accept ? 'checked' : 'disabled'; ?>>Создать выбранные
							</label>
						</div>
						<div class="mt-2 grid gap-2 md:grid-cols-2">
							<?php foreach ($proposals as $proposal): ?>
							<section class="h-full rounded-lg border border-gray-200 p-3 dark:border-gray-800">
								<label class="flex items-start gap-2">
									<input type="checkbox" name="process[]" value="<?php echo (int) $proposal['index']; ?>" class="mt-0.5 h-3.5 w-3.5 rounded border-gray-300 text-blue-600" <?php echo $can_accept ? 'checked' : 'disabled'; ?>>
									<span class="n2j-ds-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $proposal['title']); ?></span>
								</label>
								<div class="mt-1 flex flex-wrap gap-1.5 pl-5 n2j-ds-xs text-gray-500 dark:text-gray-400">
									<?php if ( ! empty($proposal['department'])): ?><span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800"><i class="fas fa-building mr-1"></i><?php echo HTML::chars((string) $proposal['department']); ?></span><?php endif; ?>
									<?php if ( ! empty($proposal['owner_role_code'])): ?><span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700 dark:bg-gray-800 dark:text-gray-400"><i class="fas fa-user-tie mr-1"></i><?php echo HTML::chars((string) $proposal['owner_role_code']); ?></span><?php endif; ?>
									<span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800"><?php echo count((array) $proposal['steps']); ?> шагов</span>
									<span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $proposal['minutes'])); ?></span>
									<?php if ((int) $proposal['wait'] > 0): ?><span class="rounded bg-amber-50 px-1.5 py-0.5 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">ожидание <?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $proposal['wait'])); ?></span><?php endif; ?>
								</div>
								<ol class="mt-2 space-y-1 pl-5 n2j-ds-xs text-gray-600 dark:text-gray-300">
									<?php foreach ((array) $proposal['steps'] as $step_no => $step): ?>
									<li class="flex gap-1.5"><span class="font-mono text-gray-400"><?php echo (int) $step_no + 1; ?>.</span><span><?php echo HTML::chars((string) $step['title']); ?><?php if ( ! empty($step['role_code'])): ?> <span class="text-blue-500">@<?php echo HTML::chars((string) $step['role_code']); ?></span><?php endif; ?><?php if ((int) $step['duration_minutes'] > 0): ?> <span class="text-gray-400"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $step['duration_minutes'])); ?></span><?php endif; ?></span></li>
									<?php endforeach; ?>
								</ol>
							</section>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<?php if ($can_accept): ?>
					<div class="mt-3 flex flex-wrap items-center justify-between gap-2">
						<p class="n2j-ds-xs text-gray-500 dark:text-gray-400">Выбранные сущности добавятся в справочник. Процессы создаются черновиками: их можно открыть на холсте и доработать.</p>
						<div class="flex items-center gap-2">
							<button type="button" id="nj670-toggle-all" class="n2j-btn-secondary n2j-btn-sm">Выбрать всё</button>
							<button type="submit" class="n2j-btn-cta"><i class="fas fa-check-double mr-1.5"></i>Принять выбранное</button>
						</div>
					</div>
					<?php else: ?>
					<div class="mt-3 flex flex-wrap items-center gap-2">
						<span class="n2j-ds-xs font-semibold text-emerald-700 dark:text-emerald-300"><i class="fas fa-circle-check mr-1"></i>Предложение принято</span>
						<?php foreach ((array) Arr::get($accepted, 'codes', []) as $code): ?>
							<a href="/process/<?php echo HTML::chars((string) $code); ?>" class="rounded bg-gray-100 px-2 py-1 n2j-ds-xs font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400"><?php echo HTML::chars((string) $code); ?></a>
						<?php endforeach; ?>
						<a href="/process/map" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-building mr-1"></i>Карта организации</a>
					</div>
					<?php endif; ?>
				<?php if ($can_accept): ?>
				</form>
				<?php else: ?>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ( ! empty($feed_jobs)): ?>
		<div class="border-t border-gray-100 bg-gray-50/50 px-4 py-2 dark:border-gray-800 dark:bg-gray-800/30">
			<span class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-400">Последние разборы</span>
			<div class="mt-1 flex flex-wrap gap-1.5">
				<?php foreach ($feed_jobs as $history): ?>
				<a href="/process/ontology?job=<?php echo (int) $history['id']; ?>" class="rounded px-1.5 py-0.5 n2j-ds-xs <?php echo $feed && (int) $feed['id'] === (int) $history['id'] ? 'bg-gray-200 font-bold text-gray-800 dark:bg-gray-800 dark:text-gray-200' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700'; ?>" title="<?php echo HTML::chars((string) $history['created_at']); ?>">
					#<?php echo (int) $history['id']; ?> <?php echo HTML::chars((string) Arr::get($feed_statuses, (string) $history['status'], (string) $history['status'])); ?><?php echo ! empty($history['source_name']) ? ' · '.HTML::chars(UTF8::substr((string) $history['source_name'], 0, 24)) : ''; ?>
				</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>
	</section>

	<!-- ---------------------------------------------------------- ручное добавление -->
	<section class="mt-5 rounded-xl border border-gray-200 bg-gray-100 p-4 dark:border-gray-700 dark:bg-gray-800">
		<h2 class="n2j-ds-body font-bold text-gray-800 dark:text-gray-300">Добавить сущность вручную</h2>
		<form method="post" action="/process/ontology" class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
			<input type="hidden" name="action" value="create">
			<label class="n2j-ds-xs font-semibold text-gray-700 dark:text-gray-200">Тип
				<select name="class_type" class="n2j-input n2j-select w-full py-1.5 n2j-ds-body min-h-0 mt-1">
					<?php foreach ($types as $type => $label): ?>
					<option value="<?php echo HTML::chars($type); ?>"><?php echo HTML::chars($label); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="n2j-ds-xs font-semibold text-gray-700 dark:text-gray-200">Код
				<input name="code" maxlength="64" required pattern="[a-z0-9_\-]+" placeholder="security" class="<?php echo $field; ?> mt-1">
			</label>
			<label class="n2j-ds-xs font-semibold text-gray-700 dark:text-gray-200 sm:col-span-2">Название
				<input name="name" maxlength="255" required placeholder="Служба безопасности" class="<?php echo $field; ?> mt-1">
			</label>
			<label class="n2j-ds-xs font-semibold text-gray-700 dark:text-gray-200">Ставка, ₽/час
				<input name="rate_per_hour" type="number" min="0" max="1000000" step="50" placeholder="1500" class="<?php echo $field; ?> mt-1">
			</label>
			<div class="flex items-end">
				<button type="submit" class="n2j-btn-cta w-full">Добавить</button>
			</div>
			<label class="n2j-ds-xs font-semibold text-gray-700 sm:col-span-2 lg:col-span-4 dark:text-gray-200">Описание / атрибуты
				<input name="notes" maxlength="500" placeholder="Например: проверяет контрагента до подписания договора" class="<?php echo $field; ?> mt-1">
			</label>
			<label class="n2j-ds-xs font-semibold text-gray-700 dark:text-gray-200">Категория системы
				<input name="system_kind" maxlength="40" placeholder="erp / edms / chat" class="<?php echo $field; ?> mt-1">
			</label>
			<label class="n2j-ds-xs font-semibold text-gray-700 dark:text-gray-200">Единица артефакта
				<input name="unit" maxlength="40" placeholder="документ" class="<?php echo $field; ?> mt-1">
			</label>
		</form>
		<p class="mt-2 n2j-ds-xs text-gray-600 dark:text-gray-300/70">Код латиницей: он попадает в JSON схемы и в отчёты. Ставка роли используется для расчёта стоимости и потерь.</p>
	</section>

	<!-- ---------------------------------------------------------- справочник -->
	<div class="mt-5 space-y-4">
		<?php
		$type_tone = [
			'role' => 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
			'system' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
			'artifact' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
		];
		$type_icon = ['role' => 'fa-user', 'system' => 'fa-server', 'artifact' => 'fa-file-lines'];
		?>
		<?php foreach ($grouped as $type => $group): ?>
		<?php $items = (array) Arr::get($group, 'items', []); ?>
		<section class="n2j-card p-0">
			<div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
				<h2 class="flex items-center gap-2 n2j-ds-body font-bold text-gray-900 dark:text-white"><span class="inline-flex h-6 w-6 items-center justify-center rounded-md <?php echo Arr::get($type_tone, $type, 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'); ?>"><i class="fas <?php echo Arr::get($type_icon, $type, 'fa-tag'); ?> text-[11px]"></i></span><?php echo HTML::chars((string) Arr::get($group, 'label', $type)); ?></h2>
				<span class="rounded-full bg-gray-100 px-2 py-0.5 n2j-ds-xs font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300"><?php echo count($items); ?></span>
			</div>
			<?php if (empty($items)): ?>
			<p class="px-4 py-3 n2j-ds-sm text-gray-500 dark:text-gray-400">Пусто. Добавьте первую сущность выше.</p>
			<?php else: ?>
			<div class="overflow-x-auto">
				<table class="n2j-table min-w-full">
					<thead class="bg-gray-50 n2j-ds-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
						<tr>
							<th class="px-4 py-2">Код</th>
							<th class="px-4 py-2">Название</th>
							<th class="px-4 py-2">Атрибуты</th>
							<th class="px-4 py-2">База</th>
							<th class="px-4 py-2 text-right">Действия</th>
						</tr>
					</thead>
					<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
						<?php foreach ($items as $entity): ?>
						<tr class="align-top hover:bg-gray-50/70 dark:hover:bg-gray-800/40">
							<td class="px-4 py-2 font-mono n2j-ds-xs text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $entity['code']); ?></td>
							<td class="px-4 py-2">
								<form method="post" action="/process/ontology" class="flex flex-wrap items-center gap-1.5">
									<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
									<input type="hidden" name="action" value="update">
									<input type="hidden" name="entity_id" value="<?php echo (int) $entity['id']; ?>">
									<input name="name" value="<?php echo HTML::chars((string) $entity['name']); ?>" maxlength="255" class="w-52 rounded border border-transparent bg-transparent px-1 py-0.5 n2j-ds-sm font-semibold text-gray-900 hover:border-gray-300 focus:border-blue-500 focus:outline-none dark:text-white dark:hover:border-gray-600">
									<input name="rate_per_hour" type="number" min="0" max="1000000" step="50" value="<?php echo HTML::chars((string) Arr::get((array) $entity['attributes'], 'rate_per_hour', '')); ?>" placeholder="₽/час" class="w-20 rounded border border-transparent bg-transparent px-1 py-0.5 n2j-ds-xs text-gray-600 hover:border-gray-300 focus:border-blue-500 focus:outline-none dark:text-gray-300 dark:hover:border-gray-600">
									<button type="submit" class="n2j-btn-secondary n2j-btn-sm">Сохранить</button>
								</form>
							</td>
							<td class="px-4 py-2 n2j-ds-xs text-gray-500 dark:text-gray-400">
								<?php
								$attrs = (array) $entity['attributes'];
								$pairs = [];
								foreach ($attrs as $k => $v)
								{
									if (is_scalar($v) AND $v !== '')
									{
										$pairs[] = $k.'='.(string) $v;
									}
								}
								echo HTML::chars(UTF8::substr(implode(' · ', $pairs), 0, 160));
								?>
							</td>
							<td class="px-4 py-2"><?php echo ! empty($entity['is_base']) ? '<span class="rounded bg-gray-100 px-1.5 py-0.5 n2j-ds-xs font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">база</span>' : ''; ?></td>
							<td class="px-4 py-2 text-right">
								<form method="post" action="/process/ontology" class="inline">
									<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
									<input type="hidden" name="action" value="delete">
									<input type="hidden" name="entity_id" value="<?php echo (int) $entity['id']; ?>">
									<button type="submit" data-n2j-confirm="Удалить сущность? Код перестанет находиться в схемах." data-n2j-tone="danger" data-n2j-ok="Удалить" class="n2j-btn-danger n2j-btn-sm" title="Удалить"><i class="fas fa-trash"></i></button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</section>
		<?php endforeach; ?>
	</div>

	<p class="mt-4 n2j-ds-xs text-gray-500 dark:text-gray-400">Удаление роли, системы или артефакта, которые используются в схемах, блокируется. Сначала уберите ссылки из версий.</p>
</div>

<script>
(function () {
	var form = document.getElementById('nj670-feed-form');
	var file = document.getElementById('nj670-feed-file');
	var label = document.getElementById('nj670-feed-file-label');
	var csv = document.getElementById('nj670-feed-csv');
	var text = document.getElementById('nj670-feed-text');
	var status = document.getElementById('nj670-feed-status');
	var toggle = document.getElementById('nj670-toggle-all');
	var acceptForm = document.getElementById('nj670-accept-form');

	if (file && label) {
		file.addEventListener('change', function () {
			label.textContent = file.files && file.files.length ? file.files[0].name : 'Файл: PDF, фото, аудио, txt';
		});
	}

	// CSV из выгрузки подставляем в текст: модель увидит последовательность шагов.
	if (csv && text) {
		csv.addEventListener('change', function () {
			var picked = csv.files && csv.files[0];
			if (!picked) { return; }
			var reader = new FileReader();
			reader.onload = function () {
				text.value = String(reader.result || '').slice(0, 20000);
				text.focus();
			};
			reader.readAsText(picked, 'utf-8');
		});
	}

	if (form) {
		form.addEventListener('submit', function (evt) {
			var submit = form.querySelector('button[type="submit"]');
			if (submit) {
				submit.disabled = true;
				submit.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1.5"></i>Отправляем…';
			}
			if (text && text.value.trim().length < 40 && (!file || !file.files.length)) {
				evt.preventDefault();
				submit.disabled = false;
				submit.innerHTML = '<i class="fas fa-microchip mr-1.5"></i>Разобрать';
				text.classList.add('border-rose-400');
				text.focus();
			}
		});
	}

	if (toggle && acceptForm) {
		toggle.addEventListener('click', function () {
			var boxes = acceptForm.querySelectorAll('input[name="entity[]"]:not(:disabled), input[name="process[]"]:not(:disabled)');
			var allOn = true;
			for (var i = 0; i < boxes.length; i++) { if (!boxes[i].checked) { allOn = false; break; } }
			for (var j = 0; j < boxes.length; j++) { boxes[j].checked = !allOn; }
			toggle.textContent = allOn ? 'Выбрать всё' : 'Снять выбор';
		});
	}

	// Опрос статуса: пока воркер не ответил, страница перезагружается сама.
	if (status) {
		var job = parseInt(status.getAttribute('data-job'), 10) || 0;
		var state = status.getAttribute('data-status') || '';
		var stageEl = document.getElementById('nj670-feed-stage');
		var elapsedEl = document.getElementById('nj670-feed-elapsed');

		function clock(sec) {
			sec = Math.max(0, parseInt(sec, 10) || 0);
			var m = Math.floor(sec / 60), s = sec % 60;
			return m + ':' + (s < 10 ? '0' : '') + s;
		}

		if (elapsedEl) { elapsedEl.textContent = clock(elapsedEl.getAttribute('data-elapsed')); }

		if (job > 0 && (state === 'queued' || state === 'running')) {
			var tries = 0;
			var timer = window.setInterval(function () {
				tries++;
				if (tries > 120) { window.clearInterval(timer); return; }
				fetch('/process/api/ontology', {
					method: 'POST',
					headers: {'Content-Type': 'application/json'},
					credentials: 'same-origin',
					body: JSON.stringify({id: job})
				}).then(function (r) { return r.json(); }).then(function (data) {
					var info = data && data.job ? data.job : null;
					var now = info ? String(info.status || '') : '';
					// Этап и секундомер обновляем на месте: владелец видит,
					// что воркер жив, даже если статус ещё не сменился.
					if (info) {
						if (stageEl && info.stage) { stageEl.textContent = info.stage; }
						if (elapsedEl && info.elapsed_sec != null) { elapsedEl.textContent = clock(info.elapsed_sec); }
					}
					if (now && now !== state) {
						window.clearInterval(timer);
						window.location.reload();
					}
				}).catch(function () {});
			}, 4000);
		}
	}
})();
</script>
