<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Калькулятор зарплаты (nj503_salary) — страница.
 *
 * @var array      $form   значения формы (из адреса)
 * @var array|NULL $init   первый расчёт сервера
 * @var int[]      $years
 * @var array      $params Service_Nj503_Salary::all_params()
 */

$input = 'n2j-input mt-1.5 w-full font-semibold';
$label = 'n2j-label';
$check = 'inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300';

$rub = function ($x) { return number_format((float) $x, 0, ',', "\xC2\xA0"); };
$mln = function ($x) { return rtrim(rtrim(number_format($x / 1000000, 1, ',', ''), '0'), ','); };

/** «13 % до 2,4 млн · 15 % до 5 млн · … 22 % свыше 50 млн» */
$scale_text = function (array $scale) use ($mln) {
    $parts = [];
    foreach ($scale as $i => $band)
    {
        $parts[] = isset($scale[$i + 1])
            ? $band[1].' % до '.$mln($scale[$i + 1][0]).' млн'
            : $band[1].' % '.($i === 0 ? 'со всего дохода' : 'свыше '.$mln($band[0]).' млн');
    }
    return implode(' · ', $parts);
};

$advanced_open = $form['children'] > 0 || $form['disabled'] > 0 || $form['single'] || ! $form['resident'] || $form['sme'] || (float) $form['injury'] !== 0.2;
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <!-- ============ Форма ============ -->
    <div class="n2j-card">

        <div id="sal-modes" role="tablist" class="flex flex-wrap gap-2">
            <button type="button" role="tab" data-mode="gross" aria-selected="<?php echo $form['mode'] === 'gross' ? 'true' : 'false'; ?>"
                    class="n2j-chip sal-mode<?php echo $form['mode'] === 'gross' ? ' is-on' : ''; ?>">
                <i class="fas fa-arrow-right"></i>Оклад → на руки</button>
            <button type="button" role="tab" data-mode="net" aria-selected="<?php echo $form['mode'] === 'net' ? 'true' : 'false'; ?>"
                    class="n2j-chip sal-mode<?php echo $form['mode'] === 'net' ? ' is-on' : ''; ?>">
                <i class="fas fa-arrow-left"></i>На руки → оклад</button>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="sal-amount" id="sal-amount-label" class="<?php echo $label; ?>"><?php echo $form['mode'] === 'net' ? 'Нужно получать на руки, ₽ в месяц' : 'Оклад до вычета налога, ₽ в месяц'; ?></label>
                <input id="sal-amount" type="text" inputmode="decimal" autocomplete="off" value="<?php echo HTML::chars($form['amount']); ?>" placeholder="100 000" class="<?php echo $input; ?>">
            </div>
            <div>
                <label for="sal-year" class="<?php echo $label; ?>">Год</label>
                <select id="sal-year" class="n2j-input n2j-select mt-1.5 w-full font-semibold">
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo (int) $y; ?>" <?php echo (int) $y === (int) $form['year'] ? 'selected' : ''; ?>><?php echo (int) $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-2" id="sal-presets">
            <?php foreach ([30000, 50000, 80000, 100000, 150000, 250000] as $p): ?>
                <button type="button" data-amount="<?php echo $p; ?>" class="n2j-chip sal-preset"><?php echo $rub($p); ?></button>
            <?php endforeach; ?>
        </div>

        <details class="mt-4 group" <?php echo $advanced_open ? 'open' : ''; ?>>
            <summary class="cursor-pointer list-none text-sm font-semibold text-gray-700 marker:hidden dark:text-gray-300">
                <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>Дети, резидентство, тариф взносов
            </summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div><label for="sal-children" class="<?php echo $label; ?>">Детей до 18 (24) лет</label>
                    <input id="sal-children" type="number" min="0" max="10" step="1" value="<?php echo (int) $form['children']; ?>" class="<?php echo $input; ?>"></div>
                <div><label for="sal-disabled" class="<?php echo $label; ?>">Из них детей-инвалидов</label>
                    <input id="sal-disabled" type="number" min="0" max="10" step="1" value="<?php echo (int) $form['disabled']; ?>" class="<?php echo $input; ?>"></div>
                <div><label for="sal-injury" class="<?php echo $label; ?>">Взносы «на травматизм», %</label>
                    <input id="sal-injury" type="number" min="0" max="8.5" step="0.1" value="<?php echo HTML::chars($form['injury']); ?>" class="<?php echo $input; ?>"></div>
            </div>
            <div class="mt-3 flex flex-wrap gap-x-6 gap-y-2">
                <label class="<?php echo $check; ?>"><input id="sal-single" type="checkbox" class="h-4 w-4 rounded border-gray-300" <?php echo $form['single'] ? 'checked' : ''; ?>> Единственный родитель (вычет ×2)</label>
                <label class="<?php echo $check; ?>"><input id="sal-nr" type="checkbox" class="h-4 w-4 rounded border-gray-300" <?php echo $form['resident'] ? '' : 'checked'; ?>> Нерезидент РФ (НДФЛ 30 %)</label>
                <label class="<?php echo $check; ?>"><input id="sal-sme" type="checkbox" class="h-4 w-4 rounded border-gray-300" <?php echo $form['sme'] ? 'checked' : ''; ?>> Работодатель — МСП (льготный тариф)</label>
            </div>
        </details>
    </div>

    <!-- ============ Результат ============ -->
    <div id="sal-result" class="mt-4" aria-live="polite"></div>

    <!-- ============ Ставки по годам (SSR, для поисковиков и справки) ============ -->
    <section class="n2j-card mt-6">
        <h2 class="n2j-h3">Ставки и вычеты по годам</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="n2j-table min-w-[640px]">
                <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-3">Год</th>
                        <th class="py-2 pr-3">НДФЛ резидента (с дохода за год)</th>
                        <th class="py-2 pr-3">Вычет на детей, ₽/мес</th>
                        <th class="py-2 pr-3">Предел дохода для вычета</th>
                        <th class="py-2 pr-3">Предельная база взносов</th>
                        <th class="py-2">МРОТ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700 text-gray-700 dark:text-gray-200">
                    <?php foreach (array_reverse($years) as $y): $p = $params[$y]; ?>
                        <tr>
                            <td class="py-2 pr-3 font-bold"><?php echo (int) $y; ?></td>
                            <td class="py-2 pr-3"><?php echo HTML::chars($scale_text($p['ndfl_scale'])); ?></td>
                            <td class="py-2 pr-3"><?php echo $rub($p['child'][0]); ?> / <?php echo $rub($p['child'][1]); ?> / <?php echo $rub($p['child'][2]); ?> (+<?php echo $rub($p['child_disabled']); ?> за инвалидность)</td>
                            <td class="py-2 pr-3"><?php echo $rub($p['child_limit']); ?> ₽</td>
                            <td class="py-2 pr-3"><?php echo $rub($p['contrib_limit']); ?> ₽ (<?php echo $p['contrib_rate']; ?> % → <?php echo str_replace('.', ',', (string) $p['contrib_over']); ?> %)</td>
                            <td class="py-2"><?php echo $rub($p['mrot']); ?> ₽</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="n2j-hint mt-3">
            Нерезидентам — 30 % без вычетов. Источники: <?php echo HTML::chars($params[$form['year']]['source']); ?>.
            Расчёт справочный: окончательные суммы определяет бухгалтерия работодателя.
        </p>
    </section>

