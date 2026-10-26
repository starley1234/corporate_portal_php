<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Банк картинок (/similar/collection): пополнение и правка.
 *
 * Страница рассчитана на сотню картинок, а не на три: содержимое банка
 * показывается плотной сеткой квадратных миниатюр (до шести в ряд) с
 * постраничной навигацией, а подписи правятся в режиме «списком» — там
 * поля идут подряд, и можно пройти десяток строк не трогая мышь.
 *
 * Оформление — по дизайн-эталону /pages/required_design_sample: шапка с
 * крошками, форма 2/3 + блок-компаньон 1/3 (на телефоне скрыт), карточки
 * rounded-xl, контролы rounded-lg, тач-таргеты 44 px, удаление в два шага,
 * никаких полос прогресса.
 *
 * @var array  $stats   сводка банка
 * @var array  $items   элементы текущей страницы
 * @var int    $total   сколько всего под отбором
 * @var array  $filter  текущий отбор
 * @var bool   $mine    показаны только свои
 * @var bool   $grid    вид: плитка (TRUE) или список (FALSE)
 * @var int    $page    текущая страница
 * @var int    $pages   сколько страниц
 * @var array  $edit    элемент, открытый на правку (или NULL)
 * @var array  $limits  пределы: bulk, mb, width
 * @var string $visitor метка этого браузера
 * @var bool   $admin   админ ли смотрит
 * @var string $csrf    токен формы
 * @var string $flash   сообщение после действия
 */

$waiting = (int) ($stats['waiting'] ?? 0);
$bytes   = max(0, (int) ($stats['bytes'] ?? 0));
$weight  = $bytes >= 1048576
    ? number_format($bytes / 1048576, 1, ',', ' ').' МБ'
    : number_format($bytes / 1024, 0, ',', ' ').' КБ';

/** Ссылка на тот же список с изменённым параметром — отбор не теряется. */
$link = function (array $change = []) use ($filter, $mine, $grid, $page) {
    $args = array_filter([
        'search' => (string) $filter['search'],
        'mine'   => $mine ? '1' : '',
        'view'   => $grid ? 'grid' : '',
        'p'      => $page > 1 ? (string) $page : '',
    ], 'strlen');

    foreach ($change as $key => $value)
    {
        if ((string) $value === '')
        {
            unset($args[$key]);
        }
        else
        {
            $args[$key] = (string) $value;
        }
    }

    return '/similar/collection'.($args ? '?'.http_build_query($args) : '');
};

/** Скрытые поля «откуда пришли»: правка и удаление возвращают на это же место. */
$whence = function () use ($filter, $mine, $grid, $page) { ?>
    <input type="hidden" name="search" value="<?php echo HTML::chars((string) $filter['search']); ?>">
    <input type="hidden" name="mine" value="<?php echo $mine ? '1' : ''; ?>">
    <input type="hidden" name="view" value="<?php echo $grid ? 'grid' : ''; ?>">
    <input type="hidden" name="p" value="<?php echo (int) $page; ?>">
<?php };

