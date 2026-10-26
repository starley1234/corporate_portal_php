<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Часть управления nj530: короткие ссылки + журнал переходов (IP кликнувших).
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="n2j-card">
        <h1 class="n2j-h1">Короткие ссылки — последние</h1>

        <?php if ( ! empty($_GET['flash'])): ?>
            <div class="n2j-flash n2j-flash-info mt-3 text-xs block">
                <?php echo HTML::chars((string) $_GET['flash']); ?>
            </div>
        <?php endif; ?>

        <form method="get" action="/nj530/admin" class="mt-4 flex flex-wrap items-center gap-2">
            <input type="text" name="q" value="<?php echo HTML::chars($q ?? ''); ?>"
                   placeholder="Поиск: код, URL, домен, IP создателя…"
                   class="n2j-input w-full max-w-xs">
            <button type="submit" class="n2j-btn-process n2j-btn-sm"><i class="fas fa-magnifying-glass mr-1"></i>Найти</button>
            <?php if ( ! empty($q ?? '')): ?>
                <a href="/nj530/admin" class="text-xs text-gray-400 hover:text-gray-600">сбросить</a>
            <?php endif; ?>
        </form>

        <?php if (empty($links)): ?>
            <p class="mt-5 text-sm text-gray-500 dark:text-gray-400">Ссылок не найдено.</p>
        <?php else: ?>
            <div class="mt-4 overflow-x-auto">
                <table class="n2j-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
                            <th class="py-2.5 pr-3 font-semibold">Код</th>
                            <th class="py-2.5 pr-3 font-semibold">Куда ведёт</th>
                            <th class="py-2.5 pr-3 font-semibold">Переходов</th>
                            <th class="py-2.5 pr-3 font-semibold">Создатель</th>
                            <th class="py-2.5 pr-3 font-semibold">Создана</th>
                            <th class="py-2 font-semibold text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($links as $l): ?>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2.5 pr-3">
                                <a href="<?php echo HTML::chars(URL::base(TRUE).'x/'.$l['code']); ?>" target="_blank" rel="noopener"
                                   class="font-mono text-xs font-bold text-blue-600 hover:underline dark:text-blue-400">/x/<?php echo HTML::chars($l['code']); ?></a>
                            </td>
                            <td class="max-w-[22rem] py-2 pr-3">
                                <span class="block truncate text-xs text-gray-700 dark:text-gray-300" title="<?php echo HTML::chars($l['url']); ?>"><?php echo HTML::chars($l['url']); ?></span>
                                <span class="text-xs text-gray-400"><?php echo HTML::chars((string) $l['host']); ?></span>
                            </td>
                            <td class="py-2.5 pr-3 font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $l['clicks']; ?></td>
                            <td class="py-2.5 pr-3 text-xs">
                                <?php if ( ! empty($l['username'])): ?>
                                    <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($l['username']); ?></span>
                                <?php endif; ?>
                                <span class="block font-mono text-xs text-gray-400"><?php echo HTML::chars((string) $l['creator_ip']); ?></span>
                            </td>
                            <td class="py-2.5 pr-3 text-xs text-gray-400"><?php echo HTML::chars((string) $l['created_at']); ?></td>
                            <td class="py-2 text-right">
                                <form method="post" action="/nj530/admin/delete/<?php echo (int) $l['id']; ?>">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <button data-n2j-arm="Удалить?" type="submit" class="n2j-btn-danger n2j-btn-sm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="n2j-card mt-6">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Последние переходы (IP кликнувших)</h2>
        <?php if (empty($clicks)): ?>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Переходов пока не было.</p>
        <?php else: ?>
            <div class="mt-3 overflow-x-auto">
                <table class="n2j-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
                            <th class="py-2.5 pr-3 font-semibold">Когда</th>
                            <th class="py-2.5 pr-3 font-semibold">IP</th>
                            <th class="py-2.5 pr-3 font-semibold">Ссылка</th>
                            <th class="py-2 font-semibold">Домен</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clicks as $c): ?>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2.5 pr-3 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $c['created_at']); ?></td>
                            <td class="py-2.5 pr-3 font-mono text-xs text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $c['ip']); ?></td>
                            <td class="py-2.5 pr-3 font-mono text-xs font-bold text-blue-600 dark:text-blue-400">/x/<?php echo HTML::chars($c['code']); ?></td>
                            <td class="py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $c['host']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
