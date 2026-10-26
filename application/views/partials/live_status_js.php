<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JS живого статус-ряда (один раз на страницу).
 *
 * Подключается ВСЕГДА — даже если сейчас ни один ряд не отрисован: кнопка
 * «Собрать» монтирует ряд сама, из <template data-prl-live-tpl="<code>">,
 * который страница рендерит для каждого радара/потока. Так пользователь видит
 * «идёт сбор» СРАЗУ после нажатия, без перезагрузки страницы (жалоба владельца:
 * «при нажатии Собрать я ожидал появления <div class="w-full"> с информацией о
 * запущенном процессе — почему я его увидел только после перезагрузки?»).
 *
 * Публичный API: window.PRLLive.mount(li, code) — вставить ряд из шаблона.
 */
if ( ! empty($GLOBALS['__prl_live_status_js']))
{
    return;
}

$GLOBALS['__prl_live_status_js'] = TRUE;
?>
<script>
/* Живой статус-ряд: один интервал на страницу, каждый блок опрашивает свой
   thin-endpoint. Завершение — «Готово ✓» + одна перезагрузка (данные целостные). */
window.PRLLive = (function () {
    'use strict';

    var blocks = [];
    var reloading = false;
    var failures = 0;

    // Домены в этапах — ссылками: источник, который воркер успел найти, полезен
    // и после остановки сбора. Текст сначала экранируется (XSS закрыт), ссылку
    // собираем из строгого совпадения.
    function linkify(text) {
        var d = document.createElement('div');
        d.textContent = String(text == null ? '' : text);
        return d.innerHTML.replace(
            /((?:[a-z0-9-]+\.)+(?:ru|com|net|org|io|info|su|biz|pro|tech|site|online)(?:\/[^\s<]*)?)/gi,
            function (m) {
                var url = /^https?:\/\//i.test(m) ? m : 'https://' + m;
                return '<a href="' + url + '" target="_blank" rel="noopener nofollow" '
                    + 'class="text-blue-600 hover:underline dark:text-blue-400">' + m + '</a>';
            });
    }

    function fmt(sec) {
        sec = Math.max(0, Math.floor(sec));
        return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2);
    }

    // Регистрация блока: тик секундомера + опрос endpoint'а.
    // Терминальные блоки (страница уже отрисована после завершения) не
    // регистрируем: иначе «done» из endpoint'а запускал бы перезагрузку по кругу.
    function register(el) {
        if (!el || el.__prlReg || el.hasAttribute('data-live-terminal')) return el;
        el.__prlReg = true;
        el.__sec = parseInt(el.getAttribute('data-live-elapsed') || '0', 10) || 0;
        el.__last = null;

        var box = el.querySelector('[data-live-elapsed]');
        if (box) box.textContent = fmt(el.__sec);

        blocks.push(el);
        return el;
    }

    // Вставить ряд «идёт сбор» из шаблона страницы (сразу после нажатия «Собрать»).
    function mount(host, code) {
        if (!host || !code) return null;

        var tpl = document.querySelector('[data-prl-live-tpl="' + code + '"]');
        if (!tpl || !tpl.content) return null;

        // повторный клик «Собрать» не должен плодить ряды
        var old = host.querySelector('[data-prl-live]');
        if (old) return old;

        var el = tpl.content.firstElementChild.cloneNode(true);
        host.appendChild(el);
        register(el);
        return el;
    }

    function finish(b, s) {
        b.__done = true;

        var icon  = b.querySelector('i.fas');
        var title = b.querySelector('[data-live-title]');
        var stage = b.querySelector('[data-live-stage]');
        var box   = b.querySelector('[data-live-elapsed]');
        var done  = (s.status === 'done');

        if (box) box.textContent = fmt(b.__sec);

        // Финал должен быть ЧИСТЫМ: убираем всё, что относится к «идёт» —
        // кнопку остановки, подпись «обновится сама»; список этапов остаётся
        // только у завершённой плашки (см. partials/last_status).
        var stop = b.querySelector('[data-live-stop]');
        if (stop) stop.parentNode.removeChild(stop);

        // Модуль, который продолжает работу сам (data-live-keep, см. /label),
        // перезагрузку не получает — ему нельзя обещать «обновляю страницу».
        var keep = b.hasAttribute('data-live-keep');

        var note = b.querySelector('[data-live-note]');
        if (note) note.textContent = keep
            ? (done ? 'Готово — продолжаю на странице.' : 'Задача завершилась — смотрю результат.')
            : 'Обновляю страницу — покажу итог последнего сбора…';

        var steps = b.querySelector('[data-live-steps]');
        var silent = b.querySelector('[data-live-silent]');
        var found = b.querySelector('[data-live-found]');
        if (silent) silent.classList.add('hidden');
        if (found) found.classList.add('hidden');
        if (steps && done) {
            steps.classList.remove('hidden');
        }
        if (icon) icon.className = 'fas text-sm ' + (done ? 'fa-circle-check text-emerald-500' : 'fa-triangle-exclamation text-rose-500');
        if (title) title.textContent = done ? 'Готово' : (s.status === 'cancelled' ? 'Отменено' : 'Ошибка');
        if (stage) {
            stage.textContent = done
                ? (keep ? 'Результат получен — сейчас он появится на странице…' : 'Результат на странице — обновляю её…')
                : (s.error || (keep ? 'Задача завершилась ошибкой.' : 'Задача завершилась ошибкой — обновляю страницу…'));
            stage.className = 'mt-1.5 text-sm font-semibold ' + (done ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-600 dark:text-rose-400');
        }
    }

    function apply(b, s) {
        if (!s || (s.error && s.error === 'auth') || s.status === undefined) return;
        if (b.__done) return;

        // Модуль может держать СВОЙ статус (new/running/error/canceled) для своих
        // нужд и отдавать статус ряда в поле status_live — тогда общий механизм
        // читает его. Так устроен /docs/<code> (prl580): там по 'status' живёт
        // таблица документов, и ломать её нельзя.
        if (typeof s.status_live === 'string' && s.status_live !== '') {
            s.status = s.status_live;
        }

        if (typeof s.elapsed_sec === 'number' && s.elapsed_sec > b.__sec) {
            b.__sec = s.elapsed_sec;
            var box = b.querySelector('[data-live-elapsed]');
            if (box) box.textContent = fmt(b.__sec);
        }

        var stage = b.querySelector('[data-live-stage]');
        if (stage && s.stage && s.stage !== b.__last) {
            b.__last = s.stage;
            stage.textContent = s.stage;
        }

        // «найдено: N» — видно, что сбор приносит результат, ещё до завершения
        var found = b.querySelector('[data-live-found]');
        if (found && typeof s.found === 'number' && s.found > 0) {
            found.textContent = 'найдено: ' + s.found;
            found.classList.remove('hidden');
        }

        // note — подпись модуля под полосой (например, «задачу никто не взял:
        // проверьте воркер»). Раньше её писал только SSR-кадр, и на живой
        // странице подсказка не появлялась вовсе.
        var note = b.querySelector('[data-live-note]');
        if (note && typeof s.note === 'string' && s.note && s.note !== b.__note) {
            b.__note = s.note;
            note.textContent = s.note;
        }

        var silent = b.querySelector('[data-live-silent]');
        if (silent) {
            if (typeof s.silent_sec === 'number' && s.silent_sec > 180) {
                silent.textContent = 'нет пульса ' + Math.floor(s.silent_sec / 60) + ' мин';
                silent.classList.remove('hidden');

                // Честная подсказка: сервер сам остановит застрявший сбор
                // (см. JobQueue::auto_stop_silent) — результат, если успел
                // дойти, сохранён, а сбор можно запустить заново.
                var hint = b.querySelector('[data-live-note]');
                if (hint) {
                    hint.textContent = 'Воркер не отвечает — сбор остановится автоматически, найденное сохранится.';
                }
            } else {
                silent.classList.add('hidden');
            }
        }

        var steps = b.querySelector('[data-live-steps]');
        if (steps && s.stages && s.stages.length) {
            var last = s.stages.slice(-4).map(function (x) { return x && x.message ? x.message : String(x); });
            steps.innerHTML = last.map(function (t) {
                return '· ' + linkify(t);
            }).join('<br>');
            steps.classList.remove('hidden');
        }

        if (s.status === 'done' || s.status === 'failed' || s.status === 'cancelled') {
            finish(b, s);

            // Модуль может продолжить работу САМ (например, /label подставляет
            // распознанный текст в форму и тут же разбирает состав): тогда ряд
            // помечается data-live-keep, перезагрузки нет, а модуль получает
            // событие и решает, что делать дальше.
            b.dispatchEvent(new CustomEvent('prl:live-done', { detail: s, bubbles: true }));

            if (!b.hasAttribute('data-live-keep') && !reloading) {
                // Страховка от петли перезагрузок: если серверный кадр и
                // статус расходятся (кэш страницы, две реплики, старая версия
                // вьюхи), «готово» после каждого reload'а перезагружало бы
                // страницу бесконечно — владелец видел ровно это на /mcphub/admin.
                // Перезагружаем не чаще раза в 30 с на один статус-адрес:
                // второй раз показываем итог и просим обновить вручную.
                var key = 'prl-live-reload:' + b.getAttribute('data-live-url');
                var prev = 0;
                try { prev = parseInt(window.sessionStorage.getItem(key) || '0', 10) || 0; } catch (e) {}

                if (prev && Date.now() - prev < 30000) {
                    if (stage) {
                        stage.textContent = done
                            ? 'Результат готов — обновите страницу, чтобы увидеть итог.'
                            : 'Задача завершилась ошибкой — обновите страницу.';
                    }
                    if (note) note.textContent = 'Автообновление приостановлено, чтобы не зациклиться.';
                    return;
                }

                try { window.sessionStorage.setItem(key, String(Date.now())); } catch (e) {}

                reloading = true;
                setTimeout(function () { window.location.reload(); }, 1200);
            }
        }
    }

    function poll() {
        blocks.forEach(function (b) {
            if (b.__done) return;

            fetch(b.getAttribute('data-live-url'), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(function (r) {
                if (!r.ok) throw new Error('http ' + r.status);
                return r.json();
            }).then(function (s) {
                failures = 0;
                apply(b, s);
            }).catch(function () {
                failures = Math.min(failures + 1, 3);
            });
        });

        if (!reloading) setTimeout(poll, failures >= 2 ? 9000 : 3000);
    }

    // Секундомер: один тик на страницу
    setInterval(function () {
        blocks.forEach(function (b) {
            if (b.__done) return;
            b.__sec += 1;
            var box = b.querySelector('[data-live-elapsed]');
            if (box) box.textContent = fmt(b.__sec);
        });
    }, 1000);

    // Блоки, уже отрисованные сервером (страница открыта во время работы)
    Array.prototype.slice.call(
        document.querySelectorAll('[data-prl-live]:not([data-live-terminal])'))
        .forEach(register);

    setTimeout(poll, 2000);

    return { mount: mount, register: register };
})();
</script>
