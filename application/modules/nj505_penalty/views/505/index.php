<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Калькулятор пеней и неустойки (nj505_penalty) — страница.
 *
 * @var array  $form
 * @var array  $last_rate   ['date', 'rate']
 * @var array  $rates_table история ключевой ставки (новые сверху)
 * @var string $rates_as_of дата проверки таблицы
 */

$modes = [
    'tax'      => ['Пени по налогам',    'fas fa-landmark',   'ст. 75 НК РФ'],
    'gk395'    => ['Проценты по ст. 395 ГК', 'fas fa-scale-balanced', 'ключевая ставка'],
    'contract' => ['Неустойка по договору',  'fas fa-file-signature', '% за каждый день'],
];

$input = 'n2j-input mt-1.5 w-full font-semibold';
$label = 'n2j-label';
$check = 'inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300';
$rate_fmt = function ($r) { return str_replace('.', ',', rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.')); };
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <!-- ============ Режимы ============ -->
    <div id="pen-modes" role="tablist" class="flex flex-wrap gap-2">
        <?php foreach ($modes as $code => $m): $on = ($code === $form['mode']); ?>
            <button type="button" role="tab" data-mode="<?php echo $code; ?>" aria-selected="<?php echo $on ? 'true' : 'false'; ?>"
                    class="n2j-chip pen-mode<?php echo $on ? ' is-on' : ''; ?>">
                <i class="<?php echo $m[1]; ?>"></i><?php echo HTML::chars($m[0]); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- ============ Форма ============ -->
    <div class="n2j-card mt-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label for="pen-amount" class="<?php echo $label; ?>">Сумма долга, ₽</label>
                <input id="pen-amount" type="text" inputmode="decimal" autocomplete="off" value="<?php echo HTML::chars($form['amount']); ?>" placeholder="100 000" class="<?php echo $input; ?>">
            </div>
            <div>
                <label for="pen-due" class="<?php echo $label; ?>">Срок платежа (последний день без просрочки)</label>
                <input id="pen-due" type="date" value="<?php echo HTML::chars($form['due']); ?>" class="<?php echo $input; ?>">
            </div>
            <div>
                <label for="pen-paid" class="<?php echo $label; ?>">Дата уплаты (пусто — сегодня)</label>
                <input id="pen-paid" type="date" value="<?php echo HTML::chars($form['paid']); ?>" class="<?php echo $input; ?>">
            </div>
        </div>

        <div class="pen-opt mt-3 grid gap-3 sm:grid-cols-3" data-for="tax">
            <div>
                <label for="pen-payer" class="<?php echo $label; ?>">Кто платит</label>
                <select id="pen-payer" class="n2j-input n2j-select mt-1.5 w-full font-semibold">
                    <option value="org" <?php echo $form['payer'] === 'org' ? 'selected' : ''; ?>>Организация (1/300, с 31-го дня 1/150)</option>
                    <option value="person" <?php echo $form['payer'] === 'person' ? 'selected' : ''; ?>>ИП или физлицо (всегда 1/300)</option>
                </select>
            </div>
        </div>

        <div class="pen-opt mt-3 grid gap-3 sm:grid-cols-3" data-for="contract">
            <div>
                <label for="pen-rate" class="<?php echo $label; ?>">Неустойка, % в день</label>
                <input id="pen-rate" type="text" inputmode="decimal" value="<?php echo HTML::chars($form['rate']); ?>" placeholder="0,1" class="<?php echo $input; ?>">
            </div>
            <div>
                <label for="pen-cap" class="<?php echo $label; ?>">Потолок, % от долга (пусто — нет)</label>
                <input id="pen-cap" type="text" inputmode="decimal" value="<?php echo HTML::chars($form['cap']); ?>" placeholder="10" class="<?php echo $input; ?>">
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2">
            <label class="<?php echo $check; ?>"><input id="pen-incl" type="checkbox" class="h-4 w-4 rounded border-gray-300" <?php echo $form['incl'] ? 'checked' : ''; ?>> Считать день уплаты</label>
            <label class="pen-opt <?php echo $check; ?>" data-for="gk395 contract"><input id="pen-mor" type="checkbox" class="h-4 w-4 rounded border-gray-300" <?php echo $form['moratorium'] ? 'checked' : ''; ?>> Мораторий 01.04–01.10.2022 (ПП РФ № 497)</label>
            <button type="button" id="pen-example" class="n2j-chip ml-auto"><i class="fas fa-wand-magic-sparkles"></i> Подставить пример</button>
        </div>
    </div>

    <!-- ============ Результат ============ -->
    <div id="pen-result" class="mt-4" aria-live="polite"></div>

</div>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">

    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-percent mr-1.5 text-blue-500"></i>Ключевая ставка ЦБ РФ</p>
        <p class="mt-2 text-3xl font-extrabold text-gray-900 dark:text-white"><?php echo $rate_fmt($last_rate['rate']); ?> %</p>
        <p class="n2j-hint">действует с <?php echo HTML::chars(date('d.m.Y', strtotime($last_rate['date']))); ?>; таблица проверена <?php echo HTML::chars(date('d.m.Y', strtotime($rates_as_of))); ?></p>
        <details class="mt-3 group">
            <summary class="cursor-pointer list-none text-xs font-semibold text-blue-600 marker:hidden dark:text-blue-400">
                <i class="fas fa-chevron-down mr-1.5 transition group-open:rotate-180"></i>История изменений
            </summary>
            <table class="n2j-table mt-2">
                <?php foreach ($rates_table as $row): ?>
                    <tr class="border-t border-gray-100 dark:border-gray-700"><td class="py-1"><?php echo HTML::chars($row['human']); ?></td><td class="py-1 text-right font-semibold"><?php echo $rate_fmt($row['rate']); ?> %</td></tr>
                <?php endforeach; ?>
            </table>
        </details>
    </div>

    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Как считается</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li><b>Пени по НК:</b> сумма × ключевая ставка × дни ÷ 300 (организациям с 31-го дня — ÷ 150).</li>
            <li><b>Ст. 395 ГК:</b> сумма × ставка × дни ÷ 365 или 366 — по каждому году отдельно.</li>
            <li><b>Неустойка:</b> сумма × % в день × дни, не больше потолка. Для сравнения — вариант по ст. 395 ГК и по двойной ключевой ставке.</li>
            <li>При смене ставки период делится на строки таблицы; сумма каждой округляется до копейки.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3" open>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Чем 1/300 отличается от 1/150?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    По статье 75 НК РФ пени — это 1/300 ключевой ставки за каждый день просрочки. Организации с 31-го календарного дня
                    просрочки платят 1/150, а ИП и физлица — по-прежнему 1/300.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    С какого дня считаются пени?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Со следующего дня после срока платежа и по день уплаты включительно. Если срок выпал на выходной,
                    он переносится на ближайший рабочий день — укажите уже перенесённую дату.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Что такое мораторий 2022 года?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    С 01.04.2022 по 01.10.2022 (постановление Правительства РФ № 497) не начислялись неустойки и проценты по ст. 395 ГК
                    на долги, возникшие до 01.04.2022, если должник не отказался от моратория. На налоговые пени мораторий не распространяется.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Может ли суд снизить неустойку?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да, по статье 333 ГК РФ, если неустойка явно несоразмерна нарушению; нижней границей обычно служат
                    проценты по ст. 395 ГК (однократная ключевая ставка). Калькулятор показывает обе суммы для сравнения.
                </p>
            </details>
        </div>
        <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          "mainEntity": [
            {"@type": "Question", "name": "Чем пени 1/300 отличаются от 1/150?",
             "acceptedAnswer": {"@type": "Answer", "text": "Пени по ст. 75 НК РФ — 1/300 ключевой ставки Банка России за каждый день просрочки. Организации с 31-го календарного дня просрочки платят 1/150, а ИП и физические лица — всегда 1/300."}},
            {"@type": "Question", "name": "С какого дня начисляются пени по налогам?",
             "acceptedAnswer": {"@type": "Answer", "text": "Со следующего дня после срока платежа и по день уплаты включительно. Если срок пришёлся на выходной, он переносится на ближайший рабочий день."}},
            {"@type": "Question", "name": "Что такое мораторий 2022 года на неустойки?",
             "acceptedAnswer": {"@type": "Answer", "text": "С 1 апреля по 1 октября 2022 года (постановление Правительства РФ № 497) не начислялись неустойки и проценты по ст. 395 ГК РФ на долги, возникшие до 1 апреля 2022 года, если должник не отказался от моратория. На налоговые пени он не распространяется."}},
            {"@type": "Question", "name": "Может ли суд уменьшить договорную неустойку?",
             "acceptedAnswer": {"@type": "Answer", "text": "Да, по ст. 333 ГК РФ, если неустойка явно несоразмерна последствиям нарушения; ориентиром служат проценты по ст. 395 ГК РФ по ключевой ставке."}}
          ]
        }
        </script>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('505/script', ['form' => $form]);