</div>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">

    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Что считает калькулятор</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>НДФЛ <b>нарастающим итогом</b> с начала года по прогрессивной шкале — как в бухгалтерии (ст. 226 НК РФ). Поэтому в месяц, где доход пересекает порог шкалы, «на руки» заметно падает.</li>
            <li>НДФЛ в <b>целых рублях</b> (ст. 52 НК РФ: от 50 копеек — вверх).</li>
            <li>Вычеты на детей — пока доход с начала года не превысит лимит.</li>
            <li>Страховые взносы работодателя и <b>полную стоимость</b> сотрудника для компании.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3" open>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Почему «на руки» в конце года меньше, чем в начале?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    С 2025 года НДФЛ прогрессивный: 13 % действует до 2,4 млн ₽ дохода за год, дальше 15 %, 18 %, 20 % и 22 %.
                    Налог считают с начала года нарастающим итогом, поэтому повышенная ставка включается с того месяца, где порог пройден.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как работает вычет на детей?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Он уменьшает налоговую базу: 1 400 ₽ на первого ребёнка, 2 800 ₽ на второго, 6 000 ₽ на третьего и каждого следующего
                    (+12 000 ₽ за ребёнка-инвалида). Вычет даётся, пока доход с начала года не превысил 450 000 ₽; у единственного родителя он удваивается.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Кто платит страховые взносы?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Работодатель — сверх зарплаты, из зарплаты сотрудника они не удерживаются: 30 % до предельной базы за год
                    (<?php echo $rub($params[$form['year']]['contrib_limit']); ?> ₽ в <?php echo (int) $form['year']; ?> году), дальше 15,1 %, плюс взносы «на травматизм» от 0,2 %.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Откуда берутся суммы «на руки → оклад»?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Подбирается минимальный оклад, при котором в первый месяц года на руки приходит ровно нужная сумма
                    (до копейки). Следующие месяцы показаны в таблице — они могут отличаться из-за шкалы НДФЛ.
                </p>
            </details>
        </div>
        <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          "mainEntity": [
            {"@type": "Question", "name": "Почему зарплата на руки к концу года может уменьшиться?",
             "acceptedAnswer": {"@type": "Answer", "text": "С 2025 года НДФЛ прогрессивный: 13 % до 2,4 млн рублей дохода за год, далее 15 %, 18 %, 20 % и 22 %. Налог считается нарастающим итогом, поэтому повышенная ставка начинает действовать с месяца, где порог пройден."}},
            {"@type": "Question", "name": "Какой вычет на детей по НДФЛ?",
             "acceptedAnswer": {"@type": "Answer", "text": "1 400 рублей на первого ребёнка, 2 800 на второго, 6 000 на третьего и каждого следующего, плюс 12 000 за ребёнка-инвалида. Вычет действует, пока доход с начала года не превысил 450 000 рублей; единственному родителю положен двойной размер."}},
            {"@type": "Question", "name": "Кто платит страховые взносы с зарплаты?",
             "acceptedAnswer": {"@type": "Answer", "text": "Работодатель, сверх зарплаты: 30 % до предельной базы за год, 15,1 % сверх неё, плюс взносы на травматизм от 0,2 %. Из зарплаты сотрудника они не удерживаются."}}
          ]
        }
        </script>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('503/script', ['form' => $form, 'init' => $init]);
