<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/*
 * Фильтр находок сегментами v6: показывает проверки одного итога
 * (все/пробитые/спорные/отбитые/ошибки цели), пустой набор получает
 * пояснение вместо пустоты. Доказательства не перерисовываются — только
 * класс hidden на готовых <details>.
 */
(function () {
    'use strict';

    var buttons = document.querySelectorAll('[data-nj672-filter]');
    if (!buttons.length) { return; }

    function apply(status) {
        Array.prototype.forEach.call(buttons, function (btn) {
            var on = btn.getAttribute('data-nj672-filter') === status;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-nj672-finding]'), function (el) {
            var show = status === 'all' || el.getAttribute('data-nj672-finding') === status;
            el.classList.toggle('hidden', !show);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-nj672-suite]'), function (suite) {
            var visible = suite.querySelectorAll('[data-nj672-finding]:not(.hidden)').length;
            var empty = suite.querySelector('[data-nj672-suite-empty]');
            suite.classList.toggle('hidden', visible === 0);
            if (empty) { empty.classList.toggle('hidden', visible > 0 || status === 'all'); }
        });
    }

    Array.prototype.forEach.call(buttons, function (btn) {
        btn.addEventListener('click', function () { apply(btn.getAttribute('data-nj672-filter')); });
    });

    // Переход из «Главных проблем»: показать все и раскрыть находку по якорю.
    document.addEventListener('click', function (e) {
        var link = e.target.closest && e.target.closest('a[href^="#f-"]');
        if (!link) { return; }
        apply('all');
        var target = document.getElementById(link.getAttribute('href').slice(1));
        if (target) { target.open = true; }
    });

    apply('all');
})();
</script>
