<script>
/* nj593 «Тренды GitHub»:
   1) флеш-сообщение закрывается;
   2) удаление спрашивает окном, публикация — вторым нажатием: это общий app.js
      (data-n2j-confirm / data-n2j-arm), здесь своего кода нет;
   3) сборка обзора: неразрывный статус-ряд (спиннер + секундомер), живой
      прогресс и позиции по /trends/api/status/<code> — AJAX, без перезагрузок;
   4) ПРЕРВАТЬ сборку — вторым нажатием (data-n2j-arm), POST /trends/api/cancel:
      задача снимается, страница перезагружается. */
(function () {
    /* ВАЖНО: части, не зависящие от страницы обзора, выполняются ДО проверки
       root. Раньше стоял ранний `if (!root) return` — и на /trends (список
       «Мои обзоры») не работали ни закрытие флеша, ни удаление,
       ни кнопка «Открыть»: скрипт умирал на первой строке. */
    /* флеш */
    var flash = document.querySelector('[data-flash]');
    if (flash) {
        var xc = flash.querySelector('[data-flash-close]');
        if (xc) xc.addEventListener('click', function () { flash.remove(); });
    }

    /* прерывание сборки: второе нажатие (data-n2j-arm, app.js) — первое взводит
       кнопку «Точно прервать?» и сюда не доходит */
    var cancelBtn = document.querySelector('[data-n593-cancel]');

    if (cancelBtn) {
        var cancelLabel = cancelBtn.querySelector('[data-n593-cancel-label]');
        var cancelOrig = cancelLabel ? cancelLabel.textContent : '';

        function resetCancel() {
            cancelBtn.disabled = false;
            if (cancelLabel) cancelLabel.textContent = cancelOrig;
        }

        cancelBtn.addEventListener('click', function () {
            cancelBtn.disabled = true;
            if (cancelLabel) cancelLabel.textContent = 'Останавливаю…';
            var body = 'code=' + encodeURIComponent(cancelBtn.getAttribute('data-code'))
                     + '&csrf=' + encodeURIComponent(cancelBtn.getAttribute('data-csrf'));
            fetch('/trends/api/cancel', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: body
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.ok) { setTimeout(reload, 400); return; }
                resetCancel();
                N2J.toast((j && (j.message || j.error)) || 'Не удалось прервать сборку', 0, 'err');
            }).catch(function () {
                resetCancel();
                N2J.toast('Сеть недоступна — попробуйте ещё раз', 0, 'err');
            });
        });
    }

    /* копирование ссылки на обзор (страница обзора, правая колонка) */
    document.querySelectorAll('[data-n593-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var val = btn.getAttribute('data-n593-copy') || '';
            var done = function () {
                btn.innerHTML = '<i class="fas fa-check text-emerald-500"></i>';
                setTimeout(function () { btn.innerHTML = '<i class="fas fa-copy"></i>'; }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(val).then(done, function () { fallback(); });
            } else {
                fallback();
            }
            function fallback() {
                var input = document.querySelector('[data-n593-share]');
                if (input) { input.select(); input.setSelectionRange(0, 99999); }
                try { document.execCommand('copy'); done(); } catch (e) { /* ничего */ }
            }
        });
    });

    /* переходы по кнопкам-ссылкам (data-go) — используются в «Мои обзоры» */
    document.querySelectorAll('[data-go]').forEach(function (btn) {
        btn.addEventListener('click', function () { location.href = btn.getAttribute('data-go'); });
    });

    /* дальше — только страница обзора (/trends/<code>) */
    var root = document.querySelector('[data-n593-code]');

    if (!root) return;

    var code = root.getAttribute('data-n593-code');
    var status = root.getAttribute('data-n593-status');

    if (status !== 'new' && status !== 'running') return;

    /* секундомер: база — серверное elapsed_sec задачи, тикаем локально между опросами */
    var elapsedEl = root.querySelector('[data-n593-elapsed]');
    var sec = 0;

    /* сервер уже показал фактическое время (например «12:30») — продолжаем
       счёт от него, а не с нуля: иначе таймер «сбрасывался» в 0:00 */
    if (elapsedEl) {
        var m0 = /^(\d+):([0-5]\d)$/.exec(elapsedEl.textContent.trim());
        if (m0) sec = (parseInt(m0[1], 10) * 60) + parseInt(m0[2], 10);
    }
    function renderSec() {
        if (elapsedEl) elapsedEl.textContent = Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2);
    }
    if (elapsedEl) {
        setInterval(function () { sec++; renderSec(); }, 1000);
    }

    /* живой статус: прогресс, счётчик позиций, готовый обзор — перерисовка */
    var progressEl = root.querySelector('[data-n593-progress]');
    var readyEl = root.querySelector('[data-n593-ready]');
    var badgeEl = root.querySelector('[data-n593-badge]');
    var polls = 0;

    function stopPolling() {
        var run = root.querySelector('[data-n593-running]');
        if (run) run.remove();
    }

    function reload() { location.reload(); }

    function poll() {
        polls++;
        fetch('/trends/api/status/' + encodeURIComponent(code), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (s) {
            if (!s || s.error) return;

            // серверное время задачи — точнее локального счётчика
            if (s.job && typeof s.job.elapsed_sec === 'number' && s.job.elapsed_sec > sec) {
                sec = s.job.elapsed_sec;
                renderSec();
            }

            if (progressEl && s.progress) progressEl.textContent = s.progress;
            else if (progressEl && s.job && s.job.status === 'pending') {
                // «Очередь не двигается» тоже нужно показывать честно: если
                // задача ждёт дольше 10 минут, скорее всего воркер не запущен —
                // иначе владелец видел бы вечное «Ставим задачу в очередь…».
                var wait = (typeof s.job.elapsed_sec === 'number') ? s.job.elapsed_sec : 0;
                progressEl.textContent = wait > 600
                    ? 'Задача ждёт воркера уже ' + Math.floor(wait / 60) + ' мин. Похоже, воркер не запущен — через ' + Math.max(1, 15 - Math.floor(wait / 60)) + ' мин сбор закроется сам, и обзор можно будет запустить заново.'
                    : 'Задача в очереди — ждём свободного воркера…';
            }

            // Долгая сборка без результата: честно говорим, что она не «зависла
            // навсегда» — потолок на сервере закроет её сам с понятным итогом.
            if (progressEl && s.status === 'running' && sec > 1200 && s.items_ready === 0) {
                progressEl.textContent = 'Сборка идёт дольше обычного (' + Math.floor(sec / 60)
                    + ' мин). Если результата не будет, она остановится автоматически — обзор можно запустить заново.';
            }

            if (readyEl && typeof s.items_ready === 'number') {
                readyEl.textContent = s.items_ready;
            }

            if (badgeEl && s.status) {
                var map = {
                    new:       ['новый',       'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
                    running:   ['в работе',    'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300'],
                    done:      ['готово',      'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300'],
                    error:     ['ошибка',      'bg-rose-100 text-rose-700 dark:bg-rose-900/50 dark:text-rose-300'],
                    cancelled: ['остановлено', 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300']
                };
                var m = map[s.status] || map.new;
                badgeEl.textContent = m[0];
                badgeEl.className = 'rounded-full px-2.5 py-1 text-xs font-bold ' + m[1];
            }

            if (s.status === 'done' || s.status === 'error' || s.status === 'cancelled') {
                // обзор готов, упал или остановлен: полная перезагрузка раз —
                // данные целостные (при остановке на месте ряда появится
                // «Сборка остановлена» с сохранёнными позициями)
                stopPolling();
                setTimeout(reload, 700);
                return;
            }

            setTimeout(poll, 3000);
        }).catch(function () {
            setTimeout(poll, 6000); // сеть моргнула — продолжаем тише
        });
    }

    setTimeout(poll, 1200);
})();
</script>
