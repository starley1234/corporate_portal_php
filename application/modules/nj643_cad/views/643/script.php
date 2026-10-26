<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: скрипты страниц /cad и /cad/<code>.
 *
 * Здесь только то, чего не делает общий механизм:
 *   * «Собрать деталь» — кнопка-примеры, подстановка текста в описание;
 *   * «Пересобрать» из карточки — AJAX + ряд «идёт сбор» сразу, без перезагрузки;
 *   * ползунки параметров — живое значение и отправка на пересборку;
 *   * «Строк:» — выбор помнит браузер (n2j:per:/cad), как на /trends;
 *   * копирование ссылки/кода и подтверждение удаления;
 *   * точный размер числом рядом с ползунком + «вернуть размеры ИИ»;
 *   * масса по выбранному материалу (объём × плотность, выбор помнит браузер);
 *   * черновик описания не теряется при перезагрузке (n2j:cad:draft);
 *   * «Печать» — карточка детали для цеха (печатный блок в part.php);
 *   * сравнение ревизий — построчный diff кода модели (LCS, без ИИ).
 *
 * Живой статус-ряд (спиннер, секундомер, этапы, финал) рисует общий
 * partials/live_status + live_status_js.
 */
$scope = isset($scope) ? (string) $scope : 'index';
$code  = isset($code) ? (string) $code : '';
$csrf  = isset($csrf) ? (string) $csrf : '';

echo View::factory('partials/live_status_js');

