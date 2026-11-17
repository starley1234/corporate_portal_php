<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['Документооборот'], ['Ответы']],
        'title' => 'Ответы на письма',
        'pill' => ['prx394 · черновики', 'fa-reply'],
        'actions' => [
            ['label' => 'Реестр', 'href' => '/documents', 'icon' => 'fa-folder-open', 'role' => 'secondary'],
            ['label' => 'ИИ-разбор', 'href' => '/analysis', 'icon' => 'fa-wand-magic-sparkles', 'role' => 'secondary'],
        ],
        'lead' => 'Черновик по шаблону готовит воркер. Утверждает и отправляет человек: автоматической отправки нет, отправка выключена без PRX394_EMAIL_ENABLED=1.',
    ]); ?>
    <?php echo View::factory('partials/docflow_flash', ['flash' => $flash]); ?>

    <section class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" aria-label="Показатели ответов">
        <?php
        $kpis = [
            ['Всего', $stats['total'], 'fas fa-reply-all', 'info', 'черновиков'],
            ['Готовы к проверке', $stats['draft_count'], 'fas fa-pen-nib', $stats['draft_count'] ? 'run' : 'ok', 'статус draft'],
            ['Утверждены', $stats['approved'], 'fas fa-check', 'info', 'можно отправлять'],
            ['Отправлены', $stats['sent'], 'fas fa-paper-plane', 'ok', 'вручную'],
            ['Генерируются', $stats['generating'], 'fas fa-hourglass-half', $stats['generating'] ? 'warn' : 'ok', 'в воркере'],
        ];
        foreach ($kpis as $kpi): ?>
            <div class="prx-card">
                <div class="flex items-start justify-between gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kpi[0]); ?></span>
                    <i class="<?php echo HTML::chars($kpi[2]); ?> text-gray-400"></i>
                </div>
                <div class="mt-2 text-3xl font-bold text-gray-900 dark:text-white"><?php echo (int) $kpi[1]; ?></div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kpi[4]); ?></div>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="prx-card mt-5 overflow-x-auto">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-envelope-open-text mr-2 text-blue-500"></i>Черновики</h2>
            <span class="prx-count"><?php echo count($drafts); ?></span>
        </div>
        <table class="prx-table mt-3 min-w-[980px]">
            <thead><tr><th>Документ</th><th>Шаблон</th><th>Статус</th><th>Текст</th><th>Обновлён</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($drafts as $draft): ?>
                <tr>
                    <td>
                        <a class="block font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400" href="/documents/<?php echo (int) $draft['document_id']; ?>"><?php echo HTML::chars($draft['reg_no'] ?: ('документ #'.(int) $draft['document_id'])); ?></a>
                        <span class="block max-w-[300px] truncate text-xs text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars($draft['subject']); ?>"><?php echo HTML::chars($draft['subject']); ?></span>
                    </td>
                    <td><?php echo HTML::chars($draft['template_title'] ?: '—'); ?><span class="block font-mono text-[11px] text-gray-400"><?php echo HTML::chars($draft['profile']); ?></span></td>
                    <td><span class="prx-badge prx-badge-<?php echo ['generating' => 'run', 'draft' => 'wait', 'approved' => 'info', 'sent' => 'ok', 'rejected' => 'err', 'failed' => 'err'][$draft['status']] ?? 'wait'; ?>"><span class="prx-dot"></span><?php echo HTML::chars(['generating' => 'генерируется', 'draft' => 'черновик', 'approved' => 'утверждён', 'sent' => 'отправлен', 'rejected' => 'отклонён', 'failed' => 'ошибка'][$draft['status']] ?? $draft['status']); ?></span></td>
                    <td class="max-w-[340px] text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars(mb_substr(trim($draft['body']), 0, 150)); ?><?php echo mb_strlen($draft['body']) > 150 ? '…' : ''; ?><?php if ( ! $draft['body']): ?><span class="text-gray-400">ещё нет текста</span><?php endif; ?></td>
                    <td class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($draft['updated_at']))); ?></td>
                    <td class="text-right"><a class="prx-btn-secondary prx-btn-sm" href="/replies/document/<?php echo (int) $draft['document_id']; ?>">Открыть</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($drafts)): ?><tr><td colspan="6" class="text-center text-gray-500 dark:text-gray-400">Черновиков нет. Откройте карточку документа и нажмите «Подготовить ответ».</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="prx-card mt-5">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-file-lines mr-2 text-blue-500"></i>Шаблоны</h2>
        <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <?php foreach ($templates as $template): ?>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="flex items-center justify-between gap-2"><span class="font-semibold"><?php echo HTML::chars($template['title']); ?></span><span class="prx-badge prx-badge-wait"><?php echo HTML::chars($template['doc_type'] ?: 'любой'); ?></span></div>
                    <span class="mt-1 block font-mono text-[11px] text-gray-400"><?php echo HTML::chars($template['code']); ?></span>
                    <p class="mt-2 line-clamp-3 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr($template['body'], 0, 180)); ?>…</p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
