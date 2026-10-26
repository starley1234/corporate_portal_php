<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Markdown-инструмент (nj615_markdown) — страница.
 * Слева редактор, справа живой предпросмотр; форма <md = стопкой.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="md:grid md:grid-cols-2 md:gap-4 md:space-y-0 space-y-4">
        <!-- Ввод -->
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <label for="nj615-input" class="n2j-label">Markdown</label>
                <span class="text-xs text-gray-400">живой предпросмотр</span>
            </div>
            <textarea id="nj615-input" spellcheck="false" autocomplete="off"
                      placeholder="<?php echo HTML::chars("# Заголовок\n\n**Жирный** текст и *курсив*.\n\n- пункт 1\n- пункт 2\n\n`код` и [ссылка](https://n2j.ru)"); ?>"
                      class="n2j-textarea mt-2 min-h-[420px] w-full font-mono leading-relaxed"
            ><?php echo HTML::chars($input); ?></textarea>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" id="nj615-sample"
                        class="n2j-btn-secondary">
                    <i class="fas fa-wand-magic-sparkles"></i> Пример
                </button>
                <button type="button" id="nj615-clear"
                        class="n2j-btn-secondary">
                    <i class="fas fa-eraser"></i> Очистить
                </button>
            </div>
        </div>

        <!-- Предпросмотр -->
        <div class="n2j-card">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Предпросмотр</p>
                <div class="flex gap-2">
                    <button type="button" id="nj615-copy-html" class="n2j-btn-secondary n2j-btn-sm">
                        <i class="fas fa-code mr-1"></i>HTML
                    </button>
                    <button type="button" id="nj615-copy-md" class="n2j-btn-secondary n2j-btn-sm">
                        <i class="fas fa-copy mr-1"></i>MD
                    </button>
                </div>
            </div>
            <div id="nj615-preview" class="mt-3 max-w-none text-gray-800 dark:text-gray-100 break-words">
                <?php echo $result !== NULL ? $result['html'] : '<p class="text-gray-400">Предпросмотр появится здесь.</p>'; ?>
            </div>
        </div>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <!-- Статистика -->
    <div id="nj615-stats-wrap" class="n2j-card hidden">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-chart-simple mr-1.5 text-gray-400"></i>Статистика</p>
        <dl id="nj615-stats" class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm"></dl>
    </div>

    <!-- Подсказки по синтаксису -->
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Синтаксис</p>
        <ul class="mt-3 space-y-1.5 font-mono text-xs text-gray-600 dark:text-gray-300">
            <li><span class="text-gray-400">#</span> Заголовок</li>
            <li><span class="text-gray-400">**</span>жирный<span class="text-gray-400">**</span>, <span class="text-gray-400">*</span>курсив<span class="text-gray-400">*</span></li>
            <li><span class="text-gray-400">-</span> список, <span class="text-gray-400">1.</span> нумерованный</li>
            <li><span class="text-gray-400">&gt;</span> цитата, <span class="text-gray-400">```</span> код</li>
            <li><span class="text-gray-400">[текст](https://…)</span> ссылка</li>
        </ul>
    </div>

    <!-- FAQ (SEO) -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Метки отправляются на сервер?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет: предпросмотр собирается прямо в браузере. Серверная версия (/nj615/api/render)
                    — для интеграций и ИИ-агентов, которым нужно прогонять Markdown через сервер.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как получить чистый HTML?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нажмите «HTML» над предпросмотром — фрагмент скопируется в буфер. Или вызовите
                    POST /nj615/api/render с полем text.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Это безопасно для вставки на сайт?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да: любой пользовательский ввод экранируется, ссылки допускаются только на
                    http(s)/mailto/# — сырой HTML из текста не проходит.
                </p>
            </details>
        </div>
        <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          "mainEntity": [
            {"@type": "Question", "name": "Метки отправляются на сервер?",
             "acceptedAnswer": {"@type": "Answer", "text": "Нет, предпросмотр собирается в браузере; серверная версия API — для интеграций и ИИ-агентов."}},
            {"@type": "Question", "name": "Как получить чистый HTML?",
             "acceptedAnswer": {"@type": "Answer", "text": "Нажмите HTML над предпросмотром или вызовите POST /nj615/api/render с полем text."}},
            {"@type": "Question", "name": "Это безопасно для вставки на сайт?",
             "acceptedAnswer": {"@type": "Answer", "text": "Да: ввод экранируется, ссылки только на http(s)/mailto/#, сырой HTML не проходит."}}
          ]
        }
        </script>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('615/script'); ?>
