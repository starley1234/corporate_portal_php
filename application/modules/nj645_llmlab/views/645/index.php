<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * «Лаборатория LLM» (nj645_llmlab) — страница с пятью вкладками.
 *
 * Вся интерактивность — в views/645/script.php: он ходит в /llmlab/api/*.
 * Здесь только разметка и стартовые данные, чтобы страница была читаемой
 * и без JS (демонстрации честно требуют браузера).
 */

$fmt = function ($n) {
    return number_format((float) $n, 0, ',', ' ');
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="n2j-h1">Лаборатория LLM</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Пять демонстраций того, как языковая модель видит текст и выбирает следующий токен.
                Часть работает на наших глазах без внешнего ИИ, часть — на нашей базе знаний,
                которая растёт от каждого посетителя. Всё честно: где стоит оценка, а не измерение, — написано.
            </p>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <span class="rounded-full bg-blue-50 px-3 py-1 font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                <i class="fas fa-database mr-1"></i><?php echo $fmt($stats['corpus_ok']); ?> текстов в корпусе
            </span>
            <span class="rounded-full bg-emerald-50 px-3 py-1 font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                <i class="fas fa-chart-simple mr-1"></i><?php echo $fmt($stats['ngrams']); ?> n-грамм
            </span>
        </div>
    </div>

    <!-- Вкладки -->
    <div class="mt-5 flex flex-wrap gap-2" id="nj645-tabs" role="tablist">
        <?php
        $tabs = [
            'tokens'    => ['Токены и цена', 'fas fa-coins'],
            'next'      => ['Следующий токен', 'fas fa-dice'],
            'semantics' => ['Смысл и векторы', 'fas fa-chart-scatter'],
            'prompts'   => ['Промпты', 'fas fa-file-lines'],
            'knowledge' => ['Знания и квиз', 'fas fa-graduation-cap'],
        ];

        foreach ($tabs as $key => $tab): ?>
            <button type="button" data-tab="<?php echo $key; ?>"
                    class="nj645-tab n2j-chip">
                <i class="<?php echo $tab[1]; ?> mr-1.5 text-xs"></i><?php echo $tab[0]; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="mt-5 xl:flex xl:items-start xl:gap-10">
        <div class="min-w-0 flex-1">

            <!-- ============================== ТОКЕНЫ ============================== -->
            <section data-panel="tokens">
                <div class="n2j-card">
                    <label for="nj645-tok-text" class="n2j-label">
                        Текст, который «прочитает» модель
                    </label>
                    <textarea id="nj645-tok-text" rows="5"
                              class="n2j-textarea mt-2 w-full">Языковая модель предсказывает следующий токен. Токен — не всегда слово!</textarea>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span id="nj645-tok-count">—</span>
                        <span>·</span>
                        <span id="nj645-tok-ratio">—</span>
                    </div>
                </div>

                <div class="n2j-card mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="n2j-h3">Как режет мини-BPE</h2>
                        <span id="nj645-tok-vocab" class="text-xs text-gray-500 dark:text-gray-400"></span>
                    </div>
                    <p class="n2j-hint">
                        Это упрощённый токенизатор, обученный на нашем корпусе: знакомые слова и частые части слов — один токен,
                        редкие дробятся. Так видно главное: цена текста зависит от словаря модели, а не только от длины.
                    </p>
                    <div id="nj645-tok-chips" class="mt-3 flex flex-wrap gap-1.5 leading-relaxed"></div>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div class="n2j-card">
                        <h2 class="n2j-h3">Из чего сложилась оценка</h2>
                        <p class="n2j-hint">
                            Оценка по типам символов для реальных моделей (русский текст дороже латиницы: кириллица дробится мельче).
                            Точный подсчёт даёт только токенизатор конкретной модели.
                        </p>
                        <div id="nj645-tok-parts" class="mt-3 space-y-2 text-sm"></div>
                    </div>

                    <div class="n2j-card">
                        <h2 class="n2j-h3">Сколько стоит этот текст</h2>
                        <p class="n2j-hint">
                            Вход — ваш текст, выход — ответ примерно на <?php echo (int) $answer_tokens; ?> токенов. Прайс ориентировочный
                            (сверен <?php echo HTML::chars($models_updated); ?>), локальная модель бесплатна: считаем электричество, а не токены.
                        </p>
                        <div id="nj645-tok-costs" class="mt-3 space-y-2 text-sm"></div>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-blue-100 bg-blue-50/60 p-4 text-sm text-gray-700 sm:p-5 dark:border-blue-900/50 dark:bg-blue-900/20 dark:text-gray-200">
                    <i class="fas fa-lightbulb mr-1 text-amber-400"></i>
                    <strong>Зачем это знать.</strong> Цена запроса считается по токенам: сократив промпт на треть, вы на треть уменьшите счёт.
                    Русский текст стоит примерно в два раза дороже английского при том же смысле — кириллица дробится на больше кусков.
                </div>
            </section>

            <!-- =========================== СЛЕДУЮЩИЙ ТОКЕН =========================== -->
            <section data-panel="next" class="hidden">
                <div class="n2j-card">
                    <label for="nj645-ctx" class="n2j-label">
                        Начало фразы — модель продолжит её
                    </label>
                    <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                        <input id="nj645-ctx" type="text" autocomplete="off"
                               value="языковая модель предсказывает"
                               class="n2j-input w-full">
                        <button type="button" id="nj645-sample"
                                class="n2j-btn-process shrink-0">
                            <i class="fas fa-wand-magic-sparkles mr-1"></i>Продолжить
                        </button>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300">
                            Температура: <span id="nj645-temp-val" class="font-mono text-blue-600 dark:text-blue-400">0.70</span>
                            <input id="nj645-temp" type="range" min="0" max="1.5" step="0.05" value="0.7" class="mt-1 w-full accent-blue-600">
                        </label>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300">
                            Top-k: <span id="nj645-topk-val" class="font-mono text-blue-600 dark:text-blue-400">0 (все)</span>
                            <input id="nj645-topk" type="range" min="0" max="50" step="1" value="0" class="mt-1 w-full accent-blue-600">
                        </label>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300">
                            Top-p: <span id="nj645-topp-val" class="font-mono text-blue-600 dark:text-blue-400">1.00</span>
                            <input id="nj645-topp" type="range" min="0.1" max="1" step="0.05" value="1" class="mt-1 w-full accent-blue-600">
                        </label>
                    </div>
                </div>

                <div class="n2j-card mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="n2j-h3">Распределение вероятностей</h2>
                        <span id="nj645-sample-meta" class="text-xs text-gray-500 dark:text-gray-400"></span>
                    </div>
                    <div id="nj645-bars" class="mt-3 space-y-1.5"></div>
                    <div id="nj645-sample-out" class="n2j-flash n2j-flash-ok mt-4 hidden block"></div>
                    <p class="n2j-hint mt-3">
                        <i class="fas fa-circle-info mr-1"></i>
                        Здесь работает не нейросеть, а n-граммная модель платформы: она показывает те же принципы выбора
                        (распределение, температура, top-k и top-p), но проще и полностью объяснима.
                    </p>
                </div>

                <div class="n2j-card mt-4">
                    <h2 class="n2j-h3">Игра: угадайте токен, который выберет модель</h2>
                    <p class="n2j-hint">
                        Модель играет детерминированно: один и тот же контекст — один и тот же токен. Посмотрим, совпадёт ли ваш выбор.
                    </p>
                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <input id="nj645-guess" type="text" autocomplete="off" placeholder="ваш вариант: одно слово"
                               class="n2j-input w-full">
                        <button type="button" id="nj645-guess-btn"
                                class="n2j-btn-secondary shrink-0">
                            Проверить
                        </button>
                    </div>
                    <div id="nj645-guess-out" class="mt-3 hidden text-sm"></div>
                    <div class="mt-3 grid gap-3 text-xs text-gray-500 sm:grid-cols-3 dark:text-gray-400">
                        <div>Попыток: <span id="nj645-guess-total" class="font-semibold text-gray-800 dark:text-gray-200"><?php echo $fmt($guess['total']); ?></span></div>
                        <div>Совпало: <span id="nj645-guess-hit" class="font-semibold text-gray-800 dark:text-gray-200"><?php echo $guess['accuracy'] === NULL ? '—' : $guess['accuracy'].' %'; ?></span></div>
                        <div>Разных фраз: <span id="nj645-guess-ctx" class="font-semibold text-gray-800 dark:text-gray-200"><?php echo $fmt($guess['contexts']); ?></span></div>
                    </div>
                </div>

                <div class="n2j-card mt-4">
                    <h2 class="n2j-h3">Обучить модель своим текстом</h2>
                    <p class="n2j-hint">
                        Добавьте абзац — после модерации он попадёт в n-граммы и словарь мини-BPE, и продолжения станут богаче.
                        Тексты без ваших персональных данных: мы храним только сам текст и метку браузера.
                    </p>
                    <div class="mt-3 grid gap-2 sm:grid-cols-3">
                        <input id="nj645-corpus-title" type="text" placeholder="Название (необязательно)"
                               class="n2j-input sm:col-span-2 w-auto">
                        <select id="nj645-corpus-kind" class="n2j-input n2j-select w-auto">
                            <option value="prose">Проза / объяснение</option>
                            <option value="dialog">Диалог</option>
                            <option value="code">Код</option>
                        </select>
                    </div>
                    <textarea id="nj645-corpus-text" rows="4" placeholder="Вставьте связный текст на 120–6000 символов…"
                              class="n2j-textarea mt-2 w-full"></textarea>
                    <label class="mt-2 flex items-start gap-2 text-xs text-gray-600 dark:text-gray-300">
                        <input id="nj645-corpus-consent" type="checkbox" class="mt-0.5 accent-blue-600">
                        <span>Подтверждаю, что текст можно использовать для обучения демонстраций и он не содержит персональных данных.</span>
                    </label>
                    <div class="mt-3 flex items-center gap-3">
                        <button type="button" id="nj645-corpus-btn"
                                class="n2j-btn-cta">
                            Отправить на модерацию
                        </button>
                        <span id="nj645-corpus-out" class="text-xs text-gray-500 dark:text-gray-400"></span>
                    </div>
                </div>
            </section>

            <!-- ============================ СЕМАНТИКА ============================ -->
            <section data-panel="semantics" class="hidden">
                <div class="n2j-card">
                    <h2 class="n2j-h3">Близость по смыслу</h2>
                    <p class="n2j-hint">
                        Эмбеддинг превращает текст в вектор. Похожие по смыслу тексты дают близкие векторы — даже без общих слов.
                        Если на платформе подключён сервер эмбеддингов, считаем им; иначе — локальным хешем символьных n-грамм.
                    </p>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        <textarea id="nj645-sem-a" rows="3" class="n2j-textarea w-full">Как приготовить борщ: налейте воду, положите мясо и варите бульон.</textarea>
                        <textarea id="nj645-sem-b" rows="3" class="n2j-textarea w-full">Рецепт супа: сначала сварите бульон из мяса, затем добавьте овощи.</textarea>
                    </div>
                    <button type="button" id="nj645-sem-btn"
                            class="n2j-btn-process mt-3">
                        Сравнить
                    </button>
                    <div id="nj645-sem-out" class="mt-4 hidden"></div>
                </div>

                <div class="n2j-card mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="n2j-h3">Семантическая карта базы</h2>
                        <span class="text-xs text-gray-500 dark:text-gray-400">накоплено точек: <?php echo $fmt($stats['vectors']); ?></span>
                    </div>
                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <input id="nj645-map-text" type="text" placeholder="Текст, который добавим на карту"
                               class="n2j-input w-full">
                        <input id="nj645-map-label" type="text" placeholder="Подпись (необязательно)"
                               class="n2j-input w-full sm:w-56">
                        <button type="button" id="nj645-map-btn"
                                class="n2j-btn-secondary shrink-0">
                            Добавить
                        </button>
                    </div>
                    <div id="nj645-map-points" class="relative mt-4 h-72 w-full rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900"></div>
                    <div id="nj645-map-out" class="mt-3 text-xs text-gray-500 dark:text-gray-400"></div>
                </div>
            </section>

            <!-- ============================= ПРОМПТЫ ============================= -->
            <section data-panel="prompts" class="hidden">
                <div class="n2j-card">
                    <h2 class="n2j-h3">Разбор промпта по чек-листу</h2>
                    <p class="n2j-hint">
                        Детерминированная проверка: роль, задача, контекст, формат, ограничения, примеры. Без ИИ — по правилам,
                        поэтому одинаковый промпт всегда получает одинаковый разбор.
                    </p>
                    <textarea id="nj645-prompt-text" rows="6"
                              class="n2j-textarea mt-3 w-full">Напиши текст про наш новый сервис. Сделай красиво и интересно, пожалуйста!</textarea>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button type="button" id="nj645-prompt-btn"
                                class="n2j-btn-process">
                            Разобрать
                        </button>
                        <button type="button" id="nj645-prompt-save"
                                class="n2j-btn-secondary">
                            В библиотеку
                        </button>
                        <span id="nj645-prompt-out" class="text-xs text-gray-500 dark:text-gray-400"></span>
                    </div>
                    <div id="nj645-prompt-report" class="mt-4"></div>
                </div>

                <div class="n2j-card mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="n2j-h3">Прогон на реальной модели</h2>
                        <?php if ( ! $llm_ready): ?>
                            <span class="n2j-badge n2j-badge-run">ИИ на сервере не подключён</span>
                        <?php endif; ?>
                    </div>
                    <p class="n2j-hint">
                        Опишите задачу и дайте два варианта промпта — модель ответит на оба, и вы увидите разницу.
                        Прогоны уходят в очередь и хранятся в базе (без ваших персональных данных).
                    </p>
                    <textarea id="nj645-run-task" rows="3" placeholder="Задача для модели, например: придумай название для сервиса анализа состава продуктов"
                              class="n2j-textarea mt-3 w-full"></textarea>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="n2j-label">Вариант A</label>
                            <textarea id="nj645-run-a" rows="4" class="n2j-textarea mt-1 w-full font-mono">Ответь кратко.</textarea>
                        </div>
                        <div>
                            <label class="n2j-label">Вариант B (необязательно)</label>
                            <textarea id="nj645-run-b" rows="4" class="n2j-textarea mt-1 w-full font-mono"></textarea>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center gap-3">
                        <button type="button" id="nj645-run-btn" <?php echo $llm_ready ? '' : 'disabled'; ?>
                                class="n2j-btn-cta">
                            Отправить в очередь
                        </button>
                        <span id="nj645-run-out" class="text-xs text-gray-500 dark:text-gray-400"></span>
                    </div>
                    <div id="nj645-run-results" class="mt-4 space-y-4"></div>
                </div>

                <div class="n2j-card mt-4">
                    <h2 class="n2j-h3">Библиотека промптов</h2>
                    <p class="n2j-hint">
                        Шаблоны проверены на практике; голоса показывают, что работает у посетителей. Свой промпт можно добавить —
                        лучшие поднимаются вверх.
                    </p>
                    <div id="nj645-prompt-list" class="mt-3 space-y-3">
                        <?php foreach ($prompts as $prompt): ?>
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700" data-prompt="<?php echo (int) $prompt['id']; ?>">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                        <?php echo HTML::chars($prompt['title']); ?>
                                        <span class="n2j-badge n2j-badge-wait ml-1"><?php echo HTML::chars($prompt['goal']); ?></span>
                                    </div>
                                    <div class="flex items-center gap-1 text-xs">
                                        <button type="button" class="n2j-btn-secondary n2j-btn-sm nj645-vote" data-value="1" title="Полезный">👍 <span class="nj645-up"><?php echo (int) $prompt['votes_up']; ?></span></button>
                                        <button type="button" class="n2j-btn-secondary n2j-btn-sm nj645-vote" data-value="-1" title="Так себе">👎 <span class="nj645-down"><?php echo (int) $prompt['votes_down']; ?></span></button>
                                        <button type="button" class="n2j-btn-secondary n2j-btn-sm nj645-useprompt">взять</button>
                                    </div>
                                </div>
                                <pre class="nj645-body mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-words font-mono text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($prompt['body']); ?></pre>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- ============================= ЗНАНИЯ ============================= -->
            <section data-panel="knowledge" class="hidden">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <?php
                    $cards = [
                        ['Корпус', $fmt($stats['corpus_chars']).' знаков', 'одобрено текстов: '.$fmt($stats['corpus_ok']), 'fas fa-book'],
                        ['N-граммы', $fmt($stats['ngrams']), 'связок «контекст → токен»', 'fas fa-diagram-project'],
                        ['Точность квиза', $stats['quiz_accuracy'] === NULL ? '—' : $stats['quiz_accuracy'].' %', 'ответов: '.$fmt($stats['quiz_asked']), 'fas fa-graduation-cap'],
                        ['Прогоны', $fmt($stats['runs_done']).' / '.$fmt($stats['runs']), 'готовых ответов модели', 'fas fa-robot'],
                    ];

                    foreach ($cards as $card): ?>
                        <div class="n2j-card">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                <i class="<?php echo $card[3]; ?> mr-1"></i><?php echo $card[0]; ?>
                            </div>
                            <div class="mt-1 text-xl font-bold text-gray-900 dark:text-white"><?php echo $card[1]; ?></div>
                            <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo $card[2]; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="n2j-card mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="n2j-h3">Квиз: проверьте себя</h2>
                        <span id="nj645-quiz-score" class="text-xs text-gray-500 dark:text-gray-400"></span>
                    </div>
                    <p class="n2j-hint">
                        Пять терминов, четыре варианта определения. Ответы анонимно копятся в базе: видно, какие понятия путают чаще всего.
                    </p>
                    <div id="nj645-quiz" class="mt-3"></div>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div class="n2j-card">
                        <h2 class="n2j-h3">Что путают чаще всего</h2>
                        <?php if ($confusions): ?>
                            <ul class="mt-3 space-y-2 text-sm">
                                <?php foreach ($confusions as $c): ?>
                                    <li class="flex items-start justify-between gap-3">
                                        <span class="text-gray-700 dark:text-gray-200">вместо «<?php echo HTML::chars($c['term']); ?>» выбирают «<?php echo HTML::chars($c['chosen']); ?>»</span>
                                        <span class="n2j-badge n2j-badge-wait shrink-0"><?php echo (int) $c['count']; ?>×</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Пока пусто: статистика появится после первых ответов квиза.</p>
                        <?php endif; ?>
                    </div>

                    <div class="n2j-card">
                        <h2 class="n2j-h3">Где модель удивляет людей</h2>
                        <?php if ($hardest): ?>
                            <ul class="mt-3 space-y-2 text-sm">
                                <?php foreach ($hardest as $h): ?>
                                    <li class="text-gray-700 dark:text-gray-200">
                                        <span class="font-mono text-xs">«<?php echo HTML::chars(mb_substr($h['context'], 0, 60)); ?>…»</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">— модель: «<?php echo HTML::chars($h['model_token']); ?>», не угадали <?php echo (int) $h['tries'] - (int) $h['hits']; ?> из <?php echo (int) $h['tries']; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Ещё мало попыток — сыграйте во вкладке «Следующий токен».</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="n2j-card mt-4">
                    <h2 class="n2j-h3">Глоссарий</h2>
                    <p class="n2j-hint">
                        <?php echo $fmt($stats['terms']); ?> терминов с пояснениями и источниками. Термины засеяны конфигом, дальше база живёт сама.
                    </p>
                    <div class="mt-3 space-y-2" id="nj645-terms">
                        <?php foreach ($terms as $term): ?>
                            <details class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 dark:text-gray-200">
                                    <i class="fas fa-chevron-down mr-1.5 text-xs text-gray-400"></i>
                                    <?php echo HTML::chars($term['term']); ?>
                                    <span class="ml-1 text-xs font-normal text-gray-400"><?php echo HTML::chars($term['topic']); ?> · <?php echo (int) $term['difficulty']; ?>/3</span>
                                    <?php if ((int) $term['quiz_asked'] > 0): ?>
                                        <span class="ml-1 text-xs font-normal text-gray-400">верно <?php echo (int) $term['quiz_correct']; ?> из <?php echo (int) $term['quiz_asked']; ?></span>
                                    <?php endif; ?>
                                </summary>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300"><?php echo HTML::chars($term['short']); ?></p>
                                <?php if ($term['body'] !== ''): ?>
                                    <p class="n2j-hint"><?php echo HTML::chars($term['body']); ?></p>
                                <?php endif; ?>
                                <?php if ($term['source'] !== ''): ?>
                                    <p class="n2j-hint">Источник: <?php echo HTML::chars($term['source']); ?></p>
                                <?php endif; ?>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>

        <!-- ============================= ASIDE ============================= -->
        <aside class="mt-6 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
            <div class="n2j-card">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-flask mr-1.5 text-blue-500"></i>Что накапливает лаборатория</p>
                <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex justify-between gap-2"><span>Текстов корпуса одобрено</span><strong class="text-gray-900 dark:text-white"><?php echo $fmt($stats['corpus_ok']); ?></strong></li>
                    <li class="flex justify-between gap-2"><span>Ждут модерации</span><strong class="text-gray-900 dark:text-white"><?php echo $fmt($stats['corpus_pending']); ?></strong></li>
                    <li class="flex justify-between gap-2"><span>Связок n-грамм</span><strong class="text-gray-900 dark:text-white"><?php echo $fmt($stats['ngrams']); ?></strong></li>
                    <li class="flex justify-between gap-2"><span>Ответов квиза</span><strong class="text-gray-900 dark:text-white"><?php echo $fmt($stats['quiz_asked']); ?></strong></li>
                    <li class="flex justify-between gap-2"><span>Точек на карте</span><strong class="text-gray-900 dark:text-white"><?php echo $fmt($stats['vectors']); ?></strong></li>
                    <li class="flex justify-between gap-2"><span>Попыток угадать</span><strong class="text-gray-900 dark:text-white"><?php echo $fmt($stats['guesses']); ?></strong></li>
                    <li class="flex justify-between gap-2"><span>Замерено токенов</span><strong class="text-gray-900 dark:text-white"><?php echo $fmt($stats['token_tokens']); ?></strong></li>
                </ul>
                <p class="n2j-hint mt-3">
                    Замеры токенизации хранятся агрегатами: объёмы без текстов и без привязки к человеку.
                </p>
            </div>

            <div class="n2j-card">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-book-open mr-1.5 text-emerald-500"></i>Последние тексты корпуса</p>
                <?php if ($corpus): ?>
                    <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                        <?php foreach ($corpus as $item): ?>
                            <li>
                                <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($item['title'] !== '' ? $item['title'] : 'Без названия'); ?></span>
                                <span class="text-gray-400">· <?php echo $fmt($item['chars']); ?> зн. <?php echo $item['source'] === 'seed' ? '· посев' : ''; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="n2j-hint mt-3">Корпус ещё пуст — загляните на вкладку «Следующий токен».</p>
                <?php endif; ?>
            </div>

            <div class="n2j-card">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-circle-question mr-1.5 text-amber-500"></i>Почему это честно</p>
                <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li>Токенизация — мини-BPE на нашем словаре, а не токенизатор конкретной модели: показываем принцип.</li>
                    <li>Следующий токен считает n-граммная модель: те же температура и top-p, но без нейросети.</li>
                    <li>Эмбеддинги — локальный хеш, если сервер эмбеддингов не подключён; в ответе видно источник.</li>
                    <li>Разбор промпта — правила, а не ИИ: одинаковый вход даёт одинаковый разбор.</li>
                </ul>
            </div>

            <?php if ($my_runs): ?>
                <div class="n2j-card">
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-clock-rotate-left mr-1.5 text-blue-500"></i>Ваши прогоны</p>
                    <?php foreach ($my_runs as $run): ?>
                        <div class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                            <span class="font-semibold"><?php echo HTML::chars($run['variant']); ?></span>
                            <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-700"><?php echo HTML::chars($run['status']); ?></span>
                            <?php if ((string) $run['answer'] !== ''): ?>
                                <div class="mt-1 max-h-24 overflow-hidden text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr($run['answer'], 0, 220)); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php echo View::factory('645/script', [
    'limits'  => $limits,
    'stats'   => $stats,
]); ?>
