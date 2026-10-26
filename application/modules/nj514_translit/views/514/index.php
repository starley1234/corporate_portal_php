<?php defined('SYSPATH') or die('No direct script access.');

$faq = [
    ['Как правильно транслитерировать фамилию для загранпаспорта?', 'Выберите таблицу МВД для российского загранпаспорта. Если у вас уже есть действующий паспорт или билет, для точного совпадения перепишите латинское имя именно из документа: написание Ё, Й и твёрдого знака может быть неоднозначным.'],
    ['Чем ГОСТ отличается от паспортной транслитерации?', 'ГОСТ 7.79-2000 система Б использует диграфы и апострофы, например Ё → Yo, Й → J, Щ → Shh. В таблице для загранпаспорта Ё и Е совпадают, как и И и Й.'],
    ['Можно ли точно восстановить кириллицу из латиницы?', 'Не всегда. В паспортной таблице несколько русских букв дают одну латинскую: E может означать Е, Ё или Э, I — И или Й. Калькулятор показывает варианты, но не угадывает исходное имя.'],
    ['Что делает режим ICAO?', 'Показывает текст заглавной латиницей и заменяет пробелы знаком «<», как разделитель имени в MRZ-записях. Он не создаёт полный машиносчитываемый документ и не проверяет его формат.'],
    ['Отправляется ли введённый текст на сервер?', 'Нет. Основная конвертация идёт в браузере. Текст не добавляется в адрес страницы, cookies, localStorage или историю расчётов.'],
];
$letters = preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY);
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<main class="min-w-0 flex-1">
    <header class="mb-5">
        <p class="text-xs font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-400">Кириллица ↔ латиница</p>
        <h1 class="n2j-h1 mt-1">Транслитерация по стандартам</h1>
        <p class="mt-2 max-w-3xl text-base text-gray-600 dark:text-gray-300">Для загранпаспорта, документов и машиносчитываемого написания. Сравните правила, переведите текст обратно и проверьте буквы, которые меняют результат.</p>
    </header>

    <section class="n2j-card">
        <h2 class="n2j-h3">Выберите таблицу</h2>
        <div id="tl-standards" class="mt-3 flex flex-wrap gap-2" role="radiogroup" aria-label="Стандарт транслитерации">
            <?php foreach ($standards as $code => $standard): ?>
            <button type="button" class="n2j-chip tl-standard<?php echo $code === 'passport' ? ' is-on' : ''; ?>" data-standard="<?php echo HTML::chars($code); ?>" aria-checked="<?php echo $code === 'passport' ? 'true' : 'false'; ?>" role="radio">
                <?php echo HTML::chars($standard['title']); ?>
            </button>
            <?php endforeach; ?>
        </div>
        <p id="tl-standard-description" class="mt-3 rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-600 dark:bg-gray-900/60 dark:text-gray-300"><?php echo HTML::chars($standards['passport']['description']); ?></p>

        <div id="tl-directions" class="mt-4 flex flex-wrap gap-2" role="radiogroup" aria-label="Направление перевода">
            <button type="button" class="n2j-chip tl-direction is-on" data-direction="to_latin" aria-checked="true" role="radio">Русский → латиница</button>
            <button type="button" class="n2j-chip tl-direction" data-direction="to_cyrillic" aria-checked="false" role="radio">Латиница → русский</button>
        </div>

        <div class="mt-4 grid gap-3 lg:grid-cols-2">
            <div>
                <label for="tl-input" id="tl-input-label" class="n2j-label">Русский текст</label>
                <textarea id="tl-input" maxlength="<?php echo (int) $max_chars; ?>" rows="5" placeholder="Например, Щербаков Иван" class="n2j-textarea mt-1.5 min-h-[150px] w-full resize-y font-mono"></textarea>
                <p class="n2j-hint flex justify-between"><span>До <?php echo (int) $max_chars; ?> символов. Не вставляйте секреты.</span><span id="tl-count">0 / <?php echo (int) $max_chars; ?></span></p>
            </div>
            <div>
                <label for="tl-output" id="tl-output-label" class="n2j-label">Результат</label>
                <textarea id="tl-output" rows="5" readonly placeholder="Здесь появится результат" class="n2j-textarea mt-1.5 min-h-[150px] w-full resize-y font-mono font-semibold"></textarea>
                <p id="tl-ambiguity" class="mt-1 min-h-5 text-xs leading-relaxed text-amber-700 dark:text-amber-300"></p>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button id="tl-swap" type="button" class="n2j-btn-cta"><i class="fas fa-right-left"></i> Обратный перевод</button>
            <button id="tl-copy" type="button" disabled class="n2j-btn-secondary"><i class="fas fa-copy"></i> Копировать</button>
            <button type="button" data-example="Щербаков Иван" class="n2j-btn-secondary n2j-btn-sm tl-example">Щербаков Иван</button>
            <button type="button" data-example="Подъём и ёж" class="n2j-btn-secondary n2j-btn-sm tl-example">Подъём и ёж</button>
        </div>
        <p class="mt-4 flex items-start gap-2 rounded-lg bg-emerald-50 p-3 text-xs leading-relaxed text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-100"><i class="fas fa-shield-halved mt-0.5"></i><span><?php echo HTML::chars($privacy_note); ?></span></p>
    </section>

    <section class="n2j-card mt-4">
        <div class="flex flex-wrap items-end justify-between gap-2"><div><h2 class="n2j-h3">Таблица букв</h2><p class="n2j-hint">Текущий стандарт; обратные варианты отмечаются отдельно.</p></div><span id="tl-table-title" class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">Загранпаспорт РФ · МВД</span></div>
        <div class="mt-3 overflow-x-auto">
            <table class="n2j-table min-w-[420px]"><thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400"><tr><th class="py-2 pr-4">Кириллица</th><th class="py-2">Латиница</th></tr></thead><tbody id="tl-table" class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($letters as $letter): ?>
                <tr><th class="py-1.5 pr-4 font-mono text-gray-800 dark:text-gray-200"><?php echo HTML::chars(mb_strtoupper($letter, 'UTF-8').' · '.$letter); ?></th><td class="py-1.5 font-mono text-gray-700 dark:text-gray-300"><?php echo HTML::chars($standards['passport']['mapping'][$letter] !== '' ? $standards['passport']['mapping'][$letter] : '—'); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
        </div>
    </section>
