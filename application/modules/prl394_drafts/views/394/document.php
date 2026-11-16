<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['Ответы', '/replies'], [$doc['reg_no']]],
        'title' => 'Ответ: '.$doc['subject'],
        'pill' => [$doc['reg_no'], 'fa-reply'],
        'actions' => [
            ['label' => 'Карточка документа', 'href' => '/documents/'.(int) $doc['id'], 'icon' => 'fa-file-lines', 'role' => 'secondary'],
            ['label' => 'Все ответы', 'href' => '/replies', 'icon' => 'fa-arrow-left', 'role' => 'secondary'],
        ],
        'lead' => 'Черновик опирается на карточку и шаблон. Проверьте текст, утвердите и отправьте вручную — автоматической отправки нет.',
    ]); ?>
    <?php echo View::factory('partials/docflow_flash', ['flash' => $flash]); ?>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="min-w-0 space-y-4">
            <?php if (empty($drafts)): ?>
                <section class="prl-card">
                    <div class="prl-empty"><div class="prl-empty-icon"><i class="fas fa-reply"></i></div><div class="prl-empty-text">Черновиков пока нет. Выберите шаблон справа и нажмите «Сгенерировать».</div></div>
                </section>
            <?php endif; ?>

            <?php foreach ($drafts as $draft): ?>
                <section class="prl-card">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-file-pen mr-2 text-blue-500"></i><?php echo HTML::chars($draft['template_title'] ?: 'Черновик'); ?> <span class="font-mono text-xs text-gray-400">#<?php echo (int) $draft['id']; ?></span></h2>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Модель: <?php echo HTML::chars($draft['model'] ?: '—'); ?> · профиль <?php echo HTML::chars($draft['profile']); ?> · обновлён <?php echo HTML::chars(date('d.m.Y H:i', strtotime($draft['updated_at']))); ?></p>
                        </div>
                        <span class="prl-badge prl-badge-<?php echo ['generating' => 'run', 'draft' => 'wait', 'approved' => 'info', 'sent' => 'ok', 'rejected' => 'err', 'failed' => 'err'][$draft['status']] ?? 'wait'; ?>"><span class="prl-dot"></span><?php echo HTML::chars(['generating' => 'генерируется', 'draft' => 'черновик', 'approved' => 'утверждён', 'sent' => 'отправлен', 'rejected' => 'отклонён', 'failed' => 'ошибка'][$draft['status']] ?? $draft['status']); ?></span>
                    </div>

                    <?php if ($draft['status'] === 'generating'): ?>
                        <div class="prl-flash prl-flash-warn mt-3"><i class="fas fa-hourglass-half"></i><span>Задача в очереди <code class="font-mono">doc.draft</code>. Обновите страницу через несколько секунд.</span></div>
                    <?php elseif ($draft['status'] === 'failed'): ?>
                        <div class="prl-flash prl-flash-err mt-3"><i class="fas fa-circle-exclamation"></i><span><?php echo HTML::chars($draft['error'] ?: 'Генерация не удалась'); ?></span></div>
                    <?php endif; ?>

                    <form method="post" action="/replies/save/<?php echo (int) $draft['id']; ?>" class="mt-3">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <label class="prl-label" for="draft-body-<?php echo (int) $draft['id']; ?>">Текст ответа</label>
                        <textarea id="draft-body-<?php echo (int) $draft['id']; ?>" name="body" rows="14" class="prl-input mt-1 w-full font-mono text-sm" <?php echo $draft['status'] === 'sent' ? 'readonly' : ''; ?>><?php echo HTML::chars($draft['body']); ?></textarea>
                        <?php if ($draft['status'] !== 'sent'): ?>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button class="prl-btn-secondary" type="submit"><i class="fas fa-floppy-disk"></i>Сохранить текст</button>
                            </div>
                        <?php endif; ?>
                    </form>

                    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <?php if ($can_approve AND in_array($draft['status'], ['draft', 'approved'], TRUE)): ?>
                            <form method="post" action="/replies/approve/<?php echo (int) $draft['id']; ?>" class="inline-flex">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <button class="prl-btn-cta prl-btn-sm" type="submit"><i class="fas fa-check"></i><?php echo $draft['status'] === 'approved' ? 'Утвердить повторно' : 'Утвердить'; ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($can_send AND $draft['status'] === 'approved'): ?>
                            <form method="post" action="/replies/send/<?php echo (int) $draft['id']; ?>" class="inline-flex" onsubmit="return confirm('Отправить письмо корреспонденту?');">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <button class="prl-btn-process prl-btn-sm" type="submit" <?php echo $email_enabled ? '' : 'disabled title="PRL394_EMAIL_ENABLED=0"'; ?>><i class="fas fa-paper-plane"></i>Отправить</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($can_approve AND in_array($draft['status'], ['draft', 'approved', 'failed'], TRUE)): ?>
                            <form method="post" action="/replies/reject/<?php echo (int) $draft['id']; ?>" class="inline-flex">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <button class="prl-btn-secondary prl-btn-sm" type="submit"><i class="fas fa-xmark"></i>Отклонить</button>
                            </form>
                        <?php endif; ?>
                        <?php if ( ! $email_enabled): ?><span class="text-xs text-gray-500 dark:text-gray-400">Отправка выключена: PRL394_EMAIL_ENABLED=0.</span><?php endif; ?>
                    </div>

                    <?php if ( ! empty($draft['citations'])): ?>
                        <div class="mt-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Опора</div>
                            <ul class="mt-1 flex flex-wrap gap-2">
                                <?php foreach ($draft['citations'] as $cite): ?>
                                    <li><a class="prl-badge prl-badge-wait" href="<?php echo HTML::chars($cite['href'] ?? '#'); ?>"><i class="fas fa-link"></i><?php echo HTML::chars(($cite['kind'] ?? 'ref').': '.($cite['ref'] ?? '')); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>

        <aside class="space-y-4">
            <?php if ($can_draft): ?>
            <section class="prl-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-plus mr-2 text-blue-500"></i>Новый черновик</h2>
                <form method="post" action="/replies/new/<?php echo (int) $doc['id']; ?>" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div>
                        <label class="prl-label" for="template-id">Шаблон</label>
                        <select id="template-id" name="template_id" class="prl-select mt-1 w-full">
                            <option value="0">Подобрать по типу документа</option>
                            <?php foreach ($templates as $template): ?><option value="<?php echo (int) $template['id']; ?>"><?php echo HTML::chars($template['title']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button class="prl-btn-cta w-full" type="submit"><i class="fas fa-wand-magic-sparkles"></i>Сгенерировать</button>
                </form>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Генерация идёт в воркере; страница не ждёт модель.</p>
            </section>
            <?php endif; ?>

            <section class="prl-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-file-lines mr-2 text-blue-500"></i>Основание</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">Тема</dt><dd class="font-semibold"><?php echo HTML::chars($doc['subject']); ?></dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">Тип</dt><dd class="font-semibold"><?php echo HTML::chars($doc['type_title'] ?: 'не определён'); ?></dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">Корреспондент</dt><dd class="font-semibold"><?php echo HTML::chars($doc['correspondent_name'] ?: ($doc['sender_name'] ?: '—')); ?></dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">Исходящий</dt><dd class="font-semibold"><?php echo HTML::chars(trim(($doc['outbound_no'] ?: '—').' '.($doc['outbound_date'] ? 'от '.date('d.m.Y', strtotime($doc['outbound_date'])) : ''))); ?></dd></div>
                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">Краткое содержание</dt><dd class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-200"><?php echo HTML::chars($doc['summary'] ?: '—'); ?></dd></div>
                </dl>
            </section>

            <section class="prl-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-shield-halved mr-2 text-emerald-500"></i>Правила</h2>
                <ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2"><i class="fas fa-check mt-1 text-emerald-500"></i><span>Черновик не отправляется сам — только вручную.</span></li>
                    <li class="flex gap-2"><i class="fas fa-check mt-1 text-emerald-500"></i><span>Утверждение и отправка — разные права.</span></li>
                    <li class="flex gap-2"><i class="fas fa-check mt-1 text-emerald-500"></i><span>Модель локальная; текст письма наружу не уходит.</span></li>
                </ul>
            </section>
        </aside>
    </div>
</div>
