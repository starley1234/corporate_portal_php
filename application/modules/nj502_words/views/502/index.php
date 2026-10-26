<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Сумма прописью» (nj502_words) — страница.
 *
 * SSR начального состояния из ?a=&u=&k=, дальше считает JS (views/502/script.php —
 * зеркало Service_Nj502_Words). Элементы UI: живой ввод, тумблер «с большой буквы»,
 * копейки цифрами/словами, карточка результата, копирование, share-URL, FAQ.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <!-- ============ Ввод ============ -->
    <div class="n2j-card">
        <label for="w2-amount" class="n2j-label">
            Сумма, ₽
        </label>
        <input id="w2-amount" type="text" inputmode="decimal" autocomplete="off"
               value="<?php echo HTML::chars($amount); ?>"
               placeholder="22 122,00"
               class="n2j-input mt-2 w-full text-2xl font-bold tracking-wide"
               aria-describedby="w2-hint">
        <p id="w2-hint" class="n2j-hint mt-1.5">
            До 999 999 999 999 руб., копейки — через запятую или точку
        </p>

        <!-- Опции -->
        <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2">
            <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <input id="w2-ucfirst" type="checkbox" class="min-h-[44px] h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900" <?php if ($ucfirst) echo 'checked'; ?>>
                С большой буквы
            </label>
            <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <input id="w2-kopwords" type="checkbox" class="min-h-[44px] h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900" <?php if ($kopecks === Service_Nj502_Words::KOPECKS_WORDS) echo 'checked'; ?>>
                Копейки словами
            </label>
        </div>

        <!-- Примеры -->
        <div class="mt-3 flex flex-wrap gap-2">
            <?php foreach (['22 122', '1 234 567,89', '105 000', '1000000000'] as $chip): ?>
                <button type="button" data-chip="<?php echo HTML::chars($chip); ?>"
                        class="n2j-btn-secondary n2j-btn-sm">
                    <?php echo HTML::chars($chip); ?> ₽
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============ Результат ============ -->
    <div class="rounded-xl border-2 border-blue-500 bg-blue-50/60 p-5 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20 mt-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Сумма прописью</p>
        <p id="w2-out" class="mt-2 min-h-[3.5rem] text-xl font-bold leading-snug text-gray-900 dark:text-white" lang="ru">
            <?php echo $result !== NULL ? HTML::chars($result['text']) : '—'; ?>
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="button" id="w2-copy"
                    class="n2j-btn-process"
                    <?php if ($result === NULL) echo 'disabled'; ?>>
                <i class="fas fa-copy"></i> Копировать
            </button>
            <button type="button" id="w2-share"
                    class="n2j-btn-secondary"
                    <?php if ($result === NULL) echo 'disabled'; ?>>
                <i class="fas fa-link"></i> Скопировать ссылку
            </button>
        </div>
    </div>


</div>

<aside class="hidden space-y-4 md:block">
    <!-- ============ Разбор ============ -->
    <div class="n2j-card">
        <table class="n2j-table">
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <tr>
                    <td class="py-2 text-gray-500 dark:text-gray-400">Введённая сумма</td>
                    <td class="py-2 text-right font-semibold" id="row-amount"><?php echo $result !== NULL ? HTML::chars($result['amount']) : '—'; ?> ₽</td>
                </tr>
                <tr>
                    <td class="py-2 text-gray-500 dark:text-gray-400">Рублей / копеек</td>
                    <td class="py-2 text-right font-semibold" id="row-split"><?php echo $result !== NULL ? number_format($result['rub'], 0, ',', ' ').' / '.sprintf('%02d', $result['kop']) : '—'; ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ============ Правила (SEO) ============ -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Как писать сумму прописью</h2>
        <div class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p><b>В платёжных документах</b> рубли пишутся прописью, копейки — цифрами:
            «двадцать две тысячи сто двадцать два рубля 00 копеек».</p>
            <p><b>В договорах</b> сумму начинают с заглавной буквы и часто дублируют цифрами:
            «Двадцать две тысячи (22 122) рублей 00 копеек». Заглавную букву включите тумблером выше.</p>
            <p class="n2j-hint">Правило склонения: 1 рубль, 2–4 рубля, 5–20 рублей;
            одна тысяча, две тысячи, пять тысяч; один миллион, два миллиона, пять миллионов.</p>
        </div>
    </div>

    <!-- ============ FAQ (SEO) ============ -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3" open>
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как написать 22122 прописью?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    «Двадцать две тысячи сто двадцать два рубля 00 копеек». Введите сумму в поле —
                    сервис покажет прописную форму в банковском формате.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как правильно: «ноль копеек» или «00 копеек»?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    В платёжках принято «00 копеек». В текстах договоров допустим вариант словами —
                    включите тумблер «Копейки словами».
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Почему «одна тысяча», а не «один тысяча»?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    «Тысяча» — женский род, поэтому числительные в этой триаде ставятся в женской форме:
                    одна тысяча, две тысячи. Для миллионов и миллиардов — мужская форма: один миллион, два миллиона.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Сервис считает без интернета и регистрации?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Расчёт выполняется мгновенно в вашем браузере, суммы никуда не отправляются и не сохраняются.
                    Для интеграций есть JSON API того же сервиса.
                </p>
            </details>
        </div>
        <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          "mainEntity": [
            {"@type": "Question", "name": "Как написать 22122 прописью?",
             "acceptedAnswer": {"@type": "Answer", "text": "Двадцать две тысячи сто двадцать два рубля 00 копеек — в банковском формате платежных документов."}},
            {"@type": "Question", "name": "Как писать копейки в сумме прописью?",
             "acceptedAnswer": {"@type": "Answer", "text": "В платежках — цифрами: «...рубля 00 копеек». В договорах допустимо словами: «...рубля ноль копеек»."}},
            {"@type": "Question", "name": "Почему «одна тысяча», а не «один тысяча»?",
             "acceptedAnswer": {"@type": "Answer", "text": "«Тысяча» женского рода, поэтому числительные ставятся в женской форме: одна тысяча, две тысячи, пять тысяч."}}
          ]
        }
        </script>
    </div>
</aside>
</div>
</div>

<?php // Инлайн-скрипт — в конце вьюхи (0 лишних HTTP-запросов)
echo View::factory('502/script');
