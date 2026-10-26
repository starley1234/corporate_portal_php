<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Каталог MCP-серверов (nj676): публичная витрина глобального каталога.
 *
 * Порядок чтения сверху вниз: что это → поиск → живое → выбор редакции →
 * категории → весь каталог → как подключить агента. Так страница отвечает на
 * вопрос «а что тут вообще есть» ещё до того, как человек начал искать.
 */

$f = $filters;
$ready_stats = array_merge(['servers' => 0, 'tools' => 0], (array) ($ready_stats ?? []));
$audit_stats = array_merge(['gems' => 0, 'audited' => 0], (array) ($audit_stats ?? []));
$gems = isset($gems) && is_array($gems) ? $gems : [];
$mk = function (array $patch = []) use ($f) {
    $params = array_filter([
        'q' => $f['q'],
        'category' => $f['category'],
        'transport' => $f['transport'],
        'auth' => $f['auth'],
        'health' => $f['health'],
        'origin' => $f['origin'],
        'sort' => $f['sort'],
        'ready' => ! empty($f['ready']) ? '1' : '',
        'gems' => ! empty($f['gems']) ? '1' : '',
    ], static function ($v) { return $v !== '' AND $v !== NULL; });

    foreach ($patch as $key => $value)
    {
        if ($value === '' OR $value === NULL)
        {
            unset($params[$key]);
        }
        else
        {
            $params[$key] = $value;
        }
    }

    return '/mcphub'.($params ? '?'.http_build_query($params) : '');
};

$active_filters = array_filter([$f['category'], $f['transport'], $f['auth'], $f['health'], $f['origin'],
    ! empty($f['ready']) ? 'готовые' : '', ! empty($f['gems']) ? 'алмазы' : ''], static function ($v) { return $v !== ''; });
$top_categories = $facets['categories'];
arsort($top_categories);
$per_category = array_slice($top_categories, 0, 12, TRUE);

$health_badge = static function (array $server)
{
    $map = ['emerald' => 'n2j-badge-ok', 'amber' => 'n2j-badge-run', 'rose' => 'n2j-badge-err'];

    return $map[$server['health_tone']] ?? 'n2j-badge-wait';
};

$card_url = static function (array $server) { return '/mcphub/'.rawurlencode($server['slug']); };

