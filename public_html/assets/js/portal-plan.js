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
        // The server already provides an empty state when the status has no rows.
        empty.hidden = !query || found || !groups.length;
    }

    if (search && empty && matcher) {
        search.closest('[data-portal-plan-search]').hidden = false;
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
