<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['Документооборот'], ['ИИ-разбор']],
        'title' => 'ИИ-разбор входящих',
        'pill' => ['prx392 · предложения', 'fa-wand-magic-sparkles'],
        'actions' => [
            ['label' => 'Реестр', 'href' => '/documents', 'icon' => 'fa-folder-open', 'role' => 'secondary'],
            ['label' => 'Сроки', 'href' => '/sla', 'icon' => 'fa-clock', 'role' => 'secondary'],
        ],
        'lead' => 'Модель извлекает тип и реквизиты, но не пишет в карточку: результат ждёт проверки человеком. Разрешён только локальный провайдер — текст документов не уходит наружу.',
    ]); ?>
    <?php echo View::factory('partials/docflow_flash', ['flash' => $flash]); ?>

    <section class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" aria-label="Показатели ИИ-разбора">
        <?php
        $kpis = [
            ['Прогонов сегодня', $stats['today'], 'fas fa-bolt', 'info', 'за сутки'],
            ['В очереди', $stats['active'], 'fas fa-hourglass-half', $stats['active'] ? 'run' : 'ok', 'ждёт воркер'],
            ['Готово', $stats['ready'], 'fas fa-circle-check', 'ok', 'результат получен'],
            ['Ждут проверки', $stats['proposed'], 'fas fa-user-check', $stats['proposed'] ? 'warn' : 'ok', 'предложений'],
            ['Применено', $stats['accepted'], 'fas fa-check-double', 'ok', 'человеком'],
            ['Средняя уверенность', $stats['avg_confidence'] !== NULL ? (int) round($stats['avg_confidence'] * 100).'%' : '—', 'fas fa-gauge-high', 'info', 'за 30 дней'],
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

    <section class="prx-card mt-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-user-check mr-2 text-blue-500"></i>Предложения ждут решения</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">«Применить» переносит значения в карточку с источником «ИИ»; можно открыть карточку и исправить значения перед применением.</p>
            </div>
            <span class="prx-count"><?php echo count($proposals); ?></span>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="prx-table min-w-[1040px]">
                <thead><tr><th>Документ</th><th>Тип</th><th>Уверенность</th><th>Краткое содержание</th><th>Статус</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($proposals as $p): $payload = json_decode($p['payload'], TRUE) ?: []; ?>
                    <tr>
                        <td>
                            <a class="block font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400" href="/documents/<?php echo (int) $p['document_id']; ?>"><?php echo HTML::chars($p['reg_no']); ?></a>
                            <span class="block max-w-[260px] truncate text-xs text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars($p['subject']); ?>"><?php echo HTML::chars($p['subject']); ?></span>
                            <span class="block text-[11px] text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($p['created_at']))); ?></span>
                        </td>
                        <td><span class="prx-badge prx-badge-info"><?php echo HTML::chars($payload['type'] ?? 'other'); ?></span></td>
                        <td>
                            <?php $pc = (int) round(((float) $p['confidence']) * 100); ?>
                            <div class="flex items-center gap-2"><div class="h-1.5 w-20 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"><div class="h-full rounded-full <?php echo $pc >= 80 ? 'bg-emerald-500' : ($pc >= 50 ? 'bg-amber-500' : 'bg-red-500'); ?>" style="width: <?php echo max(2, $pc); ?>%"></div></div><span class="text-xs font-semibold"><?php echo $pc; ?>%</span></div>
                        </td>
                        <td class="max-w-[320px] text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars(mb_substr($payload['summary'] ?? '', 0, 160)); ?><?php echo mb_strlen($payload['summary'] ?? '') > 160 ? '…' : ''; ?></td>
                        <td><span class="prx-badge prx-badge-<?php echo $p['status'] === 'proposed' ? 'run' : 'ok'; ?>"><span class="prx-dot"></span><?php echo HTML::chars($p['status'] === 'proposed' ? 'ждёт' : 'с правками'); ?></span></td>
                        <td class="text-right">
                            <div class="inline-flex flex-wrap justify-end gap-1">
                                <?php if ($can_run): ?>
                                    <form method="post" action="/analysis/accept/<?php echo (int) $p['id']; ?>" class="inline-flex">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <input type="hidden" name="type" value="<?php echo HTML::chars($payload['type'] ?? 'other'); ?>">
                                        <input type="hidden" name="summary" value="<?php echo HTML::chars($payload['summary'] ?? ''); ?>">
                                        <?php foreach (($payload['fields'] ?? []) as $key => $item): ?><input type="hidden" name="fields[<?php echo HTML::chars($key); ?>]" value="<?php echo HTML::chars($item['value'] ?? ''); ?>"><?php endforeach; ?>
                                        <button class="prx-btn-cta prx-btn-sm" type="submit"><i class="fas fa-check"></i>Применить</button>
                                    </form>
                                    <form method="post" action="/analysis/reject/<?php echo (int) $p['id']; ?>" class="inline-flex">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <button class="prx-btn-secondary prx-btn-sm" type="submit"><i class="fas fa-xmark"></i></button>
                                    </form>
                                <?php endif; ?>
                                <a class="prx-btn-secondary prx-btn-sm" href="/documents/<?php echo (int) $p['document_id']; ?>">Карточка</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($proposals)): ?><tr><td colspan="6" class="text-center text-gray-500 dark:text-gray-400">Очередь пуста. Запустите разбор из карточки документа.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
        <section class="prx-card overflow-x-auto">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-list-check mr-2 text-blue-500"></i>Последние прогоны</h2>
            <table class="prx-table mt-3 min-w-[760px]">
                <thead><tr><th>Прогон</th><th>Документ</th><th>Статус</th><th>Модель</th><th>Токены</th><th>Время</th></tr></thead>
                <tbody>
                <?php foreach ($runs as $run): ?>
                    <tr>
                        <td class="font-mono text-xs">#<?php echo (int) $run['id']; ?><span class="mt-0.5 block text-[11px] text-gray-400"><?php echo HTML::chars(date('d.m H:i', strtotime($run['created_at']))); ?></span></td>
                        <td><a class="font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400" href="/documents/<?php echo (int) $run['document_id']; ?>"><?php echo HTML::chars($run['reg_no'] ?: '—'); ?></a></td>
                        <td><span class="prx-badge prx-badge-<?php echo ['queued' => 'wait', 'running' => 'run', 'ready' => 'ok', 'failed' => 'err'][$run['status']] ?? 'wait'; ?>"><span class="prx-dot"></span><?php echo HTML::chars($run['status']); ?></span><?php if ($run['error']): ?><span class="mt-1 block max-w-[220px] truncate text-xs text-red-500" title="<?php echo HTML::chars($run['error']); ?>"><?php echo HTML::chars($run['error']); ?></span><?php endif; ?></td>
                        <td class="max-w-[220px] truncate font-mono text-xs" title="<?php echo HTML::chars($run['model']); ?>"><?php echo HTML::chars($run['model']); ?></td>
                        <td><?php echo $run['tokens'] !== NULL ? (int) $run['tokens'] : '—'; ?></td>
                        <td><?php echo $run['duration_ms'] !== NULL ? round(((int) $run['duration_ms']) / 1000, 1).' с' : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($runs)): ?><tr><td colspan="6" class="text-center text-gray-500">Прогонов ещё нет.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>

        <aside class="space-y-4">
            <section class="prx-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-shield-halved mr-2 text-emerald-500"></i>Границы доверия</h2>
                <ul class="mt-3 space-y-3 text-sm text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2"><i class="fas fa-check mt-1 text-emerald-500"></i><span>Читает только свой текст и карточку через публичные методы модулей.</span></li>
                    <li class="flex gap-2"><i class="fas fa-check mt-1 text-emerald-500"></i><span>Пишет только прогоны и предложения — не карточку.</span></li>
                    <li class="flex gap-2"><i class="fas fa-check mt-1 text-emerald-500"></i><span>Модель обязана быть `local::…`; проверка до очереди.</span></li>
                    <li class="flex gap-2"><i class="fas fa-check mt-1 text-emerald-500"></i><span>Применение — отдельное право `prx391.edit` и запись в провенанс.</span></li>
                </ul>
            </section>

            <section class="prx-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-sliders mr-2 text-blue-500"></i>Профили</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <?php foreach ($profiles as $code => $profile): ?>
                        <li class="rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                            <span class="block font-semibold"><?php echo HTML::chars($profile['title']); ?></span>
                            <span class="block font-mono text-[11px] text-gray-500 dark:text-gray-400"><?php echo HTML::chars($code); ?> · <?php echo HTML::chars($profile['model']); ?></span>
                            <span class="block text-[11px] text-gray-400">prompt <?php echo HTML::chars($profile['prompt_version']); ?> · schema <?php echo HTML::chars($profile['schema_version']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </aside>
    </div>
</div>
