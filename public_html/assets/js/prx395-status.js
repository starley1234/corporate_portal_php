/* UI-only: API ownership and task execution remain on the server. */
(function () {
    'use strict';
    var submitting = false;
    var forms = document.querySelectorAll('[data-prx395-submit]');
    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (submitting) { event.preventDefault(); return; }
            submitting = true;
            forms.forEach(function (other) {
                var button = other.querySelector('[type="submit"]');
                if (other === form && window.PRX) window.PRX.run(button, true);
                else button.disabled = true;
            });
        });
    });
    window.addEventListener('pageshow', function () {
        submitting = false;
        forms.forEach(function (form) {
            var button = form.querySelector('[type="submit"]');
            if (window.PRX) window.PRX.run(button, false);
            else button.disabled = false;
        });
    });
    if (document.querySelector('[data-prx395-pending]')) {
        window.setTimeout(function () { window.location.reload(); }, 3000);
    }
})();
