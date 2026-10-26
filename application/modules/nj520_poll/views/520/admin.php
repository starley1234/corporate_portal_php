<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница управления опросов (nj520): список, подробности, IP голосовавших.
 * Единый широкий макет: главный блок слева, справа — пояснения.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <div class="flex flex-col gap-6 xl:flex-row xl:items-start">

        <!-- ===================== Главный блок ===================== -->
        <div class="min-w-0 flex-1">
            <div class="<?php echo $card; ?> sm:p-6">
                <h1 class="n2j-h1">Опросы · управление</h1>

                <?php if ($flash !== ''): ?>
                    <div class="n2j-flash n2j-flash-info mt-3 text-xs block">
                        <?php echo HTML::chars($flash); ?>
                    </div>
                <?php endif; ?>

                <!-- Подробности опроса (если выбран) -->
                <?php if ($detail !== NULL): ?>
                <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50/40 p-4 dark:border-blue-800 dark:bg-blue-950/20">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($detail['poll']['title']); ?>
                            <?php $d_closed = ! empty($detail['poll']['closed_at']); ?>
                            <span class="n2j-badge <?php echo $d_closed ? 'n2j-badge-wait' : 'n2j-badge-ok'; ?> ml-1 align-middle">
                                <i class="fas <?php echo $d_closed ? 'fa-lock' : 'fa-circle-play'; ?> text-[10px]"></i><?php echo $d_closed ? 'Закрыт' : 'Активен'; ?>
                            </span>
                        </p>
                        <a href="/p/<?php echo HTML::chars($detail['poll']['code']); ?>" class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-blue-600 hover:underline dark:bg-gray-800 dark:text-blue-400">/p/<?php echo HTML::chars($detail['poll']['code']); ?></a>
                        <a href="/nj520/admin" class="ml-auto text-xs text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200">закрыть</a>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-semibold uppercase text-gray-400">Варианты</p>
                            <ul class="mt-1 space-y-1">
                                <?php foreach ($detail['options'] as $o): ?>
                                <li class="n2j-card p-0 px-2.5 py-1.5 text-xs">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="min-w-0 truncate text-gray-700 dark:text-gray-200"><?php echo HTML::chars($o['label']); ?></span>
                                        <span class="shrink-0 font-bold text-blue-600 dark:text-blue-400"><?php echo (int) $o['votes']; ?> · <?php echo number_format((float) $o['percent'], 1, ',', ''); ?>%</span>
                                    </div>
                                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                        <div class="h-full rounded-full bg-blue-500" style="width: <?php echo (float) $o['percent']; ?>%"></div>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-gray-400">Кто голосовал (последние 100)</p>
                            <?php if (empty($detail['voters'])): ?>
                                <p class="n2j-hint">Голосов пока нет.</p>
                            <?php else: ?>
                            <ul class="mt-1 space-y-0.5">
                                <?php foreach ($detail['voters'] as $v): ?>
                                <li class="flex items-center justify-between gap-2 border-b border-gray-100 py-1 text-xs dark:border-gray-800">
                                    <span class="font-mono text-gray-600 dark:text-gray-300" title="ключ голосующего: браузер или учётная запись, адрес сети не хранится"><?php echo HTML::chars($v['who']); ?></span>
                                    <span class="min-w-0 flex-1 truncate px-2 text-xs text-gray-400"><?php echo HTML::chars($v['label']); ?></span>
                                    <span class="shrink-0 text-xs text-gray-400"><?php echo HTML::chars((string) $v['created_at']); ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <form method="post" action="/nj520/admin/close/<?php echo (int) $detail['poll']['id']; ?>">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <?php if ($d_closed): ?><input type="hidden" name="open" value="1"><?php endif; ?>
                            <button type="submit" class="n2j-btn-secondary n2j-btn-sm">
                                <i class="fas <?php echo $d_closed ? 'fa-lock-open' : 'fa-lock'; ?> mr-1"></i><?php echo $d_closed ? 'Открыть снова' : 'Закрыть опрос'; ?>
                            </button>
                        </form>
                        <form method="post" action="/nj520/admin/delete/<?php echo (int) $detail['poll']['id']; ?>">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <button data-n2j-arm="Точно удалить?" type="submit"
                                    class="n2j-btn-danger n2j-btn-sm"><i class="fas fa-trash mr-1"></i>Удалить опрос</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Список опросов -->
                <h2 class="mt-5 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Последние опросы</h2>
                <?php if (empty($polls)): ?>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Опросов ещё нет — создайте первый на <a href="/poll" class="text-blue-600 hover:underline dark:text-blue-400">/poll</a>.</p>
                <?php else: ?>
                <div class="mt-3 overflow-x-auto">
                    <table class="n2j-table">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
                                <th class="py-2.5 pr-3 font-semibold">Опрос</th>
                                <th class="py-2.5 pr-3 font-semibold">Код</th>
                                <th class="py-2.5 pr-3 font-semibold">Вариантов</th>
                                <th class="py-2.5 pr-3 font-semibold">Голосов</th>
                                <th class="py-2.5 pr-3 font-semibold">Статус</th>
                                <th class="py-2.5 pr-3 font-semibold">Создан</th>
                                <th class="py-2 font-semibold text-right"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($polls as $p): ?>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="max-w-[24rem] py-2 pr-3">
                                    <a href="/nj520/admin/poll/<?php echo HTML::chars($p['code']); ?>" class="block truncate text-xs font-semibold text-gray-800 hover:text-blue-600 dark:text-gray-200 dark:hover:text-blue-400" title="<?php echo HTML::chars($p['title']); ?>"><?php echo HTML::chars($p['title']); ?></a>
                                </td>
                                <td class="py-2.5 pr-3"><a href="/p/<?php echo HTML::chars($p['code']); ?>" class="font-mono text-xs text-blue-600 hover:underline dark:text-blue-400">/p/<?php echo HTML::chars($p['code']); ?></a></td>
                                <td class="py-2.5 pr-3 text-xs text-gray-500 dark:text-gray-400"><?php echo (int) $p['options_cnt']; ?></td>
                                <td class="py-2.5 pr-3 text-xs font-bold text-gray-700 dark:text-gray-200"><?php echo (int) $p['votes']; ?></td>
                                <td class="py-2.5 pr-3">
                                    <?php $is_closed = ! empty($p['closed_at']); ?>
                                    <span class="n2j-badge <?php echo $is_closed ? 'n2j-badge-wait' : 'n2j-badge-ok'; ?>">
                                        <i class="fas <?php echo $is_closed ? 'fa-lock' : 'fa-circle-play'; ?> text-[10px]"></i><?php echo $is_closed ? 'Закрыт' : 'Активен'; ?>
                                    </span>
                                </td>
                                <td class="py-2.5 pr-3 text-xs text-gray-400"><?php echo HTML::chars((string) $p['created_at']); ?></td>
                                <td class="py-2 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <form method="post" action="/nj520/admin/close/<?php echo (int) $p['id']; ?>">
                                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                            <?php if ($is_closed): ?><input type="hidden" name="open" value="1"><?php endif; ?>
                                            <button type="submit" class="n2j-btn-secondary n2j-btn-sm" title="<?php echo $is_closed ? 'Открыть снова' : 'Закрыть — голосование прекратится'; ?>">
                                                <i class="fas <?php echo $is_closed ? 'fa-lock-open' : 'fa-lock'; ?>"></i>
                                            </button>
                                        </form>
                                        <form method="post" action="/nj520/admin/delete/<?php echo (int) $p['id']; ?>">
                                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                            <button data-n2j-arm="Точно?" type="submit"
                                                    class="n2j-btn-danger n2j-btn-sm"
                                                    title="Удалить опрос со всеми голосами"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===================== Правая колонка: пояснения ===================== -->
        <aside class="w-full shrink-0 space-y-4 xl:w-80 2xl:w-96">
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Что здесь</p>
                <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-gray-600 dark:text-gray-300">
                    <li>Все опросы платформы с числом голосов; клик по названию — подробности: варианты, доли и кто голосовал.</li>
                    <li>Один голос с одного браузера — защита на уровне записи: уникальный индекс по паре «опрос + ключ посетителя» (`u:<id>` у залогиненных, `g:<ubid>` у анонимов). Адреса сети не хранятся.</li>
                    <li>Удаление опроса каскадом убирает варианты и голоса — действие необратимо.</li>
                </ul>
            </div>
            <div class="<?php echo $card; ?>">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-link mr-1.5 text-gray-400"></i>Смежное</p>
                <ul class="mt-2 space-y-1 text-xs">
                    <li><a href="/poll" class="text-blue-600 hover:underline dark:text-blue-400">Быстрый опрос</a> <span class="text-gray-400">— создать опрос</span></li>
                    <li><a href="/dashboard" class="text-blue-600 hover:underline dark:text-blue-400">Дашборд системы</a> <span class="text-gray-400">— очередь и статусы</span></li>
                </ul>
            </div>
        </aside>
    </div>
</div>

