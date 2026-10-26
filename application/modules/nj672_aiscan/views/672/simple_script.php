<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/*
 * Простой режим /aiscan/simple: выбор цели (пресет или свой эндпоинт) и та же
 * живая связка подтверждения права на тест, что в полной форме: для настоящего
 * эндпоинта кнопка «Проверить» неактивна без галочки, для песочницы
 * подтверждение отключено с пояснением. Сервер правило дублирует.
 */
(function () {
    'use strict';

    var byId = function (id) { return document.getElementById(id); };
    var customBox = byId('nj672s-custom-box');
    var attest = byId('nj672s-attest');
    var attestBox = byId('nj672s-attest-box');
    var attestHint = byId('nj672s-attest-hint');
    var submit = byId('nj672s-submit');
    var submitHint = byId('nj672s-submit-hint');
    var radios = document.querySelectorAll('[data-nj672s-target]');

    function selected() {
        var value = '';
        Array.prototype.forEach.call(radios, function (radio) {
            if (radio.checked) { value = radio.value; }
        });
        return value;
    }

    function refresh() {
        var value = selected();
        var custom = value === '__custom';
        var sandbox = !custom && value.indexOf('sandbox_') === 0;

        var modeProfile = byId('nj672s-mode-profile');
        var modeCustom = byId('nj672s-mode-custom');
        if (modeProfile) { modeProfile.checked = !custom; }
        if (modeCustom) { modeCustom.checked = custom; }
        if (customBox) { customBox.classList.toggle('hidden', !custom); }

        if (!attest || !submit) { return; }
        attest.disabled = sandbox;
        if (sandbox) { attest.checked = false; }

        var blocked = !sandbox && !attest.checked;
        submit.disabled = blocked;
        submit.classList.toggle('opacity-50', blocked);
        submit.classList.toggle('cursor-not-allowed', blocked);

        if (attestHint) {
            attestHint.textContent = sandbox
                ? 'Песочница — фейковая модель внутри воркера: право на тест не требуется, атаки никуда не уходят.'
                : 'Подтверждение фиксируется в паспорте отчёта. Без него сервер проверку не запустит.';
        }
        if (submitHint) {
            submitHint.textContent = blocked ? 'Сначала подтвердите право на тест.' : '';
        }
        if (attestBox) {
            attestBox.classList.toggle('n2j-flash-warn', blocked);
            attestBox.classList.toggle('n2j-flash-info', !blocked);
            attestBox.classList.toggle('opacity-70', sandbox);
        }
    }

    Array.prototype.forEach.call(radios, function (radio) {
        radio.addEventListener('change', refresh);
    });
    if (attest) { attest.addEventListener('change', refresh); }

    refresh();
})();
</script>
