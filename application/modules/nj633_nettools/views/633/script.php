<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/* nj633: Сетевые инструменты — клиентское зеркало серверного слоя (/net/api/*).
   В браузере только UI: все сетевые вызовы идут на сервер (SSRF-щит там). */
(function () {
    'use strict';

    var hostEl   = document.getElementById('nj633-host');
    var kindEl   = document.getElementById('nj633-kind');
    var typeEl   = document.getElementById('nj633-type-port');
    var runBtn   = document.getElementById('nj633-run');
    var copyBtn  = document.getElementById('nj633-copy');
    var statusRow= document.getElementById('nj633-status');
    var statusTx = document.getElementById('nj633-status-text');
    var timerEl  = document.getElementById('nj633-timer');
    var errBox   = document.getElementById('nj633-error');
    var resBox   = document.getElementById('nj633-result');
    var hintEl   = document.getElementById('nj633-kind-hint');
    var portHint = document.getElementById('nj633-port-hint');
    var llmBox   = document.getElementById('nj633-llm-box');
    var llmOut   = document.getElementById('nj633-llm-out');
    var llmQ     = document.getElementById('nj633-llm-q');
    var llmRun   = document.getElementById('nj633-llm-run');

    var lastResult = null;    // для «Объяснить»
    var lastCheckId = null;

    function setKind() {
        var k = kindEl.value;
        if (k === 'dns') {
            typeEl.disabled = false;
            typeEl.placeholder = 'A';
            hintEl.textContent = 'A / AAAA / MX / TXT / NS';
            portHint.textContent = 'A, AAAA, MX, TXT, NS, CNAME, SOA';
            if (typeEl.value === '80' || typeEl.value === '443') typeEl.value = 'A';
        } else if (k === 'whois') {
            typeEl.disabled = true;
            typeEl.value = '';
            typeEl.placeholder = '—';
            hintEl.textContent = 'WHOIS: регистрационные данные домена';
            portHint.textContent = 'Проверка идёт по домену, без порта';
        } else {
            typeEl.disabled = false;
            typeEl.placeholder = '443';
            hintEl.textContent = 'Доступность по TCP-подключению';
            portHint.textContent = 'Порт: 1–65535 (80, 443, 22…)';
            if (typeEl.value === 'A' || typeEl.value === '') typeEl.value = '443';
        }
    }

    kindEl.addEventListener('change', setKind);
    setKind();

    function setBusy(busy, text) {
        if (busy) {
            statusRow.classList.remove('hidden');
            statusRow.classList.add('flex');
            statusTx.textContent = text || 'Выполняю проверку…';
            var t0 = Date.now();
            clearInterval(timerEl._t);
            timerEl._t = setInterval(function () {
                timerEl.textContent = ((Date.now() - t0) / 1000).toFixed(1) + 's';
            }, 100);
        } else {
            clearInterval(timerEl._t);
            timerEl.textContent = '';
            statusRow.classList.add('hidden');
            statusRow.classList.remove('flex');
        }
    }

    function showError(msg) {
        errBox.textContent = msg || 'Не удалось выполнить проверку.';
        errBox.classList.remove('hidden');
    }

    function clearError() {
        errBox.classList.add('hidden');
        errBox.textContent = '';
    }

    function fmtValue(rec) {
        return rec.value || '';
    }

    function renderDns(json) {
        var lines = [];
        lines.push('Домен: ' + (json.host || '—'));
        lines.push('Тип:   ' + (json.type || '—') + ' · записей: ' + (json.count || 0));
        lines.push('');
        if (json.records && json.records.length) {
            json.records.forEach(function (r) {
                lines.push(r.type + '\t' + fmtValue(r) + (r.ttl ? '\t(ttl ' + r.ttl + ')' : ''));
            });
        } else {
            lines.push('(записей не найдено)');
        }
        return lines.join('\n');
    }

    function renderWhois(json) {
        return json.raw || '(пустой ответ WHOIS)';
    }

    function renderCheck(json) {
        var rows = [];
        rows.push('Хост:  ' + (json.host || '—'));
        rows.push('Порт:  ' + (json.port || '—'));
        rows.push('Статус: ' + (json.open ? 'ОТКРЫТ (доступен)' : 'закрыт / нет ответа'));
        rows.push('Время: ' + (json.elapsed_ms || 0) + ' мс');
        if (json.ip) rows.push('Адрес: ' + json.ip);
        if (!json.open && json.error) rows.push('Причина: ' + json.error);
        return rows.join('\n');
    }

    function runCheck() {
        var host = hostEl.value.trim();
        if (!host) { showError('Укажите домен или адрес, например example.com.'); hostEl.focus(); return; }

        clearError();
        setBusy(true, 'Выполняю проверку…');
        runBtn.disabled = true;
        llmBox.classList.add('hidden');
        lastResult = null;
        lastCheckId = null;

        var kind = kindEl.value;
        var url;
        var qs = 'host=' + encodeURIComponent(host.replace(/^https?:\/\//, ''));

        if (kind === 'dns') {
            url = '/net/api/dns?' + qs + '&type=' + encodeURIComponent(typeEl.value.trim() || 'A');
        } else if (kind === 'whois') {
            url = '/net/api/whois?' + qs;
        } else {
            url = '/net/api/check?' + qs + '&port=' + encodeURIComponent(parseInt(typeEl.value.trim(), 10) || 443);
        }

        fetch(url, { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                var j = res.body || {};
                if (j.error) { showError(j.error); return; }

                var out = kind === 'dns' ? renderDns(j)
                        : kind === 'whois' ? renderWhois(j)
                        : renderCheck(j);

                resBox.textContent = out;
                copyBtn.classList.remove('hidden');
                lastResult = { kind: kind, host: j.host || host, json: j };
                lastCheckId = j.check_id || null;
                llmBox.classList.remove('hidden');
            })
            .catch(function () { showError('Сервер не ответил — попробуйте ещё раз.'); })
            .finally(function () {
                setBusy(false);
                runBtn.disabled = false;
            });
    }

    function runLlm() {
        var q = llmQ.value.trim() || 'Что означает этот результат и что с ним делать?';
        llmRun.disabled = true;
        llmOut.classList.remove('hidden');
        llmOut.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1.5"></i>Объясняю…';

        var body = { target: '', prompt: q, check_id: lastCheckId || 0 };
        if (lastResult) {
            body.target = lastResult.host;
            body.prompt = 'Результат ' + lastResult.kind + '-проверки для ' + lastResult.host
                + ':\n' + (lastResult.json && lastResult.json.raw
                        ? lastResult.json.raw.slice(0, 2000)
                        : JSON.stringify(lastResult.json || {}).slice(0, 3000))
                + '\n\nВопрос владельца: ' + q;
        } else {
            body.target = hostEl.value.trim();
        }

        fetch('/net/api/llm', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j.error) { llmOut.innerHTML = '<span class="text-rose-600">' + j.error + '</span>'; return; }
            pollNote(j.note_id);
        })
        .catch(function () { llmOut.innerHTML = '<span class="text-rose-600">Не удалось отправить запрос.</span>'; })
        .finally(function () { llmRun.disabled = false; });
    }

    function pollNote(noteId) {
        var tries = 0;
        (function tick() {
            fetch('/net/api/llm_note?note_id=' + encodeURIComponent(noteId), {
                cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status === 'done' && j.text) {
                    llmOut.textContent = j.text;
                    return;
                }
                if (j.status === 'error') {
                    llmOut.innerHTML = '<span class="text-rose-600">Не удалось: ' + (j.error || 'LLM не ответил') + '</span>';
                    return;
                }
                tries++;
                if (tries < 15) setTimeout(tick, 1500);
                else llmOut.innerHTML = '<span class="text-gray-500">Ещё выполняется — проверьте чуть позже.</span>';
            })
            .catch(function () { llmOut.innerHTML = '<span class="text-gray-500">Ещё выполняется…</span>'; });
        })();
    }

    function copyResult() {
        var text = resBox.textContent;
        if (!text) return;

        function ok() {
            var old = copyBtn.innerHTML;
            copyBtn.innerHTML = '<i class="fas fa-check"></i> Скопировано';
            setTimeout(function () { copyBtn.innerHTML = old; }, 1500);
        }

        if (window.N2J && N2J.copyText) {
            N2J.copyText(text).then(ok, ok);
            return;
        }

        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        ok();
    }

    runBtn.addEventListener('click', runCheck);
    copyBtn.addEventListener('click', copyResult);
    llmRun.addEventListener('click', runLlm);
    hostEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') runCheck();
    });

    // ---- Конвертеры (IP↔integer, Base64) ----
    var cvIpBtn   = document.getElementById('nj633-cv-ip');
    var cvB64Btn  = document.getElementById('nj633-cv-b64');
    var cvIpPane  = document.getElementById('nj633-cv-ip-pane');
    var cvB64Pane = document.getElementById('nj633-cv-b64-pane');
    var ipIn      = document.getElementById('nj633-ipin');
    var ipRun     = document.getElementById('nj633-ip-run');
    var ipOut     = document.getElementById('nj633-ip-out');
    var b64Mode   = document.getElementById('nj633-b64-mode');
    var b64Text   = document.getElementById('nj633-b64-text');
    var b64Run    = document.getElementById('nj633-b64-run');
    var b64Out    = document.getElementById('nj633-b64-out');

    function setConvTab(which) {
        var ipOn = which === 'ip';
        cvIpPane.classList.toggle('hidden', !ipOn);
        cvB64Pane.classList.toggle('hidden', ipOn);
        cvIpBtn.className = 'n2j-seg-item' + (ipOn ? ' is-active' : '');
        cvB64Btn.className = 'n2j-seg-item' + (!ipOn ? ' is-active' : '');
    }

    cvIpBtn.addEventListener('click', function () { setConvTab('ip'); });
    cvB64Btn.addEventListener('click', function () { setConvTab('b64'); });

    function runIpConv() {
        var v = ipIn.value.trim();
        if (!v) { ipOut.textContent = 'Введите IP-адрес или целое число.'; return; }
        ipRun.disabled = true;
        ipOut.textContent = '…';
        fetch('/net/api/ipconv?value=' + encodeURIComponent(v), {
            cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j.error) { ipOut.textContent = j.error; return; }
            ipOut.textContent = j.int
                ? j.ip + '  →  ' + j.int + '  (' + j.hex + ')'
                : (j.ip + '  ←  ' + j.int);
        })
        .catch(function () { ipOut.textContent = 'Не удалось выполнить преобразование.'; })
        .finally(function () { ipRun.disabled = false; });
    }

    ipRun.addEventListener('click', runIpConv);
    ipIn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') runIpConv();
    });

    function runB64() {
        var t = b64Text.value;
        if (!t) { b64Out.textContent = 'Введите текст.'; return; }
        b64Run.disabled = true;
        b64Out.textContent = '…';
        fetch('/net/api/base64', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ text: t, mode: b64Mode.value })
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j.error) { b64Out.textContent = j.error; return; }
            b64Out.textContent = j.out;
        })
        .catch(function () { b64Out.textContent = 'Не удалось выполнить преобразование.'; })
        .finally(function () { b64Run.disabled = false; });
    }

    b64Run.addEventListener('click', runB64);
    b64Text.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) runB64();
    });
})();
</script>
