<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Движок поиска «Похожие картинки» — один на все три варианта оформления.
 *
 * Задача (владелец, 08.10.2026): «нажимаю кнопку поиска — форма с кнопкой и
 * полями пропадает, вместо неё информация, что ищем, и индикатор процесса;
 * когда что-то находится, результаты появляются ниже плитками; страница при
 * этом не перезагружается; после отправки фокус НЕ возвращается в поле ввода —
 * на телефоне клавиатура закрывает результаты».
 *
 * Как это устроено:
 *   1. submit перехватывается, поле теряет фокус (blur — клавиатура на
 *      телефоне закрывается), блок формы скрывается, показывается карточка
 *      запроса из 654/panel;
 *   2. POST уходит на form.action (обычный /similar/find) с заголовками
 *      XMLHttpRequest + Accept: application/json — сервер отвечает
 *      `{ok:true,id,url}` вместо 303, и страница остаётся на месте
 *      (без JS форма пошлётся как раньше и откроет /similar/result/<id>);
 *   3. адрес меняется через history.replaceState на /similar/result/<id>:
 *      F5 и «поделиться ссылкой» показывают тот же результат;
 *   4. `/similar/api/status?q=<id>` опрашивается, пока задача в очереди или в
 *      работе; терминальный статус приносит сами карточки находок (`cards`),
 *      и они достраиваются в сетку из <template data-nj654-tile-tpl> — то есть
 *      той же разметкой, что и при серверной отрисовке;
 *   5. «Новый поиск» возвращает форму без перезагрузки (значения полей целы),
 *      «Вернуться к запросу» — то же самое после отказа.
 *
 * Никаких полос хода: ход работы показывают спиннер, название этапа и
 * секундомер — один неразрывный ряд (правило 4 дизайн-эталона).
 */
