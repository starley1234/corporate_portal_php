<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Вкладка «Сборка»: простой режим управления каталогом по умолчанию + тонкая
 * настройка по раскрытию (<details>).
 *
 * Главный сценарий — две простые карточки:
 *   1. «Обслужить каталог в один клик» — актуализация из реестра MCP, проверка
 *      схем, опрос эндпоинтов, перевод на русский и векторное индексирование;
 *   2. «Найти и добавить» — целевой поиск новых серверов по теме.
 * Диагностика ИИ, ручная синхронизация отдельных источников и параметры тонкой
 * настройки аккуратно убраны во вторичные блоки и раскрывашки.
 */
$run = $run ?? NULL;
$registry = (array) ($registry ?? []);
$run_state = $run_state ?? ['wait', 'нет прогонов', 'fa-circle-stop'];
$probe_backlog = (array) ($probe_backlog ?? []);
$translate = (array) ($translate ?? []);
$semantic = (array) ($semantic ?? []);
$maintain_result = isset($maintain_result) && is_array($maintain_result) ? $maintain_result : NULL;

$probe_need = (int) ($probe_backlog['never'] ?? 0) + (int) ($probe_backlog['stale'] ?? 0);
$translate_need = (int) ($translate['pending'] ?? 0);
$semantic_need = (int) ($semantic['pending'] ?? 0);
?>
<div class="grid gap-5 md:grid-cols-3 md:items-start">
    <div class="min-w-0 space-y-5 md:col-span-2">

        <?php if ($run): ?>
            <section class="n2j-card" id="run-strip">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                            <?php echo $run_running ? 'Идёт сборка' : 'Последний прогон'; ?> #<?php echo (int) $run['id']; ?>
                            <span class="n2j-badge n2j-badge-<?php echo HTML::chars($run_state[0]); ?>">
                                <span class="n2j-dot"></span><?php echo HTML::chars($run_state[1]); ?>
                            </span>
                        </p>
                        <p class="n2j-hint mt-1">
                            <?php if ($run_running): ?>
                                <?php echo HTML::chars((string) $run['progress']); ?> — подробности на вкладке «Прогоны».
                            <?php else: ?>
                                найдено <?php echo (int) $run['found']; ?> · добавлено <?php echo (int) $run['added']; ?>
                                · обновлено <?php echo (int) $run['updated']; ?> · пропущено <?php echo (int) $run['skipped']; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a class="n2j-btn-secondary n2j-btn-sm" href="/mcphub/admin?tab=runs&run=<?php echo (int) $run['id']; ?>#run">
                        <i class="fas fa-list-check" aria-hidden="true"></i>
                        <?php echo $run_running ? 'Ход прогона' : 'Отчёт прогона'; ?>
                    </a>
                </div>
            </section>
        <?php endif; ?>

        <!-- ============ 1. Обслуживание каталога одной кнопкой ============ -->
        <section class="n2j-card" id="maintain">
            <div class="n2j-card-head flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="n2j-h2">Обслужить каталог в один клик</h2>
                    <p class="n2j-hint mt-1">
                        Проводит все операции, чтобы каталог стал работоспособным без обхода вкладок:
                        актуализацию из реестра MCP, проверку JSON Schema и адресов, опрос эндпоинтов,
                        перевод на русский и векторное индексирование.
                    </p>
                </div>
                <span class="n2j-badge n2j-badge-ok">
                    <span class="n2j-dot"></span>единый запуск
                </span>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="n2j-label">Реестр MCP</dt>
                    <dd class="mt-1 text-base font-bold tabular-nums text-gray-900 dark:text-white">
                        <?php echo (int) ($registry['from_registry'] ?? 0); ?>
                    </dd>
                    <dd class="n2j-hint mt-0.5">серверов из server.json</dd>
                </div>
                <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="n2j-label">Опрос эндпоинтов</dt>
                    <dd class="mt-1 text-base font-bold tabular-nums <?php echo $probe_need > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-300'; ?>">
                        <?php echo $probe_need > 0 ? 'ждут '.$probe_need : 'все свежие'; ?>
                    </dd>
                    <dd class="n2j-hint mt-0.5">
                        свежих: <?php echo (int) ($probe_backlog['fresh'] ?? 0); ?> из <?php echo (int) ($probe_backlog['remote'] ?? 0); ?>
                    </dd>
                </div>
                <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="n2j-label">Русский перевод</dt>
                    <dd class="mt-1 text-base font-bold tabular-nums <?php echo $translate_need > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-300'; ?>">
                        <?php echo $translate_need > 0 ? 'ждут '.$translate_need : 'всё переведено'; ?>
                    </dd>
                    <dd class="n2j-hint mt-0.5">
                        переведено: <?php echo (int) ($translate['translated'] ?? 0); ?>
                    </dd>
                </div>
                <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="n2j-label">Векторный индекс</dt>
                    <dd class="mt-1 text-base font-bold tabular-nums <?php echo $semantic_need > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-300'; ?>">
                        <?php echo empty($semantic['enabled']) ? 'выключен' : ($semantic_need > 0 ? 'ждут '.$semantic_need : 'полный'); ?>
                    </dd>
                    <dd class="n2j-hint mt-0.5">
                        в индексе: <?php echo (int) ($semantic['indexed'] ?? 0); ?>
                    </dd>
                </div>
            </dl>

            <?php if ($maintain_result !== NULL): ?>
                <div class="mt-4 rounded-xl border border-emerald-300/80 bg-emerald-50/70 px-3.5 py-2.5 text-xs text-gray-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-gray-200" role="status">
                    <p class="font-semibold text-emerald-900 dark:text-emerald-200">
                        <i class="fas fa-circle-check mr-1 text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                        Обслуживание запущено:
                    </p>
                    <p class="mt-1 leading-5">
                        проверено схем: <strong><?php echo (int) $maintain_result['schemas']; ?></strong>
                        (с ошибками: <?php echo (int) $maintain_result['broken']; ?>)
                        <?php if ((int) ($maintain_result['registry'] ?? 0) > 0): ?>
                            · синхронизация реестра: <a class="n2j-link" href="/mcphub/admin?tab=runs&run=<?php echo (int) $maintain_result['registry']; ?>#run">#<?php echo (int) $maintain_result['registry']; ?></a>
                        <?php endif; ?>
                        · опрос эндпоинтов: <strong><?php echo (int) $maintain_result['probe']; ?></strong>
                        (пачек: <?php echo (int) $maintain_result['batches']; ?>)
                        <?php if ((int) ($maintain_result['audit'] ?? 0) > 0): ?>
                            · полевое исследование агентом: <strong><?php echo (int) $maintain_result['audit']; ?></strong> серверов
                        <?php endif; ?>
                        · перевод на русский: <strong><?php echo (int) $maintain_result['translate']; ?></strong> карточек
                        <?php if ((int) ($maintain_result['translate_jobs'] ?? 0) > 0): ?>
                            (задач: <?php echo (int) $maintain_result['translate_jobs']; ?>)
                        <?php endif; ?>
                        · в векторный индекс: <strong><?php echo (int) $maintain_result['semantic']; ?></strong>
                        <?php if ((int) $maintain_result['discover'] > 0): ?>
                            · автосборка: <a class="n2j-link" href="/mcphub/admin?tab=runs&run=<?php echo (int) $maintain_result['discover']; ?>#run">#<?php echo (int) $maintain_result['discover']; ?></a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="/mcphub/admin/maintain" class="mt-4">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="custom" value="1">

                <div class="flex flex-wrap items-center gap-3">
                    <button class="n2j-btn-cta" type="submit">
                        <i class="fas fa-bolt" aria-hidden="true"></i> Сделать каталог работоспособным
                    </button>
                    <span class="n2j-hint">
                        Запустит актуализацию реестра, проверку схем, опрос эндпоинтов, перевод и индексацию.
                    </span>
                </div>

                <details class="mt-3 rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 dark:border-gray-700 dark:bg-gray-900/40">
                    <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 dark:text-gray-200">
                        <i class="fas fa-sliders mr-1.5 text-gray-400" aria-hidden="true"></i>
                        Тонкая настройка обслуживания и запуск по расписанию (cron)
                    </summary>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="n2j-label" for="maintain-probe-limit">Сколько MCP-эндпоинтов опросить сразу</label>
                            <select id="maintain-probe-limit" name="probe_limit" class="n2j-input n2j-select mt-1">
                                <option value="60">60 эндпоинтов (1 пачка)</option>
                                <option value="120">120 эндпоинтов (2×60)</option>
                                <option value="180" selected>180 эндпоинтов (3×60, рекомендуется)</option>
                                <option value="240">240 эндпоинтов (4×60)</option>
                                <option value="300">300 эндпоинтов (5×60)</option>
                                <option value="0">Не ставить опрос эндпоинтов</option>
                            </select>
                            <p class="n2j-hint mt-1">Остальные неопрошенные эндпоинты воркер доберёт цепочкой автоматически.</p>
                        </div>
                        <div class="space-y-2 text-xs text-gray-700 dark:text-gray-300">
                            <p class="n2j-label">Какие операции включить</p>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="with_registry" value="1" checked class="rounded border-gray-300 dark:dark:border-gray-600">
                                <span>Синхронизировать официальный реестр MCP (<span class="font-mono">server.json</span>), каталоги онлайн-эндпоинтов (<span class="font-mono">awesome-remote-mcp-servers</span>) и пакеты <span class="font-mono">npm</span></span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="translate_all" value="1" checked class="rounded border-gray-300 dark:dark:border-gray-600">
                                <span>Перевести все непереведённые карточки цепочкой задач</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="with_semantic" value="1" checked class="rounded border-gray-300 dark:dark:border-gray-600">
                                <span>Обновить векторный индекс (<span class="font-mono">nj115</span>)</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="with_audit" value="1" checked class="rounded border-gray-300 dark:dark:border-gray-600">
                                <span>Исследовать открытые серверы воркером-агентом (поиск «алмазов»)</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="with_discover" value="1" class="rounded border-gray-300 dark:dark:border-gray-600">
                                <span>Дособрать новые серверы по спросу агентов</span>
                            </label>
                        </div>
                    </div>
                    <p class="n2j-hint mt-3 border-t border-gray-200/80 pt-2 dark:border-gray-700">
                        Вызов из кода или cron: <span class="font-mono">Service_Nj676_McpHub::maintain()</span>
                        · HTTP-тик по расписанию: <span class="font-mono">POST /mcphub/api/tick</span>.
                    </p>
                </details>
            </form>
        </section>

        <!-- ============ 2. Найти и добавить новые карточки ============ -->
        <section class="n2j-card">
            <div class="n2j-card-head flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="n2j-h2">Найти и добавить</h2>
                    <p class="n2j-hint mt-1">Поиск новых MCP-серверов на GitHub и в открытых источниках с разбором README и проверкой схем.</p>
                </div>
                <?php if ($run_running): ?>
                    <span class="n2j-badge n2j-badge-run"><span class="n2j-dot"></span>идёт прогон #<?php echo (int) $run['id']; ?></span>
                <?php else: ?>
                    <span class="n2j-chip !cursor-default" aria-hidden="true">
                        <i class="fas fa-database text-xs" aria-hidden="true"></i><?php echo (int) $stats['servers']; ?> в каталоге
                    </span>
                <?php endif; ?>
            </div>

            <form method="post" action="/mcphub/admin/discover" class="mt-4" data-nj676-discover>
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <div class="grid gap-3 sm:grid-cols-12 sm:items-end">
                    <div class="sm:col-span-6">
                        <label class="n2j-label" for="discover-theme">Тематика <span class="font-normal text-gray-400">(необязательно)</span></label>
                        <input class="n2j-input mt-1.5" id="discover-theme" name="theme" maxlength="200"
                               placeholder="Например: базы данных, финансы, браузер, Kubernetes">
                    </div>
                    <div class="sm:col-span-3">
                        <label class="n2j-label" for="discover-n">Сколько карточек</label>
                        <input class="n2j-input mt-1.5" id="discover-n" name="n" type="number" inputmode="numeric"
                               min="1" max="<?php echo (int) $max_n; ?>" value="<?php echo (int) $default_n; ?>">
                    </div>
                    <div class="sm:col-span-3">
                        <button class="n2j-btn-cta w-full justify-center" type="submit" <?php echo $run_running ? 'disabled' : ''; ?>>
                            <span data-n2j-idle class="inline-flex items-center gap-2"><i class="fas fa-wand-magic-sparkles text-xs" aria-hidden="true"></i>Найти и добавить</span>
                            <span data-n2j-busy class="hidden inline-flex items-center gap-2"><i class="fas fa-circle-notch n2j-spin" aria-hidden="true"></i>Запускаю…</span>
                        </button>
                    </div>
                </div>
                <p class="n2j-hint mt-2">
                    Пустая тема — соберём самое заметное по всему MCP (по умолчанию <?php echo (int) $default_n; ?>, максимум <?php echo (int) $max_n; ?> карточек за раз).
                </p>
            </form>

            <?php if ( ! $run_running): ?>
                <details class="mt-3 rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 dark:border-gray-700 dark:bg-gray-900/40">
                    <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 dark:text-gray-200">
                        <i class="fas fa-globe mr-1.5 text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
                        Отдельная синхронизация с официальным реестром MCP и расписание
                    </summary>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="n2j-hint">
                                Забирает <span class="font-mono text-xs">server.json</span> из
                                <span class="font-mono text-xs">registry.modelcontextprotocol.io</span> без обращения к LLM.
                                Из реестра: <strong class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) ($registry['from_registry'] ?? 0); ?></strong>
                                из <strong class="font-semibold text-gray-700 dark:text-gray-200"><?php echo (int) ($registry['total'] ?? 0); ?></strong>
                                <?php if ( ! empty($registry['last_sync'])): ?>
                                    · синхронизация: <?php echo HTML::chars($fmt_dt($registry['last_sync'])); ?>
                                <?php endif; ?>
                            </p>
                            <p class="n2j-hint mt-1">
                                <i class="fas fa-clock mr-1" aria-hidden="true"></i>
                                <?php if ($auto_enabled): ?>
                                    Ежедневная автосборка включена (<span class="font-mono">NJ676_AUTO_DISCOVER=1</span>).
                                <?php else: ?>
                                    Ежедневная автосборка выключена (<span class="font-mono">NJ676_AUTO_DISCOVER=1</span> включает добор по расписанию).
                                <?php endif; ?>
                            </p>
                        </div>
                        <form method="post" action="/mcphub/admin/registry" class="shrink-0">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="limit" value="2500">
                            <button class="n2j-btn-secondary n2j-btn-sm" type="submit">
                                <i class="fas fa-rotate" aria-hidden="true"></i> Обновить из реестра
                            </button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <!-- ============ 3. ИИ-анализ каталога ============ -->
        <?php
        $analysis_last = isset($analysis_last) && is_array($analysis_last) ? $analysis_last : NULL;
        $analysis_active = isset($analysis_active) && is_array($analysis_active) ? $analysis_active : NULL;
        $analysis_history = (array) ($analysis_history ?? []);
        $analysis_message = isset($analysis_message) && is_array($analysis_message) ? $analysis_message : NULL;
        $analysis_report = $analysis_last ? (array) ($analysis_last['report'] ?? []) : [];
        ?>
        <section class="n2j-card" id="analysis">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="n2j-h2">ИИ-анализ каталога</h2>
                    <p class="n2j-hint mt-1">
                        Разбор состояния каталога и пустых запросов агентов: что мешает серверам отвечать и каких тем не хватает.
                    </p>
                </div>
                <?php if ($analysis_active): ?>
                    <span class="n2j-badge n2j-badge-run"><span class="n2j-dot"></span>анализ идёт</span>
                <?php endif; ?>
            </div>

            <?php if ($analysis_message AND ! empty($analysis_message['error'])): ?>
                <p class="n2j-flash n2j-flash-warn mt-3" role="status">
                    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                    <span>
                        <?php if ($analysis_message['error'] === 'analysis_active'): ?>
                            Анализ уже идёт — дождитесь его отчёта.
                        <?php else: ?>
                            Анализ не поставлен: <?php echo HTML::chars((string) $analysis_message['error']); ?>
                        <?php endif; ?>
                    </span>
                </p>
            <?php elseif ($analysis_message): ?>
                <p class="n2j-flash n2j-flash-ok mt-3" role="status">
                    <i class="fas fa-circle-check" aria-hidden="true"></i>
                    <span>
                        Анализ поставлен в очередь (№<?php echo (int) $analysis_message['id']; ?>).
                        Отчёт появится здесь через минуту.
                    </span>
                </p>
            <?php endif; ?>

            <form method="post" action="/mcphub/admin/analysis" class="mt-3 flex flex-wrap items-center gap-2">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <label class="n2j-label sr-only" for="analysis-mode">Что анализировать</label>
                <select id="analysis-mode" name="mode" class="n2j-input n2j-select w-auto">
                    <option value="both">Состояние и пробелы</option>
                    <option value="live">Только состояние каталога</option>
                    <option value="gaps">Только чего не хватает</option>
                </select>
                <button class="n2j-btn-secondary n2j-btn-sm" type="submit" <?php echo $analysis_active ? 'disabled' : ''; ?>>
                    <i class="fas fa-wand-sparkles" aria-hidden="true"></i> Проанализировать каталог
                </button>
            </form>

            <?php if ($analysis_last AND $analysis_last['status'] === 'fail'): ?>
                <p class="n2j-hint mt-3 text-rose-700 dark:dark:text-rose-300">
                    Последний анализ не удался: <?php echo HTML::chars((string) ($analysis_last['error'] ?: 'модель не дала ответ')); ?>
                </p>
            <?php elseif ($analysis_last AND $analysis_report !== []): ?>
                <?php $verdict = (string) ($analysis_report['verdict'] ?? ''); ?>
                <?php $snapshot = (array) ($analysis_last['snapshot'] ?? []); ?>
                <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span>Анализ №<?php echo (int) $analysis_last['id']; ?></span>
                        <span>·</span>
                        <span><?php echo HTML::chars((string) $analysis_last['mode_title']); ?></span>
                        <?php if ( ! empty($analysis_last['model'])): ?>
                            <span>· модель <?php echo HTML::chars((string) $analysis_last['model']); ?></span>
                        <?php endif; ?>
                        <?php if ( ! empty($analysis_last['finished_at'])): ?>
                            <span>· <?php echo HTML::chars($fmt_dt($analysis_last['finished_at'])); ?></span>
                        <?php endif; ?>
                    </p>

                    <?php if ($verdict !== ''): ?>
                        <p class="mt-2 text-sm font-semibold leading-6 text-gray-900 dark:text-white">
                            <?php echo HTML::chars($verdict); ?>
                        </p>
                    <?php endif; ?>

                    <?php if ( ! empty($analysis_report['live_notes'])): ?>
                        <p class="n2j-hint mt-2"><?php echo HTML::chars((string) $analysis_report['live_notes']); ?></p>
                    <?php endif; ?>

                    <?php if ($snapshot !== []): ?>
                        <?php $catalog = (array) ($snapshot['catalog'] ?? []); $ready = (array) ($snapshot['ready'] ?? []); ?>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                            <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800/60">
                                <dt class="n2j-label">Серверов</dt>
                                <dd class="font-bold text-gray-900 dark:text-white"><?php echo (int) ($catalog['servers'] ?? 0); ?></dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800/60">
                                <dt class="n2j-label">Готовы к вызову</dt>
                                <dd class="font-bold text-gray-900 dark:text-white"><?php echo (int) ($ready['servers'] ?? 0); ?></dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800/60">
                                <dt class="n2j-label">Инструментов</dt>
                                <dd class="font-bold text-gray-900 dark:text-white"><?php echo (int) ($ready['tools'] ?? 0); ?></dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800/60">
                                <dt class="n2j-label">Без ответа</dt>
                                <dd class="font-bold text-gray-900 dark:text-white">
                                    <?php echo (int) ($analysis_last['snapshot']['demand']['empty'] ?? count((array) ($analysis_report['gaps'] ?? []))); ?>
                                </dd>
                            </div>
                        </dl>
                    <?php endif; ?>

                    <?php if ( ! empty($analysis_report['problems'])): ?>
                        <h3 class="n2j-h3 mt-4">Что мешает</h3>
                        <ul class="mt-2 space-y-2 text-sm">
                            <?php foreach ((array) $analysis_report['problems'] as $problem): ?>
                                <li class="rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                                    <span class="font-semibold text-gray-900 dark:text-white">
                                        <?php echo HTML::chars((string) ($problem['what'] ?? '')); ?>
                                    </span>
                                    <?php if ( ! empty($problem['slug'])): ?>
                                        <a class="n2j-link ml-1 font-mono text-xs"
                                           href="/mcphub/admin?tab=cards&slug=<?php echo rawurlencode((string) $problem['slug']); ?>"><?php echo HTML::chars((string) $problem['slug']); ?></a>
                                    <?php endif; ?>
                                    <?php if ( ! empty($problem['fix'])): ?>
                                        <span class="block text-xs text-gray-600 dark:text-gray-400">
                                            Что сделать: <?php echo HTML::chars((string) $problem['fix']); ?>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ( ! empty($analysis_report['gaps'])): ?>
                        <h3 class="n2j-h3 mt-4">Чего не хватает</h3>
                        <ul class="mt-2 space-y-2 text-sm">
                            <?php foreach ((array) $analysis_report['gaps'] as $gap): ?>
                                <li class="rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                                    <span class="font-semibold text-gray-900 dark:text-white">
                                        <?php echo HTML::chars((string) ($gap['theme'] ?? '')); ?>
                                    </span>
                                    <?php if ( ! empty($gap['why'])): ?>
                                        <span class="block text-xs text-gray-600 dark:text-gray-400">
                                            <?php echo HTML::chars((string) $gap['why']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( ! empty($gap['queries'])): ?>
                                        <span class="mt-1 block font-mono text-xs text-gray-500 dark:text-gray-400">
                                            <?php echo HTML::chars(implode(' · ', array_map('strval', (array) $gap['queries']))); ?>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ( ! empty($analysis_report['collect'])): ?>
                        <h3 class="n2j-h3 mt-4">Что собрать</h3>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <?php foreach ((array) $analysis_report['collect'] as $theme): ?>
                                <form method="post" action="/mcphub/admin/discover">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <input type="hidden" name="theme" value="<?php echo HTML::chars((string) $theme); ?>">
                                    <input type="hidden" name="n" value="<?php echo (int) $default_n; ?>">
                                    <button class="n2j-chip" type="submit" title="Найти и добавить по этой теме">
                                        <i class="fas fa-plus text-xs" aria-hidden="true"></i><?php echo HTML::chars((string) $theme); ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( ! empty($analysis_report['actions'])): ?>
                        <h3 class="n2j-h3 mt-4">Что делать по приоритету</h3>
                        <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            <?php foreach ((array) $analysis_report['actions'] as $action): ?>
                                <li><?php echo HTML::chars((string) $action); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (count($analysis_history) > 1): ?>
                <details class="mt-3 rounded-xl border border-gray-200 px-3 py-2 dark:border-gray-700">
                    <summary class="cursor-pointer text-xs font-semibold text-gray-700 dark:text-gray-200">
                        История анализов (<?php echo count($analysis_history); ?>)
                    </summary>
                    <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-400">
                        <?php foreach ($analysis_history as $entry): ?>
                            <li>
                                №<?php echo (int) $entry['id']; ?>
                                · <?php echo HTML::chars($fmt_dt($entry['created_at'])); ?>
                                · <?php echo HTML::chars((string) $entry['mode']); ?>
                                · <?php echo HTML::chars((string) $entry['status']); ?>
                                <?php if ( ! empty($entry['verdict'])): ?>
                                    — <?php echo HTML::chars(mb_substr((string) $entry['verdict'], 0, 140)); ?>
                                <?php elseif ( ! empty($entry['error'])): ?>
                                    — <?php echo HTML::chars(mb_substr((string) $entry['error'], 0, 140)); ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </section>
    </div>

    <aside class="w-full space-y-4">
        <section class="n2j-card">
            <h2 class="n2j-h3">Как поддерживать каталог</h2>
            <ol class="mt-3 space-y-3 text-sm text-gray-700 dark:text-gray-300">
                <li class="flex gap-2.5">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">1</span>
                    <span class="min-w-0">
                        <span class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-bolt mr-1 text-xs text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>Сделать каталог работоспособным</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">одна кнопка синхронизирует реестр, проверяет схемы, опрашивает MCP-эндпоинты, переводит карточки и обновляет векторный индекс</span>
                    </span>
                </li>
                <li class="flex gap-2.5">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">2</span>
                    <span class="min-w-0">
                        <span class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-wand-magic-sparkles mr-1 text-xs text-gray-400" aria-hidden="true"></i>Найти и добавить по теме</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">когда нужна конкретная предметная область (например, KiCad, Telegram, базы данных)</span>
                    </span>
                </li>
                <li class="flex gap-2.5">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">3</span>
                    <span class="min-w-0">
                        <span class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-sliders mr-1 text-xs text-gray-400" aria-hidden="true"></i>Тонкая настройка — по желанию</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">ручная правка карточек, квоты, пороги векторов и детальные логи доступны в раскрывашках и соседних вкладках</span>
                    </span>
                </li>
            </ol>
        </section>

        <aside class="rounded-xl border border-amber-300/80 bg-amber-50/70 p-4 dark:border-amber-800/60 dark:bg-amber-950/40">
            <p class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                <i class="fas fa-shield-halved text-amber-600 dark:text-amber-400" aria-hidden="true"></i>
                Данные только публичные
            </p>
            <p class="mt-1 text-xs leading-5 text-gray-700 dark:text-gray-300">Автосборка читает открытые описания. Секреты, приватные адреса и закрытые схемы в каталог не попадают: их отсекает валидация сайта.</p>
        </aside>
    </aside>
</div>
