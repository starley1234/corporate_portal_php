/*
 * Навигация: Избранное принадлежит аккаунту и приходит в первом HTML.
 * Звёзды сохраняются POST-запросом; localStorage не является источником
 * Избранного.
 */
(function (window, document) {
    'use strict';

    var RECENTS_STORE = 'prx.nav.recents.v1';
    var CHANGE_SIGNAL = 'prx.nav.favorites.changed.v1';
    var MAX_RECENTS = 12;
    var catalogData = null, catalogRequest = null, catalogEpoch = 0;
    var state = { status: 'guest', userId: 0, csrf: '', items: [], saving: false, version: 0 };
    var refreshing = false, refreshNeeded = false, channel = null;
    var sidebar = null, paletteView = null;

    function readRecents() {
        try {
            var data = JSON.parse(localStorage.getItem(RECENTS_STORE) || '[]');
            return Array.isArray(data) ? data.filter(function (href) { return typeof href === 'string'; }).slice(0, MAX_RECENTS) : [];
        } catch (e) { return []; }
    }

    function pushRecent(href) {
        var recents = [href].concat(readRecents().filter(function (h) { return h !== href; })).slice(0, MAX_RECENTS);
        try { localStorage.setItem(RECENTS_STORE, JSON.stringify(recents)); } catch (e) { /* optional */ }
    }

    function isPinned(href) {
        return state.items.some(function (item) { return item.href === href; });
    }

    function loadCatalog(cb) {
        if (state.status === 'stale') { cb(null); return; }
        if (catalogData) { cb(catalogData); return; }
        if (!catalogRequest) {
            var epoch = catalogEpoch;
            catalogRequest = fetch('/prx110/api/catalog', {
                headers: { 'Accept': 'application/json' }, cache: 'no-store', credentials: 'same-origin'
            }).then(function (r) {
                if (!r.ok) {
                    var error = new Error('Каталог недоступен');
                    error.status = r.status;
                    throw error;
                }
                return r.json();
            }).then(function (data) {
                if (epoch !== catalogEpoch || state.status === 'stale') return null;
                checkAccount(data);
                if (Array.isArray(data.items)) catalogData = data;
                return catalogData;
            }).catch(function (error) {
                if (epoch === catalogEpoch && error && (error.status === 401 || error.status === 403 || error.status === 409)
                    && (state.status !== 'guest' || error.status === 409)) {
                    handleFailure(error);
                }
                return null;
            }).then(function (data) {
                catalogRequest = null;
                return data;
            });
        }
        catalogRequest.then(cb);
    }

    function score(hay, q) {
        if (!q) return 0;
        if (window.PRXSearchLayout.variants(q).some(function (variant) { return hay.indexOf(variant) !== -1; })) return 1000 + (1000 - hay.length);
        return q.split(/\s+/).filter(Boolean).reduce(function (total, token) {
            return total + (window.PRXSearchLayout.matches(hay, token) ? 500 : 0);
        }, 0);
    }

    var toastTimer = null;
    function toast(text) {
        var el = document.getElementById('prx-nav-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'prx-nav-toast';
            el.setAttribute('role', 'status');
            el.className = 'pointer-events-none fixed bottom-6 left-1/2 z-[60] -translate-x-1/2 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-semibold text-white opacity-0 shadow-lg transition-opacity dark:bg-gray-700';
            document.body.appendChild(el);
        }
        el.textContent = text;
        el.classList.remove('opacity-0');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.add('opacity-0'); }, 2800);
    }

    function status(text) {
        var el = document.getElementById('nav-favorite-status');
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('hidden', !text);
    }

    function normalizeItems(items) {
        if (!Array.isArray(items)) throw new Error('Некорректный ответ Избранного');
        return items.filter(function (item) {
            return item && typeof item.href === 'string' && item.href.charAt(0) === '/' &&
                item.href.charAt(1) !== '/' && item.href !== '/services' &&
                typeof item.title === 'string' && typeof item.icon === 'string';
        }).map(function (item) {
            return { href: item.href, title: item.title, icon: item.icon,
                nav_paths: Array.isArray(item.nav_paths) ? item.nav_paths : [] };
        });
    }

    function activeHref(items) {
        var path = window.location.pathname.replace(/\/+$/, '') || '/';
        var best = '', length = -1;
        items.forEach(function (item) {
            [item.href].concat(item.nav_paths).forEach(function (candidate) {
                if (typeof candidate !== 'string') return;
                var target = candidate.trim().replace(/\/+$/, '') || '/';
                if ((path === target || (target !== '/' && path.indexOf(target + '/') === 0)) && target.length > length) {
                    best = item.href;
                    length = target.length;
                }
            });
        });
        return best;
    }

    function syncCards() {
        document.querySelectorAll('.nav-pin-card').forEach(function (btn) {
            var href = btn.getAttribute('data-nav-pin-card') || '';
            var on = isPinned(href);
            var title = state.status !== 'ready' ? 'Избранное недоступно' : (on ? 'Убрать из Избранного' : 'В Избранное');
            var service = btn.getAttribute('data-nav-pin-title') || '';
            btn.classList.toggle('text-amber-500', on);
            btn.classList.toggle('text-gray-400', !on);
            btn.setAttribute('title', title);
            btn.setAttribute('aria-label', title + (service ? ': ' + service : ''));
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.disabled = state.status !== 'ready' || state.saving;
        });
        if (sidebar) sidebar.querySelectorAll('.nav-pin').forEach(function (btn) {
            btn.disabled = state.status !== 'ready' || state.saving;
        });
        document.querySelectorAll('[data-palette-pin]').forEach(function (btn) {
            btn.disabled = state.status !== 'ready' || state.saving;
        });
    }

    function renderFavorites() {
        var catalog = document.querySelector('[data-catalog-favorites]');
        var shortcuts = catalog && catalog.querySelector('[data-catalog-favorites-list]');
        if (shortcuts) {
            shortcuts.textContent = '';
            state.items.forEach(function (item) {
                var link = document.createElement('a');
                link.className = 'prx-btn-secondary';
                link.href = item.href;
                link.textContent = item.title;
                shortcuts.appendChild(link);
            });
            catalog.hidden = shortcuts.children.length === 0;
        }
        var list = sidebar && sidebar.querySelector('[data-nav-pinned-list]');
        if (!list) return;
        while (list.firstChild) list.removeChild(list.firstChild);
        var selected = activeHref(state.items);
        var path = window.location.pathname.replace(/\/+$/, '') || '/';

        state.items.forEach(function (item) {
            var li = document.createElement('li');
            li.className = 'group relative';
            var a = document.createElement('a');
            a.setAttribute('href', item.href);
            a.className = 'sidebar-item relative flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 pr-10 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100';
            a.setAttribute('data-search', item.title.toLowerCase());
            a.setAttribute('data-active', item.href === selected ? '1' : '0');
            a.title = item.title;
            if (item.href === selected) {
                a.classList.add('is-active');
                a.setAttribute('aria-current', path === (item.href.replace(/\/+$/, '') || '/') ? 'page' : 'location');
            }
            var icon = document.createElement('i');
            icon.className = (item.icon || 'fas fa-circle') + ' w-5 shrink-0 text-center text-[15px]';
            var label = document.createElement('span');
            label.className = 'sidebar-label min-w-0 flex-1 break-words leading-snug';
            label.textContent = item.title;
            a.appendChild(icon);
            a.appendChild(label);

            var pin = document.createElement('button');
            pin.type = 'button';
            pin.className = 'sidebar-label nav-pin is-pinned absolute right-1 top-1/2 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-md hover:bg-gray-200 dark:hover:bg-gray-700';
            pin.setAttribute('data-nav-pin', item.href);
            pin.title = 'Убрать из Избранного';
            pin.setAttribute('aria-label', 'Убрать из Избранного: ' + item.title);
            pin.setAttribute('aria-pressed', 'true');
            pin.innerHTML = '<i class="fas fa-star text-xs"></i>';
            li.appendChild(a);
            li.appendChild(pin);
            list.appendChild(li);
        });

        if (!state.items.length) {
            var hint = document.createElement('li');
            hint.className = 'sidebar-label px-3 py-2 text-xs text-gray-400';
            hint.setAttribute('data-nav-pinned-empty', '');
            hint.textContent = state.status === 'guest' ? 'Войдите, чтобы сохранять Избранное' :
                (state.status !== 'ready' ? 'Избранное недоступно. Обновите страницу.' : 'Добавляйте сервисы звёздочкой в каталоге');
            list.appendChild(hint);
        }
        syncCards();
        var search = document.getElementById('nav-search');
        if (search && search.value) search.dispatchEvent(new window.Event('input', { bubbles: true }));
    }

    function checkAccount(data) {
        if (!data || !Number.isSafeInteger(data.user_id) || data.user_id <= 0) {
            throw new Error('Некорректный ответ сервера');
        }
        if (data.user_id !== state.userId) {
            var changed = new Error('Аккаунт изменился');
            changed.status = 409;
            throw changed;
        }
    }

    function applyResponse(data) {
        checkAccount(data);
        var next = normalizeItems(data.items);
        // Не пересоздаём одинаковые ссылки при возврате на вкладку: сохраняем
        // фокус, прокрутку и отрендеренный сервером DOM.
        if (JSON.stringify(next) === JSON.stringify(state.items)) return;
        state.items = next;
        renderFavorites();
        if (paletteView) paletteView.update();
    }

    function requestJSON(url, options) {
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok) {
                    var error = new Error(data.error || 'Запрос не выполнен');
                    error.status = response.status;
                    throw error;
                }
                return data;
            });
        });
    }

    function handleFailure(error) {
        if (state.status === 'stale') return;
        if (error && (error.status === 401 || error.status === 403 || error.status === 409)) {
            state.status = 'stale';
            state.items = [];
            catalogEpoch++;
            catalogData = null;
            renderFavorites();
            if (paletteView) paletteView.invalidate();
            status('Сессия или аккаунт изменились. Обновите страницу и войдите снова.');
        } else {
            status('Не удалось подтвердить состояние Избранного. Попробуйте ещё раз или обновите страницу.');
        }
        toast('Избранное не удалось обновить');
    }

    function announceChange() {
        var message = { user_id: state.userId, nonce: Date.now() + '-' + Math.random() };
        if (channel) {
            try { channel.postMessage(message); return; } catch (e) { /* fallback */ }
        }
        try { localStorage.setItem(CHANGE_SIGNAL, JSON.stringify(message)); } catch (e) { /* optional */ }
    }

    function refreshFavorites() {
        if (state.status !== 'ready') return;
        if (state.saving || refreshing) { refreshNeeded = true; return; }
        refreshNeeded = false;
        refreshing = true;
        var version = state.version;
        requestJSON('/prx110/api/favorites', {
            headers: { 'Accept': 'application/json' }, cache: 'no-store', credentials: 'same-origin'
        }).then(function (data) {
            if (state.status !== 'ready') return;
            if (state.saving || version !== state.version) { refreshNeeded = true; return; }
            applyResponse(data);
            status('');
        }).catch(handleFailure).finally(function () {
            refreshing = false;
            if (refreshNeeded && !state.saving) refreshFavorites();
        });
    }

    function saveFavorites(url, fields, success) {
        if (state.status !== 'ready' || state.saving) return;
        state.saving = true;
        state.version++;
        syncCards();
        var body = new window.URLSearchParams();
        body.set('csrf', state.csrf);
        body.set('account_id', String(state.userId));
        Object.keys(fields).forEach(function (key) { body.set(key, fields[key]); });
        requestJSON(url, {
            method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString(), credentials: 'same-origin', cache: 'no-store'
        }).then(function (data) {
            // Другой запрос мог уже обнаружить выход/смену аккаунта, пока этот
            // POST был в пути. Его запоздавший ответ не оживляет старый список.
            if (state.status !== 'ready') return;
            applyResponse(data);
            status('');
            announceChange();
            if (success) success(data);
        }).catch(handleFailure).finally(function () {
            state.saving = false;
            syncCards();
            if (paletteView) paletteView.update();
            if (refreshNeeded && !refreshing) refreshFavorites();
        });
    }

    function setPin(href, on) {
        saveFavorites('/prx110/api/favorites', { href: href, pinned: on ? '1' : '0' }, function () {
            toast(on ? 'Добавлено в Избранное' : 'Убрано из Избранного');
        });
    }

    function initFavorites() {
        sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        state.status = sidebar.getAttribute('data-nav-status') || 'guest';
        state.userId = Number(sidebar.getAttribute('data-nav-user-id') || 0);
        state.csrf = sidebar.getAttribute('data-nav-csrf') || '';
        try { state.items = normalizeItems(JSON.parse(sidebar.getAttribute('data-nav-items') || '[]')); }
        catch (e) { state.status = 'error'; state.items = []; renderFavorites(); }

        // Уже отрендеренный сервером список не заменяем на DOMContentLoaded.
        syncCards();
        sidebar.addEventListener('click', function (event) {
            var link = event.target.closest('a.sidebar-item');
            if (link) {
                var href = link.getAttribute('href') || '';
                if (href && href !== '#') pushRecent(href);
            }
            var button = event.target.closest('.nav-pin');
            if (!button) return;
            event.preventDefault();
            var href = button.getAttribute('data-nav-pin') || '';
            if (href) setPin(href, false);
        });
        document.addEventListener('click', function (event) {
            var card = event.target.closest('.nav-pin-card');
            if (!card) return;
            event.preventDefault();
            var href = card.getAttribute('data-nav-pin-card') || '';
            if (href) setPin(href, !isPinned(href));
        });

        function receiveChange(message) {
            // Даже сигнал другого аккаунта означает, что общая сессия могла
            // смениться. Сервер определит текущего пользователя, не сообщение.
            if (message && Number.isSafeInteger(Number(message.user_id)) && Number(message.user_id) > 0) refreshFavorites();
        }
        try {
            if (window.BroadcastChannel) {
                channel = new window.BroadcastChannel('prx.nav.favorites');
                channel.onmessage = function (event) { receiveChange(event.data); };
            }
        } catch (e) { channel = null; }
        window.addEventListener('storage', function (event) {
            if (event.key !== CHANGE_SIGNAL || !event.newValue) return;
            try { receiveChange(JSON.parse(event.newValue)); } catch (e) { /* ignore */ }
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) refreshFavorites();
        });
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) refreshFavorites();
        });
    }

    function palette() {
        var backdrop = null, panel = null, list = null, input = null;
        var items = [], active = -1, loading = false, loadFailed = false;

        function trapDialogFocus(e) {
            if (e.key === 'Escape') { e.preventDefault(); close(); return; }
            if (e.key !== 'Tab' || !panel) return;
            var focusable = panel.querySelectorAll('input:not([disabled]), a[href], button:not([disabled])');
            if (!focusable.length) { e.preventDefault(); return; }
            var first = focusable[0], last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
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
            var icon = document.createElement('i');
            icon.className = 'fas fa-magnifying-glass text-sm text-gray-400';
            top.appendChild(icon);
            input = document.createElement('input');
            input.type = 'search'; input.autocomplete = 'off'; input.spellcheck = false;
            input.setAttribute('aria-label', 'Поиск по всем сервисам');
            input.setAttribute('aria-controls', 'nav-palette-results');
            input.placeholder = 'Поиск по всем сервисам…';
            input.className = 'flex-1 bg-transparent text-sm text-gray-800 outline-none placeholder:text-gray-400 dark:text-gray-100';
            top.appendChild(input);
            var esc = document.createElement('kbd');
            esc.className = 'rounded-md border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-xs font-semibold text-gray-400 dark:border-gray-700 dark:bg-gray-800';
            esc.textContent = 'Esc'; top.appendChild(esc);
            list = document.createElement('div');
            list.id = 'nav-palette-results'; list.className = 'max-h-[50vh] overflow-y-auto py-1';
            panel.appendChild(list);
            var empty = document.createElement('div');
            empty.id = 'nav-palette-empty'; empty.setAttribute('role', 'status'); empty.setAttribute('aria-live', 'polite');
            empty.className = 'hidden px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500';
            list.appendChild(empty);
            input.addEventListener('input', render);
            input.addEventListener('keydown', onKey);
            document.body.appendChild(backdrop);
        }

        function render() {
            var q = (input.value || '').trim().toLowerCase();
            var order = {};
            state.items.forEach(function (item, i) { order[item.href] = i; });
            readRecents().forEach(function (href, i) { if (order[href] === undefined) order[href] = i + 100; });
            var scored = items.map(function (it) {
                var hay = ((it.title || '') + ' ' + (it.short || '') + ' ' + (it.keywords || '') + ' ' + (it.group || '')).toLowerCase();
                return { it: it, s: q ? score(hay, q) : 1 };
            }).filter(function (x) { return !q || x.s > 0; });
            scored.sort(function (a, b) {
                if (!q) {
                    var ra = order[a.it.href] === undefined ? 1000 : order[a.it.href];
                    var rb = order[b.it.href] === undefined ? 1000 : order[b.it.href];
                    var sa = a.it.status === 'stable' ? 0 : (a.it.status === 'beta' ? 1 : 2);
                    var sb = b.it.status === 'stable' ? 0 : (b.it.status === 'beta' ? 1 : 2);
                    if (ra !== rb) return ra - rb;
                    if (sa !== sb) return sa - sb;
                } else if (b.s !== a.s) return b.s - a.s;
                return (a.it.title || '').localeCompare(b.it.title || '');
            });
            scored = scored.slice(0, q ? 24 : 12);
            active = -1;
            list.querySelectorAll('.palette-item').forEach(function (row) { row.remove(); });
            scored.forEach(function (x, i) {
                var row = document.createElement('div');
                row.className = 'palette-item flex items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 dark:text-gray-200 dark:hover:bg-blue-950/40';
                row.setAttribute('data-i', i);
                var link = document.createElement('a');
                link.href = x.it.href; link.className = 'flex min-w-0 flex-1 items-center gap-3';
                row.appendChild(link);
                var icon = document.createElement('i');
                icon.className = (x.it.icon || 'fas fa-puzzle-piece') + ' w-5 shrink-0 text-center text-xs text-gray-400';
                link.appendChild(icon);
                var mid = document.createElement('span'); mid.className = 'min-w-0 flex-1';
                var title = document.createElement('span'); title.className = 'block truncate font-semibold text-gray-900 dark:text-white';
                title.textContent = x.it.title; mid.appendChild(title);
                if (x.it.short) {
                    var description = document.createElement('span'); description.className = 'block truncate text-xs text-gray-400';
                    description.textContent = x.it.short; mid.appendChild(description);
                }
                link.appendChild(mid);
                var group = document.createElement('span');
                group.className = 'shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-semibold uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400';
                group.textContent = x.it.group; link.appendChild(group);
                if (x.it.status === 'beta') {
                    var beta = document.createElement('span');
                    beta.className = 'shrink-0 rounded-full bg-blue-50 px-1.5 py-0.5 text-xs font-bold uppercase text-blue-600 dark:bg-blue-950/60 dark:text-blue-400';
                    beta.textContent = 'beta'; link.appendChild(beta);
                }
                var pin = document.createElement('button');
                pin.type = 'button'; pin.setAttribute('data-palette-pin', x.it.href);
                pin.className = 'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-gray-300 hover:bg-gray-100 hover:text-blue-600 dark:hover:bg-gray-800';
                var on = isPinned(x.it.href);
                pin.innerHTML = '<i class="fas fa-star' + (on ? ' text-blue-500' : '') + ' text-xs"></i>';
                pin.title = state.status !== 'ready' ? 'Избранное недоступно' : (on ? 'Убрать из Избранного' : 'В Избранное');
                pin.setAttribute('aria-label', pin.title + ': ' + x.it.title);
                pin.setAttribute('aria-pressed', on ? 'true' : 'false');
                pin.disabled = state.status !== 'ready' || state.saving;
                pin.addEventListener('click', function (e) {
                    e.preventDefault(); e.stopPropagation();
                    setPin(x.it.href, !on);
                    input.focus();
                });
                row.appendChild(pin);
                row.addEventListener('mouseenter', function () { setActive(i); });
                link.addEventListener('click', function () { pushRecent(x.it.href); });
                list.appendChild(row);
            });
            var empty = list.querySelector('#nav-palette-empty');
            empty.textContent = loading ? 'Загрузка каталога…' :
                (loadFailed ? 'Не удалось загрузить каталог. Войдите или попробуйте ещё раз.' : 'Ничего не найдено');
            empty.classList.toggle('hidden', scored.length > 0);
        }

        function setActive(i) {
            list.querySelectorAll('.palette-item').forEach(function (row, idx) {
                row.classList.toggle('bg-blue-50', idx === i);
                row.classList.toggle('dark:bg-blue-950/40', idx === i);
            });
            active = i;
        }
        function onKey(e) {
            var rows = list.querySelectorAll('.palette-item');
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); if (rows.length) setActive((active + 1) % rows.length); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); if (rows.length) setActive((active - 1 + rows.length) % rows.length); return; }
            if (e.key === 'Enter' && active >= 0 && rows[active]) {
                var link = rows[active].querySelector('a');
                if (link) link.click();
                close();
            }
        }
        function open() {
            build(); loading = !catalogData; loadFailed = false;
            loadCatalog(function (data) {
                loading = false; loadFailed = !data;
                if (data) items = data.items;
                render();
            });
            backdrop.classList.remove('hidden'); backdrop.setAttribute('aria-hidden', 'false');
            var trigger = document.getElementById('nav-palette-open');
            if (trigger) trigger.setAttribute('aria-expanded', 'true');
            input.value = ''; render();
            setTimeout(function () { input.focus(); }, 10);
        }
        function close() {
            if (!backdrop || backdrop.classList.contains('hidden')) return;
            backdrop.classList.add('hidden'); backdrop.setAttribute('aria-hidden', 'true');
            var trigger = document.getElementById('nav-palette-open');
            if (trigger) { trigger.setAttribute('aria-expanded', 'false'); trigger.focus(); }
        }
        function update() {
            if (backdrop && !backdrop.classList.contains('hidden')) render();
        }
        function invalidate() {
            items = [];
            loading = false;
            loadFailed = true;
            update();
        }
        return { open: open, close: close, update: update, invalidate: invalidate };
    }

    document.addEventListener('DOMContentLoaded', function () {
        initFavorites();
        paletteView = palette();
        var button = document.getElementById('nav-palette-open');
        if (button) button.addEventListener('click', function () { paletteView.open(); });
    });
})(window, document);
