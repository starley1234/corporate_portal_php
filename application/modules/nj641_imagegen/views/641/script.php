<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/* nj641: генератор изображений — режимы, отправка, статус-ряд, история.
   Контракт статус-ряда — как у /trends: «что делает воркер» + таймер по факту
   (elapsed_sec с сервера) + «Остановить». Никаких молчаливых выходов: если
   картинки нет — говорим об этом прямо. */
(function () {
    'use strict';

    var prompt = document.getElementById('nj641-prompt');
    var negative = document.getElementById('nj641-negative');
    var sizeSel = document.getElementById('nj641-size');
    var seedEl = document.getElementById('nj641-seed');
    var strengthWrap = document.getElementById('nj641-strength-wrap');
    var strength = document.getElementById('nj641-strength');
    var strengthVal = document.getElementById('nj641-strength-val');
    var imageWrap = document.getElementById('nj641-image-wrap');
    var photo = document.getElementById('nj641-photo');
    var goBtn = document.getElementById('nj641-go');
    var csrfEl = document.getElementById('nj641-csrf');

    var statusRow = document.getElementById('nj641-status');
    var statusTx = document.getElementById('nj641-status-text');
    var stageEl = document.getElementById('nj641-stage');
    var timerEl = document.getElementById('nj641-timer');
    var warnEl = document.getElementById('nj641-warn');
    var stopBtn = document.getElementById('nj641-stop');
    var errBox = document.getElementById('nj641-error');
    var resBox = document.getElementById('nj641-result');
    var resImg = document.getElementById('nj641-result-img');
    var resMeta = document.getElementById('nj641-result-meta');
    var resDl = document.getElementById('nj641-result-dl');

    var MODE = 'generate';
    var CURRENT = 0;   // прогон, который сейчас идёт или показан
    var BUSY = false;

    // Секундомер тикает ЛОКАЛЬНО каждую секунду, а сервер лишь уточняет
    // значение на каждом поллинге. Раньше таймер обновлялся только ответом
    // статуса (раз в 2.5 с) и пустовал, пока приходит elapsed_sec = 0 —
    // владелец видел «секундомер не идёт» (26.09).
    var TIMER = null;
    var T0 = 0;

    // Предел поллинга: 400 × 2.5 с ≈ 17 мин (дольше самой задачи в очереди).
    var MAX_TICKS = 400;

    function fmtTime(sec) {
        sec = Math.max(0, Math.round(sec || 0));
        var m = Math.floor(sec / 60), s = sec % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function csrf() { return csrfEl ? csrfEl.value : ''; }

    function timerStart() {
        timerStop();
        T0 = Date.now();
        timerEl.textContent = '0:00';
        TIMER = setInterval(function () {
            timerEl.textContent = fmtTime((Date.now() - T0) / 1000);
        }, 1000);
    }

    // Сервер прислал фактическое время прогона — подтягиваем секундомер к нему
    // (учитываем, что страница могла открыться позже старта).
    function timerSync(sec) {
        if (typeof sec === 'number' && sec > 0) {
            T0 = Date.now() - sec * 1000;
            timerEl.textContent = fmtTime(sec);
        }
    }

    function timerStop() {
        if (TIMER) { clearInterval(TIMER); TIMER = null; }
    }

    function setMode(m) {
        MODE = m;
        var needsImages = (m === 'edit' || m === 'multi-reference');
        imageWrap.classList.toggle('hidden', !needsImages);
        strengthWrap.classList.toggle('hidden', m !== 'edit');
        photo.multiple = (m === 'multi-reference');
        Array.prototype.forEach.call(document.querySelectorAll('.nj641-mode'), function (b) {
            var active = (b.getAttribute('data-mode') === m);
            b.className = 'nj641-mode inline-flex min-h-[40px] items-center gap-1.5 rounded-lg border px-3.5 py-1.5 text-sm font-semibold transition ' +
                (active
                    ? 'border-blue-600 bg-blue-600 text-white shadow-xs dark:border-blue-500 dark:bg-blue-600'
                    : 'border-gray-300 bg-white text-gray-700 hover:border-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200');
            b.classList.remove('text-gray-700', 'dark:text-gray-200', 'hover:border-blue-500');
        });
    }

    function setBusy(busy, label) {
        BUSY = !!busy;

        if (busy) {
            statusRow.classList.remove('hidden');
            statusRow.classList.add('flex');
            statusTx.textContent = label || 'Генерирую…';
            stageEl.textContent = '· задача в очереди';
            warnEl.classList.add('hidden');
            timerStart();
        } else {
            statusRow.classList.add('hidden');
            statusRow.classList.remove('flex');
            warnEl.classList.add('hidden');
            stageEl.textContent = '';
            timerStop();
            timerEl.textContent = '';
        }
    }

    function showError(text) {
        setBusy(false);
        goBtn.disabled = false;
        errBox.textContent = text || 'Генерация не удалась.';
        errBox.classList.remove('hidden');
    }

    function readFilesAsDataUrls(files) {
        var list = Array.prototype.slice.call(files || []).slice(0, 3);
        return Promise.all(list.map(function (f) {
            return new Promise(function (resolve, reject) {
                var fr = new FileReader();
                fr.onload = function () { resolve(fr.result); };
                fr.onerror = reject;
                fr.readAsDataURL(f);
            });
        }));
    }

    function imageUrl(j) {
        return j.image_url || (j.image_info && j.image_info.url) || '';
    }

    function showResult(j) {
        errBox.classList.add('hidden');
        resBox.classList.add('hidden');
        setBusy(false);
        goBtn.disabled = false;

        var url = imageUrl(j);
        var mt = (j.image_info && j.image_info.media_type) ? j.image_info.media_type : 'image/png';

        // Раньше здесь был молчаливый выход — «в сервисе картинку не видно».
        // Теперь говорим прямо, что именно пришло.
        if (!j.image_b64 && !url) {
            errBox.textContent = 'Воркер отчитался «готово», но картинку не передал (прогон #' + j.id +
                '). Журнал задачи — в «Управлении»: очередь → задача прогона.';
            errBox.classList.remove('hidden');
            return;
        }

        resImg.src = j.image_b64 ? ('data:' + mt + ';base64,' + j.image_b64) : url;
        resMeta.textContent = j.mode + ' · ' + j.width + '×' + j.height +
            (j.seed ? ' · seed ' + j.seed : '') +
            (j.model ? ' · ' + j.model : '') +
            (j.duration_ms ? ' · ' + Math.round(j.duration_ms / 1000) + 'с' : '');
        resDl.setAttribute('download', 'imagegen-' + j.id + '.png');
        resDl.setAttribute('href', j.image_b64 ? ('data:' + mt + ';base64,' + j.image_b64) : url);
        resBox.classList.remove('hidden');
    }

    function paintStatus(j) {
        statusTx.textContent = 'Генерирую изображение';
        stageEl.textContent = j.stage ? '· ' + j.stage : '';

        timerSync(j.elapsed_sec);

        // Молчание воркера показываем честно: «висящее» ожидание без объяснения
        // и есть та жалоба, с которой началась эта правка.
        var silent = (typeof j.silent_sec === 'number') ? j.silent_sec : null;
        if (silent !== null && silent > 90) {
            warnEl.textContent = 'Воркер молчит ' + fmtTime(silent) +
                ' — похоже, image-API не отвечает. Можно остановить и запустить заново.';
            warnEl.classList.remove('hidden');
        } else {
            warnEl.classList.add('hidden');
        }
    }

    function pollStatus(runId, n) {
        fetch('/imagegen/api/status/' + runId, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) {
                    showError(j.error || 'Прогон не найден.');
                    return;
                }

                if (j.status === 'done') {
                    finishRun(runId);
                    return;
                }

                if (j.status === 'error' || j.status === 'cancelled') {
                    showError(j.error || (j.status === 'cancelled' ? 'Генерация остановлена.' : 'Генерация не удалась.'));
                    return;
                }

                paintStatus(j);

                if (n >= MAX_TICKS) {
                    showError('Генерация идёт дольше 17 минут — остановите и попробуйте ещё раз.');
                    return;
                }

                setTimeout(function () { pollStatus(runId, n + 1); }, 2500);
            })
            .catch(function () {
                // Сеть мигнула — не сдаёмся, просто ждём дольше.
                setTimeout(function () { pollStatus(runId, n + 1); }, 5000);
            });
    }

    function finishRun(runId) {
        fetch('/imagegen/api/result/' + runId, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) { showResult(j); })
            .catch(function () { showError('Сервер не ответил — обновите страницу.'); });
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {}),
            cache: 'no-store'
        }).then(function (r) { return r.json(); });
    }

    function run() {
        errBox.classList.add('hidden');
        resBox.classList.add('hidden');
        warnEl.classList.add('hidden');

        readFilesAsDataUrls(photo.files).then(function (dataUrls) {
            if (prompt.value.trim() === '') { throw new Error('Опишите, что нарисовать.'); }

            if (MODE === 'edit' && dataUrls.length !== 1) { throw new Error('Для правки нужно ровно одно изображение.'); }
            if (MODE === 'multi-reference' && dataUrls.length === 0) { throw new Error('Добавьте референсы.'); }

            var payload = {
                mode: MODE,
                prompt: prompt.value.trim(),
                negative_prompt: negative.value.trim(),
                size: parseInt(sizeSel.value, 10),
                seed: parseInt(seedEl.value, 10) || 0,
                strength: parseFloat(strength.value),
                data_urls: dataUrls,
                csrf: csrf()
            };

            setBusy(true, MODE === 'generate' ? 'Генерирую…' : 'Обрабатываю и генерирую…');

            return fetch('/imagegen/api/run', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload),
                cache: 'no-store'
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) { throw new Error(j.error || 'Не удалось запустить генерацию.'); }
                CURRENT = j.run_id;
                pollStatus(j.run_id, 0);
            });
        }).catch(function (e) {
            showError(e.message || 'Сервер не ответил — попробуйте ещё раз.');
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.nj641-mode'), function (b) {
        b.addEventListener('click', function () { setMode(b.getAttribute('data-mode')); });
    });

    strength.addEventListener('input', function () {
        strengthVal.textContent = parseFloat(strength.value).toFixed(2);
    });

    goBtn.addEventListener('click', function () { goBtn.disabled = true; run(); });

    // «Остановить» — прерываем задачу в очереди и помечаем прогон остановленным.
    if (stopBtn) {
        stopBtn.addEventListener('click', function () {
            if (!CURRENT) { return; }
            stopBtn.disabled = true;
            post('/imagegen/api/cancel/' + CURRENT, { csrf: csrf() })
                .then(function (j) {
                    stopBtn.disabled = false;
                    if (!j.ok) { showError(j.error || 'Не удалось остановить.'); return; }
                    showError('Генерация остановлена.');
                })
                .catch(function () {
                    stopBtn.disabled = false;
                    showError('Сервер не ответил — попробуйте ещё раз.');
                });
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.nj641-history'), function (btn) {
        btn.addEventListener('click', function () {
            errBox.classList.add('hidden');
            resBox.classList.add('hidden');

            // История — ещё и «повторить»: подставляем описание и режим в форму.
            if (btn.getAttribute('data-prompt')) {
                prompt.value = btn.getAttribute('data-prompt');
            }

            var mode = btn.getAttribute('data-mode');
            if (mode === 'generate' || mode === 'edit' || mode === 'multi-reference') {
                setMode(mode);
            }
            fetch('/imagegen/api/result/' + btn.getAttribute('data-run'), { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) { showError(j.error || 'Прогон не найден.'); return; }
                    if (j.status === 'done') { showResult(j); }
                    else { showError(j.error || 'Прогон не завершился результатом.'); }
                    resBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                })
                .catch(function () { showError('Сервер не ответил.'); });
        });
    });

    // Удаление прогона из истории: строка + файл картинки на сервере.
    // Двойное нажатие (data-n2j-arm): первый клик взводит кнопку в app.js.
    Array.prototype.forEach.call(document.querySelectorAll('.nj641-del'), function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-run');
            btn.disabled = true;
            post('/imagegen/api/delete/' + id, { csrf: csrf() })
                .then(function (j) {
                    if (!j.ok) { btn.disabled = false; showError(j.error || 'Не удалось удалить.'); return; }
                    var li = btn.closest('li');
                    if (li) { li.remove(); }
                    if (CURRENT === parseInt(id, 10)) { resBox.classList.add('hidden'); }
                })
                .catch(function () { btn.disabled = false; showError('Сервер не ответил.'); });
        });
    });

    setMode('generate');
})();
</script>
