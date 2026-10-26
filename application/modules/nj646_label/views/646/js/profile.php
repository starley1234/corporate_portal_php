<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/**
 * Мой профиль (/label/profile): ограничения, под которые сервис предупреждает.
 *
 * Владелец (03.10.2026): «мой профиль — своя страница». Раньше профиль был
 * вкладкой рядом с разбором, и человек, впервые открывший /label, видел форму
 * про аллергии раньше, чем поле для состава. Здесь профиль на своём месте:
 * его открывают осознанно, когда разборы уже начали приносить советы.
 */
(function () {
    'use strict';

    var N = window.N646;

    N.$('#nj646-profile-save').addEventListener('click', function () {
        var btn = N.$('#nj646-profile-save');
        var out = N.$('#nj646-profile-out');

        N.busy(btn, 'Сохраняю…');

        var conditions = N.$$('#nj646-conditions input:checked').map(function (i) {
            return i.getAttribute('data-condition');
        });

        N.api('profile', {
            skin: N.$('#nj646-skin').value,
            diet: N.$('#nj646-diet').value,
            conditions: conditions,
            allergies: N.$('#nj646-allergies').value
        }).then(function (data) {
            N.idle(btn);
            N.say(out, data.ok
                ? 'Профиль сохранён — советы в разборе учтут его.'
                : (data.error || 'Не получилось сохранить.'));
        }).catch(function () {
            N.idle(btn);
            N.say(out, 'Сеть недоступна — попробуйте ещё раз.');
        });
    });

    // ------------------------------------------------- УДАЛЕНИЕ СВОИХ ДАННЫХ
    //
    // Необратимое действие спрашивает окном (data-n2j-confirm, app.js): что
    // именно сотрётся, написано в нём. Сюда клик доходит только после «Удалить».
    var forget = N.$('#nj646-forget');

    if (forget) {
        var label = N.$('[data-forget-label]');

        forget.addEventListener('click', function () {
            var out = N.$('#nj646-forget-out');

            label.textContent = 'Удаляю…';
            N.say(out, '');

            N.api('forget', { csrf: forget.getAttribute('data-csrf') }).then(function (data) {
                if (!data.ok) {
                    label.textContent = 'Удалить мои данные';
                    N.say(out, data.error || 'Не получилось удалить — попробуйте ещё раз.');
                    return;
                }

                var d = data.deleted || {};

                label.textContent = 'Удалено';
                N.say(out, 'Готово: разборов ' + (d.analyses || 0) + ', записей профиля ' + (d.profiles || 0)
                    + '. Возвращаю страницу…');

                // Профиль стёрт — перезагружаем страницу, чтобы форма показала
                // пустое состояние, а не значения, которых больше нет.
                setTimeout(function () { window.location.reload(); }, 1200);
            }).catch(function () {
                label.textContent = 'Удалить мои данные';
                N.say(out, 'Сеть недоступна — попробуйте ещё раз.');
            });
        });
    }
})();
</script>