$csrf_json = json_encode((string) $csrf);
?>
<script>
(function () {
    'use strict';

    var CSRF = <?php echo $csrf_json; ?>;

    function toast(text, ok) {
        // Общий тост app.js; результат действия держим дольше обычного «ok».
        N2J.toast(text, ok ? 3500 : 0, ok ? 'ok' : 'err');
    }

    function post(path, data) {
        return fetch(path, {
            method: 'POST', cache: 'no-store', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        }).then(function (r) { return r.json().catch(function () { return {}; }); });
    }

    /* Примеры описаний: клик подставляет текст (а не отправляет форму) */
    document.querySelectorAll('[data-nj643-example]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var box = document.getElementById('nj643-prompt');

            if (!box) return;

            box.value = btn.getAttribute('data-nj643-example') || '';
            box.focus();
        });
    });

    /* «Пересобрать» из карточки: задача сразу в очередь + ряд без перезагрузки */
    document.querySelectorAll('[data-nj643-run]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var code = btn.getAttribute('data-code') || '';
            var original = btn.innerHTML;

            if (!code || btn.disabled) return;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>Ставлю задачу…';

            post('/cad/api/run', { csrf: CSRF, code: code, action: 'again' })
                .then(function (res) {
                    btn.disabled = false;
                    btn.innerHTML = original;

                    if (res.error) {
                        toast(res.error, false);
                        return;
                    }

                    toast('Сборка запущена — страница детали покажет этапы', true);

                    var scope = btn.closest('[data-nj643-part]') || document;
                    var host = scope.querySelector('[data-nj643-live-host]');

                    if (host && window.N2JLive) {
                        var prev = host.querySelector('[data-n2j-live]');
                        if (prev) prev.remove();
                        window.N2JLive.mount(host, code);
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.innerHTML = original;
                    toast('Сеть не ответила — попробуйте ещё раз', false);
                });
        });
    });

    /* AJAX-формы страницы детали: «собрать заново» и «починить границы» */
    document.querySelectorAll('form[data-nj643-ajax]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var btn = form.querySelector('button[type="submit"]');
            var original = btn ? btn.innerHTML : '';
            var act = form.getAttribute('data-nj643-ajax');

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>Ставлю задачу…';
            }

            post('/cad/api/run', { csrf: CSRF, code: '<?php echo HTML::chars($code); ?>',
                    action: (act === 'fix_limits' ? 'again' : 'again') })
                .then(function (res) {
                    if (btn) { btn.disabled = false; btn.innerHTML = original; }

                    if (res.error) { toast(res.error, false); return; }

                    toast('Запущено — покажу этапы сборки', true);

                    var host = document.querySelector('[data-nj643-live-host]');

                    if (host && window.N2JLive) {
                        var prev = host.querySelector('[data-n2j-live]');
                        if (prev) prev.remove();
                        window.N2JLive.mount(host, '<?php echo HTML::chars($code); ?>');
                    }
                })
                .catch(function () {
                    if (btn) { btn.disabled = false; btn.innerHTML = original; }
                    toast('Сеть не ответила — попробуйте ещё раз', false);
                });
        });
    });

    /* Ползунки параметров: живое значение + отправка на пересборку */
    var box = document.querySelector('[data-nj643-params]');

    if (box) {
        var code = box.getAttribute('data-code') || '';

        // Ползунок и поле с числом — два вида одного размера: ползунком ищут
        // «на глаз», числом задают ровно 120 мм. Держим их синхронными, источник
        // правды для отправки — ползунок (он же name="params[…]").
        box.querySelectorAll('[data-nj643-range]').forEach(function (range) {
            var name = range.getAttribute('data-nj643-range');
            var num  = box.querySelector('[data-nj643-num="' + name + '"]');

            function fromRange() {
                if (num) num.value = String(range.value);
            }

            function fromNum() {
                if (!num) return;

                var v = parseFloat(String(num.value).replace(',', '.'));

                if (isNaN(v)) { fromRange(); return; }

                var lo = parseFloat(range.getAttribute('min'));
                var hi = parseFloat(range.getAttribute('max'));

                if (!isNaN(lo) && v < lo) v = lo;
                if (!isNaN(hi) && v > hi) v = hi;

                range.value = String(v);
            }

            range.addEventListener('input', fromRange);
            fromRange();

            if (num) {
                num.addEventListener('input', fromNum);
                num.addEventListener('change', function () { fromNum(); fromRange(); });
            }
        });

        // «Вернуть размеры ИИ»: значения из схемы (data-default) — те, что модель
        // написала сама, без наших ползунков.
        var reset = box.querySelector('[data-nj643-reset]');

        if (reset) {
            reset.addEventListener('click', function () {
                box.querySelectorAll('[data-nj643-range]').forEach(function (range) {
                    var def = range.getAttribute('data-default');

                    if (def === null || def === '') return;

                    range.value = def;
                    range.dispatchEvent(new Event('input', { bubbles: true }));
                });

                toast('Вернул размеры, которые написал ИИ', true);
            });
        }

        var form = box.querySelector('[data-nj643-rebuild]');

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var params = {};

                form.querySelectorAll('[data-nj643-range]').forEach(function (r) {
                    params[r.getAttribute('data-nj643-range')] = parseFloat(r.value);
                });

                var btn = form.querySelector('button[type="submit"]');
                var original = btn ? btn.innerHTML : '';

                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>Пересобираю…';
                }

                post('/cad/api/run', { csrf: CSRF, code: code, action: 'rebuild', params: params })
                    .then(function (res) {
                        if (btn) { btn.disabled = false; btn.innerHTML = original; }

                        if (res.error) { toast(res.error, false); return; }

                        toast('Пересобираю с новыми размерами', true);

                        var host = document.querySelector('[data-nj643-live-host]');

                        if (host && window.N2JLive) {
                            var prev = host.querySelector('[data-n2j-live]');
                            if (prev) prev.remove();
                            window.N2JLive.mount(host, code);
                        }
                    })
                    .catch(function () {
                        if (btn) { btn.disabled = false; btn.innerHTML = original; }
                        toast('Сеть не ответила — попробуйте ещё раз', false);
                    });
            });
        }
    }

    /* Материал и масса: объём × плотность. Выбор помнит браузер. */
    var massBox = document.querySelector('[data-nj643-mass]');

    if (massBox) {
        var vol = parseFloat(massBox.getAttribute('data-volume')) || 0;
        var matSel = massBox.querySelector('[data-nj643-material]');
        var massOut = massBox.querySelector('[data-nj643-mass-out]');

        function weight(grams) {
            if (grams >= 1000) return (grams / 1000).toFixed(2).replace('.', ',') + ' кг';
            return grams.toFixed(grams >= 100 ? 0 : 1).replace('.', ',') + ' г';
        }

        function showMass() {
            var d = matSel ? parseFloat(matSel.value) : 0;

            if (!massOut || !d || !vol) return;

            massOut.textContent = weight(vol * d / 1000);
        }

        try {
            var savedMat = localStorage.getItem('n2j:cad:material');

            if (savedMat && matSel) {
                for (var i = 0; i < matSel.options.length; i++) {
                    if (matSel.options[i].value === savedMat) { matSel.value = savedMat; break; }
                }
            }
        } catch (e) {}

        if (matSel) {
            matSel.addEventListener('change', function () {
                try { localStorage.setItem('n2j:cad:material', matSel.value); } catch (e) {}
                showMass();
            });
        }

        showMass();
    }

    /* «Печать» — карточка детали для цеха (печатный блок в part.php) */
    document.querySelectorAll('[data-nj643-print]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.print();
        });
    });

    /* Черновик описания: перезагрузка страницы не должна стирать написанное */
    var promptBox = document.getElementById('nj643-prompt');

    if (promptBox) {
        var draftKey = 'n2j:cad:draft';

        try {
            var draft = localStorage.getItem(draftKey);

            if (draft && !promptBox.value) promptBox.value = draft;
        } catch (e) {}

        var draftTimer = null;

        promptBox.addEventListener('input', function () {
            clearTimeout(draftTimer);
            draftTimer = setTimeout(function () {
                try {
                    if (promptBox.value) localStorage.setItem(draftKey, promptBox.value);
                    else localStorage.removeItem(draftKey);
                } catch (e) {}
            }, 400);
        });

        var promptForm = promptBox.closest('form');

        if (promptForm) {
            promptForm.addEventListener('submit', function () {
                try { localStorage.removeItem(draftKey); } catch (e) {}
            });
        }
    }

    /* Сравнение ревизий: построчный diff кода модели без ИИ.
       Длинные куски совпадений сворачиваем — иначе diff на 300 строк читать
       невозможно, а изменившихся строк обычно единицы. */
    var diffBox = document.querySelector('[data-nj643-diff]');

    if (diffBox) {
        var srcA = document.getElementById('nj643-diff-a');
        var srcB = document.getElementById('nj643-diff-b');
        var out  = diffBox.querySelector('[data-nj643-diff-out]');

        function diffLines(a, b) {
            var n = a.length, m = b.length;

            // Страховка от огромного кода: сравнение по строкам — квадрат.
            if (n * m > 4000000) return null;

            var dp = [], i, j;

            for (i = 0; i <= n; i++) dp.push(new Int32Array(m + 1));

            for (i = n - 1; i >= 0; i--) {
                for (j = m - 1; j >= 0; j--) {
                    dp[i][j] = a[i] === b[j]
                        ? dp[i + 1][j + 1] + 1
                        : Math.max(dp[i + 1][j], dp[i][j + 1]);
                }
            }

            var res = [];
            i = 0; j = 0;

            while (i < n && j < m) {
                if (a[i] === b[j]) { res.push(['=', a[i]]); i++; j++; }
                else if (dp[i + 1][j] >= dp[i][j + 1]) { res.push(['-', a[i]]); i++; }
                else { res.push(['+', b[j]]); j++; }
            }

            while (i < n) { res.push(['-', a[i]]); i++; }
            while (j < m) { res.push(['+', b[j]]); j++; }

            return res;
        }

        function esc(text) {
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        if (srcA && srcB && out) {
            var linesA = String(srcA.textContent || '').replace(/\s+$/, '').split('\n');
            var linesB = String(srcB.textContent || '').replace(/\s+$/, '').split('\n');
            var diff = diffLines(linesA, linesB);

            if (!diff) {
                out.textContent = 'Код модели большой — покажу только то, что он изменился. Сравните версии в ревизиях.';
            } else {
                var plus = 0, minus = 0, html = '', i2, run;

                for (i2 = 0; i2 < diff.length; i2++) {
                    if (diff[i2][0] === '+') plus++;
                    if (diff[i2][0] === '-') minus++;
                }

                for (i2 = 0; i2 < diff.length; i2++) {
                    // Сворачиваем длинные совпадения: первые 2 и последние 2 строки
                    if (diff[i2][0] === '=') {
                        run = 0;

                        while (i2 + run < diff.length && diff[i2 + run][0] === '=') run++;

                        if (run > 8) {
                            for (var k = 0; k < 2; k++) {
                                html += '<div class="whitespace-pre-wrap text-gray-500"><span class="text-gray-600">  </span>' + esc(diff[i2 + k][1]) + '</div>';
                            }

                            html += '<div class="my-0.5 whitespace-pre-wrap text-gray-500">… ' + (run - 4) + ' строк без изменений</div>';

                            for (var k2 = run - 2; k2 < run; k2++) {
                                html += '<div class="whitespace-pre-wrap text-gray-500"><span class="text-gray-600">  </span>' + esc(diff[i2 + k2][1]) + '</div>';
                            }

                            i2 += run - 1;
                            continue;
                        }

                        for (var k3 = 0; k3 < run; k3++) {
                            html += '<div class="whitespace-pre-wrap text-gray-500"><span class="text-gray-600">  </span>' + esc(diff[i2 + k3][1]) + '</div>';
                        }

                        i2 += run - 1;
                        continue;
                    }

                    if (diff[i2][0] === '-') {
                        html += '<div class="whitespace-pre-wrap bg-rose-950/60 text-rose-200">− ' + esc(diff[i2][1]) + '</div>';
                    } else {
                        html += '<div class="whitespace-pre-wrap bg-emerald-950/60 text-emerald-200">+ ' + esc(diff[i2][1]) + '</div>';
                    }
                }

                out.innerHTML = '<div class="mb-1.5 whitespace-pre-wrap text-gray-400">Изменено строк: +' + plus + ' / −' + minus + '</div>' + html;
            }
        }
    }

    /* Копирование ссылки на деталь и кода модели */
    document.querySelectorAll('[data-nj643-copy]').forEach(function (el) {
        el.addEventListener('click', function () {
            var text = location.origin + (el.getAttribute('data-nj643-copy') || '');

            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () { toast('Ссылка скопирована', true); },
                    function () { toast(text, true); });
            } else {
                toast(text, true);
            }
        });
    });

    document.querySelectorAll('[data-nj643-copy-text]').forEach(function (el) {
        el.addEventListener('click', function () {
            var src = document.getElementById(el.getAttribute('data-nj643-copy-text'));

            if (!src) return;

            if (navigator.clipboard) {
                navigator.clipboard.writeText(src.textContent || '').then(function () { toast('Код скопирован', true); },
                    function () { toast('Не получилось скопировать', false); });
            }
        });
    });

    /* «Строк:» — память браузера по пути страницы (n2j:per:/cad) */
    try {
        var sel = document.querySelector('[data-nj643-per]');
        var key = 'n2j:per:/cad';
        var saved = localStorage.getItem(key);

        if (sel) {
            if (saved && [12, 24, 48].indexOf(parseInt(saved, 10)) !== -1 && String(sel.value) !== String(saved)) {
                sel.value = saved;
                var form = sel.closest('form');
                if (form) form.submit();
            }

            sel.addEventListener('change', function () {
                try { localStorage.setItem(key, sel.value); } catch (e) {}
            });
        }
    } catch (e) {}

})();
</script>
