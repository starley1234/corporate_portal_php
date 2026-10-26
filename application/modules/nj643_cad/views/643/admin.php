<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: /cad/admin — управление сервисом.
 *
 * Владелец (27.09.2026): «CAD-движок проверяю… — убрать проверку. Добавить на
 * страницу возможность удаления».
 *
 * Поэтому: без кнопки «Проверить движок» и её живого ряда (состояние видно по
 * журналу сборок), зато с превью деталей, почтой владельца и удалением.
 * Адрес внешнего движка на странице не показываем нигде (владелец: «не пиши
 * нигде url движка») — только имена переменных окружения.
 */
$stats      = isset($stats) ? (array) $stats : [];
$parts      = isset($parts) ? (array) $parts : [];
$usage      = isset($usage) ? (array) $usage : [];
$usage_log  = isset($usage_log) ? (array) $usage_log : [];
$runs       = isset($runs) ? (array) $runs : [];
$engine     = isset($engine) ? (array) $engine : [];
$daily      = isset($daily) ? (array) $daily : [];
$totals     = isset($totals) ? (array) $totals : [];
$q          = isset($q) ? (string) $q : '';
$page       = isset($page) ? (int) $page : 1;
$pages      = isset($pages) ? (int) $pages : 1;
$total      = isset($total) ? (int) $total : 0;
$csrf       = isset($csrf) ? (string) $csrf : '';
$flash      = isset($flash) ? trim((string) $flash) : '';

$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700/80 dark:bg-gray-800';

$st_ru = ['queued' => 'в очереди', 'running' => 'собирается', 'done' => 'готова',
          'failed' => 'ошибка', 'cancelled' => 'остановлено'];
