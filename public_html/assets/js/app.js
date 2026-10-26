/*
 * app.js — общие клиентские утилиты платформы N2J.
 *
 * Мелкие хелперы, которые до этого копировались в каждый модульный
 * script.php (humanSize, счётчик таймера, скачивание blob, копирование):
 * держим здесь в одном месте. Модульные скрипты используют их через
 * глобальный объект window.N2J; при желании переопределяют локально.
 *
 * Правило сохранено: скрипт подключается в <head>/в конце body ДО модульных
 * инлайн-скриптов (get_bottom_scripts) — модули уже видят N2J.
 *
 * Поведение дизайн-системы v6 (docs/DESIGN-SYSTEM.md, тест
 * tests/Js/n2j_ui.test.mjs): N2J.run — кнопка долгого процесса с
 * секундомером, N2J.stopwatch / data-n2j-stopwatch, N2J.fieldError,
 * N2J.confirm / data-n2j-confirm — окно для тяжёлых удалений, data-n2j-arm —
 * двойной клик для строк, data-n2j-copy, data-n2j-dismiss, N2J.hotkey.
 */
(function (window, document) {
    'use strict';

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    var N2J = {

        /**
         * Человекочитаемый размер: 512 → «512 Б», 2048 → «2.0 КБ»,
         * 5 * 1024 * 1024 → «5.0 МБ».
         */
        humanSize: function (n) {
            n = Number(n) || 0;
            if (n < 1024) return n + ' Б';
            if (n < 1048576) return (n / 1024).toFixed(1) + ' КБ';
            return (n / 1048576).toFixed(1) + ' МБ';
        },

        /**
         * Таймер на элементе: N2J.timer(el) — подписать; N2J.timerStop(el) —
         * остановить. Показывает секунды с момента старта вида «3.2s».
         */
        timer: function (el) {
            if (!el) return;
            var t0 = Date.now();
            window.clearInterval(el._n2jTimer);
            el._n2jTimer = window.setInterval(function () {
                el.textContent = ((Date.now() - t0) / 1000).toFixed(1) + 's';
            }, 100);
        },
        timerStop: function (el) {
            if (!el) return;
            window.clearInterval(el._n2jTimer);
            el.textContent = '';
        },

        /**
         * Скачать Blob как файл (PDF и пр.).
         * N2J.downloadBlob(blob, «имя.pdf»)
         */
        downloadBlob: function (blob, filename) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename || 'download';
            document.body.appendChild(a);
            a.click();
            window.setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 500);
        },

        /**
         * Скопировать текст в буфер (modern API + fallback execCommand).
         * N2J.copyText(text).then(ok)
         */
        copyText: function (text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text);
            }
            try {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                return Promise.resolve();
            } catch (e) {
                return Promise.reject(e);
            }
        },

        /**
         * Уведомление-тост (краткое, исчезает). N2J.toast('Скопировано').
         * Тон третьим аргументом: N2J.toast('Сеть недоступна', 0, 'err') — rose,
         * role=alert, держится 4 с; 'ok' — emerald. Замена window.alert в модулях.
         * Стилизовано токенами tailwind, не трогает разметку страницы.
         */
        toast: function (message, ms, tone) {
            var tones = {
                err: 'bg-rose-600 text-white dark:bg-rose-500',
                ok: 'bg-emerald-600 text-white dark:bg-emerald-500'
            };
            var t = document.createElement('div');
            t.setAttribute('role', tone === 'err' ? 'alert' : 'status');
            t.className = 'fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-lg px-4 py-2 text-sm shadow-lg '
                + (tones[tone] || 'bg-gray-900/95 text-white dark:bg-gray-100 dark:text-gray-900');
            t.textContent = message;
            document.body.appendChild(t);
            window.setTimeout(function () {
                t.style.transition = 'opacity .3s';
                t.style.opacity = '0';
                window.setTimeout(function () { t.remove(); }, 300);
            }, ms || (tone === 'err' ? 4000 : 1600));
        },

        /**
         * Точка входа «кнопка занята/свободна»: N2J.busy(btn, true, «...»).
         * Сохраняет исходный innerHTML и восстанавливает при busy=false.
         */
        busy: function (btn, on, label) {
            if (!btn) return;
            if (on) {
                btn._n2jBusyHTML = btn.innerHTML;
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
                if (label) btn.innerHTML = label;
            } else {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
                if (btn._n2jBusyHTML) btn.innerHTML = btn._n2jBusyHTML;
            }
        },

        /**
         * Обезличенный просмотр публичного сервиса (nj516_metrics).
         * Сервер агрегирует только день/страницу/группу/тип перехода/гость-вошёл;
         * visitor ID, cookies, IP и полный referer не сохраняются.
         * Ошибка аналитики не должна влиять на работу сервиса.
         */
        trackPageView: function () {
            if (typeof window.fetch !== 'function' || !window.location || !window.location.pathname) return;
            try {
                window.fetch('/nj516/api/hit', {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ path: window.location.pathname })
                }).catch(function () { /* best effort, навигацию не тормозим */ });
            } catch (e) { /* старый браузер или отключённая сеть */ }
        }
    };

    /* =====================================================================
     * Дизайн-система v6: общие помощники поведения.
     * Эталон — /pages/required_design_sample, рецепты — docs/DESIGN-SYSTEM.md.
     * Модули НЕ копируют этот код к себе: только вызывают N2J.* или ставят
     * атрибуты data-n2j-* (обработка — делегированием на document).
     * ===================================================================== */

    /** 75 → «1:15», 3725 → «1:02:05». Моноширинный секундомер процесса. */
    function formatClock(sec) {
        sec = Math.max(0, Math.floor(Number(sec) || 0));
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
    }

    function fire(el, name, detail) {
        var ev;
        try {
            ev = new window.CustomEvent(name, { bubbles: true, detail: detail || null });
        } catch (e) {
            ev = document.createEvent('CustomEvent');
            ev.initCustomEvent(name, true, false, detail || null);
        }
        el.dispatchEvent(ev);
    }

    function closestEl(target, selector) {
        return target && target.closest ? target.closest(selector) : null;
    }

    N2J.formatClock = formatClock;

    /**
     * Секундомер «м:сс» на элементе. from — сколько секунд уже прошло (сервер
     * знает время старта задачи). Возвращает { stop() → секунды, seconds() }.
     * Декларативно: <span data-n2j-stopwatch="1023">17:03</span> — запускается
     * сам при загрузке страницы.
     */
    N2J.stopwatch = function (el, from) {
        if (!el) return { stop: function () { return 0; }, seconds: function () { return 0; } };
        if (el._n2jWatch) el._n2jWatch.stop();
        var t0 = Date.now() - (Number(from) || 0) * 1000;
        var seconds = function () { return Math.floor((Date.now() - t0) / 1000); };
        var tick = function () { el.textContent = formatClock(seconds()); };
        tick();
        var id = window.setInterval(tick, 1000);
        var api = {
            seconds: seconds,
            stop: function () {
                window.clearInterval(id);
                if (el._n2jWatch === api) el._n2jWatch = null;
                return seconds();
            }
        };
        el._n2jWatch = api;
        return api;
    };

    /**
     * Кнопка долгого процесса: N2J.run(btn, true) — занята, N2J.run(btn, false)
     * — свободна (вернёт, сколько секунд шло). Внутри кнопки:
     *   [data-n2j-idle]  — подпись в покое;
     *   [data-n2j-busy]  — «Выполняется… (0:12)», изначально class="hidden";
     *   [data-n2j-clock] — сюда идёт секундомер.
     * data-n2j-busy-show="#cancel, #hint" на кнопке — что показать на время
     * работы (кнопка «Прервать», подсказка про фоновый воркер).
     * Полосы прогресса не рисуем никогда — только спиннер, этап и время.
     */
    N2J.run = function (btn, on) {
        if (!btn) return 0;
        var idle = btn.querySelector('[data-n2j-idle]');
        var busy = btn.querySelector('[data-n2j-busy]');
        var clock = btn.querySelector('[data-n2j-clock]');
        var extra = btn.getAttribute('data-n2j-busy-show');
        var sec = 0;

        btn.disabled = !!on;
        if (on) btn.setAttribute('aria-busy', 'true');
        else btn.removeAttribute('aria-busy');
        if (idle) idle.classList.toggle('hidden', !!on);
        if (busy) busy.classList.toggle('hidden', !on);
        if (extra) {
            Array.prototype.forEach.call(document.querySelectorAll(extra), function (el) {
                el.classList.toggle('hidden', !on);
            });
        }
        if (clock) {
            if (on) {
                N2J.stopwatch(clock, 0);
            } else {
                if (clock._n2jWatch) sec = clock._n2jWatch.stop();
                clock.textContent = formatClock(0);
            }
        }
        return sec;
    };

    function errorBox(input, create) {
        var box = null;
        if (input.id) {
            box = document.querySelector('[data-n2j-error-for="' + input.id.replace(/["\\]/g, '\\$&') + '"]');
        }
        if (!box) {
            var field = closestEl(input, '.n2j-field');
            if (field) box = field.querySelector('.n2j-field-error');
        }
        if (!box && create) {
            box = document.createElement('p');
            box.className = 'n2j-field-error mt-1';
            if (input.id) box.setAttribute('data-n2j-error-for', input.id);
            var parent = input.parentElement;
            var anchor = parent && (parent.classList.contains('n2j-input-group') || parent.classList.contains('relative')) ? parent : input;
            anchor.insertAdjacentElement('afterend', box);
        }
        if (box && !box.id && input.id) box.id = input.id + '-error';
        return box;
    }

    /**
     * Ошибка поля: N2J.fieldError(input, 'Укажите адрес') — красная рамка
     * (aria-invalid), иконка .n2j-invalid-icon (если стоит рядом) и текст в
     * [data-n2j-error-for="id"] / .n2j-field .n2j-field-error (нет — создаст
     * под полем). N2J.fieldError(input, null) — снять. Ошибка снимается сама,
     * как только пользователь начал править поле.
     */
    N2J.fieldError = function (input, msg) {
        if (!input) return;
        var box = errorBox(input, !!msg);
        if (msg) {
            input.setAttribute('aria-invalid', 'true');
            if (box) {
                box.innerHTML = '<i class="fas fa-circle-exclamation mr-1" aria-hidden="true"></i>';
                box.appendChild(document.createTextNode(String(msg)));
                box.hidden = false;
                box.classList.remove('hidden');
                if (box.id) input.setAttribute('aria-describedby', box.id);
            }
            if (!input._n2jErrClear) {
                input._n2jErrClear = function () {
                    input.removeEventListener('input', input._n2jErrClear);
                    input._n2jErrClear = null;
                    N2J.fieldError(input, null);
                };
                input.addEventListener('input', input._n2jErrClear);
            }
        } else {
            input.removeAttribute('aria-invalid');
            if (input._n2jErrClear) {
                input.removeEventListener('input', input._n2jErrClear);
                input._n2jErrClear = null;
            }
            if (box) {
                box.hidden = true;
                box.textContent = '';
            }
        }
    };

    var CONFIRM_TONES = {
        danger: { icon: 'n2j-modal-icon', glyph: 'fa-triangle-exclamation', btn: 'n2j-btn-danger-solid', sub: 'Это действие невозможно отменить.' },
        cta: { icon: 'n2j-modal-icon n2j-modal-icon-cta', glyph: 'fa-circle-check', btn: 'n2j-btn-cta', sub: '' },
        process: { icon: 'n2j-modal-icon n2j-modal-icon-process', glyph: 'fa-circle-question', btn: 'n2j-btn-process', sub: '' }
    };
    var confirmSeq = 0;

    /**
     * Окно подтверждения — ТОЛЬКО для тяжёлых действий (удалить сущность с
     * файлами, сбросить всё). Строки и списки удаляются двойным кликом
     * (data-n2j-arm). window.confirm в модулях запрещён.
     *
     * N2J.confirm({
     *     title: 'Удалить запись?',
     *     message: 'Удалить сущность {subject} и все связанные файлы?',
     *     subject: 'dsnf592',              // подставится жирным моноширинным
     *     ok: 'Удалить навсегда', cancel: 'Отмена',
     *     tone: 'danger' | 'cta' | 'process',
     *     note: 'Это действие невозможно отменить.'   // подзаголовок
     * }).then(function (yes) { … });
     */
    N2J.confirm = function (opts) {
        opts = opts || {};
        var tone = CONFIRM_TONES[opts.tone] ? opts.tone : 'danger';
        var t = CONFIRM_TONES[tone];
        var uid = 'n2j-confirm-' + (++confirmSeq);
        var prev = document.activeElement;
        var large = !!document.querySelector('.n2j-ds.is-125');

        var root = document.createElement('div');
        root.className = 'n2j-ds n2j-modal' + (large ? ' is-125' : '');
        root.setAttribute('role', 'alertdialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', uid + '-t');
        root.setAttribute('aria-describedby', uid + '-m');
        root.innerHTML =
            '<div class="n2j-modal-box">' +
                '<div class="flex items-center gap-3">' +
                    '<div class="' + t.icon + '"><i class="fas ' + t.glyph + '" aria-hidden="true"></i></div>' +
                    '<div>' +
                        '<h3 id="' + uid + '-t" class="n2j-h3"></h3>' +
                        '<p class="n2j-ds-xs mt-0.5 text-gray-500 dark:text-gray-400" data-n2j-note></p>' +
                    '</div>' +
                '</div>' +
                '<p id="' + uid + '-m" class="n2j-ds-sm mt-3 text-gray-600 dark:text-gray-300"></p>' +
                '<div class="mt-5 flex items-center justify-end gap-2.5">' +
                    '<button type="button" class="n2j-btn-secondary" data-n2j-cancel></button>' +
                    '<button type="button" class="' + t.btn + ' px-5" data-n2j-ok></button>' +
                '</div>' +
            '</div>';

        var titleEl = root.querySelector('#' + uid + '-t');
        var noteEl = root.querySelector('[data-n2j-note]');
        var msgEl = root.querySelector('#' + uid + '-m');
        var okBtn = root.querySelector('[data-n2j-ok]');
        var cancelBtn = root.querySelector('[data-n2j-cancel]');

        titleEl.textContent = opts.title || (tone === 'danger' ? 'Удалить?' : 'Подтвердите действие');
        var note = opts.note !== undefined && opts.note !== null ? String(opts.note) : t.sub;
        if (note) noteEl.textContent = note;
        else noteEl.remove();

        // Сообщение: {subject} → <strong class="font-mono">…</strong>, без innerHTML.
        var parts = String(opts.message || '').split('{subject}');
        parts.forEach(function (part, i) {
            if (i > 0) {
                var strong = document.createElement('strong');
                strong.className = 'font-mono text-gray-900 dark:text-white';
                strong.textContent = opts.subject || 'текущую запись';
                msgEl.appendChild(strong);
            }
            if (part) msgEl.appendChild(document.createTextNode(part));
        });
        if (!msgEl.firstChild) msgEl.remove();

        okBtn.textContent = opts.ok || (tone === 'danger' ? 'Удалить' : 'Подтвердить');
        cancelBtn.textContent = opts.cancel || 'Отмена';

        return new Promise(function (resolve) {
            var done = false;
            function close(result) {
                if (done) return;
                done = true;
                document.removeEventListener('keydown', onKey, true);
                root.remove();
                if (prev && typeof prev.focus === 'function' && document.contains(prev)) prev.focus();
                resolve(result);
            }
            function onKey(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    close(false);
                } else if (e.key === 'Tab') {
                    // Фокус не уходит из окна: две кнопки по кругу.
                    e.preventDefault();
                    (document.activeElement === okBtn ? cancelBtn : okBtn).focus();
                }
            }
            okBtn.addEventListener('click', function () { close(true); });
            cancelBtn.addEventListener('click', function () { close(false); });
            root.addEventListener('click', function (e) { if (e.target === root) close(false); });
            document.addEventListener('keydown', onKey, true);
            document.body.appendChild(root);
            // Фокус на безопасной кнопке: Enter по привычке не удалит.
            cancelBtn.focus();
        });
    };

    /* --- Двойной клик для удаления (data-n2j-arm) -------------------------
     * <button data-n2j-arm="Удалить?"> — первый клик «взводит» кнопку
     * (сплошной красный, подпись из атрибута, 4 секунды), второй — выполняет
     * исходное действие: submit формы, переход по ссылке, обработчик click
     * модуля и событие n2j:confirmed (всплывает) — слушайте что-то одно.
     * Подпись меняется в [data-n2j-arm-label], если он есть; иначе кнопка
     * показывает иконку + подпись. Если модуль сам сменил содержимое кнопки,
     * пока она взведена («Удаляю…», счётчик выбранных), прежнюю подпись не
     * возвращаем — иначе она затрёт его состояние.
     */
    var armedEl = null;
    var ARM_MS = 4000;

    function disarm(el) {
        if (!el) return;
        window.clearTimeout(el._n2jArmTimer);
        if (el.classList.contains('is-armed')) {
            el.classList.remove('is-armed');
            if (el._n2jArmHTML !== undefined && el._n2jArmHTML !== null && el.innerHTML === el._n2jArmView) el.innerHTML = el._n2jArmHTML;
        }
        el._n2jArmHTML = null;
        el._n2jArmView = null;
        if (armedEl === el) armedEl = null;
    }

    function arm(el) {
        if (armedEl && armedEl !== el) disarm(armedEl);
        var label = el.getAttribute('data-n2j-arm') || 'Точно удалить?';
        el._n2jArmHTML = el.innerHTML;
        el.classList.add('is-armed');
        var slot = el.querySelector('[data-n2j-arm-label]');
        if (slot) {
            slot.textContent = label;
        } else {
            var icon = el.querySelector('i');
            el.innerHTML = '';
            if (icon) el.appendChild(icon.cloneNode(true));
            var span = document.createElement('span');
            span.textContent = label;
            el.appendChild(span);
        }
        el._n2jArmView = el.innerHTML;
        armedEl = el;
        el._n2jArmTimer = window.setTimeout(function () { disarm(el); }, ARM_MS);
    }

    N2J.arm = arm;
    N2J.disarm = disarm;

    // Захват (третий аргумент true): срабатывает РАНЬШЕ обработчиков модуля,
    // поэтому первый клик не доходит ни до submit, ни до их click.
    document.addEventListener('click', function (e) {
        var el = closestEl(e.target, '[data-n2j-confirm], [data-n2j-arm]');

        if (armedEl && armedEl !== el) disarm(armedEl);
        if (!el) return;

        if (el._n2jPass) {
            // Повторный click() после «Да» в окне — пропускаем к модулю.
            el._n2jPass = false;
            fire(el, 'n2j:confirmed');
            return;
        }

        if (el.hasAttribute('data-n2j-confirm')) {
            e.preventDefault();
            e.stopPropagation();
            N2J.confirm({
                title: el.getAttribute('data-n2j-title'),
                message: el.getAttribute('data-n2j-confirm'),
                subject: el.getAttribute('data-n2j-subject'),
                ok: el.getAttribute('data-n2j-ok'),
                cancel: el.getAttribute('data-n2j-cancel'),
                tone: el.getAttribute('data-n2j-tone'),
                note: el.getAttribute('data-n2j-note')
            }).then(function (yes) {
                if (!yes) return;
                el._n2jPass = true;
                el.click();
            });
            return;
        }

        if (el.classList.contains('is-armed')) {
            // Второй клик: действие выполняется; подпись вернём после того,
            // как отработают обработчики (им может понадобиться e.target).
            fire(el, 'n2j:confirmed');
            window.setTimeout(function () { disarm(el); }, 0);
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        arm(el);
    }, true);

    /* --- Копирование и закрытие (data-n2j-copy, data-n2j-dismiss) --------- */
    document.addEventListener('click', function (e) {
        var copyBtn = closestEl(e.target, '[data-n2j-copy]');
        if (copyBtn) {
            var value = copyBtn.getAttribute('data-n2j-copy');
            if (value) {
                N2J.copyText(value).then(function () {
                    var ico = copyBtn.querySelector('i');
                    if (!ico) { N2J.toast('Скопировано'); return; }
                    if (ico._n2jCopyClass === undefined || ico._n2jCopyClass === null) ico._n2jCopyClass = ico.className;
                    ico.className = ico._n2jCopyClass.replace('fa-copy', 'fa-check') + ' text-emerald-600';
                    window.clearTimeout(ico._n2jCopyTimer);
                    ico._n2jCopyTimer = window.setTimeout(function () {
                        ico.className = ico._n2jCopyClass;
                        ico._n2jCopyClass = null;
                    }, 1200);
                }, function () { N2J.toast('Не удалось скопировать'); });
            }
        }

        var closeBtn = closestEl(e.target, '[data-n2j-dismiss]');
        if (closeBtn) {
            var sel = closeBtn.getAttribute('data-n2j-dismiss');
            var box = sel ? document.querySelector(sel) : closestEl(closeBtn, '.n2j-flash, [data-n2j-dismissible]');
            if (box) box.remove();
        }
    });

    /* --- Горячие клавиши ---------------------------------------------------
     * N2J.hotkey('mod+s', save) — Ctrl+S / ⌘S, в любой раскладке (буквы
     * сверяются по e.code, поэтому «Ctrl+Ы» тоже сработает).
     * N2J.hotkey('mod+enter', parse, { within: textarea }) — только когда
     * фокус внутри элемента (или селектора). Возвращает функцию отписки.
     */
    function parseCombo(combo) {
        var spec = { mod: false, shift: false, alt: false, code: null, key: null };
        String(combo || '').toLowerCase().split('+').forEach(function (p) {
            p = p.trim();
            if (p === 'mod' || p === 'ctrl' || p === 'cmd' || p === 'meta') spec.mod = true;
            else if (p === 'shift') spec.shift = true;
            else if (p === 'alt' || p === 'option') spec.alt = true;
            else if (/^[a-z]$/.test(p)) spec.code = 'Key' + p.toUpperCase();
            else if (/^[0-9]$/.test(p)) spec.code = 'Digit' + p;
            else if (p === 'esc' || p === 'escape') spec.key = 'Escape';
            else if (p) spec.key = p.charAt(0).toUpperCase() + p.slice(1);
        });
        return spec;
    }

    N2J.hotkey = function (combo, handler, opts) {
        var spec = parseCombo(combo);
        opts = opts || {};
        var fn = function (e) {
            var mod = e.ctrlKey || e.metaKey;
            if (mod !== spec.mod || !!e.shiftKey !== spec.shift || !!e.altKey !== spec.alt) return;
            if (spec.code ? e.code !== spec.code : e.key !== spec.key) return;
            if (opts.within) {
                var scope = typeof opts.within === 'string' ? document.querySelector(opts.within) : opts.within;
                if (!scope || !scope.contains(document.activeElement)) return;
            }
            e.preventDefault();
            handler(e);
        };
        document.addEventListener('keydown', fn);
        return function () { document.removeEventListener('keydown', fn); };
    };

    // Esc снимает «взведённую» кнопку удаления.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && armedEl) disarm(armedEl);
    });

    function initDeclarative() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-n2j-stopwatch]'), function (el) {
            if (!el._n2jWatch) N2J.stopwatch(el, Number(el.getAttribute('data-n2j-stopwatch')) || 0);
        });
    }

    window.N2J = N2J;

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDeclarative);
    else initDeclarative();

    // Счётчик знает только pathname; nj516 решает по публичным манифестам,
    // является ли адрес страницей сервиса. query-параметры не отправляются.
    N2J.trackPageView();
})(window, document);
