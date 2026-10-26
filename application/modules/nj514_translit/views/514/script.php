(function () {
    'use strict';
    var STANDARDS = <?php echo json_encode($standards, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var MAX_CHARS = <?php echo (int) $max_chars; ?>;
    var ALPHABET = Array.from('абвгдеёжзийклмнопрстуфхцчшщъыьэюя');
    var $ = function (id) { return document.getElementById(id); };
    var state = { standard: 'passport', direction: 'to_latin' };

    function toLatin(text, standard) {
        var definition = STANDARDS[standard], mapping = definition.mapping, chars = Array.from(text), allUpper = text.toUpperCase() === text, out = '';
        chars.forEach(function (char, index) {
            var lower = char.toLowerCase();
            if (char === ' ' && definition.mrz_spaces) { out += '<'; return; }
            if (!Object.prototype.hasOwnProperty.call(mapping, lower)) { out += char; return; }
            var token = mapping[lower];
            if (standard === 'gost_b' && lower === 'ц') {
                var next = chars[index + 1] ? chars[index + 1].toLowerCase() : '';
                var nextToken = Object.prototype.hasOwnProperty.call(mapping, next) ? mapping[next] : next;
                token = 'eiyj'.indexOf(String(nextToken).charAt(0).toLowerCase()) !== -1 ? 'c' : 'cz';
            }
            if (!token) return;
            var upper = char.toUpperCase() === char && char.toLowerCase() !== char;
            out += definition.uppercase || allUpper ? token.toUpperCase() : (upper ? token.charAt(0).toUpperCase() + token.slice(1) : token);
        });
        return { text: out, ambiguities: [] };
    }

    function reverseMap(standard) {
        var definition = STANDARDS[standard], reverse = {};
        Object.keys(definition.mapping).forEach(function (letter) {
            var token = definition.mapping[letter].toLowerCase();
            if (token && !Object.prototype.hasOwnProperty.call(reverse, token)) reverse[token] = letter;
        });
        if (standard === 'gost_b') reverse.cz = 'ц';
        return Object.keys(reverse).sort(function (a, b) { return b.length - a.length; }).map(function (token) { return { token: token, letter: reverse[token] }; });
    }

    function toCyrillic(text, standard) {
        var definition = STANDARDS[standard], tokens = reverseMap(standard), input = definition.mrz_spaces ? text.replace(/</g, ' ') : text;
        var chars = Array.from(input), out = '', ambiguities = [], i = 0;
        while (i < chars.length) {
            var match = null;
            for (var t = 0; t < tokens.length; t++) {
                var candidate = chars.slice(i, i + tokens[t].token.length).join('');
                if (candidate.length === tokens[t].token.length && candidate.toLowerCase() === tokens[t].token) { match = { candidate: candidate, letter: tokens[t].letter, token: tokens[t].token }; break; }
            }
            if (!match) { out += chars[i]; i++; continue; }
            var choices = definition.ambiguities[match.token] || [];
            if (choices.length) ambiguities.push({ position: i + 1, latin: match.candidate, choices: choices });
            var allUpper = match.candidate.toUpperCase() === match.candidate && match.candidate.toLowerCase() !== match.candidate;
            var startsUpper = match.candidate.charAt(0).toUpperCase() === match.candidate.charAt(0) && match.candidate.charAt(0).toLowerCase() !== match.candidate.charAt(0);
            var letter = allUpper || startsUpper ? match.letter.toUpperCase() : match.letter;
            out += letter; i += Array.from(match.candidate).length;
        }
        return { text: out, ambiguities: ambiguities };
    }

    function convert(text, standard, direction) {
        if (typeof text !== 'string' || Array.from(text).length > MAX_CHARS || !STANDARDS[standard]) return null;
        if (direction === 'to_latin') return toLatin(text, standard);
        if (direction === 'to_cyrillic') return toCyrillic(text, standard);
        return null;
    }

    window.NJ514 = { convert: convert, toLatin: toLatin, toCyrillic: toCyrillic };
    var input = $('tl-input'), output = $('tl-output');
    if (!input || !output) return;

    var ON = ['is-on'];
    var OFF = [];

    function updateTable() {
        var body = $('tl-table'), definition = STANDARDS[state.standard];
        body.textContent = '';
        $('tl-table-title').textContent = definition.title;
        $('tl-standard-description').textContent = definition.description;
        ALPHABET.forEach(function (letter) {
            var tr = document.createElement('tr'), left = document.createElement('th'), right = document.createElement('td');
            tr.className = 'transition hover:bg-emerald-50/60 dark:hover:bg-emerald-900/20';
            left.className = 'py-1.5 pr-4 font-mono text-gray-800 dark:text-gray-200';
            right.className = 'py-1.5 font-mono text-gray-700 dark:text-gray-300';
            left.textContent = letter.toUpperCase() + ' · ' + letter;
            var value = definition.mapping[letter];
            if (state.standard === 'gost_b' && letter === 'ц') value = 'c перед e/i/y/j; иначе cz';
            if (!value) value = '—';
            right.textContent = definition.uppercase ? value.toUpperCase() : value;
            tr.appendChild(left); tr.appendChild(right); body.appendChild(tr);
        });
    }

    function render() {
        var size = Array.from(input.value).length, result = convert(input.value, state.standard, state.direction);
        $('tl-count').textContent = size + ' / ' + MAX_CHARS;
        $('tl-input-label').textContent = state.direction === 'to_latin' ? 'Русский текст' : 'Латинский текст';
        $('tl-output-label').textContent = state.direction === 'to_latin' ? 'Результат латиницей' : 'Результат кириллицей';
        $('tl-ambiguity').textContent = '';
        if (!result) { output.value = ''; $('tl-copy').disabled = true; return; }
        output.value = result.text;
        $('tl-copy').disabled = result.text === '';
        if (result.ambiguities.length) {
            $('tl-ambiguity').textContent = 'Возможные варианты: ' + result.ambiguities.slice(0, 8).map(function (item) { return item.latin + ' → ' + item.choices.join(' / '); }).join('; ') + (result.ambiguities.length > 8 ? ' …' : '');
        }
    }

    function selectStandard(code) {
        if (!STANDARDS[code]) return;
        state.standard = code;
        document.querySelectorAll('.tl-standard').forEach(function (button) {
            var active = button.dataset.standard === code;
            button.setAttribute('aria-checked', active ? 'true' : 'false');
            button.classList.remove.apply(button.classList, ON.concat(OFF));
            button.classList.add.apply(button.classList, active ? ON : OFF);
        });
        updateTable(); render();
    }

    function selectDirection(direction) {
        state.direction = direction;
        document.querySelectorAll('.tl-direction').forEach(function (button) {
            var active = button.dataset.direction === direction;
            button.setAttribute('aria-checked', active ? 'true' : 'false');
            button.classList.remove.apply(button.classList, ON.concat(OFF));
            button.classList.add.apply(button.classList, active ? ON : OFF);
        });
        render();
    }

    document.querySelectorAll('.tl-standard').forEach(function (button) { button.addEventListener('click', function () { selectStandard(button.dataset.standard); }); });
    document.querySelectorAll('.tl-direction').forEach(function (button) { button.addEventListener('click', function () { selectDirection(button.dataset.direction); }); });
    input.addEventListener('input', render);
    $('tl-copy').addEventListener('click', function () { if (output.value && navigator.clipboard) navigator.clipboard.writeText(output.value).then(function () { $('tl-copy').textContent = 'Скопировано'; }); });
    $('tl-swap').addEventListener('click', function () {
        var converted = output.value;
        if (!converted) return;
        input.value = converted;
        selectDirection(state.direction === 'to_latin' ? 'to_cyrillic' : 'to_latin');
        input.focus();
    });
    document.querySelectorAll('.tl-example').forEach(function (button) { button.addEventListener('click', function () { input.value = button.dataset.example; render(); input.focus(); }); });
    updateTable(); render();
})();
