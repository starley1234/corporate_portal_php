<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/** @var array $rows @var int $total @var int $page @var int $pages @var array $filters @var array $skills @var array $intents @var array $intent_labels */
$badge = [
	'welcome'   => 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
	'help'      => 'bg-gray-100 text-gray-600 dark:bg-gray-900 dark:text-gray-300',
	'exit'      => 'bg-gray-100 text-gray-600 dark:bg-gray-900 dark:text-gray-300',
	'llm'       => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
	'fallback'  => 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
	'dangerous' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
];
$query = http_build_query(array_filter([
	'skill'  => $filters['skill'] ?: NULL,
	'intent' => $filters['intent'] !== '' ? $filters['intent'] : NULL,
	'source' => $filters['source'] !== '' ? $filters['source'] : NULL,
]));
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-comments text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Журнал диалогов</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Каждая реплика навыка: что спросили, что ответили и почему (интеграция, модель, время ответа). Это же — память разговоров.</p>
		</div>
		<span class="n2j-badge n2j-badge-wait"><?php echo number_format($total, 0, ',', ' '); ?> записей</span>
	</div>

	<form method="get" action="/alice/admin/history" class="n2j-card mt-5 flex flex-wrap items-end gap-3">
		<div>
			<label class="n2j-label" for="flt-skill">Навык</label>
			<select id="flt-skill" name="skill" class="n2j-input n2j-select mt-1.5 w-auto">
				<option value="0">все навыки</option>
				<?php foreach ($skills as $skill): ?>
					<option value="<?php echo (int) $skill['id']; ?>" <?php echo $filters['skill'] === (int) $skill['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($skill['title']); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label class="n2j-label" for="flt-intent">Сценарий</label>
			<select id="flt-intent" name="intent" class="n2j-input n2j-select mt-1.5 w-auto">
				<option value="">все сценарии</option>
				<?php foreach ($intents as $intent): ?>
					<option value="<?php echo HTML::chars($intent); ?>" <?php echo $filters['intent'] === $intent ? 'selected' : ''; ?>><?php echo HTML::chars(Arr::get($intent_labels, $intent, $intent)); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label class="n2j-label" for="flt-source">Источник</label>
			<select id="flt-source" name="source" class="n2j-input n2j-select mt-1.5 w-auto">
				<option value="">Алиса и тесты</option>
				<option value="yandex" <?php echo $filters['source'] === 'yandex' ? 'selected' : ''; ?>>только Алиса</option>
				<option value="test" <?php echo $filters['source'] === 'test' ? 'selected' : ''; ?>>только тесты</option>
			</select>
		</div>
		<button type="submit" class="n2j-btn-secondary">Показать</button>
	</form>

	<?php if ( ! $rows): ?>
		<div class="mt-6 rounded-xl border border-dashed border-blue-200 bg-blue-50/60 p-6 text-center dark:border-blue-900 dark:bg-blue-950/20">
			<i class="fas fa-comments text-2xl text-blue-500" aria-hidden="true"></i>
			<p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Записей нет</p>
			<p class="n2j-hint mx-auto max-w-md">Скажите что-нибудь навыку в Алисе или прогоните фразу в тестовой консоли.</p>
		</div>
	<?php else: ?>
		<div class="mt-6 space-y-3">
			<?php foreach ($rows as $row): ?>
				<?php $meta = json_decode((string) $row['meta'], TRUE); $meta = is_array($meta) ? $meta : []; ?>
				<div class="n2j-card">
					<div class="flex flex-wrap items-center gap-2 text-xs">
						<span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($row['skill_title']); ?></span>
						<span class="rounded-full px-2 py-0.5 font-bold <?php echo Arr::get($badge, $row['intent'], 'bg-gray-100 text-gray-600 dark:bg-gray-900 dark:text-gray-300'); ?>"><?php echo HTML::chars(Arr::get($intent_labels, $row['intent'], $row['intent'])); ?></span>
						<?php if ($row['source'] === 'test'): ?><span class="rounded-full bg-blue-50 px-2 py-0.5 font-bold text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">тест</span><?php endif; ?>
						<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars(date('d.m.Y H:i:s', strtotime((string) $row['created_at']))); ?></span>
						<?php if (Arr::get($meta, 'ms')): ?><span class="text-gray-400 dark:text-gray-500" title="Время ответа модели"><?php echo (int) $meta['ms']; ?> мс</span><?php endif; ?>
						<?php if (Arr::get($meta, 'model')): ?><span class="font-mono text-gray-400 dark:text-gray-500" title="Модель"><?php echo HTML::chars((string) $meta['model']); ?></span><?php endif; ?>
						<span class="text-gray-400 dark:text-gray-500" title="Хеш пользователя (обезличен)"><?php echo HTML::chars(mb_substr((string) $row['user_key'], 0, 8)); ?></span>
					</div>
					<?php if ((string) $row['question_text'] !== ''): ?>
						<p class="mt-2 text-sm italic text-gray-500 dark:text-gray-400">«<?php echo HTML::chars($row['question_text']); ?>»</p>
					<?php endif; ?>
					<p class="mt-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100"><?php echo nl2br(HTML::chars(mb_substr((string) $row['answer_text'], 0, 600).(mb_strlen((string) $row['answer_text']) > 600 ? '…' : ''))); ?></p>
					<?php if (Arr::get($meta, 'error')): ?>
						<p class="mt-2 rounded-lg bg-rose-50 px-3 py-1.5 text-xs text-rose-700 dark:bg-rose-950/30 dark:text-rose-300" title="Ошибка ИИ"><?php echo HTML::chars((string) $meta['error']); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ($pages > 1): ?>
			<div class="mt-6 flex items-center justify-between">
				<?php if ($page > 1): ?>
					<a href="/alice/admin/history?<?php echo HTML::chars($query.'&page='.($page - 1)); ?>" class="n2j-btn-secondary">← Новее</a>
				<?php else: ?><span></span><?php endif; ?>
				<span class="text-sm text-gray-500 dark:text-gray-400">страница <?php echo $page; ?> из <?php echo $pages; ?></span>
				<?php if ($page < $pages): ?>
					<a href="/alice/admin/history?<?php echo HTML::chars($query.'&page='.($page + 1)); ?>" class="n2j-btn-secondary">Старее →</a>
				<?php else: ?><span></span><?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
