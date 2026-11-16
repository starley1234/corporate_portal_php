<?php defined('SYSPATH') or die('No direct script access.');
/**
 * ЭТАЛОННЫЙ ДИЗАЙН-МАКЕТ МОДУЛЯ СИСТЕМЫ PRL
 * Версия 6.0 (Строгая семантическая палитра: Emerald CTA, Blue Process, Rose Danger).
 *
 * С 20.10.2026 у страницы НЕТ своих стилей и помощников: всё берётся из
 * общего слоя — ровно так же, как в модулях. Макет — живая витрина этого слоя:
 *   классы     .prl-btn-*, .prl-input, .prl-card, .prl-badge-* … → .tools/tailwind/input.css
 *   поведение  PRL.run, PRL.confirm, data-prl-arm, data-prl-copy … → public_html/assets/js/app.js
 *   шапка      partials/page_header
 *   рецепты    docs/DESIGN-SYSTEM.md — агенту читать его, а не этот файл (73 КБ разметки);
 *   план       docs/PLAN-2026-10-20-design.md — перевод модулей на эталон.
 * Скрипт внизу — только демонстрация (имитация воркера, «Тест ошибки»).
 *
 * СТАНДАРТ ЦВЕТОВЫХ РОЛЕЙ КНОПОК И ДЕЙСТВИЙ (UI SEMANTICS):
 * 1. ИЗУМРУДНЫЙ (Emerald CTA — `.prl-btn-cta`):
 *    - Созидательные/утверждающие действия, сохранение данных, публикация, первичный пуш («Создать», «Сохранить»).
 * 2. СИНИЙ (Process / Analysis — `.prl-btn-process`):
 *    - Запуск алгоритмов, генерация кода, ИИ-парсинг данных, фильтрация.
 * 3. КРАСНЫЙ (Danger / Destructive — `.prl-btn-danger`, `.prl-btn-danger-soft`, в окне подтверждения `.prl-btn-danger-solid`):
 *    - Необратимые операции: прерывание с потерей данных, удаление сущности, сброс к фабричным настройкам.
 * 4. СЕРЫЙ / НЕЙТРАЛЬНЫЙ (Secondary — `.prl-btn-secondary`):
 *    - Отмена, возврат, вторичные опции, чтение логов.
 *
 * ПРАВИЛА ПЛАТФОРМЫ (AGENTS.md), которые макет соблюдает:
 * - Полос прогресса нет: процесс = спиннер + «Этап 2 из 3» + секундомер (полоса убрана 20.10.2026).
 * - Липких блоков нет (у оглавления статьи убран sticky 20.10.2026).
 * - Строку удаляют двойным кликом (data-prl-arm). Окно подтверждения (data-prl-confirm)
 *   — только для тяжёлого: сущность с файлами, полный сброс. window.confirm и alert запрещены.
 * - Шкала: база 100 %; .is-125 — крупный вариант (ProcessHub). $scale > 1 включает его здесь.
 */

$scale = isset($scale) ? (float) $scale : 1.0;
$is_large = $scale > 1.0;