?>
<script>
window.NJ654 = (function () {
    'use strict';

    var form  = document.getElementById('nj654-form');
    var wrap  = document.querySelector('[data-nj654-form]');
    var panel = document.querySelector('[data-nj654-panel]');
    var out   = document.querySelector('[data-nj654-results]');

    // Страница без формы (гость) или без карточки запроса — движку нечего делать.
    if (!form || !wrap || !panel) { return null; }

    var TONE = {
        emerald: 'bg-emerald-500',
        sky: 'bg-blue-500',
        amber: 'bg-amber-500',
        gray: 'bg-gray-400'
    };

    var POLL_MS = 2500;      // опрос состояния
    var LIMIT_S = 300;       // столько ждём воркер, дальше честный отказ

    function el(sel, root) { return (root || document).querySelector(sel); }
    function show(node, on) { if (node) { node.classList.toggle('hidden', !on); } }
    function fmt(sec) {
        sec = Math.max(0, Math.floor(sec || 0));
        return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2);
    }

    var ui = {
        title:  el('[data-nj654-title]', panel),
        stage:  el('[data-nj654-stage]', panel),
        note:   el('[data-nj654-note]', panel),
        spin:   el('[data-nj654-spin]', panel),
        timer:  el('[data-nj654-elapsed]', panel),
        found:  el('[data-nj654-found]', panel),
        query:  el('[data-nj654-q]', panel),
        qimg:   el('[data-nj654-q-img]', panel),
        qphoto: el('[data-nj654-q-photo]', panel),
        qicon:  el('[data-nj654-q-icon]', panel),
        error:  el('[data-nj654-error]', panel),
        errtext: el('[data-nj654-error-text]', panel),
        reset:  el('[data-nj654-reset]', panel),
        fresh:  el('[data-nj654-new]', panel)
    };

    var res = {
        box:   out,
        grid:  el('[data-nj654-grid]', out),
        note:  el('[data-nj654-results-note]', out),
        empty: el('[data-nj654-empty]', out),
        tpl:   el('template[data-nj654-tile-tpl]', out)
    };

    var text  = el('#nj654-text', form);
    var image = el('#nj654-image', form);
    var chip  = document.querySelector('[data-nj654-file-name]');
    // Адреса: страница поиска и префикс результата — их подставляет карточка
    // запроса, чтобы history и без-JS-ссылки совпадали.
    var base   = panel.getAttribute('data-nj654-base') || '/similar';
    var result = panel.getAttribute('data-nj654-result') || '/similar/result/';

    function resultUrl(id) {
        return result + id;
    }

    var state = { id: 0, status: '', elapsed: 0, tick: null, poll: null, fails: 0, busy: false, preview: '' };

    // ------------------------------------------------------------ статус-ряд

    function paint(kind, title, stage, note) {
        if (ui.title) { ui.title.textContent = title; }
        if (ui.stage) { ui.stage.textContent = stage || ''; }
        if (ui.note)  { ui.note.textContent  = note  || ''; }

        if (ui.spin) {
            ui.spin.className = 'fas text-sm ' + (kind === 'busy' ? 'fa-spinner n2j-spin text-blue-500'
                : (kind === 'done' ? 'fa-circle-check text-emerald-500' : 'fa-triangle-exclamation text-rose-500'));
        }
    }

    function timer(on) {
        if (state.tick) { window.clearInterval(state.tick); state.tick = null; }
        if (!on) { return; }

        state.tick = window.setInterval(function () {
            state.elapsed += 1;
            if (ui.timer) { ui.timer.textContent = fmt(state.elapsed); }
        }, 1000);
    }

    function timerSet(sec) {
        state.elapsed = Math.max(0, Math.floor(sec || 0));
        if (ui.timer) { ui.timer.textContent = fmt(state.elapsed); }
    }

    // ------------------------------------------------------------ плитки

    function tile(card) {
        if (!res.tpl) { return null; }

        var node  = res.tpl.content.firstElementChild.cloneNode(true);
        var photo = el('[data-nj654-tile-photo]', node);
        var img   = el('[data-nj654-tile-img]', node);
        var blank = el('[data-nj654-tile-blank]', node);
        var title = el('[data-nj654-tile-title]', node);
        var score = el('[data-nj654-tile-score]', node);
        var say   = el('[data-nj654-tile-say]', node);
        var tone  = el('[data-nj654-tile-tone]', node);
        var url   = card.file || '';
        var name  = card.title || 'Без подписи';

        title.textContent = name;
        title.setAttribute('title', name);

        if (url) {
            img.src = url;
            img.alt = name;
            if (photo) {
                photo.setAttribute('data-nj654-zoom', url);
                photo.setAttribute('data-nj654-zoom-title', name);
            }
        }

        show(photo, !!url);
        show(blank, !url);

        score.textContent = card.score_text || '0,000';
        say.textContent   = card.say || 'непохоже';

        if (tone) { tone.className = 'h-1.5 w-1.5 shrink-0 rounded-full ' + (TONE[card.tone] || TONE.gray); }

        return node;
    }

    function render(cards) {
        cards = cards || [];

        if (!res.grid) { return; }

        res.grid.innerHTML = '';

        cards.forEach(function (card) {
            var node = tile(card);

            if (node) { res.grid.appendChild(node); }
        });

        show(res.grid, cards.length > 0);
        show(res.empty, cards.length === 0);
        show(res.box, true);

        if (res.note) {
            res.note.textContent = cards.length > 0
                ? 'Найдено: ' + cards.length + ' · ближе всего — сверху'
                : 'Ничего похожего не нашлось — банк не знает такой картинки.';
        }
    }

    /**
     * Показать находки, если они оказались ниже экрана. Прокрутку не навязываем:
     * человек, который уже листает страницу, сам решит, куда смотреть.
     */
    function nudge() {
        var head = res.note || res.grid;
        if (!head) { return; }

        var box = head.getBoundingClientRect();

        if (box.top > window.innerHeight - 80) {
            try { head.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            catch (e) { head.scrollIntoView(); }
        }
    }

    // ------------------------------------------------------------ запрос

    function syncQuery() {
        var body = new FormData(form);

        if (ui.query) { ui.query.textContent = String(body.get('text') || '').trim() || 'Поиск по картинке'; }

        var file = body.get('image');

        if (file && file.size && window.URL && window.URL.createObjectURL) {
            releasePreview();
            state.preview = window.URL.createObjectURL(file);

            if (ui.qimg) { ui.qimg.src = state.preview; }
            if (ui.qphoto) {
                ui.qphoto.setAttribute('data-nj654-zoom', state.preview);
                ui.qphoto.setAttribute('data-nj654-zoom-title', 'Картинка запроса');
            }

            show(ui.qphoto, true);
            show(ui.qicon, false);
        }
    }

    function releasePreview() {
        if (state.preview) {
            window.URL.revokeObjectURL(state.preview);
            state.preview = '';
        }
    }

    function openForm() {
        stopPolling();
        timer(false);
        state.busy = false;

        // Значения полей целы: форма была скрыта, а не перестроена.
        if (text && text.value.trim() === '' && ui.query) {
            var known = ui.query.textContent.trim();

            if (known && known !== 'Поиск по картинке') { text.value = known; }
        }

        show(panel, false);
        show(wrap, true);
        show(ui.error, false);

        if (window.history && window.history.replaceState && window.location.pathname !== base) {
            window.history.replaceState(null, '', base);
        }
    }

    function fail(message) {
        state.busy = false;
        stopPolling();
        timer(false);

        paint('fail', 'Поиск не выполнен', 'Воркер вернул отказ — можно попробовать ещё раз.', '');
        if (ui.errtext) { ui.errtext.textContent = message || 'поиск не выполнен'; }
        show(ui.error, true);
        show(ui.found, false);
    }

    function stopPolling() {
        if (state.poll) { window.clearTimeout(state.poll); state.poll = null; }
    }

    function apply(status) {
        if (!status || status.ok !== true) { return; }

        state.status = status.status || state.status;

        if (typeof status.elapsed_sec === 'number' && status.elapsed_sec > state.elapsed) {
            timerSet(status.elapsed_sec);
        }

        if (typeof status.found === 'number' && status.found > 0 && ui.found) {
            ui.found.textContent = 'найдено: ' + status.found;
            show(ui.found, true);
        }

        if (state.status === 'queued' || state.status === 'running') {
            timer(true);
            paint('busy', 'Ищу похожие изображения',
                status.stage || (state.status === 'queued'
                    ? 'Запрос ждёт свободного обработчика…'
                    : 'Сравниваю смысл запроса с картинками банка…'),
                state.status === 'queued' && state.elapsed > 30
                    ? 'Свободного обработчика пока нет — задача в очереди, ждём.'
                    : 'Результаты появятся здесь сами, обычно через несколько секунд.');
            return;
        }

        timer(false);

        if (state.status === 'done') {
            state.busy = false;
            paint('done', 'Готово',
                status.found > 0 ? 'Совпадения ниже — они уже на странице.' : 'Похожего в банке нет — попробуйте другое описание.',
                'Число рядом с подписью — близость: 1,000 — та же картинка.');
            render(status.cards);
            nudge();
            return;
        }

        fail(status.error);
    }

    function poll(soon) {
        stopPolling();

        if (!state.id) { return; }

        state.poll = window.setTimeout(function () {
            fetch('/similar/api/status?q=' + encodeURIComponent(state.id), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(function (response) {
                if (!response.ok) { throw new Error('http ' + response.status); }
                return response.json();
            }).then(function (status) {
                state.fails = 0;
                apply(status);

                if (state.busy) {
                    if (state.elapsed > LIMIT_S) {
                        fail('Воркер не ответил за ' + Math.round(LIMIT_S / 60) + ' минут — попробуйте ещё раз.');
                        return;
                    }

                    poll();
                }
            }).catch(function () {
                // Связь пропала — не пугаем человека сразу: три попытки, потом скажем.
                state.fails += 1;

                if (state.fails >= 3) {
                    fail('Нет связи с сервером — проверьте соединение и попробуйте ещё раз.');
                    return;
                }

                poll();
            });
        }, soon ? 400 : POLL_MS);
    }

    // ------------------------------------------------------------ запуск поиска

    function start(data) {
        state.busy = true;
        state.fails = 0;

        show(ui.error, false);
        show(ui.found, false);
        timerSet(0);
        paint('busy', 'Ищу похожие изображения', 'Отправляю запрос…', 'Обычно это занимает несколько секунд.');
        syncQuery();

        // Форма уступает место карточке запроса: кнопки и поля больше не нужны,
        // а скрытая форма сохраняет значения — их вернёт «Новый поиск».
        show(wrap, false);
        show(panel, true);

        fetch(form.action, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.text().then(function (body) {
                var json = null;

                try { json = JSON.parse(body); } catch (e) { json = null; }

                return { response: response, json: json };
            });
        }).then(function (got) {
            if (got.json && got.json.ok && got.json.id) {
                state.id = got.json.id;

                if (window.history && window.history.replaceState) {
                    // Адрес результата приходит в ответе целиком: вариант
                    // оформления выбран один (09.10.2026), переключателя нет,
                    // и дописывать к ссылке больше нечего — лишний параметр
                    // только портит «поделиться».
                    window.history.replaceState(null, '', got.json.url || resultUrl(state.id));
                }

                poll(true);
                return;
            }

            if (got.json && got.json.error) { fail(got.json.error); return; }

            // Ответ сервера без JSON (старая сессия, выключенный JS-путь):
            // честно доезжаем до канонической страницы результата.
            if (got.response.redirected) { window.location.assign(got.response.url); return; }

            fail('Не удалось отправить запрос — попробуйте ещё раз.');
        }).catch(function () {
            fail('Нет связи с сервером — проверьте соединение и попробуйте ещё раз.');
        });
    }

    form.addEventListener('submit', function (event) {
        if (state.busy) { event.preventDefault(); return; }

        var data = new FormData(form);
        var body = String(data.get('text') || '').trim();
        var file = data.get('image');

        // Пустой запрос — не наша забота: обычная отправка покажет подсказку
        // сервера, а форма останется на месте.
        if (!body && (!file || !file.size)) { return; }

        event.preventDefault();

        // Фокус снимаем ЯВНО и больше никуда не возвращаем: на телефоне это
        // закрывает клавиатуру, и находки видно, а не поле ввода.
        if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); }

        start(data);
    });

    if (ui.fresh) {
        ui.fresh.addEventListener('click', function (event) {
            event.preventDefault();
            openForm();
        });
    }

    if (ui.reset) {
        ui.reset.addEventListener('click', function (event) {
            event.preventDefault();
            openForm();
        });
    }

    if (image) {
        image.addEventListener('change', function () {
            var file = image.files && image.files[0];

            if (chip) {
                chip.textContent = file ? file.name : '';
                show(chip, !!file);
            }
        });
    }

    // ------------------------------------------------------------ вход на страницу

    // Страница с уже заведённым поиском (F5, ссылка, /similar/result/<id>):
    // досматриваем его тем же движком, пока задача не завершится.
    state.id = parseInt(panel.getAttribute('data-nj654-id') || '0', 10) || 0;
    state.status = panel.getAttribute('data-nj654-status') || '';

    if (state.id > 0 && (state.status === 'queued' || state.status === 'running')) {
        state.busy = true;
        timerSet(parseInt(panel.getAttribute('data-nj654-elapsed') || '0', 10) || 0);
        timer(true);
        poll(true);
    }

    return { openForm: openForm, tile: tile, tone: TONE };
})();
</script>
