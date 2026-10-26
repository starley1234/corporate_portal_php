/** NJ-644: браузерная формула и DOM-поведение калькулятора военной пенсии. */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { JSDOM } from 'jsdom';

const require = createRequire(import.meta.url);
const pension = require('../../public_html/assets/js/nj644-pension-core.js');
const defaults = {
    coefficient_percent: 93.59,
    coefficient_from: '2026-01-01',
    coefficient_to: '2026-12-31',
    rpp_rub: 9424.12,
    service_min_years: 20,
    service_base_percent: 50,
    service_percent_per_year: 3,
    service_max_percent: 85,
    mixed_min_total_years: 25,
    mixed_min_service_months: 150,
    mixed_min_age: 45,
    mixed_base_percent: 50,
    mixed_percent_per_year: 1,
};
const config = {
    reviewed_on: '2026-10-06', defaults,
    periods: {
        '2026-current': { label: '2026: действующая доля 93,59%', coefficient_percent: 93.59, from: '2026-01-01', to: '2026-12-31', status: 'official' },
        '2026-proposal': { label: 'с 01.10.2026: 97,34% — сценарий', coefficient_percent: 97.34, from: '2026-10-01', to: '2026-12-31', status: 'proposal' },
    },
    limits: { max_monthly_rub: 100000000, max_years: 100, max_age: 120 }, sources: [],
};

function ordinary(overrides = {}) {
    return {
        method: 'service', date: '2026-10-06',
        position_salary: 50000, rank_salary: 20000,
        nuclear_multiplier: 1, qualification_percent: 0, service_allowance_percent: 30, other_allowance: 0,
        service_years: 25, service_months: 0,
        combat_veteran: false, dependents: 0, age80_or_group1: false,
        disability_percent: 0, special_monthly: 0, district_coefficient: 1,
        ...overrides,
    };
}
function mixed(overrides = {}) {
    return {
        ...ordinary({ method: 'mixed' }),
        total_years: 25, total_months: 0, service_years: 12, service_months: 6,
        age: 45, dismissal_reason: 'health', ...overrides,
    };
}

test('Обычная выслуга: база ОДС и 30% надбавка, +3 п.п. и действующая доля 93,59%', () => {
    const result = pension.calculate(ordinary(), {}, config);
    assert.equal(result.eligible, true);
    assert.equal(result.base, 91000);
    assert.deepEqual(result.base_components, {
        position_salary: 50000, position_adjusted: 50000, rank_salary: 20000,
        nuclear_multiplier: 1, qualification_allowance: 0,
        service_allowance_percent: 30, service_allowance: 21000, other_allowance: 0,
    });
    assert.equal(result.full_years, 25);
    assert.equal(result.extra_years, 5);
    assert.equal(result.pension_percent, 65);
    assert.equal(result.before_coefficient, 59150);
    assert.equal(result.main_amount, 55358.49);
    assert.equal(result.amount, 55358.49);
});

test('Расширенные поля совпадают с подробным breakdown: квалификация, ядерный множитель, РРП и район', () => {
    const result = pension.calculate(ordinary({
        nuclear_multiplier: 1.25, qualification_percent: 10, service_allowance_percent: 15, other_allowance: 500,
        combat_veteran: true, dependents: 2, age80_or_group1: true, disability_percent: 150,
        special_monthly: 1000, district_coefficient: 1.2,
    }), {}, config);
    assert.equal(result.base_components.position_adjusted, 62500);
    assert.equal(result.base_components.qualification_allowance, 6250);
    assert.equal(result.base_components.service_allowance, 12375);
    assert.equal(result.base, 101625);
    assert.equal(result.main_amount, 61822.04);
    assert.deepEqual(result.additions, {
        combat_veteran: 3015.72, dependents: 6031.44, age80_or_group1: 9424.12, disability: 14136.18, special_monthly: 1000,
    });
    assert.equal(result.additions_total, 33607.46);
    assert.equal(result.before_district, 95429.5);
    assert.equal(result.amount, 114515.4);
});

