<?php defined('SYSPATH') or die('No direct script access.');

$faq = [
    ['Как написать дату 01.09.2026 прописью?', 'Для обычной документной формулировки: «первого сентября две тысячи двадцать шестого года». Для поля с цифровым числом дня можно использовать вид «01» сентября 2026 г.'],
    ['Как записать период с 1 по 5 сентября?', 'Кратко в пределах одного месяца: «с первого по пятое сентября две тысячи двадцать шестого года». Если меняется месяц или год, обе даты показываются полностью.'],
    ['Какие даты принимает калькулятор?', 'Календарная дата в формате ГГГГ-ММ-ДД или ДД.ММ.ГГГГ. Несуществующие даты, например 31 апреля, не принимаются; 29 февраля доступно только в високосный год.'],
    ['Подходит ли формулировка для любого договора?', 'Это заготовка текста, а не юридическое заключение. Проверьте формулировку, падеж и оформление по шаблону организации и требованиям конкретного документа.'],
    ['Сохраняются ли даты?', 'Нет. Дата не сохраняется на сервере; ссылка содержит только введённые параметры, чтобы можно было вернуться к примеру.'],
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<main class="min-w-0 flex-1">
    <header class="mb-5">
        <p class="text-xs font-bold uppercase tracking-widest text-blue-600 dark:text-blue-400">Для договора · приказа · акта</p>
        <h1 class="n2j-h1 mt-1">Дата прописью</h1>
        <p class="mt-2 max-w-3xl text-base text-gray-600 dark:text-gray-300">Преобразуйте дату в текст в правильном падеже, получите привычный формат с кавычками или запишите период действия документа.</p>
    </header>

    <section class="n2j-card">
        <form id="datewords-form" method="get" action="/date-words">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="dw-date" class="n2j-label">Дата начала</label>
                    <input id="dw-date" name="date" type="date" value="<?php echo HTML::chars($date); ?>" class="n2j-input mt-1.5 w-full font-semibold">
                </div>
                <div>
                    <label for="dw-end" class="n2j-label">Дата окончания <span class="normal-case font-normal">(необязательно)</span></label>
                    <input id="dw-end" name="end" type="date" value="<?php echo HTML::chars($end); ?>" class="n2j-input mt-1.5 w-full font-semibold">
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="submit" class="n2j-btn-process"><i class="fas fa-wand-magic-sparkles"></i> Преобразовать</button>
                <button type="button" data-start="2026-09-01" data-end="" class="n2j-btn-secondary n2j-btn-sm dw-example">1 сентября 2026</button>
                <button type="button" data-start="2026-09-01" data-end="2026-09-30" class="n2j-btn-secondary n2j-btn-sm dw-example">Весь сентябрь</button>
                <button type="button" data-start="2026-12-31" data-end="2027-01-02" class="n2j-btn-secondary n2j-btn-sm dw-example">Переход года</button>
            </div>
        </form>
    </section>

    <section class="mt-4 rounded-xl border-2 border-blue-500 bg-blue-50/60 p-4 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20 sm:p-6" aria-live="polite">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div><p class="text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">Дата для документа</p><p id="dw-long" class="mt-1 text-2xl font-extrabold leading-snug text-blue-800 dark:text-blue-200 sm:text-3xl"><?php echo $result !== NULL ? HTML::chars($result['long']) : 'Введите дату — варианты появятся сразу'; ?></p></div>
            <button id="dw-copy-long" type="button" class="n2j-btn-process n2j-btn-sm" <?php echo $result === NULL ? 'disabled' : ''; ?>><i class="fas fa-copy"></i> Копировать текст</button>
        </div>
        <p id="dw-error" class="mt-2 hidden text-sm font-semibold text-rose-700 dark:text-rose-300"></p>
        <div id="dw-formats" class="mt-4 grid gap-2 sm:grid-cols-2">
            <div class="rounded-lg bg-white/80 p-3 dark:bg-gray-900/50"><p class="text-xs font-semibold text-gray-500 dark:text-gray-400">День в кавычках</p><p id="dw-quoted" class="mt-1 text-sm font-bold text-gray-900 dark:text-white"><?php echo $result !== NULL ? HTML::chars($result['quoted']) : '—'; ?></p></div>
            <div class="rounded-lg bg-white/80 p-3 dark:bg-gray-900/50"><p class="text-xs font-semibold text-gray-500 dark:text-gray-400">Числовая запись</p><p id="dw-numeric" class="mt-1 text-sm font-bold text-gray-900 dark:text-white"><?php echo $result !== NULL ? HTML::chars($result['numeric']) : '—'; ?></p></div>
            <div class="rounded-lg bg-white/80 p-3 dark:bg-gray-900/50 sm:col-span-2"><p class="text-xs font-semibold text-gray-500 dark:text-gray-400">Краткий вариант</p><p id="dw-plain" class="mt-1 text-sm font-bold text-gray-900 dark:text-white"><?php echo $result !== NULL ? HTML::chars($result['plain']) : '—'; ?></p></div>
        </div>
        <div id="dw-range-wrap" class="mt-4 rounded-lg border border-blue-200 bg-white/70 p-4 dark:border-blue-900/50 dark:bg-gray-900/50 <?php echo $end === '' ? 'hidden' : ''; ?>">
            <p class="text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">Диапазон</p>
            <p id="dw-range" class="mt-1 text-lg font-extrabold text-gray-900 dark:text-white"><?php echo $range !== NULL ? HTML::chars($range['long']) : '—'; ?></p>
            <p id="dw-range-numeric" class="n2j-hint"><?php echo $range !== NULL ? HTML::chars($range['numeric']) : ''; ?></p>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button id="dw-copy-range" type="button" class="n2j-btn-secondary" <?php echo $range === NULL ? 'disabled' : ''; ?>><i class="fas fa-copy"></i> Копировать диапазон</button>
            <button id="dw-share" type="button" class="n2j-btn-secondary"><i class="fas fa-link"></i> Скопировать ссылку</button>
        </div>
    </section>

    <section class="n2j-card mt-4">
        <h2 class="n2j-h3">Как выбрать формат</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-700"><p class="text-xs font-bold text-gray-900 dark:text-white">Прописью</p><p class="mt-1 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Подходит для текста договора и приказа: «первого сентября две тысячи двадцать шестого года».</p></div>
            <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-700"><p class="text-xs font-bold text-gray-900 dark:text-white">С числом</p><p class="mt-1 text-xs leading-relaxed text-gray-600 dark:text-gray-300">«01» сентября 2026 г. — распространённый шаблон с днём в кавычках.</p></div>
            <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-700"><p class="text-xs font-bold text-gray-900 dark:text-white">Период</p><p class="mt-1 text-xs leading-relaxed text-gray-600 dark:text-gray-300">Для одного месяца день окончания ставится в винительном падеже: «с первого по пятое сентября».</p></div>
        </div>
    </section>
</main>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-xs dark:border-amber-900/60 dark:bg-amber-950/30">
        <h2 class="text-sm font-bold text-amber-900 dark:text-amber-200"><i class="fas fa-triangle-exclamation mr-1.5"></i>Проверьте шаблон</h2>
        <p class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80">Форматирование помогает подготовить черновик, но не заменяет правила делопроизводства и требования вашего договора. Проверьте падежи, кавычки и сокращения перед подписанием.</p>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Другие инструменты для документов</h2>
        <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
            <a href="/summa-propisyu" class="n2j-chip">Сумма прописью</a>
            <a href="/calendar" class="n2j-chip">Календарь</a>
        </div>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <?php foreach ($faq as $i => $item): ?>
            <details class="group py-3" <?php echo $i === 0 ? 'open' : ''; ?>><summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200"><i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i><?php echo HTML::chars($item[0]); ?></summary><p class="mt-2 pl-6 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($item[1]); ?></p></details>
            <?php endforeach; ?>
        </div>
    </section>
</aside>
</div>
</div>
<script type="application/ld+json"><?php echo json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($item) { return ['@type' => 'Question', 'name' => $item[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]]]; }, $faq)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script>
<?php echo View::factory('513/script', ['date' => $date, 'end' => $end, 'result' => $result, 'range' => $range]); ?>
</script>
