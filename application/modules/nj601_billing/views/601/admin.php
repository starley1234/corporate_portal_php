<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj601/admin — счета Robokassa: сводка, ручной тестовый счёт, список.
 */
$st = function ($v) {
    $map = ['paid' => 'оплачен', 'new' => 'новый', 'failed' => 'отменён'];

    return $map[$v] ?? $v;
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dashboard" class="transition hover:text-emerald-600 dark:hover:text-emerald-400">Кабинет</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Счета Robokassa</span>
    </nav>

    <h1 class="n2j-h1 mt-2">
        <i class="fas fa-credit-card mr-2 text-emerald-600 dark:text-emerald-400"></i>Счета Robokassa
        <?php if ( ! $configured): ?><span class="n2j-badge n2j-badge-err ml-2 uppercase">не настроено</span>
        <?php elseif ($is_test): ?><span class="n2j-badge n2j-badge-run ml-2 uppercase">тестовый режим</span><?php endif; ?>
    </h1>

    <?php if ( ! $configured): ?>
    <p class="n2j-flash n2j-flash-warn mt-2 max-w-3xl text-xs block">
        Задайте в <code>.env</code>: NJ601_ROBOKASSA_LOGIN, NJ601_ROBOKASSA_PASSWORD1, NJ601_ROBOKASSA_PASSWORD2 (и NJ601_ROBOKASSA_IS_TEST=0 для боевого режима).
        В кабинете Robokassa укажите Result URL: <b>https://n2j.ru/billing/result</b>, алгоритм хеша — MD5.
    </p>
    <?php endif; ?>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 break-all text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Оплачено счетов</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo (int) $stats['paid_cnt']; ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Оплачено на сумму</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo HTML::chars($stats['paid_rub']); ?> ₽</p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase text-gray-400">Счетов всего</p>
            <p class="mt-1 text-2xl font-black text-gray-900 dark:text-white"><?php echo (int) $stats['total']; ?></p>
        </div>
    </div>

    <div class="n2j-card mt-4">
        <p class="text-sm font-bold text-gray-900 dark:text-white">Создать тестовый счёт</p>
        <form method="post" action="/nj601/admin" class="mt-2 flex flex-wrap items-end gap-2">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <label class="text-xs font-semibold text-gray-700 dark:text-gray-300">Сумма, ₽
                <input type="number" name="amount" min="10" max="500000" step="0.01" value="100" required
                       class="n2j-input mt-0.5 w-28">
            </label>
            <label class="min-w-0 flex-1 text-xs font-semibold text-gray-700 dark:text-gray-300">Описание
                <input type="text" name="description" maxlength="100" value="Тестовый платёж N2J.ru"
                       class="n2j-input mt-0.5 w-full">
            </label>
            <button type="submit" class="n2j-btn-cta">Создать</button>
        </form>
    </div>

    <div class="n2j-card p-0 mt-4 overflow-x-auto">
        <table class="n2j-table">
            <thead>
                <tr class="text-left text-gray-400">
                    <th class="px-3 py-2.5">№</th><th>Плательщик</th><th>Описание</th><th>Сумма</th><th>Статус</th><th>Создан</th><th>Оплачен</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 dark:text-gray-300">
                <?php foreach ($invoices as $r): ?>
                <tr class="border-t border-gray-50 dark:border-gray-700/50">
                    <td class="px-3 py-2 font-mono"><?php echo (int) $r['id']; ?></td>
                    <td class="max-w-[12rem] truncate font-semibold"><?php echo HTML::chars($r['owner']); ?></td>
                    <td class="max-w-[16rem] truncate"><?php echo HTML::chars($r['description']); ?></td>
                    <td class="whitespace-nowrap font-bold"><?php echo HTML::chars($r['out_sum']); ?> ₽</td>
                    <td><span class="rounded px-1.5 py-0.5 font-bold <?php echo $r['status'] === 'paid' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'; ?>"><?php echo HTML::chars($st($r['status'])); ?></span></td>
                    <td class="whitespace-nowrap text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $r['created_at'], 'd.m.y H:i')); ?></td>
                    <td class="whitespace-nowrap text-gray-400"><?php echo $r['paid_at'] ? HTML::chars(Date::formatted_time((string) $r['paid_at'], 'd.m.y H:i')) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ( ! $invoices): ?><tr><td colspan="7" class="px-3 py-4 text-gray-400">Счетов пока нет.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
