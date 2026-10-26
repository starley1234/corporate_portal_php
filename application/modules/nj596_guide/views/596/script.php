<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj596: Mermaid (локальный vendor) — рендер диаграмм презентаций.
 * Если библиотека не загрузилась — блоки остаются как читаемый код.
 */
?>

<script src="/assets/vendor/mermaid/mermaid.min.js"></script>
<script>
(function () {
    'use strict';

    var blocks = document.querySelectorAll('.mermaid');

    if (!blocks.length) return;

    if (typeof window.mermaid === 'undefined') {
        // библиотека недоступна: показываем исходник моноширинно (fallback без CDN)
        blocks.forEach(function (el) {
            el.style.whiteSpace = 'pre';
            el.style.fontFamily = 'ui-monospace, monospace';
        });
        return;
    }

    window.mermaid.initialize({
        startOnLoad: true,
        theme: document.documentElement.classList.contains('dark') ? 'dark' : 'neutral',
        securityLevel: 'strict',
        flowchart: { curve: 'basis', htmlLabels: true }
    });
})();
</script>
