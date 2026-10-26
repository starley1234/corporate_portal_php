<script>
/* nj603: визуальное подтверждение пинков (формы обычные POST) */
(function () {
    'use strict';
    document.querySelectorAll('form[action="/nj603/admin"]').forEach(function (f) {
        f.addEventListener('submit', function () {
            var b = f.querySelector('button[type="submit"]');

            if (b) {
                b.disabled = true;
                b.classList.add('opacity-60');
            }
        });
    });
})();
</script>
