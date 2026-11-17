<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php include __DIR__.'/partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 min-h-screen transition-colors duration-200">
    <script>/* состояние панели — ДО первого рендера, иначе «разъезжается» */
        try { if (localStorage.getItem('prx.sidebar.collapsed') === '1') document.body.classList.add('sb-collapsed'); } catch (e) { }
    </script>
    <?php echo View::factory('partials/sidebar')
            ->set('menu_items', isset($menu_items) ? $menu_items : [])
            ->set('module_nav', isset($module_nav) ? $module_nav : []); ?>
    <div id="page-wrapper" class="flex min-h-screen flex-col">
    <?php include __DIR__.'/partials/topbar.php'; ?>

    <?php  // echo View::factory('profiler/stats') ?>

    <div class="flex-grow">
        <?php /* запасной путь для контроллеров: $breadcrumbs/$h1 у шаблона; модули
                 используют partials/page_header — те же классы v6 */ ?>
        <?php $tpl_crumbs = (isset($breadcrumbs) && ! empty($breadcrumbs)) ? $breadcrumbs : []; ?>
        <?php if ($tpl_crumbs || ( ! empty($h1))): ?>
        <div class="prx-ds w-full px-3.5 pt-4 sm:px-6 lg:px-8">
            <?php if ($tpl_crumbs): ?>
            <nav class="prx-crumbs overflow-x-auto whitespace-nowrap" aria-label="Хлебные крошки">
                <?php foreach (array_values($tpl_crumbs) as $key => $crumb): ?>
                    <?php if ($key > 0): ?><span class="prx-crumbs-sep">/</span><?php endif; ?>
                    <?php if ( ! empty($crumb['active'])): ?>
                        <span class="prx-crumbs-current" aria-current="page"><?php echo HTML::chars($crumb['text']); ?></span>
                    <?php else: ?>
                        <a href="<?php echo HTML::chars($crumb['href']); ?>"><?php echo HTML::chars($crumb['text']); ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <?php if ( ! empty($h1)): ?>
            <h1 class="prx-h1<?php echo $tpl_crumbs ? ' mt-2.5' : ''; ?>"><?php echo HTML::chars($h1); ?></h1>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <main class="">
            <?php echo $content; ?>
        </main>

		<?php
			// Проверяем, включен ли модуль
			if (array_key_exists('cookie_consent', Kohana::modules())) {
				echo Controller_CookieConsent::get_popup_html();
			}
		?>

    </div>

    </div><!-- /#page-wrapper -->


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('theme-toggle');
            const themeToggleMobile = document.getElementById('theme-toggle-mobile');
            const html = document.documentElement;

            function updateTheme(isDark) {
                if (isDark) {
                    html.classList.add('dark');
                    localStorage.setItem('theme', 'dark');
                } else {
                    html.classList.remove('dark');
                    localStorage.setItem('theme', 'light');
                }
                if(themeToggle) themeToggle.checked = isDark;
                if(themeToggleMobile) themeToggleMobile.checked = isDark;
            }

            if (html.classList.contains('dark')) {
                if(themeToggle) themeToggle.checked = true;
                if(themeToggleMobile) themeToggleMobile.checked = true;
            }

            themeToggle?.addEventListener('change', (e) => updateTheme(e.target.checked));
            themeToggleMobile?.addEventListener('change', (e) => updateTheme(e.target.checked));

            // Мобильное меню заменено левой панелью (см. скрипт сайдбара ниже)

            const btn = document.getElementById('userDropdownButton');
            const menu = document.getElementById('userDropdownMenu');
            const arrow = document.getElementById('dropdownArrow');

            if (btn && menu) {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isHidden = menu.classList.contains('hidden');
                    if (isHidden) {
                        btn.setAttribute('aria-expanded', 'true');
                        menu.classList.remove('hidden');
                        setTimeout(() => {
                            menu.classList.remove('opacity-0', 'scale-95');
                            arrow?.classList.add('rotate-180');
                        }, 10);
                    } else {
                        closeDropdown();
                    }
                });

                document.addEventListener('click', closeDropdown);
            }

            function closeDropdown() {
                if (!menu || menu.classList.contains('hidden')) return;
                btn?.setAttribute('aria-expanded', 'false');
                menu.classList.add('opacity-0', 'scale-95');
                arrow?.classList.remove('rotate-180');
                setTimeout(() => menu.classList.add('hidden'), 200);
            }

            <?php /* prx597: колокольчик обновляется собственным скриптом (см. шапку) — legacy-бейдж prx217 удалён */ ?>
        });
    </script>
    <script>
        // Левая панель: сворачивание (localStorage), мобильный drawer, активный пункт
        (function () {
            var sidebar = document.getElementById('sidebar');
            if (!sidebar) return;

            var body = document.body;
            var overlay = document.getElementById('sidebar-overlay');
            var collapseBtn = document.getElementById('sidebar-collapse');
            var burger = document.getElementById('mobile-menu-button');
            // класс sb-collapsed ставится ранним boot-скриптом сразу после <body>

            collapseBtn && collapseBtn.addEventListener('click', function () {
                var collapsed = body.classList.toggle('sb-collapsed');
                localStorage.setItem('prx.sidebar.collapsed', collapsed ? '1' : '0');
            });

            function drawer(open) {
                sidebar.classList.toggle('open', open);
                overlay && overlay.classList.toggle('hidden', !open);
                // открытая шторка держит страницу: фон под ней не прокручивается
                body.classList.toggle('sb-drawer-open', open);
                var icon = burger && burger.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-bars', !open);
                    icon.classList.toggle('fa-times', open);
                }
            }

            // Планшет повернули или окно развернули до md — шторка уступает
            // место постоянной колонке, иначе оверлей остался бы поверх страницы.
            window.addEventListener('resize', function () {
                if (window.innerWidth >= 768 && sidebar.classList.contains('open')) drawer(false);
            });

            burger && burger.addEventListener('click', function (e) {
                e.stopPropagation();
                drawer(!sidebar.classList.contains('open'));
            });
            overlay && overlay.addEventListener('click', function () { drawer(false); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') drawer(false);
            });
            var nav = document.getElementById('sidebar-nav');
            // Активная ссылка мобильного раздела может лежать за правым краем.
            // Прокручиваем только сам ряд, не всю страницу.
            var mobileSection = document.getElementById('mobile-section-nav');
            var mobileActive = mobileSection && mobileSection.querySelector('[aria-current]');
            if (mobileActive) window.requestAnimationFrame(function () {
                if (!mobileSection.clientWidth) return; // на md+ ряд скрыт
                var bounds = mobileSection.getBoundingClientRect();
                var activeBounds = mobileActive.getBoundingClientRect();
                if (activeBounds.left < bounds.left || activeBounds.right > bounds.right) {
                    mobileSection.scrollLeft += activeBounds.left - bounds.left - (bounds.width - activeBounds.width) / 2;
                }
            });
            var sidebarScrollKey = 'prx.sidebar.scrollTop';

            sidebar.addEventListener('click', function (e) {
                var link = e.target.closest('a');

                // Позиция меню живёт между переходами в этой вкладке: нижний
                // пункт не заставляет снова прокручивать длинный список сверху.
                if (link && nav && link.closest('#sidebar-nav')) {
                    try { sessionStorage.setItem(sidebarScrollKey, String(nav.scrollTop)); } catch (ignore) { }
                }

                if (link && window.innerWidth < 768) drawer(false);
            });

            var path = location.pathname.replace(/\/+$/, '') || '/';
            var best = null, bestLen = -1;
            if (nav) {
                nav.querySelectorAll('a.sidebar-item').forEach(function (a) {
                    // Сервер знает об алиасах страниц объектов; при прямом
                    // совпадении с href оставляем fallback для старых пунктов.
                    if (a.getAttribute('data-active') === '1') {
                        best = a;
                        bestLen = Number.MAX_SAFE_INTEGER;
                        return;
                    }

                    var href = a.getAttribute('href') || '';
                    if (!href || href === '#') return;
                    var h = href.replace(/\/+$/, '') || '/';
                    if (h === path || (h !== '/' && path.indexOf(h + '/') === 0)) {
                        if (h.length > bestLen) { bestLen = h.length; best = a; }
                    }
                });
            }
            if (best) {
                best.classList.add('is-active');
                var bestPath = (best.getAttribute('href') || '').replace(/\/+$/, '') || '/';
                best.setAttribute('aria-current', bestPath === path ? 'page' : 'location');
            }

            if (nav) {
                var savedScroll = null;
                try {
                    savedScroll = sessionStorage.getItem(sidebarScrollKey);
                    if (savedScroll !== null) sessionStorage.removeItem(sidebarScrollKey);
                } catch (ignore) { }

                window.requestAnimationFrame(function () {
                    if (savedScroll !== null && isFinite(Number(savedScroll))) {
                        nav.scrollTop = Math.max(0, Number(savedScroll));
                    } else if (best) {
                        var navRect = nav.getBoundingClientRect();
                        var itemRect = best.getBoundingClientRect();
                        if (itemRect.top < navRect.top || itemRect.bottom > navRect.bottom) {
                            best.scrollIntoView({ block: 'nearest' });
                        }
                    }
                });
            }

            // Поиск по сервисам в левой панели (Ctrl+K / Cmd+K / «/»): поле
            // фильтрует ТЕКУЩЕЕ меню по data-search, как было изначально.
            // Палитра всех сервисов открывается кнопкой рядом с полем —
            // см. navpalette.js (там же персонализация меню).
            // Подсказка сочетания клавиш соответствует платформе пользователя.
            var platformName = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
            var modifierShortcut = /mac|iphone|ipad|ipod/i.test(platformName) ? '⌘K' : 'Ctrl+K';
            document.querySelectorAll('[data-shortcut="mod-k"]').forEach(function (hint) {
                hint.textContent = modifierShortcut;
            });

            var search = document.getElementById('nav-search');
            var empty = document.getElementById('nav-search-empty');

            if (search) {
                var applyFilter = function () {
                    var q = search.value.trim().toLowerCase();
                    var anyVisible = false;

                    sidebar.querySelectorAll('.sidebar-group').forEach(function (groupEl) {
                        var groupVisible = false;

                        groupEl.querySelectorAll('a.sidebar-item').forEach(function (a) {
                            var hay = a.getAttribute('data-search') || '';
                            var show = !q || hay.indexOf(q) !== -1;
                            a.parentElement.classList.toggle('hidden', !show);
                            if (show) groupVisible = true;
                        });

                        groupEl.classList.toggle('hidden', !groupVisible);
                        if (groupVisible) anyVisible = true;
                    });

                    if (empty) empty.classList.toggle('hidden', anyVisible || !q);
                };

                search.addEventListener('input', applyFilter);
                search.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        search.value = '';
                        applyFilter();
                        search.blur();
                    }
                });

                document.addEventListener('keydown', function (e) {
                    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement && document.activeElement.tagName || '');

                    if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) {
                        e.preventDefault();

                        // Поиск остаётся доступен, даже если панель свернута
                        // или на телефоне закрыта за кнопкой-гамбургером.
                        // Свёрнутость снимаем только на ПК: в шторке (<md) она
                        // и так не действует, а выбор пользователя должен
                        // дождаться его возвращения к широкому окну.
                        if (window.innerWidth < 768) {
                            drawer(true);
                        } else if (body.classList.contains('sb-collapsed')) {
                            body.classList.remove('sb-collapsed');
                            try { localStorage.setItem('prx.sidebar.collapsed', '0'); } catch (ignore) { }
                        }

                        search.focus();
                        search.select();
                    } else if (e.key === '/' && !typing) {
                        e.preventDefault();
                        search.focus();
                    }
                });
            }
        })();
    </script>


</body>
</html>
