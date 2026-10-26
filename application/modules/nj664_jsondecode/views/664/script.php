<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!--
    nj664_jsondecode: страница /json-decode.

    Разбор живёт в браузере: JSON никуда не уходит, результат появляется на
    каждое нажатие. Вкладки «PHP Array» и «json_decode()» — исключение: их
    считает сервер (там нужен настоящий print_r и генерация кода), поэтому
    ответ по одному и тому же входу запоминается в кэше.
-->
<script>
(function () {
    'use strict';

    var input   = document.getElementById('nj664-input');
    var out     = document.getElementById('nj664-out');
    var raw     = document.getElementById('nj664-out-raw');
    var status  = document.getElementById('nj664-status');
    var modeBox = document.getElementById('nj664-mode');
    var fileBox = document.getElementById('nj664-file');
    var urlRow  = document.getElementById('nj664-url-row');
    var urlBox  = document.getElementById('nj664-url');
    var sizeBox = document.querySelector('[data-nj664-size]');
    var form    = document.getElementById('nj664-form');

    if (!input || !out || !status) return;

    var mode   = (modeBox && modeBox.value) || 'json';
    var timer  = null;
    var cache  = {};          // вход → {json, min, array, php}
    var parsed = null;        // разобранные данные последнего удачного входа

    // ------------------------------------------------------------- вход

    function normalize(text) {
        return text.replace(/\r\n?/g, '\n').trim();
    }

    // Как в PHP: сначала пытаемся понять строку целиком как JSON-строку,
    // иначе снимаем экранирование по одному символу.
    function unslash(text) {
        try {
            var s = JSON.parse('"' + text.replace(/"/g, '\\"') + '"');
            if (typeof s === 'string') return s;
        } catch (e) { /* не строка — снимаем по одному символу */ }
        return text.replace(/\\(.)/g, '$1');
    }

    function urlDecode(text) {
        try { return decodeURIComponent(text.replace(/\+/g, ' ')); }
        catch (e) { return ''; }
    }

    function htmlDecode(text) {
        var el = document.createElement('textarea');
        el.innerHTML = text.replace(/</g, '&lt;');
        return el.value;
    }

    function unquote(text) {
        var first = text.charAt(0);
        var last  = text.charAt(text.length - 1);
        return ((first === '"' || first === "'") && last === first)
            ? text.substring(1, text.length - 1)
            : '';
    }

    var LABELS = {
        raw:     'как есть — это уже JSON',
        slashes: 'снято экранирование (\\" и \\n)',
        url:     'раскодировано из URL (%7B%22…)',
        html:    'раскодированы HTML-сущности (&quot;)',
        quoted:  'раскрыта строка в кавычках'
    };

    // Порядок проб — ровно как в Service_Nj664_Decode: «как есть» первый,
    // чтобы честный JSON не портить никакими догадками.
    function decode(text) {
        var tries = [
            ['raw',     text],
            ['slashes', text.indexOf('\\') === -1 ? '' : unslash(text)],
            ['url',     text.indexOf('%') === -1 ? '' : urlDecode(text)],
            ['html',    text.indexOf('&') === -1 ? '' : htmlDecode(text)],
            ['quoted',  unquote(text)]
        ];

        for (var i = 0; i < tries.length; i++) {
            var body = tries[i][1];
            if (!body || (i > 0 && body === text)) continue;
            try {
                return { ok: true, data: JSON.parse(body), mode: tries[i][0], text: body };
            } catch (e) { /* пробуем следующий вариант */ }
        }

        try {
            JSON.parse(text);
        } catch (e) {
            return { ok: false, error: e.message };
        }

        return { ok: false, error: 'JSON не разобран' };
    }

    // Строка и позиция из текста ошибки: V8 умеет и «line X column Y»,
    // и просто «at position N».
    function spot(message, text) {
        var m = /line (\d+) column (\d+)/.exec(message) || /position (\d+)/.exec(message);
        if (!m) return { line: 0, col: 0 };

        if (m.length === 3) return { line: +m[1], col: +m[2] };

        var pos = +m[1];
        var before = text.substring(0, pos).split('\n');

        return { line: before.length, col: before[before.length - 1].length + 1 };
    }

    // ------------------------------------------------------------- вывод

    function setTabs() {
        var tabs = document.querySelectorAll('[data-nj664-tab]');

        for (var i = 0; i < tabs.length; i++) {
            var on = tabs[i].getAttribute('data-nj664-tab') === mode;

            tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
            tabs[i].classList.toggle('is-active', on);
        }

        if (modeBox) modeBox.value = mode;
    }

    function say(html, kind) {
        status.className = 'mt-2 min-h-[2rem] text-xs';

        if (kind === 'ok') {
            status.innerHTML = '<p class="flex flex-wrap items-center gap-x-3 gap-y-1">'
                + '<span class="inline-flex items-center gap-1 font-semibold text-emerald-600 dark:text-emerald-400"><i class="fas fa-circle-check"></i> Разобрано</span>'
                + html + '</p>';
        } else if (kind === 'err') {
            status.innerHTML = '<p class="flex flex-wrap items-center gap-x-2 font-medium text-rose-600 dark:text-rose-400">'
                + '<i class="fas fa-triangle-exclamation"></i>' + html + '</p>';
        } else {
            status.innerHTML = '<p class="text-gray-400">' + html + '</p>';
        }
    }

    function statsLine(data) {
        var nodes = 0, keys = 0;
        var walk = function (value, depth) {
            nodes++;
            if (value === null || typeof value !== 'object') return depth;

            var max = depth;

            for (var k in value) {
                if (!Object.prototype.hasOwnProperty.call(value, k)) continue;
                keys++;

                if (value[k] !== null && typeof value[k] === 'object') {
                    max = Math.max(max, walk(value[k], depth + 1));
                } else {
                    nodes++;
                }
            }

            return max;
        };

        var depth = walk(data, 1);
        var root  = (data !== null && typeof data === 'object')
            ? (Array.isArray(data) ? 'массив (список)' : 'объект')
            : (typeof data === 'number' ? 'число' : typeof data);

        return '<span class="text-gray-400">корень: ' + root + '</span>'
            + '<span class="text-gray-400">узлов: ' + nodes + '</span>'
            + '<span class="text-gray-400">ключей: ' + keys + '</span>'
            + '<span class="text-gray-400">глубина: ' + depth + '</span>';
    }

    function show(text) {
        out.textContent = text;
        if (raw) raw.textContent = text;
    }

    function current() {
        return raw ? raw.textContent : out.textContent;
    }

    // --------------------------------------------------------- сервер

    function fromServer(text, want, done) {
        var hit = cache[text];

        if (hit && hit[want] !== undefined) {
            done(hit[want]);
            return;
        }

        var body = JSON.stringify({ input: text, mode: 'all' });

        fetch('/nj664/api/decode', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=UTF-8' },
            body: body
        }).then(function (r) {
            return r.json();
        }).then(function (data) {
            if (!data.ok) {
                done(null, data.error || 'Сервер не смог разобрать JSON');
                return;
            }

            cache[text] = {
                json:  data.result,
                min:   data.minified,
                array: data.php_array,
                php:   data.php_code
            };

            done(cache[text][want]);
        })['catch'](function () {
            done(null, 'Сервер недоступен — вкладки PHP считаются на нём');
        });
    }

    // ------------------------------------------------------------- цикл

    function run() {
        var text = normalize(input.value);

        if (sizeBox) {
            sizeBox.textContent = text
                ? (new Blob([text]).size + ' байт')
                : '';
        }

        if (!text) {
            parsed = null;
            show('');
            say('Разбор живой: начните вставлять — результат появится сразу.', 'idle');
            return;
        }

        var r = decode(text);

        if (!r.ok) {
            parsed = null;
            show('');

            var at = spot(r.error, text);
            var where = at.line ? '<span class="text-gray-500 dark:text-gray-400">— строка ' + at.line + ', позиция ' + at.col + '</span>' : '';

            say('<span>' + r.error.replace(/[<>&]/g, '') + '</span>' + where, 'err');

            return;
        }

        parsed = r.data;

        if (mode === 'array' || mode === 'php') {
            fromServer(text, mode, function (body, error) {
                if (error) {
                    show('');
                    say('<span>' + error + '</span>', 'err');
                    return;
                }

                show(body || '');
                say('<span>' + LABELS[r.mode] + '</span>' + statsLine(r.data), 'ok');
            });

            return;
        }

        show(mode === 'min' ? JSON.stringify(r.data) : JSON.stringify(r.data, null, 2));
        say('<span>' + LABELS[r.mode] + '</span>' + statsLine(r.data), 'ok');
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(run, 120);
    }

    function setMode(next) {
        mode = next;
        setTabs();
        run();
    }

    // ------------------------------------------------------------- пульт

    input.addEventListener('input', schedule);
    input.addEventListener('paste', function () { setTimeout(schedule, 0); });

    var tabs = document.querySelectorAll('[data-nj664-tab]');

    for (var i = 0; i < tabs.length; i++) {
        tabs[i].addEventListener('click', function () {
            setMode(this.getAttribute('data-nj664-tab'));
        });
    }

    var buttons = document.querySelectorAll('[data-nj664-act]');

    for (var j = 0; j < buttons.length; j++) {
        buttons[j].addEventListener('click', function () {
            var act = this.getAttribute('data-nj664-act');

            if (act === 'clear') {
                input.value = '';
                run();
                input.focus();
                return;
            }

            if (act === 'sample') {
                fetch('/nj664/api/sample').then(function (r) { return r.json(); }).then(function (data) {
                    input.value = data.input || '';
                    run();
                });
                return;
            }

            if (act === 'open') {
                if (fileBox) fileBox.click();
                return;
            }

            if (act === 'url') {
                if (!urlRow) return;

                // Показываем строку адреса; повторный клик её убирает
                var shown = urlRow.classList.toggle('hidden');
                urlRow.classList.toggle('flex', !shown);

                if (!shown && urlBox) urlBox.focus();
                return;
            }

            if (act === 'load') {
                if (!urlBox) return;

                var href = urlBox.value.trim();

                if (!href) return;

                say('Тянем ' + href.replace(/[<>&]/g, '') + ' …', 'idle');

                // Тянет браузер: сервер по чужим адресам не ходит (иначе любую
                // ссылку можно превратить в запрос во внутреннюю сеть сайта).
                fetch(href, { headers: { Accept: 'application/json, text/plain' } })
                    .then(function (r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.text();
                    })
                    .then(function (text) {
                        input.value = text;
                        run();
                    })
                    ['catch'](function (error) {
                        say('<span>Не получилось: ' + String(error.message).replace(/[<>&]/g, '')
                            + '. Чужой сайт должен разрешать запрос из браузера (CORS).</span>', 'err');
                    });
                return;
            }

            if (act === 'paste') {
                if (navigator.clipboard && navigator.clipboard.readText) {
                    navigator.clipboard.readText().then(function (text) {
                        input.value = text || '';
                        run();
                    });
                }
                return;
            }

            if (act === 'copy') {
                var text = current();

                if (!text) return;

                var done = function () {
                    if (window.N2J && N2J.toast) N2J.toast('Скопировано');
                };

                if (window.N2J && N2J.copyText) {
                    N2J.copyText(text).then(done, function () { });
                } else if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () { });
                }
                return;
            }

            if (act === 'save') {
                var body = current();
                if (!body) return;

                var names = { json: 'decoded.json', min: 'decoded.min.json', array: 'decoded.txt', php: 'decoded.php' };
                var blob  = new Blob([body], { type: 'text/plain;charset=utf-8' });
                var link  = document.createElement('a');

                link.href     = URL.createObjectURL(blob);
                link.download = names[mode] || 'decoded.txt';
                link.click();

                setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
                return;
            }

            if (act === 'full') {
                var box = out.parentNode;
                var on  = box.classList.toggle('nj664-full');

                out.classList.toggle('h-80', !on);
                out.classList.toggle('h-[75vh]', on);

                this.innerHTML = on
                    ? '<i class="fas fa-compress"></i> Экран'
                    : '<i class="fas fa-expand"></i> Экран';
                return;
            }
        });
    }

    if (urlBox) {
        urlBox.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;

            event.preventDefault();

            var load = document.querySelector('[data-nj664-act="load"]');

            if (load) load.click();
        });
    }

    if (fileBox) {
        fileBox.addEventListener('change', function () {
            var file = this.files && this.files[0];
            if (!file) return;

            var reader = new FileReader();

            reader.onload = function () {
                input.value = String(reader.result || '');
                run();
            };

            reader.readAsText(file);
            this.value = '';
        });
    }

    // Enter внутри поля не должен отправлять форму — разбор уже живой
    if (form) {
        form.addEventListener('submit', function (event) {
            if (input.value.length > 100000) return;   // длинный текст пусть уйдёт POST
            event.preventDefault();
            run();
        });
    }

    setTabs();
    if (input.value) run();
})();
</script>
