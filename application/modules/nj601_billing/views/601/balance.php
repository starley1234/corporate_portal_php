<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj601_billing: /billing — баланс кредитов, пакеты, история операций.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$lbl  = 'text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500';
?>
<div class="n2j-ds w-full space-y-6 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-warn font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <?php if ( ! Service_Nj601_Billing::enabled()): ?>
    <!-- nj601_billing: монетизация выключена — explaining banner вместо прайса -->
    <div class="n2j-flash n2j-flash-ok font-semibold block"><i class="fas fa-circle-check mr-1.5"></i>Монетизация выключена (NJ601_BILLING_ENABLED=0) — все операции бесплатны, норма не тратится.</div>
    <?php endif; ?>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="<?php echo $card; ?> sm:col-span-2">
            <p class="<?php echo $lbl; ?>">Баланс кредитов</p>
            <p class="mt-1 text-4xl font-black text-gray-900 dark:text-white"><?php echo (int) $state['balance']; ?></p>
            <p class="n2j-hint">Бесплатных в этом месяце осталось: <b><?php echo (int) $state['free_left']; ?></b> · субъект <?php echo HTML::chars($state['subject']); ?></p>
            <?php if ($test_mode): ?><p class="mt-2 rounded-lg bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">Robokassa в тестовом режиме — платежи эмулируются</p><?php endif; ?>
        </div>
        <div class="<?php echo $card; ?>">
            <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-blue-500"></i>Что тратится</p>
            <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                <?php foreach ($costs as $mod => $c): if ((int) $c <= 0) continue; ?>
                <li class="flex justify-between gap-2"><span><?php echo HTML::chars(str_replace('_', ' ', (string) $mod)); ?></span><b><?php echo (int) $c; ?></b></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Пакеты -->
    <div>
        <h2 class="n2j-h3 mb-3">Пополнить</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            <?php foreach ($packs as $key => $p): ?>
            <div class="<?php echo $card; ?> flex flex-col">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($p['label']); ?></p>
                <p class="mt-1 text-2xl font-black text-blue-600 dark:text-blue-400"><?php echo number_format((int) $p['price'], 0, '', ' '); ?> ₽</p>
                <form method="post" action="/billing" class="mt-auto pt-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="pack" value="<?php echo HTML::chars($key); ?>">
                    <button type="submit" class="n2j-btn-cta w-full">Купить</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="n2j-hint mt-2">Оплата через Robokassa (карты, СБП). Кредиты не сгорают.</p>
    </div>

    <!-- История -->
    <div class="<?php echo $card; ?>">
        <h2 class="n2j-h3 border-b border-gray-100 pb-3">История операций</h2>
        <?php if (empty($history)): ?>
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Операций пока нет.</p>
        <?php else: ?>
        <ul class="mt-1 divide-y divide-gray-50 dark:divide-gray-700/40">
            <?php foreach ($history as $h): ?>
            <li class="flex flex-wrap items-center gap-2 py-2 text-xs">
                <span class="w-14 shrink-0 font-mono <?php echo (int) $h['delta'] < 0 ? 'text-rose-500' : 'text-emerald-600'; ?>"><?php echo (int) $h['delta'] > 0 ? '+' : ''; echo (int) $h['delta']; ?></span>
                <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($h['module']); ?></span>
                <span class="min-w-0 flex-1 truncate text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $h['note']); ?></span>
                <span class="text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $h['created_at'], 'd.m H:i')); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