$own = function ($item) use ($admin, $visitor) {
    return ($admin OR (string) $item['visitor'] === (string) $visitor);
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <!-- ============ Шапка модуля ============ -->
    <header>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
                <h1 class="n2j-h1">Банк картинок</h1>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
                    <i class="fas fa-layer-group text-[11px]"></i><?php echo (int) $stats['total']; ?> элементов
                </span>
                <span class="hidden text-xs text-gray-400 sm:inline">
                    с картинкой <?php echo (int) $stats['images']; ?> · на диске <?php echo HTML::chars($weight); ?>
                    <?php if ($waiting > 0): ?>
                        · <i class="fas fa-spinner n2j-spin text-[11px] text-amber-600"></i> считается <?php echo $waiting; ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </header>

    <?php if ($flash !== ''): ?>
        <div class="n2j-flash n2j-flash-ok mt-4 flex items-center gap-3 text-xs">
            <i class="fas fa-check-circle shrink-0 text-emerald-600 dark:text-emerald-400"></i>
            <span><?php echo HTML::chars($flash); ?></span>
        </div>
    <?php endif; ?>

    <!-- ============ Пополнение: 2/3 + компаньон 1/3 ============ -->
    <div class="mt-5 gap-5 space-y-5 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <div class="n2j-card md:col-span-2">
            <div class="flex items-baseline justify-between gap-2 border-b border-gray-100 pb-2.5 dark:border-gray-700/60">
                <h2 class="n2j-h3">Добавить</h2>
                <span class="text-xs text-gray-400">до <?php echo (int) $limits['bulk']; ?> файлов за раз</span>
            </div>

            <form method="post" action="/similar/add" enctype="multipart/form-data" class="mt-3 space-y-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

                <input id="nj654-files" name="images[]" type="file" multiple accept="image/*"
                       class="block min-h-[44px] w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-600 outline-none transition file:mr-3 file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-blue-700 focus:border-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:file:bg-blue-900/40 dark:file:text-blue-200">

                <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                    <input name="title" type="text" maxlength="200" autocomplete="off"
                           placeholder="Подпись (для одного элемента)"
                           class="n2j-input w-full">
                    <input name="note" type="text" maxlength="1000" autocomplete="off"
                           placeholder="Текст — его тоже ищем"
                           class="n2j-input w-full">
                    <button type="submit"
                            class="n2j-btn-cta">
                        <i class="fas fa-plus"></i>Добавить
                    </button>
                </div>

                <p class="n2j-hint">
                    Подписью каждого файла станет его имя. Картинки ужимаются до
                    <?php echo (int) $limits['width']; ?> px по ширине перед сохранением; одинаковые не задваиваются.
                    Картинка необязательна — можно добавить одно текстовое описание.
                </p>
            </form>
        </div>

        <!-- Блок-компаньон: на телефоне скрыт -->
        <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40">
            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                <i class="fas fa-lightbulb text-amber-500"></i>
                <h3 class="text-sm font-bold">Быстрые советы</h3>
            </div>
            <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Выделите в проводнике всю папку — загрузится до <?php echo (int) $limits['bulk']; ?> файлов разом.</span>
                </li>
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Для правки подписей подряд переключитесь на вид «Списком».</span>
                </li>
                <li class="flex items-start gap-1.5">
                    <span class="font-bold text-blue-500">•</span>
                    <span>Элемент можно добавить и без картинки — одним текстом.</span>
                </li>
            </ul>
        </div>
    </div>

    <!-- ============ Правка одного элемента ============ -->
    <?php if ($edit AND $own($edit)): ?>
    <form method="post" action="/similar/rename"
          class="mt-5 rounded-xl border border-blue-300 bg-blue-50/60 p-4 shadow-xs dark:border-blue-800/70 dark:bg-blue-950/30">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">
        <?php $whence(); ?>

        <div class="flex flex-wrap items-center gap-3 sm:flex-nowrap">
            <?php if ((string) $edit['file'] !== ''): ?>
                <?php $url = Service_Nj654_Bank::file_url((string) $edit['file']); ?>
                <button type="button" class="shrink-0 cursor-zoom-in" title="Посмотреть целиком"
                        data-nj654-zoom="<?php echo HTML::chars($url); ?>"
                        data-nj654-zoom-title="<?php echo HTML::chars((string) $edit['title']); ?>">
                    <img src="<?php echo HTML::chars($url); ?>" alt="" class="h-12 w-12 rounded-md object-cover">
                </button>
            <?php endif; ?>
            <input name="title" type="text" maxlength="200" value="<?php echo HTML::chars((string) $edit['title']); ?>"
                   class="n2j-input w-full flex-1 sm:w-auto">
            <input name="note" type="text" maxlength="1000" value="<?php echo HTML::chars((string) $edit['note']); ?>"
                   placeholder="текст элемента"
                   class="n2j-input w-full flex-1 sm:w-auto">
            <button type="submit"
                    class="n2j-btn-cta">
                Сохранить
            </button>
            <a href="<?php echo HTML::chars($link(['edit' => ''])); ?>"
               class="n2j-btn-secondary">
                Отмена
            </a>
        </div>
    </form>
    <?php endif; ?>

    <!-- ============ Содержимое банка ============ -->
    <section class="mt-5">
        <!-- Панель управления: отбор, вид, счётчик -->
        <div class="n2j-card p-0 flex flex-wrap items-center gap-2 px-3 py-2.5">
            <form method="get" action="/similar/collection" class="flex flex-1 flex-wrap items-center gap-2">
                <input type="hidden" name="view" value="<?php echo $grid ? 'grid' : ''; ?>">
                <div class="relative min-w-[160px] flex-1">
                    <i class="fas fa-search absolute top-1/2 left-3 -translate-y-1/2 text-xs text-gray-400"></i>
                    <input type="search" name="search" autocomplete="off"
                           value="<?php echo HTML::chars((string) $filter['search']); ?>"
                           placeholder="Найти в банке…"
                           class="n2j-input min-h-[40px] w-full text-xs sm:text-sm pl-9">
                </div>
                <label class="inline-flex min-h-[40px] cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                    <input type="checkbox" name="mine" value="1" <?php echo $mine ? 'checked' : ''; ?>
                           class="h-4 w-4 rounded border-gray-300 text-blue-600">мои
                </label>
                <button type="submit"
                        class="n2j-btn-process">
                    Показать
                </button>
            </form>

            <span class="hidden text-xs text-gray-400 sm:inline">
                <?php echo (int) $total; ?> шт. · стр. <?php echo (int) $page; ?> из <?php echo (int) $pages; ?>
            </span>

            <!-- Переключатель вида -->
            <div class="n2j-seg inline-flex items-center">
                <a href="<?php echo HTML::chars($link(['view' => 'grid', 'p' => ''])); ?>" title="Плиткой"
                   class="n2j-seg-item w-8 px-0<?php echo $grid ? ' is-active' : ''; ?>">
                    <i class="fas fa-grip text-xs"></i>
                </a>
                <a href="<?php echo HTML::chars($link(['view' => '', 'p' => ''])); ?>" title="Списком"
                   class="n2j-seg-item w-8 px-0<?php echo ! $grid ? ' is-active' : ''; ?>">
                    <i class="fas fa-table-list text-xs"></i>
                </a>
            </div>
        </div>

        <?php if ( ! $items): ?>
            <div class="mt-4 rounded-xl border border-dashed border-gray-300 bg-white px-4 py-10 text-center shadow-xs dark:border-gray-600 dark:bg-gray-800">
                <i class="fas fa-images text-2xl text-gray-300 dark:text-gray-600"></i>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    <?php echo ((string) $filter['search'] !== '' OR $mine)
                        ? 'Под этот отбор ничего не нашлось.'
                        : 'Пока пусто. Выберите несколько картинок выше — и банк начнёт работать.'; ?>
                </p>
            </div>
        <?php else: ?>

        <form method="post" action="/similar/delete" class="mt-4" id="nj654-list">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <?php $whence(); ?>

            <?php if ($grid): ?>
            <!-- ---------- Плитка: максимум картинок на экран ---------- -->
            <ul class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8">
                <?php foreach ($items as $item): ?>
                <li class="group relative overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xs transition hover:border-blue-400 dark:border-gray-700/80 dark:bg-gray-800">
                    <?php if ($own($item)): ?>
                        <label class="absolute top-1 left-1 z-10 flex h-6 w-6 cursor-pointer items-center justify-center rounded-md bg-white/85 shadow-xs dark:bg-gray-900/80">
                            <input type="checkbox" name="ids[]" value="<?php echo (int) $item['id']; ?>" data-nj654-box
                                   class="h-4 w-4 rounded border-gray-300 text-blue-600">
                        </label>
                        <a href="<?php echo HTML::chars($link(['edit' => (int) $item['id']])); ?>" title="Править подпись"
                           class="absolute top-1 right-1 z-10 flex h-6 w-6 items-center justify-center rounded-md bg-white/85 text-gray-500 opacity-0 shadow-xs transition group-hover:opacity-100 hover:text-blue-600 dark:bg-gray-900/80 dark:text-gray-300">
                            <i class="fas fa-pen text-[10px]"></i>
                        </a>
                    <?php endif; ?>

                    <?php if ((string) $item['status'] !== 'ready'): ?>
                        <span class="absolute bottom-7 left-1 z-10 rounded-md bg-white/85 px-1.5 py-0.5 text-xs font-semibold dark:bg-gray-900/80 <?php
                            echo (string) $item['status'] === 'error'
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-amber-700 dark:text-amber-400'; ?>">
                            <?php echo (string) $item['status'] === 'error' ? 'ошибка' : 'считается'; ?>
                        </span>
                    <?php endif; ?>

                    <?php if ((string) $item['file'] !== ''): ?>
                        <?php $url = Service_Nj654_Bank::file_url((string) $item['file']); ?>
                        <button type="button" class="block w-full cursor-zoom-in" title="Посмотреть целиком"
                                data-nj654-zoom="<?php echo HTML::chars($url); ?>"
                                data-nj654-zoom-title="<?php echo HTML::chars((string) $item['title']); ?>">
                            <img src="<?php echo HTML::chars($url); ?>"
                                 alt="<?php echo HTML::chars((string) $item['title']); ?>" loading="lazy"
                                 class="aspect-square w-full bg-gray-50 object-cover dark:bg-gray-900">
                        </button>
                    <?php else: ?>
                        <div class="flex aspect-square w-full items-center justify-center bg-gray-50 text-gray-300 dark:bg-gray-900">
                            <i class="fas fa-align-left"></i>
                        </div>
                    <?php endif; ?>

                    <p class="truncate px-1.5 py-1 text-xs text-gray-600 dark:text-gray-300"
                       title="<?php echo HTML::chars((string) $item['title']); ?>">
                        <?php echo HTML::chars((string) $item['title']); ?>
                    </p>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php else: ?>
            <!-- ---------- Список: правка подписей подряд ---------- -->
            <div class="n2j-scrollbar overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-xs dark:border-gray-700/80 dark:bg-gray-800">
                <table class="n2j-table">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pr-2 pl-3 sm:pl-4">Элемент</th>
                            <th class="px-2 py-2.5">Статус</th>
                            <th class="px-2 py-2.5">Размер</th>
                            <th class="py-2.5 pr-3 pl-2 text-right sm:pr-4">Добавлен</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60">
                        <?php foreach ($items as $item): ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="py-2.5 pr-2 pl-3 sm:pl-4">
                                <div class="flex items-center gap-2.5">
                                    <?php if ($own($item)): ?>
                                        <input type="checkbox" name="ids[]" value="<?php echo (int) $item['id']; ?>" data-nj654-box
                                               class="h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600">
                                    <?php else: ?>
                                        <span class="inline-block h-4 w-4 shrink-0"></span>
                                    <?php endif; ?>

                                    <?php if ((string) $item['file'] !== ''): ?>
                                        <?php $url = Service_Nj654_Bank::file_url((string) $item['file']); ?>
                                        <button type="button" class="shrink-0 cursor-zoom-in" title="Посмотреть целиком"
                                                data-nj654-zoom="<?php echo HTML::chars($url); ?>"
                                                data-nj654-zoom-title="<?php echo HTML::chars((string) $item['title']); ?>">
                                            <img src="<?php echo HTML::chars($url); ?>"
                                                 alt="" loading="lazy" class="h-9 w-9 rounded-md object-cover">
                                        </button>
                                    <?php else: ?>
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-400 dark:bg-gray-700">
                                            <i class="fas fa-align-left text-xs"></i>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($own($item)): ?>
                                        <input form="nj654-row-<?php echo (int) $item['id']; ?>" name="title" type="text"
                                               maxlength="200" value="<?php echo HTML::chars((string) $item['title']); ?>"
                                               class="min-h-[36px] w-full min-w-[160px] rounded-lg border border-transparent bg-transparent px-2 py-1.5 text-sm text-gray-900 outline-none transition hover:border-gray-300 focus:border-blue-500 focus:bg-white dark:text-white dark:hover:border-gray-600 dark:focus:bg-gray-900">
                                        <input form="nj654-row-<?php echo (int) $item['id']; ?>" name="note" type="text"
                                               maxlength="1000" value="<?php echo HTML::chars((string) $item['note']); ?>"
                                               placeholder="текст"
                                               class="hidden min-h-[36px] w-full min-w-[160px] rounded-lg border border-transparent bg-transparent px-2 py-1.5 text-xs text-gray-600 outline-none transition hover:border-gray-300 focus:border-blue-500 focus:bg-white sm:block dark:text-gray-300 dark:hover:border-gray-600 dark:focus:bg-gray-900">
                                        <button form="nj654-row-<?php echo (int) $item['id']; ?>" type="submit" title="Сохранить"
                                                class="n2j-btn-icon shrink-0">
                                            <i class="fas fa-check text-xs"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="truncate text-sm text-gray-900 dark:text-white"><?php echo HTML::chars((string) $item['title']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-2 py-2.5">
                                <?php if ((string) $item['status'] === 'ready'): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>в поиске
                                    </span>
                                <?php elseif ((string) $item['status'] === 'error'): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-rose-700 dark:text-rose-400"
                                          title="<?php echo HTML::chars(mb_substr((string) $item['error'], 0, 120)); ?>">
                                        <span class="h-2 w-2 rounded-full bg-rose-500"></span>ошибка
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-400">
                                        <i class="fas fa-spinner n2j-spin text-[10px]"></i>считается
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-2 py-2.5 font-mono text-xs text-gray-500 dark:text-gray-400">
                                <?php echo (int) $item['width'] > 0
                                    ? (int) $item['width'].'×'.(int) $item['height']
                                    : '—'; ?>
                            </td>
                            <td class="py-2.5 pr-3 pl-2 text-right text-xs text-gray-500 sm:pr-4 dark:text-gray-400">
                                <?php echo HTML::chars(mb_substr((string) $item['created_at'], 0, 16)); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Нижняя панель: выбрать всё, убрать, постранично -->
            <div class="n2j-card p-0 mt-3 flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" data-nj654-all
                            class="n2j-btn-secondary n2j-btn-sm">
                        <i class="fas fa-check-double text-[11px]"></i><span>Выбрать все</span>
                    </button>
                    <button data-n2j-arm="Точно убрать?" type="submit"
                            class="n2j-btn-danger n2j-btn-sm">
                        <i class="fas fa-trash-can text-[11px]"></i><span>Убрать отмеченные</span>
                    </button>
                    <span data-nj654-count class="text-xs text-gray-400"></span>
                </div>

                <?php if ($pages > 1): ?>
                <div class="inline-flex items-center gap-1">
                    <a href="<?php echo HTML::chars($link(['p' => max(1, $page - 1)])); ?>"
                       class="n2j-page" aria-label="Предыдущая страница">‹</a>
                    <?php
                    $from = max(1, $page - 2);
                    $to   = min($pages, $from + 4);
                    $from = max(1, $to - 4);
                    ?>
                    <?php for ($n = $from; $n <= $to; $n++): ?>
                        <a href="<?php echo HTML::chars($link(['p' => $n])); ?>"
                           class="n2j-page"<?php echo $n === (int) $page ? ' aria-current="page"' : ''; ?>><?php echo $n; ?></a>
                    <?php endfor; ?>
                    <a href="<?php echo HTML::chars($link(['p' => min($pages, $page + 1)])); ?>"
                       class="n2j-page" aria-label="Следующая страница">›</a>
                </div>
                <?php endif; ?>
            </div>
        </form>

        <?php // Формы правки строк живут вне таблицы: вложенные формы запрещены. ?>
        <?php if ( ! $grid): ?>
            <?php foreach ($items as $item): ?>
                <?php if ($own($item)): ?>
                <form method="post" action="/similar/rename" id="nj654-row-<?php echo (int) $item['id']; ?>" class="hidden">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                    <?php $whence(); ?>
                </form>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php echo View::factory('654/zoom'); ?>
</div>

<script>
// Мелочи, без которых сотня картинок неудобна: «выбрать все», счётчик
// отмеченных и удаление в два шага (правило эталона).
(function () {
    var form = document.getElementById('nj654-list');
    if (!form) { return; }

    var boxes = form.querySelectorAll('[data-nj654-box]');
    var count = form.querySelector('[data-nj654-count]');
    var all = form.querySelector('[data-nj654-all]');

    function refresh() {
        var n = 0;
        boxes.forEach(function (b) { if (b.checked) { n++; } });
        if (count) { count.textContent = n ? 'отмечено: ' + n : ''; }
    }

    boxes.forEach(function (b) { b.addEventListener('change', refresh); });

    if (all) {
        all.addEventListener('click', function () {
            var on = Array.prototype.some.call(boxes, function (b) { return !b.checked; });
            boxes.forEach(function (b) { b.checked = on; });
            var label = all.querySelector('span');
            if (label) { label.textContent = on ? 'Снять отметки' : 'Выбрать все'; }
            refresh();
        });
    }

    // Взведённая кнопка должна быть ЧИТАЕМОЙ в обеих темах, поэтому класс
    // подменяется целиком: добавление bg-rose-600 к bg-white раньше давало
    // белый текст на белом фоне (светлая тема).

})();
</script>

<?php if ($waiting > 0): ?>
<script>
// Элементы считаются воркером — ждём и обновляем страницу, когда очередь
// опустеет. Без этого человек видит «считается» и жмёт F5 наугад.
(function () {
    var tries = 0;

    (function poll() {
        if (++tries > 40) { return; }

        fetch('/similar/api/status', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && Number(d.waiting) === 0) { location.reload(); } else { setTimeout(poll, 2000); }
            })
            .catch(function () { setTimeout(poll, 4000); });
    })();
})();
</script>
<?php endif; ?>
