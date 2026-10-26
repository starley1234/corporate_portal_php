<script>
/* nj570 управление: без интерактива — только автообновление очереди модерации раз в 30 с, если есть pending */
(function () {
    'use strict';
    if (document.body.innerHTML.indexOf('Модерация моделей (0)') !== -1) return;
    setTimeout(function () { window.location.reload(); }, 30000);
})();
</script>