test('Для обычной пенсии нужны 20 лет и действует максимум 85%; для смешанной — все условия', () => {
    assert.equal(pension.calculate(ordinary({ service_years: 19, service_months: 11 }), {}, config).eligible, false);
    assert.equal(pension.calculate(ordinary({ service_years: 20, service_months: 11 }), {}, config).pension_percent, 50);
    assert.equal(pension.calculate(ordinary({ service_years: 40 }), {}, config).pension_percent, 85);
    assert.equal(pension.calculate(mixed(), {}, config).amount, 42583.45);
    for (const input of [
        mixed({ total_years: 24, total_months: 11 }), mixed({ service_years: 12, service_months: 5 }),
        mixed({ age: 44 }), mixed({ dismissal_reason: 'other' }),
    ]) assert.equal(pension.calculate(input, {}, config).eligible, false);
    assert.equal(pension.calculate(mixed({ total_years: 70, service_years: 30, service_months: 0 }), {}, config).pension_percent, 95);
});

test('Валидация не допускает неутверждённый произвольный процент инвалидности и неверные поля', () => {
    for (const input of [
        ordinary({ position_salary: -1 }), ordinary({ service_months: 12 }), ordinary({ date: '2026-02-30' }),
        ordinary({ disability_percent: 100 }), ordinary({ nuclear_multiplier: 1.1 }), ordinary({ district_coefficient: 2.1 }),
        ordinary({ position_salary: '1e2' }), ordinary({ rank_salary: '   ' }), mixed({ service_years: 26 }),
    ]) assert.throws(() => pension.calculate(input, {}, config), Error);
});

test('Сценарий законопроекта выбирается явно, а настройки РРП и коэффициента валидируются', () => {
    const parameters = { coefficient_percent: 97.34, coefficient_from: '2026-10-01', coefficient_to: '2026-12-31', rpp_rub: 10000 };
    const result = pension.calculate(ordinary(), parameters, config);
    assert.equal(result.coefficient_percent, 97.34);
    assert.equal(result.amount, 57576.61);
    assert.throws(() => pension.calculate(ordinary({ date: '2026-09-30' }), parameters, config), /Понижающая доля/);
    assert.throws(() => pension.normalizeSettings({ coefficient_from: '2026-12-31', coefficient_to: '2026-01-01' }, config), /не может быть раньше/);
});

