/* nj644_military_pension — DOM, локальные настройки и вывод ориентировочного расчёта. */
(function () {
    'use strict';
    var root = document.getElementById('pension-app');
    if (!root || !window.Nj644Pension) return;

    var core = window.Nj644Pension;
    var settingsNode = document.getElementById('pension-settings');
    if (!settingsNode) return;
    var config;
    try { config = JSON.parse(settingsNode.textContent); } catch (_) { return; }
    var defaults = core.normalizeSettings({}, config);
    var state = { parameters: defaults, settingsValid: true };
    var STORAGE_KEY = 'nj644.military-pension.settings.v2';

    function el(id) { return document.getElementById('pension-' + id); }
    function value(id) { var node = el(id); return node ? node.value : ''; }
    function checked(id) { var node = el(id); return Boolean(node && node.checked); }
    function show(node, visible) {
        if (!node) return;
        node.classList.toggle('hidden', !visible);
        node.style.display = visible ? '' : 'none';
    }
    function formatRub(number) {
        return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(number) + ' ₽';
    }
    function formatPercent(number) {
        return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(number) + '%';
    }
    function formatMultiplier(number) {
        return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(number);
    }

    function clearResult() {
        show(el('result-empty'), true);
        show(el('ineligible'), false);
        show(el('result-body'), false);
    }
    function inputError(text) {
        el('error').textContent = text || '';
        show(el('error'), Boolean(text));
        if (text) clearResult();
    }
    function settingsError(text) {
        el('settings-error').textContent = text || '';
        show(el('settings-error'), Boolean(text));
        if (text) clearResult();
    }

    function applySettings(parameters) {
        el('setting-coefficient-percent').value = parameters.coefficient_percent;
        el('setting-coefficient-from').value = parameters.coefficient_from;
        el('setting-coefficient-to').value = parameters.coefficient_to;
        el('setting-rpp-rub').value = parameters.rpp_rub;
        el('setting-service-min-years').value = parameters.service_min_years;
        el('setting-service-base-percent').value = parameters.service_base_percent;
        el('setting-service-percent-per-year').value = parameters.service_percent_per_year;
        el('setting-service-max-percent').value = parameters.service_max_percent;
        el('setting-mixed-min-total-years').value = parameters.mixed_min_total_years;
        el('setting-mixed-min-service-years').value = Math.floor(parameters.mixed_min_service_months / 12);
        el('setting-mixed-min-service-months').value = parameters.mixed_min_service_months % 12;
        el('setting-mixed-min-age').value = parameters.mixed_min_age;
        el('setting-mixed-base-percent').value = parameters.mixed_base_percent;
        el('setting-mixed-percent-per-year').value = parameters.mixed_percent_per_year;
    }

    function settingsFromInputs() {
        var serviceYearsRaw = value('setting-mixed-min-service-years');
        var serviceMonthsRaw = value('setting-mixed-min-service-months');
        var serviceYears = serviceYearsRaw === '' ? NaN : Number(serviceYearsRaw);
        var serviceMonths = serviceMonthsRaw === '' ? NaN : Number(serviceMonthsRaw);
        return {
            coefficient_percent: value('setting-coefficient-percent'),
            coefficient_from: value('setting-coefficient-from'),
            coefficient_to: value('setting-coefficient-to'),
            rpp_rub: value('setting-rpp-rub'),
            service_min_years: value('setting-service-min-years'),
            service_base_percent: value('setting-service-base-percent'),
            service_percent_per_year: value('setting-service-percent-per-year'),
            service_max_percent: value('setting-service-max-percent'),
            mixed_min_total_years: value('setting-mixed-min-total-years'),
            mixed_min_service_months: serviceYears * 12 + serviceMonths,
            mixed_min_age: value('setting-mixed-min-age'),
            mixed_base_percent: value('setting-mixed-base-percent'),
            mixed_percent_per_year: value('setting-mixed-percent-per-year'),
        };
    }

    function isDefaultParameters() {
        return Object.keys(defaults).every(function (key) { return state.parameters[key] === defaults[key]; });
    }
    function updateSettingsStatus() {
        el('settings-status').textContent = isDefaultParameters()
            ? 'Официальные значения из конфигурации сайта; проверены ' + config.reviewed_on + '.'
            : 'Настройки формулы изменены и сохранены только в этом браузере.';
    }
    function persistSettings() {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state.parameters));
            updateSettingsStatus();
        } catch (_) {
            el('settings-status').textContent = 'Изменения действуют до закрытия вкладки: браузер запретил хранилище.';
        }
    }

    function matchingPeriod(parameters) {
        var periods = config.periods || {};
        return Object.keys(periods).find(function (key) {
            var period = periods[key];
            return Number(period.coefficient_percent) === parameters.coefficient_percent
                && period.from === parameters.coefficient_from && period.to === parameters.coefficient_to;
        }) || 'custom';
    }
    function setPeriodPresentation() {
        var key = matchingPeriod(state.parameters);
        var select = el('period');
        if (select) select.value = key;
        var period = (config.periods || {})[key];
        var note = el('period-note');
        if (note) {
            note.textContent = period
                ? 'Период: ' + period.from + ' — ' + period.to + '; понижающая доля ' + formatPercent(period.coefficient_percent) + '.'
                : 'Используется изменённый вручную сценарий из настроек формулы.';
        }
        show(el('proposal-warning'), Boolean(period && period.status === 'proposal'));
    }
    function setMethodFields() {
        var mixed = value('method') === 'mixed';
        show(el('service-fields'), !mixed);
        show(el('mixed-fields'), mixed);
    }

    function calculatorInput() {
        var method = value('method');
        var input = {
            method: method,
            date: value('date'),
            position_salary: value('position-salary'),
            rank_salary: value('rank-salary'),
            nuclear_multiplier: value('nuclear-multiplier'),
            qualification_percent: value('qualification-percent'),
            service_allowance_percent: value('service-allowance-percent'),
            other_allowance: value('other-allowance'),
            combat_veteran: checked('combat-veteran'),
            dependents: value('dependents'),
            age80_or_group1: checked('age80-or-group1'),
            disability_percent: value('disability-percent'),
            special_monthly: value('special-monthly'),
            district_coefficient: value('district-coefficient'),
        };
        if (method === 'service') {
            input.service_years = value('service-years');
            input.service_months = value('service-months');
        } else {
            input.total_years = value('total-years');
            input.total_months = value('total-months');
            input.service_years = value('mixed-service-years');
            input.service_months = value('mixed-service-months');
            input.age = value('age');
            input.dismissal_reason = value('dismissal-reason');
        }
        return input;
    }

    function additionRows(result) {
        var labels = {
            combat_veteran: 'Ветеран боевых действий',
            dependents: 'Иждивенцы',
            age80_or_group1: '80 лет / инвалидность I группы',
            disability: 'Увеличение по инвалидности',
            special_monthly: 'Ежемесячная доплата',
        };
        return Object.keys(labels).filter(function (key) { return result.additions[key] > 0; }).map(function (key) {
            return { label: labels[key], amount: result.additions[key] };
        });
    }

    function renderResult(result) {
        inputError('');
        if (!result.eligible) {
            show(el('result-empty'), false);
            show(el('result-body'), false);
            show(el('ineligible'), true);
            var reasons = el('reasons'); reasons.replaceChildren();
            result.reasons.forEach(function (reason) {
                var item = document.createElement('li'); item.textContent = reason; reasons.appendChild(item);
            });
            return;
        }
        show(el('result-empty'), false);
        show(el('ineligible'), false);
        show(el('result-body'), true);
        el('result-label').textContent = result.method === 'service'
            ? 'Вариант по выслуге · ' + result.full_years + ' полных лет'
            : 'Смешанный стаж · ' + result.full_years + ' полных лет общего стажа';
        el('result-amount').textContent = formatRub(result.amount);
        el('result-district').textContent = result.district_coefficient === 1
            ? 'Без районного коэффициента' : 'Районный коэффициент × ' + formatMultiplier(result.district_coefficient);
        el('breakdown-base').textContent = formatRub(result.base);
        el('breakdown-percent').textContent = formatPercent(result.pension_percent);
        el('breakdown-main').textContent = formatRub(result.main_amount);
        el('breakdown-additions').textContent = formatRub(result.additions_total);

        var component = result.base_components;
        el('breakdown-base-detail').textContent = 'ОВД ' + formatRub(component.position_salary)
            + (component.nuclear_multiplier !== 1 ? ' × ' + formatMultiplier(component.nuclear_multiplier) : '')
            + ' + ОВЗ ' + formatRub(component.rank_salary)
            + ' + квалификация ' + formatRub(component.qualification_allowance)
            + ' + выслуга ' + formatRub(component.service_allowance)
            + ' + прочие ' + formatRub(component.other_allowance) + '.';
        var additionsList = el('breakdown-additions-detail'); additionsList.replaceChildren();
        var rows = additionRows(result);
        if (!rows.length) {
            var empty = document.createElement('li'); empty.textContent = 'Не выбраны.'; additionsList.appendChild(empty);
        } else {
            rows.forEach(function (row) {
                var item = document.createElement('li'); item.textContent = row.label + ': ' + formatRub(row.amount) + '.'; additionsList.appendChild(item);
            });
        }
        var first = formatRub(result.base) + ' × ' + formatPercent(result.pension_percent)
            + ' = ' + formatRub(result.before_coefficient) + '; × ' + formatPercent(result.coefficient_percent)
            + ' = ' + formatRub(result.main_amount) + '.';
        el('result-formula').textContent = first + ' После выбранных увеличений ' + formatRub(result.before_district)
            + (result.district_coefficient !== 1 ? ' × ' + formatMultiplier(result.district_coefficient) : '')
            + ' = ' + formatRub(result.amount) + '.';
    }

    function refresh() {
        setMethodFields();
        inputError('');
        if (!state.settingsValid) { clearResult(); return; }
        var salaryFields = [value('position-salary'), value('rank-salary')];
        if (!salaryFields.some(function (field) { return field !== ''; })) {
            clearResult();
            return;
        }
        try { renderResult(core.calculate(calculatorInput(), state.parameters, config)); }
        catch (error) { inputError(error.message); }
    }

    function changeSettings() {
        try {
            state.parameters = core.normalizeSettings(settingsFromInputs(), config);
            state.settingsValid = true;
            settingsError('');
            persistSettings();
            setPeriodPresentation();
            refresh();
        } catch (error) {
            state.settingsValid = false;
            settingsError(error.message);
            setPeriodPresentation();
        }
    }
    function choosePeriod() {
        var key = value('period');
        var period = (config.periods || {})[key];
        if (!period) { setPeriodPresentation(); return; }
        try {
            var replacement = Object.assign({}, state.parameters, {
                coefficient_percent: period.coefficient_percent,
                coefficient_from: period.from,
                coefficient_to: period.to,
            });
            state.parameters = core.normalizeSettings(replacement, config);
            state.settingsValid = true;
            applySettings(state.parameters);
            settingsError('');
            persistSettings();
            setPeriodPresentation();
            refresh();
        } catch (error) {
            state.settingsValid = false;
            settingsError(error.message);
        }
    }
    function restoreSettings() {
        state.parameters = core.normalizeSettings({}, config);
        state.settingsValid = true;
        applySettings(state.parameters);
        settingsError('');
        try { window.localStorage.removeItem(STORAGE_KEY); } catch (_) { /* Хранилище может быть отключено. */ }
        updateSettingsStatus();
        setPeriodPresentation();
        refresh();
    }
    function loadSettings() {
        state.parameters = defaults;
        try {
            var stored = window.localStorage.getItem(STORAGE_KEY);
            if (stored) state.parameters = core.normalizeSettings(JSON.parse(stored), config);
        } catch (_) { state.parameters = defaults; }
        applySettings(state.parameters);
        updateSettingsStatus();
        setPeriodPresentation();
    }

    root.addEventListener('input', function (event) {
        if (!event.target.matches('input, select')) return;
        if (event.target.id.indexOf('pension-setting-') === 0) changeSettings();
        else refresh();
    });
    root.addEventListener('change', function (event) {
        if (!event.target.matches('input, select')) return;
        if (event.target.id === 'pension-period') { choosePeriod(); return; }
        if (event.target.id.indexOf('pension-setting-') === 0) { changeSettings(); return; }
        refresh();
    });
    el('settings-reset').addEventListener('click', restoreSettings);

    loadSettings();
    refresh();
})();
