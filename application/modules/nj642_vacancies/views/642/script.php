<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj642_vacancies: скрипт страниц /vacancies и /vacancies/<code>.
 *
 * Здесь только то, чего не делает общий механизм:
 *   * «Собрать» — AJAX, ряд «идёт сбор» монтируется сразу (N2JLive.mount),
 *     без перезагрузки страницы;
 *   * «Строк:» — выбор помнит браузер (n2j:per:/vacancies), как на /trends;
 *   * строки компаний в форме: «ещё компания» и удаление строки;
 *   * удаление монитора спрашивает окном (data-n2j-confirm, общий app.js).
 *
 * Живой статус-ряд (спиннер, секундомер, этапы, «нет пульса», завершение с
 * одной перезагрузкой) рисует ОБЩИЙ partials/live_status + live_status_js.
 */
echo View::factory('partials/live_status_js');

$csrf_json = json_encode((string) $csrf);
?>
<script>
(function () {
    'use strict';

    var CSRF = <?php echo $csrf_json; ?>;

    function toast(text, ok) {
        // Общий тост app.js; результат действия держим дольше обычного «ok».
        N2J.toast(text, ok ? 3500 : 0, ok ? 'ok' : 'err');
    }

    function post(path, data) {
        return fetch(path, {
            method: 'POST',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        }).then(function (r) { return r.json().catch(function () { return {}; }); });
    }

    // «Собрать» — задача сразу в очередь, ряд «идёт сбор» появляется сразу же
    document.querySelectorAll('[data-nj642-run]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var code = btn.getAttribute('data-code') || '';
            var original = btn.innerHTML;

            if (!code) return;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>Собираю…';

            post('/vacancies/api/run', { csrf: CSRF, code: code })
                .then(function (res) {
                    btn.disabled = false;
                    btn.innerHTML = original;
                    toast(res.ok ? 'Сбор запущен — вакансии появятся в ленте' : (res.error || 'Не получилось'), !!res.ok);

                    if (res.ok && window.N2JLive) {
                        var scope = btn.closest('[data-nj642-watch]') || document;
                        var host  = scope.querySelector('[data-nj642-live-host]');

                        if (host) {
                            var prev = host.querySelector('[data-n2j-live]');
                            if (prev) prev.remove();
                            window.N2JLive.mount(host, code);
                        }
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.innerHTML = original;
                    toast('Сеть не ответила — попробуйте ещё раз', false);
                });
        });
    });

    // «Строк:» — память браузера по пути страницы (n2j:per:/vacancies)
    try {
        var sel = document.querySelector('[data-nj642-per]');
        var key = 'n2j:per:' + location.pathname.replace(/\/[0-9a-z]+$/, '');
        var saved = localStorage.getItem(key);

        if (sel) {
            if (saved && [20, 50, 100].indexOf(parseInt(saved, 10)) !== -1
                && String(sel.value) !== String(saved)) {
                sel.value = saved;
                var form = sel.closest('form');
                if (form) form.submit();
            }
            sel.addEventListener('change', function () {
                try { localStorage.setItem(key, sel.value); } catch (e) {}
            });
        }
    } catch (e) {}

    // Форма монитора: строки компаний
    var rows = document.querySelector('[data-nj642-rows]');

    if (rows) {
        document.querySelectorAll('[data-nj642-row-add]').forEach(function (add) {
            add.addEventListener('click', function () {
                var src = rows.querySelector('[data-nj642-row]');
                if (!src) return;
                var box = src.parentNode.cloneNode(true);
                var input = box.querySelector('[data-nj642-row]');
                input.value = '';
                input.setAttribute('placeholder', 'ещё компания');
                var del = box.querySelector('[data-nj642-row-del]');
                if (!del) {
                    del = document.createElement('button');
                    del.type = 'button';
                    del.setAttribute('data-nj642-row-del', '');
                    del.title = 'Убрать строку';
                    del.className = 'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40';
                    del.innerHTML = '<i class="fas fa-xmark"></i>';
                    box.appendChild(del);
                }
                rows.appendChild(box);
                input.focus();
            });
        });

        rows.addEventListener('click', function (e) {
            var del = e.target.closest('[data-nj642-row-del]');
            if (!del) return;
            var box = del.parentNode;
            if (rows.querySelectorAll('[data-nj642-row]').length > 1) box.remove();
        });
    }

})();
</script>
