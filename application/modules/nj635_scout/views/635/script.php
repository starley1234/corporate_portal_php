<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Живой отчёт /check: раз в 4 секунды спрашиваем /check/api/status и
 * подменяем только те блоки, у которых изменился отпечаток (data-rev).
 *
 * Секундомер тикает локально — страница не перерисовывается между опросами.
 * Когда все шаги завершились, опрос останавливается и страница один раз
 * перезагружается — уже с готовыми данными (ссылки и подписи в постоянном
 * виде). Перезагрузка идёт только если на странице что-то изменилось: иначе
 * только что загруженная страница крутила бы перезагрузку бесконечно.
 */
?>
<script>
(function () {
    var root = document.querySelector('[data-nj635-report]');

    if ( ! root || ! root.dataset.api) { return; }

    var api     = root.dataset.api;
    var blocks  = root.querySelector('[data-nj635-blocks]');
    var counter = root.querySelector('[data-nj635-counter]');
    var hint    = root.querySelector('[data-nj635-hint]');
    var revs    = {};
    var timer   = null;
    var ticker  = null;
    var busy    = false;
    var failed  = 0;
    var changed = false;

    // что нарисовал сервер — с этим и сравниваем
    var shown = root.querySelectorAll('[data-nj635-step]');

    for (var i = 0; i < shown.length; i++) {
        revs[shown[i].getAttribute('data-nj635-step')] = shown[i].getAttribute('data-rev') || '';
    }

    // локальные секундомеры: считаем от факта, не дожидаясь сервера
    function tick() {
        var nodes = root.querySelectorAll('[data-nj635-timer]');

        for (var i = 0; i < nodes.length; i++) {
            var n = parseInt(nodes[i].dataset.elapsed || '0', 10) + 1;
            nodes[i].dataset.elapsed = n;
            nodes[i].textContent = n > 90 ? Math.floor(n / 60) + ' мин ' + (n % 60) + ' с' : n + ' с';
        }
    }

    function applyStep(step) {
        if ( ! step || ! step.key || ! step.html) { return; }

        if (revs[step.key] === step.rev) { return; }

        var el = blocks.querySelector('[data-nj635-step="' + step.key + '"]');

        revs[step.key] = step.rev;

        if (el) {
            el.outerHTML = step.html;
            changed = true;
        }
    }

    function poll() {
        if (busy) { return; }
        busy = true;

        fetch(api, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                busy = false;
                failed = 0;

                if ( ! j || ! j.ok || ! j.steps) { return; }

                var ready = 0;
                var total = 0;

                for (var k in j.steps) {
                    if ( ! j.steps.hasOwnProperty(k)) { continue; }

                    total++;
                    applyStep(j.steps[k]);

                    if (j.steps[k].status === 'done') { ready++; }
                }

                if (counter) { counter.textContent = 'готово ' + ready + ' из ' + total; }

                if ( ! j.done) { return; }

                stop();

                if (hint) { hint.innerHTML = '<i class="fas fa-circle-check mr-1 text-emerald-500"></i>готово'; }

                if (changed) {
                    // показываем уже собранный результат в постоянном виде
                    setTimeout(function () { window.location.reload(); }, 900);
                }
            })
            .catch(function () {
                busy = false;
                failed++;

                if (failed === 3 && hint) {
                    hint.innerHTML = '<i class="fas fa-triangle-exclamation mr-1 text-amber-500"></i>связь потеряна — обновите страницу';
                }
            });
    }

    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
        if (ticker) { clearInterval(ticker); ticker = null; }
    }

    ticker = setInterval(tick, 1000);
    timer = setInterval(poll, 4000);
    poll();
})();
</script>
