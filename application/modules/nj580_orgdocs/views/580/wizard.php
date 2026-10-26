<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Мастер-опросник создания пакета (/docs/wizard): 6 вопросов с кнопками
 * (+ своё значение текстом, если ни одна кнопка не подходит) → пакет.
 * Кнопки-чипы работают на инлайн-JS ниже, внешних библиотек нет.
 */

$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <header>
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/docs" class="transition hover:text-blue-600 dark:hover:text-blue-400">Документы организации</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-medium text-gray-700 dark:text-gray-200">Мастер пакета</span>
        </nav>
        <h1 class="n2j-h1 mt-2"><i class="fas fa-list-check mr-2 text-blue-500"></i>Мастер пакета документов</h1>
        <p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">
            6 коротких вопросов — самое важное для качественных документов. Где нет подходящей кнопки, впишите своё в поле.
            В конце ИИ подберёт состав пакета, напишет тексты и соберёт файлы Word + PDF по ГОСТ.
        </p>
    </header>

    <?php if ($error): ?>
    <div class="n2j-flash n2j-flash-warn mb-3 text-xs block">
        <?php echo HTML::chars($error); ?>
    </div>
    <?php endif; ?>

    <?php if ( ! $logged): ?>
    <div data-nj580-autohide class="n2j-flash n2j-flash-info mb-3 text-xs block">
        Сервис для зарегистрированных — <a href="/auth/login?return_url=docs/wizard" class="font-semibold underline">войдите</a>, ответы сохранятся.
    </div>
    <?php endif; ?>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
    <form method="post" action="/docs/wizard" id="wizard-form" class="space-y-4 md:col-span-2">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

        <!-- 0. Организация -->
        <div class="<?php echo $card; ?>">
            <p class="text-base font-bold text-gray-900 sm:text-lg dark:text-white"><span class="mr-2 rounded-md bg-blue-600 px-1.5 py-0.5 font-mono text-xs font-bold text-white">0</span>ОГРН / ОГРНИП / ИНН организации <span class="text-rose-500">*</span></p>
            <p class="n2j-hint">Единственное, что нужно для старта: по номеру подтянем официальные реквизиты, а остальное покажем после проверки.</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <input type="text" name="ogrn" required maxlength="15" inputmode="numeric" autocomplete="off" placeholder="13 / 15 / 10 или 12 цифр"
                       class="n2j-input w-full text-center tracking-[0.15em] sm:w-64">
                <button type="button" id="wiz-ogrn-find" class="n2j-btn-secondary">
                    <i class="fas fa-magnifying-glass mr-1"></i>Найти
                </button>
            </div>
            <div id="wiz-ogrn-result" class="mt-1 text-xs text-gray-500 sm:text-sm dark:text-gray-400">вставьте номер — найдём организацию автоматически</div>
            <div id="wiz-org-card" class="n2j-flash n2j-flash-ok mt-1 hidden text-xs block"></div>
            <div id="wiz-name-wrap" class="mt-2 hidden">
                <label class="n2j-label">Название организации <span class="font-normal text-gray-400">(не нашлось в реестре — введите вручную)</span></label>
                <input type="text" name="org_name" maxlength="300" placeholder="ООО «Ромашка» или ИП Иванов И.И."
                       class="n2j-input mt-1.5 w-full">
            </div>
        </div>

        <div id="wiz-rest" class="hidden space-y-4">

        <!-- 1. Сфера -->
        <div class="<?php echo $card; ?>">
            <p class="text-base font-bold text-gray-900 sm:text-lg dark:text-white"><span class="mr-2 rounded-md bg-blue-600 px-1.5 py-0.5 font-mono text-xs font-bold text-white">1</span>Чем занимается организация?</p>
            <div class="mt-2 flex flex-wrap gap-1.5" data-chips="industry">
                <?php foreach (['розничная торговля', 'услуги', 'производство', 'IT / интернет', 'общепит', 'строительство и ремонт'] as $v): ?>
                <button type="button" class="n2j-chip" data-val="<?php echo HTML::chars($v); ?>"><?php echo HTML::chars($v); ?></button>
                <?php endforeach; ?>
                <button type="button" class="n2j-chip" data-val="__other">другое…</button>
            </div>
            <input type="hidden" name="industry" value="">
            <input type="text" name="industry_other" placeholder="свой вариант — например: ветеринарная клиника"
                   class="n2j-input other-field mt-2 hidden w-full">
        </div>

        <!-- 2. Размер -->
        <div class="<?php echo $card; ?>">
            <p class="text-base font-bold text-gray-900 sm:text-lg dark:text-white"><span class="mr-2 rounded-md bg-blue-600 px-1.5 py-0.5 font-mono text-xs font-bold text-white">2</span>Сколько сотрудников?</p>
            <div class="mt-2 flex flex-wrap gap-1.5" data-chips="size">
                <?php foreach (['только я', '2–5 человек', '6–15 человек', '16–50 человек', 'более 50 человек'] as $v): ?>
                <button type="button" class="n2j-chip" data-val="<?php echo HTML::chars($v); ?>"><?php echo HTML::chars($v); ?></button>
                <?php endforeach; ?>
                <button type="button" class="n2j-chip" data-val="__other">другое…</button>
            </div>
            <input type="hidden" name="size" value="">
            <input type="text" name="size_other" placeholder="свой вариант — например: 70 человек, из них 40 сменных"
                   class="n2j-input other-field mt-2 hidden w-full">
        </div>

        <!-- 3. Структура -->
        <div class="<?php echo $card; ?>">
            <p class="text-base font-bold text-gray-900 sm:text-lg dark:text-white"><span class="mr-2 rounded-md bg-blue-600 px-1.5 py-0.5 font-mono text-xs font-bold text-white">3</span>Есть ли отделы / подразделения?</p>
            <div class="mt-2 flex flex-wrap gap-1.5" data-chips="structure-pick">
                <button type="button" class="n2j-chip" data-val="нет — небольшой коллектив">нет, все в одном коллективе</button>
                <button type="button" class="n2j-chip" data-val="__list">есть — перечислю</button>
            </div>
            <input type="hidden" name="structure" value="">
            <textarea name="structure_text" rows="2" placeholder="администрация; бухгалтерия; отдел продаж; склад…"
                      class="n2j-textarea other-field mt-2 hidden min-h-[44px] w-full"></textarea>
        </div>

        <!-- 4. Режим работы -->
        <div class="<?php echo $card; ?>">
            <p class="text-base font-bold text-gray-900 sm:text-lg dark:text-white"><span class="mr-2 rounded-md bg-blue-600 px-1.5 py-0.5 font-mono text-xs font-bold text-white">4</span>Режим работы сотрудников?</p>
            <div class="mt-2 flex flex-wrap gap-1.5" data-chips="schedule">
                <?php foreach (['Пн–Пт 9:00–18:00', 'сменный график', 'гибкий график', 'частично удалённо'] as $v): ?>
                <button type="button" class="n2j-chip" data-val="<?php echo HTML::chars($v); ?>"><?php echo HTML::chars($v); ?></button>
                <?php endforeach; ?>
                <button type="button" class="n2j-chip" data-val="__other">другое…</button>
            </div>
            <input type="hidden" name="schedule" value="">
            <input type="text" name="schedule_other" placeholder="свой вариант — например: 2/2 по 12 часов"
                   class="n2j-input other-field mt-2 hidden w-full">
        </div>

        <!-- 5. Нюансы (несколько) -->
        <div class="<?php echo $card; ?>">
            <p class="text-base font-bold text-gray-900 sm:text-lg dark:text-white"><span class="mr-2 rounded-md bg-blue-600 px-1.5 py-0.5 font-mono text-xs font-bold text-white">5</span>Что важно учесть? <span class="text-xs font-normal text-gray-400">(можно несколько)</span></p>
            <div class="mt-2 flex flex-wrap gap-1.5" data-chips-multi="needs">
                <?php foreach (['работа с персональными данными клиентов',
                                'материальная ответственность сотрудников',
                                'удалённые сотрудники',
                                'стажёры и договоры ГПХ',
                                'сезонные работники',
                                'ночные смены'] as $v): ?>
                <button type="button" class="n2j-chip" data-val="<?php echo HTML::chars($v); ?>"><?php echo HTML::chars($v); ?></button>
                <?php endforeach; ?>
            </div>
            <div id="needs-inputs"></div>
            <textarea name="needs_extra" rows="2" placeholder="свои нюансы — например: работа с детьми, лицензия, коллегиальный орган…"
                      class="n2j-textarea min-h-[44px] mt-2 w-full"></textarea>
        </div>

        <!-- 6. Состав пакета -->
        <div class="<?php echo $card; ?>">
            <p class="text-base font-bold text-gray-900 sm:text-lg dark:text-white"><span class="mr-2 rounded-md bg-blue-600 px-1.5 py-0.5 font-mono text-xs font-bold text-white">6</span>Какой состав пакета?</p>
            <div class="mt-2 flex flex-wrap gap-1.5" data-chips="set">
                <button type="button" class="n2j-chip is-on" data-val="auto">полный — подберёт ИИ (рекомендую)</button>
                <button type="button" class="n2j-chip" data-val="minimal">минимум для старта</button>
                <button type="button" class="n2j-chip" data-val="custom">выберу сам</button>
            </div>
            <input type="hidden" name="set" value="auto">
            <div id="types-block" class="mt-3 hidden space-y-2">
                <div class="flex gap-2">
                    <button type="button" class="n2j-btn-secondary n2j-btn-sm" data-check="all">выбрать всё</button>
                    <button type="button" class="n2j-btn-secondary n2j-btn-sm" data-check="none">снять выделение</button>
                </div>
                <?php foreach ((array) $groups as $group => $types): ?>
                <div class="rounded-xl border border-gray-100 px-3 py-2 dark:border-gray-700">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-400"><?php echo HTML::chars((string) $group); ?></p>
                    <div class="mt-1 grid grid-cols-1 gap-1 sm:grid-cols-2">
                        <?php foreach ((array) $types as $key => $title): ?>
                        <label class="flex min-h-[36px] items-start gap-2.5 py-1 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                            <input type="checkbox" name="types[]" value="<?php echo HTML::chars((string) $key); ?>" class="mt-0.5 h-4 w-4 rounded accent-blue-600">
                            <span><?php echo HTML::chars((string) $title); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <label class="mt-3 flex min-h-[44px] cursor-pointer items-center gap-3 text-xs font-semibold text-blue-600 sm:text-sm dark:text-blue-400">
                <input type="checkbox" name="use_search" value="1" class="h-4 w-4 rounded accent-blue-600">
                <i class="fas fa-globe"></i>изучить актуальные требования в интернете (точнее, но на пару минут дольше)
            </label>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="n2j-btn-cta w-full sm:w-auto">
                <i class="fas fa-wand-magic-sparkles mr-1.5"></i>Создать пакет документов
            </button>
            <span class="text-xs text-gray-400 sm:text-sm dark:text-gray-400">ответы мастер-воркер использует при написании документов</span>
        </div>

        </div><!-- /wiz-rest -->
    </form>

    <!-- Правая колонка (1/3): компаньон, на смартфонах скрыт -->
    <div class="hidden space-y-5 md:block">
        <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                <i class="fas fa-lightbulb text-amber-500"></i>
                <h3 class="text-sm font-bold">Советы</h3>
            </div>
            <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Отвечайте «как есть» — ИИ учтёт особенности при написании документов.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>«Полный — подберёт ИИ» — лучший выбор для старта: минимум по ТК РФ со всеми ключевыми положениями.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Нужны точечные настройки полей и каталога — есть <a href="/docs/custom" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">подробная форма</a>.</span></li>
            </ul>
        </div>

        <div class="n2j-card">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Что будет на выходе</h3>
            <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Word и PDF по ГОСТ Р 7.0.97-2016, таблицы — Excel.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Проверка текстов: полнота, стиль делопроизводства.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Всё — одним ZIP; можно пересобрать с уточнениями.</span></li>
            </ul>
        </div>
    </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var CHIP = 'n2j-chip', CHIP_ON = 'is-on';

    function hiddenInput(name) {
        var el = document.querySelector('#wizard-form [name="' + name + '"]');
        return el || null;
    }

    function activate(group, btn) {
        var wrap = btn.closest('[data-chips]'),
            target = hiddenInput(group),
            isOther = btn.getAttribute('data-val') === '__other',
            other = wrap.parentNode.querySelector('.other-field');

        wrap.querySelectorAll('.n2j-chip').forEach(function (b) { b.classList.remove(CHIP_ON); });
        btn.classList.add(CHIP_ON);

        if (other) { other.classList.toggle('hidden', !isOther); if (isOther) other.focus(); }

        if (target) {
            if (isOther) {
                target.value = other ? other.value.trim() : '';
                if (other) other.oninput = function () { target.value = other.value.trim(); };
            } else {
                target.value = btn.getAttribute('data-val');
                if (other) other.value = '';
            }
        }

        if (group === 'set') {
            var tb = document.getElementById('types-block');
            if (tb) tb.classList.toggle('hidden', btn.getAttribute('data-val') !== 'custom');
        }
    }

    // одиночный выбор
    document.querySelectorAll('[data-chips]').forEach(function (wrap) {
        var group = wrap.getAttribute('data-chips');

        if (group === 'structure-pick') {
            // структура: скрытое поле заполняем с учётом текстового поля
            wrap.querySelectorAll('.n2j-chip').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    wrap.querySelectorAll('.n2j-chip').forEach(function (b) { b.classList.remove(CHIP_ON); });
                    btn.classList.add(CHIP_ON);
                    var isList = btn.getAttribute('data-val') === '__list',
                        other = wrap.parentNode.querySelector('.other-field'),
                        target = hiddenInput('structure');
                    if (other) { other.classList.toggle('hidden', !isList); if (isList) other.focus(); }
                    if (target) target.value = isList ? other.value.trim() : btn.getAttribute('data-val');
                    if (other) other.oninput = function () { target.value = other.value.trim(); };
                });
            });
            return;
        }

        wrap.querySelectorAll('.n2j-chip').forEach(function (btn) {
            btn.addEventListener('click', function () { activate(group, btn); });
        });
    });

    // множественный выбор (нюансы)
    var multi = document.querySelector('[data-chips-multi="needs"]'),
        sink = document.getElementById('needs-inputs');

    if (multi && sink) {
        multi.querySelectorAll('.n2j-chip').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var val = btn.getAttribute('data-val'),
                    on = btn.classList.toggle(CHIP_ON),
                    existing = sink.querySelector('input[value="' + val.replace(/"/g, '\\"') + '"]');
                if (on && !existing) {
                    var i = document.createElement('input');
                    i.type = 'hidden'; i.name = 'needs[]'; i.value = val;
                    sink.appendChild(i);
                } else if (!on && existing) {
                    existing.remove();
                }
            });
        });
    }

    // «выбрать всё / снять выделение» в каталоге типов
    var typesBlock = document.getElementById('types-block');
    if (typesBlock) {
        typesBlock.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-check]');
            if (!btn) return;
            typesBlock.querySelectorAll('input[name="types[]"]').forEach(function (cb) {
                cb.checked = (btn.getAttribute('data-check') === 'all');
            });
        });
    }

    // ОГРН: проверка + карточка из реестра сервиса
    function bindOgrn(btnId, inputSel, resId, cardId, nameSel, extra) {
        var btn = document.getElementById(btnId);
        if (!btn) return;
        var inp = document.querySelector(inputSel),
            res = document.getElementById(resId),
            card = document.getElementById(cardId),
            nameEl = document.querySelector(nameSel),
            t = null,
            OK = /^(\d{10}|\d{12}|\d{13}|\d{15})$/;

        // сброс: пока ищем/номер меняется — вопросы скрыты и чисты
        function resetStage() {
            var rest = document.getElementById('wiz-rest'),
                nameWrap = document.getElementById('wiz-name-wrap');
            if (rest) {
                rest.classList.add('hidden');
                rest.querySelectorAll('input[type="text"], textarea').forEach(function (el) { el.value = ''; });
                rest.querySelectorAll('.n2j-chip.is-on').forEach(function (b) { b.classList.remove('is-on'); });
                var sink2 = document.getElementById('needs-inputs');
                if (sink2) sink2.innerHTML = '';
            }
            if (card) card.classList.add('hidden');
            if (nameWrap) {
                nameWrap.classList.add('hidden');
                var ne = nameWrap.querySelector('input');
                if (ne) ne.value = '';
            }
        }

        function find() {
            var v = (inp && inp.value || '').replace(/\D/g, '');
            if (!OK.test(v)) {
                res.textContent = 'ОГРН — 13 цифр, ОГРНИП — 15, ИНН — 10 или 12';
                return;
            }
            resetStage();
            res.textContent = 'Смотрю в реестре сервиса…';
            fetch('/docs/api/org/' + v, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var rest = document.getElementById('wiz-rest'),
                        nameWrap = document.getElementById('wiz-name-wrap');
                    if (!data.found || !data.org) {
                        if (card) card.classList.add('hidden');
                        if (nameWrap) { nameWrap.classList.remove('hidden'); var ne = nameWrap.querySelector('input'); if (ne) ne.focus(); }
                        res.textContent = 'Карточки пока нет — введите название вручную: воркер проверит ОГРН при сборке';
                        if (rest) rest.classList.remove('hidden');
                        return;
                    }
                    var o = data.org || {}, lines = [o.full_name].filter(Boolean);
                    if (o.inn) lines.push('ИНН ' + o.inn);
                    if (o.okved) lines.push('ОКВЭД: ' + o.okved);
                    if (o.director) lines.push(o.director);
                    if (o.address) lines.push(o.address);
                    if (nameWrap) nameWrap.classList.add('hidden');
                    if (extra && extra.industry) { var el2 = document.querySelector(extra.industry); if (el2 && !el2.value && o.okved) el2.value = o.okved; }
                    if (extra && extra.structure) { var el3 = document.querySelector(extra.structure); if (el3 && !el3.value && (o.director || o.address)) el3.value = [o.director, o.address].filter(Boolean).join('; '); }
                    if (card) { card.textContent = lines.join(' · '); card.classList.remove('hidden'); }
                    res.textContent = 'Организация найдена — заполняйте особенности ниже';
                    if (rest) rest.classList.remove('hidden');
                })
                .catch(function () {
                    var rest = document.getElementById('wiz-rest'),
                        nameWrap = document.getElementById('wiz-name-wrap');
                    if (nameWrap) nameWrap.classList.remove('hidden');
                    if (rest) rest.classList.remove('hidden');
                    res.textContent = 'Реестр недоступен — заполните вручную, воркер всё проверит при сборке';
                });
        }

        btn.addEventListener('click', function () { resetStage(); find(); });
        if (inp) {
            inp.addEventListener('input', function () {
                clearTimeout(t);
                resetStage();
                res.textContent = 'Смотрю в реестре сервиса…';
                var v = inp.value.replace(/\D/g, '');
                if (OK.test(v)) t = setTimeout(find, 600);
            });
        }
    }

    bindOgrn('wiz-ogrn-find', '#wizard-form [name="ogrn"]', 'wiz-ogrn-result', 'wiz-org-card',
             '#wizard-form [name="org_name"]', {
        industry: '#wizard-form [name="industry"]',
        structure: '#wizard-form [name="structure"]'
    });

    // защита от двойной отправки
    var form = document.getElementById('wizard-form');
    if (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
        });
    }
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('#wizard-form button[type="submit"]').forEach(function (b) { b.disabled = false; });
    });
})();
</script>
