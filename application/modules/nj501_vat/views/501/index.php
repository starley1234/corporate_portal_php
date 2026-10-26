<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Калькулятор НДС (nj501_vat) — страница.
 *
 * Сервер рендерит начальное состояние (из ?a=&r=&m=), дальше считает JS
 * (views/501/script.php — зеркало Service_Nj501_Vat). Элементы UI: живой ввод
 * суммы, сегмент ставок, переключатель режима, чипы, карточки результата,
 * таблица-расчётка, копирование, share-ссылка, история (localStorage), FAQ.
 */

$mode_is_add = ($mode === Service_Nj501_Vat::MODE_ADD);

/** Экранированное значение карточки или «—» */
$card = function ($value) use ($result) {
    return HTML::chars($result !== NULL ? $result[$value] : '—');
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <!-- ============ Ввод ============ -->
    <div class="n2j-card">
        <label for="vat-amount" class="n2j-label">
            Сумма, ₽
        </label>
        <input id="vat-amount" type="text" inputmode="decimal" autocomplete="off"
               value="<?php echo HTML::chars($amount); ?>"
               placeholder="10 000,00"
               class="n2j-input mt-2 w-full text-2xl font-bold tracking-wide"
               aria-describedby="vat-hint">
        <p id="vat-hint" class="n2j-hint mt-1.5">
            Можно вставлять с пробелами и копейками — «1 234 567,89»
        </p>

        <!-- Быстрые суммы -->
        <div class="mt-3 flex flex-wrap gap-2">
            <?php foreach ([1000, 10000, 100000, 1000000] as $chip): ?>
                <button type="button" data-chip="<?php echo $chip; ?>"
                        class="n2j-btn-secondary n2j-btn-sm">
                    <?php echo HTML::chars(Service_Nj501_Vat::format_kop($chip * 100, FALSE)); ?> ₽
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Ставка: сегмент-контроль -->
        <div class="mt-5">
            <span class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Ставка НДС</span>
            <div id="vat-rates" role="radiogroup" aria-label="Ставка НДС"
                 class="n2j-seg mt-2 inline-flex flex-wrap gap-1">
                <?php foreach ($rates as $r): ?>
                    <button type="button" role="radio"
                            data-rate="<?php echo (int) $r['rate']; ?>"
                            aria-checked="<?php echo ((int) $r['rate']) === (int) $rate ? 'true' : 'false'; ?>"
                            class="vat-rate-btn n2j-seg-item<?php echo ((int) $r['rate']) === (int) $rate ? ' is-active' : ''; ?>"
                            title="<?php echo HTML::chars($r['label']); ?>">
                        <?php echo (int) $r['rate']; ?>%
                        <?php if ($r['note']): ?>
                            <span class="n2j-badge n2j-badge-info ml-1 uppercase"><?php echo HTML::chars($r['note']); ?></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Режим: НДС в сумме / сверху -->
        <div class="mt-5">
            <span class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Что считаем</span>
            <div id="vat-mode" role="radiogroup" aria-label="Режим расчёта" class="mt-2 grid grid-cols-2 gap-2">
                <button type="button" role="radio" data-mode="extract" aria-checked="<?php echo ! $mode_is_add ? 'true' : 'false'; ?>"
                        class="vat-mode-btn n2j-chip justify-center py-2.5<?php echo ! $mode_is_add ? ' is-on' : ''; ?>">
                    <i class="fas fa-arrow-down-short-wide mr-1.5"></i>Выделить из суммы
                </button>
                <button type="button" role="radio" data-mode="add" aria-checked="<?php echo $mode_is_add ? 'true' : 'false'; ?>"
                        class="vat-mode-btn n2j-chip justify-center py-2.5<?php echo $mode_is_add ? ' is-on' : ''; ?>">
                    <i class="fas fa-arrow-up-wide-short mr-1.5"></i>Начислить сверху
                </button>
            </div>
        </div>
    </div>

    <!-- ============ Результат ============ -->

    <div class="mt-4 grid gap-3 sm:grid-cols-3" id="vat-result">
        <div class="rounded-xl border-2 border-blue-500 bg-blue-50/60 p-4 text-center shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20">
            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">НДС</p>
            <p class="vat-num mt-1 text-2xl font-extrabold text-blue-700 dark:text-blue-300" id="vat-out"><?php echo $card('vat'); ?></p>
        </div>
        <div class="n2j-card text-center">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Сумма без НДС</p>
            <p class="vat-num mt-1 text-2xl font-bold text-gray-900 dark:text-white" id="net-out"><?php echo $card('net'); ?></p>
        </div>
        <div class="n2j-card text-center">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Итого</p>
            <p class="vat-num mt-1 text-2xl font-bold text-gray-900 dark:text-white" id="total-out"><?php echo $card('total'); ?></p>
        </div>
    </div>

    <!-- ============ Расчётка и действия ============ -->
    <div class="n2j-card mt-4">
        <table class="n2j-table">
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <tr>
                    <td class="py-2 text-gray-500 dark:text-gray-400">Введённая сумма</td>
                    <td class="py-2 text-right font-semibold" id="row-amount"><?php echo $card('amount'); ?></td>
                </tr>
                <tr>
                    <td class="py-2 text-gray-500 dark:text-gray-400">
                        Формула <span class="text-xs">(<?php echo $mode_is_add ? 'начисление' : 'выделение'; ?>)</span>
                    </td>
                    <td class="py-2 text-right font-mono text-xs" id="row-formula"><?php echo $card('formula'); ?></td>
                </tr>
                <tr>
                    <td class="py-2 text-gray-500 dark:text-gray-400">Ставка</td>
                    <td class="py-2 text-right font-semibold" id="row-rate"><?php echo (int) $rate; ?>%</td>
                </tr>
            </tbody>
        </table>

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" id="vat-copy"
                    class="n2j-btn-process"
                    <?php if ($result === NULL) echo 'disabled'; ?>>
                <i class="fas fa-copy"></i> Копировать результат
            </button>
            <button type="button" id="vat-share"
                    class="n2j-btn-secondary"
                    <?php if ($result === NULL) echo 'disabled'; ?>>
                <i class="fas fa-link"></i> Скопировать ссылку
            </button>
            <button type="button" id="vat-swap"
                    class="n2j-btn-secondary"
                    title="Поменять режим расчёта">
                <i class="fas fa-right-left"></i> Поменять
            </button>
        </div>
    </div>


</div>

<aside class="hidden space-y-4 md:block">
    <!-- ============ Последние расчёты (первый блок сайдбара) ============ -->
    <div id="vat-history-wrap" class="n2j-card">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-clock-rotate-left mr-1.5 text-gray-400"></i>Последние расчёты</p>
            <button type="button" id="vat-history-clear" class="text-xs text-gray-400 transition hover:text-rose-500">очистить</button>
        </div>
        <div id="vat-history" class="mt-2 flex flex-wrap gap-2"></div>
    </div>

    <!-- ============ Формулы ============ -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Формулы</h2>
        <div class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p><b>Выделить НДС</b> из суммы с НДС: <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-gray-900">НДС = Сумма × <?php echo (int) $rate; ?>/<?php echo 100 + (int) $rate; ?></code></p>
            <p><b>Начислить НДС</b> на сумму без НДС: <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-gray-900">НДС = Сумма × <?php echo (int) $rate; ?>/100</code></p>
            <p class="n2j-hint">Расчётные ставки выделения: 22/122, 20/120, 10/110, 7/107, 5/105. Округление — до копейки.</p>
        </div>
    </div>

    <!-- ============ FAQ (SEO) ============ -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3" open>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как выделить НДС 22% из суммы?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Разделите сумму на 122 и умножьте на 22. Например, из 1 220 000 ₽:
                    1 220 000 × 22/122 = 220 000 ₽. Ставка 22% — общая с 1 января 2026 года.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Какие ставки НДС действуют для УСН?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Плательщики на упрощёнке с доходом свыше установленного лимита применяют
                    пониженные ставки 5% (расчётная 5/105) или 7% (расчётная 7/107). Границы доходов
                    и условия периодически меняются — сверяйтесь с действующей редакцией НК РФ.
                    При пониженных ставках входной НДС к вычету не принимается.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как посчитать НДС с аванса?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    С полученной предоплаты НДС исчисляется по расчётной ставке: сумма аванса
                    умножается на r/(100+r). Пример: аванс 105 000 ₽ при ставке 5% →
                    105 000 × 5/105 = 5 000 ₽.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Калькулятор считает без регистрации?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да, расчёт выполняется мгновенно в вашем браузере. История последних расчётов
                    хранится только на вашем устройстве.
                </p>
            </details>
        </div>
        <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          "mainEntity": [
            {"@type": "Question", "name": "Как выделить НДС 22% из суммы?",
             "acceptedAnswer": {"@type": "Answer", "text": "Разделите сумму на 122 и умножьте на 22: 1 220 000 × 22/122 = 220 000 ₽. Ставка 22% — общая с 1 января 2026 года."}},
            {"@type": "Question", "name": "Какие ставки НДС действуют для УСН?",
             "acceptedAnswer": {"@type": "Answer", "text": "Пониженные ставки 5% и 7% для плательщиков УСН; расчётные ставки выделения — 5/105 и 7/107. Границы доходов уточняйте по действующей редакции НК РФ."}},
            {"@type": "Question", "name": "Как посчитать НДС с аванса?",
             "acceptedAnswer": {"@type": "Answer", "text": "НДС с предоплаты исчисляется по расчётной ставке r/(100+r): аванс 105 000 ₽ при ставке 5% даёт 5 000 ₽."}}
          ]
        }
        </script>
    </div>
</aside>
</div>
</div>

<?php // Инлайн-скрипт калькулятора — в конце вьюхи (0 лишних HTTP-запросов)
echo View::factory('501/script');
