/* nj510_qr — чистые функции. Кодирование локальное; никаких HTTP-запросов. */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('../vendor/qrcode/qrcode.js'));
    } else {
        root.Nj510Qr = factory(root.qrcode);
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function (qrcode) {
    'use strict';

    function fail(message) { throw new Error(message); }
    function bytes(value) { return new TextEncoder().encode(String(value)); }
    function text(value) { return value == null ? '' : String(value); }
    function required(value, label) {
        var result = text(value).trim();
        if (!result) fail('Заполните поле «' + label + '».');
        return result;
    }

    function url(value) {
        value = required(value, 'Ссылка');
        // Явные схемы не подменяем: javascript: и data: не являются ссылками сайта.
        if (!/^[a-z][a-z\d+.-]*:/i.test(value)
            || /^(?:(?:[a-z\d-]+\.)+[a-z\d-]+|localhost|\[[\da-f:]+\]):\d+(?:[/?#]|$)/i.test(value)) value = 'https://' + value;
        var parsed;
        try { parsed = new URL(value); } catch (_) { fail('Проверьте ссылку, например https://example.com.'); }
        if (!/^https?:$/.test(parsed.protocol) || !parsed.hostname || /\s/.test(value)) {
            fail('Для ссылки нужен адрес сайта с http:// или https://.');
        }
        return parsed.href;
    }

    function phone(value) {
        var number = required(value, 'Телефон').replace(/[\s().-]/g, '');
        if (!/^\+?\d{3,20}$/.test(number)) fail('Введите телефон: цифры и, при необходимости, + в начале.');
        return number;
    }

    function email(value) {
        value = required(value, 'Электронная почта');
        if (!/^[^\s@;,:<>]+@[^\s@;,:<>]+\.[^\s@;,:<>]+$/.test(value)) fail('Проверьте адрес электронной почты.');
        return value;
    }

    function wifiEscape(value) { return text(value).replace(/([\\;,:"])/g, '\\$1'); }
    function cardEscape(value) {
        return text(value).replace(/\\/g, '\\\\').replace(/\r\n?|\n/g, '\\n').replace(/([;,])/g, '\\$1');
    }

    function payload(type, fields, limits) {
        fields = fields || {};
        var value;
        switch (type) {
            case 'url': value = url(fields.url); break;
            case 'text':
                value = text(fields.text);
                if (!value.trim()) fail('Введите текст для QR-кода.');
                break;
            case 'phone': value = 'tel:' + phone(fields.phone); break;
            case 'sms': value = 'SMSTO:' + phone(fields.phone) + ':' + text(fields.message); break;
            case 'email':
                var query = [];
                if (fields.subject) query.push('subject=' + encodeURIComponent(fields.subject));
                if (fields.message) query.push('body=' + encodeURIComponent(fields.message));
                value = 'mailto:' + encodeURIComponent(email(fields.email)).replace(/%40/g, '@') + (query.length ? '?' + query.join('&') : '');
                break;
            case 'wifi':
                var security = fields.security || 'WPA';
                if (['WPA', 'WEP', 'nopass'].indexOf(security) === -1) fail('Выберите тип защиты Wi-Fi.');
                var ssid = required(fields.ssid, 'Название сети');
                var password = security === 'nopass' ? '' : required(fields.password, 'Пароль сети');
                value = 'WIFI:T:' + security + ';S:' + wifiEscape(ssid) + ';P:' + wifiEscape(password)
                    + ';H:' + (fields.hidden ? 'true' : 'false') + ';;';
                break;
            case 'vcard':
                var first = text(fields.first).trim();
                var last = text(fields.last).trim();
                if (!first && !last) fail('Укажите имя или фамилию для визитки.');
                var lines = ['BEGIN:VCARD', 'VERSION:3.0', 'N:' + cardEscape(last) + ';' + cardEscape(first) + ';;;',
                    'FN:' + cardEscape([first, last].filter(Boolean).join(' '))];
                if (fields.organization) lines.push('ORG:' + cardEscape(fields.organization));
                if (fields.position) lines.push('TITLE:' + cardEscape(fields.position));
                if (fields.phone) lines.push('TEL;TYPE=CELL:' + phone(fields.phone));
                if (fields.email) lines.push('EMAIL:' + cardEscape(email(fields.email)));
                if (fields.url) lines.push('URL:' + cardEscape(url(fields.url)));
                lines.push('END:VCARD');
                value = lines.join('\r\n');
                break;
            default: fail('Неизвестный тип QR-кода.');
        }
        return checkPayload(value, limits);
    }

    function checkPayload(value, limits) {
        value = text(value);
        if (!value.trim()) fail('Содержимое QR-кода пустое.');
        if (value.indexOf('\0') !== -1) fail('В данных есть нулевой символ. Уберите его.');
        if (bytes(value).length > limits.payload_bytes) {
            fail('Слишком длинное содержимое: максимум ' + limits.payload_bytes + ' байт UTF-8. Сократите текст.');
        }
        return value;
    }

    // CSV с кавычками, удвоенными кавычками, переносами внутри ячеек и BOM.
    function delimiterOf(input) {
        var counts = { ';': 0, ',': 0, '\t': 0 };
        var quoted = false;
        for (var i = 0; i < input.length; i++) {
            var ch = input[i];
            if (ch === '"') {
                if (quoted && input[i + 1] === '"') i++;
                else quoted = !quoted;
            } else if (!quoted) {
                if (ch === '\r' || ch === '\n') break;
                if (Object.prototype.hasOwnProperty.call(counts, ch)) counts[ch]++;
            }
        }
        return Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a]; })[0];
    }

    function csvRows(input, delimiter) {
        var rows = [], row = [], cell = '', quoted = false, closed = false;
        var line = 1, start = 1;
        function finishCell() { row.push(cell); cell = ''; closed = false; }
        function finishRow() {
            finishCell();
            if (row.some(function (item) { return item.trim() !== ''; })) rows.push({ cells: row, line: start });
            row = []; start = line + 1;
        }
        for (var i = 0; i < input.length; i++) {
            var ch = input[i];
            if (quoted) {
                if (ch === '"') {
                    if (input[i + 1] === '"') { cell += '"'; i++; }
                    else { quoted = false; closed = true; }
                } else {
                    cell += ch;
                    if (ch === '\n' || (ch === '\r' && input[i + 1] !== '\n')) line++;
                }
            } else if (ch === delimiter) {
                finishCell();
            } else if (ch === '\r' || ch === '\n') {
                finishRow();
                if (ch === '\r' && input[i + 1] === '\n') i++;
                line++;
            } else if (ch === '"') {
                if (cell !== '' || closed) fail('Строка ' + line + ': кавычки внутри ячейки нужно удвоить.');
                quoted = true;
            } else if (closed) {
                if (!/\s/.test(ch)) fail('Строка ' + line + ': после закрывающей кавычки нужен разделитель.');
            } else {
                cell += ch;
            }
        }
        if (quoted) fail('Строка ' + start + ': не закрыта кавычка в CSV.');
        finishRow();
        return rows;
    }

    function parseBatch(input, mode, delimiter, limits) {
        input = text(input).replace(/^\uFEFF/, '');
        if (bytes(input).length > limits.import_bytes) fail('Список слишком большой. Ограничение — 1 МБ.');
        var rows;
        if (mode === 'lines') {
            rows = input.split(/\r\n?|\n/).map(function (value, index) {
                return { cells: [value.trim()], line: index + 1 };
            }).filter(function (row) { return row.cells[0] !== ''; });
        } else if (mode === 'csv') {
            delimiter = delimiter === 'auto' ? delimiterOf(input) : delimiter;
            if ([';', ',', '\t'].indexOf(delimiter) === -1) fail('Выберите разделитель CSV.');
            rows = csvRows(input, delimiter);
        } else {
            fail('Выберите формат списка.');
        }
        var nameColumn = 0, dataColumn = 1;
        if (mode === 'csv' && rows.length && rows[0].cells.length === 2) {
            var header = rows[0].cells.map(function (value) { return value.trim().toLowerCase(); });
            var names = ['name', 'filename', 'label', 'имя', 'название', 'файл'];
            var data = ['data', 'content', 'url', 'text', 'данные', 'содержимое', 'ссылка', 'текст'];
            if (names.indexOf(header[0]) !== -1 && data.indexOf(header[1]) !== -1) rows.shift();
            else if (data.indexOf(header[0]) !== -1 && names.indexOf(header[1]) !== -1) {
                rows.shift(); nameColumn = 1; dataColumn = 0;
            }
        }
        if (rows.length > limits.batch) fail('В одном пакете не больше ' + limits.batch + ' кодов. Разделите список.');
        return rows.map(function (row, index) {
            if (row.cells.length > 2) fail('Строка ' + row.line + ': нужны 1 или 2 колонки (имя;содержимое).');
            var value = row.cells.length === 1 ? row.cells[0] : row.cells[dataColumn];
            try { checkPayload(value, limits); } catch (error) { fail('Строка ' + row.line + ': ' + error.message); }
            return { label: row.cells.length === 1 ? 'qr' : (row.cells[nameColumn].trim() || 'qr'),
                data: value, line: row.line, index: index + 1 };
        });
    }

    // Маска ID: # — случайная цифра, * — случайный символ A–Z/0–9,
    // прочие разрешённые символы — литералы; \# и \* — буквальные токены.
    function idMaskTokens(mask, maxLength) {
        mask = text(mask);
        var chars = Array.from(mask), tokens = [], random = 0;
        if (!mask || chars.length > maxLength) fail('Маска ID должна содержать от 1 до ' + maxLength + ' символов.');
        if (mask.trim() !== mask) fail('Уберите пробелы в начале и конце маски ID.');
        for (var i = 0; i < chars.length; i++) {
            var ch = chars[i];
            if (ch === '\\') {
                var next = chars[++i];
                if (next !== '#' && next !== '*' && next !== '\\') fail('В маске после обратной косой черты можно экранировать только #, * или \\.');
                tokens.push({ literal: next });
            } else if (ch === '#') {
                tokens.push({ alphabet: '0123456789' }); random++;
            } else if (ch === '*') {
                tokens.push({ alphabet: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' }); random++;
            } else if (/^[\p{L}\p{N}_.-]$/u.test(ch)) {
                tokens.push({ literal: ch });
            } else {
                fail('В маске ID допустимы буквы, цифры, _, -, ., # и *.');
            }
        }
        if (!random) fail('Добавьте в маску случайный символ: # или *.');
        return { tokens: tokens, random: random };
    }

    // randomIndex(max) обязан вернуть криптографически случайный индекс [0, max).
    // Сам генератор проверяет размерность маски и гарантирует уникальность пакета.
    function generateIds(mask, count, randomIndex, limits) {
        limits = limits || {};
        var maxCount = Number(limits.batch) || 200;
        var maxLength = Number(limits.id_mask_chars) || 64;
        count = Number(count);
        if (!Number.isInteger(count) || count < 1 || count > maxCount) {
            fail('Количество ID должно быть от 1 до ' + maxCount + '.');
        }
        if (typeof randomIndex !== 'function') fail('Для генерации ID нужен источник случайных значений.');
        var parsed = idMaskTokens(mask, maxLength), capacity = 1;
        parsed.tokens.forEach(function (token) {
            if (token.alphabet) capacity = Math.min(count, capacity * token.alphabet.length);
        });
        if (capacity < count) fail('Эта маска не может создать ' + count + ' уникальных ID. Добавьте символы # или *.');
        var result = [], seen = new Set(), attempts = 0, maxAttempts = Math.max(1000, count * 100);
        while (result.length < count) {
            if (++attempts > maxAttempts) fail('Не удалось получить уникальные ID. Увеличьте случайную часть маски.');
            var id = parsed.tokens.map(function (token) {
                if (!token.alphabet) return token.literal;
                var index = randomIndex(token.alphabet.length);
                if (!Number.isInteger(index) || index < 0 || index >= token.alphabet.length) {
                    fail('Источник случайных значений вернул некорректный индекс.');
                }
                return token.alphabet[index];
            }).join('');
            if (!seen.has(id)) { seen.add(id); result.push(id); }
        }
        return result;
    }

    function filename(label, index, format) {
        if (['png', 'svg', 'jpeg'].indexOf(format) === -1) fail('Неизвестный формат файла.');
        var name = text(label).normalize('NFC').replace(/\.(png|svg|jpe?g)$/i, '')
            .replace(/[\x00-\x1f\x7f\\/:*?"<>|]/g, '-').replace(/^[.\s]+|[.\s]+$/g, '');
        name = Array.from(name).slice(0, 60).join('').replace(/[.\s]+$/g, '') || 'qr';
        if (/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i.test(name)) name = '_' + name;
        return String(index).padStart(3, '0') + '-' + name + '.' + (format === 'jpeg' ? 'jpg' : format);
    }

    function luminance(color) {
        var rgb = [1, 3, 5].map(function (start) {
            var channel = parseInt(color.slice(start, start + 2), 16) / 255;
            return channel <= 0.04045 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
        });
        return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
    }

    function contrast(a, b) {
        var first = luminance(a), second = luminance(b);
        return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
    }

    function options(input, settings) {
        var result = Object.assign({}, settings.defaults);
        input = input || {};
        Object.keys(settings.defaults).concat(['logo']).forEach(function (key) {
            if (Object.prototype.hasOwnProperty.call(input, key)) result[key] = input[key];
        });
        ['foreground', 'background', 'accent'].forEach(function (key) {
            if (typeof result[key] !== 'string' || !/^#[\da-f]{6}$/i.test(result[key])) fail('Цвет должен быть в формате #112233.');
        });
        ['shape', 'eye', 'level', 'format'].forEach(function (key) {
            var registry = settings[{ shape: 'shapes', eye: 'eyes', level: 'levels', format: 'formats' }[key]];
            if (typeof result[key] !== 'string' || !Object.prototype.hasOwnProperty.call(registry, result[key])) fail('Некорректная настройка «' + ({ shape: 'форма элементов', eye: 'угловые метки', level: 'защита', format: 'формат' }[key]) + '».');
        });
        result.size = Number(result.size);
        result.margin = Number(result.margin);
        result.logo_size = Number(result.logo_size);
        if (settings.sizes.indexOf(result.size) === -1) fail('Выберите доступный размер QR-кода.');
        if (!Number.isInteger(result.margin) || result.margin < 4 || result.margin > 8) fail('Отступ должен быть от 4 до 8 модулей.');
        if (!Number.isInteger(result.logo_size) || result.logo_size < 12 || result.logo_size > 20) fail('Логотип должен занимать от 12 до 20% ширины кода.');
        result.gradient = result.gradient === true;
        result.logo = text(result.logo);
        if (result.logo) {
            if (!/^data:image\/png;base64,[A-Za-z\d+/]+={0,2}$/.test(result.logo)
                || result.logo.length > Math.ceil(settings.limits.logo_bytes * 4 / 3) + 100) {
                fail('Используйте локальное изображение PNG, JPEG или WebP.');
            }
            result.level = 'H';
        }
        var colors = [result.foreground];
        if (result.gradient) colors.push(result.accent);
        result.contrast = Math.min.apply(null, colors.map(function (color) { return contrast(color, result.background); }));
        if (colors.some(function (color) { return luminance(color) >= luminance(result.background); }) || result.contrast < 3) {
            fail('Код должен быть тёмным на светлом фоне. Увеличьте контраст цветов или выберите готовый стиль.');
        }
        return result;
    }

    function matrix(data, level) {
        if (typeof qrcode !== 'function') fail('Библиотека QR не загрузилась. Обновите страницу.');
        // Конвертер общая библиотека делит с 2FA и короткими ссылками: возвращаем его.
        var previous = qrcode.stringToBytes;
        try {
            qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
            var qr = qrcode(0, level);
            qr.addData(data, 'Byte');
            qr.make();
            var count = qr.getModuleCount();
            return Array.from({ length: count }, function (_, row) {
                return Array.from({ length: count }, function (_, col) { return qr.isDark(row, col); });
            });
        } catch (_) {
            fail('Данные не помещаются в QR-код с этой защитой. Сократите текст или уберите логотип и выберите защиту M.');
        } finally { qrcode.stringToBytes = previous; }
    }

    // Расположение выравнивающих меток по версии QR (ISO/IEC 18004).
    function alignmentPositions(count) {
        var version = (count - 17) / 4;
        if (version === 1) return [];
        var number = Math.floor(version / 7) + 2;
        var step = version === 32 ? 26 : Math.floor((version * 4 + number * 2 + 1) / (number * 2 - 2)) * 2;
        var result = [6];
        for (var position = count - 7; result.length < number; position -= step) result.splice(1, 0, position);
        return result;
    }

    function finderCell(row, col, count) {
        return (row < 7 && col < 7) || (row < 7 && col >= count - 7) || (row >= count - 7 && col < 7);
    }

    function functionCell(row, col, count, positions) {
        if (row === 6 || col === 6) return true;
        if ((row <= 8 && col <= 8) || (row <= 8 && col >= count - 8) || (row >= count - 8 && col <= 8)) return true;
        if (count >= 45 && ((row < 6 && col >= count - 11) || (col < 6 && row >= count - 11))) return true;
        for (var i = 0; i < positions.length; i++) {
            for (var j = 0; j < positions.length; j++) {
                var x = positions[i], y = positions[j];
                if ((x === 6 && y === 6) || (x === 6 && y === count - 7) || (y === 6 && x === count - 7)) continue;
                if (Math.abs(col - x) <= 2 && Math.abs(row - y) <= 2) return true;
            }
        }
        return false;
    }

    function render(data, input, settings) {
        checkPayload(data, settings.limits);
        var style = options(input, settings);
        var grid = matrix(data, style.level), count = grid.length;
        var total = count + 2 * style.margin, positions = alignmentPositions(count);
        var visibleMatrix = grid, logoZone = null, logoCutouts = 0;
        if (style.logo) {
            var logoSide = count * style.logo_size / 100;
            var logoStart = (count - logoSide) / 2;
            logoZone = { left: Math.floor(logoStart), top: Math.floor(logoStart),
                right: Math.ceil(logoStart + logoSide), bottom: Math.ceil(logoStart + logoSide) };
            visibleMatrix = grid.map(function (row) { return row.slice(); });
            // Выгрызаем целые QR-модули из области логотипа ДО построения SVG.
            // Данные остаются в потоке с ECC H, который восстанавливает эти стирания.
            for (var maskRow = logoZone.top; maskRow < logoZone.bottom; maskRow++) {
                for (var maskCol = logoZone.left; maskCol < logoZone.right; maskCol++) {
                    if (visibleMatrix[maskRow][maskCol]) logoCutouts++;
                    visibleMatrix[maskRow][maskCol] = false;
                }
            }
        }
        var fill = style.gradient ? 'url(#qr-ink)' : style.foreground;
        var parts = ['<svg xmlns="http://www.w3.org/2000/svg" width="' + style.size + '" height="' + style.size
            + '" viewBox="0 0 ' + total + ' ' + total + '" role="img" aria-label="QR-код">', '<title>QR-код</title>'];
        if (style.gradient) parts.push('<defs><linearGradient id="qr-ink" x1="0" y1="0" x2="1" y2="1"><stop stop-color="'
            + style.foreground + '"/><stop offset="1" stop-color="' + style.accent + '"/></linearGradient></defs>');
        parts.push('<rect width="' + total + '" height="' + total + '" fill="' + style.background + '"/>');
        var squares = [], dataSquares = [];
        for (var row = 0; row < count; row++) {
            for (var col = 0; col < count; col++) {
                if (!visibleMatrix[row][col] || finderCell(row, col, count)) continue;
                var x = col + style.margin, y = row + style.margin;
                if (functionCell(row, col, count, positions)) squares.push('M' + x + ' ' + y + 'h1v1h-1z');
                else if (style.shape === 'square') dataSquares.push('M' + x + ' ' + y + 'h1v1h-1z');
                else if (style.shape === 'rounded') parts.push('<rect x="' + x + '" y="' + y + '" width="1" height="1" rx=".24" fill="' + fill + '"/>');
                else parts.push('<circle cx="' + (x + 0.5) + '" cy="' + (y + 0.5) + '" r=".47" fill="' + fill + '"/>');
            }
        }
        if (dataSquares.length) parts.push('<path d="' + dataSquares.join('') + '" fill="' + fill + '"/>');
        if (squares.length) parts.push('<path d="' + squares.join('') + '" fill="' + style.foreground + '"/>');
        [[0, 0], [count - 7, 0], [0, count - 7]].forEach(function (point) {
            var x = point[0] + style.margin, y = point[1] + style.margin;
            var radius = style.eye === 'rounded' ? [1.1, 0.65, 0.5] : [0, 0, 0];
            [7, 5, 3].forEach(function (side, index) {
                parts.push('<rect x="' + (x + index) + '" y="' + (y + index) + '" width="' + side + '" height="' + side
                    + '" rx="' + radius[index] + '" fill="' + (index === 1 ? style.background : style.foreground) + '"/>');
            });
        });
        if (style.logo) {
            var side = count * style.logo_size / 100, start = (total - side) / 2;
            var padding = Math.min(0.8, side / 8);
            var zoneX = logoZone.left + style.margin, zoneY = logoZone.top + style.margin;
            parts.push('<rect x="' + zoneX + '" y="' + zoneY + '" width="' + (logoZone.right - logoZone.left)
                + '" height="' + (logoZone.bottom - logoZone.top) + '" rx=".65" fill="' + style.background + '"/>');
            parts.push('<image href="' + style.logo + '" x="' + (start + padding) + '" y="' + (start + padding)
                + '" width="' + (side - padding * 2) + '" height="' + (side - padding * 2) + '" preserveAspectRatio="xMidYMid meet"/>');
        }
        parts.push('</svg>');
        return { svg: parts.join(''), matrix: grid, visibleMatrix: visibleMatrix, logoZone: logoZone,
            logoCutouts: logoCutouts, count: count, total: total,
            bytes: bytes(data).length, style: style, data: data };
    }

    // Табличные редакторы не должны исполнять содержимое пользовательских QR.
    function csvCell(value) {
        value = text(value);
        if (/^[\s]*[=+@-]/.test(value) || /^[\t\r\n]/.test(value)) value = "'" + value;
        return '"' + value.replace(/"/g, '""') + '"';
    }

    function manifest(items, format) {
        return '\uFEFFномер;файл;содержимое\r\n' + items.map(function (item, index) {
            return (index + 1) + ';' + csvCell(filename(item.label, index + 1, format)) + ';' + csvCell(item.data);
        }).join('\r\n') + '\r\n';
    }

    return { bytes: bytes, payload: payload, parseBatch: parseBatch, generateIds: generateIds, filename: filename,
        options: options, contrast: contrast, render: render, manifest: manifest, alignmentPositions: alignmentPositions };
});
