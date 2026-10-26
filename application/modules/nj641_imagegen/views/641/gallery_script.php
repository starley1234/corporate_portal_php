<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/* nj641 «Изображения»: модерация и публикация в библиотеку.
   Действия идут без перезагрузки (эталон /nj602/admin): карточка сразу меняет
   бейдж, счётчики чипов обновляются, при фильтре «На модерации» одобренная
   картинка честно уезжает из списка. Удаление — в два клика. */
(function () {
    'use strict';

    var csrfEl  = document.getElementById('nj641-csrf');
    var filterEl = document.getElementById('nj641-filter');
    var flashEl = document.getElementById('nj641-flash');
    var pickAll = document.getElementById('nj641-select-all');
    var pickedEl = document.getElementById('nj641-picked');
    var bulkBtns = document.querySelectorAll('.nj641-bulk');
    var perSel = document.getElementById('nj641-per');

    var LABELS = {
        approved: ['В библиотеке', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'],
        rejected: ['Отклонено', 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'],
        pending:  ['На модерации', 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300']
    };

    function csrf() { return csrfEl ? csrfEl.value : ''; }
    function filter() { return filterEl ? filterEl.value : ''; }

    function flash(text, ok) {
        if (!flashEl) { return; }

        flashEl.textContent = text || '';
        flashEl.classList.toggle('hidden', !text);
        flashEl.classList.toggle('text-rose-600', ok === false);
        flashEl.classList.toggle('dark:text-rose-400', ok === false);
        flashEl.classList.toggle('text-blue-700', ok !== false);
        flashEl.classList.toggle('dark:text-blue-300', ok !== false);

        if (text) {
            window.setTimeout(function () { flashEl.classList.add('hidden'); }, 6000);
        }
    }

    /* «Строк:» запоминается браузером — как в /nj593/admin и /nj602/admin */
    if (perSel) {
        var saved = null;

        try { saved = window.localStorage.getItem('n2j:per:/imagegen/gallery'); } catch (e) {}

        if (saved && perSel.form) {
            var known = Array.prototype.some.call(perSel.options, function (o) { return o.value === saved; });

            if (known && saved !== perSel.value) {
                perSel.value = saved;
                perSel.form.submit();
                return;
            }
        }

        perSel.addEventListener('change', function () {
            try { window.localStorage.setItem('n2j:per:/imagegen/gallery', perSel.value); } catch (e) {}
            if (perSel.form) { perSel.form.submit(); }
        });
    }

    function cards() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-nj641-card]'));
    }

    function picks() {
        return cards().filter(function (card) {
            var box = card.querySelector('.nj641-pick');
            return box && box.checked;
        });
    }

    function syncPick() {
        var n = picks().length;

        if (pickedEl) { pickedEl.textContent = String(n); }
        Array.prototype.forEach.call(bulkBtns, function (b) { b.disabled = (n === 0); });
    }

    if (pickAll) {
        pickAll.addEventListener('change', function () {
            Array.prototype.forEach.call(document.querySelectorAll('.nj641-pick'), function (c) { c.checked = pickAll.checked; });
            syncPick();
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('nj641-pick')) {
            syncPick();
        }
    });

    function dropCard(card) {
        card.style.transition = 'opacity .2s';
        card.style.opacity = '0';

        window.setTimeout(function () {
            card.remove();

            var total = document.querySelector('[data-nj641-total]');
            if (total) {
                total.textContent = String(Math.max(0, (parseInt(total.textContent, 10) || 0) - 1));
            }

            if (cards().length === 0) {
                window.location.reload();
            }
        }, 200);
    }

    function applyStatus(card, status) {
        var pair = LABELS[status] || LABELS.pending;

        card.setAttribute('data-status', status);

        var badge = card.querySelector('[data-nj641-badge]');
        if (badge) {
            badge.textContent = pair[0];
            badge.className = 'absolute right-2.5 top-2.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ' + pair[1];
        }

        Array.prototype.forEach.call(card.querySelectorAll('[data-nj641-review]'), function (b) {
            b.classList.toggle('opacity-40', b.getAttribute('data-nj641-review') === status);
        });

        // карточка не подходит под открытый фильтр — убираем из списка
        if (filter() && filter() !== status) {
            dropCard(card);
        }
    }

    function applyCounts(counts) {
        if (!counts) { return; }

        Object.keys(counts).forEach(function (k) {
            var el = document.querySelector('[data-nj641-count="' + k + '"]');
            if (el) { el.textContent = String(counts[k]); }
        });
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {}),
            cache: 'no-store'
        }).then(function (r) { return r.json(); });
    }

    /* модерация одной картинки */
    Array.prototype.forEach.call(document.querySelectorAll('[data-nj641-review]'), function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            var status = btn.getAttribute('data-nj641-review');

            btn.disabled = true;

            post('/imagegen/api/review/' + id, { status: status, csrf: csrf() })
                .then(function (j) {
                    btn.disabled = false;

                    if (!j.ok) { flash(j.error || 'Не удалось сохранить.', false); return; }

                    var card = document.querySelector('[data-nj641-card="' + id + '"]');
                    if (card) { applyStatus(card, status); }

                    applyCounts(j.counts);
                    flash(j.flash || 'Сохранено.');
                })
                .catch(function () {
                    btn.disabled = false;
                    flash('Сервер не ответил — попробуйте ещё раз.', false);
                });
        });
    });

    /* пакетная модерация выбранных */
    Array.prototype.forEach.call(bulkBtns, function (btn) {
        btn.addEventListener('click', function () {
            var status = btn.getAttribute('data-nj641-bulk');
            var ids = picks().map(function (card) { return parseInt(card.getAttribute('data-nj641-card'), 10); });

            if (!ids.length) { flash('Выберите изображения.', false); return; }

            Array.prototype.forEach.call(bulkBtns, function (b) { b.disabled = true; });

            post('/imagegen/api/review-many', { ids: ids, status: status, csrf: csrf() })
                .then(function (j) {
                    if (!j.ok) { flash(j.error || 'Не удалось сохранить.', false); syncPick(); return; }

                    ids.forEach(function (id) {
                        var card = document.querySelector('[data-nj641-card="' + id + '"]');
                        if (card) { applyStatus(card, status); }
                    });

                    applyCounts(j.counts);

                    if (pickAll) { pickAll.checked = false; }
                    Array.prototype.forEach.call(document.querySelectorAll('.nj641-pick'), function (c) { c.checked = false; });
                    syncPick();
                    flash(j.flash || 'Сохранено.');
                })
                .catch(function () {
                    syncPick();
                    flash('Сервер не ответил — попробуйте ещё раз.', false);
                });
        });
    });

    /* удаление — в два клика (data-n2j-arm): первый взводит кнопку в app.js
       и сюда не доходит, второй — удаляет */
    Array.prototype.forEach.call(document.querySelectorAll('[data-nj641-del]'), function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-nj641-del');
            btn.disabled = true;

            post('/imagegen/api/delete/' + id, { csrf: csrf() })
                .then(function (j) {
                    if (!j.ok) { btn.disabled = false; flash(j.error || 'Не удалось удалить.', false); return; }

                    var card = document.querySelector('[data-nj641-card="' + id + '"]');
                    if (card) { card.remove(); }

                    var total = document.querySelector('[data-nj641-total]');
                    if (total) {
                        total.textContent = String(Math.max(0, (parseInt(total.textContent, 10) || 0) - 1));
                    }

                    flash('Изображение удалено.');
                })
                .catch(function () {
                    btn.disabled = false;
                    flash('Сервер не ответил.', false);
                });
        });
    });

    syncPick();
})();
</script>
