<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/* nj602: «Перегенерировать схему» — общая кнопка для блока «Модерация» на
   странице статьи и для страницы ручной правки. Ставит воркеру задачу
   конвейера в режиме mode=mermaid, опрашивает её состояние и перезагружает
   вид, когда схема обновлена. Текст статьи при этом не меняется. */
(function () {
    'use strict';

    if (window.nj602RegenMermaid) return;

    window.nj602RegenMermaid = function (btn, opts) {
        opts = opts || {};

        var label = btn.querySelector('[data-regen-text]') || btn,
            base = opts.label || 'Перегенерировать схему',
            toastFn = opts.toast || function () {},
            spin = document.createElement('i'),
            tries = 0;

        spin.className = 'fas fa-sync fa-spin text-[11px]';

        function idle(text) {
            spin.remove();
            label.textContent = text || base;
            btn.disabled = false;
        }

        function poll() {
            if (tries++ > 200) { idle('Схема долго не приходит — попробуйте ещё раз'); return; }

            setTimeout(function () {
                fetch('/nj602/admin/mermaid_status/' + encodeURIComponent(opts.code || ''), { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (s) {
                        if (s.status === 'done') {
                            label.textContent = 'Схема обновлена — обновляю';
                            location.reload();
                            return;
                        }

                        if (s.status === 'failed' || s.status === 'cancelled') {
                            toastFn(s.error || 'Схему не удалось перегенерировать', false);
                            idle();
                            return;
                        }

                        if (s.stage) { label.textContent = s.stage + (s.elapsed_sec ? ' · ' + s.elapsed_sec + ' с' : ''); }

                        poll();
                    })
                    .catch(function () { poll(); });
            }, 3000);
        }

        var fd = new FormData();
        fd.set('csrf', opts.csrf || '');
        fd.set('id', opts.id || 0);

        btn.disabled = true;
        btn.insertBefore(spin, btn.firstChild);
        label.textContent = 'Схема перегенерируется…';

        fetch('/nj602/admin/regen_mermaid', { method: 'POST', body: fd, cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                toastFn(res.flash || (res.ok ? 'Готово' : 'Не получилось'), !!res.ok);
                if (!res.ok) { idle(); return; }
                poll();
            })
            .catch(function () { toastFn('Сеть не ответила', false); idle(); });
    };
})();
</script>
