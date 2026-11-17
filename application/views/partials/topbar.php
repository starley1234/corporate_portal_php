<?php defined('SYSPATH') or die('No direct script access.'); ?>
    <header class="sticky top-0 z-40 w-full bg-white/90 dark:bg-gray-800/90 backdrop-blur-md border-b border-gray-200 dark:border-gray-700">
        <div class="container-fluid mx-auto px-4 h-16 flex items-center justify-between">
            <?php // левая группа тянется (flex-1 + min-w-0): чипы раздела скроллятся внутри себя, а не распирают шапку ?>
            <div class="flex min-w-0 flex-1 items-center">
                <button id="mobile-menu-button" class="md:hidden mr-3 p-2 rounded-md text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <a href="/" class="prx-brand-link text-xl font-bold flex items-center text-blue-600 dark:text-blue-400 shrink-0 mr-2 md:hidden">

					<?php echo isset($site_logo) ? $site_logo : '<i class="fas fa-brain mr-2"></i>'; ?>

                    <?php // имя сайта на телефонах (до 380px) уезжало за край — шапка на этой
                   		// ширине умещает только иконку; «xs:» в конфига Tailwind нет, класс не работал ?>
                    <span class="hidden min-[380px]:inline text-gray-900 dark:text-white"><?php echo HTML::chars(isset($site_name) ? $site_name : ''); ?></span>
                </a>

                <!-- Чипы текущего раздела (prx110_catalog) и меню модуля -->
                <?php $prx_header = class_exists('Service_Prx110_Catalog') ? Service_Prx110_Catalog::header_nav() : NULL; ?>
                <?php
                // href'ы чипов раздела: меню модуля их НЕ повторяет. Иначе на /trends
                // рядом стоят два одинаковых чипа. Фильтруем только показ в шапке;
                // ссылки модулей также доступны через каталог и «Избранное».
                $prx_hrefs = [];

                if ($prx_header !== NULL)
                {
                    foreach ($prx_header['items'] as $prx_it)
                    {
                        $prx_hrefs[rtrim((string) $prx_it['href'], '/')] = TRUE;
                    }
                }
                ?>
                <?php if ($prx_header !== NULL): ?>
                    <div class="hidden md:flex items-center gap-1.5 min-w-0 overflow-x-auto">
                        <span class="mr-1 inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                            <i class="<?php echo HTML::chars($prx_header['icon']); ?>"></i><?php echo HTML::chars($prx_header['group']); ?>
                        </span>
                        <?php foreach ($prx_header['items'] as $prx_item): ?>
                            <a href="<?php echo HTML::chars($prx_item['href']); ?>"
                               class="prx-header-chip shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition <?php echo ! empty($prx_item['active'])
                                   ? 'is-active'
                                   : 'bg-gray-100 text-gray-600 hover:bg-gray-200 hover:text-gray-900 dark:bg-gray-700/60 dark:text-gray-300 dark:hover:bg-gray-700'; ?>"<?php echo ! empty($prx_item['active']) ? ' aria-current="page"' : ''; ?>>
                                <i class="<?php echo HTML::chars($prx_item['icon']); ?> mr-1 text-xs"></i><?php echo HTML::chars($prx_item['title']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php // Меню модуля (md+): рядом с чипами раздела ?>
                <?php
                // меню модуля без повторов чипов раздела (см. $prx_hrefs выше)
                $mn_chips = [];

                foreach ((array) (isset($module_nav) ? $module_nav : []) as $mn_item)
                {
                    if (isset($prx_hrefs[rtrim((string) $mn_item['href'], '/')]))
                    {
                        continue;
                    }

                    $mn_chips[] = $mn_item;
                }
                ?>
                <?php if ( ! empty($mn_chips)): ?>
                    <div class="hidden md:flex items-center gap-1.5 min-w-0 overflow-x-auto <?php echo $prx_header !== NULL ? 'ml-2 pl-2 border-l border-gray-200 dark:border-gray-700' : ''; ?>">
                        <?php foreach ($mn_chips as $mn_item): ?>
                            <a href="<?php echo HTML::chars($mn_item['href']); ?>"
                               class="prx-header-chip shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition <?php echo ! empty($mn_item['active'])
                                   ? 'is-active'
                                   : 'bg-gray-100 text-gray-600 hover:bg-gray-200 hover:text-gray-900 dark:bg-gray-700/60 dark:text-gray-300 dark:hover:bg-gray-700'; ?>"<?php echo ! empty($mn_item['active']) ? ' aria-current="page"' : ''; ?>>
                                <?php if ( ! empty($mn_item['icon'])): ?><i class="<?php echo HTML::chars($mn_item['icon']); ?> mr-1 text-xs"></i><?php endif; ?><?php echo HTML::chars($mn_item['title']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="flex min-w-0 items-center space-x-1.5 sm:space-x-4">

                <?php // Глобальный поиск (prx100): по моим материалам, базе знаний, организациям
                // и вакансиям. На телефоне окно ПРИВЯЗАНО К ШАПКЕ (max-sm:fixed), а не к кнопке:
                // кнопка стоит в середине строки, и 22rem вправо от неё не влезали — панель
                // уезжала за левый край. Шапка — sticky + backdrop-blur, поэтому fixed считается
                // от неё: то же самое и при прокрутке страницы.
                // 16px у поля — чтобы iOS не приближала страницу на фокусе (text-sm = 14px). ?>
                <div class="relative" id="gsearch">
                    <button type="button" id="gsearch-btn" class="p-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors" aria-label="Поиск по содержимому сайта" title="Поиск по содержимому сайта: досье, компании, база знаний, вакансии">
                        <i class="fas fa-magnifying-glass text-xl"></i>
                    </button>
                    <div id="gsearch-drop" class="hidden absolute right-0 z-50 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg max-sm:fixed max-sm:inset-x-3 max-sm:top-[4.25rem] max-sm:mt-0 max-sm:w-auto max-sm:max-w-none dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-gray-100 p-2 dark:border-gray-700/60">
                            <input type="search" id="gsearch-q" aria-label="Поиск по содержимому сайта, минимум два символа" placeholder="Поиск по содержимому… (от 2 символов)" autocomplete="off"
                                   class="min-h-[40px] w-full rounded-lg border border-gray-300 bg-white px-3 text-[16px] text-gray-900 outline-none focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 sm:text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        </div>
                        <div id="gsearch-results" class="max-h-[60vh] overflow-y-auto sm:max-h-96">
                            <p class="px-4 py-6 text-center text-xs text-gray-400">Ищет досье, компании, 3D-модели, базу знаний, организации и вакансии.</p>
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    'use strict';
                    var wrap = document.getElementById('gsearch');
                    if (!wrap) return;
                    var btn = document.getElementById('gsearch-btn'),
                        drop = document.getElementById('gsearch-drop'),
                        input = document.getElementById('gsearch-q'),
                        box = document.getElementById('gsearch-results'),
                        timer = null, lastQ = '', items = [], active = -1;

                    function esc(s) {
                        var d = document.createElement('div');
                        d.textContent = s == null ? '' : String(s);
                        return d.innerHTML;
                    }

                    function close() {
                        drop.classList.add('hidden');
                    }

                    function open() {
                        drop.classList.remove('hidden');
                        input.focus();
                    }

                    function setActive(n) {
                        if (!items.length) return;
                        active = (active + n + items.length) % items.length;
                        items.forEach(function (el, i) {
                            el.classList.toggle('bg-blue-50', i === active);
                            el.classList.toggle('dark:bg-blue-950/40', i === active);
                        });
                        if (items[active]) items[active].scrollIntoView({block: 'nearest'});
                    }

                    function render(data) {
                        if (!data || data.error) {
                            box.innerHTML = '<p class="px-4 py-6 text-center text-xs text-rose-500">' + esc((data && data.error) || 'Поиск не удался') + '</p>';
                            return;
                        }
                        if (!data.results || !data.results.length) {
                            box.innerHTML = '<p class="px-4 py-6 text-center text-xs text-gray-400">Ничего не найдено по «' + esc(lastQ) + '»</p>';
                            return;
                        }
                        var html = '';
                        data.results.forEach(function (r, i) {
                            html += '<a href="' + esc(r.url) + '" data-gsi="' + i + '" class="block px-4 py-2.5 transition hover:bg-gray-50 dark:hover:bg-gray-700/40">'
                                + '<div class="flex items-center gap-2">'
                                + '<span class="prx-count shrink-0">' + esc(r.kind) + '</span>'
                                + '<span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-white">' + esc(r.title) + '</span>'
                                + '</div>'
                                + (r.sub ? '<p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">' + esc(r.sub) + '</p>' : '')
                                + '</a>';
                        });
                        html += '<p class="border-t border-gray-100 px-4 py-2 text-center text-xs text-gray-400 dark:border-gray-700/60">' + data.total + ' результатов · ' + data.took_ms + ' мс · Enter — перейти</p>';
                        box.innerHTML = html;
                        items = Array.prototype.slice.call(box.querySelectorAll('[data-gsi]'));
                        active = -1;
                    }

                    function run() {
                        var q = input.value.trim();
                        if (q.length < 2) {
                            box.innerHTML = '<p class="px-4 py-6 text-center text-xs text-gray-400">Минимум 2 символа…</p>';
                            items = [];
                            return;
                        }
                        if (q === lastQ) return;
                        lastQ = q;
                        box.innerHTML = '<p class="px-4 py-6 text-center text-xs text-gray-400"><i class="fas fa-circle-notch fa-spin mr-1"></i>Ищу…</p>';
                        fetch('/search/api?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
                            .then(function (r) { return r.json(); })
                            .then(function (data) { if (input.value.trim() === lastQ) render(data); })
                            .catch(function () {
                                box.innerHTML = '<p class="px-4 py-6 text-center text-xs text-rose-500">Сеть недоступна — попробуйте ещё раз</p>';
                            });
                    }

                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        if (drop.classList.contains('hidden')) { open(); } else { close(); }
                    });

                    input.addEventListener('input', function () {
                        if (timer) clearTimeout(timer);
                        timer = setTimeout(run, 300);
                    });

                    input.addEventListener('keydown', function (e) {
                        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(1); }
                        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(-1); }
                        else if (e.key === 'Enter' && items[active]) { e.preventDefault(); location.href = items[active].getAttribute('href'); }
                        else if (e.key === 'Escape') { close(); }
                    });

                    wrap.addEventListener('click', function (e) { e.stopPropagation(); });
                    document.addEventListener('click', function () { close(); });
                    // Ctrl+K здесь больше НЕ перехватываем: он принадлежит поиску
                    // по сервисам в левой панели (там же поле и подсказка). Этот
                    // блок — поиск по содержимому (досье, компании, база знаний),
                    // открывается кнопкой-лупой.
                })();
                </script>

				<?php
					// Проверяем, включен ли модуль
					if (array_key_exists('prx118_links', Kohana::modules())) {
						// Выполняем внутренний под-запрос к контроллеру виджета
						echo Request::factory('prx118_widget/bookmark_button')->execute();
					}
				?>

                <?php if (Auth::instance()->logged_in()): ?>
                <div class="relative" id="prx597-wrap">
                    <button type="button" id="prx597-bell" class="relative p-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors" aria-label="Уведомления">
                        <i class="fas fa-bell text-xl"></i>
                        <span id="prx597-badge" class="absolute top-0.5 right-0.5 inline-flex min-w-[1.1rem] items-center justify-center px-1 py-0.5 text-xs font-bold leading-none text-white bg-rose-600 rounded-full" style="display: none;">0</span>
                    </button>
                    <div id="prx597-drop" class="hidden absolute right-0 z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg max-sm:fixed max-sm:inset-x-3 max-sm:top-[4.25rem] max-sm:mt-0 max-sm:w-auto max-sm:max-w-none dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-gray-700/60">
                            <span class="text-sm font-bold text-gray-900 dark:text-white">Уведомления</span>
                            <button type="button" id="prx597-readall" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Прочитано всё</button>
                        </div>
                        <div id="prx597-list" class="max-h-80 overflow-y-auto">
                            <p class="px-4 py-6 text-center text-xs text-gray-400">Загружаю…</p>
                        </div>
                        <a href="/prx597" class="block border-t border-gray-100 px-4 py-2.5 text-center text-xs font-semibold text-blue-600 transition hover:bg-gray-50 dark:border-gray-700/60 dark:text-blue-400 dark:hover:bg-gray-700/40">Все уведомления и настройки</a>
                    </div>
                </div>
                <script>
                (function () {
                    'use strict';
                    var CSRF = '<?php echo Security::token(); ?>';
                    var wrap = document.getElementById('prx597-wrap');
                    if (!wrap) return;
                    var bell = document.getElementById('prx597-bell'),
                        drop = document.getElementById('prx597-drop'),
                        list = document.getElementById('prx597-list'),
                        badge = document.getElementById('prx597-badge');
                    var esc = function (s) {
                        var d = document.createElement('div');
                        d.textContent = String(s == null ? '' : s);
                        return d.innerHTML;
                    };
                    function setBadge(n) {
                        if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.style.display = ''; }
                        else { badge.style.display = 'none'; }
                    }
                    function icon(kind) {
                        if (kind === 'job_done') return ['fa-circle-check', 'text-emerald-500'];
                        if (kind === 'job_failed') return ['fa-triangle-exclamation', 'text-rose-500'];
                        return ['fa-circle-info', 'text-blue-500'];
                    }
                    function render(items) {
                        if (!items.length) {
                            list.innerHTML = '<p class="px-4 py-6 text-center text-xs text-gray-400">Пока пусто — здесь появятся готовые задачи и ошибки.</p>';
                            return;
                        }
                        var html = '';
                        items.forEach(function (n) {
                            var ic = icon(n.kind);
                            html += '<a href="' + esc(n.href || '/prx597') + '" data-id="' + n.id + '"'
                                + ' class="prx597-item block border-b border-gray-50 px-4 py-2.5 transition-colors hover:bg-gray-50 dark:border-gray-700/40 dark:hover:bg-gray-700/40'
                                + (n.unread ? ' bg-blue-50/50 dark:bg-blue-950/20' : '') + '">'
                                + '<p class="flex items-start gap-2 text-sm font-semibold text-gray-900 dark:text-white">'
                                + '<i class="fas ' + ic[0] + ' ' + ic[1] + ' mt-0.5 w-4 shrink-0 text-center text-xs"></i>'
                                + '<span class="min-w-0 flex-1">' + esc(n.title)
                                + (n.unread ? ' <span class="ml-1 inline-block h-2 w-2 rounded-full bg-blue-600 align-middle"></span>' : '')
                                + '</span><span class="shrink-0 font-normal text-xs text-gray-400">' + esc(n.ago) + '</span></p>'
                                + (n.body ? '<p class="mt-0.5 pl-6 truncate text-xs text-gray-500 dark:text-gray-400">' + esc(n.body) + '</p>' : '')
                                + '</a>';
                        });
                        list.innerHTML = html;
                    }
                    function refresh() {
                        // visible=1 — «человек смотрит на вкладку»: сервер пишет
                        // присутствие (prx_597_presence). Свёрнутая вкладка молчит,
                        // иначе уведомление о своём же событии пропадало бы зря.
                        var vis = (document.visibilityState === 'visible') ? '1' : '0';
                        fetch('/prx597/api/unread?visible=' + vis + '&bust=' + Date.now(), {headers: {'Accept': 'application/json'}})
                            .then(function (r) {
                                if (r.status === 401) { location.href = '/auth/logout'; return null; }
                                return r.ok ? r.json() : null;
                            })
                            .then(function (j) {
                                if (!j) return;
                                setBadge(j.unread);
                                render(j.items || []);
                            })
                            .catch(function () {});
                    }
                    function postRead(payload, btn) {
                        var body = new URLSearchParams();
                        body.set('csrf', CSRF);
                        Object.keys(payload).forEach(function (k) { body.set(k, payload[k]); });
                        if (btn) { btn.disabled = true; btn.classList.add('opacity-60'); }
                        fetch('/prx597/api/read', {method: 'POST', body: body})
                            .then(function (r) { return r.ok ? r.json() : null; })
                            .then(function (j) { if (j) setBadge(j.unread); })
                            .catch(function () {})
                            .then(function () { if (btn) { btn.disabled = false; btn.classList.remove('opacity-60'); } });
                    }
                    bell.addEventListener('click', function () {
                        drop.classList.toggle('hidden');
                        if (!drop.classList.contains('hidden')) refresh();
                    });
                    document.addEventListener('click', function (e) {
                        if (!wrap.contains(e.target)) drop.classList.add('hidden');
                    });
                    list.addEventListener('click', function (e) {
                        var a = e.target.closest('a.prx597-item');
                        if (!a) return;
                        e.preventDefault();
                        var href = a.getAttribute('href');
                        postRead({id: JSON.stringify([a.getAttribute('data-id')])}, null);
                        if (href && href !== '#') location.href = href;
                    });
                    document.getElementById('prx597-readall').addEventListener('click', function () {
                        postRead({all: 1}, this);
                        list.querySelectorAll('.bg-blue-50\\/50, .bg-blue-950\\/20').forEach(function (el) {
                            el.classList.remove('bg-blue-50/50', 'bg-blue-950/20');
                        });
                        badge.style.display = 'none';
                    });
                    refresh();
                    setInterval(refresh, 45000);
                    // Вернулся на вкладку — сразу и отметился, и посмотрел ленту.
                    document.addEventListener('visibilitychange', function () {
                        if (document.visibilityState === 'visible') refresh();
                    });
                })();
                </script>
                <?php endif; ?>


                <div class="shrink-0">
                    <label class="theme-toggle">
                        <input type="checkbox" id="theme-toggle">
                        <span class="slider">
                            <i class="fas fa-sun sun-icon"></i>
                            <i class="fas fa-moon moon-icon"></i>
                        </span>
                    </label>
                </div>

                <?php if (isset($current_user_roles) && in_array('login', $current_user_roles)): ?>
                    <div class="relative">
                        <button type="button" id="userDropdownButton" aria-label="Меню аккаунта" aria-controls="userDropdownMenu" aria-expanded="false" class="flex items-center space-x-2 sm:space-x-3 focus:outline-none group p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                            <div class="prx-account-avatar w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-blue-600 flex items-center justify-center text-white shadow-sm text-sm font-bold">
                                <?php echo HTML::chars(strtoupper(substr($current_user->username, 0, 1))); ?>
                            </div>
                            <div class="hidden lg:block text-left">
                                <p class="text-sm font-semibold leading-none"><?php echo HTML::chars($current_user->username); ?></p>
                            </div>
                            <i class="fas fa-chevron-down text-xs text-gray-400 transition-transform group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300" id="dropdownArrow"></i>
                        </button>

                        <div id="userDropdownMenu" class="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden opacity-0 transition-all duration-200 transform scale-95 origin-top-right z-50">
                            <div class="p-3 border-b border-gray-100 dark:border-gray-700 lg:hidden">
                                <p class="text-sm font-bold text-gray-900 dark:text-white truncate"><?php echo HTML::chars($current_user->username); ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?php echo HTML::chars($current_user->email); ?></p>
                            </div>
                            <div class="p-2 space-y-1">
                                <div class="sm:hidden flex items-center justify-between px-3 py-2 text-sm text-gray-600 dark:text-gray-300">
                                    <span>Тема</span>
                                    <label class="theme-toggle scale-75">
                                        <input type="checkbox" id="theme-toggle-mobile">
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <a href="/auth/profile" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                    <i class="fas fa-user-circle mr-3 text-gray-400"></i> Профиль
                                </a>
                                <a href="/dashboard" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                    <i class="fas fa-gauge-high mr-3 text-gray-400"></i> Рабочий стол
                                </a>
                                <?php if (in_array('admin', $current_user_roles)): ?>
                                    <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
                                    <a href="/admin" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 text-amber-600 dark:text-amber-400 transition-colors">
                                        <i class="fas fa-screwdriver-wrench mr-3"></i> Панель управления
                                    </a>
                                    <a href="/prx595/admin" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 text-amber-600 dark:text-amber-400 transition-colors">
                                        <i class="fas fa-users-gear mr-3"></i> Пользователи и роли
                                    </a>
                                    <a href="/prx595/admin/activity" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 text-amber-600 dark:text-amber-400 transition-colors">
                                        <i class="fas fa-chart-line mr-3"></i> Активность системы
                                    </a>
                                    <a href="/prx120/monitor" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 text-amber-600 dark:text-amber-400 transition-colors">
                                        <i class="fas fa-heart-pulse mr-3"></i> Воркеры · монитор
                                    </a>
                                	<a href="/migrate_pg" class="flex items-center px-3 py-2.5 text-sm rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 text-amber-600 dark:text-amber-400 transition-colors">
                                        <i class="fas fa-database mr-3"></i> Миграции PG
                                    </a>
                                <?php endif; ?>
                                <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
                                <a href="/auth/logout" class="flex items-center px-3 py-2.5 text-sm rounded-xl text-rose-600 transition-colors hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-900/20">
                                    <i class="fas fa-sign-out-alt mr-3"></i> Выйти
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="/auth/login" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">Вход</a>
                <?php endif; ?>
            </div>
        </div>

    </header>
    <?php
    // На телефоне постоянная панель остаётся короткой, но ссылки текущего
    // раздела не должны исчезать вместе с desktop-чипами. Используем те же
    // пункты: каталог фильтрует аудиторию/статус, module_nav — роли/ACL.
    $mobile_nav = array_merge($prx_header !== NULL ? $prx_header['items'] : [], $mn_chips);
    ?>
    <?php if ($mobile_nav): ?>
    <nav id="mobile-section-nav" aria-label="Навигация текущего раздела"
         class="flex items-center gap-1.5 overflow-x-auto border-b border-gray-200 bg-white px-3 py-2 md:hidden dark:border-gray-700 dark:bg-gray-800">
        <?php foreach ($mobile_nav as $mobile_item): ?>
            <a href="<?php echo HTML::chars($mobile_item['href']); ?>"
               class="prx-header-chip inline-flex min-h-10 shrink-0 items-center rounded-full px-3 py-2 text-xs font-semibold transition <?php echo ! empty($mobile_item['active'])
                   ? 'is-active'
                   : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700/60 dark:text-gray-300 dark:hover:bg-gray-700'; ?>"<?php echo ! empty($mobile_item['active']) ? ' aria-current="page"' : ''; ?>>
                <?php if ( ! empty($mobile_item['icon'])): ?><i class="<?php echo HTML::chars($mobile_item['icon']); ?> mr-1 text-xs"></i><?php endif; ?><?php echo HTML::chars($mobile_item['title']); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
