<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Справочник организаций (nj594): поиск и список карточек.
 * Данные открытые (ЕГРЮЛ/ЕГРИП) — страница доступна гостям.
 */

$status_badge = function ($status) {
    $s = trim((string) $status);
    $cls = ($s === '' || mb_stripos($s, 'действ') !== FALSE)
        ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60'
        : ($s === '' ? '' : 'bg-gray-100 text-gray-500 dark:bg-gray-800');

    return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold uppercase '.$cls.'">'
        .HTML::chars($s === '' ? '—' : $s).'</span>';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-medium text-gray-700 dark:text-gray-200">Справочник организаций</span>
    </nav>
    <h1 class="n2j-h1 mt-2"><i class="fas fa-building-columns mr-2 text-blue-500"></i>Справочник организаций и ИП</h1>
    <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Единый хаб платформы: официальные реквизиты организаций (ЕГРЮЛ/ЕГРИП, данные ФНС) и все материалы модулей, привязанные к организации. Поиск — по ОГРН/ОГРНИП, ИНН или названию.</p>

    <!-- KPI -->
    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Карточек в справочнике</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format((int) $counts['total'], 0, ',', ' '); ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Обновлены напрямую из ФНС</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format((int) $counts['fns'], 0, ',', ' '); ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Материалов привязано</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format((int) $counts['links'], 0, ',', ' '); ?></p>
        </div>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Список (2/3) ============ -->
        <div class="min-w-0 md:col-span-2">
            <form method="get" action="/orgs" class="n2j-card">
                <label for="orgs-q" class="n2j-label">Поиск организации</label>
                <div class="mt-1.5 flex flex-col gap-2 sm:flex-row">
                    <input type="text" id="orgs-q" name="q" value="<?php echo HTML::chars($q); ?>" placeholder="ОГРН / ОГРНИП / ИНН / название — напр. «Сбербанк» или 1027700132195"
                           class="n2j-input w-full">
                    <button type="submit" class="n2j-btn-process">
                        <i class="fas fa-magnifying-glass"></i>Найти
                    </button>
                </div>
            </form>

            <div class="n2j-card p-0 mt-4">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h2 class="n2j-h3"><?php echo $q !== '' ? 'Результаты поиска' : 'Организации справочника'; ?></h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($rows); ?></span>
                </div>

                <?php if (empty($rows)): ?>
                <div class="px-4 py-8 text-center sm:px-5">
                    <i class="fas fa-magnifying-glass-minus text-2xl text-gray-300 dark:text-gray-600"></i>
                    <p class="mt-2 text-sm font-semibold text-gray-700 dark:text-gray-200">Ничего не найдено</p>
                    <p class="n2j-hint">Поиск ищет по каждому слову названия — «сбер банк» найдёт и «Сбербанк».</p>

                    <?php if ( ! empty($lookup)): ?>
                    <div class="mx-auto mt-4 max-w-md rounded-xl border border-blue-200 bg-blue-50/70 p-4 text-left dark:border-blue-800/60 dark:bg-blue-950/30">
                        <p class="flex items-center gap-1.5 text-xs font-bold text-blue-800 dark:text-blue-200">
                            <i class="fas fa-globe"></i>Найти в интернете
                        </p>
                        <p class="mt-1 text-xs leading-relaxed text-blue-700 dark:text-blue-300">
                            Организации нет в справочнике — проверим живыми источниками
                            <?php if ($lookup['is_num']): ?> (ЕГРЮЛ/ЕГРИП ФНС)<?php else: ?> (сквозная разведка)<?php endif; ?>.
                        </p>

                        <div class="mt-3 flex flex-wrap justify-center gap-2">
                            <form method="post" action="/orgs/lookup" class="inline">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($lookup['csrf']); ?>">
                                <input type="hidden" name="q" value="<?php echo HTML::chars($q); ?>">
                                <button type="submit" class="n2j-btn-process">
                                    <i class="fas fa-globe"></i>
                                    <?php echo ( ! empty($lookup['is_num'])) ? 'Найти в интернете (ФНС)' : 'Найти в интернете (Разведка)'; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php else: ?>
                    <p class="n2j-hint">
                        Нужной организации нет? Администратор добавит её в <a href="/orgs/admin" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">админке справочника</a> — по названию или ОГРН/ИНН напрямую из ФНС.
                    </p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    <?php foreach ($rows as $o): ?>
                    <li class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                        <a href="/orgs/<?php echo HTML::chars($o['ogrn']); ?>" class="block px-4 py-3 sm:px-5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="min-w-0 flex-1 truncate text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($o['short_name'] ?: $o['full_name']); ?></span>
                                <?php echo $status_badge($o['status']); ?>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                <span class="font-mono font-semibold text-blue-600 dark:text-blue-400"><?php echo HTML::chars($o['ogrn']); ?></span>
                                <?php if ((string) $o['inn'] !== ''): ?><span>ИНН <?php echo HTML::chars($o['inn']); ?></span><?php endif; ?>
                                <?php if ((string) $o['okved'] !== ''): ?><span>ОКВЭД <?php echo HTML::chars($o['okved']); ?></span><?php endif; ?>
                                <?php if ((string) $o['address'] !== ''): ?><span class="min-w-0 truncate"><?php echo HTML::chars($o['address']); ?></span><?php endif; ?>
                            </div>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ Компаньон (1/3) ============ -->
        <div class="hidden space-y-5 md:block">
            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-circle-info text-blue-500"></i>
                    <h3 class="text-sm font-bold">Как работает справочник</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Это общий хаб: модули платформы привязывают свои материалы к организации — карточка показывает всё сразу.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Карточки заполняются из ЕГРЮЛ/ЕГРИП: точечный запрос — сервис ФНС egrul.nalog.ru.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Номера проверяются контрольной цифрой ещё до запроса — опечатка не уйдёт в ФНС.</span></li>
                </ul>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Официальный источник</h3>
                <p class="mt-2 text-xs text-gray-600 sm:text-sm dark:text-gray-300">Полные выгрузки публикует ФНС России в рамках открытых данных: <span class="font-mono text-xs">nalog.gov.ru/opendata/7707329152-egrul</span> (ЕГРЮЛ) и <span class="font-mono text-xs">…-egrip</span> (ЕГРИП) — ZIP-архивы XML, слепок около 15 ГБ + суточные изменения. Импорт идёт на отдельном воркер-сервере порциями через <span class="font-mono text-xs">/orgs/api/import</span> — сайту большие файлы не нужны.</p>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Для модулей</h3>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><span class="font-mono">Service_Nj594_Orgs::upsert()</span> — положить/обновить карточку;</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><span class="font-mono">::link(модуль, код, org_id, …)</span> — привязать материал;</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span><span class="font-mono">::org_for_item()</span> — найти организацию своего артефакта.</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>
