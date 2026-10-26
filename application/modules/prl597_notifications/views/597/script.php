<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prl597: общие мелочи интерфейса центра уведомлений.
 *
 *   1. (двойное нажатие опасных кнопок — общий data-prl-arm из app.js);
 *   2. занятие кнопок на время запроса;
 *   3. клик по клетке матрицы «событие × канал» — запрос к /prl597/api/sub;
 *   4. получение кода привязки мессенджера (/prl597/api/bind);
 *   5. правка типа события в админке (заполнение формы);
 *   6. предпросмотр шаблона (/prl597/admin/template);
 *   7. ИИ-составление шаблона из имеющегося сообщения
 *      (/prl597/admin/ai_template), выбор готового сообщения из ленты,
 *      чипы подстановок, поиск по событиям и подстановка результата в редактор.
 */
?>

<script>
(function () {
    'use strict';

    /* Уведомление разделено на два явных действия: переход или прочтение без перехода. */
    document.querySelectorAll('.prl597-item[data-id]').forEach(function (item) {
        var id = item.getAttribute('data-id');
        var csrf = item.getAttribute('data-csrf');
        var open = item.querySelector('.prl597-open');
        var read = item.querySelector('.prl597-read');
        if (!id || !csrf) return;

        function markRead(keepalive, done) {
            fetch('/prl597/api/read', {
                method: 'POST', keepalive: !!keepalive,
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: 'csrf=' + encodeURIComponent(csrf) + '&id=' + encodeURIComponent(JSON.stringify([Number(id)]))
            }).catch(function () {}).finally(function () { if (done) done(); });
        }

        if (open) {
            open.addEventListener('click', function (e) {
                e.preventDefault();
                var href = open.getAttribute('data-href') || open.href;
                markRead(true, function () { window.location.href = href; });
            });
        }

        if (read) {
            read.addEventListener('click', function () {
                read.disabled = true;
                read.classList.add('opacity-60');
                markRead(false, function () { window.location.reload(); });
            });
        }
    });

    /* Клетка матрицы «событие × канал»: включить/выключить без перезагрузки. */
    document.querySelectorAll('.prl597-cell').forEach(function (cell) {
        cell.addEventListener('click', function () {
            if (cell.dataset.locked === '1') {
                if (cell.title) { PRL.toast('Это событие обязательное — его нельзя отключить.'); }
                return;
            }

            var on = cell.dataset.on === '1';
            var req = new FormData();
            req.append('csrf', cell.dataset.csrf);
            req.append('event', cell.dataset.event);
            req.append('channel', cell.dataset.channel);
            req.append('enabled', on ? '0' : '1');

            cell.disabled = true;
            cell.classList.add('opacity-60');

            fetch('/prl597/api/sub', {method: 'POST', body: req, credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) { throw new Error((data && data.error) || 'не вышло'); }
                    cell.dataset.on = on ? '0' : '1';
                    var icon = cell.querySelector('i');
                    if (icon) {
                        icon.className = 'fas ' + (on ? 'fa-bell-slash' : 'fa-bell') + ' text-xs';
                    }
                    cell.classList.toggle('bg-emerald-100', !on);
                    cell.classList.toggle('dark:bg-emerald-900/40', !on);
                    cell.classList.toggle('text-emerald-600', !on);
                    cell.classList.toggle('bg-gray-100', on);
                    cell.classList.toggle('dark:bg-gray-700', on);
                    cell.classList.toggle('text-gray-400', on);
                })
                .catch(function () { window.location.reload(); })
                .finally(function () {
                    cell.disabled = false;
                    cell.classList.remove('opacity-60');
                });
        });
    });

    /* Код привязки мессенджера: просим у API и показываем ссылку на бота. */
    document.querySelectorAll('[data-prl597-bind-btn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var channel = btn.getAttribute('data-prl597-bind-btn');
            var box = document.querySelector('[data-prl597-bind="' + channel + '"] [data-prl597-bind-state]');
            var csrf = document.querySelector('#prl597-prefs-form input[name="csrf"]');

            if (!csrf) { return; }

            btn.disabled = true;
            btn.classList.add('opacity-60');

            var req = new FormData();
            req.append('csrf', csrf.value);
            req.append('channel', channel);

            fetch('/prl597/api/bind', {method: 'POST', body: req, credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) { throw new Error((data && data.error) || 'не вышло'); }
                    var url = new URL(data.url || String(btn.getAttribute('data-url')).replace('__CODE__', encodeURIComponent(data.code)));
                    if (url.protocol !== 'https:') { throw new Error('Неверная ссылка на бота'); }
                    if (box) {
                        box.textContent = 'Код ' + data.code + ' — действует 15 минут. ';
                        var link = document.createElement('a');
                        link.href = url.href;
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                        link.className = 'text-blue-600 underline';
                        link.textContent = 'Открыть бота';
                        box.appendChild(link);
                    }
                    window.open(url.href, '_blank', 'noopener,noreferrer');
                })
                .catch(function (e) {
                    if (box) { box.textContent = 'Не вышло: ' + e.message; }
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.classList.remove('opacity-60');
                });
        });
    });

    /* Админка: правка события — заполняем форму из данных строки. */
    var evForm = document.getElementById('prl597-event-form');

    function fillEvent(event) {
        if (!evForm) { return; }

        var map = {
            alias: 'prl_ev_alias', title: 'prl_ev_title', description: 'prl_ev_description',
            icon: 'prl_ev_icon', ability: 'prl_ev_ability', category: 'prl_ev_category',
            placeholders: 'prl_ev_placeholders'
        };

        Object.keys(map).forEach(function (key) {
            var el = document.getElementById(map[key]);
            if (el) { el.value = event[key] || ''; }
        });

        document.getElementById('prl_ev_enabled').checked = !!event.enabled;
        document.getElementById('prl_ev_mandatory').checked = !!event.mandatory;
        document.getElementById('prl_ev_digest').checked = !!event.digest;

        var channels = event.channels || [];
        document.querySelectorAll('#prl_ev_channels input').forEach(function (box) {
            box.checked = channels.indexOf(box.value) >= 0;
        });

        var roles = event.roles || [];
        document.querySelectorAll('#prl_ev_roles input').forEach(function (box) {
            box.checked = roles.indexOf(box.value) >= 0;
        });

        evForm.classList.remove('hidden');
        evForm.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    document.querySelectorAll('[data-prl597-event-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try { fillEvent(JSON.parse(btn.getAttribute('data-prl597-event-edit'))); }
            catch (e) { window.location.reload(); }
        });
    });

    var newBtn = document.querySelector('[data-prl597-event-new]');

    if (newBtn) {
        newBtn.addEventListener('click', function () {
            fillEvent({channels: ['in_app'], roles: ['login'], enabled: true, category: 'system', icon: 'fas fa-bell', ability: 'prl597.notify'});
        });
    }

    var cancelBtn = document.querySelector('[data-prl597-event-cancel]');

    if (cancelBtn && evForm) {
        cancelBtn.addEventListener('click', function () { evForm.classList.add('hidden'); });
    }

    /* Админка: предпросмотр шаблона на демо-данных. */
    document.querySelectorAll('[data-prl597-tpl-preview]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('form');
            if (!form) { return; }

            var out = form.querySelector('[data-prl597-tpl-out]');
            var url = '/prl597/admin/template?event=' + encodeURIComponent(form.getAttribute('data-prl597-tpl-event')) +
                      '&channel=' + encodeURIComponent(form.getAttribute('data-prl597-tpl-channel'));

            fetch(url, {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) { throw new Error((data && data.error) || 'не вышло'); }
                    var p = data.preview || {};
                    out.innerHTML = '<strong>' + (p.subject || '') + '</strong><br>' +
                        String(p.body || '').replace(/\n/g, '<br>');
                    out.classList.remove('hidden');
                })
                .catch(function (e) {
                    out.textContent = 'Не вышло: ' + e.message;
                    out.classList.remove('hidden');
                });
        });
    });

    /* Шаблоны: ИИ собирает текст из имеющегося сообщения. Ничего не сохраняем —
       только показываем и по кнопке подставляем в нужную карточку. */
    (function () {
        var form = document.querySelector('form[data-prl597-ai]');
        if (!form) { return; }

        var out = document.querySelector('[data-prl597-ai-out]'),
            elSubject = document.querySelector('[data-prl597-ai-subject]'),
            elBody = document.querySelector('[data-prl597-ai-body]'),
            elNotes = document.querySelector('[data-prl597-ai-notes]'),
            elMeta = document.querySelector('[data-prl597-ai-meta]'),
            elFlash = document.querySelector('[data-prl597-ai-flash]'),
            btnRun = form.querySelector('[data-prl597-ai-run]'),
            btnApply = document.querySelector('[data-prl597-ai-apply]'),
            btnAgain = document.querySelector('[data-prl597-ai-again]'),
            last = null;

        function flash(text) {
            if (elFlash) { elFlash.textContent = text || ''; }
        }

        function fail(text) {
            if (!out) { return; }
            if (elSubject) { elSubject.textContent = ''; }
            if (elBody) { elBody.textContent = text; }
            if (elNotes) { elNotes.textContent = ''; }
            if (elMeta) { elMeta.textContent = ''; }
            out.classList.remove('hidden');
            last = null;
        }

        /* Тело запроса собираем сами: так форма одинаково уходит и из браузера,
           и из теста, где обёртки FormData вокруг формы нет. */
        function params() {
            var q = new URLSearchParams();

            form.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (el) {
                if (el.disabled) { return; }
                q.append(el.name, el.value === undefined || el.value === null ? '' : el.value);
            });

            return q.toString();
        }

        function run() {
            if (btnRun) { btnRun.disabled = true; btnRun.classList.add('opacity-70'); }
            flash('Собираю… это занимает несколько секунд');

            fetch('/prl597/admin/ai_template', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: params()
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        fail((data && data.error) || 'ИИ не ответил');
                        return;
                    }

                    last = data;
                    if (elSubject) { elSubject.textContent = data.subject || '— (у этого канала темы нет)'; }
                    if (elBody) { elBody.textContent = data.body || ''; }
                    if (elNotes) { elNotes.textContent = data.notes || ''; }
                    if (elMeta) { elMeta.textContent = (data.model || '') + ' · ' + (data.ms || 0) + ' мс'; }
                    out.classList.remove('hidden');
                    flash('');
                    out.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                })
                .catch(function (e) { fail('Не вышло: ' + e.message); })
                .finally(function () {
                    if (btnRun) { btnRun.disabled = false; btnRun.classList.remove('opacity-70'); }
                });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            run();
        });

        if (btnAgain) {
            btnAgain.addEventListener('click', function () { run(); });
        }

        /* Готовое сообщение за сутки можно не копировать руками: выбрали в
           списке — текст сам встал в поле «имеющееся сообщение». */
        var recent = document.querySelector('[data-prl597-ai-recent]');

        if (recent) {
            recent.addEventListener('change', function () {
                var option = recent.options ? recent.options[recent.selectedIndex] : null;
                var text = option ? option.getAttribute('data-text') : '';
                var source = form.querySelector('[name="source"]');

                if (source && text) {
                    source.value = text;
                    flash('Сообщение взято — можно собирать шаблон.');
                }
            });
        }

        if (btnApply) {
            btnApply.addEventListener('click', function () {
                if (!last) { return; }

                var target = document.querySelector(
                    'form[data-prl597-tpl-event="' + last.event_alias + '"][data-prl597-tpl-channel="' + last.channel + '"]'
                );

                if (!target) {
                    flash('Этого канала нет в карточках события — включите канал выше и сохраните.');
                    return;
                }

                var subject = target.querySelector('[name="subject"]'),
                    body = target.querySelector('[name="body"]');

                if (subject && (last.subject || '')) { subject.value = last.subject; }
                if (body) { body.value = last.body || ''; }

                target.classList.add('ring-2', 'ring-blue-400');
                setTimeout(function () { target.classList.remove('ring-2', 'ring-blue-400'); }, 2500);
                target.scrollIntoView({behavior: 'smooth', block: 'center'});
                flash('Подставлено — проверьте предпросмотр и нажмите «Сохранить».');
            });
        }
    })();

    /* Поиск по событиям в шаблонах: у кого-то текста ещё нет — искать его
       прокруткой по десяткам карточек бессмысленно. */
    (function () {
        var filter = document.querySelector('[data-prl597-tpl-filter]'),
            gaps = document.querySelector('[data-prl597-tpl-gaps]'),
            hidden = document.querySelector('[data-prl597-tpl-hidden]');

        if (!filter) { return; }

        var cards = document.querySelectorAll('[data-prl597-tpl-card]');

        function apply() {
            var needle = (filter.value || '').trim().toLowerCase(),
                only_gaps = !!(gaps && gaps.checked),
                shown = 0,
                closed = 0;

            cards.forEach(function (card) {
                var haystack = (card.getAttribute('data-prl597-tpl-search') || '').toLowerCase(),
                    filled = parseInt(card.getAttribute('data-prl597-tpl-filled') || '0', 10),
                    match = (needle === '' || haystack.indexOf(needle) >= 0) && (! only_gaps || filled === 0);

                card.classList.toggle('hidden', ! match);

                if (match) { shown++; } else { closed++; }

                // Поиск по конкретному событию — раскрываем найденное,
                // иначе строка поиска не помогает.
                if (match && needle !== '' && card.open === false) { card.open = true; }
            });

            if (hidden) {
                hidden.textContent = closed > 0 ? 'скрыто: ' + closed : '';
            }

            if (shown === 0 && closed > 0) {
                filter.setAttribute('aria-invalid', 'true');
            } else {
                filter.removeAttribute('aria-invalid');
            }
        }

        filter.addEventListener('input', apply);

        if (gaps) { gaps.addEventListener('change', apply); }

        apply();
    })();

    /* Чипы подстановок: {{title}} вставляется туда, где сейчас курсор. */
    (function () {
        var chips = document.querySelectorAll('[data-prl597-insert]');

        if (!chips.length) { return; }

        var last = null;

        if (document.addEventListener) {
            document.addEventListener('focusin', function (e) {
                var el = e.target;
                if (el && el.name && (el.name === 'body' || el.name === 'subject')) { last = el; }
            });
        }

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                var token = chip.getAttribute('data-prl597-insert') || '';
                var field = last || document.querySelector('form[data-prl597-tpl-event] textarea[name="body"]');

                if (!field || !token) { return; }

                if (typeof field.selectionStart === 'number' && typeof field.setRangeText === 'function') {
                    field.setRangeText(token, field.selectionStart, field.selectionEnd, 'end');
                } else {
                    field.value = (field.value || '') + token;
                }

                if (field.focus) { field.focus(); }
            });
        });
    })();

    /* Долгие действия (тест письма идёт до таймаута SMTP) — блокируем кнопку. */
    document.querySelectorAll('form.prl597-busy').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            var label = form.querySelector('.prl597-busy-label');
            if (btn) { btn.disabled = true; btn.classList.add('opacity-70'); }
            if (label && !label.dataset.orig) {
                label.dataset.orig = label.textContent;
                label.textContent = 'Отправляю…';
            }
        });
    });
})();
</script>
