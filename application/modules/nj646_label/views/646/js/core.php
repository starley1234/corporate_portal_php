<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/**
 * Ядро JS «Разбора состава»: помощники, которые нужны нескольким страницам.
 *
 * Владелец (03.10.2026): «/label перегружена функционалом — тут должна быть
 * простая страница для ввода информации… а база знаний, мои разборы и мой
 * профиль — свои страницы». Страницы разъехались, а работа с сетью, экранирование
 * и живой статус-ряд остались общими — вот они здесь.
 *
 * Без внешних библиотек и без сборщиков: fetch + canvas. Все запросы — к
 * /label/api/*, ответы — JSON. Классы Tailwind пишем целиком: динамическая
 * склейка в сборку CSS не попадает.
 */
window.N646 = (function () {
    'use strict';

    function $(sel, root) {
        return (root || document).querySelector(sel);
    }

    function $$(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = text === null || text === undefined ? '' : String(text);
        return d.innerHTML;
    }

    function api(action, data, method) {
        var m = method || 'POST';
        var opts = {
            method: m,
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        };

        if (m === 'POST') {
            opts.body = JSON.stringify(data || {});
        }

        return fetch('/label/api/' + action, opts).then(function (r) {
            return r.json().catch(function () {
                return { ok: false, error: 'Сервер ответил не JSON (код ' + r.status + ')' };
            });
        });
    }

    /** Ответ в подпись рядом с кнопкой: одинаково на всех страницах модуля. */
    function say(sel, text) {
        var box = typeof sel === 'string' ? $(sel) : sel;

        if (box) {
            box.textContent = text === null || text === undefined ? '' : String(text);
        }
    }

    /** Кнопка «работаю»: спиннер приклеен к действию, а не живёт отдельно. */
    function busy(btn, text) {
        if (!btn) { return; }

        if (!btn.__idle) {
            btn.__idle = btn.innerHTML;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner n2j-spin mr-1"></i>' + esc(text || 'Работаю…');
    }

    function idle(btn) {
        if (!btn) { return; }

        btn.disabled = false;

        if (btn.__idle) {
            btn.innerHTML = btn.__idle;
        }
    }

    // ------------------------------------------------------- ЖИВОЙ ХОД РАБОТЫ
    //
    // Эталон — общий статус-ряд платформы (partials/live_status): спиннер,
    // название этапа и секундомер в одной неразрывной группе, список этапов
    // воркера и честное «нет пульса». Полосы хода в ряду нет: владелец убрал
    // её из эталона — анимация показывала движение, не связанное с работой.
    //
    // Ряды помечаем data-live-keep: когда задача завершится, страница НЕ
    // перезагружается (иначе пропали бы фото и введённый текст) — ряд шлёт
    // событие n2j:live-done, и страница сама решает, что делать дальше.

    function liveTemplate(code) {
        return document.querySelector('[data-n646-live-tpl="' + code + '"]');
    }

    /** Вставить живой ряд в host и вернуть его (или null, если шаблона нет). */
    function mountLive(code, host, url, title) {
        var tpl = liveTemplate(code);

        if (!tpl || !tpl.content || !host) { return null; }

        var el = tpl.content.firstElementChild.cloneNode(true);
        el.setAttribute('data-live-url', url);
        el.setAttribute('data-live-keep', '1');

        if (title) {
            var box = el.querySelector('[data-live-title]');
            if (box) { box.textContent = title; }
        }

        host.appendChild(el);

        if (window.N2JLive && window.N2JLive.register) {
            window.N2JLive.register(el);
        }

        return el;
    }

    return {
        $: $,
        $$: $$,
        esc: esc,
        api: api,
        say: say,
        busy: busy,
        idle: idle,
        mountLive: mountLive
    };
})();
</script>