function buildForm() {
    const inputIds = [
        'date', 'service-years', 'service-months', 'total-years', 'total-months', 'mixed-service-years', 'mixed-service-months', 'age',
        'position-salary', 'rank-salary', 'other-allowance', 'special-monthly',
        'setting-coefficient-percent', 'setting-coefficient-from', 'setting-coefficient-to', 'setting-rpp-rub',
        'setting-service-min-years', 'setting-service-base-percent', 'setting-service-percent-per-year', 'setting-service-max-percent',
        'setting-mixed-min-total-years', 'setting-mixed-min-service-years', 'setting-mixed-min-service-months', 'setting-mixed-min-age',
        'setting-mixed-base-percent', 'setting-mixed-percent-per-year',
    ];
    const controls = inputIds.map((id) => `<input id="pension-${id}">`).join('');
    const html = `<!doctype html><html><body><div id="pension-app">
      <select id="pension-method"><option value="service">Обычная</option><option value="mixed">Смешанная</option></select>
      <select id="pension-period"><option value="2026-current">Действующая</option><option value="2026-proposal">Сценарий</option><option value="custom">Свой</option></select>
      <select id="pension-dismissal-reason"><option value=""></option><option value="health">Здоровье</option></select>
      <select id="pension-nuclear-multiplier"><option value="1">Нет</option><option value="1.25">Да</option></select>
      <select id="pension-qualification-percent"><option value="0">Нет</option><option value="10">10%</option></select>
      <select id="pension-service-allowance-percent"><option value="0">Нет</option><option value="30">30%</option></select>
      <select id="pension-dependents"><option value="0">Нет</option><option value="2">2</option></select>
      <select id="pension-disability-percent"><option value="0">Нет</option><option value="150">150%</option></select>
      <select id="pension-district-coefficient"><option value="1">1</option><option value="1.2">1,2</option></select>
      <input id="pension-combat-veteran" type="checkbox"><input id="pension-age80-or-group1" type="checkbox">
      ${controls}
      <div id="pension-service-fields"></div><div id="pension-mixed-fields" class="hidden"></div>
      <button id="pension-settings-reset" type="button">Сбросить</button>
      <p id="pension-settings-status"></p><p id="pension-settings-error" class="hidden"></p><p id="pension-error" class="hidden"></p>
      <p id="pension-period-note"></p><p id="pension-proposal-warning" class="hidden"></p>
      <p id="pension-result-empty"></p><div id="pension-ineligible" class="hidden"><ul id="pension-reasons"></ul></div>
      <div id="pension-result-body" class="hidden"><span id="pension-result-label"></span><span id="pension-result-amount"></span><span id="pension-result-district"></span>
        <span id="pension-breakdown-base"></span><span id="pension-breakdown-percent"></span><span id="pension-breakdown-main"></span><span id="pension-breakdown-additions"></span>
        <span id="pension-breakdown-base-detail"></span><ul id="pension-breakdown-additions-detail"></ul><span id="pension-result-formula"></span></div>
      </div><script id="pension-settings" type="application/json">${JSON.stringify(config)}</script></body></html>`;
    const dom = new JSDOM(html, { url: 'https://example.test/military-pension', runScripts: 'outside-only' });
    const initial = { date: '2026-10-06', 'service-years': '25', 'service-months': '0', 'total-years': '25', 'total-months': '0', 'mixed-service-years': '12', 'mixed-service-months': '6', age: '45', 'other-allowance': '0', 'special-monthly': '0' };
    for (const [id, value] of Object.entries(initial)) dom.window.document.getElementById('pension-' + id).value = value;
    dom.window.Nj644Pension = pension;
    dom.window.eval(readFileSync(new URL('../../public_html/assets/js/nj644-pension.js', import.meta.url), 'utf8'));
    return dom;
}

test('Форма считает без сервера, показывает сценарий законопроекта и не хранит оклады', () => {
    const dom = buildForm();
    const { document, Event } = dom.window;
    const field = (id) => document.getElementById('pension-' + id);
    const change = (id, val, type = 'input') => { field(id).value = String(val); field(id).dispatchEvent(new Event(type, { bubbles: true })); };
    change('position-salary', 50000); change('rank-salary', 20000); change('service-allowance-percent', 30, 'change');
    assert.equal(field('result-body').classList.contains('hidden'), false);
    assert.match(field('result-amount').textContent, /55.?358,49/);
    change('period', '2026-proposal', 'change');
    assert.equal(field('proposal-warning').classList.contains('hidden'), false);
    assert.match(field('result-amount').textContent, /57.?576,61/);
    const stored = JSON.parse(dom.window.localStorage.getItem('nj644.military-pension.settings.v2'));
    assert.equal(stored.coefficient_percent, 97.34);
    assert.equal(Object.hasOwn(stored, 'position_salary'), false);
    assert.equal(Object.hasOwn(stored, 'age'), false);
    field('combat-veteran').checked = true; field('combat-veteran').dispatchEvent(new Event('change', { bubbles: true }));
    assert.match(field('breakdown-additions').textContent, /3.?015,72/);
    change('setting-rpp-rub', 10000);
    assert.equal(field('period').value, '2026-proposal');
    change('setting-coefficient-percent', 101);
    assert.equal(field('settings-error').classList.contains('hidden'), false);
    assert.equal(field('result-body').classList.contains('hidden'), true);
    field('settings-reset').dispatchEvent(new Event('click', { bubbles: true }));
    assert.equal(dom.window.localStorage.getItem('nj644.military-pension.settings.v2'), null);
    assert.match(field('result-amount').textContent, /58.?374,21/); // 55 358,49 + 3 015,72
    assert.equal(field('period').value, '2026-current');
    dom.window.close();
});
