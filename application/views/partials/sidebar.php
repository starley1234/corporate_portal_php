<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Левая панель навигации (sidebar).
 *
 * Постоянные пункты берёт из каталога, независимо от локального set_menu().
 * Контекстная навигация модуля передаётся отдельно через $module_nav.
 *
 * Возможности пунктов меню (всё опционально, обратная совместимость полная):
 *   'group' => 'Сервисы'   — секция панели (по умолчанию «Разделы»);
 *   'badge' => '3'         — счётчик справа (в свёрнутом виде — точка);
 *   'children' => [...]    — раскрываются как подзаголовок + подпункты группы.
 *
 * Поведение:
 *   * md+ — постоянная колонка; кнопка «шеврон» сворачивает до иконок
 *     (состояние — в localStorage, ключ n2j.sidebar.collapsed);
 *   * <md — выезжающая панель с оверлеем (открывает #mobile-menu-button).
 *     Она ВСЕГДА в полном виде: свёрнутость — режим постоянной колонки, в
 *     шторке подписей лишать нельзя (иначе меню из одних иконок и снять
 *     нечем — «шеврона» там нет). Держит sb-collapsed в @media (min-width:
 *     768px) в input.css и проверка в .tools/nj110_menu_stand.php;
 *   * активный пункт подсвечивается по текущему пути (самый длинный префикс).
 */

$items = class_exists('Service_Nj110_Catalog')
    ? Service_Nj110_Catalog::menu_items()
    : (isset($menu_items) && is_array($menu_items) ? $menu_items : []);

$groups = [];
foreach ($items as $item)
{
    // служебные ссылки без названия (например, кнопка-закладка) — не в панели
    if (empty($item['title']) OR trim((string) $item['title']) === '')
        continue;

    // ВАЖНО: скобки обязательны — без них «?:» связывает крепче «AND»
    // и любая группа превращается в булево true (в HTML — «1»)
    $group = (isset($item['group']) AND trim((string) $item['group']) !== '')
        ? (string) $item['group']
        : 'Разделы';

    if ( ! empty($item['children']) && is_array($item['children']))
    {
        // бывший dropdown: заголовок группы + подпункты
        $groups[$group][] = ['header' => (string) $item['title']];
        foreach ($item['children'] as $child)
        {
            if (empty($child['title']))
                continue;
            $groups[$group][] = $child;
        }
    }
    else
    {
        $groups[$group][] = $item;
    }
}

?>
<aside id="sidebar" aria-label="Навигация по сервисам">
    <!-- Бренд + сворачивание -->
    <div class="flex h-16 shrink-0 items-center gap-2 border-b border-gray-200/70 px-4 dark:border-gray-700/60">
        <a href="/" class="sidebar-brand flex min-w-0 items-center gap-2 text-blue-600 dark:text-blue-400" title="На главную">
            <span class="shrink-0 text-xl leading-none"><?php echo isset($site_logo) ? $site_logo : '<i class="fas fa-brain"></i>'; ?></span>
            <span class="sidebar-label truncate text-base font-bold text-gray-900 dark:text-white"><?php echo HTML::chars(isset($site_name) ? $site_name : ''); ?></span>
        </a>
        <button id="sidebar-collapse" type="button" class="hidden md:flex" aria-label="Свернуть/развернуть панель">
            <i class="fas fa-angles-left text-sm transition-transform duration-200"></i>
        </button>
    </div>

    <!-- Поиск разделён по области: поле фильтрует уже видимое меню, «Все» ищет по каталогу. -->
    <div class="shrink-0 px-3 pt-3">
        <div class="flex items-center gap-1.5">
            <div class="sidebar-label relative min-w-0 flex-1">
                <i class="fas fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400" aria-hidden="true"></i>
                <input id="nav-search" type="text" autocomplete="off" spellcheck="false"
                       placeholder="Фильтр меню…" aria-label="Фильтр сервисов в меню; Ctrl/⌘+K или слэш"
                       class="w-full rounded-lg border border-gray-200 bg-gray-50 py-1.5 pl-8 pr-12 text-xs text-gray-700 outline-none transition placeholder:text-gray-400 focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:placeholder:text-gray-500">
                <?php /* design-audit: allow tiny — подсказка клавиш внутри поля фильтра: 12 px не помещается в pr-12 */ ?>
                <kbd data-shortcut="mod-k" class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded-md border border-gray-200 bg-white px-1.5 py-0.5 text-xs font-semibold text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-500">Ctrl+K</kbd>
            </div>
            <button type="button" id="nav-palette-open" title="Искать среди всех сервисов"
                    aria-label="Искать среди всех сервисов" aria-haspopup="dialog" aria-controls="n2j-nav-palette-dialog" aria-expanded="false"
                    class="inline-flex h-[30px] shrink-0 items-center justify-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2 text-gray-500 transition hover:border-blue-400 hover:text-blue-600 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400 dark:hover:border-blue-400 dark:hover:text-blue-400">
                <i class="fas fa-grip text-xs" aria-hidden="true"></i><span class="sidebar-label text-xs font-semibold">Все</span>
            </button>
        </div>
    </div>

    <?php $mn_items = isset($module_nav) && is_array($module_nav) ? $module_nav : []; ?>
    <?php if ( ! empty($mn_items)): // меню модуля: на малых экранах здесь (сверху), на больших — чипы над контентом ?>
    <div class="shrink-0 border-b border-gray-200/70 px-3 py-3 md:hidden dark:border-gray-700/60">
        <ul class="space-y-0.5">
            <?php foreach ($mn_items as $mn): ?>
            <li>
                <a href="<?php echo HTML::chars($mn['href']); ?>"
                   class="flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition <?php echo ! empty($mn['active'])
                       ? 'bg-blue-600/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400'
                       : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800'; ?>">
                    <?php if ( ! empty($mn['icon'])): ?><i class="<?php echo HTML::chars($mn['icon']); ?> w-4 text-center text-xs"></i><?php endif; ?>
                    <span class="truncate"><?php echo HTML::chars($mn['title']); ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Навигация -->
    <nav id="sidebar-nav" class="flex-1 space-y-4 overflow-y-auto overflow-x-hidden px-3 py-4" aria-label="Сервисы и разделы">
        <?php foreach ($groups as $group => $group_items): ?>
            <div class="sidebar-group">
                <div class="sidebar-group-title px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                    <?php echo HTML::chars($group); ?>
                </div>
                <ul class="space-y-0.5">
                    <?php foreach ($group_items as $item): ?>
                        <?php if ( ! empty($item['header'])): ?>
                            <li class="sidebar-label px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500"><?php echo HTML::chars($item['header']); ?></li>
                        <?php else: ?>
                            <li>
                                <a href="<?php echo HTML::chars(isset($item['href']) ? $item['href'] : '#'); ?>"
                                   class="sidebar-item group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100"
                                   title="<?php echo HTML::chars($item['title']); ?>"
                                   data-active="<?php echo ! empty($item['active']) ? '1' : '0'; ?>"
                                   data-search="<?php echo HTML::chars(mb_strtolower($item['title'].' '.(isset($item['keywords']) ? $item['keywords'] : ''))); ?>">
                                    <i class="<?php echo ! empty($item['icon']) ? HTML::chars($item['icon']) : 'fas fa-circle text-[6px]'; ?> w-5 shrink-0 text-center text-[15px]"></i>
                                    <span class="sidebar-label flex-1 truncate"><?php echo HTML::chars($item['title']); ?></span>
                                    <button type="button" data-nav-pin="<?php echo HTML::chars(isset($item['href']) ? $item['href'] : '#'); ?>"
                                            class="sidebar-label nav-pin hidden shrink-0 rounded-md p-1 text-gray-300 transition hover:bg-gray-200 hover:text-blue-600 group-hover:inline-flex dark:hover:bg-gray-700 dark:hover:text-blue-400"
                                            title="Закрепить в Избранное"><i class="fas fa-star text-[10px]"></i></button>
                                    <?php if ( ! empty($item['badge'])): ?>
                                        <span class="sidebar-badge inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-blue-600 px-1.5 text-xs font-bold leading-none text-white dark:bg-blue-500"><?php echo HTML::chars((string) $item['badge']); ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div id="nav-search-empty" class="sidebar-label hidden px-6 pb-4 text-xs text-gray-400 dark:text-gray-500">
        Ничего не найдено. <span class="whitespace-nowrap">Esc — сбросить.</span>
    </div>

</aside>
<div id="sidebar-overlay" class="fixed inset-0 z-40 hidden bg-gray-900/50 backdrop-blur-sm md:hidden"></div>
