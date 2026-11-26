<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * /services — полный каталог опубликованных сервисов PRX.
 * SSR: всё видимо поисковику и без JS; поле поиска фильтрует на клиенте.
 */
$nav_favorite_hrefs = array_fill_keys(array_column((array) ($nav_favorites['items'] ?? []), 'href'), TRUE);
$nav_favorites_ready = ($nav_favorites['status'] ?? '') === 'ready';
?>

<?php /* каталог — каркас эталона v6: плоский фон, шапка page_header, разделы .prx-card */ ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Сервисы']],
        'title'  => 'Каталог сервисов PRX',
        'lead'   => (int) $total.' сервисов, готовых к работе. Состав зависит от прав доступа.',
        'actions' => [['label' => 'План и архив', 'href' => '/portal-plan', 'icon' => 'fa-box-archive']],
    ]); ?>

    <?php if ( ! $groups): ?>
        <p class="prx-hint mt-6">Нет доступных сервисов.</p>
    <?php else: ?>
    <?php echo View::factory('partials/service_search', [
        'id' => 'catalog-search', 'label' => 'Поиск по каталогу сервисов', 'controls' => 'catalog-groups',
        'empty_message' => 'Ничего не найдено — измените запрос.',
    ]); ?>

    <?php
    $sections = ['Рабочие сервисы' => [], 'Инструменты платформы' => []];
    foreach ($groups as $group)
    {
        $sections[$group['title'] === 'Платформа' ? 'Инструменты платформы' : 'Рабочие сервисы'][] = $group;
    }
    ?>
    <div id="catalog-groups" class="space-y-6 mt-6">
        <?php foreach ($sections as $section_title => $section_groups): ?>
        <?php if ( ! $section_groups) continue; ?>
        <section data-catalog-section>
            <h2 class="prx-h3 mb-3"><?php echo HTML::chars($section_title); ?></h2>
            <div class="columns-1 gap-5 [column-fill:balance] sm:columns-2 xl:columns-3">
                <?php foreach ($section_groups as $g): ?>
                <section class="catalog-group prx-card mb-5 break-inside-avoid"
                         data-search="<?php echo HTML::chars(mb_strtolower($g['title'])); ?>">
                    <a href="/services/<?php echo HTML::chars($g['slug']); ?>" class="group flex items-center gap-2.5">
                        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/80 dark:text-blue-400"><i class="<?php echo HTML::chars($g['icon']); ?>"></i></span>
                        <h3 class="prx-h3 min-w-0 truncate"><?php echo HTML::chars($g['title']); ?></h3>
                        <span class="prx-count ml-auto shrink-0"><?php echo (int) $g['count']; ?></span>
                    </a>
                    <ul class="mt-3.5 space-y-1 text-sm leading-snug text-gray-600 dark:text-gray-300">
                        <?php foreach ($g['items'] as $m): ?>
                        <li class="catalog-item group/item flex items-center gap-1 break-inside-avoid" data-search="<?php echo HTML::chars(mb_strtolower($m['title'].' '.($m['short'] ?? '').' '.($m['keywords'] ?? ''))); ?>">
<?php // Подсветка строки — как в левой панели: фон на всю высоту ряда (кнопка звёздочки h-10),
      // скругление и те же цвета. Всплывающего title нет: описание и так рядом с группой,
      // а для скринридеров — aria-label. ?>
                            <a href="<?php echo HTML::chars($m['href']); ?>"<?php echo empty($m['short']) ? '' : ' aria-label="'.HTML::chars($m['title'].': '.$m['short']).'"'; ?> class="inline-flex min-h-10 min-w-0 flex-1 items-center gap-2 rounded-lg px-1.5 -mx-1.5 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-gray-700/60 dark:hover:text-gray-100">
                                <i class="<?php echo HTML::chars($m['icon']); ?> w-4 shrink-0 text-center text-sm text-gray-400"></i>
                                <span class="truncate"><?php echo HTML::chars($m['title']); ?></span>
                                <?php if ( ! empty($m['badge'])): ?>
                                <span class="shrink-0 text-[10px] font-bold uppercase tracking-wide text-blue-600 dark:text-blue-400"><?php echo HTML::chars((string) $m['badge']); ?></span>
                                <?php endif; ?>
                            </a>
                            <?php // Состояние звезды приходит в первом HTML вместе с аккаунтом. ?>
                            <?php $nav_is_pinned = isset($nav_favorite_hrefs[$m['href']]);
                                  $nav_pin_label = ! $nav_favorites_ready ? 'Избранное недоступно' : ($nav_is_pinned ? 'Убрать из Избранного' : 'В Избранное'); ?>
                            <button type="button"
                                    data-nav-pin-card="<?php echo HTML::chars($m['href']); ?>"
                                    data-nav-pin-title="<?php echo HTML::chars($m['title']); ?>"
                                    data-nav-pin-icon="<?php echo HTML::chars($m['icon']); ?>"
                                    class="prx-btn-icon nav-pin-card h-10 w-10 shrink-0 text-sm <?php echo $nav_is_pinned ? 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300' : 'text-gray-400 dark:text-gray-500'; ?>"
                                    title="<?php echo HTML::chars($nav_pin_label); ?>" aria-label="<?php echo HTML::chars($nav_pin_label.': '.$m['title']); ?>"
                                    aria-pressed="<?php echo $nav_is_pinned ? 'true' : 'false'; ?>"<?php echo $nav_favorites_ready ? '' : ' disabled'; ?>><i class="fas fa-star"></i></button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>


