<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['Документооборот'], ['Реестр']],
        'title' => 'Реестр входящих',
        'pill' => [$total.' документов', 'fa-folder-open'],
        'actions' => [
            ['label' => 'Приём PDF', 'href' => '/inbox', 'icon' => 'fa-inbox', 'role' => 'process'],
            ['label' => 'ИИ-разбор', 'href' => '/analysis', 'icon' => 'fa-wand-magic-sparkles', 'role' => 'secondary'],
            ['label' => 'Сроки', 'href' => '/sla', 'icon' => 'fa-clock', 'role' => 'secondary'],
        ],
        'lead' => 'Карточка — единственный источник реквизитов. ИИ предлагает значения, человек применяет; каждое изменение поля остаётся в провенансе.',
    ]); ?>
    <?php echo View::factory('partials/docflow_flash', ['flash' => $flash]); ?>

    <section class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" aria-label="Показатели реестра">
        <?php
        $kpis = [
            ['Всего', $stats['total'], 'fas fa-folder-open', 'info', 'кроме архива'],
            ['Новые', $stats['new_count'] ?? 0, 'fas fa-file-circle-plus', 'wait', 'ещё не разобраны'],
            ['В работе', $stats['work_count'], 'fas fa-person-digging', 'run', 'на разборе и в работе'],
            ['Просрочено', $stats['overdue'], 'fas fa-fire', $stats['overdue'] ? 'err' : 'ok', 'срок истёк'],
            ['ИИ ждут проверки', $ai_stats['proposed'] ?? 0, 'fas fa-wand-magic-sparkles', ($ai_stats['proposed'] ?? 0) ? 'warn' : 'ok', 'предложений в очереди'],
            ['Закрыто', $stats['closed_count'], 'fas fa-circle-check', 'ok', 'закрытые документы'],
        ];
        foreach ($kpis as $kpi): ?>
            <div class="prl-card">
                <div class="flex items-start justify-between gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kpi[0]); ?></span>
                    <i class="<?php echo HTML::chars($kpi[2]); ?> text-gray-400"></i>
                </div>
                <div class="mt-2 text-3xl font-bold text-gray-900 dark:text-white"><?php echo (int) $kpi[1]; ?></div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kpi[4]); ?></div>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div class="min-w-0">
            <form method="get" action="/documents" class="prl-card">
                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_180px_200px_auto]">
                    <div>
                        <label class="prl-label" for="docs-q">Поиск</label>
                        <input id="docs-q" name="q" type="search" value="<?php echo HTML::chars($filters['q']); ?>" placeholder="№, тема, корреспондент" class="prl-input mt-1 w-full">
                    </div>
                    <div>
                        <label class="prl-label" for="docs-status">Статус</label>
                        <select id="docs-status" name="status" class="prl-select mt-1 w-full">
                            <option value="">Любой</option>
                            <?php foreach (['new' => 'Новый', 'in_review' => 'На разборе', 'in_work' => 'В работе', 'on_hold' => 'Приостановлен', 'closed' => 'Закрыт'] as $code => $label): ?>
                                <option value="<?php echo $code; ?>" <?php echo $filters['status'] === $code ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="prl-label" for="docs-type">Тип</label>
                        <select id="docs-type" name="type" class="prl-select mt-1 w-full">
                            <option value="">Любой</option>
                            <?php foreach ($types as $type): ?>
                                <option value="<?php echo HTML::chars($type['code']); ?>" <?php echo $filters['type'] === $type['code'] ? 'selected' : ''; ?>><?php echo HTML::chars($type['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button class="prl-btn-process" type="submit"><i class="fas fa-filter"></i>Применить</button>
                        <a class="prl-btn-secondary" href="/documents">Сбросить</a>
                    </div>
                </div>
            </form>

            <div class="prl-card mt-4 overflow-x-auto p-0">
                <table class="prl-table min-w-[1080px]">
                    <thead>
                    <tr>
                        <th>Документ</th>
                        <th>Тип</th>
                        <th>Корреспондент</th>
                        <th>Статус</th>
                        <th>Срок</th>
                        <th>ИИ</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <a href="/documents/<?php echo (int) $row['id']; ?>" class="block font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"><?php echo HTML::chars($row['subject'] ?: 'Без темы'); ?></a>
                                <span class="block font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($row['reg_no']); ?></span>
                                <span class="block text-[11px] text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($row['received_at']))); ?></span>
                            </td>
                            <td>
                                <span class="inline-flex items-center gap-1.5"><i class="<?php echo HTML::chars($row['type_icon'] ?: 'fas fa-file'); ?> text-gray-400"></i><?php echo HTML::chars($row['type_title'] ?: 'Не определён'); ?></span>
                            </td>
                            <td><span class="block"><?php echo HTML::chars($row['correspondent_name'] ?: ($row['sender_name'] ?: '—')); ?></span><?php if ( ! empty($row['sender_inn'])): ?><span class="block font-mono text-[11px] text-gray-400">ИНН <?php echo HTML::chars($row['sender_inn']); ?></span><?php endif; ?></td>
                            <td>
                                <?php
                                $status_badge = [
                                    'new' => ['wait', 'Новый'],
                                    'in_review' => ['run', 'На разборе'],
                                    'in_work' => ['run', 'В работе'],
                                    'on_hold' => ['wait', 'Приостановлен'],
                                    'closed' => ['ok', 'Закрыт'],
                                ][ $row['status'] ] ?? ['wait', $row['status']];
                                $priority_badge = ['urgent' => 'err', 'high' => 'run', 'normal' => 'info', 'low' => 'wait'][$row['priority']] ?? 'info';
                                ?>
                                <span class="prl-badge prl-badge-<?php echo $status_badge[0]; ?>"><span class="prl-dot"></span><?php echo HTML::chars($status_badge[1]); ?></span>
                                <?php if ($row['priority'] !== 'normal'): ?><span class="mt-1 block"><span class="prl-badge prl-badge-<?php echo $priority_badge; ?>"><?php echo HTML::chars($row['priority'] === 'urgent' ? 'срочный' : 'высокий'); ?></span></span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['deadline_at']): ?>
                                    <span class="block font-semibold <?php echo $row['deadline_state'] === 'overdue' ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white'; ?>"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($row['deadline_at']))); ?></span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo $row['deadline_state'] === 'overdue' ? 'просрочен' : ($row['deadline_state'] === 'soon' ? 'меньше суток' : 'в работе'); ?></span>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">не назначен</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $row['llm_events'] > 0): ?>
                                    <span class="prl-badge prl-badge-info"><i class="fas fa-wand-magic-sparkles"></i><?php echo (int) $row['llm_events']; ?> пол.</span>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><a class="prl-btn-secondary prl-btn-sm" href="/documents/<?php echo (int) $row['id']; ?>">Карточка</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center text-gray-500 dark:text-gray-400">Документы не найдены. Начните с <a class="text-blue-600 hover:underline dark:text-blue-400" href="/inbox">приёма PDF</a>.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="mt-4 flex items-center justify-between" aria-label="Страницы реестра">
                    <?php $base = $filters; unset($base['page']); ?>
                    <?php if ($page > 1): ?><a class="prl-btn-secondary" href="/documents?<?php echo http_build_query($base + ['page' => $page - 1]); ?>">← Назад</a><?php else: ?><span></span><?php endif; ?>
                    <span class="text-sm text-gray-500 dark:text-gray-400">Страница <?php echo (int) $page; ?> из <?php echo (int) $pages; ?></span>
                    <?php if ($page < $pages): ?><a class="prl-btn-secondary" href="/documents?<?php echo http_build_query($base + ['page' => $page + 1]); ?>">Вперёд →</a><?php else: ?><span></span><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>

        <aside class="space-y-4">
            <section class="prl-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-triangle-exclamation mr-2 text-amber-500"></i>Требует внимания</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    <div class="flex items-center justify-between"><dt class="text-gray-600 dark:text-gray-300">Просрочено</dt><dd class="font-bold <?php echo $stats['overdue'] ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white'; ?>"><?php echo (int) $stats['overdue']; ?></dd></div>
                    <div class="flex items-center justify-between"><dt class="text-gray-600 dark:text-gray-300">Срок &lt; 24 ч</dt><dd class="font-bold text-amber-600 dark:text-amber-400"><?php echo (int) $stats['due_soon']; ?></dd></div>
                    <div class="flex items-center justify-between"><dt class="text-gray-600 dark:text-gray-300">Без исполнителя</dt><dd class="font-bold"><?php echo (int) $stats['unassigned']; ?></dd></div>
                    <div class="flex items-center justify-between"><dt class="text-gray-600 dark:text-gray-300">Поступило сегодня</dt><dd class="font-bold"><?php echo (int) $stats['today']; ?></dd></div>
                </dl>
                <a href="/sla" class="prl-btn-secondary mt-4 w-full justify-center">Открыть сроки</a>
            </section>

            <section class="prl-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-clock-rotate-left mr-2 text-blue-500"></i>Лента</h2>
                <?php if (empty($history)): ?>
                    <div class="prl-empty mt-3"><div class="prl-empty-text">Событий пока нет.</div></div>
                <?php else: ?>
                    <ul class="mt-3 space-y-3">
                        <?php foreach ($history as $h): ?>
                            <li class="border-l-2 border-gray-200 pl-3 dark:border-gray-700">
                                <a href="/documents/<?php echo (int) $h['document_id']; ?>" class="block text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"><?php echo HTML::chars($h['reg_no']); ?></a>
                                <span class="block text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($h['note'] ?: $h['event']); ?></span>
                                <span class="block text-[11px] text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($h['created_at']))); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</div>
