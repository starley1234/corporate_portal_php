<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Калькулятор процентов (nj518_percent) — страница.
 *
 * Сервер рендерит начальное состояние (?m=&a=&b=), дальше считает JS
 * (views/518/script.php — зеркало Service_Nj518_Percent).
 *
 * @var array      $modes
 * @var string     $mode
 * @var string     $a
 * @var string     $b
 * @var array|null $result
 */

$cur = $modes[$mode];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <!-- ============ Режим ============ -->
    <div class="n2j-card">
        <span class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Что посчитать</span>
        <div id="pc-modes" role="radiogroup" aria-label="Режим расчёта" class="mt-2 flex flex-wrap gap-2">
            <?php foreach ($modes as $code => $m): $on = ($code === $mode); ?>
                <button type="button" role="radio" data-mode="<?php echo HTML::chars($code); ?>"
                        aria-checked="<?php echo $on ? 'true' : 'false'; ?>"
                        class="n2j-chip pc-mode<?php echo $on ? ' is-on' : ''; ?>">
                    <?php echo HTML::chars($m['title']); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <p id="pc-phrase" class="mt-5 text-base font-semibold text-gray-900 dark:text-white"></p>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label for="pc-a" id="pc-a-label" class="n2j-label"><?php echo HTML::chars($cur['a']); ?></label>
                <input id="pc-a" type="text" inputmode="decimal" autocomplete="off"
                       value="<?php echo HTML::chars($a); ?>" placeholder="<?php echo HTML::chars($cur['ex'][0]); ?>"
                       class="n2j-input mt-1.5 w-full text-2xl font-bold tracking-wide">
            </div>
            <div>
                <label for="pc-b" id="pc-b-label" class="n2j-label"><?php echo HTML::chars($cur['b']); ?></label>
                <input id="pc-b" type="text" inputmode="decimal" autocomplete="off"
                       value="<?php echo HTML::chars($b); ?>" placeholder="<?php echo HTML::chars($cur['ex'][1]); ?>"
                       class="n2j-input mt-1.5 w-full text-2xl font-bold tracking-wide">
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" id="pc-example"
                    class="n2j-btn-secondary n2j-btn-sm">
                <i class="fas fa-wand-magic-sparkles mr-1"></i>Подставить пример
            </button>
            <button type="button" id="pc-swap"
                    class="n2j-btn-secondary n2j-btn-sm">
                <i class="fas fa-right-left mr-1"></i>Поменять числа местами
            </button>
        </div>
    </div>

    <!-- ============ Результат ============ -->
    <div class="mt-4 rounded-xl border-2 border-blue-500 bg-blue-50/60 p-4 sm:p-6 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20" id="pc-result" aria-live="polite">
        <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Результат</p>
        <p class="mt-1 text-4xl font-extrabold text-blue-700 dark:text-blue-300" id="pc-main"><?php echo $result !== NULL ? HTML::chars($result['main'].($result['unit'] !== '' ? ' '.$result['unit'] : '')) : '—'; ?></p>
        <p class="mt-1 text-sm font-semibold text-gray-700 dark:text-gray-200" id="pc-headline"><?php echo $result !== NULL ? HTML::chars($result['headline']) : 'Введите оба числа — результат появится сразу'; ?></p>
        <p class="n2j-hint mt-3">Формула: <code class="rounded bg-white/70 px-1.5 py-0.5 font-mono dark:bg-gray-900" id="pc-formula"><?php echo $result !== NULL ? HTML::chars($result['formula']) : '—'; ?></code></p>
        <dl id="pc-rows" class="mt-3 divide-y divide-blue-100 text-sm dark:divide-blue-900/40">
            <?php if ($result !== NULL): foreach ($result['rows'] as $row): ?>
                <div class="flex items-center justify-between py-1.5">
                    <dt class="text-gray-600 dark:text-gray-300"><?php echo HTML::chars($row[0]); ?></dt>
                    <dd class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($row[1]); ?></dd>
                </div>
            <?php endforeach; endif; ?>
        </dl>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" id="pc-copy"
                    class="n2j-btn-process"
                    <?php if ($result === NULL) echo 'disabled'; ?>>
                <i class="fas fa-copy"></i> Копировать результат
            </button>
            <button type="button" id="pc-share"
                    class="n2j-btn-secondary">
                <i class="fas fa-link"></i> Скопировать ссылку
            </button>
        </div>
    </div>

</div>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">

    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-scale-balanced mr-1.5 text-amber-400"></i>Наценка — не маржа</p>
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            <b>Наценка</b> считается от себестоимости, <b>маржа</b> — от цены продажи. Товар за 700 ₽, проданный за 1000 ₽,
            даёт наценку 42,86 %, а маржу — 30 %. Поэтому «накрутить 30 %» и «получить маржу 30 %» — разные цены.
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
            <a href="/percent?m=price&a=700&b=1000" class="n2j-chip">Посмотреть пример</a>
            <a href="/vat" class="n2j-chip">Калькулятор НДС</a>
        </div>
    </div>

    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3" open>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как посчитать процент от числа?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Умножьте число на процент и разделите на 100. 15 % от 2000: 2000 × 15 ÷ 100 = 300.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как узнать, на сколько процентов число выросло?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Из нового значения вычтите старое, разделите на старое и умножьте на 100. Было 80, стало 100:
                    (100 − 80) ÷ 80 × 100 = 25 %. Обратный путь с 100 до 80 — уже минус 20 %, а не 25.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как найти число, если известна его часть?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Разделите часть на её процент и умножьте на 100. Если 45 — это 15 % числа, то само число
                    45 ÷ 15 × 100 = 300.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Данные уходят на сервер?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет: расчёт идёт прямо в браузере, числа попадают только в адресную строку — чтобы можно было
                    отправить ссылку на результат.
                </p>
            </details>
        </div>
        <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          "mainEntity": [
            {"@type": "Question", "name": "Как посчитать процент от числа?",
             "acceptedAnswer": {"@type": "Answer", "text": "Умножьте число на процент и разделите на 100. 15 % от 2000: 2000 × 15 ÷ 100 = 300."}},
            {"@type": "Question", "name": "Как узнать, на сколько процентов число выросло?",
             "acceptedAnswer": {"@type": "Answer", "text": "Из нового значения вычтите старое, разделите на старое и умножьте на 100. Было 80, стало 100: (100 − 80) ÷ 80 × 100 = 25 %."}},
            {"@type": "Question", "name": "Как найти число, если известна его часть?",
             "acceptedAnswer": {"@type": "Answer", "text": "Разделите часть на её процент и умножьте на 100: 45 ÷ 15 × 100 = 300."}}
          ]
        }
        </script>
    </div>
</aside>
</div>
</div>

<?php // Инлайн-скрипт калькулятора — в конце вьюхи (0 лишних HTTP-запросов)
echo View::factory('518/script', ['modes' => $modes, 'mode' => $mode]);
