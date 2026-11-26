/*
 * app.js — общие клиентские утилиты платформы PRX.
 *
 * Мелкие хелперы, которые до этого копировались в каждый модульный
 * script.php (humanSize, счётчик таймера, скачивание blob, копирование):
 * держим здесь в одном месте. Модульные скрипты используют их через
 * глобальный объект window.PRX; при желании переопределяют локально.
 *
 * Правило сохранено: скрипт подключается в <head>/в конце body ДО модульных
 * инлайн-скриптов (get_bottom_scripts) — модули уже видят PRX.
 *
 * Поведение дизайн-системы v6 (docs/DESIGN-SYSTEM.md, тест
 * tests/Js/prx_ui.test.mjs): PRX.run — кнопка долгого процесса с
 * секундомером, PRX.stopwatch / data-prx-stopwatch, PRX.fieldError,
 * PRX.confirm / data-prx-confirm — окно для тяжёлых удалений, data-prx-arm —
 * двойной клик для строк, data-prx-copy, data-prx-dismiss, PRX.hotkey.
 */
(function (window, document) {
    'use strict';

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    var PRX = {

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
         * Таймер на элементе: PRX.timer(el) — подписать; PRX.timerStop(el) —
         * остановить. Показывает секунды с момента старта вида «3.2s».
         */
        timer: function (el) {
            if (!el) return;
            var t0 = Date.now();
            window.clearInterval(el._prxTimer);
            el._prxTimer = window.setInterval(function () {
                el.textContent = ((Date.now() - t0) / 1000).toFixed(1) + 's';
            }, 100);
        },
        timerStop: function (el) {
            if (!el) return;
            window.clearInterval(el._prxTimer);
            el.textContent = '';
        },

        /**
         * Скачать Blob как файл (PDF и пр.).
         * PRX.downloadBlob(blob, «имя.pdf»)
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
         * PRX.copyText(text).then(ok)
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
         * Уведомление-тост (краткое, исчезает). PRX.toast('Скопировано').
         * Тон третьим аргументом: PRX.toast('Сеть недоступна', 0, 'err') — rose,
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
         * Точка входа «кнопка занята/свободна»: PRX.busy(btn, true, «...»).
         * Сохраняет исходный innerHTML и восстанавливает при busy=false.
         */
        busy: function (btn, on, label) {
            if (!btn) return;
            if (on) {
                btn._prxBusyHTML = btn.innerHTML;
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
                if (label) btn.innerHTML = label;
            } else {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
                if (btn._prxBusyHTML) btn.innerHTML = btn._prxBusyHTML;
            }
        },

    };
    /* =====================================================================
     * Дизайн-система v6: общие помощники поведения.
     * Эталон — /pages/required_design_sample, рецепты — docs/DESIGN-SYSTEM.md.
     * Модули НЕ копируют этот код к себе: только вызывают PRX.* или ставят
     * атрибуты data-prx-* (обработка — делегированием на document).
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

    PRX.formatClock = formatClock;

    /**
     * Секундомер «м:сс» на элементе. from — сколько секунд уже прошло (сервер
     * знает время старта задачи). Возвращает { stop() → секунды, seconds() }.
     * Декларативно: <span data-prx-stopwatch="1023">17:03</span> — запускается
     * сам при загрузке страницы.
     */
    PRX.stopwatch = function (el, from) {
        if (!el) return { stop: function () { return 0; }, seconds: function () { return 0; } };
        if (el._prxWatch) el._prxWatch.stop();
        var t0 = Date.now() - (Number(from) || 0) * 1000;
        var seconds = function () { return Math.floor((Date.now() - t0) / 1000); };
        var tick = function () { el.textContent = formatClock(seconds()); };
        tick();
        var id = window.setInterval(tick, 1000);
        var api = {
            seconds: seconds,
            stop: function () {
                window.clearInterval(id);
                if (el._prxWatch === api) el._prxWatch = null;
                return seconds();
            }
        };
        el._prxWatch = api;
        return api;
    };

    /**
     * Кнопка долгого процесса: PRX.run(btn, true) — занята, PRX.run(btn, false)
     * — свободна (вернёт, сколько секунд шло). Внутри кнопки:
     *   [data-prx-idle]  — подпись в покое;
     *   [data-prx-busy]  — «Выполняется… (0:12)», изначально class="hidden";
     *   [data-prx-clock] — сюда идёт секундомер.
     * data-prx-busy-show="#cancel, #hint" на кнопке — что показать на время
     * работы (кнопка «Прервать», подсказка про фоновый воркер).
     * Полосы прогресса не рисуем никогда — только спиннер, этап и время.
     */
    PRX.run = function (btn, on) {
        if (!btn) return 0;
        var idle = btn.querySelector('[data-prx-idle]');
        var busy = btn.querySelector('[data-prx-busy]');
        var clock = btn.querySelector('[data-prx-clock]');
        var extra = btn.getAttribute('data-prx-busy-show');
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
                PRX.stopwatch(clock, 0);
            } else {
                if (clock._prxWatch) sec = clock._prxWatch.stop();
                clock.textContent = formatClock(0);
            }
        }
        return sec;
    };

    function errorBox(input, create) {
        var box = null;
        if (input.id) {
            box = document.querySelector('[data-prx-error-for="' + input.id.replace(/["\\]/g, '\\$&') + '"]');
        }
        if (!box) {
            var field = closestEl(input, '.prx-field');
            if (field) box = field.querySelector('.prx-field-error');
        }
        if (!box && create) {
            box = document.createElement('p');
            box.className = 'prx-field-error mt-1';
            if (input.id) box.setAttribute('data-prx-error-for', input.id);
            var parent = input.parentElement;
            var anchor = parent && (parent.classList.contains('prx-input-group') || parent.classList.contains('relative')) ? parent : input;
            anchor.insertAdjacentElement('afterend', box);
        }
        if (box && !box.id && input.id) box.id = input.id + '-error';
        return box;
    }

    /**
     * Ошибка поля: PRX.fieldError(input, 'Укажите адрес') — красная рамка
     * (aria-invalid), иконка .prx-invalid-icon (если стоит рядом) и текст в
     * [data-prx-error-for="id"] / .prx-field .prx-field-error (нет — создаст
     * под полем). PRX.fieldError(input, null) — снять. Ошибка снимается сама,
     * как только пользователь начал править поле.
     */
    PRX.fieldError = function (input, msg) {
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
            if (!input._prxErrClear) {
                input._prxErrClear = function () {
                    input.removeEventListener('input', input._prxErrClear);
                    input._prxErrClear = null;
                    PRX.fieldError(input, null);
                };
                input.addEventListener('input', input._prxErrClear);
            }
        } else {
            input.removeAttribute('aria-invalid');
            if (input._prxErrClear) {
                input.removeEventListener('input', input._prxErrClear);
                input._prxErrClear = null;
            }
            if (box) {
                box.hidden = true;
                box.textContent = '';
            }
        }
    };

    var CONFIRM_TONES = {
        danger: { icon: 'prx-modal-icon', glyph: 'fa-triangle-exclamation', btn: 'prx-btn-danger-solid', sub: 'Это действие невозможно отменить.' },
        cta: { icon: 'prx-modal-icon prx-modal-icon-cta', glyph: 'fa-circle-check', btn: 'prx-btn-cta', sub: '' },
        process: { icon: 'prx-modal-icon prx-modal-icon-process', glyph: 'fa-circle-question', btn: 'prx-btn-process', sub: '' }
    };
    var confirmSeq = 0;

    /**
     * Окно подтверждения — ТОЛЬКО для тяжёлых действий (удалить сущность с
     * файлами, сбросить всё). Строки и списки удаляются двойным кликом
     * (data-prx-arm). window.confirm в модулях запрещён.
     *
     * PRX.confirm({
     *     title: 'Удалить запись?',
     *     message: 'Удалить сущность {subject} и все связанные файлы?',
     *     subject: 'dsnf592',              // подставится жирным моноширинным
     *     ok: 'Удалить навсегда', cancel: 'Отмена',
     *     tone: 'danger' | 'cta' | 'process',
     *     note: 'Это действие невозможно отменить.'   // подзаголовок
     * }).then(function (yes) { … });
     */
    PRX.confirm = function (opts) {
        opts = opts || {};
        var tone = CONFIRM_TONES[opts.tone] ? opts.tone : 'danger';
        var t = CONFIRM_TONES[tone];
        var uid = 'prx-confirm-' + (++confirmSeq);
        var prev = document.activeElement;
        var large = !!document.querySelector('.prx-ds.is-125');

        var root = document.createElement('div');
        root.className = 'prx-ds prx-modal' + (large ? ' is-125' : '');
        root.setAttribute('role', 'alertdialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', uid + '-t');
        root.setAttribute('aria-describedby', uid + '-m');
        root.innerHTML =
            '<div class="prx-modal-box">' +
                '<div class="flex items-center gap-3">' +
                    '<div class="' + t.icon + '"><i class="fas ' + t.glyph + '" aria-hidden="true"></i></div>' +
                    '<div>' +
                        '<h3 id="' + uid + '-t" class="prx-h3"></h3>' +
                        '<p class="prx-ds-xs mt-0.5 text-gray-500 dark:text-gray-400" data-prx-note></p>' +
                    '</div>' +
                '</div>' +
                '<p id="' + uid + '-m" class="prx-ds-sm mt-3 text-gray-600 dark:text-gray-300"></p>' +
                '<div class="mt-5 flex items-center justify-end gap-2.5">' +
                    '<button type="button" class="prx-btn-secondary" data-prx-cancel></button>' +
                    '<button type="button" class="' + t.btn + ' px-5" data-prx-ok></button>' +
                '</div>' +
            '</div>';

        var titleEl = root.querySelector('#' + uid + '-t');
        var noteEl = root.querySelector('[data-prx-note]');
        var msgEl = root.querySelector('#' + uid + '-m');
        var okBtn = root.querySelector('[data-prx-ok]');
        var cancelBtn = root.querySelector('[data-prx-cancel]');

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

    /* --- Двойной клик для удаления (data-prx-arm) -------------------------
     * <button data-prx-arm="Удалить?"> — первый клик «взводит» кнопку
     * (сплошной красный, подпись из атрибута, 4 секунды), второй — выполняет
     * исходное действие: submit формы, переход по ссылке, обработчик click
     * модуля и событие prx:confirmed (всплывает) — слушайте что-то одно.
     * Подпись меняется в [data-prx-arm-label], если он есть; иначе кнопка
     * показывает иконку + подпись. Если модуль сам сменил содержимое кнопки,
     * пока она взведена («Удаляю…», счётчик выбранных), прежнюю подпись не
     * возвращаем — иначе она затрёт его состояние.
     */
    var armedEl = null;
    var ARM_MS = 4000;

    function disarm(el) {
        if (!el) return;
        window.clearTimeout(el._prxArmTimer);
        if (el.classList.contains('is-armed')) {
            el.classList.remove('is-armed');
            if (el._prxArmHTML !== undefined && el._prxArmHTML !== null && el.innerHTML === el._prxArmView) el.innerHTML = el._prxArmHTML;
        }
        el._prxArmHTML = null;
        el._prxArmView = null;
        if (armedEl === el) armedEl = null;
    }

    function arm(el) {
        if (armedEl && armedEl !== el) disarm(armedEl);
        var label = el.getAttribute('data-prx-arm') || 'Точно удалить?';
        el._prxArmHTML = el.innerHTML;
        el.classList.add('is-armed');
        var slot = el.querySelector('[data-prx-arm-label]');
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
        el._prxArmView = el.innerHTML;
        armedEl = el;
        el._prxArmTimer = window.setTimeout(function () { disarm(el); }, ARM_MS);
    }

    PRX.arm = arm;
    PRX.disarm = disarm;

    // Захват (третий аргумент true): срабатывает РАНЬШЕ обработчиков модуля,
    // поэтому первый клик не доходит ни до submit, ни до их click.
    document.addEventListener('click', function (e) {
        var el = closestEl(e.target, '[data-prx-confirm], [data-prx-arm]');

        if (armedEl && armedEl !== el) disarm(armedEl);
        if (!el) return;

        if (el._prxPass) {
            // Повторный click() после «Да» в окне — пропускаем к модулю.
            el._prxPass = false;
            fire(el, 'prx:confirmed');
            return;
        }

        if (el.hasAttribute('data-prx-confirm')) {
            e.preventDefault();
            e.stopPropagation();
            PRX.confirm({
                title: el.getAttribute('data-prx-title'),
                message: el.getAttribute('data-prx-confirm'),
                subject: el.getAttribute('data-prx-subject'),
                ok: el.getAttribute('data-prx-ok'),
                cancel: el.getAttribute('data-prx-cancel'),
                tone: el.getAttribute('data-prx-tone'),
                note: el.getAttribute('data-prx-note')
            }).then(function (yes) {
                if (!yes) return;
                el._prxPass = true;
                el.click();
            });
            return;
        }

        if (el.classList.contains('is-armed')) {
            // Второй клик: действие выполняется; подпись вернём после того,
            // как отработают обработчики (им может понадобиться e.target).
            fire(el, 'prx:confirmed');
            window.setTimeout(function () { disarm(el); }, 0);
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        arm(el);
    }, true);

    /* --- Копирование и закрытие (data-prx-copy, data-prx-dismiss) --------- */
    document.addEventListener('click', function (e) {
        var copyBtn = closestEl(e.target, '[data-prx-copy]');
        if (copyBtn) {
            var value = copyBtn.getAttribute('data-prx-copy');
            if (value) {
                // Значением может быть селектор элемента (#id): копируем его
                // содержимое, а не литеральную строку селектора (прод 25.11.2026:
                // «Копировать» в prx395 клал в буфер «#prx395-text»).
                var source = value.charAt(0) === '#' ? document.querySelector(value) : null;
                var text = value;
                if (source) {
                    text = (source.tagName === 'INPUT' || source.tagName === 'TEXTAREA')
                        ? (source.value || '')
                        : (source.textContent || '');
                }
                PRX.copyText(text).then(function () {
                    var ico = copyBtn.querySelector('i');
                    if (!ico) { PRX.toast('Скопировано'); return; }
                    if (ico._prxCopyClass === undefined || ico._prxCopyClass === null) ico._prxCopyClass = ico.className;
                    ico.className = ico._prxCopyClass.replace('fa-copy', 'fa-check') + ' text-emerald-600';
                    window.clearTimeout(ico._prxCopyTimer);
                    ico._prxCopyTimer = window.setTimeout(function () {
                        ico.className = ico._prxCopyClass;
                        ico._prxCopyClass = null;
                    }, 1200);
                }, function () { PRX.toast('Не удалось скопировать'); });
            }
        }

        var closeBtn = closestEl(e.target, '[data-prx-dismiss]');
        if (closeBtn) {
            var sel = closeBtn.getAttribute('data-prx-dismiss');
            var box = sel ? document.querySelector(sel) : closestEl(closeBtn, '.prx-flash, [data-prx-dismissible]');
            if (box) box.remove();
        }
    });

    /* --- Горячие клавиши ---------------------------------------------------
     * PRX.hotkey('mod+s', save) — Ctrl+S / ⌘S, в любой раскладке (буквы
     * сверяются по e.code, поэтому «Ctrl+Ы» тоже сработает).
     * PRX.hotkey('mod+enter', parse, { within: textarea }) — только когда
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

    PRX.hotkey = function (combo, handler, opts) {
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
        Array.prototype.forEach.call(document.querySelectorAll('[data-prx-stopwatch]'), function (el) {
            if (!el._prxWatch) PRX.stopwatch(el, Number(el.getAttribute('data-prx-stopwatch')) || 0);
        });
    }

    /* CSRF: сервер (Prx_PostGuard) требует токен у каждого небезопасного
       запроса. Один хук добавляет заголовок X-CSRF-Token ко всем same-origin
       POST/PUT/PATCH/DELETE — модульным скриптам не нужно таскать токен. */
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }
    PRX.csrfToken = csrfToken;

    (function installCsrfHook() {
        var UNSAFE = { POST: 1, PUT: 1, PATCH: 1, DELETE: 1 };

        function sameOrigin(url) {
            if (!url) return true;
            if (url.charAt(0) === '/' || url.charAt(0) === '?') return true;
            try { return new URL(url, window.location.href).origin === window.location.origin; }
            catch (e) { return false; }
        }

        var nativeFetch = window.fetch;
        if (typeof nativeFetch === 'function') {
            window.fetch = function (input, init) {
                var isRequest = input && typeof input === 'object';
                var url = isRequest ? input.url : (input || '');
                var method = ((init && init.method) || (isRequest && input.method) || 'GET').toUpperCase();

                if (UNSAFE[method] && sameOrigin(url)) {
                    var headers = new Headers((init && init.headers) || (isRequest && input.headers) || {});
                    if (!headers.has('X-CSRF-Token') && csrfToken()) {
                        headers.set('X-CSRF-Token', csrfToken());
                    }
                    if (isRequest) {
                        // Request нельзя менять на месте: пересобираем, иначе
                        // потеряются body, signal и keepalive.
                        input = new Request(input, { headers: headers });
                    } else {
                        init = Object.assign({}, init || {}, { headers: headers });
                    }
                }
                return nativeFetch.call(this, input, init);
            };
        }

        var xhr = window.XMLHttpRequest;
        if (xhr && xhr.prototype) {
            var nativeOpen = xhr.prototype.open;
            var nativeSend = xhr.prototype.send;
            xhr.prototype.open = function (method, url) {
                this.__prxMethod = String(method).toUpperCase();
                this.__prxUrl = String(url);
                return nativeOpen.apply(this, arguments);
            };
            xhr.prototype.send = function () {
                if (UNSAFE[this.__prxMethod] && sameOrigin(this.__prxUrl) && csrfToken()) {
                    this.setRequestHeader('X-CSRF-Token', csrfToken());
                }
                return nativeSend.apply(this, arguments);
            };
        }
    })();

    window.PRX = PRX;

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDeclarative);
    else initDeclarative();

})(window, document);
