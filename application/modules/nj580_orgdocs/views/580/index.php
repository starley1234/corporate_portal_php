<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /docs — старт: минимум полей. Вводим ОГРН/ИНН → «Собрать пакет» (ИИ сам
 * подберёт состав) или переходим в подробную форму / мастер.
 * Все расширенные поля — на /docs/custom.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ( ! empty($error)): ?>
    <div class="mb-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-600 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
        <?php echo HTML::chars((string) $error); ?>
    </div>
    <?php endif; ?>

    <div class="gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Главная колонка (2/3): номер + сборка ============ -->
        <div class="n2j-card md:col-span-2">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="n2j-h3">Собрать пакет документов</h2>
            </div>

            <?php if ( ! $logged): ?>
            <div class="mt-4">
                <?php echo Gate::wall('docs', [
                    'icon'   => 'fas fa-folder-open',
                    'title'  => 'Кадровые документы — по бесплатному аккаунту',
                    'text'   => 'Пакет пишет ИИ, а файлы собирает код: DOCX и PDF по ГОСТ, таблицы XLSX, всё одним ZIP. Аккаунт нужен, чтобы генерация не расходовалась анонимами, а готовые пакеты хранились у вас.',
                    'points' => [
                        'Пакет по ОГРН/ИНН: от приказа до графика отпусков',
                        'Подбор по ситуации: ИП, ООО, приём, увольнение, отпуск',
                        'Проверка пакета отдельным проходом: полнота и непротиворечивость',
                        'Скачивание архивом: DOCX/PDF по ГОСТ + XLSX для таблиц',
                    ],
                    'stats'  => ['Beta, бесплатно', 'ОГРН/ИНН — всё, что нужно'],
                    'cta'    => 'Войти и собрать пакет',
                    'note'   => 'В каталоге модуля — десятки типов документов; рекомендованный минимум отмечен отдельно. Без аккаунта доступен просмотр состава пакетов.',
                ]); ?>
            </div>
            <?php else: ?>

            <form method="post" action="/docs" class="mt-4 space-y-4 sm:mt-5" id="docs-create">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="auto" value="1">

                <div>
                    <label for="docs-ogrn" class="n2j-label">
                        ОГРН / ОГРНИП / ИНН организации <span class="font-normal text-gray-400">— единственное, что нужно для старта</span>
                    </label>
                    <div class="mt-1.5 flex flex-col gap-2 sm:flex-row">
                        <input id="docs-ogrn" type="text" name="ogrn" required maxlength="15" inputmode="numeric" autocomplete="off"
                               class="n2j-input w-full tracking-[0.15em] sm:w-64">
                        <button type="button" id="docs-ogrn-find"
                                class="n2j-btn-secondary">
                            <i class="fas fa-magnifying-glass mr-1.5"></i>Найти
                        </button>
                    </div>
                    <p id="docs-ogrn-result" class="n2j-hint">По номеру подтянем официальные реквизиты — страница сама подскажет.</p>
                    <div id="docs-org-card" class="n2j-flash n2j-flash-ok mt-1 hidden text-xs block"></div>
                </div>

                <!-- Шаг 2: появляется после проверки номера -->
                <div id="docs-rest" class="hidden space-y-4 border-t border-gray-100 pt-4 dark:border-gray-700/60">

                    <div id="docs-name-wrap" class="hidden">
                        <label class="n2j-label">Название организации <span class="font-normal text-gray-400">(не нашлось в реестре — введите вручную)</span></label>
                        <input type="text" name="org_name" maxlength="300" placeholder="ООО «Ромашка» или ИП Иванов И.И."
                               class="n2j-input mt-1.5 w-full">
                    </div>

                    <p class="text-sm text-gray-600 sm:text-base dark:text-gray-300">
                        <i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500"></i>Состав определит ИИ — рекомендованный минимум по ТК РФ под вашу сферу и численность.
                    </p>

                    <div class="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center">
                        <button type="submit"
                                class="n2j-btn-process w-full sm:w-auto">
                            <i class="fas fa-file-lines mr-1.5"></i>Собрать пакет
                        </button>
                        <a id="docs-custom-link" href="/docs/custom"
                           class="n2j-btn-secondary w-full sm:w-auto">
                            Настроить подробно
                        </a>
                        <a href="/docs/wizard"
                           class="n2j-btn-secondary w-full sm:w-auto">
                            <i class="fas fa-list-check mr-1.5"></i>Мастер: 6 вопросов
                        </a>
                    </div>
                    <p class="n2j-hint">Обычно сборка занимает 5–15 минут — можно закрыть страницу, статус сохранится.</p>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <!-- ============ Правая колонка (1/3) ============ -->
        <div class="min-w-0 space-y-4 md:col-span-1">

            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-box-open mr-1.5 text-gray-400"></i>Что можно получить</p>
                <p class="n2j-hint">Один ZIP с готовым комплектом под вашу организацию:</p>
                <ul class="mt-2 space-y-2 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                    <li><i class="fas fa-file mr-1.5 w-3.5 text-center text-gray-400"></i>Приказы, положения, инструкции — Word и PDF</li>
                    <li><i class="fas fa-table mr-1.5 w-3.5 text-center text-gray-400"></i>Таблицы и журналы — Excel</li>
                    <li><i class="fas fa-stamp mr-1.5 w-3.5 text-center text-gray-400"></i>Оформление по ГОСТ Р 7.0.97-2016: поля, шрифты, «УТВЕРЖДАЮ»</li>
                    <li><i class="fas fa-scale-balanced mr-1.5 w-3.5 text-center text-gray-400"></i>Рекомендованный минимум по ТК РФ</li>
                </ul>
                <?php if ( ! empty($groups)): ?>
                <?php $gtotal = 0; foreach ((array) $groups as $group => $types) { $gtotal += count((array) $types); } ?>
                <p class="n2j-hint mt-3 border-t border-gray-100 pt-2">В каталоге модуля — <?php echo $gtotal; ?> типов документов: <?php $first = TRUE; foreach ((array) $groups as $group => $types): ?><?php echo $first ? '' : ', '; $first = FALSE; ?><?php echo HTML::chars((string) $group); ?> (<?php echo count((array) $types); ?>)<?php endforeach; ?>. Полный список — в <a href="/docs/custom" class="text-blue-600 hover:underline dark:text-blue-400">подробной форме</a>.</p>
                <?php endif; ?>
            </div>

            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Как это работает</p>
                <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                    <li>Вводите ОГРН/ИНН — подтягиваем официальные реквизиты.</li>
                    <li>ИИ составляет план пакета и пишет каждый документ; по желанию проверяет актуальные требования в интернете.</li>
                    <li>Отдельный проход-проверка: полнота, непротиворечивость, стиль делопроизводства.</li>
                    <li>Файлы собирает код (не ИИ): DOCX и PDF по ГОСТ, таблицы — XLSX, всё — одним ZIP.</li>
                </ol>
            </div>

            <?php if ( ! empty($my)): ?>
            <div class="n2j-card">
                <details>
                    <summary class="cursor-pointer list-none text-sm font-semibold text-blue-600 dark:text-blue-400">
                        <i class="fas fa-clock-rotate-left mr-1"></i>Мои пакеты (<?php echo count((array) $my); ?>)
                    </summary>
                    <ul class="mt-2 space-y-1.5">
                        <?php foreach ($my as $p): ?>
                        <li class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-700">
                            <a href="/docs/<?php echo HTML::chars($p['code']); ?>" class="min-w-0 flex-1 truncate text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">
                                <?php echo HTML::chars($p['org_name'] ?: $p['code']); ?>
                            </a>
                            <span class="n2j-badge n2j-badge-wait"><?php echo (int) $p['docs']; ?> док.</span>
                            <span class="n2j-badge <?php echo $p['status'] === 'done' ? 'n2j-badge-ok' : ($p['status'] === 'error' ? 'n2j-badge-err' : 'n2j-badge-info'); ?> uppercase">
                                <?php $status_ru = ['new' => 'в очереди', 'running' => 'собирается', 'done' => 'готово', 'error' => 'ошибка']; echo HTML::chars($status_ru[$p['status']] ?? $p['status']); ?>
                            </span>
                            <form method="post" action="/docs/<?php echo HTML::chars($p['code']); ?>/delete" class="ml-auto">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <button data-n2j-confirm="Удалить пакет «<?php echo HTML::chars($p['org_name'] ?: $p['code']); ?>» ПОЛНОСТЬЮ: документы, файлы, историю? Отменить нельзя." data-n2j-title="Удалить пакет?" type="submit" class="n2j-btn-danger n2j-btn-sm">Удалить</button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo View::factory('580/script');
