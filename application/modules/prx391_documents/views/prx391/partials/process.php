<?php defined('SYSPATH') or die('No direct script access.'); ?>
                <?php if (!empty($progress['found'])): ?>
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Разбор документа · прогон #<?php echo (int) $progress['run_id']; ?> · задача #<?php echo (int) ($progress['job_id'] ?? 0); ?></p>
                    <?php echo View::factory('partials/live_status', [
                        'url' => '/prx391_documents/progress/'.(int) $doc['id'],
                        'title' => ['queued' => 'В очереди', 'running' => 'В работе', 'done' => 'Готово', 'failed' => 'Ошибка', 'cancelled' => 'Отменено'][$progress['status']] ?? 'Разбор документа', 'status' => $progress['status'],
                        'stage' => $progress['stage'] ?? '', 'steps' => $progress['stages'] ?? [],
                        'elapsed' => $progress['elapsed_sec'] ?? 0, 'silent' => $progress['silent_sec'] ?? NULL,
                        'note' => $progress['error'] ?? ($progress['status'] === 'queued' ? 'Задача ожидает воркер канала doc.analyze. Если ожидание затянулось, проверьте WORKER_CHANNELS, APP_URL и токен воркера.' : 'Результат появится здесь после приёмки.'),
                    ]); ?>
                    <a href="/prx120/monitor" class="prx-link mt-3 inline-flex">Монитор очереди · doc.analyze</a>
                <?php endif; ?>
