<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/*
 * Поведение формы /aiscan: переключатель «готовая цель / свой эндпоинт»,
 * подпись пресета, счётчик своих промптов и — главное — живая связка
 * подтверждения права на тест: для настоящего эндпоинта кнопка запуска
 * неактивна, пока галочка не стоит; для песочницы подтверждение отключено
 * с пояснением. Сервер дублирует это правило (Controller_Nj672_Scan).
 */
(function () {
    'use strict';

    var byId = function (id) { return document.getElementById(id); };
    var profile = byId('nj672-profile');
    var profileBox = byId('nj672-profile-box');
    var profileNote = byId('nj672-profile-note');
    var customBox = byId('nj672-custom-box');
    var attest = byId('nj672-attest');
    var attestBox = byId('nj672-attest-box');
    var attestHint = byId('nj672-attest-hint');
    var submit = byId('nj672-submit');
    var submitHint = byId('nj672-submit-hint');
    var dataset = byId('nj672-dataset');
    var datasetCount = byId('nj672-dataset-count');
    var datasetFile = byId('nj672-dataset-file');
    var notes = window.__nj672ProfileNotes || {};
    var mode = 'profile';

    function isSandbox() {
        return mode === 'profile' && profile && profile.value.indexOf('sandbox_') === 0;
    }

    function refreshProfileNote() {
        if (profile && profileNote) {
            profileNote.textContent = notes[profile.value] || '';
        }
    }

    function refreshAttest() {
        if (!attest || !submit) { return; }
        var sandbox = isSandbox();
        attest.disabled = sandbox;
        if (sandbox) { attest.checked = false; }

        var required = !sandbox;
        var blocked = required && !attest.checked;
        submit.disabled = blocked;
        submit.classList.toggle('opacity-50', blocked);
        submit.classList.toggle('cursor-not-allowed', blocked);

        if (attestHint) {
            attestHint.textContent = sandbox
                ? 'Песочница — фейковая модель внутри воркера: право на тест не требуется, атаки никуда не уходят.'
                : 'Подтверждение фиксируется в паспорте отчёта. Без него сервер прогон не создаст.';
        }
        if (submitHint) {
            submitHint.textContent = blocked
                ? 'Сначала подтвердите право на тест — это настоящий эндпоинт.'
                : '';
        }
        if (attestBox) {
            attestBox.classList.toggle('n2j-flash-warn', blocked);
            attestBox.classList.toggle('n2j-flash-info', !blocked);
            attestBox.classList.toggle('opacity-70', sandbox);
        }
    }

    function applyMode(next) {
        mode = next;
        var buttons = document.querySelectorAll('[data-nj672-mode]');
        Array.prototype.forEach.call(buttons, function (btn) {
            var on = btn.getAttribute('data-nj672-mode') === mode;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        var radioProfile = byId('nj672-mode-profile');
        var radioCustom = byId('nj672-mode-custom');
        if (radioProfile) { radioProfile.checked = mode === 'profile'; }
        if (radioCustom) { radioCustom.checked = mode === 'custom'; }
        if (profileBox) { profileBox.classList.toggle('hidden', mode !== 'profile'); }
        if (customBox) { customBox.classList.toggle('hidden', mode !== 'custom'); }
        refreshAttest();
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-nj672-mode]'), function (btn) {
        btn.addEventListener('click', function () { applyMode(btn.getAttribute('data-nj672-mode')); });
    });

    if (profile) {
        profile.addEventListener('change', function () {
            refreshProfileNote();
            refreshAttest();
        });
    }
    if (attest) { attest.addEventListener('change', refreshAttest); }

    function recountDataset() {
        if (!dataset || !datasetCount) { return; }
        var blocks = dataset.value.split(/\n\s*\n/).filter(function (b) { return b.trim() !== ''; });
        datasetCount.textContent = String(blocks.length);
    }

    if (dataset) { dataset.addEventListener('input', recountDataset); }
    if (datasetFile && dataset) {
        datasetFile.addEventListener('change', function () {
            var file = datasetFile.files && datasetFile.files[0];
            if (!file) { return; }
            file.text().then(function (text) {
                dataset.value = text.slice(0, 30000);
                recountDataset();
                dataset.closest('details').open = true;
            });
        });
    }

    refreshProfileNote();
    applyMode('profile');
    recountDataset();
})();
</script>
