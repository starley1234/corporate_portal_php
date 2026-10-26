(function () {
    'use strict';
    var MONTHS = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    var GEN = ['', 'первого', 'второго', 'третьего', 'четвёртого', 'пятого', 'шестого', 'седьмого', 'восьмого', 'девятого', 'десятого', 'одиннадцатого', 'двенадцатого', 'тринадцатого', 'четырнадцатого', 'пятнадцатого', 'шестнадцатого', 'семнадцатого', 'восемнадцатого', 'девятнадцатого', 'двадцатого'];
    var ACC = ['', 'первое', 'второе', 'третье', 'четвёртое', 'пятое', 'шестое', 'седьмое', 'восьмое', 'девятое', 'десятое', 'одиннадцатое', 'двенадцатое', 'тринадцатое', 'четырнадцатое', 'пятнадцатое', 'шестнадцатое', 'семнадцатое', 'восемнадцатое', 'девятнадцатое', 'двадцатое'];
    var TENS_GEN = { 2: 'двадцатого', 3: 'тридцатого', 4: 'сорокового', 5: 'пятидесятого', 6: 'шестидесятого', 7: 'семидесятого', 8: 'восьмидесятого', 9: 'девяностого' };
    var TENS_ACC = { 2: 'двадцатое', 3: 'тридцатое', 4: 'сороковое', 5: 'пятидесятое', 6: 'шестидесятое', 7: 'семидесятое', 8: 'восьмидесятое', 9: 'девяностое' };
    var TENS = { 2: 'двадцать', 3: 'тридцать', 4: 'сорок', 5: 'пятьдесят', 6: 'шестьдесят', 7: 'семьдесят', 8: 'восемьдесят', 9: 'девяносто' };
    var HUNDREDS = { 1: 'сто', 2: 'двести', 3: 'триста', 4: 'четыреста', 5: 'пятьсот', 6: 'шестьсот', 7: 'семьсот', 8: 'восемьсот', 9: 'девятьсот' };
    var HUNDREDS_ORD = { 1: 'сотого', 2: 'двухсотого', 3: 'трёхсотого', 4: 'четырёхсотого', 5: 'пятисотого', 6: 'шестисотого', 7: 'семисотого', 8: 'восьмисотого', 9: 'девятисотого' };
    var $ = function (id) { return document.getElementById(id); };

    function parseDate(raw) {
        if (typeof raw !== 'string') return null;
        var match, year, month, day;
        if ((match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw))) {
            year = Number(match[1]); month = Number(match[2]); day = Number(match[3]);
        } else if ((match = /^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/.exec(raw))) {
            day = Number(match[1]); month = Number(match[2]); year = Number(match[3]);
        } else return null;
        if (year < 1 || year > 9999 || month < 1 || month > 12 || day < 1) return null;
        var leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
        var monthDays = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if (day > monthDays[month - 1]) return null;
        return { iso: String(year).padStart(4, '0') + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0'), year: year, month: month, day: day };
    }

    function dayWords(day, kase) {
        var map = kase === 'accusative' ? ACC : GEN;
        var tensMap = kase === 'accusative' ? TENS_ACC : TENS_GEN;
        if (day < 1 || day > 31) return null;
        if (day <= 20) return map[day];
        if (day === 30) return kase === 'accusative' ? 'тридцатое' : 'тридцатого';
        var tens = Math.floor(day / 10), ones = day % 10;
        return TENS[tens] + ' ' + map[ones];
    }

    function smallCardinal(number) {
        return ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'][number] || '';
    }

    function ordinalGroup(number) {
        if (number <= 20) return GEN[number] || null;
        if (number <= 99) {
            if (number % 10 !== 0) return TENS[Math.floor(number / 10)] + ' ' + GEN[number % 10];
            return TENS_GEN[Math.floor(number / 10)] || null;
        }
        var hundreds = Math.floor(number / 100), remainder = number % 100;
        if (remainder === 0) return HUNDREDS_ORD[hundreds] || null;
        return HUNDREDS[hundreds] + ' ' + ordinalGroup(remainder);
    }

    function yearWords(year) {
        if (year < 1 || year > 9999) return null;
        var thousands = Math.floor(year / 1000), remainder = year % 1000, prefix = '';
        if (thousands === 1) prefix = 'тысяча';
        else if (thousands === 2) prefix = 'две тысячи';
        else if (thousands === 3) prefix = 'три тысячи';
        else if (thousands === 4) prefix = 'четыре тысячи';
        else if (thousands >= 5) prefix = smallCardinal(thousands) + ' тысяч';
        return remainder === 0 ? prefix : (prefix + ' ' + ordinalGroup(remainder)).trim();
    }

    function convert(raw) {
        var date = parseDate(raw);
        if (!date) return null;
        var month = MONTHS[date.month], year = yearWords(date.year);
        return {
            date: date.iso, day: date.day, month: date.month, year: date.year, month_word: month,
            long: dayWords(date.day, 'genitive') + ' ' + month + ' ' + year + ' года',
            quoted: '«' + String(date.day).padStart(2, '0') + '» ' + month + ' ' + date.year + ' г.',
            numeric: String(date.day).padStart(2, '0') + '.' + String(date.month).padStart(2, '0') + '.' + String(date.year).padStart(4, '0'),
            plain: date.day + ' ' + month + ' ' + date.year + ' г.'
        };
    }

    function range(rawFrom, rawTo) {
        var from = parseDate(rawFrom), to = parseDate(rawTo);
        if (!from || !to || to.iso < from.iso) return null;
        var long;
        if (from.iso === to.iso) long = convert(from.iso).long;
        else if (from.year === to.year && from.month === to.month) {
            long = 'с ' + dayWords(from.day, 'genitive') + ' по ' + dayWords(to.day, 'accusative') + ' ' + MONTHS[from.month] + ' ' + yearWords(from.year) + ' года';
        } else {
            long = 'с ' + dayWords(from.day, 'genitive') + ' ' + MONTHS[from.month] + ' ' + yearWords(from.year) + ' года по ' + dayWords(to.day, 'accusative') + ' ' + MONTHS[to.month] + ' ' + yearWords(to.year) + ' года';
        }
        var numeric = String(from.day).padStart(2, '0') + '.' + String(from.month).padStart(2, '0') + '.' + String(from.year).padStart(4, '0');
        if (from.iso !== to.iso) numeric += ' — ' + String(to.day).padStart(2, '0') + '.' + String(to.month).padStart(2, '0') + '.' + String(to.year).padStart(4, '0');
        return { from: from.iso, to: to.iso, long: long, numeric: numeric };
    }

    window.NJ513 = { parseDate: parseDate, dayWords: dayWords, yearWords: yearWords, convert: convert, range: range };
    var dateField = $('dw-date'), endField = $('dw-end');
    if (!dateField || !endField) return;

    function syncUrl() {
        if (!window.history || !history.replaceState) return;
        var query = new URLSearchParams();
        if (dateField.value) query.set('date', dateField.value);
        if (endField.value) query.set('end', endField.value);
        history.replaceState(null, '', location.pathname + (query.toString() ? '?' + query.toString() : ''));
    }

    function render() {
        var date = dateField.value ? convert(dateField.value) : null;
        var rangeResult = dateField.value && endField.value ? range(dateField.value, endField.value) : null;
        var error = $('dw-error'), copyLong = $('dw-copy-long'), copyRange = $('dw-copy-range');
        if (!date) {
            $('dw-long').textContent = 'Введите дату — варианты появятся сразу';
            $('dw-quoted').textContent = '—'; $('dw-numeric').textContent = '—'; $('dw-plain').textContent = '—';
            copyLong.disabled = true; copyLong.__text = '';
        } else {
            $('dw-long').textContent = date.long; $('dw-quoted').textContent = date.quoted;
            $('dw-numeric').textContent = date.numeric; $('dw-plain').textContent = date.plain;
            copyLong.disabled = false; copyLong.__text = date.long;
        }
        if (endField.value && !rangeResult) {
            error.textContent = dateField.value ? 'Проверьте даты: окончание не может быть раньше начала.' : 'Сначала выберите дату начала.';
            error.classList.remove('hidden');
        } else error.classList.add('hidden');
        $('dw-range-wrap').classList.toggle('hidden', !endField.value);
        $('dw-range').textContent = rangeResult ? rangeResult.long : '—';
        $('dw-range-numeric').textContent = rangeResult ? rangeResult.numeric : '';
        copyRange.disabled = !rangeResult; copyRange.__text = rangeResult ? rangeResult.long : '';
        syncUrl();
    }

    $('datewords-form').addEventListener('submit', function (event) { event.preventDefault(); render(); });
    dateField.addEventListener('input', render); endField.addEventListener('input', render);
    document.querySelectorAll('.dw-example').forEach(function (button) {
        button.addEventListener('click', function () { dateField.value = button.dataset.start; endField.value = button.dataset.end; render(); });
    });
    $('dw-copy-long').addEventListener('click', function () { if (this.__text && navigator.clipboard) navigator.clipboard.writeText(this.__text).then(function () { $('dw-copy-long').textContent = 'Скопировано'; }); });
    $('dw-copy-range').addEventListener('click', function () { if (this.__text && navigator.clipboard) navigator.clipboard.writeText(this.__text).then(function () { $('dw-copy-range').textContent = 'Скопировано'; }); });
    $('dw-share').addEventListener('click', function () { if (navigator.clipboard) navigator.clipboard.writeText(location.href).then(function () { $('dw-share').textContent = 'Ссылка скопирована'; }); });
    render();
})();
