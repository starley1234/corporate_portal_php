<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JS общего блока «фото → vision-модель»: window.N2JVision (один раз на страницу).
 *
 * Делает ровно то, что одинаково у всех таких сервисов:
 *   1. берёт файл (кнопка, камера телефона, перетаскивание) и уменьшает кадр
 *      в браузере до 1400 px по ширине — трафик и токены модели стоят денег,
 *      а деталей апскейл не добавляет;
 *   2. отправляет его в <api>/photo, монтирует общий живой статус-ряд;
 *   3. после терминального статуса дочитывает <api>/status и зовёт onDone
 *      с разобранным результатом (страница рисует его по-своему).
 *
 * Страница подключает так:
 *   N2JVision.mount('nj650', { onDone: function (data) { … } });
 *
 * Полос прогресса нет: ход работы показывает статус-ряд (спиннер + этап +
 * секундомер) — правило платформы.
 */
if ( ! empty($GLOBALS['__n2j_photo_capture_js']))
{
    return;
}

$GLOBALS['__n2j_photo_capture_js'] = TRUE;
?>
<script>
window.N2JVision = (function () {
    'use strict';

    var MAX_W = 1400;

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = text === null || text === undefined ? '' : String(text);
        return d.innerHTML;
    }

    /** Уменьшить кадр в браузере: canvas → data URL (JPEG, качество 0.88). */
    function shrink(file, cb) {
        var reader = new FileReader();

        reader.onload = function () {
            var img = new Image();

            img.onload = function () {
                var scale = img.width > MAX_W ? MAX_W / img.width : 1;
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(img.width * scale);
                canvas.height = Math.round(img.height * scale);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                cb(canvas.toDataURL('image/jpeg', 0.88));
            };

            img.onerror = function () { cb(''); };
            img.src = reader.result;
        };

        reader.onerror = function () { cb(''); };
        reader.readAsDataURL(file);
    }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(data || {})
        }).then(function (r) {
            return r.json().catch(function () {
                return { ok: false, error: 'Сервер ответил не JSON (код ' + r.status + ')' };
            });
        });
    }

    /** Живой ряд из шаблона блока; null — шаблона нет (тогда просто опрос). */
    function mountLive(id, host, url, title) {
        var tpl = document.querySelector('[data-vision-live-tpl="' + id + '"]');

        if (!tpl || !tpl.content || !host) { return null; }

        var el = tpl.content.firstElementChild.cloneNode(true);
        el.setAttribute('data-live-url', url);
        el.setAttribute('data-live-keep', '1');

        if (title) {
            var box = el.querySelector('[data-live-title]');
            if (box) { box.textContent = title; }
        }

        host.innerHTML = '';
        host.appendChild(el);

        if (window.N2JLive && window.N2JLive.register) {
            window.N2JLive.register(el);
        }

        return el;
    }

    function mount(id, options) {
        var opts = options || {};
        var root = document.querySelector('[data-vision-root="' + id + '"]');

        if (!root) { return null; }

        var api = root.getAttribute('data-vision-api') || '';
        var liveTitle = root.getAttribute('data-vision-live') || 'Смотрю фото';
        var maxMb = Number(root.getAttribute('data-vision-mb') || 8);

        var file = root.querySelector('[data-vision-file]');
        var drop = root.querySelector('[data-vision-drop]');
        var preview = root.querySelector('[data-vision-preview]');
        var img = root.querySelector('[data-vision-img]');
        var clear = root.querySelector('[data-vision-clear]');
        var send = root.querySelector('[data-vision-send]');
        var say = root.querySelector('[data-vision-say]');
        var liveHost = root.querySelector('[data-vision-live-host]');

        var photo = null;
        var busy = false;

        function tell(text) {
            if (say) { say.textContent = text || ''; }
        }

        function setBusy(on, label) {
            busy = on;

            if (!send) { return; }

            if (on) {
                if (!send.__idle) { send.__idle = send.innerHTML; }
                send.disabled = true;
                send.innerHTML = '<i class="fas fa-circle-notch n2j-spin"></i><span>' + esc(label || 'Работаю…') + '</span>';
            } else {
                send.disabled = false;
                if (send.__idle) { send.innerHTML = send.__idle; }
            }
        }

        function reset() {
            photo = null;
            if (file) { file.value = ''; }
            if (preview) {
                preview.classList.add('hidden');
                preview.classList.remove('flex');
            }
            if (img) { img.removeAttribute('src'); }
            tell('');
        }

        function take(f) {
            if (!f) { return; }

            if (f.size > maxMb * 1024 * 1024) {
                tell('Файл больше ' + maxMb + ' МБ — снимите кадр поменьше.');
                return;
            }

            shrink(f, function (dataUrl) {
                if (!dataUrl) {
                    tell('Не получилось прочитать файл — попробуйте другой кадр.');
                    return;
                }

                photo = dataUrl;

                if (img) { img.src = dataUrl; }
                if (preview) {
                    preview.classList.remove('hidden');
                    preview.classList.add('flex');
                }

                tell('Кадр готов — нажмите кнопку.');

                if (typeof opts.onPick === 'function') { opts.onPick(dataUrl); }
                if (opts.auto) { start(); }
            });
        }

        /** Дочитать результат: задача может закрыться чуть раньше приёмки. */
        function finish(runId, tries, live) {
            if (live && live.status && live.status !== 'done' && tries === 0) {
                // ряд закрылся отказом — всё равно спросим статус: там причина
                tries = 0;
            }

            post(api + '/status', { id: runId }).then(function (data) {
                if (!data.ok) {
                    setBusy(false);
                    tell(data.error || 'Не получилось узнать статус.');
                    return;
                }

                if ((data.status === 'queued' || data.status === 'running') && tries < 10) {
                    setTimeout(function () { finish(runId, tries + 1, live); }, 1500);
                    return;
                }

                setBusy(false);

                if (data.status !== 'done') {
                    tell(data.error || data.hint || 'Не удалось разобрать фото — попробуйте другой кадр.');
                    if (typeof opts.onError === 'function') { opts.onError(data); }
                    return;
                }

                tell('');

                if (typeof opts.onDone === 'function') { opts.onDone(data.result || {}, data); }
            }).catch(function () {
                setBusy(false);
                tell('Сеть недоступна — попробуйте ещё раз.');
            });
        }

        function start() {
            if (busy) { return; }

            if (!photo) {
                tell('Сначала приложите фото.');
                return;
            }

            setBusy(true, 'Отправляю…');
            tell('');

            var body = { image: photo };

            if (typeof opts.extra === 'function') {
                var more = opts.extra() || {};
                Object.keys(more).forEach(function (k) { body[k] = more[k]; });
            }

            post(api + '/photo', body).then(function (data) {
                if (!data.ok) {
                    setBusy(false);

                    // Гейт создания (ключ 'auth' у модуля): гость получает 401
                    // и адрес входа — уводим на login, как /nj530/api/shorten.
                    if (data.login) { window.location = data.login; return; }

                    tell(data.error || 'Не получилось отправить фото.');
                    return;
                }

                setBusy(true, 'Смотрю фото…');

                var el = mountLive(id, liveHost, api + '/status?id=' + Number(data.run_id), liveTitle);

                if (!el) {
                    finish(data.run_id, 0, null);
                    return;
                }

                el.addEventListener('n2j:live-done', function (e) {
                    finish(data.run_id, 0, e.detail);
                });
            }).catch(function () {
                setBusy(false);
                tell('Сеть недоступна — попробуйте ещё раз.');
            });
        }

        if (file) {
            file.addEventListener('change', function () {
                take(file.files && file.files[0]);
            });
        }

        if (clear) { clear.addEventListener('click', reset); }
        if (send) { send.addEventListener('click', start); }

        if (drop) {
            ['dragenter', 'dragover'].forEach(function (name) {
                drop.addEventListener(name, function (e) {
                    e.preventDefault();
                    drop.classList.add('border-blue-500');
                });
            });

            ['dragleave', 'drop'].forEach(function (name) {
                drop.addEventListener(name, function (e) {
                    e.preventDefault();
                    drop.classList.remove('border-blue-500');
                });
            });

            drop.addEventListener('drop', function (e) {
                var files = e.dataTransfer && e.dataTransfer.files;
                if (files && files.length) { take(files[0]); }
            });
        }

        return { start: start, reset: reset, tell: tell, esc: esc };
    }

    return { mount: mount, esc: esc, shrink: shrink, post: post };
})();
</script>
