<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Память выбора пользователя в элементах управления (localStorage).
 *
 * Владелец: «выбор пользователя в элементах управления — запоминай в браузере».
 * Работает для select, полей даты, числа и переключателей: значение сохраняется
 * при изменении и восстанавливается при следующем открытии той же страницы.
 *
 * Ключ: n2j:pref:<путь страницы>|<форма>|<имя поля> — значения разных страниц и
 * форм не пересекаются. Тексты и textarea не трогаем (это ввод, а не выбор);
 * чекбоксы и произвольные поля подключаются атрибутом data-n2j-remember.
 *
 * Отключить память у поля — data-n2j-forget; у всей формы — data-n2j-no-remember.
 * Файл безопасен для повторного подключения (ставится один раз на страницу).
 */
if ( ! empty($GLOBALS['__n2j_prefs_js']))
{
    return;
}

$GLOBALS['__n2j_prefs_js'] = TRUE;
?>
<script>
/* N2JPrefs: запоминаем выбор пользователя в браузере (localStorage). */
window.N2JPrefs = (function () {
    'use strict';

    var KEY = 'n2j:pref:';
    var SELECTOR = 'select, input[type="date"], input[type="number"], input[type="radio"], [data-n2j-remember]';

    function store() {
        try { return window.localStorage || null; } catch (e) { return null; }
    }

    // Ключ: страница + форма + имя контрола (у radio — общий для группы).
    function keyOf(el) {
        var form = el.form, scope = 'p';

        if (form) {
            scope = form.getAttribute('action') || form.getAttribute('name') || form.id || 'p';
        }

        return KEY + location.pathname.replace(/\/+$/, '') + '|' + scope + '|' + (el.name || el.id || '');
    }

    function allowed(el) {
        if (!el || !el.matches || !el.matches(SELECTOR)) return false;
        if (el.disabled || el.readOnly) return false;
        if (el.type === 'hidden' || el.hasAttribute('data-n2j-forget')) return false;
        return !el.closest('[data-n2j-no-remember]');
    }

    function save(el) {
        var st = store();
        if (!st) return;

        var value = el.value;

        try { st.setItem(keyOf(el), value == null ? '' : String(value)); } catch (e) { /* приватный режим */ }
    }

    function restore(el) {
        var st = store();
        if (!st) return;

        var value = null;

        try { value = st.getItem(keyOf(el)); } catch (e) { return; }

        if (value === null) return;

        if (el.type === 'radio') {
            var group = document.querySelectorAll('input[type="radio"][name="' + el.name.replace(/"/g, '\\"') + '"]');

            group.forEach(function (r) { if (r.value === value) r.checked = true; });

            return;
        }

        if (el.type === 'checkbox') {
            el.checked = (value === '1' || value === 'true');
            return;
        }

        if (el.tagName === 'SELECT') {
            for (var i = 0; i < el.options.length; i++) {
                if (el.options[i].value === value) { el.value = value; return; }
            }
            return;
        }

        if (value !== '' && el.value !== value) {
            el.value = value;
        }
    }

    function boot() {
        document.querySelectorAll(SELECTOR).forEach(function (el) {
            if (allowed(el)) restore(el);
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target && allowed(e.target)) save(e.target);
    });

    // Ставим значения до того, как пользователь начнёт работу с формой.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    return { save: save, restore: restore, keyOf: keyOf };
})();
</script>
