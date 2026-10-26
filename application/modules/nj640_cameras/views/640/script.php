<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/* nj640: камеры цеха — ручная проверка, камеры, подтверждения, история. */
(function () {
    'use strict';

    var photo = document.getElementById('nj640-photo');
    var sceneSel = document.getElementById('nj640-scene-select');
    var topic = document.getElementById('nj640-topic');
    var sceneName = document.getElementById('nj640-scene-name');
    var ruleText = document.getElementById('nj640-rule-text');
    var schema = document.getElementById('nj640-schema');
    var goBtn = document.getElementById('nj640-go');

    var statusRow = document.getElementById('nj640-status');
    var statusTx = document.getElementById('nj640-status-text');
    var timerEl = document.getElementById('nj640-timer');
    var errBox = document.getElementById('nj640-error');
    var resBox = document.getElementById('nj640-result');

    var TOPIC_HINTS = { '': '' };

    try {
        var topicsJson = document.getElementById('nj640-topics-json');
        var parsed = topicsJson ? JSON.parse(topicsJson.textContent || '{}') : {};
        Object.keys(parsed || {}).forEach(function (k) {
            TOPIC_HINTS[k] = parsed[k].label || k;
        });
    } catch (e) { /* не критично — просто без подсказок */ }

    function setBusy(busy, label) {
        if (busy) {
            statusRow.classList.remove('hidden');
            statusRow.classList.add('flex');
            statusTx.textContent = label || 'VLM анализирует кадр…';
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

    function readFileAsDataUrl(file) {
        return new Promise(function (resolve, reject) {
            if (!file) { resolve(null); return; }
            var fr = new FileReader();
            fr.onload = function () { resolve(fr.result); };
            fr.onerror = reject;
            fr.readAsDataURL(file);
        });
    }

    function fmtVerdict(j) {
        if (!j || typeof j !== 'object') return '';
        try { return JSON.stringify(j, null, 2); } catch (e) { return String(j); }
    }

    function showResult(j) {
        var okFlag = j.schema_ok !== false && j.status === 'done';
        resBox.className = 'n2j-flash ' + (okFlag ? 'n2j-flash-ok' : 'n2j-flash-warn') + ' block';
        resBox.innerHTML = '';

        var p = document.createElement('p');
        p.className = 'font-semibold';
        p.textContent = 'Статус: ' + (j.status || '') + (j.model ? ' · модель ' + j.model : '');
        resBox.appendChild(p);

        if (j.verdict) {
            var pre = document.createElement('pre');
            pre.className = 'mt-2 max-h-64 overflow-auto rounded-md bg-white/70 p-3 font-mono text-xs dark:bg-gray-900/60';
            pre.textContent = fmtVerdict(j.verdict);
            resBox.appendChild(pre);
        }
        if (j.error) {
            var e = document.createElement('p');
            e.className = 'mt-2 text-rose-700 dark:text-rose-300';
            e.textContent = j.error;
            resBox.appendChild(e);
        }
        resBox.classList.remove('hidden');
    }

    function pollResult(runId, attempts) {
        var n = attempts || 0;
        fetch('/cameras/api/result/' + runId, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status === 'done' || j.status === 'error') {
                    setBusy(false);
                    goBtn.disabled = false;
                    showResult(j);
                    location.reload();
                    return;
                }
                if (n > 60) { setBusy(false); goBtn.disabled = false; errBox.textContent = 'Проверка затянулась — повторите позже.'; errBox.classList.remove('hidden'); return; }
                setTimeout(function () { pollResult(runId, n + 1); }, 2500);
            })
            .catch(function () {
                setTimeout(function () { pollResult(runId, n + 1); }, 4000);
            });
    }

    // подтянуть сохранённый сценарий в форму
    function loadScene(sceneId) {
        if (!sceneId) { return; }
        fetch('/cameras/api/scene/' + sceneId, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { return; }
                sceneName.value = j.name || '';
                topic.value = j.topic || 'discipline';
                ruleText.value = j.rule_text || '';
                schema.value = j.schema_json || '';
            })
            .catch(function () {});
    }

    function run() {
        errBox.classList.add('hidden');
        resBox.classList.add('hidden');

        readFileAsDataUrl(photo.files && photo.files[0]).then(function (dataUrl) {
            var sceneId = parseInt(sceneSel.value, 10);

            if (!dataUrl) { throw new Error('Приложите кадр с камеры.'); }

            var payload = {
                scene_id: sceneId,
                topic: topic.value,
                scene_name: sceneName.value.trim(),
                rule_text: ruleText.value,
                schema_json: schema.value,
                data_urls: [dataUrl]
            };

            setBusy(true);

            return fetch('/cameras/api/run', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload),
                cache: 'no-store'
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) { setBusy(false); throw new Error(j.error || 'Не удалось запустить проверку.'); }
                pollResult(j.run_id, 0);
            });
        }).catch(function (e) {
            setBusy(false);
            goBtn.disabled = false;
            errBox.textContent = e.message || 'Сервер не ответил — попробуйте ещё раз.';
            errBox.classList.remove('hidden');
        });
    }

    goBtn.addEventListener('click', function () { goBtn.disabled = true; run(); });

    // пресет темы подсказывает название
    topic.addEventListener('change', function () {
        var label = TOPIC_HINTS[topic.value] || '';
        if (sceneName.value.trim() === '') {
            sceneName.placeholder = label ? ('Например: ' + label + ', зона 1') : 'Название сценария';
        }
    });

    // история: клик по строке — показать вердикт
    Array.prototype.forEach.call(document.querySelectorAll('.nj640-history'), function (btn) {
        btn.addEventListener('click', function () {
            var runId = btn.getAttribute('data-run');
            errBox.classList.add('hidden');
            resBox.classList.add('hidden');
            fetch('/cameras/api/result/' + runId, { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) { errBox.textContent = j.error || 'Прогон не найден.'; errBox.classList.remove('hidden'); return; }
                    showResult(j);
                    resBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                })
                .catch(function () {
                    errBox.textContent = 'Сервер не ответил.';
                    errBox.classList.remove('hidden');
                });
        });
    });

    sceneSel.addEventListener('change', function () {
        var sid = parseInt(sceneSel.value, 10);
        if (sid > 0) { loadScene(sid); }
        else {
            sceneName.value = '';
            ruleText.value = '';
            schema.value = '';
        }
    });

    // --- камеры ---
    var camKind = document.getElementById('nj640-cam-kind');
    var camTrigger = document.getElementById('nj640-cam-trigger');
    var camName = document.getElementById('nj640-cam-name');
    var camLocation = document.getElementById('nj640-cam-location');
    var camScene = document.getElementById('nj640-cam-scene');
    var camCfg = document.getElementById('nj640-cam-cfg');
    var camInterval = document.getElementById('nj640-cam-interval');
    var camStart = document.getElementById('nj640-cam-start');
    var camEnd = document.getElementById('nj640-cam-end');
    var camCooldown = document.getElementById('nj640-cam-cooldown');
    var camAdd = document.getElementById('nj640-cam-add');
    var camMsg = document.getElementById('nj640-cam-msg');

    var PRESETS = {
        snapshot: '{"snapshot_url":"http://camera/axis-cgi/jpg/image.cgi","login":"","password":""}',
        onvif: '{"host":"http://camera","port":80,"login":"admin","password":""}',
        http: '{"url":"https://obj.example.com/line2/latest.jpg"}'
    };

    camKind.addEventListener('change', function () {
        var k = camKind.value;
        if (camCfg.value.trim() === '' || (k in PRESETS)) {
            camCfg.value = PRESETS[k] || '{}';
        }
    });

    camTrigger.addEventListener('change', function () {
        camInterval.disabled = (camTrigger.value !== 'timer');
    });

    camAdd.addEventListener('click', function () {
        var sceneId = parseInt(camScene.value, 10);
        if (sceneId === 0) { camMsg.textContent = 'Выберите сценарий для камеры.'; return; }
        if (camName.value.trim() === '') { camMsg.textContent = 'Назовите камеру.'; return; }

        var cfg = {};
        try { cfg = JSON.parse(camCfg.value || '{}'); } catch (e) { camMsg.textContent = 'Настройки не JSON.'; return; }

        var workHours = null;
        if (camStart.value || camEnd.value) {
            if (!camStart.value || !camEnd.value) { camMsg.textContent = 'Укажите и «с», и «до» (или оставьте оба пустыми).'; return; }
            workHours = { start: camStart.value, end: camEnd.value, zone: 'Europe/Moscow' };
        }

        var payload = {
            kind: camKind.value,
            trigger: camTrigger.value,
            name: camName.value.trim(),
            location: camLocation.value.trim(),
            scene_id: sceneId,
            interval_sec: parseInt(camInterval.value, 10) || 60,
            cooldown_sec: parseInt(camCooldown.value, 10) || 300,
            work_hours: workHours,
            config: cfg
        };

        camAdd.disabled = true;
        camMsg.textContent = 'Сохраняю…';

        fetch('/cameras/api/camera', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload),
            cache: 'no-store'
        }).then(function (r) { return r.json(); }).then(function (j) {
            camAdd.disabled = false;
            if (!j.ok) { camMsg.textContent = j.error || 'Ошибка'; return; }
            camMsg.textContent = j.webhook_url ? ('Webhook: ' + j.webhook_url) : 'Камера добавлена.';
            setTimeout(function () { location.reload(); }, 1200);
        }).catch(function () {
            camAdd.disabled = false;
            camMsg.textContent = 'Сервер не ответил.';
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.nj640-fire'), function (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = '…';
            fetch('/cameras/api/camera-fire', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ code: btn.getAttribute('data-fire') }),
                cache: 'no-store'
            }).then(function (r) { return r.json(); }).then(function (j) {
                btn.disabled = false;
                btn.textContent = 'Тест';
                if (!j.ok) { camMsg.textContent = j.error || 'Ошибка запуска.'; return; }
                camMsg.textContent = 'Захват запущен, прогон #' + j.run_id + '.';
                setTimeout(function () { location.reload(); }, 1500);
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = 'Тест';
                camMsg.textContent = 'Сервер не ответил.';
            });
        });
    });

    // --- подтверждения событий ---
    Array.prototype.forEach.call(document.querySelectorAll('.nj640-confirm'), function (btn) {
        btn.addEventListener('click', function () {
            var eventId = btn.getAttribute('data-confirm');
            var verdict = btn.getAttribute('data-v');
            if (!eventId) { return; }

            btn.disabled = true;

            fetch('/cameras/api/confirm', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ event_id: parseInt(eventId, 10), verdict: verdict, note: '' }),
                cache: 'no-store'
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) { btn.disabled = false; return; }
                location.reload();
            }).catch(function () {
                btn.disabled = false;
            });
        });
    });
})();
</script>
