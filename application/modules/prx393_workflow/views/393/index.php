<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php
    $recalc = '<form method="post" action="/sla/recalc" class="inline-flex">'
        .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
        .'<button type="submit" class="prx-btn-process"><i class="fas fa-calculator"></i><span>Пересчитать сроки</span></button>'
        .'</form>';

    echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['Документооборот'], ['Сроки']],
        'title' => 'Сроки и маршруты',
        'pill' => ['prx393 · правила-first', 'fa-clock'],
        'actions' => $can_manage ? [['html' => $recalc], ['label' => 'Реестр', 'href' => '/documents', 'icon' => 'fa-folder-open', 'role' => 'secondary']] : [['label' => 'Реестр', 'href' => '/documents', 'icon' => 'fa-folder-open', 'role' => 'secondary']],
        'lead' => 'Срок считает этот модуль по производственному календарю РФ. prx391 хранит только снимок для списков; правила и календарь не зависят от ответа модели.',
    ]);
    echo View::factory('partials/docflow_flash', ['flash' => $flash]);
    ?>

    <section class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" aria-label="Показатели сроков">
        <?php
        $kpis = [
            ['Открыто', $stats['open_count'], 'fas fa-hourglass-half', 'info', 'в работе'],
            ['Просрочено', $stats['overdue'], 'fas fa-fire', $stats['overdue'] ? 'err' : 'ok', 'срок истёк'],
            ['Меньше суток', $stats['soon'], 'fas fa-triangle-exclamation', $stats['soon'] ? 'warn' : 'ok', 'требуют внимания'],
            ['Закрыто в срок', $stats['met'], 'fas fa-circle-check', 'ok', 'статус met'],
            ['Средний срок', $stats['avg_hours'] !== NULL ? $stats['avg_hours'].' ч' : '—', 'fas fa-gauge-high', 'info', 'от постановки до срока'],
        ];
        foreach ($kpis as $kpi): ?>
            <div class="prx-card">
                <div class="flex items-start justify-between gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kpi[0]); ?></span>
                    <i class="<?php echo HTML::chars($kpi[2]); ?> text-gray-400"></i>
                </div>
                <div class="mt-2 text-3xl font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $kpi[1]); ?></div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kpi[4]); ?></div>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="prx-card mt-5 overflow-x-auto">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-list-ol mr-2 text-amber-500"></i>Открытые сроки</h2>
            <span class="prx-count"><?php echo count($deadlines); ?></span>
        </div>
        <table class="prx-table mt-3 min-w-[1060px]">
            <thead><tr><th>Документ</th><th>Отдел / роль</th><th>Источник</th><th>Срок</th><th>Осталось</th><th>Состояние</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($deadlines as $dl): ?>
                <tr>
                    <td>
                        <a class="block font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400" href="/documents/<?php echo (int) $dl['document_id']; ?>"><?php echo HTML::chars($dl['reg_no']); ?></a>
                        <span class="block max-w-[280px] truncate text-xs text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars($dl['subject']); ?>"><?php echo HTML::chars($dl['subject']); ?></span>
                    </td>
                    <td><span class="block"><?php echo HTML::chars($dl['department'] ?: '—'); ?></span><span class="block font-mono text-[11px] text-gray-400"><?php echo HTML::chars($dl['role_code'] ?: '—'); ?></span></td>
                    <td><span class="prx-badge prx-badge-<?php echo $dl['source'] === 'hint' ? 'info' : 'wait'; ?>"><?php echo HTML::chars(['rule' => 'правило', 'hint' => 'текст письма', 'default' => 'по умолчанию'][$dl['source']] ?? $dl['source']); ?></span></td>
                    <td class="whitespace-nowrap font-semibold"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($dl['due_at']))); ?></td>
                    <td><?php $hl = (float) $dl['hours_left']; ?><span class="font-semibold <?php echo $hl < 0 ? 'text-red-600 dark:text-red-400' : ($hl <= 24 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white'); ?>"><?php echo $hl < 0 ? '−'.round(abs($hl)).' ч' : round($hl).' ч'; ?></span></td>
                    <td><span class="prx-badge prx-badge-<?php echo ['overdue' => 'err', 'soon' => 'run', 'ok' => 'wait', 'met' => 'ok'][$dl['state']] ?? 'wait'; ?>"><span class="prx-dot"></span><?php echo HTML::chars(['overdue' => 'просрочен', 'soon' => 'меньше суток', 'ok' => 'в работе', 'met' => 'закрыт'][$dl['state']] ?? $dl['state']); ?></span></td>
                    <td class="text-right"><a class="prx-btn-secondary prx-btn-sm" href="/documents/<?php echo (int) $dl['document_id']; ?>">Карточка</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($deadlines)): ?><tr><td colspan="7" class="text-center text-gray-500 dark:text-gray-400">Открытых сроков нет. Они появятся после регистрации документа.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
        <section class="prx-card overflow-x-auto">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-scale-balanced mr-2 text-blue-500"></i>Правила маршрутизации</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Побеждает первое подходящее правило по приоритету. Отправитель и ИНН важнее типа; если тип уже известен, ключевое слово из другого типа маршрут не меняет. Данные — из текста и конверта, не из ответа ИИ.</p>
            <table class="prx-table mt-3 min-w-[720px]">
                <thead><tr><th>Правило</th><th>Условия</th><th>Маршрут</th><th>Срок</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td><span class="block font-semibold"><?php echo HTML::chars($rule['title']); ?></span><span class="font-mono text-[11px] text-gray-400"><?php echo HTML::chars($rule['code']); ?> · приоритет <?php echo (int) $rule['priority']; ?></span></td>
                        <td class="max-w-[300px] text-xs text-gray-600 dark:text-gray-300">
                            <span class="block"><b>типы:</b> <?php echo HTML::chars(trim((string) $rule['match_types'], '{}') ?: '—'); ?></span>
                            <span class="block"><b>домены:</b> <?php echo HTML::chars(trim((string) $rule['match_domains'], '{}') ?: '—'); ?></span>
                            <span class="block"><b>слова:</b> <?php echo HTML::chars(trim((string) $rule['match_keywords'], '{}') ?: '—'); ?></span>
                        </td>
                        <td><span class="block"><?php echo HTML::chars($rule['assign_department'] ?: '—'); ?></span><span class="font-mono text-[11px] text-gray-400"><?php echo HTML::chars($rule['assign_role'] ?: '—'); ?></span></td>
                        <td class="whitespace-nowrap"><?php echo (int) $rule['sla_hours']; ?> ч<span class="block text-[11px] text-gray-400"><?php echo $rule['business_days'] ? 'рабочие' : 'календарные'; ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <aside class="space-y-4">
            <section class="prx-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-bullhorn mr-2 text-red-500"></i>Эскалации</h2>
                <?php if ($escalations): ?>
                    <ul class="mt-3 space-y-3">
                        <?php foreach ($escalations as $e): ?>
                            <li class="border-l-2 border-amber-300 pl-3 dark:border-amber-700">
                                <a class="block text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400" href="/documents/<?php echo (int) $e['document_id']; ?>"><?php echo HTML::chars($e['reg_no']); ?></a>
                                <span class="block text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars(['t24' => 'T−24 ч', 't4' => 'T−4 ч', 'overdue' => 'просрочка'][$e['level']] ?? $e['level']); ?> · <?php echo HTML::chars(date('d.m.Y H:i', strtotime($e['sent_at']))); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Эскалаций нет — все сроки под контролем.</p>
                <?php endif; ?>
            </section>

            <section class="prx-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-calendar-days mr-2 text-blue-500"></i>Календарь</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Праздники РФ 2026 заданы в миграции; выходные — суббота и воскресенье. Срок в рабочих днях всегда ставится на 18:00 последнего рабочего дня.</p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Срочные правила (суд, ФАС) считаются в календарных часах; длинные — в рабочих днях.</p>
            </section>
        </aside>
    </div>
</div>
