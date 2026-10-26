<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление nj580: пакеты документов + учёт ресурсов воркера.
 * Таблицы по стандарту (py-2.5, text-sm), удаление в 2 шага (без confirm).
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="mb-4">
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/docs" class="transition hover:text-blue-600 dark:hover:text-blue-400">Документы организации</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-medium text-gray-700 dark:text-gray-200">Управление</span>
        </nav>
        <h1 class="n2j-h1 mt-2"><i class="fas fa-folder-open mr-2 text-blue-500"></i>Документы организации · управление</h1>
    </div>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash <?php echo ($flash['type'] ?? 'ok') === 'err' ? 'n2j-flash-err' : 'n2j-flash-ok'; ?> mb-3">
        <i class="fas <?php echo ($flash['type'] ?? 'ok') === 'err' ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?> shrink-0"></i>
        <span><?php echo HTML::chars((string) ($flash['text'] ?? '')); ?></span>
    </div>
    <?php elseif ( ! empty($_GET['flash'])): ?>
    <div id="admin-flash" data-nj580-autohide class="n2j-flash n2j-flash-info mb-3 flex items-center gap-3 text-xs">
        <i class="fas fa-circle-info shrink-0"></i>
        <span><?php echo HTML::chars((string) $_GET['flash']); ?></span>
    </div>
    <script>setTimeout(function () { var f = document.getElementById('admin-flash'); if (f) f.hidden = true; }, 10000);</script>
    <?php endif; ?>

    <!-- KPI -->
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Вызовов LLM всего</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format((int) ($usage['calls'] ?? 0), 0, ',', ' '); ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Токены: вход / выход</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format((int) ($usage['prompt_tokens'] ?? 0), 0, ',', ' '); ?></p>
            <p class="n2j-hint">выход: <?php echo number_format((int) ($usage['completion_tokens'] ?? 0), 0, ',', ' '); ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Пакетов в списке</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo count((array) $packages); ?></p>
        </div>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Пакеты (2/3) ============ -->
        <div class="min-w-0 md:col-span-2">
            <div class="n2j-card p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h2 class="n2j-h3">Пакеты документов</h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count((array) $packages); ?></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="n2j-table whitespace-nowrap">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                                <th class="py-2.5 pl-4 pr-3 sm:pl-5">Код</th>
                                <th class="py-2.5 px-3">Организация</th>
                                <th class="py-2.5 px-3">Док.</th>
                                <th class="py-2.5 px-3">Статус</th>
                                <th class="py-2.5 px-3">Создан</th>
                                <th class="py-2.5 pl-3 pr-4 text-right sm:pr-5">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                            <?php foreach ((array) $packages as $p): ?>
                            <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25<?php echo $p['deleted_at'] ? ' opacity-50' : ''; ?>">
                                <td class="py-2.5 pl-4 pr-3 font-mono text-xs sm:pl-5"><a href="/docs/<?php echo HTML::chars($p['code']); ?>" class="font-bold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($p['code']); ?></a></td>
                                <td class="max-w-[14rem] truncate px-3 py-2.5" title="<?php echo HTML::chars((string) $p['progress']); ?>"><?php echo HTML::chars($p['org_name']); ?></td>
                                <td class="px-3 py-2.5"><?php echo (int) $p['docs']; ?></td>
                                <td class="px-3 py-2.5 text-xs"><?php echo $p['deleted_at'] ? '<span class="text-gray-400">скрыт · </span>' : ''; ?><?php echo HTML::chars($p['status']); ?></td>
                                <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $p['created_at']); ?></td>
                                <td class="py-2.5 pl-3 pr-4 text-right sm:pr-5">
                                    <div class="inline-flex items-center justify-end gap-1.5">
                                        <?php if (in_array($p['status'], ['new', 'running'], TRUE)): ?>
                                        <form method="post" action="/nj580/admin/cancel/<?php echo (int) $p['id']; ?>">
                                            <input type="hidden" name="csrf" value="<?php echo Security::token(); ?>">
                                            <button data-n2j-arm="Точно?" type="submit" class="n2j-btn-danger-soft n2j-btn-sm">остановить</button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($p['deleted_at']): ?>
                                        <form method="post" action="/nj580/admin/delete/<?php echo (int) $p['id']; ?>">
                                            <input type="hidden" name="csrf" value="<?php echo Security::token(); ?>">
                                            <input type="hidden" name="mode" value="restore">
                                            <button type="submit" class="n2j-btn-secondary n2j-btn-sm">вернуть</button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="post" action="/nj580/admin/delete/<?php echo (int) $p['id']; ?>">
                                            <input type="hidden" name="csrf" value="<?php echo Security::token(); ?>">
                                            <input type="hidden" name="mode" value="soft">
                                            <button data-n2j-arm="Скрыть?" type="submit" class="n2j-btn-secondary n2j-btn-sm">скрыть</button>
                                        </form>
                                        <form method="post" action="/nj580/admin/delete/<?php echo (int) $p['id']; ?>">
                                            <input type="hidden" name="csrf" value="<?php echo Security::token(); ?>">
                                            <input type="hidden" name="mode" value="hard">
                                            <button data-n2j-arm="Удалить навсегда?" type="submit" class="n2j-btn-danger n2j-btn-sm">удалить</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($packages)): ?>
                            <tr><td colspan="6" class="py-6 pl-4 text-center text-sm text-gray-400 sm:pl-5">Пакетов пока нет.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="n2j-hint border-t border-gray-100 px-4 py-3 sm:px-5">«Остановить» снимает ожидающую задачу сразу; уже захваченная воркером — на ближайшем контрольном пункте (перед следующим документом). Опасные действия — в два клика: первый подсвечивает кнопку, второй выполняет.</p>
            </div>
        </div>

        <!-- ============ Правая колонка (1/3): вызовы LLM ============ -->
        <div class="min-w-0 space-y-4 md:col-span-1">
            <div class="n2j-card p-0">
                <div class="border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h2 class="n2j-h3">Последние вызовы LLM</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="n2j-table">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                                <th class="py-2.5 pl-4 pr-3 sm:pl-5">Фаза</th>
                                <th class="py-2.5 px-3">Модель</th>
                                <th class="py-2.5 px-3">Вх/Вых</th>
                                <th class="py-2.5 pr-4 text-right sm:pr-5">мс</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-xs text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                            <?php foreach ((array) $recent as $u): ?>
                            <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                                <td class="py-2.5 pl-4 pr-3 sm:pl-5"><?php echo HTML::chars($u['phase']); ?></td>
                                <td class="max-w-[10rem] truncate px-3 py-2.5 font-mono" title="<?php echo HTML::chars($u['model']); ?>"><?php echo HTML::chars($u['model']); ?></td>
                                <td class="px-3 py-2.5 font-mono"><?php echo (int) $u['prompt_tokens']; ?>/<?php echo (int) $u['completion_tokens']; ?></td>
                                <td class="py-2.5 pr-4 text-right font-mono text-gray-400 sm:pr-5"><?php echo (int) $u['duration_ms']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recent)): ?>
                            <tr><td colspan="4" class="py-6 pl-4 text-center text-sm text-gray-400 sm:pl-5">Вызовов пока нет.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

