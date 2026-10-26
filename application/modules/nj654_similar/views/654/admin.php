<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Админка банка (/similar/admin): что кладут и что ищут.
 *
 * Оформление — по дизайн-эталону /pages/required_design_sample: шапка с
 * крошками и бейджем, сетка KPI (числа, без полос), таблицы плотностью
 * py-2.5 / text-sm с горизонтальной прокруткой, удаление в два шага.
 *
 * @var array  $stats   сводка
 * @var array  $items   последние добавленные
 * @var array  $queries последние поиски
 * @var array  $failed  элементы, которым не посчитали вектор
 * @var string $flash   сообщение после действия
 * @var string $csrf    токен формы
 */

$kinds  = ['text' => 'текст', 'image' => 'картинка', 'both' => 'текст и картинка'];
$bytes  = max(0, (int) ($stats['bytes'] ?? 0));
$weight = $bytes >= 1048576
    ? number_format($bytes / 1048576, 1, ',', ' ').' МБ'
    : number_format($bytes / 1024, 0, ',', ' ').' КБ';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <!-- ============ Шапка модуля ============ -->
    <header>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
                <h1 class="n2j-h1">Похожие картинки</h1>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
                    <i class="fas fa-shield-halved text-[11px]"></i>Админ
                </span>
            </div>
        </div>
        <p class="mt-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
            Два журнала: что люди кладут в банк и что они в нём ищут. Рядом — уборка.
        </p>
    </header>

    <?php if ($flash !== ''): ?>
        <div class="n2j-flash n2j-flash-ok mt-5 flex items-center gap-3 text-xs">
            <i class="fas fa-check-circle shrink-0 text-base text-emerald-600 dark:text-emerald-400"></i>
            <span><?php echo HTML::chars($flash); ?></span>
        </div>
    <?php endif; ?>

    <!-- ============ Сводка (числом, без полос) ============ -->
    <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Элементов</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400">
                    <i class="fas fa-layer-group text-sm"></i>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format((int) $stats['total'], 0, ',', ' '); ?></span>
            </div>
            <p class="n2j-hint">с картинкой <?php echo (int) $stats['images']; ?></p>
        </div>

        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">На диске</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400">
                    <i class="fas fa-hard-drive text-sm"></i>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo HTML::chars($weight); ?></span>
            </div>
            <p class="n2j-hint">после уменьшения на входе</p>
        </div>

        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Поисков</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400">
                    <i class="fas fa-magnifying-glass text-sm"></i>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo number_format((int) $stats['queries'], 0, ',', ' '); ?></span>
            </div>
            <p class="n2j-hint">за всё время</p>
        </div>

        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Ждут вектор</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400">
                    <i class="fas fa-clock-rotate-left text-sm"></i>
                </span>
            </div>
            <div class="mt-2.5 flex items-baseline gap-2">
                <span class="text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) $stats['waiting']; ?></span>
                <?php if ((int) $stats['failed'] > 0): ?>
                    <span class="text-xs font-semibold text-rose-600 dark:text-rose-400">ошибок <?php echo (int) $stats['failed']; ?></span>
                <?php endif; ?>
            </div>
            <?php if ((int) $stats['waiting'] > 0): ?>
            <div class="mt-1.5 flex items-center gap-1.5 text-xs">
                <i class="fas fa-spinner n2j-spin text-[11px] text-amber-600 dark:text-amber-400"></i>
                <span class="font-medium text-gray-700 dark:text-gray-300">Воркер активен</span>
            </div>
            <?php else: ?>
                <p class="n2j-hint">модель: <?php echo HTML::chars(mb_substr((string) $stats['model'], 0, 28) ?: '—'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <form method="post" action="/similar/admin/drop_failed">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <button data-n2j-arm="Точно выбросить?" type="submit"
                    class="n2j-btn-secondary">
                <i class="fas fa-broom text-xs"></i><span>Выбросить несчитанные (<?php echo (int) $stats['failed']; ?>)</span>
            </button>
        </form>
        <form method="post" action="/similar/admin/clear_log">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <button data-n2j-arm="Точно очистить?" type="submit"
                    class="n2j-btn-secondary">
                <i class="fas fa-eraser text-xs"></i><span>Очистить журнал поисков</span>
            </button>
        </form>
    </div>

    <!-- ============ Что ищут ============ -->
    <div class="n2j-card p-0 mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Что ищут</h2>
                <span class="n2j-badge n2j-badge-wait"><?php echo count($queries); ?></span>
            </div>
        </div>

        <?php if ( ! $queries): ?>
            <p class="px-4 py-8 text-center text-sm text-gray-500 sm:px-5 dark:text-gray-400">Поисков ещё не было.</p>
        <?php else: ?>
        <div class="n2j-scrollbar overflow-x-auto">
            <table class="n2j-table whitespace-nowrap">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                        <th class="py-2.5 pr-3 pl-4 sm:pl-5">Запрос</th>
                        <th class="px-3 py-2.5">Чем</th>
                        <th class="px-3 py-2.5">Статус</th>
                        <th class="px-3 py-2.5">Нашлось</th>
                        <th class="px-3 py-2.5">Посетитель</th>
                        <th class="py-2.5 pr-4 pl-3 text-right sm:pr-5">Когда</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                    <?php foreach ($queries as $q): ?>
                    <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                        <td class="max-w-[320px] truncate py-2.5 pr-3 pl-4 font-semibold text-gray-900 sm:pl-5 dark:text-white">
                            <?php echo HTML::chars((string) $q['text'] !== '' ? (string) $q['text'] : '(без текста)'); ?>
                        </td>
                        <td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($kinds[(string) $q['kind']] ?? 'текст'); ?></td>
                        <td class="px-3 py-2.5">
                            <?php if ((string) $q['status'] === 'ready'): ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>готово
                                </span>
                            <?php elseif ((string) $q['status'] === 'error'): ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-rose-700 dark:text-rose-400">
                                    <span class="h-2 w-2 rounded-full bg-rose-500"></span><?php echo HTML::chars(mb_substr((string) $q['error'], 0, 40)); ?>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-400">
                                    <span class="h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>считается
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="max-w-[260px] truncate px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">
                            <?php if ($q['hits']): ?>
                                <?php echo HTML::chars(mb_substr((string) ($q['hits'][0]['title'] ?? ''), 0, 40)); ?>
                                <span class="ml-1 font-mono text-xs text-gray-400"><?php echo HTML::chars(number_format((float) ($q['hits'][0]['score'] ?? 0), 3, ',', ' ')); ?></span>
                            <?php else: ?>
                                <span class="text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2.5 font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $q['visitor'], 0, 8)); ?></td>
                        <td class="py-2.5 pr-4 pl-3 text-right text-xs text-gray-500 sm:pr-5 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $q['created_at'], 0, 16)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============ Что добавляют ============ -->
    <div class="n2j-card p-0 mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
            <div class="flex items-center gap-2.5">
                <h2 class="n2j-h3">Что добавляют</h2>
                <span class="n2j-badge n2j-badge-wait"><?php echo count($items); ?></span>
            </div>
        </div>

        <?php if ( ! $items): ?>
            <p class="px-4 py-8 text-center text-sm text-gray-500 sm:px-5 dark:text-gray-400">Банк пуст.</p>
        <?php else: ?>
        <form method="post" action="/similar/admin/delete">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

            <div class="n2j-scrollbar overflow-x-auto">
                <table class="n2j-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pr-3 pl-4 sm:pl-5">Элемент</th>
                            <th class="px-3 py-2.5">Статус</th>
                            <th class="px-3 py-2.5">Размер</th>
                            <th class="px-3 py-2.5">Посетитель</th>
                            <th class="py-2.5 pr-4 pl-3 text-right sm:pr-5">Добавлен</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                        <?php foreach ($items as $item): ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="py-2.5 pr-3 pl-4 sm:pl-5">
                                <label class="flex items-center gap-2.5">
                                    <input type="checkbox" name="ids[]" value="<?php echo (int) $item['id']; ?>"
                                           class="h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600">
                                    <?php if ((string) $item['file'] !== ''): ?>
                                        <img src="<?php echo HTML::chars(Service_Nj654_Bank::file_url((string) $item['file'])); ?>"
                                             alt="" loading="lazy" class="h-10 w-10 shrink-0 rounded-md object-cover">
                                    <?php else: ?>
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-400 dark:bg-gray-700">
                                            <i class="fas fa-align-left text-xs"></i>
                                        </span>
                                    <?php endif; ?>
                                    <span class="inline-block max-w-[260px] truncate font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $item['title']); ?></span>
                                </label>
                            </td>
                            <td class="px-3 py-2.5">
                                <?php if ((string) $item['status'] === 'ready'): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>в поиске
                                    </span>
                                <?php elseif ((string) $item['status'] === 'error'): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-rose-700 dark:text-rose-400">
                                        <span class="h-2 w-2 rounded-full bg-rose-500"></span>ошибка
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-400">
                                        <span class="h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>считается
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2.5 font-mono text-xs text-gray-500 dark:text-gray-400">
                                <?php echo (int) $item['width'] > 0
                                    ? (int) $item['width'].'×'.(int) $item['height']
                                    : '—'; ?>
                            </td>
                            <td class="px-3 py-2.5 font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $item['visitor'], 0, 8)); ?></td>
                            <td class="py-2.5 pr-4 pl-3 text-right text-xs text-gray-500 sm:pr-5 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $item['created_at'], 0, 16)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-700/60">
                <span class="text-xs text-gray-500 dark:text-gray-400">Отметьте строки — файлы удалятся вместе с элементами.</span>
                <button data-n2j-arm="Точно убрать?" type="submit"
                        class="n2j-btn-danger">
                    <i class="fas fa-trash-can text-xs"></i><span>Убрать отмеченные</span>
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($failed): ?>
    <!-- ============ Не посчитались ============ -->
    <div class="n2j-card mt-6 border-rose-200 dark:border-rose-900/60">
        <h2 class="n2j-h3">Не посчитались</h2>
        <ul class="mt-3 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
            <?php foreach ($failed as $item): ?>
            <li class="flex flex-wrap items-baseline gap-x-2">
                <span class="font-mono text-xs font-bold text-blue-600 dark:text-blue-400">#<?php echo (int) $item['id']; ?></span>
                <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars(mb_substr((string) $item['title'], 0, 50)); ?></span>
                <span class="text-rose-600 dark:text-rose-400"><?php echo HTML::chars(mb_substr((string) $item['error'], 0, 120)); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>

