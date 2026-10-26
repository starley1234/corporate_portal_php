<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Вкладка «Спрос агентов»: что спрашивали через шлюз и по чему не нашлось.
 *
 * Это не отчёт ради отчёта: из этих строк берутся темы автосборки — каталог
 * растёт под реальные запросы, а не под чьё-то представление о нужном.
 */
$demand = (array) ($demand ?? []);
$demand_stats = array_merge(
    ['searches' => 0, 'empty' => 0, 'queries' => 0, 'agents' => 0],
    (array) ($demand_stats ?? [])
);
$demand_days = (int) ($demand_days ?? Service_Nj676_Demand::WINDOW_DAYS);
$demand_next = (string) ($demand_next ?? '');
$demand_empty = ! empty($demand_empty);
?>
<div class="space-y-5">
    <?php if ($demand_empty): ?>
        <div class="n2j-flash n2j-flash-info" role="status">
            <i class="fas fa-circle-info shrink-0 text-lg" aria-hidden="true"></i>
            <span>Спроса пока нет: все запросы агентов находят ответ в каталоге — собирать по теме нечего.</span>
        </div>
    <?php endif; ?>
    <section class="n2j-card" id="demand">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="n2j-h2">Спрос агентов</h2>
                <p class="n2j-hint mt-1">
                    Что ищут агенты через шлюз <span class="font-mono">/mcphub/mcp</span>.
                    Запрос без ответа становится темой автосборки — каталог растёт под реальный спрос,
                    а не по догадке редактора.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="n2j-badge n2j-badge-info"><span class="n2j-dot"></span>за 7 дней: <?php echo (int) $demand_stats['searches']; ?></span>
                <?php if ((int) $demand_stats['empty'] > 0): ?>
                    <span class="n2j-badge n2j-badge-err"><span class="n2j-dot"></span>без ответа: <?php echo (int) $demand_stats['empty']; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <div class="n2j-seg" role="group" aria-label="Период спроса">
                <?php foreach ([7, 30, 90] as $days): ?>
                    <a class="n2j-seg-item<?php echo $demand_days === $days ? ' is-active' : ''; ?>"
                       href="/mcphub/admin?tab=demand&days=<?php echo (int) $days; ?>#demand"
                       <?php echo $demand_days === $days ? 'aria-current="true"' : ''; ?>><?php echo (int) $days; ?> дней</a>
                <?php endforeach; ?>
            </div>
            <span class="n2j-hint">
                разных запросов (7 дн.): <?php echo (int) $demand_stats['queries']; ?>
                · агентов: <?php echo (int) $demand_stats['agents']; ?>
            </span>
        </div>

        <?php if ($demand === []): ?>
            <div class="n2j-empty mt-2">
                <span class="n2j-empty-icon"><i class="fas fa-comment-dots" aria-hidden="true"></i></span>
                <p class="n2j-empty-text mt-3 font-semibold text-gray-900 dark:text-white">Запросов пока не было</p>
                <p class="n2j-hint mt-1">Подключите агент к <span class="font-mono">/mcphub/mcp</span> и задайте ему задачу — запросы появятся здесь.</p>
            </div>
        <?php else: ?>
            <div class="mt-3 overflow-x-auto n2j-scrollbar">
                <table class="n2j-table w-full min-w-[52rem]">
                    <thead>
                        <tr>
                            <th>Запрос</th>
                            <th class="text-right">Искали</th>
                            <th>Результат</th>
                            <th>Когда</th>
                            <th class="text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($demand as $row): ?>
                        <?php
                        $row_norm = (string) $row['norm'];
                        $row_sample = trim((string) ($row['sample'] ?? ''));
                        $row_display = $row_sample !== '' ? $row_sample : $row_norm;
                        $best = (int) ($row['best'] ?? 0);
                        $empty_count = (int) ($row['empty'] ?? 0);
                        $is_queued = ($demand_next !== '' AND ($demand_next === $row_norm OR $demand_next === $row_display));
                        ?>
                        <tr>
                            <td>
                                <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($row_display); ?></span>
                                <?php if ($row_sample !== '' AND $row_sample !== $row_norm): ?>
                                    <span class="n2j-hint ml-1 font-mono text-xs">(<?php echo HTML::chars($row_norm); ?>)</span>
                                <?php endif; ?>
                                <?php if ($is_queued): ?>
                                    <span class="n2j-badge n2j-badge-run ml-1"><span class="n2j-dot"></span>следующая сборка</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right font-mono tabular-nums"><?php echo (int) $row['searches']; ?></td>
                            <td>
                                <?php if ($best > 0): ?>
                                    <span class="n2j-badge n2j-badge-ok"><span class="n2j-dot"></span>нашлось: <?php echo $best; ?></span>
                                <?php else: ?>
                                    <span class="n2j-badge n2j-badge-err"><span class="n2j-dot"></span>не нашлось ни разу</span>
                                <?php endif; ?>
                                <?php if ($empty_count > 0 AND $best > 0): ?>
                                    <span class="n2j-hint ml-1">пустых: <?php echo $empty_count; ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($fmt_dt($row['last_seen'] ?? '')); ?></td>
                            <td class="whitespace-nowrap text-right">
                                <?php if ( ! $run_running): ?>
                                    <form method="post" action="/mcphub/admin/demand" class="inline">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <input type="hidden" name="theme" value="<?php echo HTML::chars($row_display); ?>">
                                        <input type="hidden" name="n" value="8">
                                        <button class="n2j-btn-secondary n2j-btn-sm" type="submit">
                                            <i class="fas fa-magnifying-glass" aria-hidden="true"></i> Собрать
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3 dark:border-gray-700/60">
                <p class="n2j-hint min-w-0">
                    <i class="fas fa-robot mr-1" aria-hidden="true"></i>
                    <?php if ($auto_enabled): ?>
                        Ночная автосборка берёт тему отсюда сама, начиная с самой частой
                        <?php if ($demand_next !== ''): ?>
                            — следующая: <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($demand_next); ?></span>
                        <?php endif; ?>.
                    <?php else: ?>
                        Автосборка по спросу выключена: включите <span class="font-mono">NJ676_AUTO_DISCOVER=1</span>,
                        и темы будут собираться без вас.
                    <?php endif; ?>
                </p>
                <?php if ($demand_next !== '' AND ! $run_running): ?>
                    <div class="flex flex-wrap gap-2">
                        <form method="post" action="/mcphub/admin/demand">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="n" value="<?php echo (int) $default_n; ?>">
                            <button class="n2j-btn-process n2j-btn-sm" type="submit">
                                <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>
                                Собрать по спросу: <?php echo HTML::chars(mb_substr($demand_next, 0, 24, 'UTF-8')); ?><?php echo mb_strlen($demand_next, 'UTF-8') > 24 ? '…' : ''; ?>
                            </button>
                        </form>
                        <form method="post" action="/mcphub/admin/demand">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="all" value="1">
                            <input type="hidden" name="n" value="<?php echo (int) $default_n; ?>">
                            <button class="n2j-btn-secondary n2j-btn-sm" type="submit" title="Поставить сборку до 3 топ-тем без ответа">
                                <i class="fas fa-layer-group" aria-hidden="true"></i> Топ-3 темы
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
