<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Левая панель навигации (sidebar).
 *
 * Постоянные пункты берёт из каталога, независимо от локального set_menu().
 * Контекстная навигация — в шапке или под ней на телефоне, не в панели.
 *
 * Возможности пунктов меню (всё опционально, обратная совместимость полная):
 *   'group' => 'Сервисы'   — секция панели (по умолчанию «Разделы»);
 *   'badge' => '3'         — счётчик справа (в свёрнутом виде — точка);
 *   'children' => [...]    — раскрываются как подзаголовок + подпункты группы.
 *
 * Поведение:
 *   * md+ — постоянная колонка; кнопка «шеврон» сворачивает до иконок
 *     (состояние — в localStorage, ключ prx.sidebar.collapsed);
 *   * <md — выезжающая панель с оверлеем (открывает #mobile-menu-button).
 *     Она ВСЕГДА в полном виде: свёрнутость — режим постоянной колонки, в
 *     шторке подписей лишать нельзя (иначе меню из одних иконок и снять
 *     нечем — «шеврона» там нет). Держит sb-collapsed в @media (min-width:
 *     768px) в input.css и проверка в .tools/prx110_menu_stand.php;
 *   * активный пункт подсвечивается по текущему пути (самый длинный префикс).
 */

$items = class_exists('Service_Prx110_Catalog')
    ? Service_Prx110_Catalog::menu_items()
    : (isset($menu_items) && is_array($menu_items) ? $menu_items : []);

$nav_state = isset($nav_favorites) && is_array($nav_favorites) ? $nav_favorites : [];
$nav_status = in_array(($nav_state['status'] ?? ''), ['guest', 'ready', 'error'], TRUE) ? $nav_state['status'] : 'guest';
$nav_items = isset($nav_state['items']) && is_array($nav_state['items']) ? $nav_state['items'] : [];
$nav_json = json_encode($nav_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';

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
<aside id="sidebar" aria-label="Навигация по сервисам"
       data-nav-status="<?php echo HTML::chars($nav_status); ?>"
       data-nav-user-id="<?php echo (int) ($nav_state['user_id'] ?? 0); ?>"
       data-nav-csrf="<?php echo HTML::chars((string) ($nav_state['csrf'] ?? '')); ?>"
       data-nav-items="<?php echo HTML::chars($nav_json); ?>">
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
                    aria-label="Искать среди всех сервисов" aria-haspopup="dialog" aria-controls="prx-nav-palette-dialog" aria-expanded="false"
                    class="inline-flex h-[30px] shrink-0 items-center justify-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2 text-gray-500 transition hover:border-blue-400 hover:text-blue-600 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400 dark:hover:border-blue-400 dark:hover:text-blue-400">
                <i class="fas fa-grip text-xs" aria-hidden="true"></i><span class="sidebar-label text-xs font-semibold">Все</span>
            </button>
        </div>
    </div>

    <!-- Навигация -->
    <nav id="sidebar-nav" class="flex-1 space-y-4 overflow-y-auto overflow-x-hidden px-3 py-4" aria-label="Сервисы и разделы">
        <div class="sidebar-group" data-nav-group="pinned">
            <div class="sidebar-group-title px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Избранное</div>
            <ul class="space-y-0.5" data-nav-pinned-list>
                <?php foreach ($nav_items as $favorite): ?>
                <li class="group relative">
                    <a href="<?php echo HTML::chars((string) $favorite['href']); ?>"
                       class="sidebar-item relative flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 pr-10 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100<?php echo ! empty($favorite['active']) ? ' is-active' : ''; ?>"
                       title="<?php echo HTML::chars((string) $favorite['title']); ?>"
                       data-search="<?php echo HTML::chars(mb_strtolower((string) $favorite['title'])); ?>"
                       data-active="<?php echo ! empty($favorite['active']) ? '1' : '0'; ?>"<?php echo ! empty($favorite['aria_current']) ? ' aria-current="'.HTML::chars($favorite['aria_current']).'"' : ''; ?>>
                        <i class="<?php echo HTML::chars((string) $favorite['icon']); ?> w-5 shrink-0 text-center text-[15px]"></i>
                        <span class="sidebar-label min-w-0 flex-1 break-words leading-snug"><?php echo HTML::chars((string) $favorite['title']); ?></span>
                    </a>
                    <button type="button" data-nav-pin="<?php echo HTML::chars((string) $favorite['href']); ?>"
                            class="sidebar-label nav-pin is-pinned absolute right-1 top-1/2 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-md hover:bg-gray-200 dark:hover:bg-gray-700"
                            title="Убрать из Избранного" aria-label="Убрать из Избранного: <?php echo HTML::chars((string) $favorite['title']); ?>" aria-pressed="true"><i class="fas fa-star text-xs"></i></button>
                </li>
                <?php endforeach; ?>
                <?php if ( ! $nav_items): ?>
                <li class="sidebar-label px-3 py-2 text-xs text-gray-400" data-nav-pinned-empty><?php echo $nav_status === 'guest'
                    ? 'Войдите, чтобы сохранять Избранное' : ($nav_status === 'error'
                        ? 'Не удалось загрузить Избранное. Обновите страницу.' : 'Добавляйте сервисы звёздочкой в каталоге'); ?></li>
                <?php endif; ?>
            </ul>
            <div id="nav-legacy-import" class="sidebar-label hidden px-3 pt-2 text-xs text-gray-500 dark:text-gray-400">
                <p>В этом браузере есть прежнее Избранное. Перенести доступные сервисы в текущий аккаунт?</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="button" data-nav-import class="rounded-md border border-gray-300 px-2 py-1 font-semibold hover:bg-gray-100 dark:border-gray-600 dark:hover:bg-gray-800">Перенести</button>
                    <button type="button" data-nav-import-dismiss class="rounded-md px-2 py-1 hover:bg-gray-100 dark:hover:bg-gray-800">Не сейчас</button>
                </div>
            </div>
            <div id="nav-favorite-status" class="sidebar-label hidden px-3 pt-2 text-xs text-rose-600 dark:text-rose-400" role="status" aria-live="polite"></div>
        </div>
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
                                    <?php if (($item['href'] ?? '') !== '/services'): ?>
                                    <button type="button" data-nav-pin="<?php echo HTML::chars(isset($item['href']) ? $item['href'] : '#'); ?>"
                                            class="sidebar-label nav-pin hidden shrink-0 rounded-md p-1 text-gray-300 transition hover:bg-gray-200 hover:text-blue-600 group-hover:inline-flex dark:hover:bg-gray-700 dark:hover:text-blue-400"
                                            title="Закрепить в Избранное" aria-label="Закрепить в Избранное"><i class="fas fa-star text-[10px]"></i></button>
                                    <?php endif; ?>
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
