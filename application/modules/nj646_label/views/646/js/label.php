<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/**
 * Лёгкая страница входа «Разбора состава»: состав текстом и/или фото этикетки.
 *
 * Владелец (03.10.2026): «человек может написать текст + этикетка, либо текст,
 * либо этикетка, нажать на кнопку и получить наглядный ответ… (сейчас после
 * загрузки этикетки ты гоняешь пользователя по закладкам)».
 *
 * Поэтому кнопка здесь ОДНА, и она доводит дело до ответа:
 *   текст            → разбор → страница разбора;
 *   фото             → распознавание → разбор → страница разбора;
 *   текст и фото     → распознавание (оно попутно собирает товар с этикетки)
 *                      → разбор по тексту человека → страница разбора.
 * Выбор «еда/косметика» из интерфейса убран: модуль определяет вид состава и
 * тип продукта сам (kind_guess + product_kind).
 *
 * Результат здесь НЕ рисуется: сервер сохраняет разбор и отдаёт его id, страница
 * открывает /label/analysis/<id> — там ответ целиком, в том же наглядном виде,
 * что и ход работы, и на него ведёт ссылка из «Моих разборов».
 */
(function () {
    'use strict';

    var N = window.N646;
    var LIMITS = <?php echo json_encode([
        'photo_mb' => (int) ($limits['photo_mb'] ?? 8),
    ], JSON_UNESCAPED_UNICODE); ?>;

    // Прогон фото: его id уходит в разбор, чтобы сервер дочитал строки этикетки
    // (там производитель, ГОСТ и штрихкод, которых в самом составе нет).
    var photoRun = null;
    var photoB64 = null;
    var busy = false;

    function detailedOn() {
        var box = N.$('#nj646-detailed');
        return box ? box.checked : true;
    }

    function typed() {
        var box = N.$('#nj646-text');
        return box ? box.value.trim() : '';
    }

    function setBusy(on, label) {
        busy = on;

        var btn = N.$('#nj646-analyze');

        if (on) {
            N.busy(btn, label || 'Работаю…');
        } else {
            N.idle(btn);
        }
    }

    // ------------------------------------------------------------- РАЗБОР

    /** Разбор состава: сервер считает и отдаёт страницу ответа. */
    function analyze(text, runId) {
        var out = N.$('#nj646-text-out');

        setBusy(true, photoRun ? 'Разбираю…' : 'Разбираю…');
        N.say(out, 'Разбираю состав…');

        N.api('analyze', {
            text: text,
            detailed: detailedOn(),
            run_id: runId
        }).then(function (data) {
            if (!data.ok) {
                setBusy(false);
                N.say(out, data.error || 'Не получилось разобрать.');
                return;
            }

            // Разбор сохранён — открываем страницу ответа: она постоянная
            // (ссылку можно послать) и её же открывают из «Моих разборов».
            N.say(out, 'Готово — открываю разбор…');
            window.location.href = '/label/analysis/' + Number(data.analysis);
        }).catch(function () {
            setBusy(false);
            N.say(out, 'Сеть недоступна — попробуйте ещё раз.');
        });
    }

    // --------------------------------------------------------------- ФОТО

    function shrinkImage(file, cb) {
        var reader = new FileReader();

        reader.onload = function () {
            var img = new Image();

            img.onload = function () {
                var maxW = 1400;
                var scale = img.width > maxW ? maxW / img.width : 1;
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(img.width * scale);
                canvas.height = Math.round(img.height * scale);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                cb(canvas.toDataURL('image/jpeg', 0.88));
            };

            img.src = reader.result;
        };

        reader.readAsDataURL(file);
    }

    var fileInput = N.$('#nj646-file');

    function clearPhoto() {
        photoB64 = null;
        photoRun = null;
        fileInput.value = '';
        N.$('#nj646-photo-preview').classList.add('hidden');
        N.$('#nj646-photo-preview').classList.remove('flex');
        N.$('#nj646-photo-img').removeAttribute('src');
        N.say('#nj646-text-out', '');
    }

    function takeFile(file) {
        if (!file) { return; }

        if (file.size > LIMITS.photo_mb * 1024 * 1024) {
            N.say('#nj646-text-out', 'Файл больше ' + LIMITS.photo_mb + ' МБ — сожмите его.');
            return;
        }

        shrinkImage(file, function (dataUrl) {
            photoB64 = dataUrl;
            photoRun = null;
            N.$('#nj646-photo-img').src = dataUrl;
            N.$('#nj646-photo-preview').classList.remove('hidden');
            N.$('#nj646-photo-preview').classList.add('flex');
            N.say('#nj646-text-out', 'Фото приложено — нажмите «Разобрать».');
        });
    }

    fileInput.addEventListener('change', function () {
        takeFile(fileInput.files && fileInput.files[0]);
    });

    N.$('#nj646-photo-clear').addEventListener('click', clearPhoto);

    // Перетаскивание: подпись обещает «приложить фото» — значит файл можно и
    // перетащить, а не только выбрать кнопкой.
    var drop = N.$('#nj646-drop');

    ['dragenter', 'dragover'].forEach(function (name) {
        drop.addEventListener(name, function (e) {
            e.preventDefault();
            drop.classList.add('border-blue-500');
        });
    });

    ['dragleave', 'drop'].forEach(function (name) {
        drop.addEventListener(name, function (e) {
            e.preventDefault();
            drop.classList.remove('border-blue-500');
        });
    });

    drop.addEventListener('drop', function (e) {
        var files = e.dataTransfer && e.dataTransfer.files;

        if (files && files.length) {
            takeFile(files[0]);
        }
    });

    /** Распознавание фото: живой ряд, затем разбор (текст человека важнее). */
    function recognize(text) {
        var out = N.$('#nj646-text-out');
        var host = N.$('#nj646-photo-live');

        host.innerHTML = '';
        setBusy(true, 'Распознаю фото…');
        N.say(out, 'Распознаю этикетку…');

        N.api('photo', { image: photoB64 }).then(function (data) {
            if (!data.ok) {
                setBusy(false);
                N.say(out, data.error || 'Не получилось отправить фото.');
                return;
            }

            photoRun = data.run_id;

            var el = N.mountLive('photo', host, '/label/api/status?id=' + Number(data.run_id), 'Распознаю этикетку');

            if (!el) {
                photoResult(data.run_id, text, 0);
                return;
            }

            el.addEventListener('n2j:live-done', function (e) {
                photoResult(data.run_id, text, 0, e.detail);
            });
        }).catch(function () {
            setBusy(false);
            N.say(out, 'Сеть недоступна — попробуйте ещё раз.');
        });
    }

    // Результат распознавания после терминального статуса ряда.
    //
    // Задача очереди может закрыться на мгновение раньше, чем сайт применит
    // результат (воркер сначала присылает его POST-ом, потом закрывает задачу),
    // поэтому первые попытки, когда прогон ещё идёт, повторяем — иначе человек
    // увидел бы «Готово» и пустое поле.
    function photoResult(runId, text, tries, live) {
        var out = N.$('#nj646-text-out');

        if (live && live.status && live.status !== 'done') {
            setBusy(false);
            N.say(out, live.error || 'Не удалось прочитать состав — попробуйте другое фото.');
            return;
        }

        N.api('status', { id: runId }).then(function (data) {
            if (!data.ok) {
                setBusy(false);
                N.say(out, data.error || 'Не получилось узнать статус.');
                return;
            }

            if ((data.status === 'queued' || data.status === 'running') && tries < 8) {
                setTimeout(function () { photoResult(runId, text, tries + 1, live); }, 1500);
                return;
            }

            var recognized = data.result && String(data.result.text || '').trim();

            if (data.status === 'error' || !recognized) {
                setBusy(false);
                N.say(out, (data.result && data.result.error) || data.error || 'Не удалось прочитать состав — попробуйте другое фото.');
                return;
            }

            // Своя пометка воркера (обрыв ответа, блик) уезжает на страницу
            // разбора вместе с текстом: там она и нужна человеку.
            //
            // Разбираем ТЕКСТ ЧЕЛОВЕКА, если он что-то написал: фото в этом
            // случае — источник сопутствующих данных этикетки (товар, ГОСТ,
            // производитель), а состав важнее тот, что человек проверил глазами.
            analyze(text && text.length >= 10 ? text : recognized, runId);
        }).catch(function () {
            setBusy(false);
            N.say(out, 'Сеть недоступна — попробуйте ещё раз.');
        });
    }

    // ------------------------------------------------------- ЕДИНАЯ КНОПКА

    N.$('#nj646-analyze').addEventListener('click', function () {
        if (busy) { return; }

        var text = typed();

        if (!text && !photoB64) {
            N.say('#nj646-text-out', 'Вставьте состав или приложите фото этикетки.');
            return;
        }

        if (photoB64) {
            recognize(text);
            return;
        }

        analyze(text, null);
    });

    // Ctrl+Enter — привычный способ отправить текст с клавиатуры.
    N.$('#nj646-text').addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            N.$('#nj646-analyze').click();
        }
    });
})();
</script>