<?php /* Меню «Сервисы»: администратор добавляет пункты левой панели для всех.
       Ссылка — только внутренний путь; новый пункт виден в сайдбаре и палитре. */ ?>
<?php if ( ! empty($can_manage_menu)): ?>
    <section id="menu-items" class="prx-card mt-6" aria-labelledby="prx110-menu-title">
        <h2 id="prx110-menu-title" class="prx-h3">Меню «Сервисы» — пункты для всех сотрудников</h2>
        <p class="prx-hint mt-1">Пункт появится в левой панели в категории «Сервисы» у всех пользователей и в палитре «Все». Ссылка — внутренний путь, например <code class="font-mono">/prx120_jobs</code>.</p>
        <?php if ( ! empty($menu_flash)): ?><p class="prx-flash prx-flash-info mt-2" role="status"><?php echo HTML::chars((string) $menu_flash); ?></p><?php endif; ?>
        <form method="post" action="/services/menu/add" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_14rem_auto] lg:items-end">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
            <div>
                <label class="prx-label" for="m-title">Название</label>
                <input id="m-title" name="title" required maxlength="120" placeholder="Монитор задач" class="prx-input mt-1 w-full">
            </div>
            <div>
                <label class="prx-label" for="m-href">Ссылка (внутренний путь)</label>
                <input id="m-href" name="href" required maxlength="300" placeholder="/prx120_jobs" class="prx-input mt-1 w-full font-mono text-sm">
            </div>
            <div>
                <label class="prx-label" for="m-icon">Иконка (необязательно)</label>
                <input id="m-icon" name="icon" maxlength="64" placeholder="fas fa-list-check" class="prx-input mt-1 w-full font-mono text-sm">
            </div>
            <div class="lg:pb-1">
                <button type="submit" class="prx-btn-secondary w-full lg:w-auto"><i class="fas fa-plus mr-1.5" aria-hidden="true"></i>Добавить пункт</button>
            </div>
        </form>
        <?php if ( ! empty($menu_custom)): ?>
        <ul class="mt-4 space-y-1 text-sm">
            <?php foreach ($menu_custom as $row): ?>
            <li class="flex min-h-10 items-center gap-2 rounded-lg px-1.5 hover:bg-gray-100 dark:hover:bg-gray-700/60">
                <i class="<?php echo HTML::chars((string) $row['icon']); ?> w-5 shrink-0 text-center text-gray-400" aria-hidden="true"></i>
                <span class="min-w-0 truncate font-medium"><?php echo HTML::chars((string) $row['title']); ?></span>
                <code class="hidden shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400 sm:inline"><?php echo HTML::chars((string) $row['href']); ?></code>
                <form method="post" action="/services/menu/delete/<?php echo (int) $row['id']; ?>" class="ml-auto shrink-0">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
                    <button type="submit" data-prx-arm="Убрать пункт «<?php echo HTML::chars((string) $row['title']); ?>» из меню?"
                            class="prx-btn-icon-danger" aria-label="Убрать пункт <?php echo HTML::chars((string) $row['title']); ?> из меню">
                        <i class="fas fa-trash-can text-xs" aria-hidden="true"></i>
                    </button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="prx-hint mt-3">Дополнительных пунктов пока нет — форма выше добавляет их в категорию «Сервисы» левой панели.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>
    <script>
        (function () {
            var input = document.getElementById('catalog-search');
            var empty = document.getElementById('catalog-search-empty');
            var groups = document.getElementById('catalog-groups');
            if (!input || !empty || !groups || !window.PRXSearchLayout) return;
            input.closest('[data-service-search]').hidden = false;

            function apply() {
                var q = input.value.trim().toLowerCase();
                var any = false;
                groups.querySelectorAll('.catalog-group').forEach(function (g) {
                    var gMatch = window.PRXSearchLayout.matches(g.getAttribute('data-search') || '', q);
                    var shown = 0;
                    g.querySelectorAll('.catalog-item').forEach(function (it) {
                        var hay = it.getAttribute('data-search') || '';
                        var show = !q || gMatch || window.PRXSearchLayout.matches(hay, q);
                        it.classList.toggle('hidden', !show);
                        if (show) shown++;
                    });
                    g.classList.toggle('hidden', !(gMatch || shown > 0 || !q));
                    if (!g.classList.contains('hidden')) any = true;
                });
                groups.querySelectorAll('[data-catalog-section]').forEach(function (section) {
                    section.hidden = !section.querySelector('.catalog-group:not(.hidden)');
                });
                empty.hidden = any || !q;
            }

            input.addEventListener('input', apply);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && input.value) {
                    input.value = '';
                    apply();
                    e.preventDefault();
                }
            });
            apply();
        })();
    </script>
    <?php endif; ?>
</div>
