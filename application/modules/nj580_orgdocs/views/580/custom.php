<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /docs/custom — подробная форма пакета. Макет эталона (2/3 форма + 1/3
 * компаньон), блоки от левого верха. ИИ-разбор сырых данных — первым блоком.
 * Префилл из реестра по ?ogrn= (название, ОКВЭД → сфера). POST → общий /docs.
 */
$org_name = $org['full_name'] ?? '';
$okved    = $org['okved'] ?? '';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <!-- ============ Шапка ============ -->
    <header>
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/docs" class="transition hover:text-blue-600 dark:hover:text-blue-400">Документы организации</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-medium text-gray-700 dark:text-gray-200">Подробная форма</span>
        </nav>
        <h1 class="n2j-h1 mt-2">Пакет документов — подробно</h1>
        <p class="mt-1.5 text-xs sm:text-sm text-gray-600 dark:text-gray-300">Заполните, что знаете, — остальное ИИ уточнит из официальных данных и текста о компании. Обязательно — только организация.</p>
    </header>

    <?php if ( ! $logged): ?>
    <div class="n2j-flash n2j-flash-warn mt-5 flex items-center gap-3 text-xs">
        <i class="fas fa-lock shrink-0 text-base text-amber-600 dark:text-amber-400"></i>
        <span>Сервис для зарегистрированных — <a href="/auth/login?return_url=docs/custom" class="font-semibold underline">войдите</a>, чтобы собрать пакет.</span>
    </div>
    <?php else: ?>

    <!-- ============ Рабочая область: 2/3 форма + 1/3 компаньон ============ -->
    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">
        <!-- Левая колонка (2/3) -->
        <div class="space-y-6 md:col-span-2">

            <!-- БЛОК 1: ИИ-разбор сырых данных (как в эталоне — первым) -->
            <div class="rounded-xl border-2 border-dashed border-blue-300/80 bg-blue-50/40 p-4 shadow-xs dark:border-blue-700/60 dark:bg-blue-950/20 sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2 text-blue-900 dark:text-blue-200">
                        <i class="fas fa-wand-magic-sparkles text-sm text-blue-600 dark:text-blue-400"></i>
                        <h2 class="n2j-h3">Умное заполнение через ИИ</h2>
                    </div>
                    <span class="n2j-badge n2j-badge-info">Экстрактор сырых данных</span>
                </div>
                <p class="mt-1.5 text-xs text-blue-800/80 dark:text-blue-300/80">
                    Вставьте любой текст об организации: описание с сайта, письмо, выписку. ИИ разложит его по полям формы ниже.
                </p>
                <textarea id="docs-raw" rows="3" autocomplete="off" spellcheck="false"
                          placeholder="Пример: ООО «Ромашка», розничная торговля, 12 человек; директор и бухгалтер, 2 магазина…"
                          class="n2j-textarea min-h-[44px] mt-3 w-full"></textarea>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <button type="button" id="docs-raw-parse" class="n2j-btn-process">
                        <i class="fas fa-bolt text-amber-300"></i>Разложить по полям
                    </button>
                    <span class="text-xs text-gray-500 dark:text-gray-400">это черновик — при сборке воркер ещё раз разберёт текст с помощью ИИ</span>
                </div>
            </div>

            <!-- БЛОК 2: Основная форма -->
            <form method="post" action="/docs" id="docs-create" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="ogrn" value="<?php echo HTML::chars($ogrn); ?>">

                <!-- Организация -->
                <div class="n2j-card">
                    <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                        <h2 class="n2j-h3">Организация</h2>
                    </div>

                    <?php if ($org !== NULL): ?>
                    <div class="n2j-flash n2j-flash-ok mt-4 text-xs block">
                        <i class="fas fa-circle-check mr-1.5"></i><?php echo HTML::chars($org_name); ?><?php echo ($org['inn'] ?? '') !== '' ? ' · ИНН '.HTML::chars($org['inn']) : ''; ?>
                    </div>
                    <?php else: ?>
                    <p class="n2j-hint mt-4">Номер не передан или не найден в реестре — при сборке воркер проверит данные и подтянет официальные реквизиты.</p>
                    <?php endif; ?>

                    <div class="mt-4">
                        <label for="docs-org-name" class="n2j-label">
                            Название организации <span class="font-normal text-gray-400">— если пусто, впишите вручную</span>
                        </label>
                        <input id="docs-org-name" type="text" name="org_name" maxlength="300" value="<?php echo HTML::chars($org_name); ?>"
                               placeholder="ООО «Ромашка» или ИП Иванов И.И." autocomplete="off"
                               class="n2j-input mt-1.5 w-full">
                    </div>
                </div>

                <!-- Особенности -->
                <div class="n2j-card mt-6">
                    <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                        <h2 class="n2j-h3">Особенности</h2>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2 sm:gap-5">
                        <div>
                            <label for="docs-industry" class="n2j-label">Сфера деятельности</label>
                            <input id="docs-industry" type="text" name="industry" maxlength="300" value="<?php echo HTML::chars($okved); ?>"
                                   placeholder="розничная торговля, 2 магазина" autocomplete="off"
                                   class="n2j-input mt-1.5 w-full">
                            <?php if ($okved !== ''): ?><p class="n2j-hint">Заполнено из реестра: ОКВЭД.</p><?php endif; ?>
                        </div>
                        <div>
                            <label for="docs-size" class="n2j-label">Численность</label>
                            <input id="docs-size" type="text" name="size" maxlength="60" placeholder="12 человек" autocomplete="off"
                                   class="n2j-input mt-1.5 w-full">
                        </div>
                    </div>

                    <div class="mt-4 sm:mt-5">
                        <label for="docs-structure" class="n2j-label">Структура (отделы, должности)</label>
                        <textarea id="docs-structure" name="structure" rows="3" maxlength="2000" placeholder="Директор; бухгалтер; 2 продавца-кассира; кладовщик…"
                                  class="n2j-textarea min-h-[44px] mt-1.5 w-full"></textarea>
                    </div>

                    <div class="mt-4 sm:mt-5">
                        <label for="docs-needs" class="n2j-label">Особые требования</label>
                        <textarea id="docs-needs" name="needs" rows="2" maxlength="2000" placeholder="Например: работа со сменным графиком, нужен раздел про удалённых сотрудников…"
                                  class="n2j-textarea min-h-[44px] mt-1.5 w-full"></textarea>
                    </div>
                </div>

                <!-- Состав пакета -->
                <div class="n2j-card mt-6">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3 dark:border-gray-700/60">
                        <h2 class="n2j-h3">Состав пакета</h2>
                        <a href="/docs/wizard" class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 transition hover:text-blue-500 dark:text-blue-400">
                            <i class="fas fa-list-check text-[11px]"></i>не знаете, что нужно? Мастер — 6 вопросов
                        </a>
                    </div>

                    <div class="mt-4 flex gap-2">
                        <button type="button" class="n2j-btn-secondary n2j-btn-sm" data-check="all">выбрать всё</button>
                        <button type="button" class="n2j-btn-secondary n2j-btn-sm" data-check="none">снять выделение</button>
                    </div>

                    <div class="mt-3 space-y-3">
                        <?php foreach ((array) $groups as $group => $types): ?>
                        <div class="rounded-xl border border-gray-100 px-3 py-2.5 dark:border-gray-700">
                            <p class="text-xs font-bold uppercase tracking-wide text-gray-400"><?php echo HTML::chars((string) $group); ?></p>
                            <div class="mt-1.5 grid grid-cols-1 gap-1 sm:grid-cols-2">
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

                    <div class="mt-4 space-y-3 border-t border-gray-100 pt-4 dark:border-gray-700/60">
                        <label class="flex cursor-pointer items-center gap-3">
                            <input type="checkbox" name="auto" value="1" class="h-4 w-4 rounded accent-blue-600">
                            <span class="text-xs font-medium text-gray-700 sm:text-sm dark:text-gray-300"><i class="fas fa-wand-magic-sparkles mr-1 text-blue-500"></i>Доверить подбор набора ИИ (рекомендуемый минимум по ТК РФ)</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-3">
                            <input type="checkbox" name="use_search" value="1" class="h-4 w-4 rounded accent-blue-600">
                            <span class="text-xs font-medium text-gray-700 sm:text-sm dark:text-gray-300">Изучить актуальные требования в интернете (DuckDuckGo) перед написанием</span>
                        </label>
                    </div>

                    <div class="mt-5 flex flex-col gap-3 border-t border-gray-100 pt-5 sm:flex-row sm:items-center dark:border-gray-700/60">
                        <button type="submit" class="n2j-btn-process w-full sm:w-auto">
                            <i class="fas fa-file-lines mr-1.5"></i>Собрать пакет документов
                        </button>
                        <p class="n2j-hint sm:ml-auto sm:text-right">Обычно 5–15 минут — можно закрыть страницу, статус сохранится.</p>
                    </div>
                </div>
            </form>
        </div>

        <!-- Правая колонка (1/3): компаньон, на смартфонах скрыт -->
        <div class="hidden space-y-5 md:block">

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">Советы</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Можно вставить письмо или описание целиком — ИИ сам отделит поля.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Сфера и название подтягиваются из реестра по ОГРН/ИНН.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Пустые поля не страшны: воркер уточнит из открытых источников.</span></li>
                </ul>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Быстрый путь</h3>
                <p class="n2j-hint mt-1.5">Не хочется заполнять поля? Ответьте на 6 вопросов мастера — этого достаточно для точного пакета.</p>
                <a href="/docs/wizard" class="n2j-btn-secondary n2j-btn-sm mt-3 w-full">
                    <i class="fas fa-list-check mr-1.5"></i>Открыть мастер
                </a>
            </div>

            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Порядок работы</h3>
                <ol class="mt-2.5 list-decimal space-y-1.5 pl-4 text-xs text-gray-600 dark:text-gray-300">
                    <li>ИИ составляет план пакета (или берёт ваш выбор).</li>
                    <li>Пишет каждый документ: разделы, таблицы, реквизиты.</li>
                    <li>Проверяет: полнота, непротиворечивость, стиль.</li>
                    <li>Собирает файлы кодом: DOCX/PDF по ГОСТ, XLSX, ZIP.</li>
                </ol>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php echo View::factory('580/script');
