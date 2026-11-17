<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['Документооборот'], ['VLM-демо']],
        'title' => 'VLM-разбор входящего письма',
        'pill' => ['prl398 · демо', 'fa-eye'],
        'actions' => [
            ['label' => 'Входящие', 'href' => '/inbox', 'icon' => 'fa-inbox', 'role' => 'secondary'],
            ['label' => 'Реестр', 'href' => '/documents', 'icon' => 'fa-folder-open', 'role' => 'secondary'],
        ],
        'lead' => 'PDF рендерится в браузере, страницы смотрит vision-модель. Без OCR, Python-воркеров и очереди: это витрина возможностей VLM, а не рабочий конвейер.',
    ]); ?>

    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-100" role="note">
        <div class="flex items-start gap-2">
            <i class="fas fa-triangle-exclamation mt-0.5"></i>
            <div>
                <strong>Демо.</strong> Используйте тестовые или обезличенные письма. Страницы отправляются в модель, указанную в конфигурации; результат не сохраняется в реестр и не меняет карточки.
                <span class="block mt-1 text-xs">По умолчанию разрешён только локальный LLM-шлюз. Внешний провайдер включается явно через <code class="font-mono">PRL398_ALLOWED_PROVIDERS</code>.</span>
            </div>
        </div>
    </div>

    <div id="vlm-app"
         class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-2"
         data-analyze-url="<?php echo HTML::chars(URL::site('vlm-demo/analyze')); ?>"
         data-max-pages="<?php echo (int) $max_pages; ?>"
         data-can-run="<?php echo $can_run ? '1' : '0'; ?>">
        <section class="prl-card" aria-labelledby="vlm-upload-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="vlm-upload-title" class="text-base font-semibold text-gray-900 dark:text-white">
                        <i class="fas fa-file-arrow-up mr-2 text-blue-500"></i>Письмо
                    </h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">PDF, PNG или JPEG. PDF не покидает браузер: на сервер уходят только отрендеренные страницы.</p>
                </div>
                <span class="prl-badge prl-badge-info">до <?php echo (int) $max_pages; ?> стр.</span>
            </div>

            <input id="vlm-csrf" type="hidden" value="<?php echo HTML::chars($csrf); ?>">
            <input id="vlm-file" type="file" accept="application/pdf,image/png,image/jpeg" class="sr-only">

            <label id="vlm-dropzone" for="vlm-file"
                   class="mt-4 flex min-h-[220px] cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center transition hover:border-blue-400 hover:bg-blue-50 dark:border-gray-600 dark:bg-gray-800/50 dark:hover:border-blue-500 dark:hover:bg-gray-800">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300">
                    <i class="fas fa-cloud-arrow-up text-xl"></i>
                </span>
                <span class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">Перетащите PDF сюда</span>
                <span class="mt-1 text-xs text-gray-500 dark:text-gray-400">или нажмите, чтобы выбрать файл. Максимум <?php echo (int) $max_pages; ?> страницы, по 2 МБ.</span>
            </label>

            <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <label class="prl-label" for="vlm-pages">Сколько страниц отправить</label>
                    <select id="vlm-pages" class="prl-select mt-1">
                        <?php for ($page = 1; $page <= (int) $max_pages; $page++): ?>
                            <option value="<?php echo $page; ?>" <?php echo $page === 1 ? 'selected' : ''; ?>><?php echo $page; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <span class="text-xs text-gray-500 dark:text-gray-400">Первой страницы обычно достаточно для письма.</span>
            </div>

            <div id="vlm-preview" class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3" aria-live="polite"></div>

            <button id="vlm-run" type="button" class="prl-btn-cta mt-4 w-full" <?php echo $can_run ? '' : 'disabled'; ?>>
                <i class="fas fa-eye"></i>
                <span>Разобрать через VLM</span>
            </button>

            <p id="vlm-status" class="mt-3 text-xs text-gray-500 dark:text-gray-400" role="status">
                <?php echo $can_run ? 'Файл ещё не выбран.' : 'Нет права prl398.run — запуск недоступен.'; ?>
            </p>

            <div class="mt-4 border-t border-gray-200 pt-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                <span class="font-semibold">Модель:</span>
                <code class="font-mono break-words"><?php echo HTML::chars($model); ?></code>
            </div>
        </section>

        <section class="prl-card" aria-labelledby="vlm-result-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="vlm-result-title" class="text-base font-semibold text-gray-900 dark:text-white">
                    <i class="fas fa-wand-magic-sparkles mr-2 text-blue-500"></i>Что увидела модель
                </h2>
                <button id="vlm-download" type="button" class="prl-btn-secondary hidden">
                    <i class="fas fa-download"></i><span>JSON</span>
                </button>
            </div>

            <div id="vlm-empty" class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                <i class="fas fa-image mb-3 block text-4xl text-gray-300 dark:text-gray-600"></i>
                Загрузите письмо и нажмите «Разобрать через VLM».<br>
                <span class="text-xs">Модель увидит бланк, таблицы, печати и подписи — то, что теряет текстовый слой.</span>
            </div>

            <div id="vlm-loading" class="hidden py-12 text-center" role="status" aria-live="polite">
                <i class="fas fa-circle-notch fa-spin text-3xl text-blue-500"></i>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Модель смотрит страницы…</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Обычно 10–40 секунд. Не закрывайте вкладку.</p>
            </div>

            <div id="vlm-error" class="hidden mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-200" role="alert">
                <div class="flex items-start gap-2">
                    <i class="fas fa-circle-exclamation mt-0.5"></i>
                    <span id="vlm-error-text"></span>
                </div>
            </div>

            <div id="vlm-result" class="hidden"></div>
        </section>
    </div>

    <p class="mt-5 text-xs text-gray-500 dark:text-gray-400">
        <i class="fas fa-shield-halved mr-1"></i>
        Демо не сохраняет файлы и результат в БД. Для production-сценария нужен отдельный конвейер с очередью, аудитом и проверкой человеком.
    </p>
</div>

<script type="application/json" id="vlm-field-labels"><?php echo json_encode($field_labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="application/json" id="vlm-types"><?php echo json_encode($types, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="module" src="/assets/js/prl398_vlm_demo.js"></script>
