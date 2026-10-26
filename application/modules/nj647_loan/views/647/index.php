<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Кредит и ипотека (nj647_loan) — страница /loan.
 *
 * Расчёт делает сервер, форма — обычный GET: страница работает без
 * JavaScript, а адрес со всеми полями можно переслать.
 *
 * @var array $form   значения полей (sum, rate, term, term_unit, type, extra, extra_mode, start)
 * @var array $result Service_Nj647_Loan::calculate()
 * @var array $types  типы платежа
 * @var array $modes  режимы досрочного погашения
 * @var bool  $full   раскрыть весь график
 */

$f    = static function ($v, $d = 2) { return Service_Nj647_Loan::fmt($v, $d); };
$ok   = ! empty($result['ok']);

$label = 'n2j-label';
$input = 'n2j-input mt-1.5 w-full font-semibold';
$cell  = 'px-3 py-2 text-right tabular-nums';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <!-- ======================== Форма ======================== -->
    <form method="get" action="/loan" id="loan-form" class="n2j-card">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2 lg:col-span-1">
                <label for="loan-sum" class="<?php echo $label; ?>">Сумма кредита, ₽</label>
                <input id="loan-sum" name="sum" inputmode="decimal" value="<?php echo HTML::chars($form['sum']); ?>" class="<?php echo $input; ?>">
            </div>
            <div>
                <label for="loan-rate" class="<?php echo $label; ?>">Ставка, % годовых</label>
                <input id="loan-rate" name="rate" inputmode="decimal" value="<?php echo HTML::chars($form['rate']); ?>" class="<?php echo $input; ?>">
            </div>
            <div>
                <label for="loan-term" class="<?php echo $label; ?>">Срок</label>
                <div class="mt-1.5 flex gap-2">
                    <input id="loan-term" name="term" inputmode="numeric" value="<?php echo HTML::chars($form['term']); ?>"
                           class="n2j-input min-w-0 flex-1 font-semibold">
                    <select name="term_unit" aria-label="Единица срока"
                            class="n2j-input n2j-select font-semibold w-auto">
                        <option value="years" <?php echo $form['term_unit'] === 'years' ? 'selected' : ''; ?>>лет</option>
                        <option value="months" <?php echo $form['term_unit'] === 'months' ? 'selected' : ''; ?>>мес.</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="loan-start" class="<?php echo $label; ?>">Дата выдачи</label>
                <input id="loan-start" name="start" type="date" value="<?php echo HTML::chars($form['start']); ?>" class="<?php echo $input; ?>">
            </div>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <span class="<?php echo $label; ?>">Тип платежа</span>
                <div class="mt-1.5 flex flex-wrap gap-2">
                    <?php foreach ($types as $code => $t): $on = ($form['type'] === $code); ?>
                        <label class="n2j-chip min-h-[44px] cursor-pointer<?php echo $on ? ' is-on' : ''; ?>">
                            <input type="radio" name="type" value="<?php echo HTML::chars($code); ?>" <?php echo $on ? 'checked' : ''; ?> class="sr-only" data-loan-payment-type>
                            <?php echo HTML::chars($t['title']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <label for="loan-extra" class="<?php echo $label; ?>">Досрочно каждый месяц, ₽</label>
                <input id="loan-extra" name="extra" inputmode="decimal" placeholder="0" value="<?php echo HTML::chars($form['extra']); ?>" class="<?php echo $input; ?>">
            </div>
            <div>
                <label for="loan-extra-mode" class="<?php echo $label; ?>">Досрочные платежи</label>
                <select id="loan-extra-mode" name="extra_mode" class="n2j-input n2j-select mt-1.5 w-full font-semibold">
                    <?php foreach ($modes as $code => $title): ?>
                        <option value="<?php echo HTML::chars($code); ?>" <?php echo $form['extra_mode'] === $code ? 'selected' : ''; ?>><?php echo HTML::chars($title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="submit" class="n2j-btn-process">
                <i class="fas fa-calculator"></i>Рассчитать
            </button>
            <button type="button" id="loan-copy" class="n2j-btn-secondary">
                <i class="fas fa-link"></i>Ссылка на расчёт
            </button>
            <span class="text-xs text-gray-400 dark:text-gray-500">Числа никуда не отправляются и не сохраняются — только в адресе страницы.</span>
        </div>
    </form>

    <?php if ( ! $ok): ?>
        <div class="n2j-flash n2j-flash-warn mt-4 block">
            <i class="fas fa-triangle-exclamation mr-1.5"></i><?php echo HTML::chars($result['error']); ?>
        </div>
    <?php else: ?>

    <!-- ======================== Итог ======================== -->
    <div class="n2j-card mt-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            <?php echo $result['type'] === 'annuity' ? 'Ежемесячный платёж' : 'Первый платёж (дальше убывает)'; ?>
        </p>
        <p class="mt-1 text-3xl font-bold text-gray-900 tabular-nums dark:text-white">
            <?php echo $f($result['payment']); ?> <span class="text-xl font-semibold text-gray-400">₽</span>
        </p>
        <?php if ($result['type'] === 'diff'): ?>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Последний платёж — <?php echo $f($result['payment_last']); ?> ₽.</p>
        <?php endif; ?>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-900/60">
                <p class="n2j-hint">Переплата за весь срок</p>
                <p class="mt-0.5 text-lg font-bold text-gray-900 tabular-nums dark:text-white"><?php echo $f($result['interest']); ?> ₽</p>
                <p class="n2j-hint">это <?php echo $f($result['interest_pct'], 1); ?> % от суммы кредита</p>
            </div>
            <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-900/60">
                <p class="n2j-hint">Всего выплат банку</p>
                <p class="mt-0.5 text-lg font-bold text-gray-900 tabular-nums dark:text-white"><?php echo $f($result['total']); ?> ₽</p>
                <p class="n2j-hint">долг <?php echo $f($result['sum'], 0); ?> ₽ + проценты</p>
            </div>
            <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-900/60">
                <p class="n2j-hint">Срок погашения</p>
                <p class="mt-0.5 text-lg font-bold text-gray-900 dark:text-white"><?php echo HTML::chars(Service_Nj647_Loan::term_words($result['months_real'])); ?></p>
                <p class="n2j-hint"><?php echo (int) $result['months_real'].' '.Service_Nj647_Loan::payments_word($result['months_real']); ?></p>
            </div>
            <div class="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-900/60">
                <p class="n2j-hint">Последний платёж</p>
                <p class="mt-0.5 text-lg font-bold text-gray-900 dark:text-white"><?php echo HTML::chars(date('d.m.Y', strtotime($result['end']))); ?></p>
                <p class="n2j-hint">ставка <?php echo $f($result['rate'], 2); ?> % годовых</p>
            </div>
        </div>

        <?php if ($result['type'] === 'annuity' AND $result['turn_month'] !== NULL AND $result['turn_month'] > 1): ?>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                <i class="fas fa-circle-info mr-1.5 text-gray-400"></i>
                Долг начнёт гаситься быстрее процентов только с <b><?php echo (int) $result['turn_month']; ?>-го платежа</b>
                (<?php echo HTML::chars(date('m.Y', strtotime($result['rows'][$result['turn_month'] - 1]['date']))); ?>) —
                до этого больше половины денег уходит банку за пользование.
            </p>
        <?php endif; ?>
    </div>

    <!-- ================== Эффект досрочного ================== -->
    <?php if ($result['saved'] !== NULL): ?>
        <div class="mt-4 rounded-xl border border-emerald-300 bg-emerald-50 p-4 sm:p-6 dark:border-emerald-800 dark:bg-emerald-900/20">
            <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-200">
                <i class="fas fa-piggy-bank mr-1.5"></i>Досрочно по <?php echo $f($result['extra'], 0); ?> ₽ в месяц
                — <?php echo HTML::chars(mb_strtolower($modes[$result['extra_mode']])); ?>
            </p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <p class="text-xs text-emerald-800/80 dark:text-emerald-300/80">Экономия на процентах</p>
                    <p class="text-lg font-bold text-emerald-900 tabular-nums dark:text-emerald-100"><?php echo $f($result['saved']['interest']); ?> ₽</p>
                </div>
                <div>
                    <p class="text-xs text-emerald-800/80 dark:text-emerald-300/80">Срок короче на</p>
                    <p class="text-lg font-bold text-emerald-900 dark:text-emerald-100">
                        <?php echo $result['saved']['months'] > 0 ? HTML::chars(Service_Nj647_Loan::term_words($result['saved']['months'])) : 'тот же срок, но платёж ниже'; ?>
                    </p>
                </div>
            </div>
            <p class="mt-3 text-xs text-emerald-800/90 dark:text-emerald-300/90">
                Без досрочных платежей кредит закрылся бы <?php echo HTML::chars(date('d.m.Y', strtotime($result['saved']['end']))); ?>,
                а банку ушло бы <?php echo $f($result['saved']['total']); ?> ₽.
            </p>
        </div>
    <?php endif; ?>

    <!-- ======================= График ======================= -->
    <div class="n2j-card mt-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="n2j-h3">График платежей</h2>
            <span class="text-xs text-gray-400 dark:text-gray-500">по годам — разверните нужный</span>
        </div>

        <div class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
            <?php foreach ($result['years'] as $idx => $y): ?>
                <details class="group py-2" <?php echo ($full OR $idx === 0) ? 'open' : ''; ?>>
                    <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 py-1.5 marker:hidden">
                        <span class="text-sm font-bold text-gray-800 dark:text-gray-100">
                            <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i><?php echo (int) $y['year']; ?>
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo (int) $y['months']; ?> мес.</span>
                        <span class="ml-auto text-xs text-gray-500 tabular-nums dark:text-gray-400">
                            выплачено <b class="text-gray-800 dark:text-gray-100"><?php echo $f($y['payment'], 0); ?></b> ₽,
                            из них процентов <b class="text-gray-800 dark:text-gray-100"><?php echo $f($y['interest'], 0); ?></b> ₽,
                            остаток долга <b class="text-gray-800 dark:text-gray-100"><?php echo $f($y['balance'], 0); ?></b> ₽
                        </span>
                    </summary>

                    <div class="mt-2 overflow-x-auto">
                        <table class="n2j-table min-w-[34rem]">
                            <thead>
                                <tr class="text-gray-400 dark:text-gray-500">
                                    <th class="px-3 py-2 text-left font-semibold">№</th>
                                    <th class="px-3 py-2 text-left font-semibold">Дата</th>
                                    <th class="px-3 py-2 text-right font-semibold">Платёж</th>
                                    <th class="px-3 py-2 text-right font-semibold">Проценты</th>
                                    <th class="px-3 py-2 text-right font-semibold">Долг</th>
                                    <?php if ($result['extra'] > 0): ?><th class="px-3 py-2 text-right font-semibold">Досрочно</th><?php endif; ?>
                                    <th class="px-3 py-2 text-right font-semibold">Остаток</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <?php foreach ($y['rows'] as $row): ?>
                                    <tr class="text-gray-700 dark:text-gray-200">
                                        <td class="px-3 py-2 text-gray-400"><?php echo (int) $row['n']; ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap"><?php echo HTML::chars(date('d.m.Y', strtotime($row['date']))); ?></td>
                                        <td class="<?php echo $cell; ?> font-semibold"><?php echo $f($row['payment']); ?></td>
                                        <td class="<?php echo $cell; ?> text-gray-500 dark:text-gray-400"><?php echo $f($row['interest']); ?></td>
                                        <td class="<?php echo $cell; ?>"><?php echo $f($row['principal']); ?></td>
                                        <?php if ($result['extra'] > 0): ?><td class="<?php echo $cell; ?> text-emerald-600 dark:text-emerald-400"><?php echo $f($row['extra']); ?></td><?php endif; ?>
                                        <td class="<?php echo $cell; ?> text-gray-500 dark:text-gray-400"><?php echo $f($row['balance']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>

    <?php endif; ?>
</div>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">

    <?php if ($ok): ?>
    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-scale-balanced mr-1.5 text-gray-400"></i>Обратный расчёт</p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Платёж <b><?php echo $f($result['payment'], 0); ?> ₽</b> при ставке <?php echo $f($result['rate'], 2); ?> %
            и сроке <?php echo HTML::chars(Service_Nj647_Loan::term_words($result['months'])); ?> — это кредит
            примерно на <b class="text-gray-900 dark:text-white"><?php echo $f($result['capacity'], 0); ?> ₽</b>.
        </p>
        <p class="n2j-hint mt-2">
            Банк обычно соглашается на платёж до 40–50 % дохода семьи: прикиньте по этой цифре, какую сумму одобрят.
        </p>
    </div>
    <?php endif; ?>

    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Что важно знать</p>
        <ul class="mt-3 space-y-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
            <li><b>Аннуитет</b> — равный платёж; в первые годы почти весь он уходит на проценты.</li>
            <li><b>Дифференцированный</b> — долг делится поровну, платёж убывает; переплата меньше, но вход тяжелее.</li>
            <li>Досрочные платежи выгоднее направлять <b>на сокращение срока</b>: процентов набегает меньше.</li>
            <li>Расчёт помесячный, банк считает проценты по дням — расхождение в пределах рубля-двух на платёж.</li>
            <li>Страховка, комиссии и материнский капитал не учитываются.</li>
        </ul>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Сократить срок или уменьшить платёж?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Сокращение срока экономит больше: проценты начисляются на остаток, и чем меньше месяцев,
                    тем меньше начислений. Уменьшение платежа снижает нагрузку на бюджет сейчас — поставьте
                    в поле «Досрочные платежи» оба варианта и сравните переплату.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Почему мой банк показывает другую цифру?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Здесь месяц — это 1/12 года, а банк начисляет проценты за фактическое число дней.
                    Поэтому в феврале платёж у банка чуть меньше, в марте чуть больше. На итоговую переплату
                    это влияет слабо, но сверять выписку по этому графику не стоит.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Можно получить расчёт программно?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: <span class="font-mono text-xs">GET /nj647/api/calc?sum=5000000&amp;rate=18&amp;term=20</span>
                    вернёт JSON с платежом и переплатой, а <span class="font-mono text-xs">&amp;schedule=1</span> — весь график.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('647/script'); ?>