</main>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
    <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-xs dark:border-amber-900/60 dark:bg-amber-950/30">
        <h2 class="text-sm font-bold text-amber-900 dark:text-amber-200"><i class="fas fa-circle-exclamation mr-1.5"></i>Не угадывайте написание</h2>
        <p class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80">Обратное преобразование может иметь несколько вариантов: паспортная E — это Е, Ё или Э; I — И или Й. Для билета и документов сверяйте написание с действующим загранпаспортом.</p>
        <p class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80">Режим ICAO показывает заглавные символы и разделитель `<`, но не создаёт полный MRZ-код.</p>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Стандарты и первоисточники</h2>
        <ul class="mt-3 space-y-3 text-xs">
            <?php foreach ($standards as $standard): ?>
            <li><p class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($standard['title']); ?></p><a href="<?php echo HTML::chars($standard['source']); ?>" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block font-semibold text-emerald-700 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900 dark:text-emerald-300"><?php echo HTML::chars($standard['source_title']); ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="n2j-card">
        <h2 class="n2j-h3">Другие инструменты для текста</h2>
        <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
            <a href="/summa-propisyu" class="n2j-chip">Сумма прописью</a>
            <a href="/date-words" class="n2j-chip">Дата прописью</a>
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
<?php echo View::factory('514/script', ['standards' => $standards, 'max_chars' => $max_chars]); ?>
</script>
