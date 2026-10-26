<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление справочника организаций (nj594): карточки, обновление из ФНС,
 * удаление вторым нажатием (data-n2j-arm), загрузка по ОГРН/ИНН. Роль admin.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/orgs" class="transition hover:text-blue-600 dark:hover:text-blue-400">Справочник организаций</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Управление</span>
    </nav>
    <h1 class="n2j-h1 mt-2"><i class="fas fa-user-gear mr-2 text-blue-500"></i>Справочник организаций · управление</h1>

    <?php if ((string) $flash !== ''): ?>
    <div class="n2j-flash <?php echo $flash_err ? 'n2j-flash-err' : 'n2j-flash-ok'; ?> mt-4">
        <i class="fas <?php echo $flash_err ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?> shrink-0"></i>
        <span><?php echo HTML::chars($flash); ?></span>
    </div>
    <?php endif; ?>

    <!-- KPI -->
    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Карточек всего</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format((int) $counts['total'], 0, ',', ' '); ?></p>
            <p class="n2j-hint">ИП: <?php echo (int) $counts['ie']; ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Обновлены из ФНС</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format((int) $counts['fns'], 0, ',', ' '); ?></p>
            <p class="n2j-hint">egrul.nalog.ru, точечно</p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Связей с модулями</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format((int) $counts['links'], 0, ',', ' '); ?></p>
            <p class="n2j-hint">nj580 и другие</p>
        </div>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Таблица карточек (2/3) ============ -->
        <div class="min-w-0 md:col-span-2">
            <div class="n2j-card p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h2 class="n2j-h3">Карточки организаций</h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($rows); ?></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="n2j-table whitespace-nowrap">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                                <th class="py-2.5 pl-4 pr-3 sm:pl-5">ОГРН</th>
                                <th class="py-2.5 px-3">Название</th>
                                <th class="py-2.5 px-3">ИНН / КПП</th>
                                <th class="py-2.5 px-3">Источник</th>
                                <th class="py-2.5 px-3">Связи</th>
                                <th class="py-2.5 pl-3 pr-4 text-right sm:pr-5">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                            <?php foreach ($rows as $r): ?>
                            <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                                <td class="py-2.5 pl-4 pr-3 font-mono text-xs sm:pl-5"><a href="/orgs/<?php echo HTML::chars($r['ogrn']); ?>" class="font-bold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($r['ogrn']); ?></a></td>
                                <td class="max-w-[14rem] truncate px-3 py-2.5" title="<?php echo HTML::chars((string) $r['full_name']); ?>"><?php echo HTML::chars($r['short_name'] ?: $r['full_name']); ?></td>
                                <td class="px-3 py-2.5 font-mono text-xs"><?php echo HTML::chars($r['inn']); ?><?php echo (string) $r['kpp'] !== '' ? ' / '.HTML::chars($r['kpp']) : ''; ?></td>
                                <td class="px-3 py-2.5 text-xs">
                                    <?php if ((string) $r['source'] === 'fns'): ?>
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 font-bold uppercase text-emerald-600 dark:bg-emerald-950/60">ФНС</span>
                                    <?php else: ?>
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 font-bold uppercase text-gray-500 dark:bg-gray-800"><?php echo HTML::chars($r['source']); ?></span>
                                    <?php endif; ?>
                                    <?php echo $r['fetched_at'] ? '<span class="text-gray-400">'.HTML::chars(Date::formatted_time((string) $r['fetched_at'], 'd.m.y')).'</span>' : ''; ?>
                                </td>
                                <td class="px-3 py-2.5"><?php echo (int) $r['links']; ?></td>
                                <td class="py-2.5 pl-3 pr-4 text-right sm:pr-5">
                                    <div class="inline-flex items-center justify-end gap-1.5">
                                        <form method="post" action="/orgs/admin/refresh/<?php echo (int) $r['id']; ?>" class="nj594-refresh">
                                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                            <button type="submit" class="n2j-btn-secondary n2j-btn-sm"><span class="nj594-refresh-label">обновить</span></button>
                                        </form>
                                        <form method="post" action="/orgs/admin/delete/<?php echo (int) $r['id']; ?>">
                                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                            <button data-n2j-arm="Удалить?" type="submit" class="n2j-btn-danger n2j-btn-sm">удалить</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($rows)): ?>
                            <tr><td colspan="6" class="py-6 pl-4 text-center text-sm text-gray-400 sm:pl-5">Справочник пуст — заполните демо-данными или загрузите карточку из ФНС.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="n2j-hint border-t border-gray-100 px-4 py-3 sm:px-5">«Обновить» — точечный запрос в ФНС (несколько секунд). Удаление — в два клика: первый подсвечивает кнопку, второй выполняет; вместе с карточкой уйдут и связи модулей.</p>
            </div>
        </div>

        <!-- ============ Компаньон (1/3): пополнение ============ -->
        <div class="min-w-0 space-y-5 md:col-span-1">

            <div class="n2j-card">
                <h3 class="n2j-h3 border-b border-gray-100 pb-3"><i class="fas fa-download mr-1.5 text-blue-500"></i>Загрузить из ФНС</h3>
                <form method="post" action="/orgs/admin/fetch" class="mt-3 nj594-refresh">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <label for="orgs-fetch" class="n2j-label">По номеру — ОГРН / ОГРНИП / ИНН</label>
                    <input type="text" id="orgs-fetch" name="ogrn" inputmode="numeric" autocomplete="off" placeholder="13/15 или 10/12 цифр"
                           class="n2j-input mt-1.5 w-full tracking-[0.1em]">
                    <button type="submit" class="n2j-btn-process mt-2.5 w-full"><span class="nj594-refresh-label"><i class="fas fa-cloud-arrow-down mr-1.5"></i>Найти и сохранить</span></button>
                    <p class="n2j-hint mt-2">Контрольная цифра проверяется до запроса; карточка из ФНС сохраняется с меткой времени.</p>
                </form>
                <form method="post" action="/orgs/admin/findname" class="mt-4 border-t border-gray-100 pt-3.5 nj594-refresh dark:border-gray-700/60">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <label for="orgs-findname" class="n2j-label">По названию — даже неточному</label>
                    <input type="text" id="orgs-findname" name="name" value="<?php echo HTML::chars((string) ($name_q ?? '')); ?>" autocomplete="off" placeholder="напр. «ромашка москва» или «сбер»"
                           class="n2j-input mt-1.5 w-full">
                    <button type="submit" class="n2j-btn-secondary mt-2.5 w-full"><span class="nj594-refresh-label"><i class="fas fa-magnifying-glass mr-1.5"></i>Искать в ФНС</span></button>
                    <p class="n2j-hint mt-2">Официальный поиск ЕГРЮЛ/ЕГРИП найдёт организацию по вхождению слов; кандидатов проверим и сохраним одним кликом.</p>
                </form>

                <?php if ( ! empty($candidates)): ?>
                <div class="mt-4 border-t border-gray-100 pt-3.5 dark:border-gray-700/60">
                    <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-list mr-1.5 text-blue-500"></i>Кандидаты из ФНС <span class="font-normal text-xs text-gray-400">(нажмите «сохранить» у нужной)</span></p>
                    <ul class="mt-2 space-y-2">
                        <?php foreach ($candidates as $c): ?>
                        <li class="rounded-lg border border-gray-100 px-3 py-2.5 dark:border-gray-700">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $c['full_name']); ?></p>
                                    <p class="n2j-hint mt-0.5">
                                        <?php if ((string) ($c['ogrn'] ?? '') !== ''): ?><span class="font-mono font-semibold text-blue-600 dark:text-blue-400"><?php echo HTML::chars($c['ogrn']); ?></span> · <?php endif; ?>
                                        <?php if ((string) ($c['inn'] ?? '') !== ''): ?>ИНН <?php echo HTML::chars($c['inn']); ?><?php endif; ?>
                                        <?php if ((string) ($c['address'] ?? '') !== ''): ?> · <span class="min-w-0"><?php echo HTML::chars(mb_substr((string) $c['address'], 0, 70)); ?></span><?php endif; ?>
                                    </p>
                                </div>
                                <form method="post" action="/orgs/admin/fetch" class="nj594-refresh shrink-0">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <input type="hidden" name="ogrn" value="<?php echo HTML::chars((string) ($c['ogrn'] ?? '')); ?>">
                                    <button type="submit" class="n2j-btn-secondary n2j-btn-sm"><span class="nj594-refresh-label">сохранить</span></button>
                                </form>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <!-- Запросы к ФНС: мягкий лимит и журнал (все выходы в реестр — здесь) -->
            <?php
                $st    = $fns['stats'];
                $quota = $fns['quota'];
                $st_map = ['ok' => ['нашли', 'text-emerald-600 dark:text-emerald-400'],
                           'empty' => ['пусто', 'text-gray-500 dark:text-gray-400'],
                           'limited' => ['лимит', 'text-amber-600 dark:text-amber-400'],
                           'error' => ['ошибка', 'text-rose-500']];
            ?>
            <div class="n2j-card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-gauge-high mr-1.5 text-blue-500"></i>Запросы к ФНС</h3>
                    <span class="n2j-badge <?php echo $quota['ok'] ? 'n2j-badge-ok' : 'n2j-badge-run'; ?> uppercase">
                        <?php echo $quota['ok'] ? 'лимит свободен' : 'лимит исчерпан'; ?>
                    </span>
                </div>

                <p class="n2j-hint mt-1.5">Лимит: <?php echo HTML::chars((string) $fns['limit']); ?> на адрес; общий кап платформы — <?php echo (int) $quota['limits']['total_per_hour']; ?> запросов в час (бережём реестр от перегрузки).</p>

                <div class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                    <?php foreach ([
                        ['всего', $st['total'], 'text-gray-900 dark:text-white'],
                        ['нашли', $st['ok'], 'text-emerald-600 dark:text-emerald-400'],
                        ['пусто', $st['empty'], 'text-gray-500 dark:text-gray-400'],
                        ['по лимиту', $st['limited'], 'text-amber-600 dark:text-amber-400'],
                    ] as $m): ?>
                    <div class="rounded-lg border border-gray-100 px-2.5 py-2 dark:border-gray-700">
                        <p class="n2j-hint"><?php echo HTML::chars($m[0]); ?> за сутки</p>
                        <p class="mt-0.5 text-base font-black tracking-tight <?php echo $m[2]; ?>"><?php echo (int) $m[1]; ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>

                <p class="n2j-hint mt-2">За последний час: <?php echo (int) $st['hour']; ?> запросов, с вашего адреса — <?php echo (int) $quota['minute']; ?> в минуту и <?php echo (int) $quota['hour']; ?> в час.</p>

                <?php if ( ! $quota['ok']): ?>
                <p class="n2j-flash n2j-flash-warn mt-2 text-xs block">
                    <i class="fas fa-circle-exclamation mr-1"></i><?php echo HTML::chars((string) $quota['message']); ?>
                </p>
                <?php endif; ?>

                <?php if ( ! empty($fns['recent'])): ?>
                <ul class="mt-3 divide-y divide-gray-100 border-t border-gray-100 pt-2 dark:divide-gray-700/60 dark:border-gray-700/60">
                    <?php foreach ($fns['recent'] as $r): ?>
                    <?php $bad = $st_map[(string) $r['status']] ?? [ (string) $r['status'], 'text-gray-500' ]; ?>
                    <li class="flex items-center gap-2 py-1.5 text-xs">
                        <span class="shrink-0 font-mono text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $r['created_at'], 'd.m H:i')); ?></span>
                        <span class="min-w-0 flex-1 break-words text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $r['query']); ?></span>
                        <span class="shrink-0 font-semibold <?php echo $bad[1]; ?>"><?php echo HTML::chars($bad[0]); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="n2j-hint mt-3 border-t border-gray-100 pt-2">Журнал пуст — запросов к ФНС ещё не было.</p>
                <?php endif; ?>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Демо-данные</h3>
                <p class="mt-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">12 организаций из открытых источников (Сбербанк, Яндекс, Газпром, РЖД, Лукойл, Магнит, Ростелеком, Вкусвилл, Ozon, РВБ, X5, ТБанк). Контрольные цифры проверены.</p>
                <form method="post" action="/orgs/admin/seed" class="mt-2.5">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button type="submit" class="n2j-btn-secondary n2j-btn-sm">Добавить отсутствующие</button>
                </form>
            </div>

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-database text-blue-500"></i>
                    <h3 class="text-sm font-bold">План полного импорта</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Источник — открытые данные ФНС: <span class="font-mono">nalog.gov.ru/opendata/7707329152-egrul</span> и <span class="font-mono">…-egrip</span> (ZIP XML, слепок ≈15 ГБ + суточные дельты).</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Скачивание и разбор — на воркер-сервере: архив обрабатывается потоком и сразу удаляется, на сайте файлов не хранится.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Карточки заливаются порциями ≤500 в <span class="font-mono text-xs">POST /orgs/api/import</span> с токеном воркера; обновление — теми же суточными дельтами.</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('594/script'); ?>
