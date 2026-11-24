<?php defined('SYSPATH') or die('No direct script access.'); ?>

            <section class="prx-card">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-wand-magic-sparkles mr-2 text-blue-500"></i>ИИ-разбор</h2>
                    <span class="prx-badge prx-badge-<?php echo $analysis ? ($analysis['status'] === 'proposed' ? 'run' : 'ok') : 'wait'; ?>"><span class="prx-dot"></span><?php echo $analysis ? HTML::chars(['proposed' => 'ждёт проверки', 'accepted' => 'применено', 'edited' => 'применено', 'rejected' => 'отклонено'][$analysis['status']] ?? $analysis['status']) : (!empty($progress['found']) ? HTML::chars(['queued' => 'в очереди', 'running' => 'воркер работает', 'failed' => 'ошибка', 'done' => 'готово', 'cancelled' => 'отменено'][$progress['status']] ?? $progress['status']) : 'не запускался'); ?></span>
                </div>
                <?php if (!empty($progress['found'])): ?>
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Прогон #<?php echo (int) $progress['run_id']; ?> · задача #<?php echo (int) ($progress['job_id'] ?? 0); ?></p>
                    <?php echo View::factory('partials/live_status', [
                        'url' => '/prx391_documents/progress/'.(int) $doc['id'],
                        'title' => 'Разбор документа', 'status' => $progress['status'],
                        'stage' => $progress['stage'] ?? '', 'steps' => $progress['stages'] ?? [],
                        'elapsed' => $progress['elapsed_sec'] ?? 0, 'silent' => $progress['silent_sec'] ?? NULL,
                        'note' => $progress['error'] ?? ($progress['status'] === 'queued' ? 'Задача ожидает воркер канала doc.analyze. Если ожидание затянулось, проверьте WORKER_CHANNELS, APP_URL и токен воркера.' : 'Результат появится здесь после приёмки.'),
                    ]); ?>
                    <a href="/prx120/monitor" class="prx-link mt-3 inline-flex">Монитор очереди · doc.analyze</a>
                <?php endif; ?>
                <?php if ($analysis): ?>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Тип</dt><dd class="text-right font-semibold"><?php echo HTML::chars($analysis['payload']['type'] ?? '—'); ?></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Уверенность</dt><dd class="font-semibold"><?php echo (int) round(((float) $analysis['confidence']) * 100); ?>%</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Модель</dt><dd class="max-w-[190px] truncate text-right font-mono text-xs" title="<?php echo HTML::chars($analysis['model']); ?>"><?php echo HTML::chars($analysis['model']); ?></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Токенов</dt><dd class="font-semibold"><?php echo (int) $analysis['tokens']; ?></dd></div>
                    </dl>
                <?php endif; ?>
                <?php if ($can_run_ai): ?>
                    <form method="post" action="/analysis/run/<?php echo (int) $doc['id']; ?>" class="mt-3">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <button class="prx-btn-process w-full justify-center" type="submit"><i class="fas fa-play"></i><?php echo $analysis ? 'Разобрать заново' : 'Запустить разбор'; ?></button>
                    </form>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Задача уходит в воркер <code class="font-mono">doc.analyze</code>; страница не ждёт модель.</p>
                <?php endif; ?>
                <a class="mt-2 block text-center text-xs text-blue-600 hover:underline dark:text-blue-400" href="/analysis">Все предложения</a>
            </section>
