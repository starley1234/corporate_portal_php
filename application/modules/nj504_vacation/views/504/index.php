<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Отпуск (nj504_vacation) — страница.
 *
 * @var string $tab   plan|dates|pay|comp
 * @var int[]  $known годы с календарём
 * @var string $today Y-m-d
 * @var array  $q     значения форм из адреса
 */

$tabs = [
    'plan'  => ['Выгодные даты',   'fas fa-wand-magic-sparkles'],
    'dates' => ['Даты отпуска',    'fas fa-calendar-check'],
    'pay'   => ['Отпускные',       'fas fa-ruble-sign'],
    'comp'  => ['Компенсация',     'fas fa-door-open'],
];

$input = 'n2j-input mt-1.5 w-full font-semibold';
$label = 'n2j-label';
$chip  = 'n2j-chip';
$rate_select = function ($id, $value) use ($input) {
    $o = '<select id="'.$id.'" class="'.$input.' n2j-select">';
    foreach ([13, 15, 18, 20, 22] as $r)
    {
        $o .= '<option value="'.$r.'"'.((int) $value === $r ? ' selected' : '').'>'.$r.' %</option>';
    }
    return $o.'</select>';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <!-- ============ Вкладки ============ -->
    <div id="vac-tabs" role="tablist" class="flex flex-wrap gap-2">
        <?php foreach ($tabs as $code => $t): $on = ($code === $tab); ?>
            <button type="button" role="tab" data-tab="<?php echo $code; ?>" aria-selected="<?php echo $on ? 'true' : 'false'; ?>"
                    class="n2j-chip vac-tab<?php echo $on ? ' is-on' : ''; ?>">
                <i class="<?php echo $t[1]; ?>"></i><?php echo HTML::chars($t[0]); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- ============ Формы ============ -->
    <div class="n2j-card mt-4">

        <!-- Выгодные даты -->
        <div class="vac-form hidden" data-form="plan">
            <p class="text-sm text-gray-600 dark:text-gray-300">Сколько дней отпуска у вас есть? Подберём, с какой даты их выгоднее всего взять: праздники и выходные вокруг отпуска сложатся в самый длинный отдых.</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div><label for="vac-p-year" class="<?php echo $label; ?>">Год</label>
                    <select id="vac-p-year" class="n2j-input n2j-select mt-1.5 w-full font-semibold">
                        <?php foreach ($known as $y): ?>
                            <option value="<?php echo (int) $y; ?>" <?php echo (int) $y === (int) $q['year'] ? 'selected' : ''; ?>><?php echo (int) $y; ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div><label for="vac-p-days" class="<?php echo $label; ?>">Дней отпуска</label>
                    <input id="vac-p-days" type="number" min="1" max="42" step="1" value="<?php echo HTML::chars($q['days']); ?>" class="<?php echo $input; ?>"></div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php foreach ([7, 10, 14, 21, 28] as $n): ?>
                    <button type="button" data-days="<?php echo $n; ?>" class="vac-preset <?php echo $chip; ?>"><?php echo $n; ?> дн.</button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Даты отпуска -->
        <div class="vac-form hidden" data-form="dates">
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="vac-d-start" class="<?php echo $label; ?>">Начало отпуска</label>
                    <input id="vac-d-start" type="date" value="<?php echo HTML::chars($q['start']); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="vac-d-days" class="<?php echo $label; ?>">Дней отпуска</label>
                    <input id="vac-d-days" type="number" min="1" max="400" step="1" value="<?php echo HTML::chars($q['days']); ?>" class="<?php echo $input; ?>"></div>
            </div>
        </div>

        <!-- Отпускные -->
        <div class="vac-form hidden" data-form="pay">
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2"><label for="vac-y-income" class="<?php echo $label; ?>">Доход за расчётный период, ₽</label>
                    <input id="vac-y-income" type="text" inputmode="decimal" autocomplete="off" value="<?php echo HTML::chars($q['income']); ?>" placeholder="1 200 000" class="<?php echo $input; ?>"></div>
                <div><label for="vac-y-months" class="<?php echo $label; ?>">Полных месяцев</label>
                    <input id="vac-y-months" type="number" min="1" max="12" step="1" value="<?php echo HTML::chars($q['months']); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="vac-y-monthly" class="<?php echo $label; ?>">Или зарплата в месяц, ₽</label>
                    <input id="vac-y-monthly" type="text" inputmode="decimal" autocomplete="off" placeholder="100 000" class="<?php echo $input; ?>"></div>
                <div><label for="vac-y-days" class="<?php echo $label; ?>">Дней отпуска</label>
                    <input id="vac-y-days" type="number" min="1" max="400" step="1" value="<?php echo HTML::chars($q['days']); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="vac-y-rate" class="<?php echo $label; ?>">Ставка НДФЛ</label>
                    <?php echo $rate_select('vac-y-rate', $q['rate']); ?></div>
            </div>
        </div>

        <!-- Компенсация -->
        <div class="vac-form hidden" data-form="comp">
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="vac-c-hire" class="<?php echo $label; ?>">Дата приёма на работу</label>
                    <input id="vac-c-hire" type="date" value="<?php echo HTML::chars($q['hire']); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="vac-c-end" class="<?php echo $label; ?>">Последний рабочий день</label>
                    <input id="vac-c-end" type="date" value="<?php echo HTML::chars($q['dismiss'] ?: $today); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="vac-c-annual" class="<?php echo $label; ?>">Отпуск в год, дней</label>
                    <input id="vac-c-annual" type="number" min="1" max="70" step="1" value="<?php echo HTML::chars($q['annual']); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="vac-c-used" class="<?php echo $label; ?>">Уже использовано, дней</label>
                    <input id="vac-c-used" type="text" inputmode="decimal" value="<?php echo HTML::chars($q['used']); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="vac-c-income" class="<?php echo $label; ?>">Доход за 12 месяцев, ₽</label>
                    <input id="vac-c-income" type="text" inputmode="decimal" autocomplete="off" value="<?php echo HTML::chars($q['income']); ?>" placeholder="1 200 000" class="<?php echo $input; ?>"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label for="vac-c-months" class="<?php echo $label; ?>">Месяцев в расчёте</label>
                        <input id="vac-c-months" type="number" min="1" max="12" step="1" value="<?php echo HTML::chars($q['months']); ?>" class="<?php echo $input; ?>"></div>
                    <div><label for="vac-c-rate" class="<?php echo $label; ?>">НДФЛ</label>
                        <?php echo $rate_select('vac-c-rate', $q['rate']); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ Результат ============ -->
    <div id="vac-result" class="mt-4" aria-live="polite"></div>

</div>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">

    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Правила отпуска</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>Отпуск считается <b>календарными днями</b>: выходные внутри отпуска входят в него (ст. 114 ТК РФ).</li>
            <li><b>Праздничные дни</b> (1–8 января, 23 февраля, 8 марта, 1 и 9 мая, 12 июня, 4 ноября) в число дней отпуска не входят — отпуск продлевается (ст. 120 ТК РФ). Перенесённые выходные праздниками не считаются.</li>
            <li>Отпускные платят <b>не позднее чем за 3 календарных дня</b> до начала (ст. 136 ТК РФ).</li>
            <li>Средний дневной заработок = доход за 12 месяцев ÷ 12 ÷ 29,3 (ст. 139 ТК РФ).</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3" open>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как считаются отпускные?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Доход за последние 12 месяцев делят на 12 и на 29,3 — получается средний дневной заработок,
                    его умножают на число дней отпуска и удерживают НДФЛ. Калькулятор округляет средний заработок до копейки.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как праздники влияют на отпуск?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Праздничные дни не засчитываются в отпуск: если в 14 дней отпуска попали 1 и 9 мая, он продлится 16 календарных дней.
                    Выходные, наоборот, в отпуск входят. Поэтому выгодно ставить отпуск так, чтобы он «обнимал» праздники.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как считается компенсация при увольнении?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    За каждый отработанный месяц положено 28 ÷ 12 дня отпуска. Остаток от 15 дней округляется до месяца,
                    неиспользованные дни умножают на средний дневной заработок (ст. 127 ТК РФ).
                </p>
            </details>
        </div>
        <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          "mainEntity": [
            {"@type": "Question", "name": "Как рассчитать отпускные?",
             "acceptedAnswer": {"@type": "Answer", "text": "Доход за последние 12 месяцев делится на 12 и на 29,3 — это средний дневной заработок (ст. 139 ТК РФ). Он умножается на число дней отпуска, из суммы удерживается НДФЛ."}},
            {"@type": "Question", "name": "Входят ли праздники в дни отпуска?",
             "acceptedAnswer": {"@type": "Answer", "text": "Нет. Нерабочие праздничные дни не включаются в число календарных дней отпуска и продлевают его (ст. 120 ТК РФ). Выходные дни в отпуск входят."}},
            {"@type": "Question", "name": "Как считается компенсация за неиспользованный отпуск?",
             "acceptedAnswer": {"@type": "Answer", "text": "За каждый отработанный месяц положено 28/12 дня отпуска; остаток от 15 дней округляется до целого месяца. Неиспользованные дни умножаются на средний дневной заработок."}}
          ]
        }
        </script>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('504/script', ['tab' => $tab, 'today' => $today]);
