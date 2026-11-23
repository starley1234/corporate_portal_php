/* Сравнение поисковых запросов с учётом русской/английской раскладки. */
(function (window) {
    'use strict';
    var latin = "`qwertyuiop[]asdfghjkl;'zxcvbnm,.";
    var russian = 'ёйцукенгшщзхъфывапролджэячсмитьбю';

    function convert(text, from, to) {
        return Array.from(text).map(function (ch) {
            var index = from.indexOf(ch);
            return index < 0 ? ch : to[index];
        }).join('');
    }
    function variants(text) {
        text = String(text || '').toLowerCase();
        return [text, convert(text, latin, russian), convert(text, russian, latin)];
    }
    // Небольшие опечатки при ошибочной раскладке: только длинные слова и
    // только после перекладки; точное совпадение всегда имеет приоритет.
    function near(a, b) {
        if (a.length < 7 || a.length > 64 || b.length > 64 || Math.abs(a.length - b.length) > 2) return false;
        var previous = Array.from({ length: b.length + 1 }, function (_, i) { return i; });
        for (var i = 1; i <= a.length; i++) {
            var row = [i];
            for (var j = 1; j <= b.length; j++) {
                row[j] = Math.min(row[j - 1] + 1, previous[j] + 1, previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
            }
            previous = row;
        }
        return previous[b.length] <= 2;
    }
    function matches(haystack, query) {
        haystack = String(haystack || '').toLowerCase();
        return String(query || '').trim().toLowerCase().split(/\s+/).filter(Boolean).every(function (token) {
            // Обратный слэш перед [/] может быть текстовым экранированием.
            token = token.replace(/\\(?=[\[\]])/g, '');
            var options = variants(token);
            if (options.some(function (variant) { return haystack.indexOf(variant) !== -1; })) return true;
            var words = haystack.match(/[\p{L}\p{N}_]+/gu) || [];
            return options.slice(1).some(function (variant) {
                return variant !== token && words.some(function (word) { return near(variant, word); });
            });
        });
    }
    window.PRXSearchLayout = { variants: variants, matches: matches };
})(window);
