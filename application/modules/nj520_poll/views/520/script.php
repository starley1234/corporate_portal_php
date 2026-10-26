<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj520_poll: страница опроса — QR-код, копирование ссылки, живые результаты -->
<script>
(function () {
    'use strict';

    var CODE = <?php echo json_encode((string) $code); ?>;
    // LIVE: обновлять цифры есть смысл, только когда они на странице есть
    // (проголосовал и опрос ещё открыт). До голоса результатов не видно.
    var LIVE = <?php echo ! empty($live) ? 'true' : 'false'; ?>;

    // ----- QR-код опроса (локальная библиотека, без обращений наружу) -------
    var qrBox = document.getElementById('poll-qr');

    if (qrBox && typeof window.qrcode === 'function') {
        var qr = window.qrcode(0, 'M');
        qr.addData(qrBox.getAttribute('data-qr-url') || window.location.href);
        qr.make();
        qrBox.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 1, scalable: true });
        var svg = qrBox.querySelector('svg');
        if (svg) {
            svg.setAttribute('width', '168');
            svg.setAttribute('height', '168');
            svg.setAttribute('role', 'img');
            svg.setAttribute('aria-label', 'QR-код со ссылкой на опрос');
        }
    }

    // ----- Копирование ссылки ------------------------------------------------
    function toast(msg) { N2J.toast(msg); }

    var share = document.getElementById('poll-share');

    if (share) {
        share.addEventListener('click', function () {
            var url = qrBox ? qrBox.getAttribute('data-qr-url') : window.location.href;
            N2J.copyText(url).then(function () { toast('Ссылка на опрос скопирована'); }, function () { N2J.toast('Не удалось скопировать'); });
        });
    }

    // ----- Живые результаты ---------------------------------------------------
    // Раньше страница раз в 15 секунд перезагружалась целиком: прокрутка
    // прыгала, а уже отданный голос заново «мигал» сообщением. Теперь берём
    // те же цифры из /nj520/api/stats и меняем только числа и ширину полос.
    var box = document.querySelector('[data-poll-results]');
    var totalEl = document.querySelector('[data-poll-total]');
    var updatedEl = document.querySelector('[data-poll-updated]');

    if (!box || !LIVE) return;

    function fmt(value) {
        return Number(value).toFixed(1).replace('.', ',');
    }

    function paint(data) {
        if (!data || data.ok !== true) return;

        if (totalEl) totalEl.textContent = data.total;

        (data.options || []).forEach(function (opt) {
            var row = box.querySelector('[data-option="' + opt.id + '"]');
            if (!row) return;

            var votes = row.querySelector('[data-option-votes]');
            var percent = row.querySelector('[data-option-percent]');
            var bar = row.querySelector('[data-option-bar]');
            var leader = row.querySelector('[data-option-leader]');

            if (votes) votes.textContent = opt.votes;
            if (percent) percent.textContent = fmt(opt.percent);
            if (bar) bar.style.width = opt.percent + '%';
            if (leader) leader.classList.toggle('hidden', data.leader !== opt.id);
        });

        if (updatedEl) {
            var now = new Date();
            updatedEl.textContent = 'обновлено в ' + now.toTimeString().slice(0, 5);
        }
    }

    var timer = null;

    function tick() {
        if (document.hidden) return;   // вкладка не видна — не дёргаем сервер

        fetch('/nj520/api/stats/' + encodeURIComponent(CODE), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                paint(data);
                if (data && data.closed && timer) { clearInterval(timer); timer = null; }
            })
            .catch(function () { });
    }

    timer = setInterval(tick, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
})();
</script>
