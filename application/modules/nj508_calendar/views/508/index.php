<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Календарь и даты (nj508_calendar) — страница.
 *
 * @var string     $tab          year|between|until|age|add
 * @var int        $year
 * @var array|NULL $table        Service_Nj508_Calendar::year_table($year)
 * @var int[]      $known_years
 * @var int|NULL   $missing_year текущий год, которого нет в таблице
 * @var string     $today        Y-m-d
 * @var array      $q            значения форм из URL
 */

$tabs = [
    'year'    => ['Календарь года',       'fas fa-calendar-days'],
    'between' => ['Рабочие дни между датами', 'fas fa-business-time'],
    'until'   => ['Сколько до даты',      'fas fa-hourglass-half'],
    'age'     => ['Возраст и стаж',       'fas fa-cake-candles'],
    'add'     => ['Прибавить дни',        'fas fa-calendar-plus'],
];

/** Часы: 1 972 | 151,2 */
$num = function ($x) {
    $s = number_format((float) $x, 1, ',', "\xC2\xA0");
    return rtrim(rtrim($s, '0'), ',');
};

$input = 'n2j-input mt-1.5 w-full font-semibold';
$label = 'n2j-label';
$chip  = 'n2j-chip';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <!-- ============ Вкладки ============ -->
    <div id="cal-tabs" role="tablist" class="flex flex-wrap gap-2">
        <?php foreach ($tabs as $code => $t): $on = ($code === $tab); ?>
            <button type="button" role="tab" data-tab="<?php echo $code; ?>" aria-selected="<?php echo $on ? 'true' : 'false'; ?>"
                    class="n2j-chip cal-tab<?php echo $on ? ' is-on' : ''; ?>">
                <i class="<?php echo $t[1]; ?>"></i><?php echo HTML::chars($t[0]); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- ============ Формы ============ -->
    <div id="cal-forms" class="mt-4 rounded-xl border border-gray-200 bg-white p-4 sm:p-6 shadow-xs dark:border-gray-700 dark:bg-gray-800 <?php echo $tab === 'year' ? 'hidden' : ''; ?>">

        <div class="cal-form hidden" data-form="between">
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="cal-b-from" class="<?php echo $label; ?>">С даты</label>
                    <input id="cal-b-from" type="date" value="<?php echo HTML::chars($q['from'] ?: $today); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="cal-b-to" class="<?php echo $label; ?>">По дату</label>
                    <input id="cal-b-to" type="date" value="<?php echo HTML::chars($q['to']); ?>" class="<?php echo $input; ?>"></div>
            </div>
            <label class="mt-3 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input id="cal-b-incl" type="checkbox" class="h-4 w-4 rounded border-gray-300" <?php echo $q['inclusive'] === '0' ? '' : 'checked'; ?>>
                Считать первый и последний день
            </label>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="month">Этот месяц</button>
                <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="nextmonth">Следующий месяц</button>
                <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="quarter">Этот квартал</button>
                <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="year">Весь год</button>
            </div>
        </div>

        <div class="cal-form hidden" data-form="until">
            <label for="cal-u-target" class="<?php echo $label; ?>">До какой даты</label>
            <input id="cal-u-target" type="date" value="<?php echo HTML::chars($q['target']); ?>" class="<?php echo $input; ?> sm:max-w-xs">
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="ny">Новый год</button>
                <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="feb23">23 февраля</button>
                <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="mar8">8 Марта</button>
                <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="may1">1 мая</button>
                <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="jun1">1 июня</button>
                <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="sep1">1 сентября</button>
            </div>
        </div>

        <div class="cal-form hidden" data-form="age">
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="cal-a-birth" class="<?php echo $label; ?>">Дата рождения (или начала)</label>
                    <input id="cal-a-birth" type="date" value="<?php echo HTML::chars($q['birth']); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="cal-a-on" class="<?php echo $label; ?>">На дату</label>
                    <input id="cal-a-on" type="date" value="<?php echo HTML::chars($q['on'] ?: $today); ?>" class="<?php echo $input; ?>"></div>
            </div>
            <label class="mt-3 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input id="cal-a-incl" type="checkbox" class="h-4 w-4 rounded border-gray-300" <?php echo $q['inclusive'] === '1' ? 'checked' : ''; ?>>
                Включая последний день (для трудового стажа)
            </label>
        </div>

        <div class="cal-form hidden" data-form="add">
            <div class="grid gap-3 sm:grid-cols-3">
                <div><label for="cal-d-from" class="<?php echo $label; ?>">От даты</label>
                    <input id="cal-d-from" type="date" value="<?php echo HTML::chars($q['from'] ?: $today); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="cal-d-n" class="<?php echo $label; ?>">Сколько дней (минус — назад)</label>
                    <input id="cal-d-n" type="number" step="1" min="-5000" max="5000" value="<?php echo HTML::chars($q['n'] !== '' ? $q['n'] : '14'); ?>" class="<?php echo $input; ?>"></div>
                <div><label for="cal-d-kind" class="<?php echo $label; ?>">Какие дни</label>
                    <select id="cal-d-kind" class="n2j-input n2j-select mt-1.5 w-full font-semibold">
                        <option value="calendar" <?php echo $q['kind'] === 'calendar' ? 'selected' : ''; ?>>Календарные</option>
                        <option value="work" <?php echo $q['kind'] === 'work' ? 'selected' : ''; ?>>Рабочие</option>
                    </select></div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php foreach ([['7', 'calendar', '+7 дней'], ['14', 'calendar', '+14 дней'], ['30', 'calendar', '+30 дней'], ['10', 'work', '+10 рабочих'], ['30', 'work', '+30 рабочих']] as $p): ?>
                    <button type="button" class="cal-preset <?php echo $chip; ?>" data-preset="add" data-n="<?php echo $p[0]; ?>" data-kind="<?php echo $p[1]; ?>"><?php echo $p[2]; ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ============ Результат расчёта ============ -->
    <div id="cal-result" class="mt-4 <?php echo $tab === 'year' ? 'hidden' : ''; ?>" aria-live="polite"></div>

    <!-- ============ Календарь года (SSR) ============ -->
    <section id="cal-year" class="<?php echo $tab === 'year' ? '' : 'hidden'; ?>">

        <?php if ($missing_year): ?>
            <div class="n2j-flash n2j-flash-warn block">
                <i class="fas fa-triangle-exclamation mr-1.5"></i>Календаря на <?php echo (int) $missing_year; ?> год у нас ещё нет — переносы выходных утверждаются
                Правительством отдельным постановлением. Показан <?php echo (int) $year; ?> год; расчёты по датам внутри <?php echo (int) $missing_year; ?> года считаются только по субботам и воскресеньям и помечаются как приблизительные.
            </div>
        <?php endif; ?>

        <div class="flex flex-wrap items-center gap-2 <?php echo $missing_year ? 'mt-4' : ''; ?>">
            <?php foreach ($known_years as $y): $on = ((int) $y === (int) $year); ?>
                <a href="/calendar/<?php echo (int) $y; ?>"
                   class="n2j-chip<?php echo $on ? ' is-on' : ''; ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>><?php echo (int) $y; ?></a>
            <?php endforeach; ?>
            <?php if ($table): ?>
                <span class="ml-1 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($table['source']); ?></span>
            <?php endif; ?>
        </div>

        <?php if ($table): $tot = $table['totals']; ?>
            <div class="mt-4 grid gap-3 sm:grid-cols-4">
                <?php foreach ([['Рабочих дней', $tot['work']], ['Выходных и праздничных', $tot['off']], ['Сокращённых дней', $tot['short']], ['Часов (40-часовая неделя)', $num($tot['h40'])]] as $card): ?>
                    <div class="n2j-card text-center">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?php echo HTML::chars($card[0]); ?></p>
                        <p class="mt-1 text-2xl font-extrabold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $card[1]); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 2xl:grid-cols-3">
                <?php foreach ($table['months'] as $m): ?>
                    <div class="n2j-card">
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($m['name']); ?></h3>
                            <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo (int) $m['work']; ?> раб. · <?php echo (int) $m['off']; ?> вых.</span>
                        </div>
                        <div class="mt-2 grid grid-cols-7 gap-0.5 text-center text-xs">
                            <?php foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $i => $wd): ?>
                                <span class="py-0.5 font-semibold <?php echo $i >= 5 ? 'text-rose-400' : 'text-gray-400'; ?>"><?php echo $wd; ?></span>
                            <?php endforeach; ?>
                            <?php for ($i = 1; $i < $m['first_wd']; $i++): ?><span></span><?php endfor; ?>
                            <?php foreach ($m['days'] as $d => $info):
                                $key = sprintf('%04d-%02d-%02d', $year, $m['month'], $d);
                                $cls = 'text-gray-700 dark:text-gray-200';
                                if ($info['type'] === 'holiday') $cls = 'bg-rose-100 font-bold text-rose-700 dark:bg-rose-900/40 dark:text-rose-300';
                                elseif ($info['type'] === 'weekend') $cls = 'text-rose-500 dark:text-rose-400';
                                elseif ($info['type'] === 'work' AND $info['wd'] >= 6) $cls = 'bg-emerald-100 font-bold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300';
                                elseif ($info['short']) $cls = 'bg-amber-100 font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300';
                                if ($key === $today) $cls .= ' ring-2 ring-blue-500';
                                ?>
                                <span title="<?php echo HTML::chars($info['name']); ?>" class="rounded py-1 <?php echo $cls; ?>"><?php echo $d; ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span><span class="inline-block h-2.5 w-2.5 rounded-sm bg-rose-200 align-middle"></span> праздник или перенесённый выходной</span>
                <span><span class="inline-block h-2.5 w-2.5 rounded-sm bg-amber-200 align-middle"></span> сокращённый день (−1 час)</span>
                <span><span class="inline-block h-2.5 w-2.5 rounded-sm bg-emerald-200 align-middle"></span> рабочая суббота</span>
                <span><span class="inline-block h-2.5 w-2.5 rounded-sm ring-2 ring-blue-500 align-middle"></span> сегодня</span>
            </div>

            <div class="n2j-card p-0 mt-4 overflow-x-auto">
                <table class="n2j-table">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2 text-left">Месяц</th><th class="px-3 py-2 text-right">Дней</th>
                            <th class="px-3 py-2 text-right">Рабочих</th><th class="px-3 py-2 text-right">Выходных</th>
                            <th class="px-3 py-2 text-right">40 ч</th><th class="px-3 py-2 text-right">36 ч</th><th class="px-3 py-2 text-right">24 ч</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($table['months'] as $m): ?>
                            <tr>
                                <td class="px-3 py-1.5 font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($m['name']); ?></td>
                                <td class="px-3 py-1.5 text-right"><?php echo (int) $m['calendar']; ?></td>
                                <td class="px-3 py-1.5 text-right font-semibold"><?php echo (int) $m['work']; ?></td>
                                <td class="px-3 py-1.5 text-right"><?php echo (int) $m['off']; ?></td>
                                <td class="px-3 py-1.5 text-right"><?php echo HTML::chars($num($m['h40'])); ?></td>
                                <td class="px-3 py-1.5 text-right"><?php echo HTML::chars($num($m['h36'])); ?></td>
                                <td class="px-3 py-1.5 text-right"><?php echo HTML::chars($num($m['h24'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50 font-bold dark:bg-gray-900">
                        <tr>
                            <td class="px-3 py-2">Год</td><td class="px-3 py-2 text-right"><?php echo (int) $tot['calendar']; ?></td>
                            <td class="px-3 py-2 text-right"><?php echo (int) $tot['work']; ?></td><td class="px-3 py-2 text-right"><?php echo (int) $tot['off']; ?></td>
                            <td class="px-3 py-2 text-right"><?php echo HTML::chars($num($tot['h40'])); ?></td>
                            <td class="px-3 py-2 text-right"><?php echo HTML::chars($num($tot['h36'])); ?></td>
                            <td class="px-3 py-2 text-right"><?php echo HTML::chars($num($tot['h24'])); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <p class="mt-4 text-sm text-gray-500">Таблица календаря пуста — добавьте годы в config/nj508_calendar.php.</p>
        <?php endif; ?>
    </section>

</div>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">

    <?php if ($table): ?>
    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-star mr-1.5 text-rose-400"></i>Праздники и переносы <?php echo (int) $year; ?></p>
        <ul class="mt-3 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
            <?php foreach ($table['holidays'] as $h): ?>
                <li class="flex justify-between gap-2"><span><b><?php echo HTML::chars($h['human']); ?></b>, <?php echo HTML::chars($h['weekday']); ?></span><span class="text-right text-gray-500 dark:text-gray-400"><?php echo HTML::chars($h['name']); ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($table['shorts']): ?>
            <p class="n2j-hint mt-3">Сокращённые дни: <?php echo HTML::chars(implode(', ', $table['shorts'])); ?>.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3" open>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Откуда данные календаря?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Из постановлений Правительства РФ о переносе выходных дней (реквизиты — над календарём).
                    Если на нужный год календаря нет, сервис не подставляет «прошлогодний» молча: он предупреждает
                    и предлагает приблизительный расчёт по субботам и воскресеньям.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как считается норма часов?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Число рабочих дней умножается на 8 часов (при 36-часовой неделе — на 7,2; при 24-часовой — на 4,8),
                    и из результата вычитается по одному часу за каждый сокращённый день (ст. 95 ТК РФ).
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как считается возраст в високосный день?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    По статье 192 ГК РФ: если в нужном месяце нет такого числа, срок истекает в последний день месяца.
                    Родившийся 29 февраля становится на год старше 28 февраля — в невисокосный год.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Данные уходят на сервер?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Даты отправляются на наш сервер только для расчёта по календарю и нигде не сохраняются.
                </p>
            </details>
        </div>
        <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          "mainEntity": [
            {"@type": "Question", "name": "Откуда данные производственного календаря?",
             "acceptedAnswer": {"@type": "Answer", "text": "Из постановлений Правительства РФ о переносе выходных дней. Если на год календаря нет, сервис предупреждает об этом и предлагает приблизительный расчёт по субботам и воскресеньям."}},
            {"@type": "Question", "name": "Как считается норма рабочего времени?",
             "acceptedAnswer": {"@type": "Answer", "text": "Число рабочих дней умножается на 8 часов (при 36-часовой неделе — на 7,2; при 24-часовой — на 4,8), из результата вычитается по часу за каждый сокращённый день."}},
            {"@type": "Question", "name": "Как считается возраст у родившихся 29 февраля?",
             "acceptedAnswer": {"@type": "Answer", "text": "По статье 192 ГК РФ срок истекает в последний день месяца, если нужного числа в нём нет: в невисокосный год это 28 февраля."}}
          ]
        }
        </script>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('508/script', ['tab' => $tab, 'today' => $today, 'year' => $year]);
