<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * JS страницы /veclab (nj653_veclab).
 *
 * Вся работа одинаковая: собрать вход → POST в /veclab/api/<действие> →
 * показать общий живой статус-ряд → по его завершении дочитать
 * /veclab/api/status и нарисовать результат. Векторы на страницу не
 * приезжают: их десятки тысяч чисел, а человеку нужны близость и список.
 *
 * Общие помощники берём из window.N2JVision (esc / post / shrink) — он уже
 * подключён страницей: не плодим вторую реализацию уменьшения кадра.
 *
 * @var int $photo_mb  предел размера кадра, МБ
 * @var int $items_max предел элементов коллекции
 * @var int $bulk_max  сколько картинок принимаем одной пачкой
 */
?>
<script>
(function () {
    'use strict';

    var API = '/veclab/api';
    var MAX_MB = <?php echo (int) $photo_mb; ?>;
    var BULK_MAX = <?php echo (int) $bulk_max; ?>;
    var V = window.N2JVision || {};
    var esc = V.esc || function (t) { return String(t == null ? '' : t); };

    function post(url, body) {
        if (V.post) { return V.post(url, body); }

        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json(); });
    }

    function el(id) { return document.getElementById(id); }

    function say(id, text) {
        var box = el(id);
        if (box) { box.textContent = text || ''; }
    }

    /**
     * Кнопка на время работы.
     *
     * label === null — «тихий» режим: кнопка просто заблокирована и
     * выглядит как обычно. Он нужен, когда ход работы уже показывает живой
     * ряд: два крутящихся колеса с одинаковой подписью человек читает как
     * две разные операции и начинает искать вторую.
     */
    function busy(button, on, label) {
        if (!button) { return; }

        if (on) {
            if (!button.__idle) { button.__idle = button.innerHTML; }
            button.disabled = true;
            button.innerHTML = (label === null)
                ? button.__idle
                : '<i class="fas fa-spinner n2j-live-spin"></i>' + esc(label || 'Работаю…');
        } else {
            button.disabled = false;
            if (button.__idle) { button.innerHTML = button.__idle; }
        }
    }

    // ------------------------------------------------------------ вкладки

    (function tabs() {
        var buttons = Array.prototype.slice.call(document.querySelectorAll('.nj653-tab'));
        var active = 'is-on';

        function show(name) {
            buttons.forEach(function (b) {
                var on = b.getAttribute('data-tab') === name;
                active.split(' ').forEach(function (c) { b.classList.toggle(c, on); });
            });

            document.querySelectorAll('[data-panel]').forEach(function (p) {
                p.classList.toggle('hidden', p.getAttribute('data-panel') !== name);
            });
        }

        buttons.forEach(function (b) {
            b.addEventListener('click', function () { show(b.getAttribute('data-tab')); });
        });

        show((location.hash || '').replace('#', '') || 'search');
    })();

    // ----------------------------------------------------- выбор картинки

    /** Уменьшить кадр до ширины w: нужен и большой кадр, и крошечное превью. */
    /** Паспорт выбранного файла: имя, тип, вес, размеры оригинала. */
    function fileMeta(file, img) {
        return {
            name: String((file && file.name) || '').slice(0, 160),
            type: String((file && file.type) || ''),
            size: Number((file && file.size) || 0),
            width: Number((img && img.naturalWidth) || 0),
            height: Number((img && img.naturalHeight) || 0),
            modified: Number((file && file.lastModified) || 0)
        };
    }

    function shrinkTo(file, w, quality, cb) {
        var reader = new FileReader();

        reader.onload = function () {
            var img = new Image();

            img.onload = function () {
                var scale = img.width > w ? w / img.width : 1;
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(img.width * scale));
                canvas.height = Math.max(1, Math.round(img.height * scale));
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                // Второй аргумент — паспорт ИСХОДНИКА: после сжатия оригинала
                // уже нет, а в коллекции потом полезно знать, что это было.
                cb(canvas.toDataURL('image/jpeg', quality), fileMeta(file, img));
            };

            img.onerror = function () { cb(''); };
            img.src = reader.result;
        };

        reader.onerror = function () { cb(''); };
        reader.readAsDataURL(file);
    }

    /**
     * Один вход страницы: текстовое поле и необязательная картинка.
     * Превью (96 px) уходит на сервер вместе с кадром и остаётся в коллекции
     * вместо самого кадра — он удаляется сразу после подсчёта вектора.
     */
    function slot(id, sayId) {
        var text = el(id + '-text');
        var file = el(id + '-file');
        var box = el(id + '-preview');
        var img = el(id + '-img');
        var clear = el(id + '-clear');
        var state = { image: '', preview: '', meta: null };

        function reset() {
            state.image = '';
            state.preview = '';
            state.meta = null;
            if (file) { file.value = ''; }
            if (box) { box.classList.add('hidden'); box.classList.remove('inline-flex'); }
            if (img) { img.removeAttribute('src'); }
        }

        if (file) {
            file.addEventListener('change', function () {
                var f = file.files && file.files[0];

                if (!f) { return; }

                if (f.size > MAX_MB * 1024 * 1024) {
                    say(sayId, 'Файл больше ' + MAX_MB + ' МБ — выберите кадр поменьше.');
                    file.value = '';
                    return;
                }

                shrinkTo(f, 1400, 0.88, function (big, meta) {
                    if (!big) {
                        say(sayId, 'Не получилось прочитать файл — попробуйте другой кадр.');
                        return;
                    }

                    state.image = big;
                    state.meta = meta || null;

                    shrinkTo(f, 96, 0.7, function (small) {
                        state.preview = small || '';
                    });

                    if (img) { img.src = big; }
                    if (box) { box.classList.remove('hidden'); box.classList.add('inline-flex'); }
                    say(sayId, '');
                });
            });
        }

        if (clear) { clear.addEventListener('click', reset); }

        return {
            reset: reset,
            empty: function () {
                return (!text || text.value.trim() === '') && state.image === '';
            },
            body: function (suffix) {
                var out = {};
                out['text' + suffix] = text ? text.value.trim() : '';
                out['image' + suffix] = state.image;
                out['preview' + suffix] = state.preview;
                out['meta' + suffix] = state.meta || {};
                return out;
            }
        };
    }

    /**
     * Пачка картинок одним прогоном: выбрать десяток файлов и жать «Добавить»
     * десять раз — верный способ бросить дело на третьем. Подпись каждой —
     * имя файла, так в коллекции потом видно, что откуда.
     */
    function bulkSlot(id, sayId) {
        var input = el(id + '-bulk');
        var box = el(id + '-bulk-box');
        var list = el(id + '-bulk-list');
        var clear = el(id + '-bulk-clear');
        var chosen = [];

        function draw() {
            if (!box || !list) { return; }

            list.innerHTML = chosen.map(function (item) {
                return '<img src="' + esc(item.preview || item.image) + '" alt="' + esc(item.label)
                    + '" title="' + esc(item.label)
                    + '" class="h-10 w-10 rounded-lg border border-gray-200 object-cover dark:border-gray-700">';
            }).join('');

            box.classList.toggle('hidden', chosen.length === 0);
            box.classList.toggle('flex', chosen.length > 0);
        }

        function reset() {
            chosen = [];
            if (input) { input.value = ''; }
            draw();
        }

        if (input) {
            input.addEventListener('change', function () {
                var files = Array.prototype.slice.call(input.files || []);

                if (!files.length) { return; }

                var skipped = Math.max(0, files.length - BULK_MAX);
                files = files.slice(0, BULK_MAX);
                chosen = [];
                draw();

                var left = files.length;

                files.forEach(function (f, n) {
                    if (f.size > MAX_MB * 1024 * 1024) {
                        left -= 1;
                        skipped += 1;
                        return;
                    }

                    shrinkTo(f, 1400, 0.88, function (big, meta) {
                        if (!big) {
                            left -= 1;
                            skipped += 1;
                            return;
                        }

                        shrinkTo(f, 96, 0.7, function (small) {
                            chosen[n] = {
                                image: big,
                                preview: small || '',
                                meta: meta || {},
                                label: String(f.name || 'Картинка').replace(/\.[^.]+$/, '').slice(0, 200)
                            };
                            left -= 1;
                            draw();

                            if (left <= 0) {
                                say(sayId, 'Готово к добавлению: ' + count() + ' картинок'
                                    + (skipped ? ', пропущено ' + skipped : '') + '.');
                            }
                        });
                    });
                });
            });
        }

        if (clear) { clear.addEventListener('click', function () { reset(); say(sayId, ''); }); }

        function count() {
            return chosen.filter(Boolean).length;
        }

        return {
            reset: reset,
            count: count,
            items: function (common) {
                return chosen.filter(Boolean).map(function (item) {
                    return {
                        text: common || '',
                        image: item.image,
                        preview: item.preview,
                        meta: item.meta || {},
                        label: item.label
                    };
                });
            }
        };
    }

    // -------------------------------------------------------- ход прогона

    function mountLive(hostId, runId, title) {
        var host = el(hostId);
        var tpl = el('nj653-live-tpl');

        if (!host) { return null; }

        host.innerHTML = '';

        if (!tpl || !tpl.content) { return null; }

        var row = tpl.content.firstElementChild.cloneNode(true);
        row.setAttribute('data-live-url', API + '/status?id=' + Number(runId));
        row.setAttribute('data-live-keep', '1');

        var head = row.querySelector('[data-live-title]');
        if (head && title) { head.textContent = title; }

        host.appendChild(row);

        if (window.N2JLive && window.N2JLive.register) {
            window.N2JLive.register(row);
        }

        return row;
    }

    /** Дочитать прогон: задача закрывается чуть раньше, чем приёмка на сайте. */
    function finish(runId, tries, done, fail) {
        post(API + '/status', { id: runId }).then(function (data) {
            if (!data.ok) {
                fail(data.error || 'Не получилось узнать состояние прогона.');
                return;
            }

            if ((data.status === 'queued' || data.status === 'running') && tries < 40) {
                setTimeout(function () { finish(runId, tries + 1, done, fail); }, 1500);
                return;
            }

            if (data.status !== 'done') {
                fail(data.error || data.hint || 'Модель не ответила — попробуйте ещё раз.');
                return;
            }

            done(data.result || {});
        }).catch(function () {
            fail('Сеть недоступна — попробуйте ещё раз.');
        });
    }

    /**
     * Полный цикл: запрос → живой ряд → результат.
     * Ошибку всегда показываем словами: молчащая кнопка хуже отказа.
     */
    function run(opts) {
        busy(opts.button, true, opts.busy || 'Отправляю…');
        say(opts.say, '');

        function fail(why) {
            busy(opts.button, false);
            say(opts.say, why);
            var host = el(opts.live);
            if (host) { host.innerHTML = ''; }
        }

        post(API + '/' + opts.action, opts.body || {}).then(function (data) {
            if (!data.ok) {
                // Если прогон успел сохраниться, открываем его отдельную
                // страницу даже при сбое записи кадра/постановки в очередь:
                // там останутся запрос и точная причина отказа.
                if (typeof opts.started === 'function' && Number(data.run_id) > 0) {
                    opts.started(Number(data.run_id));
                    return;
                }

                fail(data.error || 'Запрос не принят.');
                return;
            }

            // Потоки с отдельной страницей результата сразу уходят туда после
            // постановки задачи, не возвращая мобильную клавиатуру к полю.
            if (typeof opts.started === 'function') {
                opts.started(Number(data.run_id));
                return;
            }

            var row = mountLive(opts.live, data.run_id, opts.title);

            // Ход работы показывает живой ряд; кнопка при нём молчит.
            busy(opts.button, true, row ? null : (opts.wait || 'Считаю…'));

            function done(result) {
                busy(opts.button, false);
                say(opts.say, '');
                opts.done(result);
            }

            if (!row) {
                finish(data.run_id, 0, done, fail);
                return;
            }

            row.addEventListener('n2j:live-done', function () {
                finish(data.run_id, 0, done, fail);
            });
        }).catch(function () {
            fail('Сеть недоступна — попробуйте ещё раз.');
        });
    }

    // ----------------------------------------------------------- рисуем

    var TONES = {
        emerald: 'text-emerald-600 dark:text-emerald-400',
        sky: 'text-blue-600 dark:text-blue-400',
        amber: 'text-amber-600 dark:text-amber-400',
        rose: 'text-rose-600 dark:text-rose-400',
        gray: 'text-gray-500 dark:text-gray-400'
    };

    function num(value, digits) {
        return Number(value || 0).toFixed(digits === undefined ? 4 : digits).replace('.', ',');
    }

    function tone(name) { return TONES[name] || TONES.gray; }

    function hitHtml(hit) {
        var icon = hit.kind === 'image' ? 'fa-image' : (hit.kind === 'both' ? 'fa-layer-group' : 'fa-align-left');
        var thumb = hit.preview
            ? '<img src="' + esc(hit.preview) + '" alt="Находка" class="h-12 w-12 shrink-0 rounded-md border border-gray-200 object-cover dark:border-gray-700">'
            : '<span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-400 dark:bg-gray-900"><i class="fas ' + icon + '"></i></span>';

        return '<div class="flex items-start gap-3 rounded-lg border border-gray-200 p-2.5 dark:border-gray-700">'
            + thumb
            + '<div class="min-w-0 flex-1">'
            + '<p class="truncate text-sm font-semibold text-gray-900 dark:text-white">' + esc(hit.label) + '</p>'
            + '<p class="text-xs ' + tone(hit.score.tone) + '">' + esc(hit.score.title)
            + ' <span class="font-mono text-gray-400">' + num(hit.score.value) + '</span></p>'
            + '</div></div>';
    }

    function card(title, inner) {
        return '<section class="n2j-card">'
            + '<h2 class="n2j-h3">' + esc(title) + '</h2>'
            + inner + '</section>';
    }

    function meta(result) {
        return '<p class="mt-1 text-xs text-gray-400">'
            + esc(result.model || 'модель не названа') + ' · ' + Number(result.dims || 0) + ' измерений · '
            + esc(result.dialect_title || 'формат не определён')
            + (result.elapsed_ms ? ' · ' + Math.round(Number(result.elapsed_ms)) + ' мс' : '')
            + '</p>';
    }

    function hitsHtml(result, title, empty) {
        var hits = result.hits || result.neighbors || [];

        if (!hits.length) {
            return card(title, meta(result)
                + '<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">' + esc(empty) + '</p>');
        }

        return card(title, meta(result)
            + '<div class="mt-3 grid gap-2 sm:grid-cols-2">'
            + hits.map(hitHtml).join('') + '</div>');
    }

    // ------------------------------------------------------------ поиск

    var slotQuery = slot('nj653-q', 'nj653-search-say');
    var slotAdd = slot('nj653-a', 'nj653-add-say');
    var slotOne = slot('nj653-c1', 'nj653-compare-say');
    var slotTwo = slot('nj653-c2', 'nj653-compare-say');

    var searchGo = el('nj653-search-go');

    if (searchGo) {
        searchGo.addEventListener('click', function () {
            if (slotQuery.empty()) {
                say('nj653-search-say', 'Напишите, что ищем, или приложите картинку.');
                return;
            }

            run({
                action: 'search',
                body: slotQuery.body(''),
                button: searchGo,
                say: 'nj653-search-say',
                live: 'nj653-search-live',
                title: 'Ищу по смыслу',
                wait: 'Ищу…',
                started: function (runId) {
                    if (document.activeElement && typeof document.activeElement.blur === 'function') {
                        document.activeElement.blur();
                    }
                    window.location.assign('/veclab/result/' + runId);
                },
                done: function (result) {
                    var box = el('nj653-hits');
                    if (box) {
                        box.innerHTML = hitsHtml(result, 'Что нашлось',
                            'Ничего похожего: в коллекции пока нет близких элементов.');
                    }
                }
            });
        });
    }

    // --------------------------------------------------------- коллекция

    function refreshItems() {
        post(API + '/items', {}).then(function (data) {
            if (!data.ok) { return; }

            var box = el('nj653-items');
            var count = el('nj653-mine-count');

            if (count) { count.textContent = Number(data.total || 0); }
            if (!box) { return; }

            if (!(data.items || []).length) {
                box.innerHTML = '<p class="text-sm text-gray-500 dark:text-gray-400" data-nj653-empty>'
                    + 'Пока пусто. Добавьте картинку или текст — или нажмите «Примеры».</p>';
                return;
            }

            box.innerHTML = data.items.map(function (item) {
                var icon = item.kind === 'image' ? 'fa-image'
                    : (item.kind === 'both' ? 'fa-layer-group' : 'fa-align-left');
                var thumb = item.preview
                    ? '<img src="' + esc(item.preview) + '" alt="Элемент коллекции" class="h-12 w-12 shrink-0 rounded-md border border-gray-200 object-cover dark:border-gray-700">'
                    : '<span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-400 dark:bg-gray-900"><i class="fas ' + icon + '"></i></span>';

                return '<div class="flex items-start gap-3 rounded-lg border border-gray-200 p-2.5 dark:border-gray-700" data-nj653-item="' + Number(item.id) + '">'
                    + thumb
                    + '<div class="min-w-0 flex-1">'
                    + '<p class="truncate text-sm font-semibold text-gray-900 dark:text-white">' + esc(item.label) + '</p>'
                    + '<p class="text-xs text-gray-500 dark:text-gray-400">' + esc(item.kind === 'image' ? 'картинка' : (item.kind === 'both' ? 'текст и картинка' : 'текст')) + (item.about ? ' · ' + esc(item.about) : '') + '</p>'
                    + '</div>'
                    + '<button type="button" data-nj653-forget="' + Number(item.id) + '" class="shrink-0 rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-500 hover:border-rose-400 hover:text-rose-600 dark:border-gray-600 dark:text-gray-300">убрать</button>'
                    + '</div>';
            }).join('');
        });
    }

    var addGo = el('nj653-add-go');
    var bulkAdd = bulkSlot('nj653-a', 'nj653-add-say');

    if (addGo) {
        addGo.addEventListener('click', function () {
            var many = bulkAdd.count();

            if (!many && slotAdd.empty()) {
                say('nj653-add-say', 'Нужен текст, картинка или и то и другое.');
                return;
            }

            var text = el('nj653-a-text');
            // При пачке текст поля (если он есть) становится общей подписью
            // ко всем картинкам: «фото с дачи» к десятку кадров сразу.
            var body = many
                ? { items: bulkAdd.items(text ? text.value.trim() : '') }
                : slotAdd.body('');

            run({
                action: 'add',
                body: body,
                button: addGo,
                say: 'nj653-add-say',
                live: 'nj653-add-live',
                title: many ? ('Считаю векторы: картинок ' + many) : 'Считаю вектор элемента',
                wait: 'Считаю…',
                done: function (result) {
                    slotAdd.reset();
                    bulkAdd.reset();
                    if (text) { text.value = ''; }

                    var skipped = Number(result.duplicates || 0);

                    if (skipped > 0) {
                        say('nj653-add-say', 'Добавлено: ' + Number(result.added || 0)
                            + ', пропущено как уже имеющееся: ' + skipped + '.');
                    }

                    refreshItems();

                    var box = el('nj653-hits');
                    if (box) {
                        box.innerHTML = hitsHtml(result, 'Элемент добавлен — вот его соседи',
                            'Пока это единственный элемент коллекции: сравнивать не с чем.');
                    }
                }
            });
        });
    }

    var seedGo = el('nj653-seed-go');

    if (seedGo) {
        seedGo.addEventListener('click', function () {
            run({
                action: 'seed',
                body: {},
                button: seedGo,
                say: 'nj653-add-say',
                live: 'nj653-add-live',
                title: 'Считаю векторы примеров',
                wait: 'Считаю…',
                done: function () {
                    refreshItems();
                    say('nj653-add-say', 'Примеры в коллекции — теперь поищите в ней словами.');
                }
            });
        });
    }

    document.addEventListener('click', function (e) {
        var button = e.target.closest ? e.target.closest('[data-nj653-forget]') : null;

        if (!button) { return; }

        button.disabled = true;

        post(API + '/forget', { id: Number(button.getAttribute('data-nj653-forget')) })
            .then(function (data) {
                if (!data.ok) {
                    button.disabled = false;
                    say('nj653-add-say', data.error || 'Не получилось убрать элемент.');
                    return;
                }

                refreshItems();
            });
    });

    var forgetAll = el('nj653-forget-all');

    if (forgetAll) {
        forgetAll.addEventListener('click', function () {
            N2J.confirm({ title: 'Убрать свои элементы?', message: 'Убрать из коллекции все свои элементы?', ok: 'Убрать', tone: 'danger' })
                .then(function (yes) {
                    if (!yes) { return; }

                    busy(forgetAll, true, 'Убираю…');

                    post(API + '/forget', { all: 1 }).then(function (data) {
                        busy(forgetAll, false);

                        if (!data.ok) {
                            say('nj653-add-say', data.error || 'Не получилось очистить коллекцию.');
                            return;
                        }

                        refreshItems();
                    });
            });
        });
    }

    // --------------------------------------------------------- сравнение

    var compareGo = el('nj653-compare-go');

    if (compareGo) {
        compareGo.addEventListener('click', function () {
            if (slotOne.empty() || slotTwo.empty()) {
                say('nj653-compare-say', 'Заполните оба входа: сравнивать нужно что-то с чем-то.');
                return;
            }

            var body = slotOne.body('');
            var second = slotTwo.body('2');

            Object.keys(second).forEach(function (k) { body[k] = second[k]; });

            run({
                action: 'compare',
                body: body,
                button: compareGo,
                say: 'nj653-compare-say',
                live: 'nj653-compare-live',
                title: 'Считаю два вектора',
                wait: 'Считаю…',
                done: function (result) {
                    var pair = result.pair || { value: 0, title: '', tone: 'gray' };
                    var kinds = (result.kinds || []).map(function (k) {
                        return k === 'image' ? 'картинка' : (k === 'both' ? 'текст и картинка' : 'текст');
                    }).join(' ↔ ');

                    var box = el('nj653-compare-out');

                    if (box) {
                        box.innerHTML = card('Близость пары', meta(result)
                            + '<p class="mt-3 font-mono text-3xl font-bold ' + tone(pair.tone) + '">' + num(pair.value) + '</p>'
                            + '<p class="mt-1 text-sm font-semibold ' + tone(pair.tone) + '">' + esc(pair.title) + '</p>'
                            + (kinds ? '<p class="mt-1 text-xs text-gray-400">' + esc(kinds) + '</p>' : '')
                            + '<p class="mt-2 text-xs text-gray-500 dark:text-gray-400">'
                            + 'Это косинус между двумя векторами модели. Сравнивайте пары между собой: '
                            + 'у каждой модели своя шкала.</p>'
                            // Частый вопрос: «почему одинаковые входы не дают
                            // ровно 1». Отвечаем прямо в карточке, а не ждём,
                            // пока человек решит, что модель врёт.
                            + (pair.value >= 0.99 && pair.value < 0.99995
                                ? '<p class="mt-1 text-xs text-gray-400">Ровной единицы не бывает даже у '
                                    + 'двух одинаковых входов: квантованная модель повторяет свой вектор '
                                    + 'с точностью около 0,999.</p>'
                                : ''));
                    }
                }
            });
        });
    }

    // ---------------------------------------------------- проверка модели

    var probeGo = el('nj653-probe-go');

    if (probeGo) {
        probeGo.addEventListener('click', function () {
            run({
                action: 'probe',
                body: {},
                button: probeGo,
                say: 'nj653-probe-say',
                live: 'nj653-probe-live',
                title: 'Проверяю модель',
                wait: 'Проверяю…',
                done: function (result) {
                    var last = el('nj653-probe-last');
                    if (last) { last.classList.add('hidden'); }

                    var verdict = result.verdict || {};
                    var passport = result.passport || {};
                    var html = '<p class="mt-2 text-sm font-semibold ' + tone(verdict.tone) + '">'
                        + esc(verdict.title || '') + '</p>'
                        + '<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">' + esc(verdict.note || '') + '</p>'
                        + meta(result);

                    html += '<ul class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-300">'
                        + (result.checks || []).map(function (check) {
                            var icons = {
                                ok: 'fa-circle-check text-emerald-500',
                                warn: 'fa-circle-exclamation text-amber-500',
                                fail: 'fa-circle-xmark text-rose-500',
                                skip: 'fa-circle-minus text-gray-300'
                            };
                            var icon = icons[check.level] || (check.ok === null ? icons.skip
                                : (check.ok ? icons.ok : icons.fail));

                            return '<li><i class="fas ' + icon + ' mr-1"></i>' + esc(check.title)
                                + (check.value === null ? '' : ' <span class="font-mono text-gray-400">' + num(check.value) + '</span>')
                                + (check.note ? ' <span class="text-gray-400">— ' + esc(check.note) + '</span>' : '')
                                + '</li>';
                        }).join('') + '</ul>';

                    if ((passport.models || []).length || passport.models_error) {
                        html += '<h3 class="n2j-h3 mt-4">Что загружено на сервере</h3>'
                            + '<p class="mt-1 text-xs text-gray-600 dark:text-gray-300">'
                            + ((passport.models || []).length
                                ? esc(passport.models.join(', '))
                                    + (passport.model_known ? '' : ' <span class="text-rose-500">— имени из EMBEDDING_MODEL среди них нет</span>')
                                : esc('список получить не удалось: ' + passport.models_error))
                            + '</p>'
                            + (passport.mode === 'caption'
                                ? '<p class="mt-1 text-xs text-amber-600 dark:text-amber-400">'
                                    + esc('Режим описания: ' + (passport.mode_note || '')) + '</p>'
                                : '')
                            + (passport.model_note
                                ? '<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">' + esc(passport.model_note) + '</p>'
                                : '')
                            + (passport.provider
                                ? '<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">'
                                    + esc('Подключение как у чата: провайдер ' + passport.provider
                                        + (passport.token_src ? ', токен из ' + passport.token_src : '')
                                        + (passport.proxy ? ', через прокси' : ''))
                                    + '</p>'
                                : '');
                    }

                    if ((passport.attempts || []).length) {
                        html += '<h3 class="n2j-h3 mt-4">Какие форматы запроса пробовали</h3>'
                            + '<ul class="mt-1 space-y-1 text-xs text-gray-600 dark:text-gray-300">'
                            + passport.attempts.map(function (a) {
                                return '<li><i class="fas ' + (a.ok ? 'fa-circle-check text-emerald-500' : 'fa-circle-xmark text-gray-300')
                                    + ' mr-1"></i>' + esc(a.title)
                                    + (a.ok ? ' <span class="font-mono text-gray-400">' + Number(a.ms) + ' мс, ' + Number(a.dims) + ' чисел</span>' : '')
                                    + (a.error ? ' <span class="text-gray-400">— ' + esc(a.error) + '</span>' : '')
                                    + '</li>';
                            }).join('') + '</ul>';
                    }

                    var box = el('nj653-probe-out');
                    if (box) { box.innerHTML = card('Проверка модели', html); }
                }
            });
        });
    }
})();
</script>