$st_badge = [
    'queued'    => 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    'running'   => 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
    'done'      => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    'failed'    => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
    'cancelled' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
];
?>
<div class="n2j-ds w-full space-y-5 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <header>
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/cad" class="transition hover:text-blue-600 dark:hover:text-blue-400">CAD-детали</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-semibold text-gray-700 dark:text-gray-300">Управление</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center gap-2.5">
            <h1 class="n2j-h1"><i class="fas fa-lock mr-2 text-blue-500"></i>CAD-детали · управление</h1>
        </div>
        <p class="mt-1.5 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
            Все детали сервиса, журнал сборок и расход ИИ. Движок вызывает воркер: ключ
            (<span class="font-mono text-[13px]">NJ643_CAD_API_KEY</span> или общий <span class="font-mono text-[13px]">TOOLFORGE_API_KEY</span>)
            и адрес задаются в <span class="font-mono text-[13px]">workers/.env</span> на сервере воркера.
        </p>
    </header>

    <!-- Метрики -->
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <div class="<?php echo $card; ?>">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Деталей</p>
            <p class="mt-1.5 text-2xl font-black text-gray-900 dark:text-white"><?php echo (int) ($stats['parts'] ?? 0); ?></p>
            <p class="text-sm text-gray-400">готовых: <?php echo (int) ($stats['done'] ?? 0); ?>, с ошибкой: <?php echo (int) ($stats['failed'] ?? 0); ?></p>
        </div>
        <div class="<?php echo $card; ?>">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">В работе</p>
            <p class="mt-1.5 text-2xl font-black text-blue-600 dark:text-blue-400"><?php echo (int) ($stats['running'] ?? 0); ?></p>
            <p class="text-sm text-gray-400">очередь и сборка</p>
        </div>
        <div class="<?php echo $card; ?>">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Файлов</p>
            <p class="mt-1.5 text-2xl font-black text-gray-900 dark:text-white"><?php echo (int) ($stats['files'] ?? 0); ?></p>
            <p class="text-sm text-gray-400"><?php echo round((int) ($stats['files_bytes'] ?? 0) / 1048576, 1); ?> МБ на диске</p>
        </div>
        <div class="<?php echo $card; ?>">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Токенов ИИ</p>
            <p class="mt-1.5 text-2xl font-black text-gray-900 dark:text-white"><?php echo number_format((int) ($stats['tokens'] ?? 0), 0, ',', ' '); ?></p>
            <p class="text-sm text-gray-400">вызовов: <?php echo (int) ($stats['jobs'] ?? 0); ?></p>
        </div>
    </div>

    <!-- Состояние движка: читается из журнала, ручной проверки нет -->
    <?php
    $e_ok  = (isset($engine['last_ok']) AND is_array($engine['last_ok'])) ? $engine['last_ok'] : NULL;
    $e_bad = (isset($engine['last_bad']) AND is_array($engine['last_bad'])) ? $engine['last_bad'] : NULL;
    $e_all = (int) ($engine['day_total'] ?? 0);
    $e_okn = (int) ($engine['day_ok'] ?? 0);
    $e_pct = $e_all > 0 ? (int) round($e_okn / $e_all * 100) : 0;
    ?>
    <section class="<?php echo $card; ?>">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="n2j-h3">Состояние движка</h2>
            <span class="text-sm text-gray-400">по журналу за сутки — без ручной проверки</span>
        </div>
        <div class="mt-2 grid gap-3 sm:grid-cols-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Последний успешный вызов</p>
                <p class="mt-1 text-sm text-gray-800 dark:text-gray-200">
                    <?php if ($e_ok): ?>
                    <?php echo HTML::chars((string) $e_ok['created_at']); ?> ·
                    <?php echo round((int) $e_ok['duration_ms'] / 1000, 1); ?> с ·
                    HTTP <?php echo (int) $e_ok['http_status']; ?>
                    <?php else: ?>
                    <span class="text-gray-400">ещё не было</span>
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Успешных за сутки</p>
                <p class="mt-1 text-sm text-gray-800 dark:text-gray-200">
                    <?php echo $e_okn; ?> из <?php echo $e_all; ?>
                    <?php if ($e_all > 0): ?><span class="font-semibold <?php echo $e_pct >= 90 ? 'text-emerald-600 dark:text-emerald-400' : ($e_pct >= 60 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400'); ?>">(<?php echo $e_pct; ?> %)</span><?php endif; ?>
                    · средняя сборка <?php echo round((int) ($engine['day_avg_ms'] ?? 0) / 1000, 1); ?> с
                </p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Последняя ошибка</p>
                <p class="mt-1 text-sm text-gray-800 dark:text-gray-200">
                    <?php if ($e_bad): ?>
                    <?php echo HTML::chars((string) $e_bad['created_at']); ?> ·
                    HTTP <?php echo (int) $e_bad['http_status']; ?>
                    <span class="block text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $e_bad['error'], 0, 120)); ?></span>
                    <?php else: ?>
                    <span class="text-gray-400">за всё время не было</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </section>

    <!-- Журнал движка -->
    <?php if ($runs): ?>
    <section class="<?php echo $card; ?>">
        <h2 class="n2j-h3">Журнал обращений к движку</h2>
        <div class="mt-2 overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
            <table class="n2j-table">
                <thead>
                    <tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
                        <th class="py-2 pr-3">Когда</th>
                        <th class="py-2 px-3">Что</th>
                        <th class="py-2 px-3">Итог</th>
                        <th class="py-2 px-3">HTTP</th>
                        <th class="py-2 px-3">Время</th>
                        <th class="py-2 pl-3">Причина</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $r): ?>
                    <tr class="border-b border-gray-50 dark:border-gray-700/40">
                        <td class="py-2 pr-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $r['created_at']); ?></td>
                        <td class="py-2 px-3"><?php echo (string) $r['kind'] === 'check' ? 'проверка движка' : 'сборка'; ?><?php echo (int) $r['part_id'] > 0 ? ' #'.(int) $r['part_id'] : ''; ?></td>
                        <td class="py-2 px-3 font-semibold <?php echo ! empty($r['ok']) ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'; ?>"><?php echo ! empty($r['ok']) ? 'ок' : 'ошибка'; ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo (int) $r['http_status']; ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo round((int) $r['duration_ms'] / 1000, 1); ?> с</td>
                        <td class="py-2 pl-3 max-w-md truncate text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars((string) $r['error']); ?>"><?php echo HTML::chars(mb_substr((string) $r['error'], 0, 90)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <!-- Все детали -->
    <section class="<?php echo $card; ?>">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="n2j-h3">Детали</h2>
            <span class="rounded-md bg-gray-100 px-2 py-0.5 text-sm font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300"><?php echo $total; ?></span>
            <form method="get" action="/cad/admin" class="ml-auto flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="<?php echo HTML::chars($q); ?>" placeholder="поиск по названию и коду"
                       class="n2j-input min-w-[180px]">
                <button type="submit" class="n2j-btn-secondary">
                    <i class="fas fa-magnifying-glass text-[11px]"></i>Найти
                </button>
                <a href="/cad/admin?export=csv<?php echo $q !== '' ? '&q='.urlencode($q) : ''; ?>" title="Реестр деталей: код, владелец, габарит, объём, дата"
                   class="n2j-btn-secondary">
                    <i class="fas fa-file-csv text-[11px]"></i>CSV
                </a>
            </form>
        </div>

        <?php if ( ! $parts): ?>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Ничего не нашлось.</p>
        <?php else: ?>
        <div class="mt-2 -mx-4 overflow-x-auto n2j-scrollbar px-4" style="scrollbar-width:thin">
            <table class="n2j-table">
                <thead>
                    <tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
                        <th class="py-2 pr-3">Превью</th>
                        <th class="py-2 px-3">Деталь</th>
                        <th class="py-2 px-3">Владелец</th>
                        <th class="py-2 px-3">Состояние</th>
                        <th class="py-2 px-3">Ревизия</th>
                        <th class="py-2 px-3">Файлов</th>
                        <th class="py-2 px-3">Создана</th>
                        <th class="py-2 pl-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parts as $p): ?>
                    <tr class="border-b border-gray-50 dark:border-gray-700/40">
                        <td class="py-2 pr-3">
                            <a href="/cad/<?php echo urlencode((string) $p['code']); ?>" class="block h-9 w-12 overflow-hidden rounded border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/40" title="Открыть деталь">
                                <?php if ( ! empty($p['preview_id'])): ?>
                                <img src="/cad/file/<?php echo (int) $p['preview_id']; ?>" alt="" class="h-full w-full object-cover" loading="lazy">
                                <?php else: ?>
                                <span class="flex h-full w-full items-center justify-center text-gray-300 dark:text-gray-600"><i class="fas fa-cube text-sm"></i></span>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td class="py-2 px-3">
                            <a href="/cad/<?php echo urlencode((string) $p['code']); ?>" class="font-semibold text-gray-800 hover:text-blue-600 hover:underline dark:text-gray-200 dark:hover:text-blue-400"><?php echo HTML::chars(mb_substr((string) $p['title'], 0, 50)); ?></a>
                            <span class="ml-1 font-mono text-[12px] text-gray-400"><?php echo HTML::chars((string) $p['code']); ?></span>
                        </td>
                        <td class="py-2 px-3">
                            <?php
                            // Владелец — почтой: по «#12» непонятно, чья деталь
                            // (владелец, 27.09: «Владелец - нужно писать емейл»).
                            $own_mail = trim((string) ($p['owner_email'] ?? ''));
                            $own_name = trim((string) ($p['owner_name'] ?? ''));
                            ?>
                            <?php if ($own_mail !== ''): ?>
                            <a href="mailto:<?php echo HTML::chars($own_mail); ?>" class="text-gray-700 hover:text-blue-600 hover:underline dark:text-gray-300 dark:hover:text-blue-400"><?php echo HTML::chars($own_mail); ?></a>
                            <?php if ($own_name !== '' AND $own_name !== $own_mail): ?>
                            <span class="block text-[12px] text-gray-400"><?php echo HTML::chars($own_name); ?></span>
                            <?php endif; ?>
                            <?php elseif ((int) $p['user_id'] > 0): ?>
                            <span class="text-gray-500 dark:text-gray-400">пользователь #<?php echo (int) $p['user_id']; ?> <span class="text-gray-400">(почта не заполнена)</span></span>
                            <?php else: ?>
                            <span class="text-gray-400">гость</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2 px-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-bold <?php echo $st_badge[(string) $p['status']] ?? $st_badge['queued']; ?>"><?php echo HTML::chars($st_ru[(string) $p['status']] ?? (string) $p['status']); ?></span>
                            <?php if (trim((string) $p['error']) !== ''): ?>
                            <span class="ml-1 text-rose-600 dark:text-rose-400" title="<?php echo HTML::chars((string) $p['error']); ?>"><?php echo HTML::chars(mb_substr((string) $p['error'], 0, 40)); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo (int) $p['revision']; ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo (int) $p['files_count']; ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $p['created_at']); ?></td>
                        <td class="py-2 pl-3">
                            <form method="post" class="inline-flex">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <input type="hidden" name="act" value="delete_part">
                                <input type="hidden" name="code" value="<?php echo HTML::chars((string) $p['code']); ?>">
                                <button data-n2j-arm="Точно удалить?" type="submit" class="n2j-btn-danger n2j-btn-sm" title="Удалить деталь и файлы">
                                    <i class="fas fa-trash-can text-[11px]"></i>Удалить
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
            <?php if ($page > 1): ?><a href="/cad/admin?q=<?php echo urlencode($q); ?>&page=<?php echo $page - 1; ?>" class="n2j-btn-secondary n2j-btn-sm">Назад</a><?php endif; ?>
            <span class="px-1">стр. <?php echo $page; ?> из <?php echo $pages; ?></span>
            <?php if ($page < $pages): ?><a href="/cad/admin?q=<?php echo urlencode($q); ?>&page=<?php echo $page + 1; ?>" class="n2j-btn-secondary n2j-btn-sm">Вперёд</a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Расход ИИ по дням: динамика, а не одна сумма -->
    <?php if ($daily): ?>
    <section class="<?php echo $card; ?>">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="n2j-h3">Расход ИИ по дням</h2>
            <span class="text-sm text-gray-400">
                за сутки: <?php echo number_format((int) ($totals['day_tokens'] ?? 0), 0, ',', ' '); ?> токенов
                (<?php echo (int) ($totals['day_calls'] ?? 0); ?> вызовов) ·
                за неделю: <?php echo number_format((int) ($totals['week_tokens'] ?? 0), 0, ',', ' '); ?> токенов
                (<?php echo (int) ($totals['week_calls'] ?? 0); ?> вызовов)
            </span>
        </div>
        <div class="mt-2 overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
            <table class="n2j-table">
                <thead>
                    <tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
                        <th class="py-2 pr-3">День</th>
                        <th class="py-2 px-3">Вызовов</th>
                        <th class="py-2 px-3">Запрос</th>
                        <th class="py-2 px-3">Ответ</th>
                        <th class="py-2 px-3">Всего</th>
                        <th class="py-2 px-3">Самый большой ответ</th>
                        <th class="py-2 pl-3">На вызов</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily as $d): ?>
                    <tr class="border-b border-gray-50 dark:border-gray-700/40">
                        <td class="py-2 pr-3 font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $d['day']); ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo (int) $d['calls']; ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo number_format((int) $d['prompt_tokens'], 0, ',', ' '); ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo number_format((int) $d['completion_tokens'], 0, ',', ' '); ?></td>
                        <td class="py-2 px-3 font-semibold text-gray-800 dark:text-gray-200"><?php echo number_format((int) $d['tokens'], 0, ',', ' '); ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo number_format((int) $d['max_reply'], 0, ',', ' '); ?></td>
                        <td class="py-2 pl-3 text-gray-500 dark:text-gray-400"><?php echo (int) $d['calls'] > 0 ? number_format((int) round((int) $d['tokens'] / (int) $d['calls']), 0, ',', ' ') : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="mt-1.5 text-sm text-gray-400 dark:text-gray-500">«Ответ» — в том числе рассуждения модели: в тексте их не видно, но провайдер их считает.</p>
    </section>
    <?php endif; ?>

    <!-- Последние вызовы ИИ: видно, из чего сложился расход -->
    <?php if ($usage_log): ?>
    <section class="<?php echo $card; ?>">
        <details>
            <summary class="cursor-pointer text-base font-bold text-gray-900 dark:text-white">
                Последние вызовы ИИ
                <span class="ml-1 font-normal text-gray-400">из чего сложились токены</span>
            </summary>
            <div class="mt-2 overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
                <table class="n2j-table">
                    <thead>
                        <tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
                            <th class="py-2 pr-3">Деталь</th>
                            <th class="py-2 px-3">Фаза</th>
                            <th class="py-2 px-3">Модель</th>
                            <th class="py-2 px-3">Токены</th>
                            <th class="py-2 px-3">Из чего сложилось</th>
                            <th class="py-2 pl-3">Когда</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usage_log as $u): ?>
                        <tr class="border-b border-gray-50 dark:border-gray-700/40">
                            <td class="py-2 pr-3">
                                <?php if ( ! empty($u['code'])): ?>
                                <a href="/cad/<?php echo urlencode((string) $u['code']); ?>" class="font-semibold text-gray-800 hover:text-blue-600 hover:underline dark:text-gray-200 dark:hover:text-blue-400"><?php echo HTML::chars(mb_substr((string) (($u['title'] ?? '') !== '' ? $u['title'] : $u['code']), 0, 32)); ?></a>
                                <?php else: ?><span class="text-gray-400">—</span><?php endif; ?>
                            </td>
                            <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $u['phase']); ?></td>
                            <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $u['model']); ?></td>
                            <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo (int) $u['prompt_tokens']; ?>+<span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) $u['completion_tokens']; ?></span></td>
                            <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) ($u['note'] !== '' ? $u['note'] : '—')); ?></td>
                            <td class="py-2 pl-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $u['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </section>
    <?php endif; ?>

    <!-- Расход -->
    <?php if ($usage): ?>
    <section class="<?php echo $card; ?>">
        <h2 class="n2j-h3">Расход ИИ по деталям</h2>
        <div class="mt-2 overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
            <table class="n2j-table">
                <thead>
                    <tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
                        <th class="py-2 pr-3">Деталь</th>
                        <th class="py-2 px-3">Вызовов</th>
                        <th class="py-2 px-3">Токенов</th>
                        <th class="py-2 px-3">Время ИИ</th>
                        <th class="py-2 pl-3">Последний</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usage as $u): ?>
                    <tr class="border-b border-gray-50 dark:border-gray-700/40">
                        <td class="py-2 pr-3">
                            <?php if ( ! empty($u['code'])): ?>
                            <a href="/cad/<?php echo urlencode((string) $u['code']); ?>" class="font-semibold text-gray-800 hover:text-blue-600 hover:underline dark:text-gray-200 dark:hover:text-blue-400"><?php echo HTML::chars(mb_substr((string) (($u['title'] ?? '') !== '' ? $u['title'] : $u['code']), 0, 44)); ?></a>
                            <?php else: ?>
                            <span class="text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo (int) $u['calls']; ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo (int) $u['prompt_tokens']; ?>+<?php echo (int) $u['completion_tokens']; ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo round((int) $u['duration_ms'] / 1000, 1); ?> с</td>
                        <td class="py-2 pl-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $u['last_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <!-- Журнал удалений: детали удаляются мягко, файлы с диска стираются, поэтому
         единственная память о детали — эта строка (владелец, 28.09.2026: «надо
         чтобы детали не удалялись просто так»). -->
    <?php if ( ! empty($audit)): ?>
    <section class="<?php echo $card; ?>">
        <h2 class="n2j-h3">Удаления</h2>
        <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">Кто и что убрал: деталь, ревизия, сколько файлов стёрто с диска.</p>
        <div class="mt-2 overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
            <table class="n2j-table">
                <thead>
                    <tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:text-gray-400">
                        <th class="py-2 pr-3">Когда</th>
                        <th class="py-2 px-3">Кто</th>
                        <th class="py-2 px-3">Что</th>
                        <th class="py-2 pl-3">Подробности</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit as $a): ?>
                    <tr class="border-b border-gray-50 dark:border-gray-700/40">
                        <td class="py-2 pr-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $a['created_at']); ?></td>
                        <td class="py-2 px-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars(trim((string) ($a['email'] ?? '')) !== '' ? (string) $a['email'] : ('id '.(int) $a['user_id'])); ?></td>
                        <td class="py-2 px-3">
                            <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $a['action'] === 'delete_revision' ? 'Ревизия' : 'Деталь'); ?></span>
                            <?php if ((string) $a['code'] !== ''): ?>
                            <a href="/cad/<?php echo urlencode((string) $a['code']); ?>" class="ml-1 font-mono text-sm text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $a['code']); ?></a>
                            <?php endif; ?>
                            <span class="text-gray-400"><?php echo HTML::chars(mb_substr((string) $a['title'], 0, 40)); ?></span>
                        </td>
                        <td class="py-2 pl-3 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $a['detail']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>
