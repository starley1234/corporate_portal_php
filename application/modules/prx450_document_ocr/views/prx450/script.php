<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
(function () {
    'use strict';
    var form = document.getElementById('prx450-upload');
    if (!form) return;
    form.addEventListener('submit', function (event) {
        if (form.dataset.busy === '1') { event.preventDefault(); return; }
        form.dataset.busy = '1';
        var button = form.querySelector('[type="submit"]');
        if (window.PRX) window.PRX.run(button, true);
        else button.disabled = true;
    });
    // Back/forward cache must not leave the restored form disabled.
    window.addEventListener('pageshow', function () {
        delete form.dataset.busy;
        var button = form.querySelector('[type="submit"]');
        if (window.PRX) window.PRX.run(button, false);
        else button.disabled = false;
    });
    var content = document.getElementById('prx450-content');
    var download = document.getElementById('prx450-download');
    if (download && content) download.addEventListener('click', function () {
        var url = URL.createObjectURL(new Blob([content.textContent], {type: 'text/markdown;charset=utf-8'}));
        var link = document.createElement('a');
        link.href = url; link.download = 'document.md';
        document.body.appendChild(link); link.click(); link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    });
    var print = document.getElementById('prx450-print');
    if (print) print.addEventListener('click', function () { window.print(); });
    var feedback = document.getElementById('prx450-feedback');
    if (feedback) { feedback.focus(); feedback.scrollIntoView({block: 'start', behavior: 'smooth'}); }
})();
</script>
