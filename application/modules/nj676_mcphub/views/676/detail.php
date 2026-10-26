<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Карточка MCP-сервера (nj676): что умеет, чем платить (ключи), как подключить.
 * Главный столбец — описание и конфигурация, справа — факты, похожие серверы
 * и предупреждение о запуске чужого кода.
 */
$badge = ['emerald' => 'n2j-badge-ok', 'amber' => 'n2j-badge-wait', 'rose' => 'n2j-badge-err'][$server['health_tone']] ?? 'n2j-badge-info';
// Готовность к вызову — то, что агент спрашивает первым: без ключа и установки
// сервер бесполезен, каким бы хорошим ни было описание.
$ready_badge = [
    'ready' => 'n2j-badge-ok', 'needs_key' => 'n2j-badge-wait',
    'needs_install' => 'n2j-badge-info', 'degraded' => 'n2j-badge-run',
    'stale' => 'n2j-badge-info', 'broken' => 'n2j-badge-err',
][$server['readiness']['readiness']] ?? 'n2j-badge-info';
$icon = $category_icons[$server['categories'][0] ?? 'other'] ?? 'fa-cube';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Каталог MCP', '/mcphub'], [$server['display_name'] ?? $server['name']]],
        'title' => $server['display_name'] ?? $server['name'],
        'pill' => [$server['is_remote'] ? 'Удалённый MCP' : 'Локальный MCP', $icon],
        'actions' => array_values(array_filter([
            ($server['repo_url'] ?? '') !== '' ? ['label' => 'Исходный код', 'href' => $server['repo_url'], 'icon' => 'fa-code-branch', 'role' => 'secondary'] : NULL,
            $server['homepage_url'] !== '' ? ['label' => 'Сайт проекта', 'href' => $server['homepage_url'], 'icon' => 'fa-arrow-up-right-from-square', 'role' => 'secondary'] : NULL,
            ! empty($can_admin) ? ['label' => 'Править', 'href' => '/mcphub/admin?tab=cards&slug='.rawurlencode($server['slug']), 'icon' => 'fa-pen', 'role' => 'secondary'] : NULL,
            $config !== NULL ? ['label' => 'Скачать конфиг', 'href' => '/mcphub/export/'.rawurlencode($server['slug']), 'icon' => 'fa-download', 'role' => 'cta'] : NULL,
        ])),
        'lead' => mb_substr($server['display_description'] ?? $server['description'], 0, 240),
    ]); ?>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <span class="<?php echo $ready_badge; ?>" title="<?php echo HTML::chars($server['readiness']['note']); ?>">
            <span class="n2j-dot"></span><?php echo HTML::chars($server['readiness']['label']); ?>
        </span>
        <?php if ($server['schema_valid'] === FALSE): ?>
            <span class="n2j-badge n2j-badge-err" title="Схема инструментов не проходит проверку JSON Schema">
                <span class="n2j-dot"></span>схема сломана
            </span>
        <?php endif; ?>
        <?php // Health дублирует readiness: «недоступен» рядом с «недоступен» — шум. ?>
        <?php if (in_array($server['readiness']['readiness'], ['ready', 'degraded'], TRUE)): ?>
            <span class="<?php echo $badge; ?>"><span class="n2j-dot"></span><?php echo HTML::chars($server['health_label']); ?></span>
        <?php endif; ?>
        <span class="n2j-chip"><?php echo HTML::chars($server['transport_label']); ?></span>
        <span class="n2j-chip"><?php echo HTML::chars($server['auth_label']); ?></span>
        <?php foreach ($server['categories'] as $category): if (isset($category_labels[$category])): ?>
            <a class="n2j-chip" href="/mcphub?category=<?php echo rawurlencode($category); ?>">
                <i class="fas <?php echo HTML::chars($category_icons[$category] ?? 'fa-cube'); ?> text-xs opacity-70" aria-hidden="true"></i>
                <?php echo HTML::chars($category_labels[$category]); ?>
            </a>
        <?php endif; endforeach; ?>
        <?php // Перевод не прячет оригинал: имя проекта — факт из репозитория. ?>
        <?php if ( ! empty($server['has_ru']) AND ($server['name_ru'] ?? '') !== '' AND $server['name_ru'] !== ($server['name'] ?? '')): ?>
            <span class="n2j-chip" title="Название в репозитории автора"><?php echo HTML::chars($server['name']); ?></span>
        <?php endif; ?>
        <span class="n2j-hint ml-auto"><?php echo HTML::chars($server['origin_label']); ?> · <?php echo (int) $server['views']; ?> просмотров</span>
    </div>

    <div class="mt-5 gap-5 space-y-5 xl:flex xl:items-start xl:space-y-0 xl:gap-8">
        <div class="min-w-0 flex-1 space-y-5">
            <section class="n2j-card">
                <h2 class="n2j-h2">Что умеет</h2>
                <?php
                // Описание из чужого репозитория или реестра — недоверенный
                // ввод: в нём встречаются обращения к агенту («игнорируй
                // инструкции»). Показываем очищенный текст и честно помечаем,
                // что это слова автора, а не проверенный факт.
                $original_description = Service_Nj676_Gateway::clean_remote_text((string) ($server['description'] ?? ''), 4000);
                $translated_description = Service_Nj676_Gateway::clean_remote_text((string) ($server['description_ru'] ?? ''), 4000);
                $has_translation = $translated_description !== '';
                $clean_description = $has_translation ? $translated_description : $original_description;
                $untrusted_origin = in_array((string) $server['origin'], ['auto', 'registry'], TRUE);
                ?>
                <p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-gray-700 dark:text-gray-200"><?php echo HTML::chars($clean_description !== '' ? $clean_description : 'Описание пока не собрано. Смотрите README проекта.'); ?></p>
                <?php if ($has_translation): ?>
                    <?php // Перевод — текст редакции, но тоже машинный: оригинал рядом. ?>
                    <details class="mt-2">
                        <summary class="n2j-hint cursor-pointer select-none">
                            <i class="fas fa-language mr-1" aria-hidden="true"></i>
                            Перевод каталога<?php echo ($server['ru_source'] ?? '') === 'manual' ? ' (редакция)' : ' (LLM)'; ?> ·
                            показать оригинал автора
                        </summary>
                        <p class="mt-1.5 whitespace-pre-wrap text-sm leading-6 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($original_description !== '' ? $original_description : '—'); ?></p>
                    </details>
                <?php elseif ($untrusted_origin AND $clean_description !== ''): ?>
                    <p class="n2j-hint mt-2">
                        <i class="fas fa-circle-info mr-1" aria-hidden="true"></i>
                        Описание приведено из <?php echo $server['origin'] === 'registry' ? 'официального реестра' : 'репозитория'; ?>
                        без проверки: это слова автора сервера, а не редакции каталога.
                    </p>
                <?php endif; ?>

                <?php if ( ! empty($server['highlights'])): ?>
                    <ul class="mt-4 space-y-2">
                        <?php foreach ($server['highlights'] as $line): ?>
                            <li class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                                <i class="fas fa-circle-check mt-1 text-emerald-500" aria-hidden="true"></i>
                                <span><?php echo HTML::chars($line); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ( ! empty($server['use_cases'])): ?>
                    <h3 class="n2j-h3 mt-5">Кому пригодится</h3>
                    <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-200">
                        <?php foreach ($server['use_cases'] as $line): ?>
                            <li class="flex items-start gap-2"><i class="fas fa-arrow-right mt-1 text-xs text-blue-500" aria-hidden="true"></i><span><?php echo HTML::chars($line); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ( ! empty($server['topics'])): ?>
                    <div class="mt-4 flex flex-wrap gap-1.5">
                        <?php foreach ($server['topics'] as $topic): ?>
                            <span class="n2j-chip">#<?php echo HTML::chars($topic); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="n2j-card" id="config">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="n2j-h2">Подключение</h2>
                        <p class="n2j-hint mt-1">
                            <?php if ($server['is_remote']): ?>
                                Удалённый сервер: клиент обращается по адресу, код на вашей машине не запускается.
                            <?php else: ?>
                                Локальный сервер: клиент запустит пакет на вашем компьютере. Проверьте владельца и код.
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($config !== NULL): ?>
                        <button type="button" class="n2j-btn-secondary n2j-btn-sm" data-n2j-copy="<?php echo HTML::chars($config_text); ?>">
                            <i class="fas fa-copy" aria-hidden="true"></i> Скопировать
                        </button>
                    <?php endif; ?>
                </div>

                <?php if ($config === NULL): ?>
                    <div class="n2j-empty mt-4">
                        <p class="n2j-empty-text font-semibold">Готового фрагмента пока нет</p>
                        <p class="n2j-hint mt-1">
                            <?php if ($server['is_remote']): ?>
                                У сервера не указан проверенный адрес подключения. Смотрите README проекта.
                            <?php else: ?>
                                Нужна закреплённая версия пакета npm/PyPI — без неё каталог не выдаёт команду запуска:
                                плавающая «latest» ломает воспроизводимость.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="mt-4 grid gap-2 sm:grid-cols-3">
                        <?php foreach ($client_targets as $client): ?>
                            <div class="rounded-lg border border-gray-200 p-2.5 dark:border-gray-700">
                                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?php echo HTML::chars($client['title']); ?></p>
                                <p class="mt-1 break-all font-mono text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($client['file']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="n2j-hint mt-3">Вставьте блок <span class="font-mono">mcpServers</span> в файл конфигурации своего клиента. Ключи не входят в файл: вместо <span class="font-mono">SET_LOCALLY</span> подставьте свои значения.</p>
                    <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-950 p-3.5 text-xs leading-relaxed text-gray-100"><?php echo HTML::chars($config_text); ?></pre>
                <?php endif; ?>

                <?php if ( ! empty($server['env_keys'])): ?>
                    <h3 class="n2j-h3 mt-5">Какие ключи понадобятся</h3>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <?php foreach ($server['env_keys'] as $key): ?>
                            <span class="n2j-chip font-mono"><?php echo HTML::chars($key); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <p class="n2j-hint mt-2">Каталог хранит только имена переменных. Значения задаются у вас локально и никогда не попадают на сервер.</p>
                <?php endif; ?>

                <?php if ($server['is_remote'] AND $server['readiness']['readiness'] === 'broken'): ?>
                    <?php // Адрес не прошёл приёмку: не обещаем вызов и не показываем
                          // «ключ не нужен» — иначе это ровно та карточка, на которой
                          // агент тратил вызов в реальном тесте 24.10.2026. ?>
                    <div class="mt-5 rounded-lg border border-rose-200 bg-rose-50 p-3 dark:border-rose-800 dark:bg-rose-950/40">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">
                            <i class="fas fa-ban text-rose-600 dark:text-rose-400" aria-hidden="true"></i> Сервер недоступен для вызова
                        </p>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars($server['readiness']['note']); ?></p>
                        <?php if ((string) ($server['remote_url'] ?? '') !== ''): ?>
                            <div class="mt-2">
                                <code class="min-w-0 break-all rounded bg-white/80 px-2 py-1 font-mono text-xs text-gray-500 line-through dark:bg-gray-900/80 dark:text-gray-400"><?php echo HTML::chars($server['remote_url']); ?></code>
                            </div>
                        <?php endif; ?>
                        <p class="n2j-hint mt-2">Карточка остаётся в каталоге как запись о сервере: адрес не проходит проверку
                            безопасности (нужен HTTPS и публичный хост). Вызов через шлюз вернёт ошибку.</p>
                    </div>
                <?php elseif ($server['is_remote']): ?>
                    <div class="mt-5 rounded-lg border border-blue-200 bg-blue-50/60 p-3 dark:border-blue-800 dark:bg-blue-950/40">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white"><i class="fas fa-bolt text-blue-600 dark:text-blue-400" aria-hidden="true"></i> Вызов без установки — через шлюз</p>
                        <p class="n2j-hint mt-1">Агент вызывает инструменты этого сервера через
                            <span class="font-mono text-xs"><?php echo HTML::chars($gateway_url); ?></span>:
                            <span class="font-mono text-xs">hub_call_tool {"server":"<?php echo HTML::chars($server['slug']); ?>","tool":"…","arguments":{…}}</span>
                            <?php if ($server['auth_mode'] !== 'none'): ?>
                                Сервер требует авторизации — ключ задаётся на стороне каталога.
                            <?php else: ?>
                                Ключ не нужен, но сервер N2J ограничивает частоту анонимных вызовов.
                            <?php endif; ?>
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <code class="min-w-0 break-all rounded bg-white/80 px-2 py-1 font-mono text-xs text-gray-700 dark:bg-gray-900/80 dark:text-gray-200"><?php echo HTML::chars($server['remote_url']); ?></code>
                            <button class="n2j-btn-secondary n2j-btn-sm" type="button"
                                    data-n2j-copy="<?php echo HTML::chars($server['remote_url']); ?>">
                                <i class="fas fa-copy" aria-hidden="true"></i> Адрес
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <?php
            $audit_rep = is_array($server['audit_report'] ?? NULL) ? $server['audit_report'] : [];
            $has_audit = ( ! empty($server['audited_at']) OR (int) ($server['audit_score'] ?? 0) > 0 OR $audit_rep !== []);
            ?>
            <?php if ($has_audit OR ($can_admin AND $server['is_remote'])): ?>
                <section class="n2j-card" id="audit-report">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="n2j-h2 flex items-center gap-2">
                                    <i class="fas <?php echo ! empty($server['is_gem']) ? 'fa-gem text-emerald-600' : 'fa-microscope text-blue-600'; ?>" aria-hidden="true"></i>
                                    Полевое исследование ИИ-агентом
                                </h2>
                                <?php if (($server['audit_verdict_label'] ?? '') !== ''): ?>
                                    <span class="n2j-badge <?php echo ! empty($server['is_gem']) ? 'n2j-badge-ok' : 'n2j-badge-info'; ?>">
                                        <?php echo HTML::chars($server['audit_verdict_label']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="n2j-hint mt-1">
                                Воркер-агент подключается к открытому серверу, проверяет схемы и безопасность и выполняет пробный вызов инструментов.
                                <?php if ( ! empty($server['audited_at'])): ?>
                                    Проверено <?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $server['audited_at']))); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php if ($can_admin AND $server['is_remote']): ?>
                            <form method="post" action="/mcphub/admin/audit">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf ?? Security::token()); ?>">
                                <input type="hidden" name="slug" value="<?php echo HTML::chars($server['slug']); ?>">
                                <input type="hidden" name="return" value="detail">
                                <button type="submit" class="n2j-btn-secondary n2j-btn-sm">
                                    <i class="fas fa-microscope text-xs" aria-hidden="true"></i>
                                    <?php echo $has_audit ? 'Переисследовать агентом' : 'Исследовать агентом'; ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ( ! empty($audit_Queued)): ?>
                        <div class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-gray-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-gray-100">
                            <i class="fas fa-check-circle text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                            Сервер поставлен в очередь на полевое исследование воркером-агентом. Отчёт появится здесь после завершения задачи.
                        </div>
                    <?php endif; ?>

                    <?php if ($has_audit): ?>
                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-800/60">
                                <p class="n2j-hint text-xs">Оценка практической пользы</p>
                                <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white"><?php echo (int) ($server['audit_score'] ?? 0); ?> <span class="text-sm font-normal text-gray-500 dark:text-gray-400">/ 100</span></p>
                            </div>
                            <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-800/60">
                                <p class="n2j-hint text-xs">Оценка безопасности</p>
                                <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white"><?php echo (int) ($server['safety_score'] ?? 0); ?> <span class="text-sm font-normal text-gray-500 dark:text-gray-400">/ 100</span></p>
                            </div>
                            <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-800/60">
                                <p class="n2j-hint text-xs">Вердикт исследователя</p>
                                <p class="mt-1 text-base font-bold text-gray-900 dark:text-white">
                                    <?php if ( ! empty($server['is_gem'])): ?>
                                        <i class="fas fa-gem text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i> Алмаз каталога
                                    <?php else: ?>
                                        <?php echo HTML::chars(($server['audit_verdict_label'] ?? '') ?: 'Проверен'); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <?php if (($server['audit_summary'] ?? '') !== ''): ?>
                            <p class="mt-4 text-sm leading-6 text-gray-800 dark:text-gray-100"><?php echo HTML::chars($server['audit_summary']); ?></p>
                        <?php endif; ?>

                        <?php $audit_uc = is_array($audit_rep['use_cases'] ?? NULL) ? $audit_rep['use_cases'] : []; ?>
                        <?php if ($audit_uc !== []): ?>
                            <div class="mt-4">
                                <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Реальное применение для агентов</h3>
                                <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-200">
                                    <?php foreach ($audit_uc as $uc_line): ?>
                                        <li class="flex items-start gap-2">
                                            <i class="fas fa-check mt-1 text-xs text-emerald-600 dark:text-emerald-400" aria-hidden="true"></i>
                                            <span><?php echo HTML::chars((string) $uc_line); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty($audit_rep['safety_notes'])): ?>
                            <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-800/60">
                                <p class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-shield-halved text-emerald-600 mr-1 dark:text-emerald-400" aria-hidden="true"></i> Безопасность</p>
                                <p class="mt-1 text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $audit_rep['safety_notes']); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty($audit_rep['observations'])): ?>
                            <div class="mt-3 rounded-lg border border-blue-100 bg-blue-50/40 p-3 text-sm dark:border-blue-900 dark:bg-blue-950/40">
                                <p class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-lightbulb text-amber-500 mr-1" aria-hidden="true"></i> Наблюдения полевого агента</p>
                                <p class="mt-1 text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $audit_rep['observations']); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php $tested_list = is_array($audit_rep['tested_tools'] ?? NULL) ? $audit_rep['tested_tools'] : []; ?>
                        <?php if ($tested_list !== []): ?>
                            <div class="mt-4">
                                <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Пробные вызовы инструментов (tools/call)</h3>
                                <div class="mt-2 space-y-2">
                                    <?php foreach ($tested_list as $tt): ?>
                                        <div class="rounded-lg border border-gray-200 p-2.5 text-xs dark:border-gray-700">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <span class="font-mono font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) ($tt['tool'] ?? '')); ?></span>
                                                <span class="n2j-badge <?php echo ! empty($tt['ok']) ? 'n2j-badge-ok' : 'n2j-badge-wait'; ?>">
                                                    <?php echo ! empty($tt['ok']) ? 'OK' : 'ошибка'; ?>
                                                    <?php if ((int) ($tt['latency_ms'] ?? 0) > 0): ?> · <?php echo (int) $tt['latency_ms']; ?> мс<?php endif; ?>
                                                </span>
                                            </div>
                                            <?php if ( ! empty($tt['sample'])): ?>
                                                <p class="mt-1.5 line-clamp-2 font-mono text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $tt['sample']); ?></p>
                                            <?php elseif ( ! empty($tt['note'])): ?>
                                                <p class="mt-1 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $tt['note']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="n2j-card">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="n2j-h2">Инструменты</h2>
                        <p class="n2j-hint mt-1">
                            <?php echo (int) $server['tool_count']; ?> схем
                            <?php if ($server['tool_count'] > 0): ?>· ≈ <?php echo number_format((int) $server['approx_schema_tokens'], 0, ',', ' '); ?> условных токенов<?php endif; ?>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <?php if ($server['security_report']): ?>
                            <span class="n2j-chip">MCP Guard · риск: <?php echo HTML::chars($server['security_report']['risk'] ?? 'не определён'); ?></span>
                        <?php endif; ?>
                        <?php if ($server['is_remote']): ?>
                            <button type="button" id="mcp-live-check" class="n2j-btn-process n2j-btn-sm"
                                    data-n2j-busy-show="#mcp-live-hint">
                                <span data-n2j-idle class="inline-flex items-center gap-1.5">
                                    <i class="fas fa-satellite-dish text-xs" aria-hidden="true"></i> Проверить вживую
                                </span>
                                <span data-n2j-busy class="hidden inline-flex items-center gap-1.5">
                                    <i class="fas fa-circle-notch n2j-spin" aria-hidden="true"></i>
                                    Спрашиваю tools/list… (<span data-n2j-clock class="font-mono tabular-nums">0:00</span>)
                                </span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($server['is_remote']): ?>
                    <p class="n2j-hint mt-2 hidden" id="mcp-live-hint" role="status" aria-live="polite"></p>
                    <div id="mcp-live-result" class="mt-2"></div>
                <?php endif; ?>

                <?php if ($server['tools'] === []): ?>
                    <div class="n2j-empty mt-4">
                        <p class="n2j-empty-text font-semibold">Схемы пока не импортированы</p>
                        <p class="n2j-hint mt-1">Это не значит, что инструментов нет: сервер мог не ответить на <span class="font-mono text-xs">tools/list</span> при проверке.</p>
                    </div>
                <?php else: ?>
                    <div class="mt-4 space-y-3">
                        <?php foreach ($server['tools'] as $tool): ?>
                            <article class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <h3 class="font-semibold text-gray-900 dark:text-white"><span class="font-mono text-sm"><?php echo HTML::chars($tool['name'] ?? 'Инструмент без имени'); ?></span></h3>
                                <p class="mt-1 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars(Service_Nj676_Gateway::clean_remote_text((string) ($tool['description'] ?? ''), 1200) ?: 'Описание отсутствует.'); ?></p>
                                <?php if (isset($tool['inputSchema'])): ?>
                                    <details class="mt-3">
                                        <summary class="n2j-link cursor-pointer text-sm">Показать схему аргументов</summary>
                                        <pre class="mt-2 max-h-72 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100"><?php echo HTML::chars(json_encode($tool['inputSchema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                                    </details>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($server['security_report'] AND ! empty($server['security_report']['counts'])): ?>
                <section class="n2j-card">
                    <h2 class="n2j-h2">Статическая проверка MCP Guard</h2>
                    <p class="n2j-hint mt-1">Эвристический разбор описаний инструментов. Это не проверка исполняемого кода и не гарантия безопасности.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <?php foreach ($server['security_report']['counts'] as $severity => $count): ?>
                            <span class="n2j-chip"><?php echo HTML::chars($severity); ?>: <?php echo (int) $count; ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php $shown = 0; ?>
                    <?php if ( ! empty($server['security_report']['tools'])): ?>
                        <ul class="mt-4 space-y-2">
                            <?php foreach ($server['security_report']['tools'] as $reviewed): foreach ((array) ($reviewed['findings'] ?? []) as $finding): if ($shown >= 40) { break 2; } $shown++; ?>
                                <li class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                                    <strong><?php echo HTML::chars($reviewed['name'] ?? 'Инструмент'); ?> · <?php echo HTML::chars($finding['severity'] ?? ''); ?></strong>
                                    <p class="mt-1 text-gray-700 dark:text-gray-200"><?php echo HTML::chars($finding['message'] ?? ''); ?></p>
                                </li>
                            <?php endforeach; endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <aside class="w-full shrink-0 space-y-4 xl:w-80">
            <section class="n2j-card">
                <h2 class="n2j-h3">Коротко</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="n2j-hint">Доверие каталога</dt>
                        <dd class="font-semibold"><?php echo (int) $server['trust']; ?> / 100</dd>
                    </div>
                    <?php if ((int) ($server['audit_score'] ?? 0) > 0): ?>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="n2j-hint">Польза (полевой агент)</dt>
                            <dd class="font-semibold">
                                <?php if ( ! empty($server['is_gem'])): ?><i class="fas fa-gem text-xs text-emerald-600 mr-1 dark:text-emerald-400" aria-hidden="true"></i><?php endif; ?>
                                <?php echo (int) $server['audit_score']; ?> / 100
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="n2j-hint">Безопасность</dt>
                            <dd class="font-semibold"><?php echo (int) ($server['safety_score'] ?? 0); ?> / 100</dd>
                        </div>
                    <?php endif; ?>
                    <?php
                    $fb_sum = isset($feedback_summary) && is_array($feedback_summary) ? $feedback_summary : ['total' => 0, 'up' => 0, 'down' => 0, 'agent_rating' => NULL];
                    $fb_list = isset($recent_feedback) && is_array($recent_feedback) ? $recent_feedback : [];
                    $ar_val = $fb_sum['agent_rating'] ?? ($server['agent_rating'] ?? NULL);
                    ?>
                    <?php if ($ar_val !== NULL OR (int) ($fb_sum['total'] ?? 0) > 0): ?>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="n2j-hint">Рейтинг агентов</dt>
                            <dd class="font-semibold">
                                <?php if ($ar_val !== NULL): ?>
                                    <span class="text-amber-600 dark:text-amber-400">★ <?php echo number_format((float) $ar_val, 2, '.', ''); ?> / 5.0</span>
                                <?php endif; ?>
                                <span class="n2j-hint font-normal">(+<?php echo (int) ($fb_sum['up'] ?? 0); ?> / −<?php echo (int) ($fb_sum['down'] ?? 0); ?>)</span>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="n2j-hint">Инструментов</dt>
                        <dd class="font-semibold"><?php echo (int) $server['tool_count']; ?></dd>
                    </div>
                    <?php if ($server['stars'] > 0): ?>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="n2j-hint">Звёзды GitHub</dt>
                            <dd class="font-semibold"><?php echo number_format($server['stars'], 0, ',', ' '); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (($server['language'] ?? '') !== ''): ?>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="n2j-hint">Язык</dt>
                            <dd class="font-semibold"><?php echo HTML::chars(($server['language'] ?? '')); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (($server['license'] ?? '') !== ''): ?>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="n2j-hint">Лицензия</dt>
                            <dd class="font-semibold"><?php echo HTML::chars(($server['license'] ?? '')); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (($server['latency_ms'] ?? NULL) !== NULL): ?>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="n2j-hint">Отклик проверки</dt>
                            <dd class="font-semibold"><?php echo (int) ($server['latency_ms'] ?? 0); ?> мс</dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($server['fresh_days'] !== NULL): ?>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="n2j-hint">Коммит</dt>
                            <dd class="font-semibold"><?php echo $server['fresh_days'] === 0 ? 'сегодня' : ((int) $server['fresh_days'].' дн. назад'); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($server['package_hint'] !== ''): ?>
                        <div>
                            <dt class="n2j-hint">Запуск</dt>
                            <dd class="mt-1 break-all rounded-lg bg-gray-50 p-2 font-mono text-xs dark:bg-gray-800/60"><?php echo HTML::chars($server['package_hint']); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (($server['health_note'] ?? '') !== ''): ?>
                        <div>
                            <dt class="n2j-hint">Что ответила проверка</dt>
                            <dd class="mt-1 text-gray-700 dark:text-gray-200"><?php echo HTML::chars(($server['health_note'] ?? '')); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </section>

            <?php if ($related !== []): ?>
                <section class="n2j-card">
                    <h2 class="n2j-h3">Похожие серверы</h2>
                    <ul class="mt-3 space-y-3">
                        <?php foreach ($related as $item): ?>
                            <?php
                            $rel_name = (string) ($item['display_name'] ?? $item['name']);
                            $rel_desc = (string) ($item['display_description'] ?? $item['description']);
                            ?>
                            <li>
                                <a class="font-semibold text-gray-900 hover:underline dark:text-white" href="/mcphub/<?php echo rawurlencode($item['slug']); ?>"><?php echo HTML::chars($rel_name); ?></a>
                                <p class="n2j-hint mt-0.5"><?php echo HTML::chars(mb_substr($rel_desc, 0, 90)); ?><?php echo mb_strlen($rel_desc) > 90 ? '…' : ''; ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <aside class="n2j-card border-l-4 border-amber-400 bg-amber-50 dark:bg-amber-950/40">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">Это сторонний код</p>
                <p class="mt-1 text-xs leading-5 text-gray-700 dark:text-gray-200">
                    Каталог описывает серверы, но не проверяет их поведение. Подключение запускает чужой пакет
                    на <strong>вашем</strong> компьютере и даёт ему доступ к тому, что вы разрешите.
                    Звёзды и статический разбор — не гарантия безопасности.
                </p>
            </aside>
        </aside>
    </div>
</div>

<?php if ($server['is_remote']): ?>
<script>
(function () {
    'use strict';

    var button = document.getElementById('mcp-live-check');
    if (!button || !window.N2J) { return; }

    var hint = document.getElementById('mcp-live-hint');
    var box = document.getElementById('mcp-live-result');
    var url = '/mcphub/api/tools/<?php echo rawurlencode($server['slug']); ?>?fresh=1';

    function esc(text) {
        return String(text == null ? '' : text).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function show(text) {
        if (hint) { hint.textContent = text; hint.classList.remove('hidden'); }
    }

    button.addEventListener('click', function () {
        N2J.run(button, true);
        show('Стучусь в сервер, жду ответ на tools/list…');

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                N2J.run(button, false);

                if (!data || !data.ok) {
                    var reason = (data && data.error) ? data.error : 'сервер не ответил';
                    show('Не ответил: ' + reason);
                    if (box) { box.innerHTML = ''; }
                    N2J.toast('Сервер не ответил: ' + reason, 0, 'err');
                    return;
                }

                show('Ответил за ' + data.latency_ms + ' мс, инструментов: ' + data.count
                    + (data.cached ? ' (из кэша)' : '') + '. Статус карточки обновлён.');

                if (!box) { return; }

                if (!data.tools || !data.tools.length) {
                    box.innerHTML = '<p class="n2j-hint">Инструментов нет или сервер не отдал tools/list.</p>';
                    return;
                }

                box.innerHTML = '<div class="flex flex-wrap gap-1.5">' + data.tools.map(function (tool) {
                    return '<span class="n2j-chip" title="' + esc(tool.description) + '"><span class="font-mono text-xs">'
                        + esc(tool.name) + '</span></span>';
                }).join('') + '</div>';
            })
            .catch(function () {
                N2J.run(button, false);
                show('Запрос не дошёл до каталога');
                N2J.toast('Запрос не дошёл до каталога', 0, 'err');
            });
    });
}());
</script>
<?php endif; ?>
