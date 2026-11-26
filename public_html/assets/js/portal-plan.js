/* Search the server-rendered plan and keep its existing POST/303 contract. */
(function () {
    'use strict';
    var contentSelector = '[data-portal-plan-content]';
    var rowSelector = '[data-portal-plan-module]';
    var search = document.getElementById('portal-plan-search');
    var empty = document.getElementById('portal-plan-search-empty');
    var matcher = window.PRXSearchLayout;
    var saving = false;

    function applySearch(content) {
        if (!search || !empty || !matcher || !content) return;
        var query = search.value.trim();
        var groups = content.querySelectorAll('[data-portal-plan-group]');
        var found = false;
        groups.forEach(function (group) {
            var groupMatch = matcher.matches(group.dataset.portalPlanGroup, query);
            var hasRows = false;
            group.querySelectorAll('[data-portal-plan-item]').forEach(function (item) {
                item.hidden = !groupMatch && !matcher.matches(item.dataset.portalPlanItem, query);
                hasRows = hasRows || !item.hidden;
            });
            group.hidden = !hasRows;
            found = found || hasRows;
        });
        // Табличный вид: те же данные-атрибуты на строках <tr>.
        var table = content.querySelector('[data-plan-table]');
        if (table) {
            var tableHas = false;
            table.querySelectorAll('tr[data-portal-plan-item]').forEach(function (item) {
                item.hidden = !matcher.matches(item.dataset.portalPlanItem, query);
                tableHas = tableHas || !item.hidden;
            });
            table.hidden = Boolean(query) && !tableHas;
            found = found || tableHas;
        }
        // The server already provides an empty state when the status has no rows.
        empty.hidden = !query || found || !groups.length;
    }

    if (search && empty && matcher) {
        search.closest('[data-service-search]').hidden = false;
        var filter = function () { applySearch(document.querySelector(contentSelector)); };
        search.addEventListener('input', filter);
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && search.value) {
                search.value = '';
                filter();
                event.preventDefault();
            }
        });
        filter();
    }

    /* ===== Вид списка: карточки | таблица (выбор запоминается) ===== */
    var VIEW_KEY = 'prx112.plan.view';

    function applyView(root) {
        var scope = root || document;
        var view = 'cards';
        try { view = window.localStorage.getItem(VIEW_KEY) === 'table' ? 'table' : 'cards'; } catch (e) { /* private mode */ }
        scope.querySelectorAll('[data-plan-view-panel]').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-plan-view-panel') !== view;
        });
        scope.querySelectorAll('[data-plan-view]').forEach(function (btn) {
            var on = btn.getAttribute('data-plan-view') === view;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    document.querySelectorAll('[data-plan-view]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try { window.localStorage.setItem(VIEW_KEY, btn.getAttribute('data-plan-view')); } catch (e) { /* private mode */ }
            applyView(document);
        });
    });
    applyView(document);

    /* ===== Сортировка таблицы кликом по заголовку ===== */
    function applySortIcons(table) {
        table.querySelectorAll('th[data-plan-sort-th]').forEach(function (th) {
            var dir = th.getAttribute('aria-sort');
            var icon = 'fa-sort';
            if (dir === 'ascending') icon = 'fa-sort-up';
            else if (dir === 'descending') icon = 'fa-sort-down';
            th.querySelectorAll('[data-plan-sort-icon]').forEach(function (i) {
                i.classList.remove('fa-sort', 'fa-sort-up', 'fa-sort-down');
                i.classList.add(icon);
            });
        });
    }

    document.querySelectorAll('[data-plan-table]').forEach(function (table) {
        var tbody = table.querySelector('tbody');
        table.querySelectorAll('[data-plan-sort]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var th = btn.closest('th');
                if (!tbody || !th) return;
                var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
                table.querySelectorAll('th[data-plan-sort-th]').forEach(function (other) { other.removeAttribute('aria-sort'); });
                th.setAttribute('aria-sort', dir);
                var key = btn.getAttribute('data-plan-sort');
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-plan-row]'));
                rows.sort(function (a, b) {
                    if (key === 'ts') {
                        return (Number(a.getAttribute('data-plan-ts')) || 0) - (Number(b.getAttribute('data-plan-ts')) || 0);
                    }
                    if (key === 'state') {
                        var ra = Number(a.getAttribute('data-plan-state-rank')) || 0;
                        var rb = Number(b.getAttribute('data-plan-state-rank')) || 0;
                        if (ra !== rb) return ra - rb;
                        return String(a.getAttribute('data-plan-title') || '').localeCompare(String(b.getAttribute('data-plan-title') || ''), 'ru');
                    }
                    var va = a.getAttribute('data-plan-' + key) || '';
                    var vb = b.getAttribute('data-plan-' + key) || '';
                    return va.localeCompare(vb, 'ru');
                });
                if (dir === 'descending') rows.reverse();
                rows.forEach(function (row) { tbody.appendChild(row); });
                applySortIcons(table);
            });
        });
        applySortIcons(table);
    });

    if (!window.fetch || !window.DOMParser || !window.PRX) return;

    document.addEventListener('submit', async function (event) {
        var form = event.target;
        if (event.defaultPrevented || !form.matches('[data-portal-plan-action]')) return;
        var content = form.closest(contentSelector);
        var action = new URL(form.action, window.location.href);
        if (!content || action.origin !== window.location.origin || action.pathname !== '/portal-plan/approve') return;

        event.preventDefault();
        if (saving) return;
        var body = new URLSearchParams(new FormData(form));
        var button = event.submitter || form.querySelector('button[type="submit"]');
        var row = form.closest(rowSelector);
        var hadFocus = content.contains(document.activeElement);
        saving = true;
        window.PRX.busy(button, true);

        try {
            var response = await window.fetch(action.href, {
                method: 'POST', body: body, credentials: 'same-origin', mode: 'same-origin'
            });
            if (!response.ok) {
                throw new Error(response.status === 403
                    ? 'Не удалось сохранить: проверьте права доступа или обновите страницу.'
                    : 'Не удалось сохранить изменение.');
            }
            // A successful POST redirects to the same filter. Reuse the authoritative SSR markup.
            var result = new DOMParser().parseFromString(await response.text(), 'text/html');
            var updated = result.querySelector(contentSelector);
            var message = updated && updated.querySelector('[data-portal-plan-message]');
            if (!message) {
                throw new Error('Не удалось обновить план. Обновите страницу, чтобы проверить состояние.');
            }
            var text = message.textContent.trim();
            message.remove();

            var before = row && row.getBoundingClientRect();
            var scrollTop = window.scrollY;
            applySearch(updated);
            applyView(updated);
            var replacementRow = Array.from(updated.querySelectorAll(rowSelector)).find(function (item) {
                return row && !item.hidden && item.dataset.portalPlanModule === row.dataset.portalPlanModule;
            });
            var restoreFocus = hadFocus && (content.contains(document.activeElement) || document.activeElement === document.body);
            content.replaceWith(updated);
            if (restoreFocus) {
                var target = replacementRow || updated.querySelector('[aria-current="page"]');
                if (target) target.focus({ preventScroll: true });
            }
            if (before && before.bottom > 0 && before.top < window.innerHeight && replacementRow) {
                window.scrollBy(0, replacementRow.getBoundingClientRect().top - before.top);
            } else if (window.scrollY !== scrollTop) {
                window.scrollTo(0, scrollTop);
            }
            window.PRX.toast(text, 0, 'ok');
        } catch (error) {
            // A network error may happen after the write; never automatically resubmit.
            window.PRX.toast(error instanceof TypeError
                ? 'Не удалось связаться с сервером. Обновите страницу, чтобы проверить состояние.'
                : error.message, 0, 'err');
            if (hadFocus && document.activeElement === document.body && button) {
                window.PRX.busy(button, false);
                button.focus({ preventScroll: true });
            }
        } finally {
            saving = false;
            window.PRX.busy(button, false);
        }
    });
})();