// Готовность к вызову: человек и агент первым делом спрашивают «сработает ли
// сразу», а не «что там внутри». Пусто, если сервис не отдал readiness.
$ready_badge = static function (array $server)
{
    $map = ['ready' => 'n2j-badge-ok', 'needs_key' => 'n2j-badge-wait',
        'needs_install' => 'n2j-badge-info', 'degraded' => 'n2j-badge-run',
        'stale' => 'n2j-badge-info', 'broken' => 'n2j-badge-err'];

    return $map[$server['readiness']['readiness'] ?? ''] ?? '';
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php // ---------------------------------------------------------------- ?>
    <?php // Первый экран: что это и поиск.                                ?>
    <?php // ---------------------------------------------------------------- ?>
    <section class="n2j-hero relative overflow-hidden px-5 py-7 sm:px-8 sm:py-9">
        <div class="grid items-start gap-8 lg:grid-cols-12">
            <div class="lg:col-span-7">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="n2j-pill"><i class="fas fa-globe" aria-hidden="true"></i> Глобальный каталог MCP</span>
                    <a class="n2j-chip !py-1 !text-xs" href="/mcp">
                        <i class="fas fa-plug text-xs opacity-70" aria-hidden="true"></i> MCP этого сервиса
                    </a>
                </div>

                <h1 class="n2j-h1 mt-4 max-w-2xl">
                    Мир MCP-серверов —<br class="hidden sm:block"> через один адрес
                </h1>
                <p class="n2j-lead mt-3">
                    Веб-поиск, базы данных, погода, математика, документы, GitHub, почта — каталог собирается сам
                    из официального реестра MCP и GitHub, а агент подключается одной строкой
                    <span class="whitespace-nowrap font-mono text-xs text-gray-700 dark:text-gray-200">/mcphub/mcp</span>.
                </p>

                <form method="get" action="/mcphub" class="mt-6" role="search">
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <div class="relative flex-1">
                            <i class="fas fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true"></i>
                            <input class="n2j-input n2j-input-lg pl-11" id="mcp-query" name="q" type="search" maxlength="120"
                                   value="<?php echo HTML::chars($f['q']); ?>"
                                   placeholder="Что должен уметь сервер: погода, база данных, PDF, поиск…"
                                   aria-label="Что должен уметь сервер">
                        </div>
                        <button class="n2j-btn-process n2j-btn-lg shrink-0" type="submit">
                            <i class="fas fa-magnifying-glass" aria-hidden="true"></i> Найти сервер
                        </button>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                        <span class="n2j-hint mr-1">Часто ищут:</span>
                        <?php foreach (['погода', 'поиск в интернете', 'база данных', 'документация', 'PDF', 'почта', 'GitHub', 'математика'] as $hint): ?>
                            <a class="n2j-chip !px-2.5 !py-1 !text-xs" href="<?php echo HTML::chars($mk(['q' => $hint, 'page' => ''])); ?>">
                                <?php echo HTML::chars($hint); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>

            <div class="lg:col-span-5">
                <div class="grid grid-cols-2 gap-3">
                    <?php
                    // «Готовы к вызову» — та же цифра, что у hub_ready/hub_status:
                    // Service_Nj676_McpHub::ready_stats() и ready_servers() теперь
                    // считают по readiness(), а не по одному health_status.
                    // Раньше витрина показывала operational (8), шлюз — 10, и это
                    // читалось как вранье (тест 24.10.2026).
                    ?>
                    <?php foreach ([
                        ['серверов', $stats['servers'], 'fa-server', 'text-blue-600'],
                        ['инструментов', $stats['tools'], 'fa-screwdriver-wrench', 'text-gray-700'],
                        ['готовы к вызову', $ready_stats['servers'], 'fa-heart-pulse', 'text-emerald-600'],
                        ['из реестра MCP', $registry['from_registry'], 'fa-globe', 'text-amber-600'],
                    ] as $tile): ?>
                        <div class="rounded-xl border border-white/70 bg-white/80 px-4 py-3 shadow-xs backdrop-blur dark:border-gray-700 dark:bg-gray-900/80">
                            <p class="n2j-ds-metric text-gray-900 dark:text-white"><?php echo number_format((int) $tile[1], 0, ',', ' '); ?></p>
                            <p class="n2j-hint mt-0.5 flex items-center gap-1.5">
                                <i class="fas <?php echo $tile[2]; ?> <?php echo $tile[3]; ?> text-xs" aria-hidden="true"></i>
                                <?php echo HTML::chars($tile[0]); ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ( ! empty($ready_error)): ?>
                    <?php // Ноль из-за сбоя и ноль по делу — разные вещи: цифру не
                          // подменяем, но и молчать о сбое нельзя (24.10.2026). ?>
                    <p class="n2j-hint mt-2 text-amber-600 dark:text-amber-400">
                        <i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i>
                        Счётчик готовности не посчитался: <?php echo HTML::chars($ready_error); ?>
                    </p>
                <?php endif; ?>

                <div class="mt-3 rounded-xl border border-gray-200 bg-gray-900 p-3.5 text-gray-100 shadow-xs">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-mono text-xs uppercase tracking-wide text-gray-400">подключение агента</span>
                        <button class="n2j-btn-icon !h-7 !w-7 !text-gray-300 hover:!bg-white/10 hover:!text-white"
                                type="button" data-n2j-copy="<?php echo HTML::chars($gateway_url); ?>"
                                title="Скопировать адрес" aria-label="Скопировать адрес шлюза">
                            <i class="fas fa-copy text-xs" aria-hidden="true"></i>
                        </button>
                    </div>
                    <code class="mt-2 block break-all font-mono text-xs leading-5 text-emerald-300"><?php echo HTML::chars($gateway_url); ?></code>
                    <p class="mt-2 text-xs leading-5 text-gray-400">
                        Семь инструментов: найти, описать, вызвать, статус, настройка, обратная связь. Схемы чужих серверов не тратят контекст.
                    </p>
                    <div class="mt-2.5 flex flex-wrap items-center gap-2">
                        <button class="inline-flex items-center gap-1.5 rounded-lg border border-white/15 bg-white/10 px-2.5 py-1 text-xs font-semibold text-gray-100 transition hover:bg-white/20"
                                type="button" id="mcphub-selfcheck" aria-controls="mcphub-selfcheck-results">
                            <i class="fas fa-heart-pulse text-xs" aria-hidden="true"></i> Проверить шлюз
                        </button>
                        <span class="text-xs text-gray-400" id="mcphub-selfcheck-note">схема · поиск · живой вызов</span>
                    </div>
                    <ul class="mt-2 hidden space-y-1 text-xs leading-5" id="mcphub-selfcheck-results" aria-live="polite"></ul>
                    <a class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-blue-300 hover:underline" href="/mcp">
                        Выпустить ключ и включить инструменты N2J <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <?php // ---------------------------------------------------------------- ?>
    <?php // Живое: серверы, которые только что ответили.                  ?>
    <?php // ---------------------------------------------------------------- ?>
    <?php if ($live !== []): ?>
        <?php $live_slice = array_slice($live, 0, 8); ?>
        <section class="mt-6" aria-label="Работают прямо сейчас">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div class="flex flex-wrap items-baseline gap-2">
                    <h2 class="n2j-h3 flex items-center gap-2">
                        <span class="inline-flex h-2 w-2 animate-pulse rounded-full bg-emerald-500" aria-hidden="true"></span>
                        Отвечают прямо сейчас
                    </h2>
                    <?php if ((int) $ready_stats['servers'] > count($live_slice)): ?>
                        <span class="n2j-hint text-xs">показаны <?php echo count($live_slice); ?> из <?php echo (int) $ready_stats['servers']; ?></span>
                    <?php endif; ?>
                </div>
                <a class="n2j-link text-xs" href="<?php echo HTML::chars($mk(['ready' => '1', 'page' => ''])); ?>">Показать готовые к вызову (<?php echo (int) $ready_stats['servers']; ?>) →</a>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($live_slice as $server): ?>
                    <a class="n2j-card-hover flex items-center gap-3" href="<?php echo HTML::chars($card_url($server)); ?>">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400">
                            <i class="fas <?php echo HTML::chars($category_icons[$server['categories'][0] ?? 'other'] ?? 'fa-cube'); ?>" aria-hidden="true"></i>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($server['display_name'] ?? $server['name']); ?></span>
                            <span class="n2j-hint block truncate">
                                <?php echo (int) $server['tool_count']; ?> инстр. · <?php echo (int) $server['latency_ms']; ?> мс
                            </span>
                        </span>
                        <i class="fas fa-arrow-right text-xs text-gray-300" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php // ---------------------------------------------------------------- ?>
    <?php // Алмазы каталога: серверы, исследованные полевым ИИ-агентом.     ?>
    <?php // ---------------------------------------------------------------- ?>
    <?php if ($gems !== []): ?>
        <section class="mt-6" aria-label="Алмазы каталога">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="n2j-h2 flex items-center gap-2">
                        <i class="fas fa-gem text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                        Алмазы каталога · проверено ИИ-агентом
                    </h2>
                    <p class="n2j-hint mt-1">
                        Открытые серверы, которые полевой воркер-агент вызвал вживую и высоко оценил за практическую пользу и безопасность.
                    </p>
                </div>
                <a class="n2j-btn-secondary n2j-btn-sm" href="<?php echo HTML::chars($mk(['gems' => '1', 'sort' => 'utility', 'page' => ''])); ?>">
                    <i class="fas fa-gem text-xs text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i> Все алмазы<?php echo (int) $audit_stats['gems'] > 0 ? ' ('.(int) $audit_stats['gems'].')' : ''; ?>
                </a>
            </div>

            <div class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach (array_slice($gems, 0, 3) as $gem_srv): ?>
                    <article class="n2j-card relative flex h-full flex-col overflow-hidden">
                        <span class="absolute inset-x-0 top-0 h-1 bg-emerald-500" aria-hidden="true"></span>
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400">
                                <i class="fas fa-gem" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="n2j-h3 truncate">
                                    <a class="hover:underline" href="<?php echo HTML::chars($card_url($gem_srv)); ?>"><?php echo HTML::chars($gem_srv['display_name'] ?? $gem_srv['name']); ?></a>
                                </h3>
                                <p class="n2j-hint truncate"><?php echo HTML::chars($gem_srv['slug']); ?></p>
                            </div>
                            <span class="n2j-badge n2j-badge-ok">
                                польза <?php echo (int) ($gem_srv['audit_score'] ?? 0); ?>/100
                            </span>
                        </div>

                        <p class="mt-3 line-clamp-3 flex-1 text-sm leading-6 text-gray-700 dark:text-gray-200">
                            <?php echo HTML::chars(($gem_srv['audit_summary'] ?? '') !== '' ? $gem_srv['audit_summary'] : ($gem_srv['display_description'] ?? $gem_srv['description'])); ?>
                        </p>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <span class="n2j-chip !py-0.5"><i class="fas fa-shield-halved text-xs text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i> безопасность <?php echo (int) ($gem_srv['safety_score'] ?? 0); ?>/100</span>
                            <?php if (($gem_srv['agent_rating'] ?? NULL) !== NULL): ?>
                                <span class="n2j-chip !py-0.5"><i class="fas fa-star text-xs text-amber-500" aria-hidden="true"></i> агенты <?php echo number_format((float) $gem_srv['agent_rating'], 1, '.', ''); ?>/5 (<?php echo (int) ($gem_srv['agent_reviews_count'] ?? 0); ?>)</span>
                            <?php endif; ?>
                            <?php if (($gem_srv['tool_count'] ?? 0) > 0): ?>
                                <span class="n2j-chip !py-0.5"><?php echo (int) $gem_srv['tool_count']; ?> инстр.</span>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3 dark:border-gray-800">
                            <span class="n2j-hint">доверие <?php echo (int) $gem_srv['trust']; ?> / 100</span>
                            <a class="n2j-link text-sm" href="<?php echo HTML::chars($card_url($gem_srv)); ?>">
                                Отчёт агента <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php // ---------------------------------------------------------------- ?>
    <?php // Выбор редакции.                                                ?>
    <?php // ---------------------------------------------------------------- ?>
    <?php if ($featured !== []): ?>
        <section class="mt-6" aria-label="Выбор редакции">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="n2j-h2">С чего начать</h2>
                    <p class="n2j-hint mt-1">Серверы, которые полезны в первый же день и не требуют возни с установкой.</p>
                </div>
                <a class="n2j-btn-secondary n2j-btn-sm" href="<?php echo HTML::chars($mk(['origin' => 'manual', 'page' => ''])); ?>">
                    <i class="fas fa-star text-xs" aria-hidden="true"></i> Всё проверенное
                </a>
            </div>

            <div class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($featured as $server): ?>
                    <article class="n2j-card relative flex h-full flex-col overflow-hidden">
                        <span class="absolute inset-x-0 top-0 h-1 bg-blue-500" aria-hidden="true"></span>
                        <div class="flex items-start gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-400">
                                <i class="fas <?php echo HTML::chars($category_icons[$server['categories'][0] ?? 'other'] ?? 'fa-cube'); ?> text-lg" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="n2j-h3 truncate">
                                    <a class="hover:underline" href="<?php echo HTML::chars($card_url($server)); ?>"><?php echo HTML::chars($server['display_name'] ?? $server['name']); ?></a>
                                </h3>
                                <p class="n2j-hint truncate">
                                    <?php // Перевод не прячет оригинал: имя проекта — факт из репозитория. ?>
                                    <?php if ( ! empty($server['has_ru']) AND ($server['name_ru'] ?? '') !== '' AND $server['name_ru'] !== ($server['name'] ?? '')): ?>
                                        <?php echo HTML::chars($server['name']); ?> ·
                                    <?php endif; ?>
                                    <?php echo HTML::chars(($server['owner_login'] ?? '') !== '' ? $server['owner_login'].' · '.$server['slug'] : $server['slug']); ?>
                                </p>
                            </div>
                            <span class="n2j-badge <?php echo $health_badge($server); ?>">
                                <span class="n2j-dot"></span><?php echo HTML::chars($server['health_label']); ?>
                            </span>
                        </div>

                        <p class="mt-3 line-clamp-3 flex-1 text-sm leading-6 text-gray-700 dark:text-gray-200"><?php echo HTML::chars($server['display_description'] ?? $server['description']); ?></p>
                        <?php if ( ! empty($server['tools'])): ?>
                            <div class="mt-3 flex flex-wrap gap-1">
                                <?php foreach (array_slice($server['tools'], 0, 3) as $known): ?>
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300"><?php echo HTML::chars((string) ($known['name'] ?? '')); ?></span>
                                <?php endforeach; ?>
                                <?php if (count($server['tools']) > 3): ?>
                                    <span class="n2j-hint text-xs self-center">+<?php echo count($server['tools']) - 3; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <?php if ($server['auth_mode'] === 'none'): ?>
                                <span class="n2j-chip !py-0.5"><i class="fas fa-key text-xs text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i> без ключа</span>
                            <?php elseif ($server['auth_mode'] !== 'unknown'): ?>
                                <span class="n2j-chip !py-0.5"><?php echo HTML::chars($server['auth_label']); ?></span>
                            <?php endif; ?>
                            <?php if (($server['readiness']['label'] ?? '') !== ''): ?>
                                <span class="n2j-chip !py-0.5" title="<?php echo HTML::chars((string) ($server['readiness']['note'] ?? '')); ?>">
                                    <i class="fas <?php echo $ready_badge($server) === 'n2j-badge-ok' ? 'fa-bolt text-xs text-emerald-600' : 'fa-circle-info text-xs opacity-60'; ?>" aria-hidden="true"></i>
                                    <?php echo HTML::chars($server['readiness']['label']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (($server['schema_valid'] ?? NULL) === FALSE): ?>
                                <span class="n2j-chip !py-0.5" title="Схема инструментов не проходит проверку JSON Schema">
                                    <i class="fas fa-triangle-exclamation text-xs text-rose-500" aria-hidden="true"></i> схема
                                </span>
                            <?php endif; ?>
                            <?php if ($server['transport'] !== 'unknown'): ?>
                                <span class="n2j-chip !py-0.5"><?php echo HTML::chars($server['transport_label']); ?></span>
                            <?php endif; ?>
                            <?php if ($server['stars'] > 0): ?>
                                <span class="n2j-chip !py-0.5"><i class="fas fa-star text-xs text-amber-500" aria-hidden="true"></i> <?php echo number_format($server['stars'], 0, ',', ' '); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3 dark:border-gray-800">
                            <span class="n2j-hint">доверие <?php echo (int) $server['trust']; ?> / 100</span>
                            <a class="n2j-link text-sm" href="<?php echo HTML::chars($card_url($server)); ?>">
                                Открыть <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php // ---------------------------------------------------------------- ?>
    <?php // Категории-плитки.                                              ?>
    <?php // ---------------------------------------------------------------- ?>
    <?php if ($per_category !== []): ?>
        <section class="mt-6" aria-label="Категории">
            <h2 class="n2j-h3">Куда идти дальше</h2>
            <div class="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-6">
                <?php foreach ($per_category as $key => $count): if ( ! isset($categories[$key])) { continue; } ?>
                    <a class="n2j-tile <?php echo $f['category'] === $key ? 'is-on' : ''; ?>"
                       href="<?php echo HTML::chars($mk(['category' => $key, 'page' => ''])); ?>">
                        <i class="fas <?php echo HTML::chars($category_icons[$key] ?? 'fa-cube'); ?>" aria-hidden="true"></i>
                        <span class="n2j-tile-title"><?php echo HTML::chars($categories[$key]); ?></span>
                        <span class="n2j-tile-meta"><?php echo (int) $count; ?> серв.</span>
                    </a>
                <?php endforeach; ?>
                <a class="n2j-tile" href="<?php echo HTML::chars($mk(['category' => '', 'page' => ''])); ?>">
                    <i class="fas fa-border-all" aria-hidden="true"></i>
                    <span class="n2j-tile-title">Все категории</span>
                    <span class="n2j-tile-meta"><?php echo (int) $stats['servers']; ?> серв.</span>
                </a>
            </div>
        </section>
    <?php endif; ?>

    <?php // ---------------------------------------------------------------- ?>
    <?php // Каталог: фильтры и карточки.                                    ?>
    <?php // ---------------------------------------------------------------- ?>
    <section class="mt-8" aria-label="Каталог серверов">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="n2j-h2">
                    <?php echo $f['q'] !== '' ? 'По запросу «'.HTML::chars($f['q']).'»' : 'Весь каталог'; ?>
                </h2>
                <p class="n2j-hint mt-1">
                    <?php echo number_format((int) $total, 0, ',', ' '); ?>
                    <?php echo $total === 1 ? 'сервер' : 'серверов'; ?>
                    <?php if ($pages > 1): ?> · страница <?php echo (int) $page; ?> из <?php echo (int) $pages; ?><?php endif; ?>
                    <?php if ($registry['last_sync'] !== ''): ?>
                        · реестр обновлён <?php echo HTML::chars(date('d.m.Y', strtotime($registry['last_sync']))); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <?php // Главный вопрос человека: «это вообще работает?» — отвечаем одним кликом. ?>
                <a class="n2j-chip <?php echo ! empty($f['ready']) ? 'is-on' : ''; ?>"
                   href="<?php echo HTML::chars(! empty($f['ready']) ? $mk(['ready' => '', 'page' => '']) : $mk(['ready' => '1', 'page' => ''])); ?>"
                   title="Без ключа, без установки, проверен вживую">
                    <i class="fas fa-bolt text-xs" aria-hidden="true"></i>
                    <?php echo ! empty($f['ready']) ? 'Только готовые' : 'Готовые к вызову'; ?>
                    <?php if ((int) ($ready_stats['servers'] ?? 0) > 0): ?>
                        <span class="n2j-count"><?php echo (int) $ready_stats['servers']; ?></span>
                    <?php endif; ?>
                </a>
                <a class="n2j-chip <?php echo ! empty($f['gems']) ? 'is-on' : ''; ?>"
                   href="<?php echo HTML::chars(! empty($f['gems']) ? $mk(['gems' => '', 'page' => '']) : $mk(['gems' => '1', 'sort' => 'utility', 'page' => ''])); ?>"
                   title="Исследованы полевым ИИ-агентом и рекомендованы за высокую пользу и безопасность">
                    <i class="fas fa-gem text-xs text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                    <?php echo ! empty($f['gems']) ? 'Только алмазы' : 'Алмазы'; ?>
                    <?php if ((int) ($audit_stats['gems'] ?? 0) > 0): ?>
                        <span class="n2j-count"><?php echo (int) $audit_stats['gems']; ?></span>
                    <?php endif; ?>
                </a>
                <div class="n2j-seg" role="group" aria-label="Сортировка">
                    <?php foreach (['popular' => 'Популярные', 'utility' => 'По пользе', 'agent_rating' => 'По отзывам', 'new' => 'Новые', 'name' => 'По алфавиту'] as $key => $label): ?>
                        <a class="n2j-seg-item <?php echo $f['sort'] === $key ? 'is-active' : ''; ?>"
                           href="<?php echo HTML::chars($mk(['sort' => $key === 'popular' ? '' : $key, 'page' => ''])); ?>"><?php echo HTML::chars($label); ?></a>
                    <?php endforeach; ?>
                </div>
                <a class="n2j-btn-secondary n2j-btn-sm" href="/mcphub/api/search?<?php echo HTML::chars(http_build_query(array_filter(['q' => $f['q'], 'category' => $f['category']]))); ?>">
                    <i class="fas fa-code" aria-hidden="true"></i> JSON
                </a>
            </div>
        </div>

        <details class="n2j-card mt-3 !py-3" <?php echo $active_filters ? 'open' : ''; ?>>
            <summary class="cursor-pointer select-none text-sm font-semibold text-gray-700 dark:text-gray-200">
                Точные фильтры
                <?php if ($active_filters): ?>
                    <span class="n2j-count ml-1"><?php echo count($active_filters); ?></span>
                <?php endif; ?>
            </summary>
            <form method="get" action="/mcphub" class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <input type="hidden" name="q" value="<?php echo HTML::chars($f['q']); ?>">
                <?php if ( ! empty($f['ready'])): ?>
                    <input type="hidden" name="ready" value="1">
                <?php endif; ?>
                <div>
                    <label class="n2j-label" for="mcp-category">Категория</label>
                    <select class="n2j-input n2j-select mt-1.5" id="mcp-category" name="category">
                        <option value="">Любая</option>
                        <?php foreach ($categories as $key => $label): ?>
                            <option value="<?php echo HTML::chars($key); ?>" <?php echo $f['category'] === $key ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="n2j-label" for="mcp-transport">Транспорт</label>
                    <select class="n2j-input n2j-select mt-1.5" id="mcp-transport" name="transport">
                        <option value="">Любой</option>
                        <?php foreach ($transports as $key => $label): if ($key === 'unknown') { continue; } ?>
                            <option value="<?php echo HTML::chars($key); ?>" <?php echo $f['transport'] === $key ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="n2j-label" for="mcp-auth">Авторизация</label>
                    <select class="n2j-input n2j-select mt-1.5" id="mcp-auth" name="auth">
                        <option value="">Любая</option>
                        <?php foreach ($auth_modes as $key => $label): if ($key === 'unknown') { continue; } ?>
                            <option value="<?php echo HTML::chars($key); ?>" <?php echo $f['auth'] === $key ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="n2j-label" for="mcp-health">Состояние</label>
                    <select class="n2j-input n2j-select mt-1.5" id="mcp-health" name="health">
                        <option value="">Любое</option>
                        <?php foreach ($health_modes as $key => $item): ?>
                            <option value="<?php echo HTML::chars($key); ?>" <?php echo $f['health'] === $key ? 'selected' : ''; ?>><?php echo HTML::chars($item[0]); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="n2j-label" for="mcp-origin">Происхождение</label>
                    <select class="n2j-input n2j-select mt-1.5" id="mcp-origin" name="origin">
                        <option value="">Любое</option>
                        <option value="manual" <?php echo $f['origin'] === 'manual' ? 'selected' : ''; ?>>Проверено модератором</option>
                        <option value="auto" <?php echo $f['origin'] === 'auto' ? 'selected' : ''; ?>>Автосборка</option>
                        <option value="registry" <?php echo $f['origin'] === 'registry' ? 'selected' : ''; ?>>Официальный реестр</option>
                    </select>
                </div>
                <div class="flex items-end gap-2 sm:col-span-2 xl:col-span-5">
                    <button class="n2j-btn-process n2j-btn-sm" type="submit"><i class="fas fa-filter" aria-hidden="true"></i> Применить</button>
                    <?php if ($active_filters OR $f['q'] !== ''): ?>
                        <a class="n2j-btn-secondary n2j-btn-sm" href="/mcphub">Сбросить</a>
                    <?php endif; ?>
                </div>
            </form>
        </details>

        <?php if ($servers === []): ?>
            <div class="n2j-card mt-3">
                <div class="n2j-empty text-center">
                    <div class="n2j-empty-icon"><i class="fas fa-magnifying-glass" aria-hidden="true"></i></div>
                    <p class="n2j-empty-text mt-3 font-semibold">Ничего не нашлось</p>
                    <p class="n2j-hint mt-1">
                        <?php if ( ! empty($f['ready'])): ?>
                            Среди готовых к вызову совпадений нет<?php echo $f['q'] !== '' ? ' по запросу «'.HTML::chars($f['q']).'»' : ''; ?>.
                            Снимите галочку — в каталоге есть карточки, которым нужен ключ или установка.
                        <?php else: ?>
                            Попробуйте слова проще: «поиск», «погода», «база данных», «документы».
                        <?php endif; ?>
                        <?php if ($stats['servers'] === 0): ?>Каталог пока пуст — администратор заполняет его одной кнопкой.<?php endif; ?>
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <a class="n2j-btn-secondary" href="<?php echo HTML::chars(! empty($f['ready']) ? $mk(['ready' => '', 'page' => '']) : '/mcphub'); ?>"><?php echo ! empty($f['ready']) ? 'Показать все' : 'Сбросить фильтры'; ?></a>
                        <a class="n2j-btn-secondary" href="/mcp">Подключить агента</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($servers as $server):
                    $icon = $category_icons[$server['categories'][0] ?? 'other'] ?? 'fa-cube';
                ?>
                    <article class="n2j-card-hover flex h-full flex-col">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gray-50 text-gray-600 dark:bg-gray-800/60 dark:text-gray-300">
                                <i class="fas <?php echo HTML::chars($icon); ?>" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="n2j-h3 truncate">
                                    <a class="hover:underline" href="<?php echo HTML::chars($card_url($server)); ?>"><?php echo HTML::chars($server['display_name'] ?? $server['name']); ?></a>
                                </h3>
                                <p class="n2j-hint truncate">
                                    <?php echo HTML::chars($server['slug']); ?>
                                    <?php if ( ! empty($server['has_ru']) AND ($server['name_ru'] ?? '') !== '' AND $server['name_ru'] !== ($server['name'] ?? '')): ?>
                                        · <?php echo HTML::chars($server['name']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <span class="n2j-badge <?php echo $health_badge($server); ?>" title="<?php echo HTML::chars($server['health_note'] ?? ''); ?>">
                                <span class="n2j-dot"></span><?php echo HTML::chars($server['health_label']); ?>
                            </span>
                        </div>

                        <p class="mt-3 line-clamp-3 flex-1 text-sm leading-6 text-gray-700 dark:text-gray-200"><?php echo HTML::chars($server['display_description'] ?? $server['description']); ?></p>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <?php if ($server['auth_mode'] === 'none'): ?>
                                <span class="n2j-chip !py-0.5"><i class="fas fa-key text-xs text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i> без ключа</span>
                            <?php elseif ($server['auth_mode'] !== 'unknown'): ?>
                                <span class="n2j-chip !py-0.5"><?php echo HTML::chars($server['auth_label']); ?></span>
                            <?php endif; ?>
                            <?php if (($server['readiness']['label'] ?? '') !== ''): ?>
                                <span class="n2j-chip !py-0.5" title="<?php echo HTML::chars((string) ($server['readiness']['note'] ?? '')); ?>">
                                    <i class="fas <?php echo $ready_badge($server) === 'n2j-badge-ok' ? 'fa-bolt text-xs text-emerald-600' : 'fa-circle-info text-xs opacity-60'; ?>" aria-hidden="true"></i>
                                    <?php echo HTML::chars($server['readiness']['label']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (($server['schema_valid'] ?? NULL) === FALSE): ?>
                                <span class="n2j-chip !py-0.5" title="Схема инструментов не проходит проверку JSON Schema">
                                    <i class="fas fa-triangle-exclamation text-xs text-rose-500" aria-hidden="true"></i> схема
                                </span>
                            <?php endif; ?>
                            <?php if ($server['is_remote']): ?>
                                <span class="n2j-chip !py-0.5"><i class="fas fa-link text-xs opacity-60" aria-hidden="true"></i> по ссылке</span>
                            <?php elseif ($server['package_hint'] !== ''): ?>
                                <span class="n2j-chip !py-0.5">одна команда</span>
                            <?php endif; ?>
                            <?php if ($server['tool_count'] > 0): ?>
                                <span class="n2j-chip !py-0.5"><?php echo (int) $server['tool_count']; ?> инстр.</span>
                            <?php endif; ?>
                            <?php if (isset($categories[$server['categories'][0] ?? ''])): ?>
                                <span class="n2j-chip !py-0.5"><?php echo HTML::chars($categories[$server['categories'][0]]); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty($server['is_gem'])): ?>
                                <span class="n2j-chip !py-0.5" title="<?php echo HTML::chars((string) ($server['audit_summary'] ?? 'Проверен полевым ИИ-агентом')); ?>">
                                    <i class="fas fa-gem text-xs text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                                    алмаз · польза <?php echo (int) $server['audit_score']; ?>/100
                                </span>
                            <?php elseif ((int) ($server['audit_score'] ?? 0) > 0): ?>
                                <span class="n2j-chip !py-0.5" title="<?php echo HTML::chars((string) ($server['audit_summary'] ?? 'Проверен полевым ИИ-агентом')); ?>">
                                    <i class="fas fa-microscope text-xs text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
                                    польза <?php echo (int) $server['audit_score']; ?>/100
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 flex items-center justify-between gap-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <?php if (($server['agent_rating'] ?? NULL) !== NULL): ?>
                                    <span title="Рейтинг на основании отзывов ИИ-агентов" class="font-medium text-gray-700 dark:text-gray-200">
                                        <i class="fas fa-robot text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                                        ★ <?php echo number_format((float) $server['agent_rating'], 1, '.', ''); ?>
                                        <span class="text-gray-400">(<?php echo (int) ($server['agent_reviews_count'] ?? 0); ?>)</span>
                                    </span>
                                <?php endif; ?>
                                <?php if ($server['stars'] > 0): ?>
                                    <span title="Звёзды на GitHub"><i class="fas fa-star text-amber-400" aria-hidden="true"></i> <?php echo number_format($server['stars'], 0, ',', ' '); ?></span>
                                <?php endif; ?>
                                <span title="Оценка доверия каталога"><?php echo (int) $server['trust']; ?> / 100</span>
                            </div>
                            <a class="n2j-link text-sm" href="<?php echo HTML::chars($card_url($server)); ?>">
                                Открыть <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php echo View::factory('partials/pagination', [
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'per' => $per,
                'per_options' => [12, 24, 48, 60],
                'url_base' => '/mcphub',
                'params' => array_filter([
                    'q' => $f['q'], 'category' => $f['category'], 'transport' => $f['transport'],
                    'auth' => $f['auth'], 'health' => $f['health'], 'origin' => $f['origin'], 'sort' => $f['sort'],
                    'ready' => ! empty($f['ready']) ? '1' : '',
                    'gems' => ! empty($f['gems']) ? '1' : '',
                ], static function ($v) { return $v !== ''; }),
                'anchor' => '',
            ]); ?>
        <?php endif; ?>
    </section>

    <?php // ---------------------------------------------------------------- ?>
    <?php // Как это работает.                                                ?>
    <?php // ---------------------------------------------------------------- ?>
    <section class="mt-8 grid gap-4 lg:grid-cols-3" aria-label="Как подключить">
        <div class="n2j-card lg:col-span-2">
            <h2 class="n2j-h3">Один адрес вместо двадцати конфигов</h2>
            <p class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-200">
                Шлюз <span class="font-mono text-xs">/mcphub/mcp</span> отдаёт агенту семь корневых инструментов.
                Схемы остальных серверов подгружаются по требованию — контекст не расходуется на сотни описаний.
                Поиск понимает смысл задачи: каталог индексируется векторами эмбеддинг-сервера платформы,
                поэтому «отправить письмо» находит почтовый сервер, а не набор иконок с конвертом.
                Работает с любым MCP-клиентом: Claude, Cursor, Windsurf, Codex, n8n, LangChain.
            </p>
            <div class="mt-3 overflow-x-auto rounded-lg bg-gray-900 p-3.5">
<pre class="font-mono text-xs leading-6 text-gray-100">curl -s -X POST <?php echo HTML::chars($gateway_url); ?> \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{
        "name":"hub_search_tools","arguments":{"query":"погода"}}}'</pre>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <a class="n2j-btn-secondary n2j-btn-sm" href="/mcp">Ключ и инструменты N2J</a>
                <a class="n2j-btn-secondary n2j-btn-sm" href="/mcphub/api/search?q=погода">JSON-поиск</a>
            </div>
            <?php /* Честность: запросы к шлюзу сохраняются и влияют на каталог. */ ?>
            <p class="n2j-hint mt-3">
                <i class="fas fa-arrows-rotate mr-1" aria-hidden="true"></i>
                Запросы к шлюзу сохраняются обезличенно (текст запроса и нашёлся ли ответ):
                по ним каталог сам добирает серверы, которых не хватает агентам.
            </p>
        </div>

        <aside class="n2j-card">
            <h2 class="n2j-h3">Как читать карточку</h2>
            <dl class="mt-3 space-y-2.5 text-sm text-gray-700 dark:text-gray-200">
                <div>
                    <dt class="n2j-label">Состояние</dt>
                    <dd>живая проверка <span class="font-mono text-xs">tools/list</span> или честное «не проверен»</dd>
                </div>
                <div>
                    <dt class="n2j-label">Доверие</dt>
                    <dd>звёзды, свежесть, лицензия, разбор схем инструментов</dd>
                </div>
                <div>
                    <dt class="n2j-label">Происхождение</dt>
                    <dd>официальный реестр MCP, автосборка из GitHub или ручная модерация</dd>
                </div>
                <div>
                    <dt class="n2j-label">Подключение</dt>
                    <dd>«по ссылке» — сразу в агента; «одна команда» — локальный запуск</dd>
                </div>
            </dl>
        </aside>
    </section>
<script>
// Самопроверка шлюза: человек не должен ставить MCP-клиент, чтобы узнать,
// работает ли эндпоинт. Кнопка дергает /mcphub/api/selfcheck и показывает
// каждый шаг с временем — так видно, что именно сломалось.
(function () {
    var button = document.getElementById('mcphub-selfcheck');
    var note = document.getElementById('mcphub-selfcheck-note');
    var list = document.getElementById('mcphub-selfcheck-results');
    if (!button || !list) { return; }

    button.addEventListener('click', function () {
        button.disabled = true;
        note.textContent = 'проверяю…';
        list.className = 'mt-2 space-y-1 text-xs leading-5';
        list.innerHTML = '';

        fetch('/mcphub/api/selfcheck?http=1', {headers: {'Accept': 'application/json'}})
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var steps = data.steps || [];
                steps.forEach(function (step) {
                    var row = document.createElement('li');
                    row.className = 'flex gap-1.5 ' + (step.ok ? 'text-emerald-300' : 'text-rose-300');
                    var mark = document.createElement('i');
                    mark.className = 'fas ' + (step.ok ? 'fa-circle-check' : 'fa-circle-xmark') + ' mt-1 text-xs';
                    mark.setAttribute('aria-hidden', 'true');
                    var text = document.createElement('span');
                    text.className = 'text-gray-300';
                    text.textContent = step.title + ' — ' + step.detail + ' (' + step.ms + ' мс)';
                    row.appendChild(mark);
                    row.appendChild(text);
                    list.appendChild(row);
                });
                var okCount = steps.filter(function (s) { return s.ok; }).length;
                note.textContent = data.ok
                    ? 'всё в порядке: ' + okCount + '/' + steps.length + ' за ' + data.ms + ' мс'
                    : 'проблемы: ' + okCount + '/' + steps.length;
                note.className = 'text-xs ' + (data.ok ? 'text-emerald-300' : 'text-rose-300');
            })
            .catch(function () {
                note.textContent = 'не удалось проверить — сеть или сервер';
                note.className = 'text-xs text-rose-300';
            })
            .then(function () { button.disabled = false; });
    });
})();
</script>
</div>