$e = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<div class="prl-ds <?php echo $is_large ? 'is-125' : ''; ?> w-full px-3.5 pt-4 pb-20 sm:px-6 lg:px-8">

    <!-- СИСТЕМНОЕ ОПОВЕЩЕНИЕ (FLASH NOTIFICATION) -->
    <div id="flash-banner" class="prl-flash prl-flash-ok mb-5" role="alert">
        <div class="flex items-center gap-3">
            <i class="fas fa-circle-check shrink-0 text-lg text-emerald-600 dark:text-emerald-400"></i>
            <span><strong class="font-bold">Иерархия действий PRL v6.0:</strong> зеленые кнопки (сохранение/утверждение), синие (вычисления/ИИ), красные (деструктивные операции) и нейтральные серые.</span>
        </div>
        <button type="button" data-prl-dismiss class="rounded-lg p-1 text-emerald-800/60 hover:text-emerald-900 dark:text-emerald-300/60 dark:hover:text-emerald-100" aria-label="Закрыть">
            <i class="fas fa-xmark text-sm"></i>
        </button>
    </div>

    <!-- ============ 1. ШАПКА МОДУЛЯ (partials/page_header) ============ -->
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Сервисы платформы', '/'], ['Генератор и анализ страниц']],
        'title' => 'Генератор и анализ страниц',
        'pill' => ['Модуль PRL v6.0', 'fa-layer-group'],
        'actions' => [
            // Вторичная серая + зелёная для прямого создания (CTA)
            ['label' => 'База знаний', 'href' => '#article-section', 'icon' => 'fa-book-open', 'role' => 'secondary'],
            ['label' => 'Новая сущность', 'href' => '#editor-section', 'icon' => 'fa-plus', 'role' => 'cta'],
        ],
        'lead' => 'Введите целевой адрес — вычислительный модуль разберёт вёрстку, семантику и подготовит чистые компоненты для интерфейса.',
    ]); ?>

    <!-- ============ 2. РАБОЧАЯ ОБЛАСТЬ: ВЫЧИСЛИТЕЛЬНАЯ ФОРМА + ПРОЦЕСС (2/3 + 1/3) ============ -->
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- Форма запуска анализа (2/3) -->
        <div class="prl-card md:col-span-2">
            <div class="prl-card-head">
                <h2 class="prl-h3">Параметры анализа</h2>
            </div>

            <form id="generator-form" method="post" action="#" class="mt-4 space-y-5" onsubmit="return false;">

                <div>
                    <div class="flex items-center justify-between">
                        <label for="ds-url" class="prl-label">
                            Целевой адрес страницы <span class="text-rose-500">*</span>
                        </label>
                        <!-- Текст ошибки ставит PRL.fieldError(input, 'текст') -->
                        <span class="prl-field-error" data-prl-error-for="ds-url" hidden></span>
                    </div>
                    <div class="relative mt-1.5">
                        <input id="ds-url" name="url" type="url" placeholder="https://example.com/catalog"
                               autocomplete="off"
                               class="prl-input">
                        <span class="prl-invalid-icon">
                            <i class="fas fa-circle-exclamation text-base"></i>
                        </span>
                    </div>
                    <p class="prl-hint">Принимаются общедоступные адреса витрин, блогов и посадочных страниц.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                    <div>
                        <label for="ds-variants" class="prl-label">Количество вариантов</label>
                        <select id="ds-variants" name="variants" class="prl-input prl-select mt-1.5">
                            <option>1 вариант</option>
                            <option selected>3 варианта</option>
                            <option>5 вариантов</option>
                        </select>
                    </div>
                    <div>
                        <label for="ds-engine" class="prl-label">
                            Движок разбора
                        </label>
                        <select id="ds-engine" name="engine" class="prl-input prl-select mt-1.5">
                            <option selected>Стандартный (DOM + Tailwind v3)</option>
                            <option>Экспериментальный (Semantic AI 2026)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="ds-wishes" class="prl-label">Пожелания к стилю</label>
                    <textarea id="ds-wishes" name="wishes" rows="3" placeholder="Например: светлая палитра, крупный акцидентный шрифт, карточки без резких теней..."
                              class="prl-textarea mt-1.5"></textarea>
                </div>

                <!-- КНОПКИ ЗАПУСКА: СИНИЙ ПРОЦЕСС + DANGER ОТМЕНА (PRL.run) -->
                <div class="border-t border-gray-100 pt-4 dark:border-gray-700/60">
                    <div class="flex flex-wrap items-center gap-3">
                        <!-- Синяя кнопка процесса: на время работы — спиннер и секундомер -->
                        <button type="button"
                                id="btn-long-process"
                                data-prl-busy-show="#btn-long-cancel, #long-process-hint"
                                class="prl-btn-process relative px-6">
                            <span data-prl-idle class="inline-flex items-center gap-2">
                                <i class="fas fa-play text-xs"></i>
                                <span>Запустить анализ</span>
                            </span>
                            <span data-prl-busy class="hidden inline-flex items-center gap-2">
                                <i class="fas fa-circle-notch prl-spin text-sm"></i>
                                <span>Выполняется… (<span data-prl-clock class="font-mono tabular-nums">0:00</span>)</span>
                            </span>
                        </button>

                        <!-- ПРИМЕР: Danger-кнопка отмены активного действия -->
                        <button type="button"
                                id="btn-long-cancel"
                                class="prl-btn-danger-soft prl-ds-xs hidden">
                            <i class="fas fa-xmark text-xs"></i>
                            <span>Прервать задачу</span>
                        </button>

                        <button type="button" id="btn-trigger-error-demo" class="prl-ds-xs text-gray-500 underline-offset-2 hover:underline sm:ml-2">
                            Тест ошибки
                        </button>
                    </div>

                    <div id="long-process-hint" class="mt-2.5 hidden flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <i class="fas fa-cloud-arrow-up text-blue-500"></i>
                        <span>Процесс запущен в фоновом воркере. Вы можете свободно покидать страницу — результат сохранится в реестре.</span>
                    </div>
                </div>
            </form>
        </div>

        <!-- Сайдбар: Процесс + Квоты (1/3) -->
        <div class="space-y-4">

            <!-- КАРТОЧКА ПРОЦЕССА: спиннер · этап «2 из 3» · секундомер. Полосы прогресса нет. -->
            <div class="prl-card w-full max-w-md border-blue-200/90 dark:border-blue-900/60" role="status" aria-live="polite">
                <div class="flex items-center justify-between gap-3">
                    <div class="prl-status">
                        <i class="fas fa-circle-notch prl-spin text-sm text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
                        <span class="font-bold text-gray-900 dark:text-white">В работе</span>
                        <span class="text-blue-300 dark:text-blue-700">·</span>
                        <!-- Сервер отдаёт, сколько секунд идёт задача; дальше тикает PRL -->
                        <span class="prl-clock" data-prl-stopwatch="1023">17:03</span>
                    </div>
                    <span class="font-mono text-xs font-semibold text-gray-400 dark:text-gray-500">dsna35b</span>
                </div>

                <div class="mt-3.5 space-y-1">
                    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>Сборка разметки</span>
                        <span class="font-semibold text-blue-600 dark:text-blue-400">Этап 2 из 3</span>
                    </div>
                    <p class="prl-ds-sm font-semibold text-gray-900 dark:text-gray-100 truncate" data-ds-progress>
                        Анализ сеток и CSS-классов…
                    </p>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3 border-t border-gray-100 pt-3.5 dark:border-gray-700/60">
                    <span class="prl-ds-xs text-gray-400 dark:text-gray-500">Осталось: ~30 с</span>
                    <!-- Красная деликатная кнопка остановки -->
                    <button type="button"
                            class="prl-btn-danger prl-btn-sm">
                        <i class="fas fa-stop text-[11px]"></i>
                        <span>Остановить</span>
                    </button>
                </div>
            </div>

            <!-- Реальный полезный контекст: Квота и нагрузка -->
            <div class="prl-card">
                <h3 class="prl-ds-sm font-bold text-gray-900 dark:text-white">Текущие квоты</h3>
                <dl class="mt-3 space-y-2 prl-ds-xs">
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Очередь воркера:</dt>
                        <dd class="font-semibold text-gray-900 dark:text-gray-100">1 задача перед вами</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Доступно токенов:</dt>
                        <dd class="font-mono font-bold text-emerald-600 dark:text-emerald-400">658k / 1M</dd>
                    </div>
                </dl>
            </div>

        </div>
    </div>

    <!-- ============ 3. РЕЕСТР ПРОГОНОВ (ТАБЛИЦА + EMPTY STATE) ============ -->
    <section class="prl-panel mt-8">

        <div class="prl-panel-head">
            <div class="flex items-center gap-2.5">
                <h2 class="prl-h3">История прогонов</h2>
                <span class="prl-count">2</span>
            </div>

            <div class="flex items-center gap-2">
                <div class="prl-seg">
                    <button type="button" class="prl-seg-item" aria-pressed="true">Все (2)</button>
                    <button type="button" class="prl-seg-item" aria-pressed="false">В работе (1)</button>
                    <button type="button" class="prl-seg-item" aria-pressed="false">Завершены (1)</button>
                </div>

                <button type="button" id="btn-toggle-empty" title="Показать Empty State" class="prl-ds-xs inline-flex min-h-[32px] items-center rounded-lg border border-gray-300 bg-white px-2.5 font-medium text-gray-600 shadow-xs hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    Тест пустоты
                </button>
            </div>
        </div>

        <!-- Мобильные карточки (< 640px) -->
        <div id="runs-content-filled" class="block">
            <div class="divide-y divide-gray-100 sm:hidden dark:divide-gray-700/60">
                <div class="p-4 space-y-2.5" data-ds-row>
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs font-bold text-blue-600 dark:text-blue-400">dsnf592</span>
                                <button type="button" data-prl-copy="dsnf592ccad" title="Копировать ID" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                                    <i class="fas fa-copy text-xs"></i>
                                </button>
                            </div>
                            <h3 class="prl-ds-sm font-bold text-gray-900 dark:text-white mt-0.5">Лендинг «Ромашка»</h3>
                        </div>
                        <span class="prl-badge prl-badge-ok"><span class="prl-dot"></span>готово</span>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <a href="#" onclick="return false;" class="font-mono text-blue-600 dark:text-blue-400 hover:underline">https://romashka.ru</a>
                        <span class="text-gray-400">20.09, 22:32</span>
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-1 border-t border-gray-50 dark:border-gray-700/40">
                        <button type="button" class="prl-btn-secondary prl-btn-sm">
                            Открыть
                        </button>
                        <!-- Удаление строки: двойной клик, без окна -->
                        <button type="button" data-prl-arm="Удалить?" title="Удалить" aria-label="Удалить" class="prl-btn-icon-danger">
                            <i class="fas fa-trash-can text-xs"></i>
                        </button>
                    </div>
                </div>

                <div class="p-4 space-y-2.5" data-ds-row>
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs font-bold text-blue-600 dark:text-blue-400">dsna35b</span>
                                <button type="button" data-prl-copy="dsna35b3900" title="Копировать ID" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                                    <i class="fas fa-copy text-xs"></i>
                                </button>
                            </div>
                            <h3 class="prl-ds-sm font-bold text-gray-900 dark:text-white mt-0.5">Блог компании</h3>
                        </div>
                        <span class="prl-badge prl-badge-run"><span class="prl-dot"></span>в работе</span>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <a href="#" onclick="return false;" class="font-mono text-blue-600 dark:text-blue-400 hover:underline">https://example.com/blog</a>
                        <span class="text-gray-400">20.09, 21:07</span>
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-1 border-t border-gray-50 dark:border-gray-700/40">
                        <button type="button" class="prl-btn-secondary prl-btn-sm">
                            Логи
                        </button>
                        <button type="button" disabled title="Нельзя удалить: прогон ещё идёт" aria-label="Удалить" class="prl-btn-icon-danger">
                            <i class="fas fa-trash-can text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Десктопная таблица (>= 640px) -->
            <div class="hidden sm:block overflow-x-auto prl-scrollbar">
                <table class="prl-table whitespace-nowrap">
                    <thead>
                        <tr>
                            <th>Код / Название</th>
                            <th>Статус</th>
                            <th>Целевой адрес</th>
                            <th>Создан</th>
                            <th class="text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-xs font-bold text-blue-600 dark:text-blue-400">dsnf592</span>
                                    <button type="button" data-prl-copy="dsnf592ccad" title="Копировать ID" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                                        <i class="fas fa-copy text-xs"></i>
                                    </button>
                                    <span class="font-semibold text-gray-900 dark:text-white">Лендинг «Ромашка»</span>
                                </div>
                            </td>
                            <td>
                                <span class="prl-badge prl-badge-ok"><span class="prl-dot"></span>готово</span>
                            </td>
                            <td class="text-gray-600 dark:text-gray-300">
                                <a href="#" onclick="return false;" class="truncate font-mono hover:underline max-w-[240px] inline-block align-bottom">https://romashka.ru</a>
                            </td>
                            <td class="prl-ds-xs text-gray-500 dark:text-gray-400">20.09, 22:32</td>
                            <td class="text-right">
                                <div class="inline-flex items-center justify-end gap-2">
                                    <button type="button" class="prl-btn-secondary prl-btn-sm">
                                        Открыть
                                    </button>
                                    <button type="button" data-prl-arm="Удалить?" title="Удалить" aria-label="Удалить" class="prl-btn-icon-danger">
                                        <i class="fas fa-trash-can text-xs"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-xs font-bold text-blue-600 dark:text-blue-400">dsna35b</span>
                                    <button type="button" data-prl-copy="dsna35b3900" title="Копировать ID" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                                        <i class="fas fa-copy text-xs"></i>
                                    </button>
                                    <span class="font-semibold text-gray-900 dark:text-white">Блог компании</span>
                                </div>
                            </td>
                            <td>
                                <span class="prl-badge prl-badge-run"><span class="prl-dot"></span>в работе</span>
                            </td>
                            <td class="text-gray-600 dark:text-gray-300">
                                <a href="#" onclick="return false;" class="truncate font-mono hover:underline max-w-[240px] inline-block align-bottom">https://example.com/blog</a>
                            </td>
                            <td class="prl-ds-xs text-gray-500 dark:text-gray-400">20.09, 21:07</td>
                            <td class="text-right">
                                <div class="inline-flex items-center justify-end gap-2">
                                    <button type="button" class="prl-btn-secondary prl-btn-sm">
                                        Логи
                                    </button>
                                    <button type="button" disabled title="Нельзя удалить: прогон ещё идёт" aria-label="Удалить" class="prl-btn-icon-danger">
                                        <i class="fas fa-trash-can text-xs"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ПРИМЕР: Зелёная кнопка в Empty State (Побуждение к созданию) -->
        <div id="runs-content-empty" class="prl-empty hidden">
            <div class="prl-empty-icon">
                <i class="fas fa-folder-open"></i>
            </div>
            <h3 class="prl-h3 mt-4">Нет активных прогонов</h3>
            <p class="prl-empty-text">
                Вы пока не запустили ни одного анализа. Создайте первый прогон, чтобы начать сборку интерфейса.
            </p>
            <div class="mt-5">
                <button type="button" id="btn-empty-create" class="prl-btn-cta px-5">
                    <i class="fas fa-plus"></i>
                    <span>Создать первый прогон</span>
                </button>
            </div>
        </div>

        <!-- Пагинация -->
        <div class="prl-panel-foot">
            <span class="prl-ds-xs text-gray-500 dark:text-gray-400">Показано <strong class="font-semibold text-gray-700 dark:text-gray-200">2</strong> из <strong class="font-semibold text-gray-700 dark:text-gray-200">2</strong></span>
            <div class="flex items-center gap-1">
                <button type="button" disabled class="prl-page" aria-label="Предыдущая страница">‹</button>
                <button type="button" class="prl-page" aria-current="page">1</button>
                <button type="button" disabled class="prl-page" aria-label="Следующая страница">›</button>
            </div>
        </div>
    </section>

    <!-- ============ 4. ЭТАЛОН ЧИТАЕМОЙ СТАТЬИ (СЕТКА 2/3 + 1/3 НА ВСЮ ШИРИНУ) ============ -->
    <section id="article-section" class="prl-card mt-8 p-5 sm:p-7">

        <header class="border-b border-gray-100 pb-5 dark:border-gray-700/60">
            <nav class="prl-crumbs" aria-label="Хлебные крошки статьи">
                <a href="#" onclick="return false;">База знаний</a>
                <span class="prl-crumbs-sep">/</span>
                <span class="prl-crumbs-current">Архитектура интерфейсов PRL</span>
            </nav>

            <div class="mt-2.5 flex flex-wrap items-center justify-between gap-3">
                <h1 class="prl-h1">
                    Семантика действий и цветовой баланс
                </h1>
                <span class="prl-pill">
                    <i class="fas fa-book-open text-xs"></i>Стандарт PRL
                </span>
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 prl-ds-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-1.5"><i class="fas fa-circle-user text-xs"></i> Дизайн-система</span>
                <span>•</span>
                <span class="inline-flex items-center gap-1.5"><i class="fas fa-clock text-xs"></i> 4 мин чтения</span>
                <span>•</span>
                <span class="inline-flex items-center gap-1.5"><i class="fas fa-calendar-check text-xs"></i> Редакция: октябрь 2026</span>
            </div>
        </header>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">

            <!-- Основной текст статьи (2/3): кегль 17px, line-height 1.7 -->
            <article class="space-y-6 text-[17px] leading-[1.7] text-gray-800 dark:text-gray-200 lg:col-span-2">

                <p class="text-lg font-medium leading-relaxed text-gray-900 dark:text-gray-100">
                    Монотонный синий интерфейс («синее море») провоцирует ошибки: оператор нажимает кнопки на автомате. Строгий B2B-интерфейс требует четкого разделения цветовых ролей.
                </p>

                <div class="space-y-3">
                    <h2 class="text-xl font-bold tracking-tight text-gray-900 dark:text-white">
                        1. Правило четырёх ролей
                    </h2>
                    <p>
                        Каждая кнопка в шаблоне должна иметь строго определённое функциональное назначение:
                    </p>
                    <ul class="list-disc pl-5 space-y-2 marker:text-blue-600 dark:marker:text-blue-400">
                        <li><strong class="font-semibold text-emerald-700 dark:text-emerald-400">Изумрудный (Emerald CTA):</strong> первичное сохранение, добавление новой сущности или публикация. Это утвердительное действие.</li>
                        <li><strong class="font-semibold text-blue-700 dark:text-blue-400">Синий (Compute / AI):</strong> запуск алгоритмов, генерация вариантов, семантический парсинг или переход по шагам мастера.</li>
                        <li><strong class="font-semibold text-rose-700 dark:text-rose-400">Красный (Danger):</strong> необратимые действия: немедленное удаление сущности, прерывание фоновой операции, очистка логов.</li>
                        <li><strong class="font-semibold text-gray-700 dark:text-gray-300">Серый (Secondary):</strong> сброс, отмена, закрытие диалога, просмотр деталей.</li>
                    </ul>
                </div>

                <!-- ВРЕЗКА: Секундомер и неразрывность -->
                <div class="rounded-xl border border-blue-200 bg-blue-50/70 p-5 shadow-xs dark:border-blue-900/60 dark:bg-blue-950/30">
                    <div class="flex items-center gap-2 text-blue-950 dark:text-blue-100">
                        <i class="fas fa-circle-check text-base text-blue-600 dark:text-blue-400"></i>
                        <h3 class="prl-ds-sm font-bold">Правило неразрывного статуса</h3>
                    </div>
                    <p class="mt-2 prl-ds-sm leading-relaxed text-blue-900/90 dark:text-blue-200/90">
                        Спиннер, наименование этапа и моноширинный секундомер объединяются в один неделимый бейдж:
                    </p>
                    <div class="mt-3.5 inline-flex items-center gap-2.5 rounded-lg border border-blue-200/80 bg-white px-3.5 py-2 shadow-xs dark:border-gray-700 dark:bg-gray-800">
                        <i class="fas fa-circle-notch prl-spin text-sm text-blue-600 dark:text-blue-400"></i>
                        <span class="prl-ds-xs font-bold text-gray-900 dark:text-white">В работе</span>
                        <span class="prl-ds-xs rounded-md border border-blue-200 bg-blue-50 px-2 py-0.5 font-mono font-bold tabular-nums text-blue-800 dark:border-blue-800/60 dark:bg-blue-900/40 dark:text-blue-300">17:03</span>
                    </div>
                </div>

                <div class="space-y-3">
                    <h2 class="text-xl font-bold tracking-tight text-gray-900 dark:text-white">
                        2. Широкоформатная компоновка
                    </h2>
                    <p>
                        Экран заполняется на 100%, а комфорт чтения достигается пропорцией 2/3 (текст) к 1/3 (сайдбар). Это исключает «простыню» на сверхшироких 4K-мониторах и одновременно избавляет от ощущения пустоты по краям.
                    </p>
                </div>
            </article>

            <!-- Сайдбар статьи (1/3). Не липкий: sticky запрещён правилами UI. -->
            <aside class="space-y-4">
                <!-- Оглавление -->
                <div class="prl-card p-4 sm:p-5">
                    <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                        <i class="fas fa-list-ul text-blue-600 dark:text-blue-400"></i>
                        <h3 class="prl-ds-sm font-bold">В этом разделе</h3>
                    </div>
                    <ul class="mt-3 space-y-2.5 prl-ds-xs text-gray-600 dark:text-gray-300">
                        <li>
                            <a href="#" onclick="return false;" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">1. Правило четырёх ролей кнопок</a>
                        </li>
                        <li>
                            <a href="#" onclick="return false;" class="hover:text-blue-600 dark:hover:text-blue-400">2. Неразрывные статусы</a>
                        </li>
                        <li>
                            <a href="#" onclick="return false;" class="hover:text-blue-600 dark:hover:text-blue-400">3. Широкоформатная пропорция 2/3</a>
                        </li>
                    </ul>
                </div>

                <!-- Шпаргалка по цветам контролов -->
                <div class="prl-card p-4 sm:p-5">
                    <h3 class="prl-ds-label text-gray-400 dark:text-gray-500">Палитра действий</h3>
                    <div class="mt-3 space-y-2 prl-ds-xs">
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded-full bg-emerald-600"></span>
                            <span class="font-medium text-gray-700 dark:text-gray-200">Emerald: Сохранение / Создание</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded-full bg-blue-600"></span>
                            <span class="font-medium text-gray-700 dark:text-gray-200">Blue: Анализ / Вычисления / ИИ</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded-full bg-rose-600"></span>
                            <span class="font-medium text-gray-700 dark:text-gray-200">Rose: Удаление / Остановка</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded-full bg-gray-400"></span>
                            <span class="font-medium text-gray-700 dark:text-gray-200">Gray: Сброс / Отмена</span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <!-- ============ 5. РЕДАКТОР СУЩНОСТИ: ЗЕЛЁНЫЙ CTA + DANGER ДЕЙСТВИЕ ============ -->
    <section id="editor-section" class="mt-10 border-t border-gray-200/80 pt-8 scroll-mt-6 dark:border-gray-700/60">
        <div>
            <h2 class="prl-h2">Редактор параметров</h2>
            <p class="prl-ds-sm mt-1 text-gray-500 dark:text-gray-400">
                Заполните форму вручную или используйте синий ИИ-экстрактор для быстрого разбора сырого текста.
            </p>
        </div>

        <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
            <div class="space-y-6 md:col-span-2">

                <!-- ИИ-экстрактор сырых данных (СИНИЙ ВЫЧИСЛИТЕЛЬНЫЙ БЛОК) -->
                <div class="rounded-xl border-2 border-dashed border-blue-300/90 bg-blue-50/40 p-5 shadow-xs dark:border-blue-700/60 dark:bg-blue-950/20">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 text-blue-900 dark:text-blue-200">
                            <i class="fas fa-wand-magic-sparkles text-blue-600 dark:text-blue-400"></i>
                            <h3 class="prl-ds-sm font-bold">Умный ИИ-экстрактор полей</h3>
                        </div>
                        <span class="prl-ds-xs rounded-md bg-blue-100 px-2.5 py-0.5 font-semibold text-blue-800 dark:bg-blue-900/60 dark:text-blue-300">
                            Сырой текст
                        </span>
                    </div>

                    <div class="mt-3">
                        <textarea id="ai-raw-input" rows="3"
                                  autocomplete="off" autocapitalize="off" spellcheck="false"
                                  placeholder="Вставьте бриф или текст задачи... Например: Проект: Монолит Бетон, слаг monolith, B2B витрина оборудования..."
                                  class="prl-textarea border-blue-200 focus:ring-blue-500/20 dark:border-blue-800"></textarea>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2.5">
                        <!-- Синяя кнопка вычисления -->
                        <button type="button" id="btn-ai-parse" class="prl-btn-process">
                            <i class="fas fa-bolt text-amber-300"></i>
                            <span>Распознать и заполнить поля</span>
                            <span class="prl-btn-kbd">Ctrl+Enter</span>
                        </button>
                        <button type="button" id="btn-ai-demo-fill" class="prl-link prl-ds-xs">
                            Вставить пример
                        </button>
                    </div>
                </div>

                <!-- Основная форма редактирования -->
                <div class="prl-card">
                    <form id="item-edit-form" method="post" action="#" autocomplete="off" novalidate onsubmit="return false;" class="space-y-5">

                        <div>
                            <label for="f-title" class="prl-label">
                                Название страницы / сущности <span class="text-rose-500">*</span>
                            </label>
                            <input id="f-title" name="item_title" type="text"
                                   autocomplete="new-password"
                                   data-lpignore="true" data-1p-ignore
                                   placeholder="Например: Каталог насосного оборудования"
                                   class="prl-input mt-1.5">
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                            <div>
                                <label for="f-slug" class="prl-label">
                                    Слаг идентификатор
                                </label>
                                <div class="prl-input-group mt-1.5">
                                    <span class="prl-input-addon">/view/</span>
                                    <input id="f-slug" name="item_slug" type="text"
                                           autocomplete="off"
                                           placeholder="monolith-pumps"
                                           class="prl-input-bare font-mono">
                                </div>
                            </div>

                            <div>
                                <label for="f-category" class="prl-label">
                                    Категория
                                </label>
                                <select id="f-category" name="item_category" class="prl-input prl-select mt-1.5">
                                    <option value="">-- Выберите отрасль --</option>
                                    <option value="landing">Промо / Landing</option>
                                    <option value="b2b">Корпоративный B2B</option>
                                    <option value="ecommerce">Каталог / E-commerce</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="f-desc" class="prl-label">Подробное описание</label>
                            <textarea id="f-desc" name="item_desc" rows="3"
                                      autocomplete="off"
                                      placeholder="Опишите назначение интерфейса и визуальные ограничения..."
                                      class="prl-textarea mt-1.5"></textarea>
                        </div>

                        <!-- ЭТАЛОН: ПОЛНЫЙ СПЕКТР КНОПОК В ПОДВАЛЕ (ЗЕЛЕНЫЙ CTA, НЕЙТРАЛЬНЫЙ СБРОС И КРАСНЫЙ DANGER) -->
                        <div class="flex flex-col gap-3 border-t border-gray-100 pt-5 sm:flex-row sm:items-center dark:border-gray-700/60">

                            <!-- 1. Зелёный CTA сохранения (побуждение к утверждению) -->
                            <button type="submit" class="prl-btn-cta w-full px-6 sm:w-auto">
                                <i class="fas fa-check text-xs"></i>
                                <span>Сохранить изменения</span>
                                <span class="prl-btn-kbd">Ctrl+S</span>
                            </button>

                            <!-- 2. Нейтральная вторичная кнопка (сброс) -->
                            <button type="button" id="btn-form-reset" class="prl-btn-secondary w-full px-5 sm:w-auto">
                                Сбросить
                            </button>

                            <!-- 3. Красная Danger-кнопка: удаление сущности с файлами — тяжёлое, поэтому окно -->
                            <button type="button" id="btn-item-delete"
                                    data-prl-confirm="Вы действительно хотите удалить сущность {subject} и все связанные файлы?"
                                    data-prl-subject="dsnf592"
                                    data-prl-title="Удалить запись?"
                                    data-prl-ok="Удалить навсегда"
                                    class="prl-btn-danger w-full sm:ml-auto sm:w-auto">
                                <i class="fas fa-trash-can text-xs"></i>
                                <span>Удалить запись</span>
                            </button>

                            <span id="save-status-msg" class="hidden prl-ds-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                <i class="fas fa-circle-check mr-1"></i> Сохранено
                            </span>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Сайдбар редактора -->
            <div class="space-y-4">
                <div class="prl-card">
                    <h3 class="prl-ds-label text-gray-400 dark:text-gray-500">Быстрые клавиши</h3>
                    <div class="mt-3 space-y-2.5 prl-ds-xs">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600 dark:text-gray-300">Сохранение формы:</span>
                            <kbd class="prl-kbd">Ctrl + S</kbd>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600 dark:text-gray-300">ИИ-парсинг:</span>
                            <kbd class="prl-kbd">Ctrl + Enter</kbd>
                        </div>
                    </div>
                </div>

                <!-- Скелетон ожидания -->
                <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40">
                    <div class="flex items-center justify-between">
                        <span class="prl-ds-label text-gray-400">Скелетон ожидания</span>
                        <?php /* design-audit: allow tiny — подпись-аннотация демо-блока, как в макете владельца */ ?>
                        <span class="text-[11px] font-mono text-gray-400">animate-pulse</span>
                    </div>
                    <div class="mt-3 animate-pulse space-y-2.5">
                        <div class="h-4 w-3/4 rounded bg-gray-200 dark:bg-gray-700"></div>
                        <div class="h-3 w-full rounded bg-gray-200 dark:bg-gray-700"></div>
                        <div class="h-3 w-5/6 rounded bg-gray-200 dark:bg-gray-700"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ ДЕМО-СЦЕНАРИИ (общие помощники — в app.js, здесь только имитация) ============ -->
    <script>
    (function () {
        var PRL = window.PRL;
        if (!PRL) return;

        // 1. Кнопка долгого процесса: PRL.run показывает спиннер, секундомер,
        //    «Прервать задачу» и подсказку про фоновый воркер (data-prl-busy-show).
        var btnLong = document.getElementById('btn-long-process');
        var btnCancel = document.getElementById('btn-long-cancel');
        if (btnLong && btnCancel) {
            btnLong.addEventListener('click', function () { PRL.run(btnLong, true); });
            btnCancel.addEventListener('click', function () { PRL.run(btnLong, false); });
        }

        // 2. Имитация воркера: смена текста этапа. На проде текст приходит
        //    из статуса задачи (partials/live_status).
        var pr = document.querySelector('[data-ds-progress]');
        if (pr) {
            var msgs = [
                'Анализ сеток и CSS-классов…',
                'Проверка контраста типографики…',
                'Подбор адаптивных компонентов…',
                'Финальная сборка HTML…'
            ];
            var mi = 0;
            setInterval(function () {
                mi = (mi + 1) % msgs.length;
                pr.textContent = msgs[mi];
            }, 3500);
        }

        // 3. Ошибка поля: PRL.fieldError (рамка, иконка, текст; снимается при правке).
        var btnDemoError = document.getElementById('btn-trigger-error-demo');
        var inputUrl = document.getElementById('ds-url');
        if (btnDemoError && inputUrl) {
            btnDemoError.addEventListener('click', function () {
                if (inputUrl.getAttribute('aria-invalid') === 'true') {
                    PRL.fieldError(inputUrl, null);
                } else {
                    PRL.fieldError(inputUrl, 'Укажите корректный URL (с http:// или https://)');
                    inputUrl.focus();
                }
            });
        }

        // 4. Переключение вида Empty State в таблице.
        var btnToggleEmpty = document.getElementById('btn-toggle-empty');
        var runsFilled = document.getElementById('runs-content-filled');
        var runsEmpty = document.getElementById('runs-content-empty');
        if (btnToggleEmpty && runsFilled && runsEmpty) {
            btnToggleEmpty.addEventListener('click', function () {
                runsFilled.classList.toggle('hidden');
                runsEmpty.classList.toggle('hidden');
            });
        }
        var btnEmptyCreate = document.getElementById('btn-empty-create');
        if (btnEmptyCreate && inputUrl) {
            btnEmptyCreate.addEventListener('click', function () { inputUrl.focus(); });
        }

        // 5. Удаление подтверждено (второй клик data-prl-arm или «Удалить
        //    навсегда» в окне data-prl-confirm) — событие prl:confirmed.
        document.addEventListener('prl:confirmed', function (e) {
            var el = e.target;
            if (el.id === 'btn-item-delete') {
                var form = document.getElementById('item-edit-form');
                if (form) form.reset();
                return;
            }
            var row = el.closest('tr, [data-ds-row]');
            if (row) {
                row.style.opacity = '0.3';
                setTimeout(function () { row.remove(); }, 200);
            }
        });

        // 6. ИИ-парсер сырых данных (имитация ответа модели).
        var rawInput = document.getElementById('ai-raw-input');
        var btnParse = document.getElementById('btn-ai-parse');
        var btnDemo = document.getElementById('btn-ai-demo-fill');
        var fTitle = document.getElementById('f-title');
        var fSlug = document.getElementById('f-slug');
        var fCat = document.getElementById('f-category');
        var fDesc = document.getElementById('f-desc');

        if (btnDemo && rawInput) {
            btnDemo.addEventListener('click', function () {
                rawInput.value = "Проект: Оборудование для бетона 'Монолит'\n" +
                                 "Слаг: monolith-pumps\n" +
                                 "Категория: B2B\n" +
                                 "Описание: Каталог высоконагруженных промышленных насосов.";
                PRL.fieldError(rawInput, null);
            });
        }

        function triggerAiParse() {
            if (!rawInput || !btnParse || btnParse.disabled) return;
            if (!rawInput.value.trim()) {
                PRL.fieldError(rawInput, 'Вставьте сырой текст для разбора.');
                rawInput.focus();
                return;
            }

            PRL.busy(btnParse, true, '<i class="fas fa-circle-notch prl-spin"></i><span>Извлечение…</span>');

            setTimeout(function () {
                fTitle.value = "Каталог оборудования 'Монолит'";
                fSlug.value = "monolith-pumps";
                fCat.value = "b2b";
                fDesc.value = "Каталог высоконагруженных промышленных насосов.";

                [fTitle, fSlug, fCat, fDesc].forEach(function (el) {
                    if (el) {
                        el.classList.add('ring-2', 'ring-emerald-500/50');
                        setTimeout(function () { el.classList.remove('ring-2', 'ring-emerald-500/50'); }, 1200);
                    }
                });

                PRL.busy(btnParse, false);
                var origHTML = btnParse.innerHTML;
                btnParse.innerHTML = '<i class="fas fa-check text-emerald-300"></i><span>Заполнено!</span>';
                setTimeout(function () { btnParse.innerHTML = origHTML; }, 1800);
            }, 600);
        }

        if (btnParse) { btnParse.addEventListener('click', triggerAiParse); }

        // 7. Сохранение формы и горячие клавиши: PRL.hotkey (любая раскладка).
        function triggerFormSave() {
            var msg = document.getElementById('save-status-msg');
            if (msg) {
                msg.classList.remove('hidden');
                setTimeout(function () { msg.classList.add('hidden'); }, 2500);
            }
        }

        var form = document.getElementById('item-edit-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                triggerFormSave();
            });
        }

        PRL.hotkey('mod+s', triggerFormSave);
        if (rawInput) { PRL.hotkey('mod+enter', triggerAiParse, { within: rawInput }); }
    })();
    </script>
</div>
