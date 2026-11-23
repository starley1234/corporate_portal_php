<?php defined('SYSPATH') or die('No direct script access.'); ?>

            <section class="prx-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-clock mr-2 text-amber-500"></i>Срок и маршрут</h2>
                <?php if ($deadline): ?>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Срок</dt><dd class="font-semibold <?php echo $deadline['state'] === 'overdue' ? 'text-red-600 dark:text-red-400' : ''; ?>"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($deadline['due_at']))); ?></dd></div>
                        <?php if ($deadline['state'] === 'met'): ?>
                            <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Контроль</dt><dd class="font-semibold">Снят с контроля</dd></div>
                        <?php else: ?>
                            <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Осталось</dt><dd class="font-semibold"><?php $hl = (float) $deadline['hours_left']; echo $hl < 0 ? 'просрочен на '.round(abs($hl)).' ч' : round($hl).' ч'; ?></dd></div>
                        <?php endif; ?>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Отдел</dt><dd class="text-right font-semibold"><?php echo HTML::chars($deadline['department']); ?></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Роль</dt><dd class="font-mono text-xs"><?php echo HTML::chars($deadline['role_code'] ?: '—'); ?></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Источник</dt><dd class="font-semibold"><?php echo HTML::chars(['rule' => 'правило', 'hint' => 'текст письма', 'default' => 'по умолчанию'][$deadline['source']] ?? $deadline['source']); ?></dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($deadline['note']); ?></p>
                <?php else: ?>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Срок ещё не назначен.</p>
                <?php endif; ?>
                <?php if ($can_route && ! in_array($doc['status'], ['closed', 'archived'], TRUE)): ?>
                    <form method="post" action="/sla/route/<?php echo (int) $doc['id']; ?>" class="mt-3">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <button class="prx-btn-secondary w-full justify-center" type="submit"><i class="fas fa-route"></i>Пересчитать маршрут</button>
                    </form>
                <?php endif; ?>
            </section>
