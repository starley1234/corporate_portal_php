<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница /receipt/<id> — разбор одного чека.
 *
 * Страница серверная: таблица позиций, сверка с итогом, категории и дележ
 * считаются в `Service_Nj650_Receipt`. Форма дележа — обычный GET, поэтому
 * ссылка с числом участников и чаевыми открывает тот же расчёт у любого,
 * кому вы её пришлёте (и работает без JavaScript).
 *
 * @var array $run    строка прогона
 * @var array $data   Service_Nj650_Receipt::shape()
 * @var array $live   контракт живого статус-ряда
 * @var string $hint  подсказка дожима (воркер не взял задачу)
 * @var int   $people участников дележа
 * @var float $tip    чаевые, %
 * @var array $split  Service_Nj650_Receipt::split()
 */

$status   = (string) $run['status'];
$currency = (string) $data['currency'];
$money    = static function ($v) use ($currency) { return Service_Nj650_Receipt::money($v, $currency); };
$cell     = 'px-3 py-2 text-right tabular-nums';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <div class="flex flex-wrap items-center gap-2">
        <a href="/receipt" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">← Новый чек</a>
        <span class="text-xs text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $run['created_at']))); ?></span>
    </div>

    <h1 class="n2j-h1 mt-2">
        <?php echo HTML::chars($data['merchant'] !== '' ? $data['merchant'] : 'Разбор чека'); ?>
    </h1>
    <?php if ($data['date'] !== ''): ?>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Дата на чеке: <?php echo HTML::chars(date('d.m.Y', strtotime($data['date']))); ?></p>
    <?php endif; ?>

    <?php if (in_array($status, ['queued', 'running'], TRUE)): ?>
        <?php echo View::factory('partials/live_status', [
            'url'     => '/receipt/api/status?id='.(int) $run['id'],
            'title'   => 'Читаю чек',
            'status'  => (string) $live['status'],
            'stage'   => (string) $live['stage'],
            'elapsed' => (int) $live['elapsed_sec'],
            'silent'  => $live['silent_sec'],
            'steps'   => (array) $live['stages'],
            'note'    => $hint !== '' ? $hint : 'Страница обновится сама, когда чек будет прочитан.',
        ]); ?>
        <?php echo View::factory('partials/live_status_js'); ?>
    <?php elseif ($status === 'error'): ?>
        <div class="n2j-flash n2j-flash-err mt-4 block">
            <p class="font-semibold">Чек не прочитался</p>
            <p class="mt-1"><?php echo HTML::chars((string) $run['error'] !== '' ? $run['error'] : 'Причина не записана.'); ?></p>
            <p class="mt-2"><a href="/receipt" class="font-semibold underline">Попробовать другой кадр</a></p>
        </div>
    <?php elseif ( ! $data['items']): ?>
        <div class="n2j-flash n2j-flash-warn mt-4 block">
            На фото не нашлось ни одной строки с ценой. Снимите чек ровно и целиком — со столбцом сумм.
        </div>
    <?php else: ?>

    <!-- ========================= сводка ========================= -->
    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="n2j-card">
            <p class="text-xs uppercase tracking-wide text-gray-400">Итого по чеку</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo HTML::chars($money($data['total'])); ?></p>
            <p class="n2j-hint">
                <?php echo count($data['items']).' '.Service_Nj650_Receipt::items_word(count($data['items'])); ?>
            </p>
        </div>
        <div class="n2j-card">
            <p class="text-xs uppercase tracking-wide text-gray-400">Сумма позиций</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white"><?php echo HTML::chars($money($data['items_total'])); ?></p>
            <p class="mt-1 text-xs <?php echo $data['check']['ok'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'; ?>">
                <?php echo $data['check']['ok'] ? 'сходится' : 'расхождение '.HTML::chars($money(abs($data['check']['delta']))); ?>
            </p>
        </div>
        <div class="n2j-card">
            <p class="text-xs uppercase tracking-wide text-gray-400">Самая дорогая позиция</p>
            <?php
                $top = $data['items'];
                usort($top, function ($a, $b) { return $b['sum'] <=> $a['sum']; });
            ?>
            <p class="mt-1 truncate text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($top[0]['name']); ?></p>
            <p class="n2j-hint tabular-nums"><?php echo HTML::chars($money($top[0]['sum'])); ?></p>
        </div>
    </div>

    <?php if ( ! $data['check']['ok']): ?>
    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
        <i class="fas fa-triangle-exclamation mr-1"></i><?php echo HTML::chars($data['check']['message']); ?>
    </p>
    <?php endif; ?>

    <?php if ((string) ($data['note'] ?? '') !== ''): ?>
    <p class="n2j-hint mt-2">Пометка модели: <?php echo HTML::chars($data['note']); ?></p>
    <?php endif; ?>

    <!-- ========================= позиции ========================= -->
    <section class="n2j-card p-0 mt-5 overflow-hidden">
        <h2 class="n2j-h3 border-b border-gray-200 px-4 py-3">Позиции чека</h2>
        <div class="overflow-x-auto">
            <table class="n2j-table min-w-[34rem]">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Позиция</th>
                        <th class="px-3 py-2 text-right">Кол-во</th>
                        <th class="px-3 py-2 text-right">Цена</th>
                        <th class="px-3 py-2 text-right">Сумма</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <?php foreach ($data['items'] as $item): ?>
                    <tr>
                        <td class="px-3 py-2">
                            <span class="text-gray-900 dark:text-gray-100"><?php echo HTML::chars($item['name']); ?></span>
                            <span class="ml-1 text-xs text-gray-400"><?php echo HTML::chars(Service_Nj650_Receipt::CATEGORIES[$item['category']] ?? 'Прочее'); ?></span>
                        </td>
                        <td class="<?php echo $cell; ?> text-gray-600 dark:text-gray-300"><?php echo HTML::chars(rtrim(rtrim(number_format($item['qty'], 3, ',', ' '), '0'), ',')); ?></td>
                        <td class="<?php echo $cell; ?> text-gray-600 dark:text-gray-300"><?php echo HTML::chars($money($item['price'])); ?></td>
                        <td class="<?php echo $cell; ?> font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($money($item['sum'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- ========================= дележ ========================= -->
    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Поделить счёт</h2>

        <form method="get" action="/receipt/<?php echo (int) $run['id']; ?>" class="mt-3 flex flex-wrap items-end gap-3">
            <div>
                <label for="rc-people" class="n2j-label">Человек</label>
                <input id="rc-people" name="people" inputmode="numeric" value="<?php echo (int) $people; ?>"
                       class="n2j-input mt-1.5 w-24 font-semibold">
            </div>
            <div>
                <label for="rc-tip" class="n2j-label">Чаевые, %</label>
                <input id="rc-tip" name="tip" inputmode="decimal" value="<?php echo HTML::chars(rtrim(rtrim(number_format($tip, 1, ',', ''), '0'), ',')); ?>"
                       class="n2j-input mt-1.5 w-24 font-semibold">
            </div>
            <button type="submit" class="n2j-btn-process">
                Посчитать
            </button>
        </form>

        <?php if ($split['people'] > 1 OR $split['tip_sum'] > 0): ?>
        <div class="mt-4 flex flex-wrap gap-2">
            <?php foreach ($split['shares'] as $n => $sum): ?>
            <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                <p class="n2j-hint">Участник <?php echo (int) $n; ?></p>
                <p class="font-mono text-sm font-bold tabular-nums text-gray-900 dark:text-white"><?php echo HTML::chars($money($sum)); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="n2j-hint mt-2">
            Итого к оплате <?php echo HTML::chars($money($split['total'])); ?><?php if ($split['tip_sum'] > 0): ?>,
            из них чаевые <?php echo HTML::chars($money($split['tip_sum'])); ?><?php endif; ?>.
            Копейки розданы так, что сумма долей точно равна итогу.
        </p>
        <?php endif; ?>
    </section>

    <!-- ========================= категории ========================= -->
    <?php if (count($data['categories']) > 1): ?>
    <section class="n2j-card mt-5">
        <h2 class="n2j-h3">Куда ушли деньги</h2>
        <ul class="mt-2 space-y-1.5 text-sm">
            <?php foreach ($data['categories'] as $cat): ?>
            <li class="flex items-center gap-3">
                <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-200"><?php echo HTML::chars($cat['title']); ?></span>
                <span class="font-mono text-xs tabular-nums text-gray-400"><?php echo HTML::chars(number_format($cat['share'], 1, ',', ' ')); ?> %</span>
                <span class="font-mono text-sm font-semibold tabular-nums text-gray-900 dark:text-white"><?php echo HTML::chars($money($cat['sum'])); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php endif; ?>

</div>

<aside class="mt-5 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Что проверить глазами</h2>
        <ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
            <li>· Строки с весовым товаром: «0,482 кг» модель иногда читает как количество штук.</li>
            <li>· Скидки и бонусы: они бывают отдельной строкой со знаком минус.</li>
            <li>· Итог чека — главный ориентир: если он не сошёлся, виноват кадр, а не арифметика.</li>
        </ul>
    </section>

    <section class="n2j-card">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Ссылка на дележ</h2>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            Число участников и чаевые попадают в адрес страницы — перешлите его тем, с кем делите счёт,
            и они увидят тот же расчёт.
        </p>
    </section>
</aside>
</div>
</div>
