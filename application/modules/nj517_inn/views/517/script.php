<?php defined('SYSPATH') or die('No direct script access.');

/** @var string $csrf токен для отдельного запроса к ФНС */
?>
<script>
(function () {
    'use strict';

    var input = document.getElementById('inn-number');
    var result = document.getElementById('inn-result');
    var csrf = <?php echo json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function normalize(value) {
        var raw = String(value == null ? '' : value).trim();
        if (!raw) return { error: 'empty', message: 'Введите ИНН.' };
        if (!/^[0-9\s\u00a0\u202f\u2010-\u2015-]+$/.test(raw)) {
            return { error: 'bad_format', message: 'Оставьте в поле только цифры, пробелы или дефисы.' };
        }
        var inn = raw.replace(/[^0-9]/g, '');
        if (inn.length !== 10 && inn.length !== 12) {
            return { error: 'bad_length', message: 'ИНН должен содержать 10 цифр для организации или 12 цифр для физлица/ИП.' };
        }
        return { inn: inn, digits: inn.length };
    }

    function controlDigit(digits, weights) {
        var sum = 0;
        for (var i = 0; i < weights.length; i++) sum += Number(digits.charAt(i)) * weights[i];
        return (sum % 11) % 10;
    }

    function validate(value) {
        var normalized = normalize(value);
        if (normalized.error) return normalized;
        var inn = normalized.inn;
        var valid;
        if (inn.length === 10) {
            valid = controlDigit(inn, [2, 4, 10, 3, 5, 9, 4, 6, 8]) === Number(inn.charAt(9));
        } else {
            var first = controlDigit(inn, [7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
            var second = controlDigit(inn, [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
            valid = first === Number(inn.charAt(10)) && second === Number(inn.charAt(11));
        }
        if (!valid) return { error: 'bad_checksum', message: 'Контрольные цифры не сходятся. Проверьте номер по документу.' };
        return {
            valid: true,
            inn: inn,
            digits: inn.length,
            type: inn.length === 10 ? 'organization' : 'person',
            type_label: inn.length === 10 ? 'Юридическое лицо' : 'Физлицо или ИП'
        };
    }

    function showHint(message) {
        result.innerHTML = '<div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">' + esc(message) + '</div>';
    }

    function showError(message) {
        result.innerHTML = '<div class="n2j-flash n2j-flash-err block"><i class="fas fa-triangle-exclamation mr-2"></i>' + esc(message) + '</div>';
    }

    function showValid(check) {
        var h = '<div class="n2j-flash n2j-flash-ok sm:p-6 block">';
        h += '<p class="text-xs font-semibold uppercase tracking-wide text-emerald-800 dark:text-emerald-300">Контрольные цифры верны</p>';
        h += '<p class="mt-1 break-all text-2xl font-extrabold tracking-wide text-gray-900 dark:text-white">' + esc(check.inn) + '</p>';
        h += '<p class="mt-1 text-sm text-gray-700 dark:text-gray-200">' + esc(check.type_label) + ' · ' + check.digits + ' цифр</p>';
        h += '<p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Это подтверждает только правильность формата и контрольных цифр — не регистрацию и не действующий статус.</p>';
        if (check.type === 'organization') {
            h += '<div class="mt-4 rounded-lg border border-blue-200 bg-white/80 p-3 dark:border-blue-900/60 dark:bg-gray-900/50">';
            h += '<p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Нужно проверить организацию?</p>';
            h += '<p class="mt-1 text-xs text-gray-600 dark:text-gray-300">По нажатию ИНН будет передан в открытый реестр ФНС. Найденная общедоступная карточка сохранится в справочнике N2J; действуют лимиты запросов.</p>';
            h += '<button type="button" id="inn-lookup" class="n2j-btn-process mt-3"><i class="fas fa-building"></i> Проверить организацию в ФНС</button>';
            h += '</div>';
        } else {
            h += '<p class="mt-4 rounded-lg bg-white/70 p-3 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300"><i class="fas fa-lock mr-1.5 text-emerald-600"></i>Удалённый поиск для 12 цифр отключён: номер остаётся в браузере.</p>';
        }
        h += '</div>';
        result.innerHTML = h;
    }

    function showCard(card) {
        var inn = String(card.inn || '').replace(/\D/g, '');
        var ogrn = String(card.ogrn || '').replace(/\D/g, '');
        var href = (ogrn.length === 13 || ogrn.length === 15) ? '/orgs/' + encodeURIComponent(ogrn) : '/orgs';
        var name = card.short_name || card.full_name || 'Организация из открытого реестра ФНС';
        var h = '<div class="n2j-card border-blue-200 dark:border-blue-900/60">';
        h += '<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300"><i class="fas fa-circle-check mr-1.5"></i>Карточка получена из ФНС</p>';
        h += '<h2 class="n2j-h2 mt-1">' + esc(name) + '</h2>';
        h += '<dl class="mt-4 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">';
        if (inn) h += '<div><dt class="text-xs text-gray-500 dark:text-gray-400">ИНН</dt><dd class="font-semibold text-gray-800 dark:text-gray-200">' + esc(inn) + '</dd></div>';
        if (ogrn) h += '<div><dt class="text-xs text-gray-500 dark:text-gray-400">ОГРН</dt><dd class="font-semibold text-gray-800 dark:text-gray-200">' + esc(ogrn) + '</dd></div>';
        if (card.kpp) h += '<div><dt class="text-xs text-gray-500 dark:text-gray-400">КПП</dt><dd class="font-semibold text-gray-800 dark:text-gray-200">' + esc(card.kpp) + '</dd></div>';
        if (card.status) h += '<div><dt class="text-xs text-gray-500 dark:text-gray-400">Статус в карточке</dt><dd class="font-semibold text-gray-800 dark:text-gray-200">' + esc(card.status) + '</dd></div>';
        if (card.reg_date) h += '<div><dt class="text-xs text-gray-500 dark:text-gray-400">Дата регистрации</dt><dd class="font-semibold text-gray-800 dark:text-gray-200">' + esc(card.reg_date) + '</dd></div>';
        if (card.address) h += '<div class="sm:col-span-2"><dt class="text-xs text-gray-500 dark:text-gray-400">Адрес</dt><dd class="font-semibold text-gray-800 dark:text-gray-200">' + esc(card.address) + '</dd></div>';
        h += '</dl>';
        h += '<div class="mt-4 flex flex-wrap gap-2"><a href="' + esc(href) + '" class="n2j-btn-secondary"><i class="fas fa-arrow-up-right-from-square"></i> Открыть карточку организации</a><a href="/check" class="n2j-btn-secondary"><i class="fas fa-magnifying-glass-chart"></i> Проверить контрагента</a></div>';
        if (card.fetched_at) h += '<p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Карточка обновлена: ' + esc(card.fetched_at) + '</p>';
        h += '</div>';
        result.innerHTML = h;
    }

    function check() {
        var raw = input.value;
        if (!String(raw).trim()) {
            showHint('Введите номер: формат и контрольные цифры проверяются в браузере; введите 10 или 12 цифр.');
            return;
        }
        var checked = validate(raw);
        if (!checked.valid) showError(checked.message);
        else showValid(checked);
    }

    function lookup() {
        var checked = validate(input.value);
        if (!checked.valid || checked.type !== 'organization') return;
        var button = document.getElementById('inn-lookup');
        if (button) { button.disabled = true; button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Запрашиваем ФНС…'; }
        result.innerHTML = '<div class="n2j-flash n2j-flash-info block"><i class="fas fa-spinner fa-spin mr-2"></i>Запрашиваем открытые сведения. Обычно это занимает несколько секунд.</div>';
        fetch('/nj517/api/lookup', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: new URLSearchParams({csrf: csrf, inn: checked.inn})
        }).then(function (response) {
            return response.json().then(function (payload) { return {response: response, payload: payload}; });
        }).then(function (data) {
            if (!data.response.ok || !data.payload.ok) {
                showError(data.payload.message || 'Не удалось подтвердить сведения. Попробуйте позже.');
                return;
            }
            showCard(data.payload.result.card);
        }).catch(function () {
            showError('Нет связи со справочником или ФНС. Контрольные цифры остаются верными, но подтвердить регистрацию сейчас не удалось.');
        });
    }

    input.addEventListener('input', check);
    document.getElementById('inn-form').addEventListener('submit', function (event) {
        event.preventDefault();
        check();
    });
    result.addEventListener('click', function (event) {
        var button = event.target.closest('#inn-lookup');
        if (button) lookup();
    });

    window.NJ517 = { normalize: normalize, validate: validate };
    check();
})();
</script>
