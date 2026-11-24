/* UI-only: API ownership and task execution remain on the server. */
(function () {
    'use strict';
    var input = document.getElementById('pdf-file');
    var info = document.getElementById('prx395-file-info');
    function showFile() {
        if (!input || !info) return;
        var file = input.files && input.files[0];
        input.setCustomValidity('');
        if (!file) { info.textContent = 'Файл пока не выбран · до 10 МиБ'; return; }
        var error = !/\.pdf$/i.test(file.name) ? 'Выберите PDF-документ.'
            : file.size > 10485760 ? 'Файл больше 10 МиБ. Выберите документ меньшего размера.' : '';
        input.setCustomValidity(error);
        info.textContent = error || file.name + ' · ' + (file.size / 1048576).toFixed(2) + ' МиБ · можно выбрать другой файл';
    }
    if (input) input.addEventListener('change', showFile);
    var drop = document.querySelector('[data-prx395-drop]');
    if (drop && input) {
        drop.addEventListener('dragover', function (event) { event.preventDefault(); });
        drop.addEventListener('drop', function (event) {
            event.preventDefault();
            if (!event.dataTransfer || event.dataTransfer.files.length !== 1) {
                input.setCustomValidity('Перетащите один PDF-документ.');
                if (info) info.textContent = 'Перетащите один PDF-документ.';
                return;
            }
            try { input.files = event.dataTransfer.files; showFile(); }
            catch (error) {
                input.value = '';
                input.setCustomValidity('Нажмите на область, чтобы выбрать PDF.');
                if (info) info.textContent = 'Нажмите на область, чтобы выбрать PDF.';
            }
        });
    }
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
        showFile();
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
