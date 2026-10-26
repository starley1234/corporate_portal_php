/*
 * app.js — общие клиентские утилиты платформы PRL.
 *
 * Мелкие хелперы, которые до этого копировались в каждый модульный
 * script.php (humanSize, счётчик таймера, скачивание blob, копирование):
 * держим здесь в одном месте. Модульные скрипты используют их через
 * глобальный объект window.PRL; при желании переопределяют локально.
 *
 * Правило сохранено: скрипт подключается в <head>/в конце body ДО модульных
 * инлайн-скриптов (get_bottom_scripts) — модули уже видят PRL.
 *
 * Поведение дизайн-системы v6 (docs/DESIGN-SYSTEM.md, тест
 * tests/Js/prl_ui.test.mjs): PRL.run — кнопка долгого процесса с
 * секундомером, PRL.stopwatch / data-prl-stopwatch, PRL.fieldError,
 * PRL.confirm / data-prl-confirm — окно для тяжёлых удалений, data-prl-arm —
 * двойной клик для строк, data-prl-copy, data-prl-dismiss, PRL.hotkey.
 */
(function (window, document) {
    'use strict';

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    var PRL = {

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
         * Таймер на элементе: PRL.timer(el) — подписать; PRL.timerStop(el) —
         * остановить. Показывает секунды с момента старта вида «3.2s».
         */
        timer: function (el) {
            if (!el) return;
            var t0 = Date.now();
            window.clearInterval(el._prlTimer);
            el._prlTimer = window.setInterval(function () {
                el.textContent = ((Date.now() - t0) / 1000).toFixed(1) + 's';
            }, 100);
        },
        timerStop: function (el) {
            if (!el) return;
            window.clearInterval(el._prlTimer);
            el.textContent = '';
        },

        /**
         * Скачать Blob как файл (PDF и пр.).
         * PRL.downloadBlob(blob, «имя.pdf»)
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
         * PRL.copyText(text).then(ok)
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
         * Уведомление-тост (краткое, исчезает). PRL.toast('Скопировано').
         * Тон третьим аргументом: PRL.toast('Сеть недоступна', 0, 'err') — rose,
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
         * Точка входа «кнопка занята/свободна»: PRL.busy(btn, true, «...»).
         * Сохраняет исходный innerHTML и восстанавливает при busy=false.
         */
        busy: function (btn, on, label) {
            if (!btn) return;
            if (on) {
                btn._prlBusyHTML = btn.innerHTML;
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
                if (label) btn.innerHTML = label;
            } else {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
                if (btn._prlBusyHTML) btn.innerHTML = btn._prlBusyHTML;
            }
        },

        /**
         * Обезличенный просмотр публичного сервиса (prl516_metrics).
         * Сервер агрегирует только день/страницу/группу/тип перехода/гость-вошёл;
         * visitor ID, cookies, IP и полный referer не сохраняются.
         * Ошибка аналитики не должна влиять на работу сервиса.
         */
        trackPageView: function () {
            if (typeof window.fetch !== 'function' || !window.location || !window.location.pathname) return;
            try {
                window.fetch('/prl516/api/hit', {
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
     * Модули НЕ копируют этот код к себе: только вызывают PRL.* или ставят
     * атрибуты data-prl-* (обработка — делегированием на document).
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

    PRL.formatClock = formatClock;

    /**
     * Секундомер «м:сс» на элементе. from — сколько секунд уже прошло (сервер
     * знает время старта задачи). Возвращает { stop() → секунды, seconds() }.
     * Декларативно: <span data-prl-stopwatch="1023">17:03</span> — запускается
     * сам при загрузке страницы.
     */
    PRL.stopwatch = function (el, from) {
        if (!el) return { stop: function () { return 0; }, seconds: function () { return 0; } };
        if (el._prlWatch) el._prlWatch.stop();
        var t0 = Date.now() - (Number(from) || 0) * 1000;
        var seconds = function () { return Math.floor((Date.now() - t0) / 1000); };
        var tick = function () { el.textContent = formatClock(seconds()); };
        tick();
        var id = window.setInterval(tick, 1000);
        var api = {
            seconds: seconds,
            stop: function () {
                window.clearInterval(id);
                if (el._prlWatch === api) el._prlWatch = null;
                return seconds();
            }
        };
        el._prlWatch = api;
        return api;
    };

    /**
     * Кнопка долгого процесса: PRL.run(btn, true) — занята, PRL.run(btn, false)
     * — свободна (вернёт, сколько секунд шло). Внутри кнопки:
     *   [data-prl-idle]  — подпись в покое;
     *   [data-prl-busy]  — «Выполняется… (0:12)», изначально class="hidden";
     *   [data-prl-clock] — сюда идёт секундомер.
     * data-prl-busy-show="#cancel, #hint" на кнопке — что показать на время
     * работы (кнопка «Прервать», подсказка про фоновый воркер).
     * Полосы прогресса не рисуем никогда — только спиннер, этап и время.
     */
    PRL.run = function (btn, on) {
        if (!btn) return 0;
        var idle = btn.querySelector('[data-prl-idle]');
        var busy = btn.querySelector('[data-prl-busy]');
        var clock = btn.querySelector('[data-prl-clock]');
        var extra = btn.getAttribute('data-prl-busy-show');
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
                PRL.stopwatch(clock, 0);
            } else {
                if (clock._prlWatch) sec = clock._prlWatch.stop();
                clock.textContent = formatClock(0);
            }
        }
        return sec;
    };

    function errorBox(input, create) {
        var box = null;
        if (input.id) {
            box = document.querySelector('[data-prl-error-for="' + input.id.replace(/["\\]/g, '\\$&') + '"]');
        }
        if (!box) {
            var field = closestEl(input, '.prl-field');
            if (field) box = field.querySelector('.prl-field-error');
        }
        if (!box && create) {
            box = document.createElement('p');
            box.className = 'prl-field-error mt-1';
            if (input.id) box.setAttribute('data-prl-error-for', input.id);
            var parent = input.parentElement;
            var anchor = parent && (parent.classList.contains('prl-input-group') || parent.classList.contains('relative')) ? parent : input;
            anchor.insertAdjacentElement('afterend', box);
        }
        if (box && !box.id && input.id) box.id = input.id + '-error';
        return box;
    }

    /**
     * Ошибка поля: PRL.fieldError(input, 'Укажите адрес') — красная рамка
     * (aria-invalid), иконка .prl-invalid-icon (если стоит рядом) и текст в
     * [data-prl-error-for="id"] / .prl-field .prl-field-error (нет — создаст
     * под полем). PRL.fieldError(input, null) — снять. Ошибка снимается сама,
     * как только пользователь начал править поле.
     */
    PRL.fieldError = function (input, msg) {
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
            if (!input._prlErrClear) {
                input._prlErrClear = function () {
                    input.removeEventListener('input', input._prlErrClear);
                    input._prlErrClear = null;
                    PRL.fieldError(input, null);
                };
                input.addEventListener('input', input._prlErrClear);
            }
        } else {
            input.removeAttribute('aria-invalid');
            if (input._prlErrClear) {
                input.removeEventListener('input', input._prlErrClear);
                input._prlErrClear = null;
            }
            if (box) {
                box.hidden = true;
                box.textContent = '';
            }
        }
    };

    var CONFIRM_TONES = {
        danger: { icon: 'prl-modal-icon', glyph: 'fa-triangle-exclamation', btn: 'prl-btn-danger-solid', sub: 'Это действие невозможно отменить.' },
        cta: { icon: 'prl-modal-icon prl-modal-icon-cta', glyph: 'fa-circle-check', btn: 'prl-btn-cta', sub: '' },
        process: { icon: 'prl-modal-icon prl-modal-icon-process', glyph: 'fa-circle-question', btn: 'prl-btn-process', sub: '' }
    };
    var confirmSeq = 0;

    /**
     * Окно подтверждения — ТОЛЬКО для тяжёлых действий (удалить сущность с
     * файлами, сбросить всё). Строки и списки удаляются двойным кликом
     * (data-prl-arm). window.confirm в модулях запрещён.
     *
     * PRL.confirm({
     *     title: 'Удалить запись?',
     *     message: 'Удалить сущность {subject} и все связанные файлы?',
     *     subject: 'dsnf592',              // подставится жирным моноширинным
     *     ok: 'Удалить навсегда', cancel: 'Отмена',
     *     tone: 'danger' | 'cta' | 'process',
     *     note: 'Это действие невозможно отменить.'   // подзаголовок
     * }).then(function (yes) { … });
     */
    PRL.confirm = function (opts) {
        opts = opts || {};
        var tone = CONFIRM_TONES[opts.tone] ? opts.tone : 'danger';
        var t = CONFIRM_TONES[tone];
        var uid = 'prl-confirm-' + (++confirmSeq);
        var prev = document.activeElement;
        var large = !!document.querySelector('.prl-ds.is-125');

        var root = document.createElement('div');
        root.className = 'prl-ds prl-modal' + (large ? ' is-125' : '');
        root.setAttribute('role', 'alertdialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', uid + '-t');
        root.setAttribute('aria-describedby', uid + '-m');
        root.innerHTML =
            '<div class="prl-modal-box">' +
                '<div class="flex items-center gap-3">' +
                    '<div class="' + t.icon + '"><i class="fas ' + t.glyph + '" aria-hidden="true"></i></div>' +
                    '<div>' +
                        '<h3 id="' + uid + '-t" class="prl-h3"></h3>' +
                        '<p class="prl-ds-xs mt-0.5 text-gray-500 dark:text-gray-400" data-prl-note></p>' +
                    '</div>' +
                '</div>' +
                '<p id="' + uid + '-m" class="prl-ds-sm mt-3 text-gray-600 dark:text-gray-300"></p>' +
                '<div class="mt-5 flex items-center justify-end gap-2.5">' +
                    '<button type="button" class="prl-btn-secondary" data-prl-cancel></button>' +
                    '<button type="button" class="' + t.btn + ' px-5" data-prl-ok></button>' +
                '</div>' +
            '</div>';

        var titleEl = root.querySelector('#' + uid + '-t');
        var noteEl = root.querySelector('[data-prl-note]');
        var msgEl = root.querySelector('#' + uid + '-m');
        var okBtn = root.querySelector('[data-prl-ok]');
        var cancelBtn = root.querySelector('[data-prl-cancel]');

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

    /* --- Двойной клик для удаления (data-prl-arm) -------------------------
     * <button data-prl-arm="Удалить?"> — первый клик «взводит» кнопку
     * (сплошной красный, подпись из атрибута, 4 секунды), второй — выполняет
     * исходное действие: submit формы, переход по ссылке, обработчик click
     * модуля и событие prl:confirmed (всплывает) — слушайте что-то одно.
     * Подпись меняется в [data-prl-arm-label], если он есть; иначе кнопка
     * показывает иконку + подпись. Если модуль сам сменил содержимое кнопки,
     * пока она взведена («Удаляю…», счётчик выбранных), прежнюю подпись не
     * возвращаем — иначе она затрёт его состояние.
     */
    var armedEl = null;
    var ARM_MS = 4000;

    function disarm(el) {
        if (!el) return;
        window.clearTimeout(el._prlArmTimer);
        if (el.classList.contains('is-armed')) {
            el.classList.remove('is-armed');
            if (el._prlArmHTML !== undefined && el._prlArmHTML !== null && el.innerHTML === el._prlArmView) el.innerHTML = el._prlArmHTML;
        }
        el._prlArmHTML = null;
        el._prlArmView = null;
        if (armedEl === el) armedEl = null;
    }

    function arm(el) {
        if (armedEl && armedEl !== el) disarm(armedEl);
        var label = el.getAttribute('data-prl-arm') || 'Точно удалить?';
        el._prlArmHTML = el.innerHTML;
        el.classList.add('is-armed');
        var slot = el.querySelector('[data-prl-arm-label]');
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
        el._prlArmView = el.innerHTML;
        armedEl = el;
        el._prlArmTimer = window.setTimeout(function () { disarm(el); }, ARM_MS);
    }

    PRL.arm = arm;
    PRL.disarm = disarm;

    // Захват (третий аргумент true): срабатывает РАНЬШЕ обработчиков модуля,
    // поэтому первый клик не доходит ни до submit, ни до их click.
    document.addEventListener('click', function (e) {
        var el = closestEl(e.target, '[data-prl-confirm], [data-prl-arm]');

        if (armedEl && armedEl !== el) disarm(armedEl);
        if (!el) return;

        if (el._prlPass) {
            // Повторный click() после «Да» в окне — пропускаем к модулю.
            el._prlPass = false;
            fire(el, 'prl:confirmed');
            return;
        }

        if (el.hasAttribute('data-prl-confirm')) {
            e.preventDefault();
            e.stopPropagation();
            PRL.confirm({
                title: el.getAttribute('data-prl-title'),
                message: el.getAttribute('data-prl-confirm'),
                subject: el.getAttribute('data-prl-subject'),
                ok: el.getAttribute('data-prl-ok'),
                cancel: el.getAttribute('data-prl-cancel'),
                tone: el.getAttribute('data-prl-tone'),
                note: el.getAttribute('data-prl-note')
            }).then(function (yes) {
                if (!yes) return;
                el._prlPass = true;
                el.click();
            });
            return;
        }

        if (el.classList.contains('is-armed')) {
            // Второй клик: действие выполняется; подпись вернём после того,
            // как отработают обработчики (им может понадобиться e.target).
            fire(el, 'prl:confirmed');
            window.setTimeout(function () { disarm(el); }, 0);
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        arm(el);
    }, true);

    /* --- Копирование и закрытие (data-prl-copy, data-prl-dismiss) --------- */
    document.addEventListener('click', function (e) {
        var copyBtn = closestEl(e.target, '[data-prl-copy]');
        if (copyBtn) {
            var value = copyBtn.getAttribute('data-prl-copy');
            if (value) {
                PRL.copyText(value).then(function () {
                    var ico = copyBtn.querySelector('i');
                    if (!ico) { PRL.toast('Скопировано'); return; }
                    if (ico._prlCopyClass === undefined || ico._prlCopyClass === null) ico._prlCopyClass = ico.className;
                    ico.className = ico._prlCopyClass.replace('fa-copy', 'fa-check') + ' text-emerald-600';
                    window.clearTimeout(ico._prlCopyTimer);
                    ico._prlCopyTimer = window.setTimeout(function () {
                        ico.className = ico._prlCopyClass;
                        ico._prlCopyClass = null;
                    }, 1200);
                }, function () { PRL.toast('Не удалось скопировать'); });
            }
        }

        var closeBtn = closestEl(e.target, '[data-prl-dismiss]');
        if (closeBtn) {
            var sel = closeBtn.getAttribute('data-prl-dismiss');
            var box = sel ? document.querySelector(sel) : closestEl(closeBtn, '.prl-flash, [data-prl-dismissible]');
            if (box) box.remove();
        }
    });

    /* --- Горячие клавиши ---------------------------------------------------
     * PRL.hotkey('mod+s', save) — Ctrl+S / ⌘S, в любой раскладке (буквы
     * сверяются по e.code, поэтому «Ctrl+Ы» тоже сработает).
     * PRL.hotkey('mod+enter', parse, { within: textarea }) — только когда
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

    PRL.hotkey = function (combo, handler, opts) {
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
        Array.prototype.forEach.call(document.querySelectorAll('[data-prl-stopwatch]'), function (el) {
            if (!el._prlWatch) PRL.stopwatch(el, Number(el.getAttribute('data-prl-stopwatch')) || 0);
        });
    }

    window.PRL = PRL;

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDeclarative);
    else initDeclarative();

    // Счётчик знает только pathname; prl516 решает по публичным манифестам,
    // является ли адрес страницей сервиса. query-параметры не отправляются.
    PRL.trackPageView();
})(window, document);
