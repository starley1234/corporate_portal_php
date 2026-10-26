<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj605_radar: общий скрипт страниц /radar и /radar/<code>.
 *
 * Здесь только то, что нужно обеим страницам:
 *   * «Собрать сейчас» — AJAX, ряд «идёт сбор» монтируется сразу (N2JLive);
 *   * двухклик на удаление радара (защита от случайного нажатия);
 *   * фильтр «новых N» в списке находок (только на /radar).
 *
 * Код радара берём из data-code кнопки, а не из DOM-соседей: на странице
 * одного радара (кнопка вне строки списка) прежний поиск `li` не находил код.
 */
?>
<script>
(function () {
    var CSRF = <?php echo json_encode($csrf); ?>;

    function toast(text, ok) {
        // Общий тост app.js; результат действия держим дольше обычного «ok».
        N2J.toast(text, ok ? 3500 : 0, ok ? 'ok' : 'err');
    }

    // «Запустить сбор сейчас» — AJAX: задача сразу в очередь nj120
    document.querySelectorAll('[data-nj605-run]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var original = btn.innerHTML;
            var li = btn.closest('li');
            var code = btn.getAttribute('data-code')
                || (li ? (li.querySelector('input[name="code"]') || {}).value : '');

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>Собираю…';

            if (!code) { btn.disabled = false; btn.innerHTML = original; return; }

            var fd = new FormData();
            fd.set('csrf', CSRF); fd.set('act', 'run_now'); fd.set('code', code); fd.set('ajax', '1');
            fetch(location.pathname, { method: 'POST', body: fd, cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false; btn.innerHTML = original;
                    toast(res.flash || (res.ok ? 'Запущено' : 'Не получилось'), !!res.ok);

                    // Ряд «идёт сбор» появляется СРАЗУ, из шаблона страницы —
                    // раньше он был виден только после перезагрузки.
                    if (res.ok && window.N2JLive) {
                        var host = (li && li.querySelector('[data-nj605-live-host]'))
                            || document.querySelector('[data-nj605-live-host]');
                        var prev = (li && li.querySelector('[data-nj605-last]'))
                            || document.querySelector('[data-nj605-last]');
                        if (prev) prev.remove();
                        window.N2JLive.mount(host, code);
                    }
                })
                .catch(function () { btn.disabled = false; btn.innerHTML = original; toast('Сеть не ответила — попробуйте ещё раз', false); });
        });
    });

    // «новых N» — фильтр ленты: только новые выбранного радара; повторный клик — все
    document.querySelectorAll('[data-nj605-fresh]').forEach(function (badge) {
        badge.addEventListener('click', function () {
            var wid = badge.getAttribute('data-watch');
            var active = badge.getAttribute('data-active') === '1';
            document.querySelectorAll('[data-nj605-fresh]').forEach(function (b) {
                b.removeAttribute('data-active');
                b.classList.remove('ring-2', 'ring-blue-400');
            });
            document.querySelectorAll('[data-nj605-watch]').forEach(function (li) {
                li.classList.remove('hidden');
            });
            if (active) return;
            badge.setAttribute('data-active', '1');
            badge.classList.add('ring-2', 'ring-blue-400');
            document.querySelectorAll('[data-nj605-watch]').forEach(function (li) {
                var show = li.getAttribute('data-nj605-watch') === wid
                    && li.getAttribute('data-nj605-status') === 'new';
                li.classList.toggle('hidden', ! show);
            });
        });
    });

})();
</script>
