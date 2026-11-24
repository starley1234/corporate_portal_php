<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php if (!empty($progress['found'])): ?>
<div class="w-full max-w-md space-y-3">
    <?php echo View::factory('partials/live_status', [
        'standalone' => TRUE,
        'url' => '/prx391_documents/progress/'.(int) $doc['id'],
        'title' => ['queued' => 'В очереди', 'running' => 'В работе', 'done' => 'Готово', 'failed' => 'Ошибка', 'cancelled' => 'Отменено'][$progress['status']] ?? 'Разбор документа',
        'status' => $progress['status'],
        'stage' => !empty($progress['stage']) ? $progress['stage'] : ([
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
    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
        <span>Прогон #<?php echo (int) $progress['run_id']; ?> · задача #<?php echo (int) ($progress['job_id'] ?? 0); ?></span>
        <a href="/prx120/monitor" class="prx-link">Монитор очереди <i class="fas fa-arrow-up-right-from-square ml-1" aria-hidden="true"></i></a>
    </div>
</div>
<?php endif; ?>
