<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Скрипт «Лаборатории LLM» (nj645_llmlab).
 *
 * Без внешних библиотек: страница должна работать и при отключённых CDN.
 * Все запросы — к /llmlab/api/*, все ответы — JSON.
 */
?>
<script>
(function () {
    'use strict';

    var LIMITS = <?php echo json_encode([
        'text'    => (int) $limits['text_max'],
        'corpus'  => (int) $limits['corpus_max'],
        'prompt'  => (int) $limits['prompt_max'],
        'task'    => (int) $limits['task_max'],
    ], JSON_UNESCAPED_UNICODE); ?>;

    function $(sel, root) {
        return (root || document).querySelector(sel);
    }

    function el(tag, cls, html) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (html !== undefined) { node.innerHTML = html; }
        return node;
    }

    function api(action, data, method) {
        var opts = {
            method: method || 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        };
        if ((method || 'POST') === 'POST') {
            opts.body = JSON.stringify(data || {});
        }
        return fetch('/llmlab/api/' + action, opts).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Сервер ответил не JSON (код ' + r.status + ')' }; });
        });
    }

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = text === null || text === undefined ? '' : String(text);
        return d.innerHTML;
    }

    function num(n) {
        return Number(n || 0).toLocaleString('ru-RU');
    }

    // ------------------------------------------------------------------ вкладки
    var tabs = document.querySelectorAll('#nj645-tabs [data-tab]');
    var panels = document.querySelectorAll('[data-panel]');

    function activate(name) {
        Array.prototype.forEach.call(tabs, function (tab) {
            var on = tab.getAttribute('data-tab') === name;
            tab.classList.toggle('is-on', on);
        });
        Array.prototype.forEach.call(panels, function (panel) {
            panel.classList.toggle('hidden', panel.getAttribute('data-panel') !== name);
        });
        if (name === 'knowledge') { loadQuiz(); }
    }

    Array.prototype.forEach.call(tabs, function (tab) {
        tab.addEventListener('click', function () { activate(tab.getAttribute('data-tab')); });
    });

    // ------------------------------------------------------------------- ТОКЕНЫ
    var tokInput = $('#nj645-tok-text');
    var tokTimer = null;

    function renderTokens(data) {
        var chips = $('#nj645-tok-chips');
        chips.innerHTML = '';

        (data.tokens || []).forEach(function (token) {
            var cls = 'rounded px-1.5 py-0.5 font-mono text-xs border ';
            if (token.kind === 'space') {
                cls += 'border-transparent bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500';
            } else if (token.kind === 'word') {
                cls += 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200';
            } else if (token.kind === 'piece') {
                cls += 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-200';
            } else if (token.kind === 'punct') {
                cls += 'border-gray-200 bg-white text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400';
            } else {
                cls += 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-900/30 dark:text-rose-200';
            }
            chips.appendChild(el('span', cls, esc(token.t)));
        });

        if (data.truncated) {
            chips.appendChild(el('span', 'px-1 text-xs text-gray-400', '… и ещё ' + (data.count - 400) + ' токенов'));
        }

        $('#nj645-tok-count').textContent = data.count + ' токенов мини-BPE';
        $('#nj645-tok-ratio').textContent = 'оценка для реальных моделей: ~' + data.estimate.total + ' токенов';
        $('#nj645-tok-vocab').textContent = 'словарь: ' + num(data.vocab.words) + ' слов, ' + num(data.vocab.pieces) + ' частей';

        var names = { cyr: 'кириллица', lat: 'латиница', digit: 'цифры', punct: 'пунктуация', space: 'пробелы', other: 'прочее' };
        var parts = $('#nj645-tok-parts');
        parts.innerHTML = '';

        (data.estimate.parts || []).forEach(function (part) {
            parts.appendChild(el('div', 'flex items-center justify-between gap-3',
                '<span class="text-gray-600 dark:text-gray-300">' + names[part.kind] + '</span>' +
                '<span class="text-xs text-gray-400">' + num(part.chars) + ' зн. → ' + num(part.tokens) + ' тк.</span>'));
        });

        var costs = $('#nj645-tok-costs');
        costs.innerHTML = '';

        (data.costs || []).forEach(function (cost) {
            var line = el('div', 'flex items-start justify-between gap-3');
            line.innerHTML =
                '<span class="text-gray-600 dark:text-gray-300">' + esc(cost.title) + '</span>' +
                '<span class="shrink-0 text-right"><span class="font-semibold text-gray-900 dark:text-white">' +
                (cost.own ? '0 ₽' : cost.total.toLocaleString('ru-RU') + ' ₽') + '</span>' +
                '<span class="block text-xs ' + (cost.fits ? 'text-gray-400' : 'text-rose-500') + '">' +
                (cost.fits ? 'окно ' + num(cost.context) + ' тк.' : 'не поместится в окно') + '</span></span>';
            costs.appendChild(line);
        });
    }

    function runTokenize() {
        var text = tokInput.value.slice(0, LIMITS.text);
        if (!text.trim()) { return; }
        api('tokenize', { text: text }).then(function (data) {
            if (data.ok) { renderTokens(data); }
        });
    }

    tokInput.addEventListener('input', function () {
        clearTimeout(tokTimer);
        tokTimer = setTimeout(runTokenize, 250);
    });

    // ---------------------------------------------------------- СЛЕДУЮЩИЙ ТОКЕН
    var temp = $('#nj645-temp'), topk = $('#nj645-topk'), topp = $('#nj645-topp');

    function syncSliders() {
        $('#nj645-temp-val').textContent = Number(temp.value).toFixed(2);
        $('#nj645-topk-val').textContent = Number(topk.value) === 0 ? '0 (все)' : topk.value;
        $('#nj645-topp-val').textContent = Number(topp.value).toFixed(2);
    }

    [temp, topk, topp].forEach(function (input) {
        input.addEventListener('input', syncSliders);
    });

    function renderBars(data) {
        var bars = $('#nj645-bars');
        bars.innerHTML = '';

        if (!data.items || !data.items.length) {
            bars.appendChild(el('p', 'text-sm text-gray-500', 'Нет данных по этому контексту.'));
            return;
        }

        // Вероятности — числами, без полос: полосы убраны везде.
        data.items.forEach(function (item, index) {
            var row = el('div', 'flex items-center gap-2');
            row.innerHTML =
                '<span class="w-32 shrink-0 truncate text-right font-mono text-xs text-gray-700 dark:text-gray-200" title="' + esc(item.token) + '">' + esc(item.token) + '</span>' +
                '<span class="flex-1 text-right font-mono text-xs ' + (index === 0 ? 'font-bold text-blue-600 dark:text-blue-400' : 'text-gray-500 dark:text-gray-400') + '">' + (100 * item.prob).toFixed(1) + ' %</span>';
            bars.appendChild(row);
        });

        $('#nj645-sample-meta').textContent =
            'кандидатов: ' + data.candidates + ' · энтропия: ' + data.entropy.toFixed(2) + ' бит';
    }

    function renderSample(data) {
        var out = $('#nj645-sample-out');
        out.classList.remove('hidden');

        if (!data.ok) {
            out.className = 'n2j-flash n2j-flash-warn block mt-4';
            out.textContent = data.error || 'Не получилось';
            return;
        }

        out.className = 'n2j-flash n2j-flash-ok block mt-4';
        out.innerHTML = 'Модель продолжила: «' + esc(data.context) + ' <strong>' + esc(data.sampled) + '</strong>»' +
            ' <span class="text-xs opacity-75">(вероятность ' + (100 * data.sampled_prob).toFixed(1) + ' %' +
            (data.greedy ? ', жадный выбор' : '') + ')</span>';
        renderBars(data);
    }

    $('#nj645-sample').addEventListener('click', function () {
        api('sample', {
            context: $('#nj645-ctx').value,
            temperature: Number(temp.value),
            top_k: Number(topk.value),
            top_p: Number(topp.value)
        }).then(renderSample);
    });

    // «Угадайка»
    $('#nj645-guess-btn').addEventListener('click', function () {
        api('guess', { context: $('#nj645-ctx').value, guess: $('#nj645-guess').value }).then(function (data) {
            var out = $('#nj645-guess-out');
            out.classList.remove('hidden');

            if (!data.ok) {
                out.className = 'mt-3 text-sm text-amber-700 dark:text-amber-300';
                out.textContent = data.error || 'Не получилось';
                return;
            }

            out.className = 'mt-3 rounded-lg px-4 py-3 text-sm ' + (data.correct
                ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-200'
                : 'bg-rose-50 text-rose-900 dark:bg-rose-900/20 dark:text-rose-200');
            out.innerHTML = (data.correct ? '<strong>Совпало!</strong> ' : '<strong>Не угадали.</strong> ') +
                'Модель выбрала <strong>' + esc(data.model_token) + '</strong> (вероятность ' + (100 * data.model_prob).toFixed(1) + ' %).' +
                '<span class="mt-1 block text-xs opacity-75">Другие кандидаты: ' +
                (data.alternatives || []).slice(0, 4).map(function (a) { return esc(a.token) + ' (' + (100 * a.prob).toFixed(1) + ' %)'; }).join(', ') + '</span>';

            $('#nj645-guess-total').textContent = num(data.stats.total);
            $('#nj645-guess-hit').textContent = data.stats.accuracy === null ? '—' : data.stats.accuracy + ' %';
            $('#nj645-guess-ctx').textContent = num(data.stats.contexts);
        });
    });

    // Корпус
    $('#nj645-corpus-btn').addEventListener('click', function () {
        var out = $('#nj645-corpus-out');
        out.textContent = 'Отправляем…';
        api('corpus', {
            text: $('#nj645-corpus-text').value,
            title: $('#nj645-corpus-title').value,
            kind: $('#nj645-corpus-kind').value,
            consent: $('#nj645-corpus-consent').checked
        }).then(function (data) {
            out.textContent = data.ok ? (data.message || 'Готово') : (data.error || 'Ошибка');
            if (data.ok) {
                $('#nj645-corpus-text').value = '';
                $('#nj645-corpus-consent').checked = false;
            }
        });
    });

    // ------------------------------------------------------------------ СЕМАНТИКА
    $('#nj645-sem-btn').addEventListener('click', function () {
        var out = $('#nj645-sem-out');
        out.classList.remove('hidden');
        out.innerHTML = '<p class="text-sm text-gray-500">Считаем векторы…</p>';

        api('similarity', { a: $('#nj645-sem-a').value, b: $('#nj645-sem-b').value }).then(function (data) {
            if (!data.ok) {
                out.innerHTML = '<p class="text-sm text-rose-600">' + esc(data.error || 'Ошибка') + '</p>';
                return;
            }

            var toneStyles = {
                good: 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/50 dark:bg-emerald-900/20',
                warn: 'border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-900/20',
                info: 'border-blue-200 bg-blue-50 dark:border-blue-900/50 dark:bg-blue-900/20'
            };
            var tone = toneStyles[data.label.tone] || toneStyles.info;
            var bars = function (spark, cls) {
                return spark.map(function (v) {
                    return '<span class="inline-block w-1 rounded-sm ' + cls + '" style="height:' + Math.max(2, Math.round(60 * Math.min(1, v * 6))) + 'px"></span>';
                }).join('');
            };

            out.innerHTML =
                '<div class="rounded-lg border p-4 ' + tone + '">' +
                '<div class="text-2xl font-bold text-gray-900 dark:text-white">' + data.cosine.toFixed(3) + '</div>' +
                '<div class="text-sm font-semibold text-gray-700 dark:text-gray-200">' + esc(data.label.title) + '</div>' +
                '<div class="mt-1 text-xs text-gray-500 dark:text-gray-400">источник векторов: ' + (data.source === 'embedding' ? 'сервер эмбеддингов платформы' : 'локальный хеш (сервер эмбеддингов не подключён)') + '</div>' +
                '<div class="mt-2 flex items-end gap-0.5">' + bars(data.spark_a, 'bg-blue-400') + '</div>' +
                '<div class="mt-1 flex items-end gap-0.5">' + bars(data.spark_b, 'bg-emerald-400') + '</div>' +
                '<div class="mt-1 text-xs text-gray-400">Подписи: сверху вектор текста A, снизу — B. Косинус 1 — направления совпали.</div>' +
                '</div>';
        });
    });

    function renderMap(points, total) {
        var box = $('#nj645-map-points');
        box.innerHTML = '';

        if (!points || !points.length) {
            box.innerHTML = '<p class="p-4 text-sm text-gray-500">Карта пуста — добавьте первый текст.</p>';
            return;
        }

        var xs = points.map(function (p) { return p.x; });
        var ys = points.map(function (p) { return p.y; });
        var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
        var minY = Math.min.apply(null, ys), maxY = Math.max.apply(null, ys);
        var spanX = (maxX - minX) || 1, spanY = (maxY - minY) || 1;

        points.forEach(function (point) {
            var left = 5 + 90 * (point.x - minX) / spanX;
            var bottom = 5 + 85 * (point.y - minY) / spanY;
            var dot = el('span', 'absolute h-3 w-3 -translate-x-1/2 translate-y-1/2 rounded-full cursor-help ' +
                (point.source === 'embedding' ? 'bg-amber-500' : 'bg-blue-500'));
            dot.style.left = left + '%';
            dot.style.bottom = bottom + '%';
            dot.title = point.label;
            box.appendChild(dot);
        });

        $('#nj645-map-out').textContent = 'Точек на карте: ' + num(points.length) + ' из ' + num(total) + '. Наведите на точку — увидите подпись.';
    }

    function loadMap() {
        api('vector_map', {}, 'POST').then(function (data) {
            if (data.ok) { renderMap(data.points, data.total); }
        });
    }

    $('#nj645-map-btn').addEventListener('click', function () {
        var text = $('#nj645-map-text').value;
        if (!text.trim()) { return; }
        var out = $('#nj645-map-out');
        out.textContent = 'Считаем вектор…';

        api('vector_add', { text: text, label: $('#nj645-map-label').value }).then(function (data) {
            if (!data.ok) { out.textContent = data.error || 'Ошибка'; return; }

            out.innerHTML = 'Добавлено (' + data.dims + ' измерений, источник: ' +
                (data.source === 'embedding' ? 'сервер эмбеддингов' : 'локальный хеш') + '). Всего точек: ' + num(data.total) + '.';

            if (data.neighbors && data.neighbors.length) {
                out.innerHTML += '<span class="mt-1 block">Ближайшие: ' + data.neighbors.slice(0, 4).map(function (n) {
                    return esc(n.label || n.text) + ' — ' + n.similarity.toFixed(2);
                }).join(', ') + '</span>';
            }

            $('#nj645-map-text').value = '';
            $('#nj645-map-label').value = '';
            loadMap();
        });
    });

    // -------------------------------------------------------------------- ПРОМПТЫ
    function renderReport(report) {
        var box = $('#nj645-prompt-report');
        var scoreStyles = [
            [85, 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/50 dark:bg-emerald-900/20'],
            [65, 'border-blue-200 bg-blue-50 dark:border-blue-900/50 dark:bg-blue-900/20'],
            [40, 'border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-900/20'],
            [0,  'border-rose-200 bg-rose-50 dark:border-rose-900/50 dark:bg-rose-900/20']
        ];
        var scoreClass = scoreStyles[scoreStyles.length - 1][1];
        for (var si = 0; si < scoreStyles.length; si++) {
            if (report.score >= scoreStyles[si][0]) { scoreClass = scoreStyles[si][1]; break; }
        }

        var checks = [
            ['role', 'Роль'], ['task', 'Задача'], ['context', 'Контекст'], ['format', 'Формат'],
            ['constraints', 'Ограничения'], ['examples', 'Примеры'], ['audience', 'Аудитория'],
            ['tone', 'Тон'], ['unknown', 'Правило «нет данных»'], ['steps', 'Пошаговость']
        ];

        var chips = checks.map(function (check) {
            var on = report.checks[check[0]];
            return '<span class="rounded-full px-2.5 py-1 text-xs font-semibold ' +
                (on ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                    : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400') + '">' +
                (on ? '✓ ' : '— ') + check[1] + '</span>';
        }).join('');

        var findings = (report.findings || []).map(function (f) {
            var icon = f.level === 'good' ? 'fa-circle-check text-emerald-500'
                : (f.level === 'warn' ? 'fa-triangle-exclamation text-amber-500' : 'fa-lightbulb text-blue-500');
            return '<li class="flex items-start gap-2 rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-700">' +
                '<i class="fas ' + icon + ' mt-0.5 text-xs"></i>' +
                '<span><strong class="text-sm text-gray-800 dark:text-gray-100">' + esc(f.title) + '</strong>' +
                '<span class="block text-xs text-gray-500 dark:text-gray-400">' + esc(f.text) + '</span></span></li>';
        }).join('');

        box.innerHTML =
            '<div class="flex flex-wrap items-center gap-4 rounded-lg border p-4 ' + scoreClass + '">' +
            '<div class="text-3xl font-bold text-gray-900 dark:text-white">' + report.score + '</div>' +
            '<div><div class="text-sm font-semibold text-gray-800 dark:text-gray-100">промпт ' + esc(report.grade) + '</div>' +
            '<div class="text-xs text-gray-500 dark:text-gray-400">' + num(report.chars) + ' знаков · ~' + num(report.tokens) + ' токенов · не хватает пунктов: ' + report.missing + '</div></div>' +
            '</div>' +
            '<div class="mt-3 flex flex-wrap gap-1.5">' + chips + '</div>' +
            '<ul class="mt-3 space-y-2">' + findings + '</ul>';
    }

    $('#nj645-prompt-btn').addEventListener('click', function () {
        api('prompt_check', { text: $('#nj645-prompt-text').value }).then(function (data) {
            if (data.ok) { renderReport(data.report); }
        });
    });

    $('#nj645-prompt-save').addEventListener('click', function () {
        var text = $('#nj645-prompt-text').value;
        var title = prompt('Название промпта:', text.slice(0, 60));
        if (!title) { return; }

        api('prompt_add', { title: title, body: text, goal: 'другое' }).then(function (data) {
            $('#nj645-prompt-out').textContent = data.ok ? (data.message || 'Сохранено') : (data.error || 'Ошибка');
            if (data.ok && data.status === 'published') {
                setTimeout(function () { window.location.reload(); }, 900);
            }
        });
    });

    function renderRun(runs, done) {
        var box = $('#nj645-run-results');
        box.innerHTML = '';

        runs.forEach(function (run) {
            var card = el('div', 'rounded-lg border border-gray-200 p-3 dark:border-gray-700');
            var statusBadge = run.status === 'done' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                : (run.status === 'error' ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200'
                    : 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200');

            var body = '';
            if (run.answer) {
                body = '<pre class="mt-2 whitespace-pre-wrap break-words font-sans text-sm text-gray-700 dark:text-gray-200">' + esc(run.answer) + '</pre>';
            } else if (run.error) {
                body = '<p class="mt-2 text-sm text-rose-600 dark:text-rose-400">' + esc(run.error) + '</p>';
            } else {
                body = '<p class="mt-2 text-sm text-gray-500">' + (run.stage ? esc(run.stage) : 'Ждём ответ модели…') + '</p>';
            }

            card.innerHTML =
                '<div class="flex flex-wrap items-center justify-between gap-2">' +
                '<span class="text-sm font-semibold text-gray-900 dark:text-white">' + esc(run.variant) + '</span>' +
                '<span class="rounded-full px-2 py-0.5 text-xs font-semibold ' + statusBadge + '">' + esc(run.status) + '</span></div>' +
                '<div class="text-xs text-gray-400">' + esc(run.model || 'модель по умолчанию') + ' · температура ' + run.temperature + (run.elapsed_ms ? ' · ' + (run.elapsed_ms / 1000).toFixed(1) + ' с' : '') + '</div>' + body;
            box.appendChild(card);
        });

        if (!done) { return; }
    }

    var pollTimer = null;

    function pollSeries(series) {
        clearInterval(pollTimer);
        pollTimer = setInterval(function () {
            api('run_status', { series: series }).then(function (data) {
                if (!data.ok) { return; }
                renderRun(data.runs, data.done);
                if (data.done) { clearInterval(pollTimer); }
            });
        }, 2500);
    }

    $('#nj645-run-btn').addEventListener('click', function () {
        var out = $('#nj645-run-out');
        out.textContent = 'Ставим в очередь…';

        api('run', {
            task: $('#nj645-run-task').value,
            prompt_a: $('#nj645-run-a').value,
            prompt_b: $('#nj645-run-b').value
        }).then(function (data) {
            if (!data.ok) { out.textContent = data.error || 'Ошибка'; return; }
            out.textContent = data.message || 'В очереди';
            pollSeries(data.series);
        });
    });

    document.addEventListener('click', function (event) {
        var vote = event.target.closest('.nj645-vote');
        if (vote) {
            var card = vote.closest('[data-prompt]');
            api('prompt_vote', { id: parseInt(card.getAttribute('data-prompt'), 10), value: parseInt(vote.getAttribute('data-value'), 10) })
                .then(function (data) {
                    if (data.ok) {
                        var up = card.querySelector('.nj645-up'), down = card.querySelector('.nj645-down');
                        if (vote.getAttribute('data-value') === '1') { up.textContent = parseInt(up.textContent, 10) + 1; }
                        else { down.textContent = parseInt(down.textContent, 10) + 1; }
                    }
                });
            return;
        }

        var use = event.target.closest('.nj645-useprompt');
        if (use) {
            var body = use.closest('[data-prompt]').querySelector('.nj645-body').textContent;
            $('#nj645-run-a').value = body;
            $('#nj645-prompt-text').value = body;
            activate('prompts');
            $('#nj645-run-out').textContent = 'Промпт подставлен в вариант A — добавьте задачу и отправьте.';
        }
    });

    // ---------------------------------------------------------------------- КВИЗ
    var quizLoaded = false;
    var quizState = { index: 0, questions: [], right: 0 };

    function loadQuiz() {
        if (quizLoaded) { return; }
        quizLoaded = true;

        api('quiz', { n: 5 }).then(function (data) {
            if (!data.ok || !data.questions.length) { return; }
            quizState.questions = data.questions;
            quizState.index = 0;
            quizState.right = 0;
            renderQuestion();
        });
    }

    function renderQuestion() {
        var box = $('#nj645-quiz');
        box.innerHTML = '';

        var q = quizState.questions[quizState.index];

        if (!q) {
            $('#nj645-quiz-score').textContent = 'Результат: ' + quizState.right + ' из ' + quizState.questions.length;
            box.innerHTML = '<div class="n2j-flash n2j-flash-ok block">' +
                'Квиз пройден: ' + quizState.right + ' из ' + quizState.questions.length + '. Ответы уже в базе — они показывают, какие темы стоит объяснить лучше.</div>' +
                '<button type="button" id="nj645-quiz-again" class="mt-3 rounded-lg border border-emerald-300 px-3 py-2 text-sm font-semibold text-emerald-800 dark:border-emerald-700 dark:text-emerald-200">Ещё пять</button>';
            var again = $('#nj645-quiz-again');
            if (again) {
                again.addEventListener('click', function () { quizLoaded = false; quizState.questions = []; loadQuiz(); });
            }
            return;
        }

        $('#nj645-quiz-score').textContent = 'Вопрос ' + (quizState.index + 1) + ' из ' + quizState.questions.length;

        var head = el('div', 'rounded-lg border border-gray-200 p-4 dark:border-gray-700');
        head.appendChild(el('div', 'text-sm text-gray-500 dark:text-gray-400',
            'Тема: ' + esc(q.topic) + ' · сложность ' + q.difficulty + '/3'));
        head.appendChild(el('div', 'mt-1 text-base font-semibold text-gray-900 dark:text-white', esc(q.term)));

        var list = el('div', 'mt-3 grid gap-2');
        q.options.forEach(function (option) {
            var button = el('button', 'rounded-lg border border-gray-200 px-3 py-2 text-left text-sm text-gray-700 transition hover:border-blue-400 hover:bg-blue-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-blue-900/20');
            button.type = 'button';
            button.textContent = option.short;
            button.addEventListener('click', function () {
                answerQuestion(q.term_id, option.id, head);
            });
            list.appendChild(button);
        });

        head.appendChild(list);
        box.appendChild(head);
    }

    function answerQuestion(termId, chosenId, holder) {
        api('quiz_answer', { term_id: termId, chosen_id: chosenId }).then(function (data) {
            if (!data.ok) { return; }

            if (data.correct) { quizState.right++; }

            var feedback = el('div', 'mt-3 rounded-lg px-3 py-2 text-sm ' + (data.correct
                ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-200'
                : 'bg-rose-50 text-rose-900 dark:bg-rose-900/20 dark:text-rose-200'));
            feedback.innerHTML = (data.correct ? '<strong>Верно.</strong> ' : '<strong>Мимо.</strong> ') +
                '«' + esc(data.term) + '» — ' + esc(data.short) +
                (data.body ? '<span class="mt-1 block text-xs opacity-80">' + esc(data.body) + '</span>' : '');
            holder.appendChild(feedback);

            Array.prototype.forEach.call(holder.querySelectorAll('button'), function (b) { b.disabled = true; });

            var next = el('button', 'mt-3 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700',
                quizState.index + 1 < quizState.questions.length ? 'Следующий вопрос' : 'Показать результат');
            next.type = 'button';
            next.addEventListener('click', function () {
                quizState.index++;
                renderQuestion();
            });
            holder.appendChild(next);
        });
    }

    // -------------------------------------------------------------------- старт
    syncSliders();
    activate('tokens');
    runTokenize();
    loadMap();
})();
</script>
