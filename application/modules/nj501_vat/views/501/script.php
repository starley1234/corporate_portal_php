<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj501_vat: инлайн-скрипт калькулятора и мини-демо на главной (источник один — этот файл) -->
<script>
/**
 * nj501_vat — калькулятор НДС. Клиентское зеркало Service_Nj501_Vat.
 *
 * Все суммы — целые копейки, округление half-up (как в PHP).
 * При изменении формул менять и PHP-сервис, и этот файл (см. AGENTS модуля).
 */
(function () {
    'use strict';

    var RATES = [22, 20, 10, 7, 5, 0];

    var state = {
        rate: 22,
        mode: 'extract' // extract | add
    };

    // ---------- деньги ----------

    // '1 234,56' → 123456 (копейки) или null
    function toKop(raw) {
        if (raw == null) return null;
        var s = String(raw).replace(/[\s\u00A0\u202F\u2009]/g, '').replace(',', '.');
        if (!/^\d{1,15}(\.\d{1,2})?$/.test(s)) return null;
        var parts = s.split('.');
        var rub = parseInt(parts[0], 10) || 0;
        var kop = 0;
        if (parts[1] !== undefined) {
            kop = parseInt((parts[1] + '00').slice(0, 2), 10) || 0;
        }
        var total = rub * 100 + kop;
        if (total > 99999999999900) return null; // MAX_AMOUNT
        return total;
    }

    // 123456 → '1 234,56'
    function formatKop(kop) {
        var neg = kop < 0;
        kop = Math.abs(kop);
        var rub = Math.floor(kop / 100);
        var fr = ('0' + (kop % 100)).slice(-2);
        var s = String(rub).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        return (neg ? '−' : '') + s + ',' + fr;
    }

    // те же формулы, что в Service_Nj501_Vat::calculate()
    function calc(amountKop, rate, mode) {
        if (amountKop == null || RATES.indexOf(rate) === -1) return null;
        var net, vat, total, formula;
        if (mode === 'extract') {
            vat = Math.round(amountKop * rate / (100 + rate)); // half-up
            net = amountKop - vat;
            total = amountKop;
            formula = rate > 0
                ? formatKop(amountKop) + ' × ' + rate + '/' + rate + '00'
                : 'без НДС';
        } else {
            net = amountKop;
            vat = Math.round(amountKop * rate / 100);
            total = amountKop + vat;
            formula = rate > 0
                ? formatKop(amountKop) + ' × ' + rate + '%'
                : 'без НДС';
        }
        return {
            kop: amountKop, net: net, vat: vat, total: total, formula: formula
        };
    }

    // ---------- DOM ----------

    var $input = document.getElementById('vat-amount');
    var $vat = document.getElementById('vat-out');
    var $net = document.getElementById('net-out');
    var $total = document.getElementById('total-out');
    var $rowAmount = document.getElementById('row-amount');
    var $rowFormula = document.getElementById('row-formula');
    var $rowRate = document.getElementById('row-rate');
    var $copy = document.getElementById('vat-copy');
    var $share = document.getElementById('vat-share');
    var $swap = document.getElementById('vat-swap');
    var $histWrap = document.getElementById('vat-history-wrap');
    var $hist = document.getElementById('vat-history');
    var $histClear = document.getElementById('vat-history-clear');
    // ---------- мини-демо на главной (#vat-mini, опционально) ----------
    // Использует те же toKop/calc/formatKop — третей копии формул нет.
    var $mini = document.getElementById('vat-mini');
    if ($mini) {
        var $miniIn = document.getElementById('vat-mini-amount');
        var $miniOut = document.getElementById('vat-mini-out');
        var miniRate = parseInt($mini.getAttribute('data-rate') || '22', 10) || 22;
        var miniMode = $mini.getAttribute('data-mode') === 'add' ? 'add' : 'extract';
        var miniRender = function () {
            var kop = toKop($miniIn.value);
            if (kop == null) { $miniOut.textContent = '—'; return; }
            $miniOut.textContent = formatKop(calc(kop, miniRate, miniMode).vat) + ' ₽';
        };
        $miniIn.addEventListener('input', miniRender);
        miniRender();
    }

    if (!$input) return;

    function rateButtons() { return document.querySelectorAll('.vat-rate-btn'); }
    function modeButtons() { return document.querySelectorAll('.vat-mode-btn'); }

    // начальное состояние — из отрендеренного сервером DOM
    (function initState() {
        var activeRate = document.querySelector('.vat-rate-btn[aria-checked="true"]');
        if (activeRate) state.rate = parseInt(activeRate.getAttribute('data-rate'), 10);
        var activeMode = document.querySelector('.vat-mode-btn[aria-checked="true"]');
        if (activeMode) state.mode = activeMode.getAttribute('data-mode');
    })();

    function renderRate() {
        rateButtons().forEach(function (b) {
            var on = parseInt(b.getAttribute('data-rate'), 10) === state.rate;
            b.setAttribute('aria-checked', on ? 'true' : 'false');
            b.className = 'vat-rate-btn n2j-seg-item' + (on ? ' is-active' : '');
        });
    }

    function renderMode() {
        modeButtons().forEach(function (b) {
            var on = b.getAttribute('data-mode') === state.mode;
            b.setAttribute('aria-checked', on ? 'true' : 'false');
            b.className = 'vat-mode-btn n2j-chip justify-center py-2.5' + (on ? ' is-on' : '');
        });
    }

    function renderResult() {
        var kop = toKop($input.value);
        var res = calc(kop, state.rate, state.mode);
        var has = !!res;

        $vat.textContent = has ? formatKop(res.vat) + ' ₽' : '—';
        $net.textContent = has ? formatKop(res.net) + ' ₽' : '—';
        $total.textContent = has ? formatKop(res.total) + ' ₽' : '—';
        $rowAmount.textContent = has ? formatKop(res.kop) + ' ₽' : '—';
        $rowFormula.textContent = has ? res.formula : '—';
        $rowRate.textContent = state.rate + '%';

        $copy.disabled = !has;
        $share.disabled = !has;

        if (has) {
            updateUrl();
            pushHistory(res);
        }
    }

    function updateUrl() {
        var kop = toKop($input.value);
        if (kop == null) return;
        var url = location.pathname +
            '?a=' + encodeURIComponent(formatKop(kop)) +
            '&r=' + state.rate +
            '&m=' + state.mode;
        try { history.replaceState(null, '', url); } catch (e) { /* file:// */ }
    }

    // ---------- история (localStorage, только локально) ----------

    var HIST_KEY = 'n2j.vat.history';

    function loadHistory() {
        try {
            var raw = localStorage.getItem(HIST_KEY);
            var arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr.slice(0, 8) : [];
        } catch (e) { return []; }
    }

    function saveHistory(list) {
        try { localStorage.setItem(HIST_KEY, JSON.stringify(list.slice(0, 8))); } catch (e) { }
    }

    function pushHistory(res) {
        var item = {
            a: formatKop(res.kop),
            r: state.rate,
            m: state.mode
        };
        var list = loadHistory();
        if (list[0] && list[0].a === item.a && list[0].r === item.r && list[0].m === item.m) return;
        list.unshift(item);
        saveHistory(list);
        renderHistory();
    }

    function renderHistory() {
        var list = loadHistory();
        $histWrap.classList.remove('hidden');
        $hist.innerHTML = '';
        if (!list.length) {
            $hist.innerHTML = '<p class="text-xs text-gray-400">Пока пусто — сделайте первый расчёт.</p>';
            return;
        }
        list.forEach(function (it) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'n2j-chip';
            b.textContent = it.a + ' ₽ · ' + it.r + '% · ' + (it.m === 'add' ? 'начислить' : 'выделить');
            b.addEventListener('click', function () {
                $input.value = it.a;
                state.rate = it.r;
                state.mode = it.m;
                renderRate(); renderMode();
                recalc(true);
            });
            $hist.appendChild(b);
        });
    }

    // ---------- ввод ----------

    var histTimer = null;

    function recalc(immediate) {
        renderResult();
        if (immediate) return;
        clearTimeout(histTimer);
        histTimer = setTimeout(renderHistory, 800); // история — не на каждый символ
    }

    // живое форматирование: пробелы между разрядами, до 2 знаков после запятой
    function formatInput() {
        var raw = $input.value;
        var s = raw.replace(/[\s\u00A0\u202F\u2009]/g, '');
        if (!s) return;
        var m = s.match(/^(\d{1,15})([.,](\d{0,2}))?/);
        if (!m) return;
        var intPart = m[1].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        var out = intPart;
        if (m[2] !== undefined) {
            out += ',' + (m[3] || '');
        }
        if (out !== raw) {
            var pos = $input.value.length; // курсор в конец — простое решение
            $input.value = out;
            try { $input.setSelectionRange(pos, pos); } catch (e) { }
        }
    }

    $input.addEventListener('input', function () {
        formatInput();
        recalc(false);
    });

    rateButtons().forEach(function (b) {
        b.addEventListener('click', function () {
            state.rate = parseInt(b.getAttribute('data-rate'), 10);
            renderRate();
            recalc(false);
        });
    });

    modeButtons().forEach(function (b) {
        b.addEventListener('click', function () {
            state.mode = b.getAttribute('data-mode');
            renderMode();
            recalc(false);
        });
    });

    document.querySelectorAll('[data-chip]').forEach(function (b) {
        b.addEventListener('click', function () {
            $input.value = b.getAttribute('data-chip');
            formatInput();
            recalc(false);
            $input.focus();
        });
    });

    // пометить сумму в режиме «поменять»: выделяем НДС из той же итоговой суммы
    $swap.addEventListener('click', function () {
        var kop = toKop($input.value);
        state.mode = state.mode === 'add' ? 'extract' : 'add';
        renderMode();
        if (kop != null && state.mode === 'extract') {
            // при смене на «выделить» сохраняем итого прежнего расчёта
            var res = calc(kop, state.rate, 'add');
            $input.value = formatKop(res.total);
            formatInput();
        }
        recalc(false);
    });

    // ---------- буфер обмена ----------

    function toast(msg) { N2J.toast(msg); }

    function copyText(text, okMsg) {
        N2J.copyText(text).then(function () { toast(okMsg); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    $copy.addEventListener('click', function () {
        var res = calc(toKop($input.value), state.rate, state.mode);
        if (!res) return;
        var dir = state.mode === 'add' ? 'начислен' : 'выделен';
        copyText(
            'Сумма: ' + formatKop(res.kop) + ' ₽\n' +
            'НДС ' + state.rate + '% (' + dir + '): ' + formatKop(res.vat) + ' ₽\n' +
            'Без НДС: ' + formatKop(res.net) + ' ₽\n' +
            'Итого: ' + formatKop(res.total) + ' ₽',
            'Результат скопирован'
        );
    });

    $share.addEventListener('click', function () {
        copyText(location.href, 'Ссылка скопирована');
    });

    // ---------- история: очистка ----------

    if ($histClear) {
        $histClear.addEventListener('click', function () {
            saveHistory([]);
            renderHistory();
        });
    }

    // первичный пересчёт, если сумма уже задана (SSR-состояние или прототип)
    if ($input.value.trim() !== '') {
        renderResult();
    }

    renderHistory();
})();
</script>
