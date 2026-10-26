<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PDF-инструменты (nj634_pdf) — страница /pdf.
 *
 * Операции выполняет сервер (/pdf/api/*); браузер — только интерфейс.
 * Слева — рабочие вкладки (файл / текст→PDF), справа 1/3 — советы и история.
 */
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-6 gap-6 md:grid md:grid-cols-3 md:items-start">
<div class="md:col-span-2">

    <div class="n2j-card">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="n2j-h3"><i class="fas fa-file-pdf mr-1.5 text-rose-500"></i>PDF-инструменты</h2>
            <span class="n2j-badge n2j-badge-ok ml-auto">
                <i class="fas fa-lock text-[11px]"></i>обработка на сервере
            </span>
        </div>

        <div class="n2j-seg mt-4 inline-flex" role="tablist">
            <button type="button" id="nj634-tab-file" class="n2j-seg-item is-active">Работа с файлом</button>
            <button type="button" id="nj634-tab-txt" class="n2j-seg-item">Текст → PDF</button>
        </div>

        <!-- ============ вкладка «файл» ============ -->
        <div id="nj634-pane-file" class="mt-4">
            <div class="grid gap-3">
                <div>
                    <label class="n2j-label">PDF-файл</label>
                    <input id="nj634-file" type="file" accept=".pdf,application/pdf"
                           class="mt-1.5 block w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:file:bg-blue-950/60 dark:file:text-blue-300">
                    <p class="n2j-hint" id="nj634-file-meta">До <?php echo class_exists('Service_Nj634_Pdf') ? (int) Service_Nj634_Pdf::cfg('max_upload_mb', 15) : 15; ?> МБ. Файл не покидает сервер, посторонними не используется.</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" data-op="info" class="n2j-btn-secondary nj634-op">
                        <i class="fas fa-circle-info"></i> Данные файла
                    </button>
                    <button type="button" data-op="text" class="n2j-btn-secondary nj634-op">
                        <i class="fas fa-font"></i> Извлечь текст
                    </button>
                    <button type="button" data-op="split" class="n2j-btn-secondary nj634-op">
                        <i class="fas fa-scissors"></i> Вырезать страницы
                    </button>
                    <button type="button" data-op="merge" class="n2j-btn-secondary nj634-op">
                        <i class="fas fa-layer-group"></i> Перекомпоновать
                    </button>
                    <button type="button" data-op="mergefiles" class="n2j-btn-secondary nj634-op">
                        <i class="fas fa-files"></i> Объединить файлы
                    </button>
                </div>

                <div id="nj634-split-box" class="hidden">
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label for="nj634-first" class="n2j-label">С (страницы)</label>
                            <input id="nj634-first" type="number" min="1" value="1" class="n2j-input mt-1 w-24">
                        </div>
                        <div>
                            <label for="nj634-last" class="n2j-label">По</label>
                            <input id="nj634-last" type="number" min="1" value="2" class="n2j-input mt-1 w-24">
                        </div>
                        <p class="n2j-hint">Номера с 1; выделит диапазон в отдельный PDF.</p>
                    </div>
                </div>

                <div id="nj634-merge-box" class="hidden">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-[240px] flex-1">
                            <label for="nj634-pages" class="n2j-label">Порядок страниц</label>
                            <input id="nj634-pages" type="text" placeholder="например 3,1,2" class="n2j-input mt-1 w-full font-mono">
                        </div>
                        <p class="n2j-hint">Через запятую; повторы допустимы.</p>
                    </div>
                </div>

                <div id="nj634-mergefiles-box" class="hidden">
                    <label class="n2j-label">Два и более PDF (все страницы по порядку)</label>
                    <input id="nj634-files" type="file" accept=".pdf,application/pdf" multiple
                           class="mt-1.5 block w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 outline-none transition focus:border-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:file:bg-blue-950/60 dark:file:text-blue-300">
                    <p class="n2j-hint" id="nj634-files-meta">До 12 файлов; страницы склеиваются в порядке выбора.</p>
                </div>

                <div class="pt-1">
                    <button type="button" id="nj634-go"
                            class="n2j-btn-process">
                        <i class="fas fa-bolt"></i> Выполнить
                    </button>
                    <span class="ml-2 text-xs text-gray-400 dark:text-gray-500">Лимит: <?php echo HTML::chars($rate_text); ?> с одного адреса.</span>
                </div>
            </div>
        </div>

        <!-- ============ вкладка «текст → PDF» ============ -->
        <div id="nj634-pane-txt" class="mt-4 hidden">
            <div class="grid gap-3">
                <div>
                    <label for="nj634-title" class="n2j-label">Заголовок (не обязательно)</label>
                    <input id="nj634-title" type="text" placeholder="Мои заметки" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
                <div>
                    <label for="nj634-txt" class="n2j-label">Текст</label>
                    <textarea id="nj634-txt" rows="10" placeholder="Вставьте текст сюда — кириллица поддерживается…" autocomplete="off" spellcheck="false"
                              class="n2j-textarea mt-1.5 w-full leading-relaxed"></textarea>
                </div>
                <div>
                    <button type="button" id="nj634-gen"
                            class="n2j-btn-process">
                        <i class="fas fa-file-pdf"></i> Сделать PDF
                    </button>
                    <span class="ml-2 text-xs text-gray-400 dark:text-gray-500">A4, шрифт с кириллицей, до 300 000 символов.</span>
                </div>
            </div>
        </div>

        <!-- Нерасчленяемый статус: спиннер + этап + таймер -->
        <div id="nj634-status" class="mt-4 hidden items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            <i class="fas fa-circle-notch fa-spin text-blue-500"></i>
            <span id="nj634-status-text">Выполняю…</span>
            <span id="nj634-timer" class="font-mono text-xs text-gray-400"></span>
        </div>

        <div id="nj634-error" class="n2j-flash n2j-flash-err hidden mt-4 block"></div>
    </div>

    <!-- Результат -->
    <div class="n2j-card mt-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-list mr-1.5 text-gray-400"></i>Результат</p>
            <div class="flex gap-2">
                <button type="button" id="nj634-dl" class="n2j-btn-secondary n2j-btn-sm hidden">
                    <i class="fas fa-download"></i> Скачать PDF
                </button>
                <button type="button" id="nj634-copy" class="n2j-btn-secondary n2j-btn-sm hidden">
                    <i class="fas fa-copy"></i> Копировать
                </button>
            </div>
        </div>

        <div id="nj634-result" class="mt-3 overflow-auto rounded-lg bg-gray-50 p-4 font-mono text-sm leading-relaxed whitespace-pre-wrap break-words text-gray-800 dark:bg-gray-950 dark:text-gray-100 max-h-[560px]">
            <span class="text-gray-400">Выберите операцию: файл или «Текст → PDF». Обработка идёт на сервере.</span>
        </div>
    </div>

</div>

<aside class="hidden space-y-4 md:block">
    <!-- Быстрые советы -->
    <div class="n2j-card hidden md:block">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-lightbulb mr-1.5 text-amber-400"></i>Быстрые советы</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>• Текст извлекается из текстовых PDF — сканы/фотографии не читаются.</li>
            <li>• «Текст → PDF» делает страницу A4 с кириллицей, без ворда.</li>
            <li>• Страницы нумеруются с 1; перекомпоновка допускает повторы.</li>
            <li>• Файлы обрабатываются сервером и не публикуются.</li>
        </ul>
    </div>

    <!-- Как защищён сервис -->
    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-shield-halved mr-1.5 text-emerald-500"></i>Как защищён сервис</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
            <li>• Никаких внешних программ: разбор PDF — чистый PHP (zlib/mbstring), без команд ОС.</li>
            <li>• Принимаются только файлы с магией %PDF-, не шифрованные, до <?php echo class_exists('Service_Nj634_Pdf') ? (int) Service_Nj634_Pdf::cfg('max_upload_mb', 15) : 15; ?> МБ.</li>
            <li>• Rate limit: <?php echo HTML::chars($rate_text); ?> с одного IP.</li>
        </ul>
    </div>

    <!-- История -->
    <?php if ( ! empty($recent)): ?>
    <div class="n2j-card">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300"><i class="fas fa-history mr-1.5 text-gray-400"></i>Мои операции</p>
        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
            <?php foreach ($recent as $rc): ?>
            <li class="flex items-center justify-between gap-2 py-2 text-xs">
                <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">
                    <span class="font-mono"><?php echo HTML::chars($rc['kind']); ?></span>
                    <?php echo HTML::chars($rc['name']); ?>
                    <?php if ($rc['pages'] !== NULL): ?><span class="text-gray-400"> · <?php echo (int) $rc['pages']; ?> стр.</span><?php endif; ?>
                </span>
                <span class="shrink-0 <?php echo $rc['status'] === 'ok' ? 'text-emerald-500' : 'text-rose-500'; ?>">
                    <i class="fas <?php echo $rc['status'] === 'ok' ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- FAQ -->
    <div class="n2j-card">
        <h2 class="n2j-h3">Частые вопросы</h2>
        <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Файл куда-то отправляется?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Нет. Файл читается только этим сервером, обрабатывается в памяти и не попадает в открытые хранилища.
                    В историю сохраняется сводка (страницы, размер) и, для операции «Текст», сам извлечённый текст.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    Почему текст не извлёкся?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Инструмент читает текстовый слой PDF. Если файл — скан без слоя текста,
                    извлечение вернёт пустоту: тогда нужен OCR, а он сюда не входит.
                    Зашифрованные PDF также не читаются.
                </p>
            </details>
            <details class="group py-3">
                <summary class="cursor-pointer list-none text-sm font-semibold text-gray-800 dark:text-gray-200">
                    <i class="fas fa-chevron-down mr-2 text-xs text-gray-400 transition group-open:rotate-180"></i>
                    «Текст → PDF» поддерживает русский язык?
                </summary>
                <p class="mt-2 pl-6 text-sm text-gray-600 dark:text-gray-300">
                    Да. Генератор встраивает шрифт с кириллицей (DejaVu Sans), поэтому русский текст
                    отображается и копируется корректно без дополнительных программ.
                </p>
            </details>
        </div>
    </div>
</aside>
</div>
</div>

<?php echo View::factory('634/script'); ?>
