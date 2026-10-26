<?php defined('SYSPATH') or die('No direct script access.');
$waiting = count(array_filter($tasks, function ($t) { return in_array($t['status'], ['queued', 'running'], TRUE); })) > 0;
$signature = hash('sha256', json_encode(array_column($tasks, 'status', 'id')));
$card = 'rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-4';
$in = 'n2j-input mt-1 w-full py-1.5 text-sm min-h-0';
$lab = 'n2j-label text-xs';
$muted = 'text-xs text-gray-500 dark:text-gray-400';
$btn = 'n2j-btn-secondary n2j-btn-sm';
$primary = 'n2j-btn-process n2j-btn-sm';
$danger = 'n2j-btn-danger n2j-btn-sm';
$by_status = ['queued' => 'В очереди', 'running' => 'Генерация идей', 'done' => 'Идеи сохранены', 'error' => 'Ошибка'];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" <?php if ($waiting): ?>data-solid-poll="/solid3d/ideas?state=1" data-signature="<?php echo HTML::chars($signature); ?>"<?php endif; ?>>
<?php echo View::factory('nj669/head', ['error' => $error]); ?>
<h1 class="n2j-h1">Идеи для 3D-деталей</h1>
<p class="mt-1 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Общая копилка пресетов для страницы «Создать». Генерация и правка идей — только модератору, как в Graph3D.</p>
<div id="solid-new-result" class="n2j-flash n2j-flash-info mt-3 text-xs block" hidden>Идеи обновились. <a class="font-semibold underline" href="/solid3d/ideas">Открыть результат</a>. Введённый текст не потерян.</div>

<div class="mt-3 grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(300px,380px)]">
	<div class="min-w-0 space-y-3">
		<?php if ($moderator): ?>
		<form method="post" action="/solid3d/ideas" class="<?php echo $card; ?>" data-solid-form autocomplete="off" data-n2j-no-remember>
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="op" value="generate">
			<label for="solid-theme" class="<?php echo $lab; ?>">Тема для 30 идей</label>
			<input id="solid-theme" name="theme" required maxlength="200" autocomplete="off" data-n2j-no-remember class="<?php echo $in; ?>" placeholder="Крепления, заглушки и органайзеры для мастерской">
			<button class="<?php echo $primary; ?> mt-2" <?php if ($waiting): ?>disabled<?php endif; ?>>Придумать идеи с ИИ</button>
		</form>
		<?php endif; ?>
		<?php if ($tasks): ?>
		<section class="<?php echo $card; ?>">
			<h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Задачи генерации</h2>
			<ul class="mt-2 space-y-1 text-xs text-gray-700 dark:text-gray-300">
			<?php foreach ($tasks as $task): ?><li>#<?php echo (int) $task['id']; ?> · <?php echo HTML::chars($by_status[$task['status']] ?? $task['status']); ?> <?php echo HTML::chars($task['error']); ?></li><?php endforeach; ?>
			</ul>
		</section>
		<?php endif; ?>
		<?php if ( ! $ideas): ?><p class="<?php echo $card; ?> text-sm text-gray-500 dark:text-gray-400">Идей пока нет.</p><?php endif; ?>
		<div class="grid gap-3 md:grid-cols-2">
		<?php foreach ($ideas as $idea): ?>
			<section class="<?php echo $card; ?>">
				<form method="post" action="/solid3d/ideas" autocomplete="off" data-n2j-no-remember data-solid-form>
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="idea" value="<?php echo (int) $idea['id']; ?>">
					<label for="theme-<?php echo (int) $idea['id']; ?>" class="<?php echo $lab; ?>">Тема</label>
					<input id="theme-<?php echo (int) $idea['id']; ?>" name="theme" maxlength="200" autocomplete="off" data-n2j-no-remember class="<?php echo $in; ?>" value="<?php echo HTML::chars($idea['theme']); ?>">
					<label for="idea-<?php echo (int) $idea['id']; ?>" class="<?php echo $lab; ?> mt-2">Идея</label>
					<textarea id="idea-<?php echo (int) $idea['id']; ?>" name="body" required maxlength="600" rows="3" autocomplete="off" data-n2j-no-remember class="n2j-textarea mt-1 w-full"><?php echo HTML::chars($idea['body']); ?></textarea>
					<button class="<?php echo $btn; ?> mt-2" name="op" value="save">Сохранить</button>
				</form>
				<form method="post" action="/solid3d/ideas" class="mt-2 border-t border-gray-100 pt-2 dark:border-gray-800" autocomplete="off" data-n2j-no-remember>
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="idea" value="<?php echo (int) $idea['id']; ?>"><input type="hidden" name="op" value="delete"><input type="hidden" name="armed" value="0">
					<button type="button" class="<?php echo $danger; ?>" data-solid-delete data-n2j-arm="Удалить идею?">Удалить идею</button>
				</form>
			</section>
		<?php endforeach; ?>
		</div>
	</div>
	<aside class="min-w-0">
		<form method="post" action="/solid3d/ideas" class="<?php echo $card; ?>" data-solid-form autocomplete="off" data-n2j-no-remember>
			<h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Добавить вручную</h2>
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="op" value="save"><input type="hidden" name="idea" value="0">
			<label for="solid-manual-theme" class="<?php echo $lab; ?> mt-2">Тема</label>
			<input id="solid-manual-theme" name="theme" maxlength="200" autocomplete="off" data-n2j-no-remember class="<?php echo $in; ?>">
			<label for="solid-manual-body" class="<?php echo $lab; ?> mt-2">Описание одной детали</label>
			<textarea id="solid-manual-body" name="body" required maxlength="600" rows="4" autocomplete="off" data-n2j-no-remember class="n2j-textarea mt-1 w-full"></textarea>
			<button class="<?php echo $btn; ?> mt-2">Добавить</button>
		</form>
	</aside>
</div>
<script defer src="/assets/js/nj669-solid3d.js?v=20261016d"></script>
</div>
