<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/**
 * База знаний (/label/knowledge): список слева, карточка справа.
 *
 * Владелец (03.10.2026): «база знаний — своя страница», «список слева,
 * подробности справа» и «похожие вещества из категории». Страница только про
 * справочник: ни разбора, ни профиля — то, что раньше жило вкладкой на /label.
 */
(function () {
    'use strict';

    var N = window.N646;
    var RISK_DOT = ['bg-emerald-500', 'bg-gray-400', 'bg-amber-500', 'bg-rose-400', 'bg-rose-600'];

    var bookLoaded = false;
    // Карточку уже просили открыть (ссылка ?slug=… или «карточка» в разборе):
    // тогда автопоказ первого вещества из списка не должен её перебивать.
    var cardRequested = false;
    // Текущее вещество: список перерисовывается запросом, и подсветку строки
    // надо вернуть — иначе открытая карточка выглядит «ничьей».
    var currentSlug = '';
    var BOOK_ROW_ON = ['border-blue-400', 'bg-blue-50', 'dark:bg-blue-900/30'];

    function riskBadge(item) {
        var risk = Math.max(0, Math.min(4, Number(item.risk) || 0));
        return '<span class="inline-block h-2 w-2 shrink-0 rounded-full ' + RISK_DOT[risk] + '"></span>';
    }

    function markBookRow(slug) {
        currentSlug = slug;
        N.$$('#nj646-book-list button').forEach(function (row) {
            BOOK_ROW_ON.forEach(function (cls) { row.classList.remove(cls); });
        });

        var row = N.$('#nj646-book-list button[data-slug="' + slug + '"]');

        if (row) {
            BOOK_ROW_ON.forEach(function (cls) { row.classList.add(cls); });
        }
    }

    function loadBook(query) {
        bookLoaded = true;

        var box = N.$('#nj646-book-list');
        box.innerHTML = '<p class="text-xs text-gray-400">Загружаю…</p>';

        N.api('search', { q: query || '' }).then(function (data) {
            if (!data.ok) { box.innerHTML = '<p class="text-xs text-gray-400">' + N.esc(data.error) + '</p>'; return; }

            if (!data.items.length) {
                box.innerHTML = '<p class="text-xs text-gray-400">Ничего не нашлось. Вещество можно попросить изучить из разбора или из админки.</p>';
                return;
            }

            box.innerHTML = '';

            data.items.forEach(function (item) {
                var row = document.createElement('button');
                row.type = 'button';
                row.setAttribute('data-slug', item.slug);
                row.className = 'flex w-full items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 text-left transition hover:border-blue-300 dark:border-gray-700';
                row.innerHTML = riskBadge(item) +
                    '<span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-gray-800 dark:text-gray-100">' + N.esc(item.name_ru) + '</span>' +
                    '<span class="block truncate text-xs text-gray-500 dark:text-gray-400">' + N.esc(item.category || '') + (item.e_code ? ' · ' + N.esc(item.e_code) : '') + '</span></span>' +
                    '<span class="text-xs text-gray-400">×' + Number(item.hits || 0) + '</span>';
                row.addEventListener('click', function () { showCard(item.slug); });
                box.appendChild(row);
            });

            // Первое вещество открываем сразу: правая колонка не должна пустовать.
            if (!cardRequested) {
                showCard(data.items[0].slug);
            } else if (currentSlug) {
                markBookRow(currentSlug);
            }
        });
    }

    function showCard(slug) {
        var box = N.$('#nj646-card');

        if (!box) { return; }

        cardRequested = true;

        box.innerHTML = '<div class="n2j-card text-sm text-gray-500 dark:text-gray-300">Загружаю карточку…</div>';
        markBookRow(slug);

        N.api('ingredient', { slug: slug }).then(function (data) {
            if (!data.ok) {
                box.innerHTML = '<div class="n2j-card text-sm text-gray-500">' + N.esc(data.error) + '</div>';
                return;
            }

            var i = data.ingredient;
            var html = '<div class="n2j-card">';
            html += '<div class="flex flex-wrap items-center gap-2">';
            html += '<h1 class="n2j-h1">' + N.esc(i.name_ru) + '</h1>';
            html += riskBadge({ risk: i.risk });
            html += '<span class="text-xs text-gray-400">' + N.esc(i.verdict_label) + (i.e_code ? ' · ' + N.esc(i.e_code) : '') + '</span>';

            if (i.status === 'draft') {
                html += '<span class="rounded-full border border-amber-300 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:border-amber-700 dark:text-amber-300">черновик · ждёт проверки</span>';
            }

            html += '</div>';

            if (i.name_int) { html += '<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">' + N.esc(i.name_int) + '</p>'; }
            if (i.summary) { html += '<p class="mt-3 text-base text-gray-700 dark:text-gray-200">' + N.esc(i.summary) + '</p>'; }
            if (i.details) { html += '<p class="mt-2 text-sm text-gray-600 dark:text-gray-300">' + N.esc(i.details) + '</p>'; }

            if (i.synonyms && i.synonyms.length) {
                html += '<p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Синонимы: ' + i.synonyms.map(N.esc).join(', ') + '</p>';
            }

            if (i.avoid && i.avoid.length) {
                html += '<p class="mt-2 text-xs text-amber-600 dark:text-amber-400">Кому обратить внимание: ' + i.avoid.map(N.esc).join(', ') + '</p>';
            }

            // Похожие из той же категории: карточка была тупиком — человек
            // прочитал про одно вещество и уходил.
            if (data.similar && data.similar.length) {
                html += '<div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-700">';
                html += '<p class="text-xs font-semibold text-gray-500 dark:text-gray-400">Похожие в категории «' + N.esc(i.category) + '»</p>';
                html += '<div class="mt-2 flex flex-wrap gap-2">';

                data.similar.forEach(function (sim) {
                    html += '<button type="button" class="nj646-similar n2j-btn-secondary n2j-btn-sm" data-slug="'
                        + N.esc(sim.slug) + '">' + riskBadge(sim) + N.esc(sim.name_ru) + '</button>';
                });

                html += '</div></div>';
            }

            html += '<div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3 dark:border-gray-700">';
            html += '<span class="text-xs text-gray-400">Встречалось ' + Number(i.hits) + ' раз · источник: '
                + (i.source === 'seed' ? 'конфиг' : (i.source === 'llm' ? 'изучено ИИ' : (i.source === 'csv' ? 'словарь владельца' : 'правка')))
                + (i.status === 'draft' ? ' · не проверено редактором' : '') + '</span>';
            html += '<button type="button" class="nj646-vote n2j-btn-secondary n2j-btn-sm" data-v="1">Согласен</button>';
            html += '<button type="button" class="nj646-vote n2j-btn-secondary n2j-btn-sm" data-v="0">Спорно</button>';
            html += '<span id="nj646-vote-out" class="text-xs text-gray-400"></span>';
            // Постоянный адрес страницы вещества: карточка здесь живёт в query
            // (?slug=…), а послать знакомому нужно нормальную ссылку — и у
            // поисковика должен быть путь к страницам веществ.
            html += '<a class="n2j-btn-secondary n2j-btn-sm ml-auto" href="/label/ingredient/'
                + encodeURIComponent(i.slug) + '">страница вещества →</a>';
            html += '</div></div>';
            box.innerHTML = html;

            // На узком экране колонки складываются: карточка уходит под список,
            // и её надо показать, а не заставить человека её искать.
            if (window.matchMedia('(max-width: 1023px)').matches) {
                box.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            N.$$('.nj646-similar', box).forEach(function (btn) {
                btn.addEventListener('click', function () {
                    showCard(btn.getAttribute('data-slug'));
                    // Ссылка в адресной строке тоже меняется: карточку можно
                    // послать знакомому, а не только показать рядом.
                    try {
                        window.history.replaceState(null, '', '/label/knowledge?slug=' + encodeURIComponent(btn.getAttribute('data-slug')));
                    } catch (e) { /* старая вкладка — не беда */ }
                });
            });

            N.$$('.nj646-vote', box).forEach(function (btn) {
                btn.addEventListener('click', function () {
                    N.api('vote', { ingredient_id: i.id, value: Number(btn.getAttribute('data-v')) ? 1 : -1 })
                        .then(function (res) {
                            N.say('#nj646-vote-out', res.ok
                                ? 'Спасибо, учтено (' + res.votes.agree + ' за / ' + res.votes.dispute + ' против)'
                                : (res.error || 'Не получилось'));
                        });
                });
            });
        });
    }

    N.$('#nj646-search-btn').addEventListener('click', function () { loadBook(N.$('#nj646-search').value); });
    N.$('#nj646-search').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { loadBook(N.$('#nj646-search').value); }
    });

    // Старт: ссылка на вещество (?slug=sugar) открывает его карточку сразу —
    // именно сюда ведут ссылки из разбора.
    (function () {
        var slug = '';

        try {
            slug = new URLSearchParams(window.location.search).get('slug') || '';
        } catch (e) {
            slug = '';
        }

        loadBook('');

        if (slug) {
            showCard(slug);
        }
    })();
})();
</script>
