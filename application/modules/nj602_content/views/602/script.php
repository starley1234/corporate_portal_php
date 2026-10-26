<script>
/* nj602: отклонение/удаление — вторым нажатием (data-n2j-arm, app.js); mermaid из vendor
   (локальный, без внешних CDN). Сетевая диаграмма связей убрана по решению
   редакции — mermaid-схемы достаточно. */
(function () {
    'use strict';

    /* --- 0. админка: чипы тем и рубильник расписания --- */
    var chips = document.querySelectorAll('.nj602-topic-chip');

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            var box = chip.querySelector('input[type="checkbox"]'),
                dot = chip.querySelector('.nj602-chip-dot'),
                on = box.checked;

            chip.classList.toggle('border-blue-300', on);
            chip.classList.toggle('bg-blue-50', on);
            chip.classList.toggle('text-blue-700', on);
            chip.classList.toggle('dark:border-blue-700', on);
            chip.classList.toggle('dark:bg-blue-950/40', on);
            chip.classList.toggle('dark:text-blue-300', on);
            chip.classList.toggle('border-gray-200', !on);
            chip.classList.toggle('bg-gray-50', !on);
            chip.classList.toggle('text-gray-400', !on);
            chip.classList.toggle('dark:border-gray-700', !on);
            chip.classList.toggle('dark:bg-gray-900', !on);
            chip.classList.toggle('dark:text-gray-500', !on);

            if (dot) {
                dot.classList.toggle('bg-blue-500', on);
                dot.classList.toggle('bg-gray-300', !on);
                dot.classList.toggle('dark:bg-gray-600', !on);
            }
        });
    });

    var enToggle = document.getElementById('nj602-enabled-toggle');

    if (enToggle) {
        enToggle.addEventListener('change', function () {
            var on = enToggle.checked,
                field = document.getElementById('nj602-enabled-field'),
                label = document.getElementById('nj602-enabled-label');

            if (field) field.value = on ? '1' : '0';

            if (label) {
                label.textContent = on ? 'работает' : 'выключен';
                label.classList.toggle('text-emerald-600', on);
                label.classList.toggle('dark:text-emerald-400', on);
                label.classList.toggle('text-gray-400', !on);
            }
        });
    }

    /* --- 1b. AJAX-модерация: fetch вместо submit, строки обновляются на месте --- */
    function toast(text, ok) {
        // Общий тост app.js; результат действия держим дольше обычного «ok».
        N2J.toast(text, ok ? 3500 : 0, ok ? 'ok' : 'err');
    }

    var traceBox = document.getElementById('nj602-trace');       // контейнер живого журнала
    var traceCode = traceBox ? traceBox.getAttribute('data-code') : '';

    function refreshRows() {
        var tbody = document.getElementById('nj602-tbody');
        if (!tbody) return Promise.resolve();
        return fetch(rowsUrl(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.html) return;
                var tmp = document.createElement('tbody');
                tmp.innerHTML = res.html;
                tbody.replaceChildren.apply(tbody, Array.prototype.slice.call(tmp.childNodes));
                bindModeration(tbody);
                bindTraceButtons(tbody);
                updateSelection();

                // пагинация приходит тем же ответом: список и «стр. k из m»
                // всегда согласованы (страница не перезагружается)
                if (res.pager) {
                    var pg = document.getElementById('nj602-pager');
                    if (pg) pg.outerHTML = res.pager;
                }

                if (typeof res.total !== 'undefined') {
                    document.querySelectorAll('[data-nj602-total]').forEach(function (el) {
                        el.textContent = res.total;
                    });
                }

                if (res.counts) {
                    Object.keys(res.counts).forEach(function (k) {
                        document.querySelectorAll('[data-nj602-count="' + k + '"]').forEach(function (el) {
                            el.textContent = res.counts[k];
                        });
                    });
                    var badge = document.getElementById('nj602-badge-review');
                    if (badge) badge.classList.toggle('hidden', !(res.counts.review > 0));
                }
                // живой журнал выбранной строки — подменяем на свежий фрагмент
                if (traceCode && res.trace) {
                    var box = document.getElementById('nj602-trace');
                    if (box) box.outerHTML = res.trace;
                }

                // Сбор начался (например, из другой вкладки), а ряда ещё нет —
                // ставим его и подключаем к общему поллеру (N2JLive.register).
                // Уже отрисованный ряд НЕ подменяем: у общего механизма живые
                // ссылки на его DOM, подмена остановила бы секундомер.
                if (res.live) {
                    var host = document.getElementById('nj602-live');
                    if (host && !host.querySelector('[data-n2j-live]')) {
                        var holder = document.createElement('div');
                        holder.innerHTML = res.live;
                        var fresh = holder.firstElementChild;
                        if (fresh && fresh.querySelector('[data-n2j-live]')) {
                            host.replaceWith(fresh);
                            if (window.N2JLive) window.N2JLive.register(fresh.querySelector('[data-n2j-live]'));
                        }
                    }
                }
            })
            .catch(function () { /* сеть моргнула — обновим в следующий тик */ });
    }

    /* кнопка «журнал» в строке collect/error — выбирает статью для live-блока */
    function bindTraceButtons(root) {
        root.querySelectorAll('[data-nj602-trace-open]').forEach(function (btn) {
            if (btn.dataset.traceBound) return;
            btn.dataset.traceBound = '1';
            btn.addEventListener('click', function () {
                // Переход (а не подмена на месте): вместе с журналом нужно
                // переключить и живой ряд сбора — у него свой поллер и свое
                // время задачи, а адрес страницы остаётся честным (?code=…).
                var c = btn.getAttribute('data-nj602-trace-open');
                if (c) { location.href = '/nj602/admin?code=' + encodeURIComponent(c); }
            });
        });
    }

    function bindModeration(root) {
        root.querySelectorAll('#nj602-tbody form').forEach(function (f) {
            if (f.dataset.ajaxBound) return;
            f.dataset.ajaxBound = '1';
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(f);
                fd.set('ajax', '1');
                fetch(f.getAttribute('action'), {
                    method: 'POST', body: fd, cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    toast(res.flash || (res.ok ? 'Готово' : 'Не получилось'), !!res.ok);
                    if (res.ok) refreshRows();
                })
                .catch(function () { toast('Сеть не ответила — попробуйте ещё раз', false); });
            });
        });
    }
    bindModeration(document);
    bindTraceButtons(document);

    /* --- 1а. адрес обновления строк: те же поиск, статус, страница --- */
    function rowsUrl() {
        var pager = document.getElementById('nj602-pager'),
            page = pager ? (pager.getAttribute('data-page') || '1') : '1',
            params = new URLSearchParams(),
            form = document.getElementById('nj602-filters');

        if (form) {
            new FormData(form).forEach(function (v, k) {
                if (String(v) !== '') params.set(k, v);
            });
        }

        params.set('page', page);
        params.set('rows', '1');

        if (traceCode) params.set('code', traceCode);

        return '/nj602/admin?' + params.toString();
    }

    /* --- 1б. массовые действия: «отметить всё» → опубликовать выбранные --- */
    function rowChecks() {
        return Array.prototype.slice.call(document.querySelectorAll('.nj602-row-check'));
    }

    function updateSelection() {
        var boxes = rowChecks(),
            on = boxes.filter(function (b) { return b.checked; }),
            bulk = document.getElementById('nj602-bulk'),
            all = document.getElementById('nj602-check-all'),
            cnt = document.querySelector('[data-nj602-selected]');

        if (cnt) cnt.textContent = on.length;

        if (bulk) {
            bulk.classList.toggle('hidden', on.length === 0);
            bulk.classList.toggle('flex', on.length > 0);
        }

        if (all) {
            all.checked = boxes.length > 0 && on.length === boxes.length;
            all.indeterminate = on.length > 0 && on.length < boxes.length;
        }
    }

    document.addEventListener('change', function (e) {
        if (!e.target) return;

        if (e.target.id === 'nj602-check-all') {
            rowChecks().forEach(function (b) { b.checked = e.target.checked; });
            updateSelection();
            return;
        }

        if (e.target.classList && e.target.classList.contains('nj602-row-check')) updateSelection();
    });

    var uncheck = document.getElementById('nj602-uncheck');

    if (uncheck) {
        uncheck.addEventListener('click', function () {
            rowChecks().forEach(function (b) { b.checked = false; });
            updateSelection();
        });
    }

    var bulkForm = document.getElementById('nj602-bulk');

    if (bulkForm) {
        // Массовое отклонение — вторым нажатием, как одиночное: data-n2j-arm на
        // кнопке (app.js), первый клик сюда не доходит.
        bulkForm.querySelectorAll('button[type="submit"]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();

                var fd = new FormData(bulkForm);
                fd.set('act', btn.value);
                btn.disabled = true;

                fetch('/nj602/admin', { method: 'POST', body: fd, cache: 'no-store',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        toast(res.flash || (res.ok ? 'Готово' : 'Не получилось'), !!res.ok);
                        btn.disabled = false;

                        if (rejectLabel) rejectLabel.textContent = 'Отклонить выбранные';

                        if (res.ok) {
                            rowChecks().forEach(function (b) { b.checked = false; });
                            updateSelection();
                            refreshRows();
                        }
                    })
                    .catch(function () { toast('Сеть не ответила', false); btn.disabled = false; });
            });
        });
    }

    updateSelection();

    /* --- 1в. «Строк:» запоминаем в браузере (как на /nj593/admin) --- */
    (function () {
        var sel = document.getElementById('nj602-per'),
            form = document.getElementById('nj602-filters');

        if (!sel || !form || !window.localStorage) return;

        var KEY = 'n2j:per:/nj602/admin';
        var have = new URLSearchParams(location.search).has('per');

        if (!have) {
            var saved = null;

            try { saved = localStorage.getItem(KEY); } catch (e) { saved = null; }

            if (saved && ['20', '50', '100'].indexOf(saved) !== -1 && saved !== sel.value) {
                sel.value = saved;
                form.submit();
                return;
            }
        }

        sel.addEventListener('change', function () {
            try { localStorage.setItem(KEY, sel.value); } catch (e) { /* приватный режим */ }
            form.submit();
        });
    })();

    /* фоновое обновление строк (статус «собирается» меняется без перезагрузки) */
    setInterval(function () {
        if (document.hidden || document.querySelector('[data-n2j-arm].is-armed')) return;
        refreshRows();
    }, 20000);

    /* --- 2. mermaid (локальный vendor, без CDN) --- */
    var msrc = document.getElementById('nj602-mermaid-src');

    if (msrc && msrc.textContent.trim()) {
        var mbox = document.getElementById('nj602-mermaid-box');
        var src = msrc.textContent;

        var fallback = function () {
            if (mbox) {
                mbox.innerHTML = '';
                var pre = document.createElement('pre');
                pre.className = 'overflow-x-auto whitespace-nowrap rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-200';
                pre.textContent = src;
                mbox.appendChild(pre);
            }
        };

        var render = function () {
            try {
                mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'strict' });
                mermaid.render('nj602-mmd-' + Date.now(), src).then(function (res) {
                    if (mbox) mbox.innerHTML = res.svg;
                }).catch(fallback);
            } catch (e) { fallback(); }
        };

        if (window.mermaid) {
            render();
        } else {
            var s = document.createElement('script');
            s.src = '/assets/vendor/mermaid/mermaid.min.js';
            s.onload = render;
            s.onerror = fallback;
            document.head.appendChild(s);
        }
    }

    /* «Перегенерировать схему» (ИИ) прямо из строки модерации: тот же
       механизм, что на странице статьи и правки. */
    var csrfEl = document.querySelector('#nj602-bulk [name=csrf]');

    Array.prototype.forEach.call(document.querySelectorAll('[data-nj602-regen][data-code]'), function (btn) {
        btn.addEventListener('click', function () {
            if ( ! window.nj602RegenMermaid) { return; }

            window.nj602RegenMermaid(btn, {
                csrf: csrfEl ? csrfEl.value : '',
                id: btn.getAttribute('data-id'),
                code: btn.getAttribute('data-code')
            });
        });
    });

    /* «Починить схемы»: ставим задачу и поллим её состояние — внятный финал
       (сколько починено) вместо молчаливой кнопки. Схемы чинятся БЕЗ ИИ:
       воркер нормализует текст, текст статьи при этом не меняется. */
    var fixForm = document.getElementById('nj602-fix-form');

    if (fixForm) {
        var fixLine = document.getElementById('nj602-fix-line'),
            fixText = document.getElementById('nj602-fix-text'),
            fixErr = document.getElementById('nj602-fix-error'),
            fixGo = document.getElementById('nj602-fix-go'),
            fixTimer = null;

        function fixStop(text) {
            clearInterval(fixTimer);
            if (fixLine) fixLine.classList.add('hidden');
            if (fixGo) fixGo.disabled = false;
            if (text) {
                fixErr.textContent = text;
                fixErr.classList.remove('hidden');
            }
        }

        function fixPoll() {
            fetch('/nj602/admin/mermaid_fix_status', { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (st) {
                    if (st.status === 'done') {
                        var fixed = st.fixed || 0, skipped = st.skipped || 0;
                        clearInterval(fixTimer);
                        if (fixGo) { fixGo.disabled = false; fixGo.textContent = 'Готово: починено ' + fixed; }
                        if (fixText) fixText.textContent = 'Готово: починено ' + fixed + ', без изменений ' + skipped;
                        if (fixLine) fixLine.classList.remove('hidden');
                        setTimeout(function () { location.reload(); }, 1200);
                        return;
                    }

                    if (st.status === 'failed' || st.status === 'cancelled') {
                        fixStop(st.error || 'Починка не удалась — попробуйте ещё раз');
                        return;
                    }

                    if (fixText) {
                        fixText.textContent = (st.stage || 'Чиню схемы…') +
                            (st.elapsed_sec ? ' · ' + Math.round(st.elapsed_sec) + ' с' : '');
                    }
                })
                .catch(function () { /* сеть мигнула — просто пробуем снова */ });
        }

        fixForm.addEventListener('submit', function (e) {
            e.preventDefault();
            fixErr.classList.add('hidden');
            fixGo.disabled = true;
            fixLine.classList.remove('hidden');
            fixText.textContent = 'Чиню схемы…';

            fetch('/nj602/admin', { method: 'POST', body: new FormData(fixForm), cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) { fixStop(j.flash || 'Не удалось запустить починку'); return; }
                    if (fixText) fixText.textContent = j.flash || 'Чиню схемы…';
                    clearInterval(fixTimer);
                    fixTimer = setInterval(fixPoll, 3000);
                    fixPoll();
                })
                .catch(function () { fixStop('Сервер не ответил — попробуйте ещё раз'); });
        });
    }
})();
</script>
