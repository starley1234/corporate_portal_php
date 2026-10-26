<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj632_csv — конвертер CSV↔JSON (клиентское зеркало Service_Nj632_Csv). */
(function () {
    'use strict';
    var input = document.getElementById('nj632-in');
    var inLabel = document.getElementById('nj632-inlabel');
    var out = document.getElementById('nj632-out');
    var go = document.getElementById('nj632-go');
    var clearBtn = document.getElementById('nj632-clear');
    var copyBtn = document.getElementById('nj632-copy');
    var delim = document.getElementById('nj632-delim');
    var params = document.getElementById('nj632-params');

    function mode() {
        var el = document.querySelector('input[name="nj632-mode"]:checked');
        return el ? el.value : 'csv2json';
    }

    function parseCsv(csv, delimiter) {
        var rows = [], line = [], field = '', inQuotes = false, i, ch;
        for (i = 0; i < csv.length; i++) {
            ch = csv[i];
            if (inQuotes) {
                if (ch === '"') {
                    if (csv[i + 1] === '"') { field += '"'; i++; } else inQuotes = false;
                } else field += ch;
            } else if (ch === '"') inQuotes = true;
            else if (ch === delimiter) { line.push(field); field = ''; }
            else if (ch === '\n' || ch === '\r') {
                if (ch === '\r' && csv[i + 1] === '\n') i++;
                line.push(field); rows.push(line); line = []; field = '';
            } else field += ch;
        }
        if (field !== '' || line.length) { line.push(field); rows.push(line); }
        return rows;
    }

    function csv2json(csv) {
        var d = delim.value === '\\t' ? '\t' : delim.value;
        var rows = parseCsv(csv, d);
        if (!rows.length) return null;
        var header = rows[0].map(function (h) { return h.trim(); });
        var res = [];
        for (var i = 1; i < rows.length; i++) {
            var obj = {};
            header.forEach(function (k, j) { obj[k !== '' ? k : 'col' + (j + 1)] = (rows[i][j] !== undefined ? rows[i][j] : ''); });
            res.push(obj);
        }
        return JSON.stringify(res, null, 2);
    }

    function cell(v) {
        v = String(v);
        if (/[",;\n]/.test(v)) return '"' + v.split('"').join('""') + '"';
        return v;
    }

    function json2csv(json) {
        var data;
        try { data = JSON.parse(json); } catch (e) { return null; }
        if (!Array.isArray(data) || !data.length) return null;

        var rows = data.map(function (row) {
            if (Array.isArray(row)) {
                var o = {}; row.forEach(function (v, i) { o['col' + (i + 1)] = v; }); return o;
            }
            if (row && typeof row === 'object') return row;
            return null;
        });
        if (rows.some(function (r) { return r === null; })) return null;

        var header = [];
        var seen = {};
        rows.forEach(function (r) { Object.keys(r).forEach(function (k) { if (!seen[k]) { seen[k] = true; header.push(k); } }); });

        var d = delim.value === '\\t' ? '\t' : delim.value;
        var lines = [header.map(cell).join(d)];
        rows.forEach(function (r) {
            lines.push(header.map(function (k) { return cell(r[k] !== undefined ? r[k] : ''); }).join(d));
        });
        return lines.join('\n');
    }

    function run() {
        var m = mode();
        var result = m === 'csv2json' ? csv2json(input.value) : json2csv(input.value);
        if (result === null || result === '') {
            out.textContent = 'Не удалось преобразовать. Проверьте входные данные (CSV — с заголовком в первой строке, JSON — массив).';
            copyBtn.disabled = true;
            return;
        }
        out.textContent = result;
        copyBtn.disabled = false;
        copyBtn.__value = result;
    }

    function syncUI() {
        var m = mode();
        inLabel.textContent = m === 'csv2json' ? 'CSV' : 'JSON';
        run();
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    function setMode(m) {
        document.querySelectorAll('input[name="nj632-mode"]').forEach(function (r) { r.checked = (r.value === m); });
        syncUI();
    }

    go.addEventListener('click', run);
    copyBtn.addEventListener('click', function () { copyText(copyBtn.__value); });
    clearBtn.addEventListener('click', function () { input.value = ''; out.textContent = 'Вставьте данные и нажмите «Конвертировать».'; copyBtn.disabled = true; });
    delim.addEventListener('change', run);
    document.querySelectorAll('input[name="nj632-mode"]').forEach(function (r) { r.addEventListener('change', syncUI); });
    document.querySelectorAll('.nj632-example').forEach(function (b) {
        b.addEventListener('click', function () {
            setMode(b.getAttribute('data-mode'));
            input.value = b.getAttribute('data-value')
                .split('&#10;').join('\n')
                .split('&quot;').join('"');
            run();
        });
    });

    syncUI();
})();
</script>
