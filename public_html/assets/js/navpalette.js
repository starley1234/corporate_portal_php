/*
 * navpalette.js — палитра всех сервисов (кнопка «Все» в сайдбаре) и персонализация меню.
 *
 * Источник данных — GET /prx110/api/catalog (тот же реестр Service_Prx110_Catalog,
 * что собирает сайдбар). Палитра ищет по ВСЕМ видимым сервисам (включая
 * auth/hidden, доступные текущему пользователю), а не только по пунктам меню.
 *
 * Персонализация меню — в localStorage (без серверной стороны до фазы 4):
 *   prx.nav.v1 = { pinned: [href...], hidden: [href...], recents: [href...] }
 *   recents пополняется при переходе по пункту меню (макс. 12).
 *
 * Звёздочка «в Избранное» есть в палитре и на карточках каталога /services,
 * а в меню её можно снять. Все они пишут в один ключ, поэтому закрепление
 * сразу поднимает сервис в группу «Избранное» без перезагрузки страницы.
 */
(function (window, document) {
    'use strict';

    var STORE = 'prx.nav.v1';
    var MAX_RECENTS = 12;
    // Каталог зависит от текущей сессии и ролей. Не храним его в localStorage:
    // после входа/выхода старый кэш скрывал доступные сервисы до 10 минут.
    var catalogData = null;
    var catalogRequest = null;

    function readStore() {
        var store = {};
        try {
            var raw = localStorage.getItem(STORE);
            if (raw) store = JSON.parse(raw) || {};
        } catch (e) { /* ignore */ }
        return {
            pinned: Array.isArray(store.pinned) ? store.pinned : [],
            hidden: Array.isArray(store.hidden) ? store.hidden : [],
            recents: Array.isArray(store.recents) ? store.recents : []
        };
    }

    function writeStore(store) {
        try { localStorage.setItem(STORE, JSON.stringify(store)); } catch (e) { /* ignore */ }
    }

    function togglePin(href) {
        var store = readStore();
        var i = store.pinned.indexOf(href);
        if (i === -1) store.pinned.push(href); else store.pinned.splice(i, 1);
        writeStore(store);
    }

    function pushRecent(href) {
        var store = readStore();
        store.recents = [href].concat(store.recents.filter(function (h) { return h !== href; })).slice(0, MAX_RECENTS);
        writeStore(store);
    }

    function loadCatalog(cb) {
        if (catalogData) { cb(catalogData); return; }

        // Один запрос на страницу, даже если одновременно открыли палитру и
        // восстанавливаем избранное. При сетевой ошибке следующий вызов повторит его.
        if (!catalogRequest) {
            catalogRequest = fetch('/prx110/api/catalog', {
                headers: { 'Accept': 'application/json' }, cache: 'no-store'
            }).then(function (r) {
                if (!r.ok) throw new Error('Каталог недоступен');
                return r.json();
            }).then(function (data) {
                if (data && Array.isArray(data.items)) catalogData = data;
                return catalogData;
            }).catch(function () { return null; }).then(function (data) {
                catalogRequest = null;
                return data;
            });
        }
        catalogRequest.then(cb);
    }

    /** Тупой fuzzy-скор: подпоследовательность + токены. */
    function score(hay, q) {
        if (!q) return 0;
        if (hay.indexOf(q) !== -1) return 1000 + (1000 - hay.length);
        var tokens = q.split(/\s+/).filter(Boolean);
        var total = 0;
        for (var i = 0; i < tokens.length; i++) {
            if (hay.indexOf(tokens[i]) !== -1) total += 500;
        }
        return total;
    }

    /* ---------------------------------------------------------------------
     * Персонализация меню: recents на кликах + pin/hide в localStorage
     * ------------------------------------------------------------------- */
    /** Метаданные сервисов со страниц каталога (для «Избранного» без пункта в меню). */
    function cardMeta() {
        var meta = {};

        document.querySelectorAll('.nav-pin-card').forEach(function (btn) {
            var href = btn.getAttribute('data-nav-pin-card') || '';
            if (!href || href === '#') return;
            meta[href] = {
                title: btn.getAttribute('data-nav-pin-title') || href,
                icon: btn.getAttribute('data-nav-pin-icon') || 'fas fa-circle'
            };
        });

        return meta;
    }

    /** Звёздочки видны и на сенсорном экране; закреплённые выделены цветом. */
    function syncCards() {
        var store = readStore();

        document.querySelectorAll('.nav-pin-card').forEach(function (btn) {
            var on = store.pinned.indexOf(btn.getAttribute('data-nav-pin-card') || '') !== -1;
            var title = on ? 'Убрать из Избранного' : 'В Избранное';
            var service = btn.getAttribute('data-nav-pin-title') || '';
            btn.classList.toggle('text-amber-500', on);
            btn.classList.toggle('text-gray-400', !on);
            btn.setAttribute('title', title);
            btn.setAttribute('aria-label', title + (service ? ': ' + service : ''));
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    var _toastTimer = null;

    /** Короткое подтверждение: «Избранное» в левом меню не всегда на виду. */
    function toast(text) {
        var el = document.getElementById('prx-nav-toast');

        if (!el) {
            el = document.createElement('div');
            el.id = 'prx-nav-toast';
            el.className = 'pointer-events-none fixed bottom-6 left-1/2 z-[60] -translate-x-1/2 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-semibold text-white opacity-0 shadow-lg transition-opacity dark:bg-gray-700';
            document.body.appendChild(el);
        }

        el.textContent = text;
        el.classList.remove('opacity-0');
        clearTimeout(_toastTimer);
        _toastTimer = setTimeout(function () { el.classList.add('opacity-0'); }, 2200);
    }

    var _menuWired = false;
    var catalogMeta = {};
    var catalogUnavailable = false;

    function personaliseMenu() {
        var sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        if (!_menuWired) {
            _menuWired = true;

            // Кнопки закрепить (⋆) у пунктов меню
            sidebar.addEventListener('click', function (e) {
                var a = e.target.closest('a.sidebar-item');
                if (a) {
                    var href = a.getAttribute('href') || '';
                    if (href && href !== '#') pushRecent(href);
                }

                var btn = e.target.closest('.nav-pin');
                if (!btn) return;
                e.preventDefault();
                e.stopPropagation();
                var phref = btn.getAttribute('data-nav-pin') || '';
                if (!phref || phref === '#') return;
                togglePin(phref);
                applyMenuState();
            });

            // Звёздочки карточек каталога (/services, /services/<slug>): тот же
            // ключ, что у меню — закреплённое сразу появляется в «Избранном».
            document.addEventListener('click', function (e) {
                var card = e.target.closest('.nav-pin-card');
                if (!card) return;

                e.preventDefault();
                e.stopPropagation();

                var href = card.getAttribute('data-nav-pin-card') || '';
                if (!href || href === '#') return;

                var was = readStore().pinned.indexOf(href) !== -1;
                togglePin(href);
                applyMenuState();
                toast(was ? 'Убрано из Избранного' : 'Добавлено в Избранное — сверху в левом меню');
            });
        }

        applyMenuState();
        // Закреплённые сервисы больше не обязаны присутствовать в коротком меню.
        // Восстанавливаем их названия из полного каталога на любой странице.
        if (readStore().pinned.length) {
            loadCatalog(function (data) {
                catalogUnavailable = !data;
                if (data) data.items.forEach(function (item) { catalogMeta[item.href] = item; });
                applyMenuState();
            });
        }
    }

    function applyMenuState() {
        var sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        var store = readStore();

        markPinned(sidebar, store);
        syncCards();

        // Старые настройки hidden не должны скрывать постоянный каталог.
        ensurePinnedGroup(sidebar, store.pinned);
        // Если фильтр уже введён, новые пункты должны сразу ему соответствовать.
        var search = document.getElementById('nav-search');
        if (search && search.value) search.dispatchEvent(new window.Event('input', { bubbles: true }));
    }

    function markPinned(sidebar, store) {
        sidebar.querySelectorAll('.nav-pin').forEach(function (btn) {
            var href = btn.getAttribute('data-nav-pin') || '';
            var on = store.pinned.indexOf(href) !== -1;
            btn.classList.toggle('is-pinned', on);
            btn.classList.toggle('text-gray-300', !on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function ensurePinnedGroup(sidebar, pinned) {
        // существующая группа «Избранное» — чистим и наполняем заново
        var el = sidebar.querySelector('[data-nav-group="pinned"]');
        if (el) el.remove();

        var nav = sidebar.querySelector('nav') || sidebar;

        var group = document.createElement('div');
        group.className = 'sidebar-group';
        group.setAttribute('data-nav-group', 'pinned');

        var title = document.createElement('div');
        title.className = 'sidebar-group-title px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500';
        title.textContent = 'Избранное';
        group.appendChild(title);

        var ul = document.createElement('ul');
        ul.className = 'space-y-0.5';
        group.appendChild(ul);

        // Полный каталог авторитетен по сравнению с карточкой (в нём есть
        // nav_paths); карточка нужна как мгновенный фолбэк до ответа API.
        var meta = Object.assign({}, cardMeta(), catalogMeta);
        var path = window.location.pathname.replace(/\/+$/, '') || '/';
        var activeHref = '', activeLength = -1;
        pinned.forEach(function (href) {
            var item = meta[href];
            if (typeof href !== 'string' || !item) return;
            var paths = [href].concat(Array.isArray(item.nav_paths) ? item.nav_paths : []);
            paths.forEach(function (candidate) {
                if (typeof candidate !== 'string') return;
                var target = candidate.trim().replace(/\/+$/, '') || '/';
                if (target !== '/' && (path === target || path.indexOf(target + '/') === 0) && target.length > activeLength) {
                    activeHref = href;
                    activeLength = target.length;
                }
            });
        });

        pinned.forEach(function (href) {
            // Каталог уже постоянный; старую закладку на него не дублируем.
            if (typeof href !== 'string' || href === '/services' || href.charAt(0) !== '/' || href.charAt(1) === '/') return;
            var item = meta[href];
            if (!item) return; // метаданные появятся после загрузки каталога

            var li = document.createElement('li');
            li.className = 'group relative';
            var a = document.createElement('a');
            a.setAttribute('href', href);
            a.className = 'sidebar-item relative flex items-center gap-3 rounded-lg px-3 py-2 pr-10 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100';

            if (href === activeHref) {
                a.classList.add('is-active');
                a.setAttribute('aria-current', path === href.replace(/\/+$/, '') ? 'page' : 'location');
            }

            var ic = document.createElement('i');
            ic.className = (item.icon || 'fas fa-circle') + ' w-5 shrink-0 text-center text-[15px]';
            a.appendChild(ic);

            var sp = document.createElement('span');
            sp.className = 'sidebar-label min-w-0 flex-1 break-words leading-snug';
            sp.textContent = item.title || href;
            a.appendChild(sp);
            a.setAttribute('data-search', (item.title || href).toLowerCase());
            a.title = item.title || href;

            // Кнопка — рядом со ссылкой, а не внутри неё: корректно для клавиатуры.
            var pin = document.createElement('button');
            pin.type = 'button';
            pin.className = 'sidebar-label nav-pin is-pinned absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1 hover:bg-gray-200 dark:hover:bg-gray-700';
            pin.setAttribute('data-nav-pin', href);
            pin.setAttribute('title', 'Убрать из Избранного');
            pin.setAttribute('aria-label', 'Убрать из Избранного: ' + (item.title || href));
            pin.setAttribute('aria-pressed', 'true');
            pin.innerHTML = '<i class="fas fa-star text-[10px]"></i>';

            li.appendChild(a);
            li.appendChild(pin);
            ul.appendChild(li);
        });

        if (!ul.children.length) {
            var hint = document.createElement('li');
            hint.className = 'sidebar-label px-3 py-2 text-xs text-gray-400';
            var hasPins = pinned.some(function (href) { return typeof href === 'string' && href !== '/services'; });
            hint.textContent = !hasPins ? 'Добавляйте сервисы звёздочкой в каталоге' :
                (catalogUnavailable ? 'Не удалось загрузить Избранное. Войдите или обновите страницу.' :
                    (catalogData ? 'Закреплённые сервисы недоступны текущему пользователю.' : 'Загрузка Избранного…'));
            ul.appendChild(hint);
        }
        nav.insertBefore(group, nav.firstChild);
    }

    /* ---------------------------------------------------------------------
     * Палитра всех сервисов — по кнопке «Все» в боковой панели.
     * ------------------------------------------------------------------- */
    function palette() {
        var backdrop = null, panel = null, list = null, input = null;
        var items = [];
        var active = -1;
        var loading = false, loadFailed = false;

        function trapDialogFocus(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
                return;
            }

            if (e.key !== 'Tab' || !panel) return;

            var focusable = panel.querySelectorAll('input:not([disabled]), a[href], button:not([disabled])');
            if (!focusable.length) {
                e.preventDefault();
                return;
            }

            var first = focusable[0];
            var last = focusable[focusable.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }

        function build() {
            if (backdrop) return;

            backdrop = document.createElement('div');
            backdrop.id = 'prx-nav-palette-dialog';
            backdrop.setAttribute('role', 'dialog');
            backdrop.setAttribute('aria-modal', 'true');
            backdrop.setAttribute('aria-label', 'Поиск по всем сервисам PRX');
            backdrop.setAttribute('aria-hidden', 'false');
            backdrop.className = 'fixed inset-0 z-50 flex items-start justify-center bg-gray-900/40 px-4 pt-[10vh] backdrop-blur-sm';
            backdrop.addEventListener('mousedown', function (e) { if (e.target === backdrop) close(); });
            backdrop.addEventListener('keydown', trapDialogFocus);

            panel = document.createElement('div');
            panel.className = 'w-full max-w-xl overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900';
            backdrop.appendChild(panel);

            var top = document.createElement('div');
            top.className = 'flex items-center gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-700';
            panel.appendChild(top);

            var ic = document.createElement('i');
            ic.className = 'fas fa-magnifying-glass text-sm text-gray-400';
            top.appendChild(ic);

            input = document.createElement('input');
            input.type = 'search';
            input.autocomplete = 'off';
            input.spellcheck = false;
            input.setAttribute('aria-label', 'Поиск по всем сервисам');
            input.setAttribute('aria-controls', 'nav-palette-results');
            input.placeholder = 'Поиск по всем сервисам…';
            input.className = 'flex-1 bg-transparent text-sm text-gray-800 outline-none placeholder:text-gray-400 dark:text-gray-100';
            top.appendChild(input);

            var esc = document.createElement('kbd');
            esc.className = 'rounded-md border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-xs font-semibold text-gray-400 dark:border-gray-700 dark:bg-gray-800';
            esc.textContent = 'Esc';
            top.appendChild(esc);

            list = document.createElement('div');
            list.id = 'nav-palette-results';
            list.className = 'max-h-[50vh] overflow-y-auto py-1';
            panel.appendChild(list);

            var empty = document.createElement('div');
            empty.id = 'nav-palette-empty';
            empty.setAttribute('role', 'status');
            empty.setAttribute('aria-live', 'polite');
            empty.className = 'hidden px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500';
            empty.textContent = 'Ничего не найдено';
            list.appendChild(empty);

            input.addEventListener('input', render);
            input.addEventListener('keydown', onKey);
            document.body.appendChild(backdrop);
        }

        function render() {
            var q = (input.value || '').trim().toLowerCase();
            var store = readStore();
            var storeOrder = {};
            store.pinned.forEach(function (h, i) { storeOrder[h] = i; });
            store.recents.forEach(function (h, i) { storeOrder[h] = storeOrder[h] === undefined ? i + 100 : storeOrder[h]; });

            // Палитра — поиск по всему доступному каталогу. Устаревший hidden
            // сохраняем для совместимости данных, но он не должен прятать сервисы.
            var scored = items
                .map(function (it) {
                    var hay = ((it.title || '') + ' ' + (it.short || '') + ' ' + (it.keywords || '') + ' ' + (it.group || '')).toLowerCase();
                    var s = q ? score(hay, q) : 1;
                    return { it: it, s: s };
                })
                .filter(function (x) { return !q || x.s > 0; });

            // без запроса — сперва пины и недавние, затем стабильные
            scored.sort(function (a, b) {
                if (!q) {
                    var pa = storeOrder[a.it.href], pb = storeOrder[b.it.href];
                    var ra = pa === undefined ? 1000 : pa;
                    var rb = pb === undefined ? 1000 : pb;
                    var sa = a.it.status === 'stable' ? 0 : (a.it.status === 'beta' ? 1 : 2);
                    var sb = b.it.status === 'stable' ? 0 : (b.it.status === 'beta' ? 1 : 2);
                    if (ra !== rb) return ra - rb;
                    if (sa !== sb) return sa - sb;
                    return (a.it.title || '').localeCompare(b.it.title || '');
                }
                if (b.s !== a.s) return b.s - a.s;
                return (a.it.title || '').localeCompare(b.it.title || '');
            });

            var max = q ? 24 : 12;
            scored = scored.slice(0, max);

            active = -1;
            var cards = list.querySelectorAll('.palette-item');
            cards.forEach(function (c) { c.remove(); });

            scored.forEach(function (x, i) {
                var row = document.createElement('div');
                row.className = 'palette-item flex items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 dark:text-gray-200 dark:hover:bg-blue-950/40';
                row.setAttribute('data-i', i);
                var link = document.createElement('a');
                link.href = x.it.href;
                link.className = 'flex min-w-0 flex-1 items-center gap-3';
                row.appendChild(link);

                var icon = document.createElement('i');
                icon.className = (x.it.icon || 'fas fa-puzzle-piece') + ' w-5 shrink-0 text-center text-xs text-gray-400';
                link.appendChild(icon);

                var mid = document.createElement('span');
                mid.className = 'min-w-0 flex-1';
                var t = document.createElement('span');
                t.className = 'block truncate font-semibold text-gray-900 dark:text-white';
                t.textContent = x.it.title;
                mid.appendChild(t);
                if (x.it.short) {
                    var d = document.createElement('span');
                    d.className = 'block truncate text-xs text-gray-400';
                    d.textContent = x.it.short;
                    mid.appendChild(d);
                }
                link.appendChild(mid);

                var grp = document.createElement('span');
                grp.className = 'shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-semibold uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400';
                grp.textContent = x.it.group;
                link.appendChild(grp);

                if (x.it.status === 'beta') {
                    var beta = document.createElement('span');
                    beta.className = 'shrink-0 rounded-full bg-blue-50 px-1.5 py-0.5 text-xs font-bold uppercase text-blue-600 dark:bg-blue-950/60 dark:text-blue-400';
                    beta.textContent = 'beta';
                    link.appendChild(beta);
                }

                var pin = document.createElement('button');
                pin.type = 'button';
                pin.className = 'shrink-0 rounded-md p-1 text-gray-300 hover:bg-gray-100 hover:text-blue-600 dark:hover:bg-gray-800';
                var isPinned = store.pinned.indexOf(x.it.href) !== -1;
                pin.innerHTML = '<i class="fas fa-' + (isPinned ? 'star text-blue-500' : 'star') + ' text-xs"></i>';
                pin.title = isPinned ? 'Убрать из Избранного' : 'В Избранное';
                pin.setAttribute('aria-label', pin.title + ': ' + x.it.title);
                pin.setAttribute('aria-pressed', isPinned ? 'true' : 'false');
                pin.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    togglePin(x.it.href);
                    applyMenuState();
                    render();
                    toast(isPinned ? 'Убрано из Избранного' : 'Добавлено в Избранное — сверху в левом меню');
                    input.focus();
                });
                row.appendChild(pin);

                row.addEventListener('mouseenter', function () { setActive(i); });
                link.addEventListener('click', function () {
                    pushRecent(x.it.href);
                });
                list.appendChild(row);
            });

            var empty = list.querySelector('#nav-palette-empty');
            empty.textContent = loading ? 'Загрузка каталога…' :
                (loadFailed ? 'Не удалось загрузить каталог. Войдите или попробуйте ещё раз.' : 'Ничего не найдено');
            empty.classList.toggle('hidden', scored.length > 0);
        }

        function setActive(i) {
            var rows = list.querySelectorAll('.palette-item');
            rows.forEach(function (r, idx) {
                r.classList.toggle('bg-blue-50', idx === i);
                r.classList.toggle('dark:bg-blue-950/40', idx === i);
            });
            active = i;
        }

        function onKey(e) {
            var rows = list.querySelectorAll('.palette-item');
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); if (rows.length) setActive((active + 1) % rows.length); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); if (rows.length) setActive((active - 1 + rows.length) % rows.length); return; }
            if (e.key === 'Enter') {
                if (active >= 0 && rows[active]) {
                    var link = rows[active].querySelector('a');
                    if (link) link.click();
                    close();
                }
            }
        }

        function open() {
            build();
            loading = !catalogData;
            loadFailed = false;
            loadCatalog(function (data) {
                loading = false;
                loadFailed = !data;
                catalogUnavailable = !data;
                if (data) {
                    items = data.items;
                    items.forEach(function (item) { catalogMeta[item.href] = item; });
                    applyMenuState();
                }
                render();
            });
            backdrop.classList.remove('hidden');
            backdrop.setAttribute('aria-hidden', 'false');
            var trigger = document.getElementById('nav-palette-open');
            if (trigger) trigger.setAttribute('aria-expanded', 'true');
            input.value = '';
            render();
            setTimeout(function () { input.focus(); }, 10);
        }

        function close() {
            if (!backdrop || backdrop.classList.contains('hidden')) return;
            backdrop.classList.add('hidden');
            backdrop.setAttribute('aria-hidden', 'true');
            var trigger = document.getElementById('nav-palette-open');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
                trigger.focus();
            }
        }

        return { open: open, close: close };
    }

    /* ---------------------------------------------------------------------
     * Инициализация
     * ------------------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        personaliseMenu();
        window.addEventListener('storage', function (event) {
            if (event.key === STORE || event.key === null) personaliseMenu();
        });

        var p = palette();

        // Палитра открывается КНОПКОЙ рядом с полем поиска: само поле фильтрует
        // текущее меню (Ctrl+K / «/» — там же, в шаблоне). Так было изначально:
        // поле — по меню, отдельная кнопка — окно со всеми сервисами.
        var paletteBtn = document.getElementById('nav-palette-open');
        if (paletteBtn) {
            paletteBtn.addEventListener('click', function () { p.open(); });
        }
    });
})(window, document);
