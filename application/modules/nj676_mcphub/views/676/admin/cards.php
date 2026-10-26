<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Вкладка «Карточки»: таблица каталога, ручная правка и проверки.
 *
 * Ручная работа — редкий путь: автосборка закрывает почти всё. Поэтому форма
 * правки спрятана в раскрывашку, а на первом плане — состояние карточек.
 */
$servers = (array) ($servers ?? []);
$calls_stats = (array) ($calls_stats ?? []);
$schema_check = isset($schema_check) && is_array($schema_check) ? $schema_check : NULL;
$stats = array_merge(['servers' => 0, 'tools' => 0, 'operational' => 0, 'fresh' => 0, 'categories' => 0], (array) ($stats ?? []));
$form = (array) ($form ?? []);
$get = function ($key, $default = '') use ($form) { return $form[$key] ?? $default; };
?>
<div class="space-y-5">
    <?php if ( ! empty($saved)): ?>
        <div class="n2j-flash n2j-flash-ok" role="status">
            <i class="fas fa-circle-check shrink-0 text-lg" aria-hidden="true"></i>
            <span><strong class="font-bold">Карточка сохранена.</strong> Правки видны в каталоге сразу.</span>
            <a class="n2j-link" href="/mcphub">Открыть каталог</a>
        </div>
    <?php endif; ?>

    <?php if ($schema_check !== NULL): ?>
        <?php $check_warn = ($schema_check['broken'] > 0 OR ($schema_check['urls'] ?? 0) > 0); ?>
        <div class="n2j-flash <?php echo $check_warn ? 'n2j-flash-warn' : 'n2j-flash-ok'; ?>" role="status">
            <i class="fas <?php echo $check_warn ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?> shrink-0 text-lg" aria-hidden="true"></i>
            <span>
                Схемы перепроверены: <strong class="font-bold"><?php echo (int) $schema_check['checked']; ?></strong> карточек.
                <?php if ($schema_check['broken'] > 0): ?>
                    С ошибками: <strong class="font-bold"><?php echo (int) $schema_check['broken']; ?></strong> — у них бейдж «схема» в таблице.
                <?php else: ?>
                    Все схемы валидны — клиенты вроде OpenCode примут <span class="font-mono">tools/list</span>.
                <?php endif; ?>
                <?php if (($schema_check['urls'] ?? 0) > 0): ?>
                    <br>Адреса не проходят проверку: <strong class="font-bold"><?php echo (int) $schema_check['urls']; ?></strong> —
                    такие карточки помечены «недоступен», вызов не обещаем.
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <?php // Проверки уже собранных карточек: сеть нужна только живой проверке. ?>
    <section class="n2j-card" id="probe">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="n2j-h2">Проверка схем и опрос MCP-эндпоинтов</h2>
                <p class="n2j-hint mt-1">
                    Быстрая проверка JSON Schema сохранённых инструментов и живой опрос удалённых серверов (<span class="font-mono">initialize</span> + <span class="font-mono">tools/list</span>).
                </p>
                <?php $probe_backlog = (array) ($probe_backlog ?? []); ?>
                <?php if ($probe_backlog !== []): ?>
                    <div class="mt-2.5 flex flex-wrap items-center gap-2 text-xs">
                        <span class="n2j-chip !cursor-default">Удалённых: <strong class="ml-1"><?php echo (int) ($probe_backlog['remote'] ?? 0); ?></strong></span>
                        <span class="n2j-chip !cursor-default">Не опрашивались: <strong class="ml-1"><?php echo (int) ($probe_backlog['never'] ?? 0); ?></strong></span>
                        <span class="n2j-chip !cursor-default">Старше <?php echo (int) ($probe_backlog['days'] ?? 7); ?> дн.: <strong class="ml-1"><?php echo (int) ($probe_backlog['stale'] ?? 0); ?></strong></span>
                        <span class="n2j-chip !cursor-default">Свежие: <strong class="ml-1"><?php echo (int) ($probe_backlog['fresh'] ?? 0); ?></strong></span>
                        <?php if ((int) ($probe_backlog['quarantined'] ?? 0) > 0): ?>
                            <span class="n2j-chip !cursor-default" title="10+ неудачных опросов подряд — повторная проверка не чаще раза в 30 дней">В карантине: <strong class="ml-1"><?php echo (int) $probe_backlog['quarantined']; ?></strong></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <form method="post" action="/mcphub/admin/schemas">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button class="n2j-btn-secondary n2j-btn-sm" type="submit" title="Проверить JSON Schema сохранённых инструментов без обращения к сети">
                        <i class="fas fa-check-double" aria-hidden="true"></i> Проверить схемы
                    </button>
                </form>
                <form method="post" action="/mcphub/admin/probe" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <label class="sr-only" for="probe-limit">Сколько эндпоинтов опросить</label>
                    <select id="probe-limit" name="limit" class="n2j-input n2j-select w-auto">
                        <option value="24">24 эндпоинта</option>
                        <option value="60" selected>60 эндпоинтов (1 пачка)</option>
                        <option value="120">120 эндпоинтов (2×60)</option>
                        <option value="240">240 эндпоинтов (4×60)</option>
                        <option value="300">До 300 эндпоинтов (5×60)</option>
                    </select>
                    <button class="n2j-btn-cta n2j-btn-sm" type="submit" title="Выполнить MCP initialize + tools/list и обновить живой статус и схемы">
                        <i class="fas fa-satellite-dish" aria-hidden="true"></i> Опросить MCP-эндпоинты
                    </button>
                </form>
            </div>
        </div>
        <details class="mt-3 rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2 dark:border-gray-700 dark:bg-gray-900/40">
            <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 dark:text-gray-200">
                <i class="fas fa-circle-info mr-1.5 text-gray-400" aria-hidden="true"></i>
                Тонкости проверки схем, параллельного опроса и карантина
            </summary>
            <p class="n2j-hint mt-2">
                Схемы проверяются локально без сети — это отлавливает некорректный <span class="font-mono">properties: []</span>,
                из-за которого строгие клиенты (OpenCode) отвергают весь <span class="font-mono">tools/list</span>.
                Опрос MCP-эндпоинтов идёт в 6 параллельных потоков пачками по 60 адресов и автоматически продолжает цепочку,
                пока в очереди есть неопрошенные серверы. Адреса с 10+ сбоями подряд переводятся в 30-дневный карантин.
            </p>
        </details>
    </section>

    <?php // Полевое исследование открытых MCP-серверов воркером-агентом (поиск алмазов). ?>
    <?php $audit_backlog = (array) ($audit_backlog ?? []); ?>
    <section class="n2j-card" id="audit">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="n2j-h2 flex items-center gap-2">
                    <i class="fas fa-gem text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                    Полевое исследование серверов ИИ-агентом (поиск алмазов)
                </h2>
                <p class="n2j-hint mt-1">
                    Воркер-агент (<span class="font-mono">nj676.audit</span>) заходит на открытые MCP-серверы, проверяет безопасность схем,
                    делает безопасный пробный вызов <span class="font-mono">tools/call</span> и составляет краткий отчёт с оценкой пользы (0–100) и безопасности (0–100).
                </p>
                <div class="mt-2.5 flex flex-wrap items-center gap-2 text-xs">
                    <span class="n2j-chip !cursor-default">Открытых серверов: <strong class="ml-1"><?php echo (int) ($audit_backlog['open_total'] ?? 0); ?></strong></span>
                    <span class="n2j-chip !cursor-default">Исследовано: <strong class="ml-1"><?php echo (int) ($audit_backlog['audited'] ?? 0); ?></strong></span>
                    <span class="n2j-chip !cursor-default">Ждут обхода: <strong class="ml-1"><?php echo (int) ($audit_backlog['unaudited'] ?? 0); ?></strong></span>
                    <span class="n2j-chip !cursor-default"><i class="fas fa-gem text-emerald-600 mr-1 dark:text-emerald-400" aria-hidden="true"></i> Алмазов: <strong class="ml-1"><?php echo (int) ($audit_backlog['gems'] ?? 0); ?></strong></span>
                    <?php if ((int) ($audit_backlog['avg_utility'] ?? 0) > 0): ?>
                        <span class="n2j-chip !cursor-default">Средняя польза: <strong class="ml-1"><?php echo (int) $audit_backlog['avg_utility']; ?>/100</strong></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php // на телефоне форма во всю ширину: shrink-0 + длинная кнопка вылезали за карточку (25.10.2026) ?>
            <form method="post" action="/mcphub/admin/audit" class="flex w-full min-w-0 flex-wrap items-center gap-2 lg:w-auto lg:shrink-0">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <label class="sr-only" for="audit-slug">Slug конкретного сервера (необязательно)</label>
                <input id="audit-slug" type="text" name="slug" value=""
                       placeholder="Slug сервера (или пачкой)…" class="n2j-input w-full min-w-0 sm:w-48">
                <label class="sr-only" for="audit-limit">Сколько серверов исследовать</label>
                <select id="audit-limit" name="limit" class="n2j-input n2j-select w-auto flex-1 sm:flex-none">
                    <option value="3">3 сервера</option>
                    <option value="6" selected>6 серверов</option>
                    <option value="12">12 серверов</option>
                </select>
                <button class="n2j-btn-cta n2j-btn-sm w-full justify-center sm:w-auto" type="submit">
                    <i class="fas fa-microscope" aria-hidden="true"></i> Исследовать серверы агентом
                </button>
            </form>
        </div>
    </section>

    <section class="n2j-card">
        <?php
        $card_q = (string) ($card_q ?? '');
        $card_filter = (string) ($card_filter ?? '');
        $filter_options = [
            '' => 'Все карточки',
            'ready' => 'Готовые к вызову',
            'gems' => 'Алмазы (is_gem)',
            'audited' => 'Исследованные агентом',
            'broken_schema' => 'Ошибка схемы',
            'untranslated' => 'Без перевода',
            'featured' => 'Избранные (featured)',
            'manual' => 'Ручная модерация',
            'unpublished' => 'Скрытые',
        ];
        ?>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="n2j-h2">Карточки <span class="n2j-count"><?php echo (int) $stats['servers']; ?></span></h2>
                <p class="n2j-hint mt-1">Автособранные карточки публикуются сразу. Тексты под ручной модерацией автосборка не перезаписывает.</p>
            </div>
            <?php if ($servers !== []): ?>
                <a class="n2j-btn-secondary n2j-btn-sm" href="/mcphub">
                    <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Весь каталог
                </a>
            <?php endif; ?>
        </div>

        <form method="get" action="/mcphub/admin" class="mt-3 flex flex-wrap items-center gap-2">
            <input type="hidden" name="tab" value="cards">
            <label class="sr-only" for="admin-card-q">Поиск по карточкам</label>
            <input id="admin-card-q" type="search" name="q" value="<?php echo HTML::chars($card_q); ?>"
                   placeholder="Slug, название, описание…" class="n2j-input w-64 max-w-full">
            <label class="sr-only" for="admin-card-filter">Фильтр состояния</label>
            <select id="admin-card-filter" name="filter" class="n2j-input n2j-select w-auto">
                <?php foreach ($filter_options as $f_key => $f_label): ?>
                    <option value="<?php echo HTML::chars($f_key); ?>" <?php echo $card_filter === $f_key ? 'selected' : ''; ?>>
                        <?php echo HTML::chars($f_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="n2j-btn-secondary n2j-btn-sm" type="submit">
                <i class="fas fa-filter" aria-hidden="true"></i> Показать
            </button>
            <?php if ($card_q !== '' OR $card_filter !== ''): ?>
                <a class="n2j-link text-xs" href="/mcphub/admin?tab=cards">Сбросить</a>
            <?php endif; ?>
        </form>

        <?php if ($servers === []): ?>
            <div class="n2j-empty mt-2">
                <span class="n2j-empty-icon"><i class="fas fa-layer-group" aria-hidden="true"></i></span>
                <p class="n2j-empty-text mt-3 font-semibold text-gray-900 dark:text-white">Карточки не найдены</p>
                <p class="n2j-hint mt-1">Сбросьте фильтр или нажмите «Найти и добавить» на вкладке сборки.</p>
            </div>
        <?php else: ?>
            <div class="mt-4 overflow-x-auto n2j-scrollbar">
                <table class="n2j-table w-full min-w-[52rem]">
                    <thead>
                        <tr>
                            <th>Сервер</th>
                            <th>Состояние</th>
                            <th class="text-right">Инструменты</th>
                            <th class="text-right">Вызовы</th>
                            <th class="text-right">Звёзды</th>
                            <th>Происхождение</th>
                            <th class="text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($servers as $server): ?>
                        <?php
                        // Подпись и цвет состояния берём у сервиса: словарь один на модуль.
                        $tone = (string) ($server['health_tone'] ?? 'gray');
                        $badge = $health_badges[$tone] ?? 'wait';
                        ?>
                        <tr>
                            <td>
                                <a class="font-semibold text-gray-900 hover:text-blue-700 hover:underline dark:text-white dark:hover:text-blue-300"
                                   href="/mcphub/<?php echo rawurlencode($server['slug']); ?>"><?php echo HTML::chars($server['display_name'] ?? $server['name']); ?></a>
                                <?php if ( ! empty($server['is_gem'])): ?>
                                    <span class="n2j-badge n2j-badge-ok ml-1" title="Алмаз каталога · польза <?php echo (int) ($server['audit_score'] ?? 0); ?>/100">
                                        <i class="fas fa-gem text-[10px]" aria-hidden="true"></i> алмаз <?php echo (int) ($server['audit_score'] ?? 0); ?>
                                    </span>
                                <?php elseif ((int) ($server['audit_score'] ?? 0) > 0): ?>
                                    <span class="n2j-badge n2j-badge-info ml-1" title="Польза <?php echo (int) $server['audit_score']; ?>/100 · безопасность <?php echo (int) ($server['safety_score'] ?? 0); ?>/100">
                                        польза <?php echo (int) $server['audit_score']; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ((float) ($server['agent_rating'] ?? 0) > 0): ?>
                                    <span class="ml-1 font-mono text-xs text-amber-600 dark:text-amber-400" title="Рейтинг агентов (<?php echo (int) ($server['agent_reviews_count'] ?? 0); ?> отз.)">★ <?php echo number_format((float) $server['agent_rating'], 1, '.', ''); ?></span>
                                <?php endif; ?>
                                <?php if ( ! empty($server['featured'])): ?>
                                    <span class="n2j-badge n2j-badge-info ml-1">featured</span>
                                <?php endif; ?>
                                <br><span class="font-mono text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($server['slug']); ?></span>
                            </td>
                            <td>
                                <?php
                                // Готовность важнее «проверен/не проверен»: она отвечает,
                                // сработает ли вызов прямо сейчас.
                                $ready_tone = [
                                    'ready' => 'ok', 'needs_key' => 'wait', 'needs_install' => 'info',
                                    'degraded' => 'run', 'stale' => 'info', 'broken' => 'err',
                                ][$server['readiness']['readiness'] ?? ''] ?? 'info';
                                ?>
                                <span class="n2j-badge n2j-badge-<?php echo $ready_tone; ?>" title="<?php echo HTML::chars((string) ($server['readiness']['note'] ?? '')); ?>">
                                    <span class="n2j-dot"></span><?php echo HTML::chars($server['readiness']['label'] ?? $server['health_label']); ?>
                                </span>
                                <?php if ($server['schema_valid'] === FALSE): ?>
                                    <br><span class="n2j-badge n2j-badge-err mt-1"><span class="n2j-dot"></span>схема</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right font-mono tabular-nums"><?php echo (int) $server['tool_count'] ?: '<span class="text-gray-300 dark:text-gray-600">—</span>'; ?></td>
                            <td class="text-right font-mono tabular-nums">
                                <?php $stat = $calls_stats[$server['slug']] ?? NULL; ?>
                                <?php if ($stat AND (int) $stat['calls'] > 0): ?>
                                    <span title="успешных: <?php echo (int) $stat['ok']; ?> из <?php echo (int) $stat['calls']; ?>">
                                        <?php echo (int) $stat['calls']; ?> · <?php echo (int) round(100 * (float) $stat['rate']); ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right font-mono tabular-nums">
                                <?php if ((int) $server['stars'] > 0): ?>
                                    <i class="fas fa-star mr-0.5 text-xs text-amber-400" aria-hidden="true"></i><?php echo (int) $server['stars']; ?>
                                <?php else: ?>
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="n2j-chip !cursor-default" aria-hidden="true"><?php echo HTML::chars($server['origin_label']); ?></span></td>
                            <td class="whitespace-nowrap text-right">
                                <?php if ( ! empty($server['is_remote']) OR trim((string) ($server['remote_url'] ?? '')) !== ''): ?>
                                    <form method="post" action="/mcphub/admin/audit" class="inline">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <input type="hidden" name="slug" value="<?php echo HTML::chars($server['slug']); ?>">
                                        <button class="n2j-btn-secondary n2j-btn-sm mr-1" type="submit" title="Направить воркер-исследователь на этот сервер">
                                            <i class="fas fa-microscope" aria-hidden="true"></i> Исследовать
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <a class="n2j-btn-secondary n2j-btn-sm" href="/mcphub/admin?tab=cards&slug=<?php echo rawurlencode($server['slug']); ?>">
                                    <i class="fas fa-pen" aria-hidden="true"></i> Править
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="n2j-card">
        <details <?php echo $get('slug') !== '' ? 'open' : ''; ?>>
            <summary class="cursor-pointer select-none">
                <span class="n2j-h2"><?php echo $get('slug') !== '' ? 'Ручная правка карточки' : 'Добавить карточку вручную'; ?></span>
                <span class="n2j-hint ml-1">(нужно редко: автосборка закрывает почти всё)</span>
            </summary>
            <form method="post" action="/mcphub/admin/save" class="mt-4 space-y-4" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $get('id', 0); ?>">
                <div class="grid gap-4 md:grid-cols-2">
                    <div><label class="n2j-label" for="mcp-slug">Slug</label><input class="n2j-input mt-1.5" id="mcp-slug" name="slug" required maxlength="100" pattern="[a-z0-9][a-z0-9-]{1,99}" value="<?php echo HTML::chars($get('slug')); ?>" <?php echo (int) $get('id', 0) > 0 ? 'readonly' : ''; ?>></div>
                    <div><label class="n2j-label" for="mcp-name">Название (оригинал)</label><input class="n2j-input mt-1.5" id="mcp-name" name="name" required maxlength="160" value="<?php echo HTML::chars($get('name')); ?>"></div>
                    <div><label class="n2j-label" for="mcp-name-ru">Название (по-русски)</label><input class="n2j-input mt-1.5" id="mcp-name-ru" name="name_ru" maxlength="160" value="<?php echo HTML::chars($get('name_ru')); ?>" placeholder="Опционально — для витрины и поиска"></div>
                    <div><label class="n2j-label" for="mcp-remote-url">Удалённый MCP-адрес (HTTPS)</label><input class="n2j-input mt-1.5" id="mcp-remote-url" name="remote_url" type="url" maxlength="1200" value="<?php echo HTML::chars($get('remote_url')); ?>" placeholder="https://example.com/mcp"></div>
                    <div><label class="n2j-label" for="mcp-repo">HTTPS-ссылка на источник</label><input class="n2j-input mt-1.5" id="mcp-repo" name="repo_url" type="url" required maxlength="1200" value="<?php echo HTML::chars($get('repo_url')); ?>"></div>
                    <div><label class="n2j-label" for="mcp-homepage">Домашняя страница</label><input class="n2j-input mt-1.5" id="mcp-homepage" name="homepage_url" type="url" maxlength="1200" value="<?php echo HTML::chars($get('homepage_url')); ?>"></div>
                    <div>
                        <label class="n2j-label" for="mcp-source">Источник</label>
                        <select class="n2j-input n2j-select mt-1.5" id="mcp-source" name="source"><?php foreach (['github' => 'GitHub', 'npm' => 'npm', 'pypi' => 'PyPI', 'manual' => 'Ручная запись', 'native' => 'N2J'] as $key => $label): ?><option value="<?php echo HTML::chars($key); ?>" <?php echo $get('source', 'github') === $key ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option><?php endforeach; ?></select>
                    </div>
                    <div>
                        <label class="n2j-label" for="mcp-transport-admin">Транспорт</label>
                        <select class="n2j-input n2j-select mt-1.5" id="mcp-transport-admin" name="transport"><?php foreach ($transports as $key => $label): ?><option value="<?php echo HTML::chars($key); ?>" <?php echo $get('transport', 'unknown') === $key ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option><?php endforeach; ?></select>
                    </div>
                    <div>
                        <label class="n2j-label" for="mcp-auth-admin">Авторизация</label>
                        <select class="n2j-input n2j-select mt-1.5" id="mcp-auth-admin" name="auth_mode"><?php foreach ($auth_modes as $key => $label): ?><option value="<?php echo HTML::chars($key); ?>" <?php echo $get('auth_mode', 'unknown') === $key ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option><?php endforeach; ?></select>
                    </div>
                    <div>
                        <label class="n2j-label" for="mcp-health-admin">Состояние</label>
                        <select class="n2j-input n2j-select mt-1.5" id="mcp-health-admin" name="health_status"><?php foreach ($health_modes as $key => $item): ?><option value="<?php echo HTML::chars($key); ?>" <?php echo $get('health_status', 'unverified') === $key ? 'selected' : ''; ?>><?php echo HTML::chars($item[0]); ?></option><?php endforeach; ?></select>
                    </div>
                    <div>
                        <label class="n2j-label" for="mcp-categories">Категории</label>
                        <input class="n2j-input mt-1.5" id="mcp-categories" name="categories" value="<?php echo HTML::chars($get('categories')); ?>" placeholder="development, web">
                        <p class="n2j-hint mt-1"><?php echo HTML::chars(implode(', ', array_keys($categories))); ?></p>
                    </div>
                    <div>
                        <label class="n2j-label" for="mcp-package-manager">Пакет для локального запуска</label>
                        <select class="n2j-input n2j-select mt-1.5" id="mcp-package-manager" name="package_manager">
                            <option value="">Не задан</option>
                            <option value="npm" <?php echo $get('package_manager') === 'npm' ? 'selected' : ''; ?>>npm (npx -y)</option>
                            <option value="pypi" <?php echo $get('package_manager') === 'pypi' ? 'selected' : ''; ?>>PyPI (uvx)</option>
                        </select>
                    </div>
                    <div><label class="n2j-label" for="mcp-package-name">Пакет с точной версией</label><input class="n2j-input mt-1.5" id="mcp-package-name" name="package_name" maxlength="200" value="<?php echo HTML::chars($get('package_name')); ?>" placeholder="@scope/package@1.2.3 или package==1.2.3"><p class="n2j-hint mt-1">Плавающая latest не выдаётся: конфигурация должна воспроизводиться.</p></div>
                    <div><label class="n2j-label" for="mcp-package-entrypoint">PyPI console-script</label><input class="n2j-input mt-1.5" id="mcp-package-entrypoint" name="package_entrypoint" maxlength="100" value="<?php echo HTML::chars($get('package_entrypoint')); ?>"></div>
                    <div><label class="n2j-label" for="mcp-env-keys">Имена env-переменных</label><input class="n2j-input mt-1.5" id="mcp-env-keys" name="env_keys" value="<?php echo HTML::chars($get('env_keys')); ?>" placeholder="API_TOKEN, WORKSPACE_ID"><p class="n2j-hint mt-1">Только имена, не значения.</p></div>
                </div>
                <div><label class="n2j-label" for="mcp-description">Описание (оригинал)</label><textarea class="n2j-textarea mt-1.5 min-h-28" id="mcp-description" name="description" maxlength="12000" required><?php echo HTML::chars($get('description')); ?></textarea></div>
                <div><label class="n2j-label" for="mcp-description-ru">Описание (по-русски)</label><textarea class="n2j-textarea mt-1.5 min-h-24" id="mcp-description-ru" name="description_ru" maxlength="12000" placeholder="Опционально — ручной перевод не перезаписывается автопереводом"><?php echo HTML::chars($get('description_ru')); ?></textarea></div>
                <div>
                    <label class="n2j-label" for="mcp-tools-json">Снимок tools/list (до 64 КБ, 100 инструментов)</label>
                    <textarea class="n2j-textarea mt-1.5 min-h-56 font-mono text-xs" id="mcp-tools-json" name="tools_json" maxlength="65536" spellcheck="false" placeholder='{"tools":[{"name":"search","description":"...","inputSchema":{"type":"object","properties":{}}}]}'><?php echo HTML::chars($get('tools_json', '[]')); ?></textarea>
                </div>
                <div class="flex flex-wrap gap-6">
                    <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input class="mt-1" type="checkbox" name="is_published" value="1" <?php echo ! empty($form['is_published']) ? 'checked' : ''; ?>> Опубликовать карточку</label>
                    <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input class="mt-1" type="checkbox" name="featured" value="1" <?php echo ! empty($form['featured']) ? 'checked' : ''; ?>> Избранная карточка (featured)</label>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button class="n2j-btn-cta" type="submit"><i class="fas fa-save" aria-hidden="true"></i> Сохранить</button>
                    <a class="n2j-btn-secondary" href="/mcphub/admin?tab=cards">Сбросить форму</a>
                </div>
            </form>
        </details>
    </section>
</div>
