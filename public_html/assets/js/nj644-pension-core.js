/* nj644_military_pension — проверяемые чистые формулы без HTTP и хранения анкеты. */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.Nj644Pension = factory();
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function fail(message) { throw new Error(message); }

    function number(raw, label, min, max, integer) {
        if (raw === null || raw === undefined || raw === '' || typeof raw === 'boolean') {
            fail('Заполните поле «' + label + '».');
        }
        var normalized = String(raw).trim().replace(/[\s ]/g, '').replace(',', '.');
        var value = typeof raw === 'number' ? raw : (/^\d+(?:\.\d+)?$/.test(normalized) ? Number(normalized) : NaN);
        if (!Number.isFinite(value) || value < min || value > max || (integer && !Number.isInteger(value))) {
            fail('Проверьте поле «' + label + '»: допустимо от ' + min + ' до ' + max + (integer ? ', целое число.' : '.'));
        }
        return value;
    }

    function optionalNumber(raw, label, min, max, integer, fallback) {
        return raw === null || raw === undefined || raw === '' ? fallback : number(raw, label, min, max, integer);
    }

    function flag(raw, label) {
        if (raw === true || raw === 1 || raw === '1' || raw === 'true' || raw === 'да') return true;
        if (raw === false || raw === 0 || raw === '0' || raw === 'false' || raw === 'нет' || raw === '' || raw == null) return false;
        fail('Проверьте поле «' + label + '».');
    }

    function date(raw, label) {
        if (typeof raw !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(raw)) fail('Проверьте поле «' + label + '».');
        var parsed = new Date(raw + 'T00:00:00.000Z');
        if (!Number.isFinite(parsed.getTime()) || parsed.toISOString().slice(0, 10) !== raw) fail('Проверьте поле «' + label + '».');
        return raw;
    }

    function roundMoney(value) {
        return Math.floor(value * 100 + 0.500000001) / 100;
    }

    function normalizeSettings(input, config) {
        input = input || {};
        config = config || {};
        var defaults = config.defaults || config;
        var limits = config.limits || {};
        var result = {};
        Object.keys(defaults).forEach(function (key) {
            result[key] = Object.prototype.hasOwnProperty.call(input, key) ? input[key] : defaults[key];
        });
        var maxYears = Number(limits.max_years) || 100;
        var maxAge = Number(limits.max_age) || 120;
        var maxMoney = Number(limits.max_monthly_rub) || 100000000;
        result.coefficient_percent = number(result.coefficient_percent, 'понижающая доля, %', 0, 100, false);
        result.coefficient_from = date(result.coefficient_from, 'дата начала действия доли');
        result.coefficient_to = date(result.coefficient_to, 'дата окончания действия доли');
        result.rpp_rub = number(result.rpp_rub, 'расчётный размер пенсии', 0, maxMoney, false);
        if (result.coefficient_from > result.coefficient_to) fail('Дата окончания действия доли не может быть раньше даты начала.');

        result.service_min_years = number(result.service_min_years, 'порог выслуги, лет', 1, maxYears, true);
        result.service_base_percent = number(result.service_base_percent, 'базовый процент выслуги', 0, 100, false);
        result.service_percent_per_year = number(result.service_percent_per_year, 'процент за год выслуги', 0, 100, false);
        result.service_max_percent = number(result.service_max_percent, 'предел процента выслуги', 0, 100, false);
        if (result.service_max_percent < result.service_base_percent) fail('Предел обычного варианта не может быть меньше базового процента.');

        result.mixed_min_total_years = number(result.mixed_min_total_years, 'минимум общего стажа, лет', 0, maxYears, true);
        result.mixed_min_service_months = number(result.mixed_min_service_months, 'минимум службы, месяцев', 0, maxYears * 12, true);
        result.mixed_min_age = number(result.mixed_min_age, 'минимальный возраст', 0, maxAge, true);
        result.mixed_base_percent = number(result.mixed_base_percent, 'базовый процент смешанного варианта', 0, 100, false);
        result.mixed_percent_per_year = number(result.mixed_percent_per_year, 'процент за год смешанного стажа', 0, 100, false);
        return result;
    }

    function ineligible(method, base, parameters, calculationDate, reasons) {
        return { eligible: false, method: method, date: calculationDate, base: base, coefficient_percent: parameters.coefficient_percent, amount: null, reasons: reasons };
    }

    function calculate(input, parameters, config) {
        input = input || {};
        var p = normalizeSettings(parameters, config);
        var limits = (config && config.limits) || {};
        var maxMoney = Number(limits.max_monthly_rub) || 100000000;
        var maxYears = Number(limits.max_years) || 100;
        var maxAge = Number(limits.max_age) || 120;
        var method = input.method;
        if (method !== 'service' && method !== 'mixed') fail('Выберите вариант расчёта.');
        var calculationDate = date(input.date, 'дата расчёта');
        if (calculationDate < p.coefficient_from || calculationDate > p.coefficient_to) {
            fail('Понижающая доля ' + p.coefficient_percent + '% по настройкам действует с ' + p.coefficient_from
                + ' по ' + p.coefficient_to + '. Проверьте период или обновите настройки.');
        }

        var position = number(input.position_salary, 'оклад по должности, ₽', 0, maxMoney, false);
        var rank = number(input.rank_salary, 'оклад по званию, ₽', 0, maxMoney, false);
        var nuclearMultiplier = optionalNumber(input.nuclear_multiplier, 'коэффициент за работу с ядерным оружием', 1, 1.25, false, 1);
        if (![1, 1.25].includes(nuclearMultiplier)) fail('Коэффициент за работу с ядерным оружием может быть только 1 или 1,25.');
        var qualificationPercent = optionalNumber(input.qualification_percent, 'надбавка за квалификационную категорию', 0, 30, false, 0);
        var serviceAllowancePercent = optionalNumber(input.service_allowance_percent, 'надбавка за выслугу', 0, 100, false, 0);
        var otherAllowance = optionalNumber(input.other_allowance, 'прочие учитываемые надбавки', 0, maxMoney, false, 0);
        var positionAdjusted = roundMoney(position * nuclearMultiplier);
        var ods = roundMoney(positionAdjusted + rank);
        var qualificationAllowance = roundMoney(positionAdjusted * qualificationPercent / 100);
        var serviceAllowance = roundMoney(ods * serviceAllowancePercent / 100);
        var base = roundMoney(ods + qualificationAllowance + serviceAllowance + otherAllowance);
        if (base <= 0) fail('Сумма окладов и учитываемых надбавок должна быть больше нуля.');

        var reasons = [], fullYears, extraYears, percent;
        if (method === 'service') {
            var serviceYears = number(input.service_years, 'полные годы выслуги', 0, maxYears, true);
            var serviceMonths = optionalNumber(input.service_months, 'месяцы выслуги', 0, 11, true, 0);
            var totalServiceMonths = serviceYears * 12 + serviceMonths;
            if (totalServiceMonths < p.service_min_years * 12) reasons.push('Для варианта по выслуге нужно не менее ' + p.service_min_years + ' лет службы.');
            if (reasons.length) return ineligible(method, base, p, calculationDate, reasons);
            fullYears = Math.floor(totalServiceMonths / 12);
            extraYears = Math.max(0, fullYears - p.service_min_years);
            percent = Math.min(p.service_max_percent, p.service_base_percent + extraYears * p.service_percent_per_year);
        } else {
            var totalYears = number(input.total_years, 'полные годы общего стажа', 0, maxYears, true);
            var totalRemainder = optionalNumber(input.total_months, 'месяцы общего стажа', 0, 11, true, 0);
            var mixedServiceYears = number(input.service_years, 'полные годы службы', 0, maxYears, true);
            var mixedServiceRemainder = optionalNumber(input.service_months, 'месяцы службы', 0, 11, true, 0);
            var age = number(input.age, 'возраст на день увольнения', 0, maxAge, true);
            var totalMonths = totalYears * 12 + totalRemainder;
            var serviceMonths = mixedServiceYears * 12 + mixedServiceRemainder;
            if (serviceMonths > totalMonths) fail('Служба не может быть дольше общего трудового стажа.');
            if (totalMonths < p.mixed_min_total_years * 12) reasons.push('Нужно не менее ' + p.mixed_min_total_years + ' календарных лет общего трудового стажа.');
            if (serviceMonths < p.mixed_min_service_months) {
                reasons.push('В составе общего стажа нужно не менее ' + Math.floor(p.mixed_min_service_months / 12) + ' лет'
                    + (p.mixed_min_service_months % 12 ? ' ' + (p.mixed_min_service_months % 12) + ' мес.' : '') + ' соответствующей службы.');
            }
            if (age < p.mixed_min_age) reasons.push('На день увольнения должно быть не менее ' + p.mixed_min_age + ' лет.');
            if (!['age_limit', 'health', 'staffing'].includes(input.dismissal_reason)) reasons.push('Для смешанного варианта укажите предусмотренное законом основание увольнения.');
            if (reasons.length) return ineligible(method, base, p, calculationDate, reasons);
            fullYears = Math.floor(totalMonths / 12);
            extraYears = Math.max(0, fullYears - p.mixed_min_total_years);
            percent = p.mixed_base_percent + extraYears * p.mixed_percent_per_year;
        }

        var beforeCoefficient = roundMoney(base * percent / 100);
        var mainAmount = roundMoney(beforeCoefficient * p.coefficient_percent / 100);
        var combatVeteran = flag(input.combat_veteran, 'статус ветерана боевых действий');
        var dependents = optionalNumber(input.dependents, 'число иждивенцев', 0, 3, true, 0);
        var age80OrGroup1 = flag(input.age80_or_group1, 'признак 80 лет или инвалидности I группы');
        var disabilityPercent = optionalNumber(input.disability_percent, 'увеличение по инвалидности', 0, 300, false, 0);
        if (![0, 150, 175, 200, 250, 300].includes(disabilityPercent)) fail('Выберите одно из предусмотренных увеличений по инвалидности.');
        var specialMonthly = optionalNumber(input.special_monthly, 'дополнительное ежемесячное обеспечение', 0, maxMoney, false, 0);
        var districtCoefficient = optionalNumber(input.district_coefficient, 'районный коэффициент', 1, 2, false, 1);
        var additions = {
            combat_veteran: combatVeteran ? roundMoney(p.rpp_rub * .32) : 0,
            dependents: roundMoney(p.rpp_rub * (dependents === 3 ? 1 : dependents * .32)),
            age80_or_group1: age80OrGroup1 ? p.rpp_rub : 0,
            disability: roundMoney(p.rpp_rub * disabilityPercent / 100),
            special_monthly: specialMonthly,
        };
        var additionsTotal = roundMoney(Object.keys(additions).reduce(function (sum, key) { return sum + additions[key]; }, 0));
        var beforeDistrict = roundMoney(mainAmount + additionsTotal);
        var amount = roundMoney(beforeDistrict * districtCoefficient);

        return {
            eligible: true, method: method, date: calculationDate, base: base,
            base_components: {
                position_salary: position, position_adjusted: positionAdjusted, rank_salary: rank,
                nuclear_multiplier: nuclearMultiplier, qualification_allowance: qualificationAllowance,
                service_allowance_percent: serviceAllowancePercent, service_allowance: serviceAllowance, other_allowance: otherAllowance,
            },
            full_years: fullYears, extra_years: extraYears, pension_percent: percent,
            before_coefficient: beforeCoefficient, coefficient_percent: p.coefficient_percent,
            main_amount: mainAmount, rpp_rub: p.rpp_rub, additions: additions,
            additions_total: additionsTotal, district_coefficient: districtCoefficient,
            before_district: beforeDistrict, amount: amount, reasons: [],
        };
    }

    return { normalizeSettings: normalizeSettings, calculate: calculate, roundMoney: roundMoney };
});
