<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница управления базы знаний (nj560_knowledge).
 *
 * Единый широкий макет: главный блок слева, справа — пояснения и действия.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$num  = 'mt-1 text-2xl font-extrabold text-gray-900 dark:text-white';
$lbl  = 'text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="flex flex-col gap-6 xl:flex-row xl:items-start">

        <!-- ===================== Главный блок ===================== -->
        <div class="min-w-0 flex-1">
            <div class="<?php echo $card; ?> sm:p-6">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="n2j-h1">База знаний</h1>
                    <span class="n2j-badge n2j-badge-info uppercase">комбинированный поиск</span>
                </div>

                <?php if ( ! empty($_GET['flash'])): ?>
                    <div class="n2j-flash n2j-flash-info mt-3 text-xs block">
                        <?php echo HTML::chars((string) $_GET['flash']); ?>
                    </div>
                <?php endif; ?>

                <!-- Карточки статистики -->
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                        <p class="<?php echo $lbl; ?>">Всего записей</p>
                        <p class="<?php echo $num; ?>"><?php echo (int) $stats['docs']; ?></p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                        <p class="<?php echo $lbl; ?>">Повторов отсрочено</p>
                        <p class="<?php echo $num; echo ($stats['hits'] - $stats['docs']) > 0 ? ' text-emerald-600 dark:text-emerald-400' : ''; ?>"><?php echo max(0, (int) $stats['hits'] - (int) $stats['docs']); ?></p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                        <p class="<?php echo $lbl; ?>">Свежих (7 дней)</p>
                        <p class="<?php echo $num; ?>"><?php echo (int) $stats['fresh']; ?></p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                        <p class="<?php echo $lbl; ?>">Источников</p>
                        <p class="<?php echo $num; ?>"><?php echo count($stats['by_source']); ?></p>
                    </div>
                </div>

                <?php if ( ! empty($stats['by_source'])): ?>
                <div class="mt-3 flex flex-wrap gap-2">
                    <?php foreach ($stats['by_source'] as $b): ?>
                        <span class="n2j-badge n2j-badge-wait">
                            <?php echo HTML::chars($b['source_module']); ?>/<?php echo HTML::chars($b['kind']); ?> — <strong><?php echo (int) $b['cnt']; ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Комбинированный поиск -->
                <form method="get" action="/nj560" class="mt-5 flex flex-wrap items-center gap-2">
                    <input type="text" name="q" value="<?php echo HTML::chars($q); ?>"
                           placeholder="Поиск по базе: текст, тема…"
                           class="n2j-input w-full max-w-md">
                    <button type="submit" class="n2j-btn-process n2j-btn-sm"><i class="fas fa-magnifying-glass mr-1"></i>Найти</button>
                    <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <input type="checkbox" name="semantic" value="1"<?php echo $semantic ? ' checked' : ''; ?> class="min-h-[44px] rounded">
                        + семантический (векторный<?php echo $semantic_available ? '' : ' — недоступен, нет nj115'; ?>)
                    </label>
                    <?php if ($q !== ''): ?><a href="/nj560" class="text-xs text-gray-400 hover:text-gray-600">сбросить</a><?php endif; ?>
                </form>

                <?php if ($result !== NULL): ?>
                    <?php if (empty($result['text']) AND empty($result['semantic'])): ?>
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Ничего не найдено.</p>
                    <?php else: ?>
                        <?php if ( ! empty($result['text'])): ?>
                            <h2 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-400">Точное совпадение</h2>
                            <?php foreach ($result['text'] as $d): ?>
                                <div class="n2j-card mt-2 p-3">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($d['title']); ?></p>
                                    <?php if ($d['summary']): ?><p class="n2j-hint mt-0.5"><?php echo HTML::chars(mb_substr($d['summary'], 0, 200)); ?></p><?php endif; ?>
                                    <p class="n2j-hint"><?php echo HTML::chars($d['source_module']); ?>/<?php echo HTML::chars($d['kind']); ?> · повторов: <?php echo (int) $d['hits']; ?> · <?php echo HTML::chars((string) $d['last_seen_at']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ( ! empty($result['semantic'])): ?>
                            <h2 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-400">Похожие по смыслу (векторные)</h2>
                            <?php foreach ($result['semantic'] as $s): ?>
                                <div class="mt-2 rounded-xl border border-dashed border-blue-300 bg-blue-50/40 p-3 dark:border-blue-800 dark:bg-blue-950/20">
                                    <p class="text-xs text-gray-700 dark:text-gray-300"><?php echo HTML::chars($s['content']); ?></p>
                                    <p class="n2j-hint">документ #<?php echo (int) $s['doc_id']; ?><?php echo $s['distance'] !== NULL ? ' · расстояние: '.round($s['distance'], 3) : ''; ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Последние записи -->
                <?php if ( ! empty($recent)): ?>
                <h2 class="mt-6 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Последние записи</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="n2j-table">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
                                <th class="py-2.5 pr-3 font-semibold">Заголовок</th>
                                <th class="py-2.5 pr-3 font-semibold">Модуль/тип</th>
                                <th class="py-2.5 pr-3 font-semibold">Повторы</th>
                                <th class="py-2.5 pr-3 font-semibold">Обновлена</th>
                                <th class="py-2 font-semibold text-right"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $d): ?>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="max-w-[26rem] py-2 pr-3">
                                    <span class="block truncate text-xs font-semibold text-gray-800 dark:text-gray-200" title="<?php echo HTML::chars($d['title']); ?>"><?php echo HTML::chars($d['title']); ?></span>
                                    <?php if ($d['summary']): ?><span class="block truncate text-xs text-gray-400"><?php echo HTML::chars(mb_substr($d['summary'], 0, 100)); ?></span><?php endif; ?>
                                </td>
                                <td class="py-2.5 pr-3 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($d['source_module']); ?>/<?php echo HTML::chars($d['kind']); ?></td>
                                <td class="py-2.5 pr-3 text-xs font-semibold <?php echo (int) $d['hits'] >= 3 ? 'text-amber-600' : 'text-gray-500'; ?>"><?php echo (int) $d['hits']; ?></td>
                                <td class="py-2.5 pr-3 text-xs text-gray-400"><?php echo HTML::chars((string) $d['last_seen_at']); ?></td>
                                <td class="py-2 text-right">
                                    <form method="post" action="/nj560/delete/<?php echo (int) $d['id']; ?>">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <button data-n2j-arm="Удалить?" type="submit" class="n2j-btn-danger n2j-btn-sm"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Записей пока нет — база наполнится автоматически, когда воркеры завершат первые задачи. Можно нажать «Собрать сейчас» справа.</p>
                <?php endif; ?>

                <!-- Очистка -->
                <form method="post" action="/nj560/cleanup" class="mt-5 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <i class="fas fa-broom text-amber-500"></i>
                    <span class="text-xs text-amber-800 dark:text-amber-300">Очистить записи старше периода хранения (config: retention_days)</span>
                    <button data-n2j-confirm="Удалить все записи старше периода хранения (из конфига модуля)?" data-n2j-tone="danger" data-n2j-ok="Очистить" type="submit" class="n2j-btn-secondary n2j-btn-sm ml-auto">Очистить</button>
                </form>
            </div>
        </div>

        <!-- ===================== Правая колонка: пояснения и действия ===================== -->
        <aside class="w-full shrink-0 space-y-4 xl:w-80 2xl:w-96">
            <!-- Сбор сейчас -->
            <div class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-800 dark:bg-blue-950/30">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-download mr-1.5 text-blue-500"></i>Собрать сейчас</p>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Разовый перенос уже готовых результатов в базу: резюме поисков вакансий (nj550) и ответы на отзывы (nj540). Дубли не задвоятся.</p>
                <form method="post" action="/nj560/backfill" class="mt-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button type="submit" class="n2j-btn-process n2j-btn-sm w-full">
                        <i class="fas fa-wand-magic-sparkles mr-1"></i>Наполнить из готовых результатов
                    </button>
                </form>
            </div>

            <!-- Откуда собирается -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-diagram-project mr-1.5 text-gray-400"></i>Откуда собирается</p>
                <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2"><code class="shrink-0 rounded bg-gray-100 px-1.5 font-mono text-xs text-blue-600 dark:bg-gray-800 dark:text-blue-400">nj540</code><span>ответы на отзывы — после завершения задачи воркером</span></li>
                    <li class="flex gap-2"><code class="shrink-0 rounded bg-gray-100 px-1.5 font-mono text-xs text-blue-600 dark:bg-gray-800 dark:text-blue-400">nj550</code><span>резюме поиска вакансий — после завершения задачи воркером</span></li>
                </ul>
                <p class="n2j-hint mt-2">Запись появляется в момент, когда воркер успешно закончил работу (status → «выполнено»). Новые модули подключаются парой строк — см. MODULE_GUIDE.</p>
            </div>

            <!-- Как это работает -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Как это работает</p>
                <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-gray-600 dark:text-gray-300">
                    <li>Похожая задача не выполняется дважды: дедуп по ключу (sha256 текста задачи), повторы считаются в колонке «повторы».</li>
                    <li>Векторы (семантический поиск) считает модуль nj115 — индексация идёт через очередь, чуть позже записи.</li>
                    <li>Очистка — по возрасту (config: retention_days); содержимое — только наработки, без персональных данных.</li>
                </ul>
            </div>

            <!-- Ссылки -->
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-link mr-1.5 text-gray-400"></i>Смежное</p>
                <ul class="mt-2 space-y-1 text-xs">
                    <li><a href="/nj550/admin" class="text-blue-600 hover:underline dark:text-blue-400">Вакансии · управление</a> <span class="text-gray-400">— источник nj550</span></li>
                    <li><a href="/tasks" class="text-blue-600 hover:underline dark:text-blue-400">Мои задачи</a> <span class="text-gray-400">— очередь воркеров</span></li>
                    <li><a href="/mcp" class="text-blue-600 hover:underline dark:text-blue-400">MCP-хаб</a> <span class="text-gray-400">— инструменты для ИИ-агентов</span></li>
                </ul>
            </div>
        </aside>
    </div>
</div>
