<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php if (!empty($progress['found'])): ?>
<div class="w-full space-y-2">
    <?php echo View::factory('partials/live_status', [
        'embedded' => TRUE,
        'compact' => TRUE,
        'url' => '/prx391_documents/progress/'.(int) $doc['id'],
        'title' => ['queued' => 'В очереди', 'running' => 'В работе', 'done' => 'Готово', 'failed' => 'Ошибка', 'cancelled' => 'Отменено'][$progress['status']] ?? 'Разбор документа',
        'status' => $progress['status'],
        'stage' => !empty($progress['stage']) && in_array($progress['status'], ['queued', 'running'], TRUE) ? $progress['stage'] : ([
            'queued' => 'Ожидание свободного воркера',
            'running' => 'Воркер разбирает документ',
            'done' => 'Разбор завершён',
            'failed' => 'Не удалось завершить разбор',
            'cancelled' => 'Разбор отменён',
        ][$progress['status']] ?? ''),
        'steps' => $progress['stages'] ?? [],
        'elapsed' => $progress['elapsed_sec'] ?? 0,
        'silent' => $progress['silent_sec'] ?? NULL,
        'note' => $progress['error'] ?? ($progress['status'] === 'queued'
            ? 'Ожидаем воркер doc.analyze. Ход работы обновится автоматически.'
            : 'Результат разбора — в основной колонке.'),
    ]); ?>
    <?php if (!empty($progress['error'])): ?>
    <details class="text-xs text-rose-700 dark:text-rose-300">
        <summary class="cursor-pointer font-semibold">Причина ошибки</summary>
        <p class="mt-2 break-words whitespace-pre-wrap"><?php echo HTML::chars($progress['error']); ?></p>
    </details>
    <?php endif; ?>

</div>
<?php endif; ?>
