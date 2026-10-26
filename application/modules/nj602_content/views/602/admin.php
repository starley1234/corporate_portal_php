<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj602/admin — модерация автостатей: список, «Собрать сейчас», публикация.
 * Строки таблицы — общий фрагмент 602/admin_rows (он же AJAX-обновление).
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/content" class="transition hover:text-blue-600 dark:hover:text-blue-400">Контент-конвейер</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Модерация</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">
            <i class="fas fa-newspaper mr-2 text-blue-500"></i>Контент · модерация
        </h1>
        <span id="nj602-badge-review" class="<?php echo (int) ($counts['review'] ?? 0) > 0 ? '' : 'hidden'; ?> inline-flex items-center gap-1.5 rounded-full bg-amber-500 px-3 py-1 text-xs font-bold text-white shadow-xs">
            <i class="fas fa-hourglass-half text-[11px]"></i>ждут проверки: <span data-nj602-count="review"><?php echo (int) ($counts['review'] ?? 0); ?></span>
        </span>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">
        Воркер приносит статью по расписанию («Пиналка»,nj603) — она попадает сюда со статусом «на модерации».
        Публикация — вручную; авто-режим (NJ602_AUTO_PUBLISH=1) включим отдельным решением.
        Всего: <span data-nj602-count="total"><?php echo (int) ($counts['total'] ?? 0); ?></span> · опубликовано: <span data-nj602-count="published"><?php echo (int) ($counts['published'] ?? 0); ?></span> · ошибок: <span data-nj602-count="error"><?php echo (int) ($counts['error'] ?? 0); ?></span>.
    </p>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <div class="n2j-card p-0 md:col-span-2">
            <div class="border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <h2 class="n2j-h3">Статьи</h2>
                        <span class="rounded-md bg-gray-100 px-2 py-0.5 text-sm font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300" data-nj602-count="rows"><?php echo count($items); ?></span>
                        <?php if ((int) $f['total'] > count($items)): ?>
                        <span class="text-sm text-gray-400">из <span data-nj602-total><?php echo (int) $f['total']; ?></span></span>
                        <?php endif; ?>
                    </div>

                    <?php /* Поиск и фильтры идут GET-ом: адрес честный, ссылку можно
                             переслать, а AJAX-обновление строк берёт те же поля. */ ?>
                    <form method="get" action="/nj602/admin" id="nj602-filters" class="flex flex-wrap items-center gap-2">
                        <input type="hidden" name="page" value="1">
                        <label class="relative">
                            <i class="fas fa-magnifying-glass pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-gray-400"></i>
                            <input type="search" name="q" value="<?php echo HTML::chars((string) $f['q']); ?>" placeholder="Поиск: заголовок, тема, код"
                                   class="n2j-input w-60 pl-9">
                        </label>
                        <select name="status" class="n2j-input n2j-select font-semibold w-auto">
                            <option value="">Все статусы</option>
                            <?php foreach ([
                                'review'     => 'на модерации',
                                'published'  => 'опубликовано',
                                'collecting' => 'собирается',
                                'error'      => 'ошибка',
                                'rejected'   => 'отклонено',
                            ] as $sk => $sl): ?>
                            <option value="<?php echo $sk; ?>" <?php echo $f['status'] === $sk ? 'selected' : ''; ?>><?php echo $sl; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">Строк:
                            <select name="per" id="nj602-per" class="n2j-input n2j-select font-semibold w-auto">
                                <?php foreach ([20, 50, 100] as $pv): ?>
                                <option value="<?php echo $pv; ?>" <?php echo (int) $f['per'] === $pv ? 'selected' : ''; ?>><?php echo $pv; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="n2j-btn-process">Найти</button>
                        <?php if ($f['q'] !== '' OR $f['status'] !== ''): ?>
                        <a href="/nj602/admin" class="inline-flex min-h-[40px] items-center rounded-lg px-2.5 text-sm font-semibold text-gray-500 transition hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400">Сбросить</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <?php /* Массовые действия: чекбоксы строк привязаны к этой форме
                     атрибутом form="nj602-bulk" (чекбокс стоит внутри таблицы). */ ?>
            <form method="post" action="/nj602/admin" id="nj602-bulk" class="hidden flex-wrap items-center gap-2 border-b border-gray-100 bg-blue-50/50 px-4 py-2.5 sm:px-5 dark:border-gray-700/60 dark:bg-blue-950/20">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="ajax" value="1">
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Отмечено: <span data-nj602-selected>0</span></span>
                <button type="submit" name="act" value="publish_bulk" class="n2j-btn-cta n2j-btn-sm">
                    <i class="fas fa-circle-check text-[11px]"></i>Опубликовать выбранные
                </button>
                <button type="submit" name="act" value="reject_bulk" data-n2j-arm="Отклонить выбранные?" class="n2j-btn-secondary n2j-btn-sm">
                    <span data-n2j-arm-label>Отклонить выбранные</span>
                </button>
                <button type="submit" name="act" value="repair_mermaids"
                        class="n2j-btn-secondary n2j-btn-sm">
                    <i class="fas fa-diagram-project text-[11px]"></i>Починить схемы у выбранных
                </button>
                <button type="button" id="nj602-uncheck" class="inline-flex min-h-[36px] items-center rounded-lg px-2.5 text-sm font-semibold text-gray-500 transition hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400">Снять отметки</button>
            </form>

            <div class="overflow-x-auto n2j-scrollbar" style="scrollbar-width:thin">
                <table class="n2j-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="w-8 py-2.5 pl-4 sm:pl-5">
                                <input type="checkbox" id="nj602-check-all" title="Отметить всё на странице"
                                       class="h-4 w-4 cursor-pointer rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900">
                            </th>
                            <th class="py-2.5 pr-3">Статья</th>
                            <th class="py-2.5 px-3">Статус</th>
                            <th class="py-2.5 px-3">Создана</th>
                            <th class="py-2.5 pr-4 pl-3 text-right sm:pr-5">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-gray-700/60" id="nj602-tbody"><?php echo View::factory('602/admin_rows', ['items' => $items, 'csrf' => $csrf]); ?></tbody>
                </table>
            </div>

            <?php echo View::factory('602/admin_pager', ['f' => $f]); ?>
        </div>

        <div class="space-y-5">
            <?php /* Живой статус-ряд сбора — общий механизм модулей (эталон
                     «Собираем обзор» на /trends/<code>): спиннер, секундомер,
                     этап воркера, «Остановить». Фрагмент подменяется AJAX-ом. */ ?>
            <?php echo $live_html; ?>

            <div class="n2j-card p-0">
                <div class="flex items-center gap-2.5 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-terminal mr-1 text-blue-500"></i>Журнал прогона</h3>
                    <span class="ml-auto inline-flex items-center gap-1.5 text-xs font-semibold text-gray-400 dark:text-gray-500"><i class="fas fa-sync fa-spin text-[10px]"></i>обновляется сам</span>
                </div>
                <?php echo View::factory('602/trace', ['trace' => $trace, 'csrf' => $csrf]); ?>
            </div>

            <div class="n2j-card">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-sliders mr-1.5 text-blue-500"></i>Конвейер и темы</h3>
                    <label class="inline-flex cursor-pointer items-center gap-2">
                        <input type="checkbox" id="nj602-enabled-toggle" <?php echo $settings['enabled'] ? 'checked' : ''; ?>
                               class="peer sr-only">
                        <span class="relative h-5.5 w-10 rounded-full bg-gray-300 transition peer-checked:bg-emerald-500 after:absolute after:top-0.5 after:left-0.5 after:h-4.5 after:w-4.5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-4 dark:bg-gray-600"></span>
                        <span id="nj602-enabled-label" class="text-xs font-bold <?php echo $settings['enabled'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400'; ?>">
                            <?php echo $settings['enabled'] ? 'работает' : 'выключен'; ?>
                        </span>
                    </label>
                </div>

                <form method="post" action="/nj602/admin" id="nj602-settings-form" class="mt-3 space-y-4">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="settings">
                    <input type="hidden" name="enabled" id="nj602-enabled-field" value="<?php echo $settings['enabled'] ? '1' : '0'; ?>">

                    <div>
                        <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">Темы каталога <span class="font-normal text-gray-400">— <?php echo count($predefined); ?> шт.; выключенные конвейер не выбирает</span></p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <?php foreach ($predefined as $i => $t): $on = in_array($t, $settings['topics'], TRUE); ?>
                            <label class="nj602-topic-chip inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition <?php echo $on
                                ? 'border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-950/40 dark:text-blue-300'
                                : 'border-gray-200 bg-gray-50 text-gray-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-500'; ?>">
                                <input type="checkbox" name="topics[]" value="<?php echo HTML::chars($t); ?>" <?php echo $on ? 'checked' : ''; ?> class="sr-only">
                                <span class="nj602-chip-dot h-1.5 w-1.5 rounded-full <?php echo $on ? 'bg-blue-500' : 'bg-gray-300 dark:bg-gray-600'; ?>"></span><?php echo HTML::chars($t); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <label for="nj602-custom" class="n2j-label">Свои темы <span class="font-normal text-gray-400">— по одной в строке, до 50; конвейер комбинирует их с каталогом и интересами аудитории</span></label>
                        <textarea id="nj602-custom" name="custom" rows="3" placeholder="Например: Выживание в тайге с ноутбуком и без связи"
                                  class="n2j-textarea w-full"><?php echo HTML::chars(implode("\n", $settings['custom'])); ?></textarea>
                    </div>

                    <button type="submit" class="n2j-btn-cta w-full">Сохранить настройки</button>
                </form>

                <p class="n2j-hint mt-3 border-t border-gray-100 pt-3">
                    <?php if ($settings['enabled']): ?>
                    Следующая тема по ротации: <span class="font-bold text-gray-700 dark:text-gray-200">«<?php echo HTML::chars((string) $next_topic); ?>»</span>.
                    <?php else: ?>
                    Конвейер выключен: расписание и кнопка сбора не создают прогоны, воркер по брифингу откажется работать.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ( ! empty($budget)): ?>
            <?php $b_over = ! empty($budget['over']); ?>
            <div class="n2j-card <?php echo $b_over ? 'border-rose-300 dark:border-rose-800' : ''; ?>">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-coins mr-1.5 text-blue-500"></i>Бюджет токенов конвейера</h3>
                    <a href="/nj120/budgets?key=s%3Anj602" class="n2j-btn-secondary n2j-btn-sm">Изменить лимит</a>
                </div>
                <p class="n2j-hint mt-1">Ключ <span class="font-mono">s:nj602</span> — все сборы статей (кнопка и расписание) идут от него. Роль «system» без лимита по умолчанию; свой лимит ставится в /nj120/budgets.</p>
                <?php if ($b_over): ?>
                <div class="n2j-flash n2j-flash-err mt-2 block text-xs font-semibold">Бюджет исчерпан — очередь отказывает новым сборам. Расширьте лимит кнопкой «Изменить лимит».</div>
                <?php endif; ?>
                <div class="mt-3"><?php echo View::factory('120/_budget', ['budget' => $budget, 'compact' => TRUE, 'admin_href' => '/nj120/budgets?key=s%3Anj602'])->render(); ?></div>
            </div>
            <?php endif; ?>

            <?php /* Схемы: у части статей mermaid-схема сохранена в виде, который
                     mermaid не рисует (одной строкой, «&»-склейки, второй
                     'flowchart TD'). Чиним БЕЗ ИИ — нормализацией в воркере. */ ?>
            <div class="n2j-card">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-diagram-project mr-1.5 text-blue-500"></i>Схемы статей</h3>
                    <span id="nj602-broken-badge" class="<?php echo (int) $broken_schemas > 0 ? '' : 'hidden'; ?> inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-sm font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                        не рисуются: <span id="nj602-broken-count"><?php echo (int) $broken_schemas; ?></span>
                    </span>
                </div>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Если схемы не отрисовываются, почините их одной кнопкой: модерация отправит тексты воркеру,
                    он приведёт их к построчному виду (одна связь — одна строка, подписи в кавычках, одна схема на статью).
                    Текст статьи не меняется.
                </p>

                <form method="post" action="/nj602/admin" class="mt-3" id="nj602-fix-form">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="repair_mermaids">
                    <input type="hidden" name="ajax" value="1">
                    <button type="submit" id="nj602-fix-go"
                            class="n2j-btn-process w-full">
                        <i class="fas fa-broom text-[11px]"></i>Починить схемы<?php echo (int) $broken_schemas > 0 ? ' ('.(int) $broken_schemas.')' : ''; ?>
                    </button>
                </form>

                <p id="nj602-fix-line" class="mt-2.5 hidden text-sm text-gray-600 dark:text-gray-300">
                    <i class="fas fa-spinner n2j-spin mr-1 text-blue-500"></i><span id="nj602-fix-text">Чиню схемы…</span>
                </p>
                <p id="nj602-fix-error" class="mt-2 hidden text-sm font-semibold text-rose-600 dark:text-rose-400"></p>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500"></i>Собрать статью сейчас</h3>
                <form method="post" action="/nj602/admin" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="act" value="create">
                    <label for="nj602-topic" class="n2j-label">Тема <span class="font-normal text-gray-400">— пусто: ротация из настроек</span></label>
                    <input id="nj602-topic" name="topic" type="text" maxlength="300" placeholder="<?php echo HTML::chars((string) ($next_topic !== '' ? $next_topic : 'Например: WASM для серверных задач')); ?>"
                           class="n2j-input w-full">
                    <button type="submit" <?php echo $settings['enabled'] ? '' : 'disabled'; ?>
                            class="n2j-btn-process w-full">Запустить сбор</button>
                    <p class="n2j-hint">Обычно 3–7 минут: поиск, чтение источников, текст, схема и граф. Пока статья собирается, вторую запустить нельзя. Тема <b>__AUTO_TRENDS__</b> — конвейер сам выберет свежий тренд, учтя ваши темы и интересы аудитории.</p>
                </form>
            </div>

            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">Как работает конвейер</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Расписание — в «Пиналке» (config/nj603_kicker.php), темы — в config/nj602_content.php.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Воркер бережно читает источники (webread), ИИ пишет статью + промпт для будущей иллюстрации + mermaid + граф.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Карта сайта для поисковиков: /sitemap.xml обновляется автоматически.</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php /* Общий JS живого статус-ряда: поллинг /nj602/admin/status/<code>,
      терминальный статус → «Готово ✓» и одна перезагрузка страницы. */ ?>
<?php echo View::factory('partials/live_status_js'); ?>
<?php /* «Перегенерировать схему» — общий механизм страницы статьи и правки:
      кнопка в строке модерации ставит воркеру ту же задачу (mode=mermaid). */ ?>
<?php echo View::factory('602/regen_js'); ?>
<?php echo View::factory('602/script'); ?>
