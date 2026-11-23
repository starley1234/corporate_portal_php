<?php defined('SYSPATH') or die('No direct script access.'); ?>

	<!-- ================= СОБЫТИЯ ================= -->
	<div class="prx-card p-0 mt-5">
		<div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
			<h2 class="prx-h3">Реестр событий</h2>
			<p class="prx-hint mt-0.5">Что объявили модули в манифестах и что из этого включено. Источник: <code>notify.events</code> в <code>config/prx_module.php</code> — при появлении модуля подхватывается само.</p>
			<p class="mt-1 text-xs text-amber-600 dark:text-amber-400"><i class="fas fa-circle-info mr-1"></i>Включённое здесь событие уходит только тем, кто отметил его у себя в настройках: у всех по умолчанию уведомления выключены.</p>
		</div>
		<div class="overflow-x-auto">
			<table class="prx-table min-w-[900px]">
				<thead>
					<tr class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
						<th class="px-4 py-2 text-left font-semibold">Событие</th>
						<th class="px-3 py-2 text-left font-semibold">Категория</th>
						<th class="px-3 py-2 text-left font-semibold">Каналы</th>
						<th class="px-3 py-2 text-left font-semibold">Кому</th>
						<th class="px-3 py-2 text-center font-semibold">Обяз.</th>
						<th class="px-3 py-2 text-center font-semibold">Сводка</th>
						<th class="px-3 py-2 text-center font-semibold">Вкл.</th>
						<th class="px-3 py-2 text-center font-semibold">Действие</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
					<?php foreach ($events as $event): ?>
					<tr>
						<td class="px-4 py-2.5">
							<span class="font-semibold text-gray-800 dark:text-gray-100"><i class="<?php echo HTML::chars($event['icon']); ?> mr-1.5 text-blue-500"></i><?php echo HTML::chars($event['title']); ?></span>
							<code class="block text-xs text-gray-400"><?php echo HTML::chars($event['alias']); ?></code>
							<?php if ( ! empty($event['source'])): ?>
							<span class="block text-xs text-gray-400">источник: <?php echo HTML::chars($event['source']); ?></span>
							<?php endif; ?>
						</td>
						<td class="px-3 py-2.5 text-gray-600 dark:text-gray-300"><?php echo HTML::chars(Service_Prx597_Registry::category_label($event['category'])); ?></td>
						<td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars(implode(', ', array_map(['Service_Prx597_Registry', 'channel_label'], $event['channels']))); ?></td>
						<td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">
							<?php echo HTML::chars(implode(', ', $event['roles'])); ?>
							<?php if ($event['ability'] !== ''): ?><span class="block text-gray-400">право: <?php echo HTML::chars($event['ability']); ?></span><?php endif; ?>
						</td>
						<td class="px-3 py-2.5 text-center"><?php echo $event['mandatory'] ? '<i class="fas fa-lock text-amber-500"></i>' : '<span class="text-gray-300">—</span>'; ?></td>
						<td class="px-3 py-2.5 text-center"><?php echo $event['digest'] ? '<i class="fas fa-layer-group text-blue-500"></i>' : '<span class="text-gray-300">—</span>'; ?></td>
						<td class="px-3 py-2.5 text-center">
							<form autocomplete="off" method="post" action="/prx597/admin">
								<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
								<input type="hidden" name="act" value="event_toggle">
								<input type="hidden" name="tab" value="events">
								<input type="hidden" name="alias" value="<?php echo HTML::chars($event['alias']); ?>">
								<input type="hidden" name="enabled" value="<?php echo $event['enabled'] ? '0' : '1'; ?>">
								<button type="submit" class="inline-flex h-6 w-6 items-center justify-center rounded <?php echo $event['enabled'] ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40' : 'bg-gray-100 text-gray-400 dark:bg-gray-700'; ?>">
									<i class="fas <?php echo $event['enabled'] ? 'fa-bell' : 'fa-bell-slash'; ?> text-xs"></i>
								</button>
							</form>
						</td>
						<td class="px-3 py-2.5 text-center">
							<button type="button" data-prx597-event-edit='<?php echo HTML::chars(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'
							        class="prx-btn-icon text-xs">
								<i class="fas fa-pen"></i>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
			<button type="button" data-prx597-event-new class="prx-btn-cta prx-btn-sm"><i class="fas fa-plus mr-1.5"></i>Добавить своё событие</button>
			<span class="ml-2 text-xs text-gray-400">Доступных пользователям сейчас: <?php echo count($events_on); ?></span>
		</div>
	</div>

	<!-- форма правки события -->
	<div id="prx597-event-form" class="prx-panel mt-4 hidden border-blue-300 dark:border-blue-700">
		<form autocomplete="off" method="post" action="/prx597/admin" class="grid gap-3 px-4 py-4 sm:grid-cols-2">
			<input type="hidden" name="csrf" value="<?php echo $csrf_h; ?>">
			<input type="hidden" name="act" value="event_save">
			<input type="hidden" name="tab" value="events">
			<h3 class="prx-h3 sm:col-span-2">Событие</h3>

			<label class="block sm:col-span-2">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Код (алиас)</span>
				<input type="text" name="alias" id="prx_ev_alias" required placeholder="mymodule.something.happened"
				       class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Название</span>
				<input type="text" name="title" id="prx_ev_title" required class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Категория</span>
				<select name="category" id="prx_ev_category" class="prx-input prx-select mt-1 w-full">
					<?php foreach (Service_Prx597_Registry::categories() as $key => $label): ?>
					<option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($label); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="block sm:col-span-2">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Описание (увидят пользователи в настройках)</span>
				<input type="text" name="description" id="prx_ev_description" class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Иконка (Font Awesome)</span>
				<input type="text" name="icon" id="prx_ev_icon" value="fas fa-bell" class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<label class="block">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Право (пусто — по ролям)</span>
				<input type="text" name="ability" id="prx_ev_ability" value="prx597.notify" class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<fieldset class="sm:col-span-2">
				<legend class="text-xs font-semibold text-gray-600 dark:text-gray-300">Каналы</legend>
				<div class="mt-1 flex flex-wrap gap-3" id="prx_ev_channels">
					<?php foreach (Service_Prx597_Registry::channels(FALSE) as $key => $info): ?>
					<label class="inline-flex items-center gap-1.5 text-sm">
						<input type="checkbox" name="channels[]" value="<?php echo HTML::chars((string) $key); ?>" class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
						<?php echo HTML::chars($info['label']); ?>
					</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
			<fieldset class="sm:col-span-2">
				<legend class="text-xs font-semibold text-gray-600 dark:text-gray-300">Роли</legend>
				<div class="mt-1 flex flex-wrap gap-3" id="prx_ev_roles">
					<?php foreach (['login' => 'вошедший', 'manager' => 'менеджер', 'admin' => 'администратор'] as $role => $label): ?>
					<label class="inline-flex items-center gap-1.5 text-sm">
						<input type="checkbox" name="roles[]" value="<?php echo $role; ?>" class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700">
						<?php echo HTML::chars($label); ?>
					</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
			<label class="block sm:col-span-2">
				<span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Подстановки (через запятую, в шаблонах — <code>{{код}}</code>)</span>
				<input type="text" name="placeholders" id="prx_ev_placeholders" placeholder="title,count,href" class="prx-input mt-1 w-full" autocomplete="off">
			</label>
			<div class="flex flex-wrap gap-4 sm:col-span-2">
				<label class="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" name="enabled" id="prx_ev_enabled" value="1" checked class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"> включено</label>
				<label class="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" name="mandatory" id="prx_ev_mandatory" value="1" class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"> обязательное (нельзя выключить)</label>
				<label class="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" name="digest" id="prx_ev_digest" value="1" class="h-4 w-4 shrink-0 rounded border-gray-300 accent-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700"> склеивать в сводку</label>
			</div>
			<div class="flex gap-2 sm:col-span-2">
				<button type="submit" class="prx-btn-cta"><i class="fas fa-floppy-disk mr-2"></i>Сохранить</button>
				<button type="button" data-prx597-event-cancel class="prx-btn-secondary">Отмена</button>
			</div>
		</form>
	</div>
