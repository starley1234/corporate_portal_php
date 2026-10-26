<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON-инструмент (nj614_json) — страница.
 *
 * Сервер рендерит начальное состояние (из ?q=&indent=&sort=), дальше считает
 * JS (views/614/script.php — клиентское зеркало Service_Nj614_Json).
 * Эталон дизайна: главный блок 2/3 + правая колонка-компаньон 1/3.
 */
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <textarea id="nj614-input" spellcheck="false" autocomplete="off"
                  placeholder='Вставьте JSON… например: {"user": {"name": "Anna", "roles": ["admin", "lead"]}}'
                  class="n2j-textarea min-h-[260px] w-full font-mono leading-relaxed"
        ><?php echo HTML::chars($text); ?></textarea>
        <p class="n2j-hint mt-1.5" id="nj614-hint">Подсказка: JSON понятен и валидатору, и минификатору — состояние хранится в ссылке.</p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                Отступ
                <select id="nj614-indent" class="n2j-input n2j-select font-medium w-auto">
                    <option value="0"<?php echo $indent === 0 ? ' selected' : ''; ?>>0 — сжать</option>
                    <option value="2"<?php echo $indent === 2 ? ' selected' : ''; ?>>2 пробела</option>
                    <option value="4"<?php echo $indent === 4 ? ' selected' : ''; ?>>4 пробела</option>
                    <option value="-1"<?php echo $indent === -1 ? ' selected' : ''; ?>>Таб</option>
                </select>
            </label>

            <label class="flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                <input type="checkbox" id="nj614-sort"<?php echo $sort ? ' checked' : ''; ?>
                       class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                Сортировать ключи
            </label>

            <div class="ml-auto flex flex-wrap gap-2">
                <button type="button" id="nj614-copy"
                        class="n2j-btn-process">
                    <i class="fas fa-copy"></i> Копировать
                </button>
                <button type="button" id="nj614-sample"
                        class="n2j-btn-secondary">
                    <i class="fas fa-wand-magic-sparkles"></i> Пример
                </button>
                <button type="button" id="nj614-clear"
                        class="n2j-btn-secondary">
                    <i class="fas fa-eraser"></i> Очистить
                </button>
            </div>
        </div>
    </div>

    <!-- Результат -->
    <div class="n2j-card mt-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-code mr-1.5 text-gray-400"></i>Результат</p>
            <span id="nj614-status" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-400 dark:text-gray-500">
                <span class="inline-block h-2 w-2 rounded-full bg-gray-300 dark:bg-gray-600"></span>готов к работе
            </span>
        </div>

        <div id="nj614-error" class="n2j-flash n2j-flash-err hidden mt-3 block"></div>

        <pre id="nj614-output" class="mt-3 max-h-[620px] overflow-auto rounded-lg bg-gray-50 p-4 font-mono text-sm leading-relaxed text-gray-800 dark:bg-gray-950 dark:text-gray-100"><span class="text-gray-400">Результат появится здесь — начните вводить или вставьте JSON.</span></pre>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <!-- Быстрые подсказки -->
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>• Вставляйте любой фрагмент — мусор вокруг JSON отрезается.</li>
            <li>• «Сжать» — одна строка для конфигов и логов.</li>
            <li>• Ошибка показывается с позицией проблемного символа.</li>
        </ul>
    </div>

    <!-- Статистика структуры -->
    <div id="nj614-stats-wrap" class="n2j-card hidden">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-chart-simple mr-1.5 text-gray-400"></i>Структура</p>
        <dl id="nj614-stats" class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm"></dl>
    </div>

    <!-- FAQ (SEO) -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как отформатировать JSON онлайн?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Вставьте текст в поле — он отформатируется автоматически с отступом 2/4 пробела или табом.
                    Мусор вокруг (как в логах) отбрасывается, ключи можно отсортировать.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Данные отправляются на сервер?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    По умолчанию всё считается в вашем браузере. Серверная версия (API /nj614/api/format)
                    нужна интеграциям и ИИ-агентам — используйте её, если прогоняете JSON через сервер для анализа.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 marker:hidden dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Как подключить к своему агенту?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Через MCP-ключ платформы (/mcp) или напрямую: POST на /nj614/api/format с полем text —
                    получите {"ok": true, "output": "…", "stats": {…}}.
                </p>
            </details>
        </div>
        <script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          "mainEntity": [
            {"@type": "Question", "name": "Как отформатировать JSON онлайн?",
             "acceptedAnswer": {"@type": "Answer", "text": "Вставьте JSON в поле — он отформатируется автоматически с отступом 2/4 пробела или табом; ключи можно отсортировать."}},
            {"@type": "Question", "name": "Данные отправляются на сервер?",
             "acceptedAnswer": {"@type": "Answer", "text": "По умолчанию всё считается в браузере; серверный API нужен интеграциям и ИИ-агентам для прогона через сервер."}},
            {"@type": "Question", "name": "Как подключить к своему агенту?",
             "acceptedAnswer": {"@type": "Answer", "text": "Через MCP-ключ платформы или POST на /nj614/api/format с полем text."}}
          ]
        }
        </script>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('614/script'); ?>
