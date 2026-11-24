<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Проверка распознавания документов: параметры шлюза → пробный запрос → ответ.
 *
 * Переменные: $config, $result, $error, $csrf.
 */
$fmt = function (int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 0, ',', ' ').' МиБ' : number_format($bytes / 1024, 0, ',', ' ').' КиБ';
};
$old = $old ?? [];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Администрирование', '/admin'], ['Распознавание документов']],
        'title'  => 'Распознавание документов',
        'pill'   => ['prx450 · внешний OCR-шлюз', 'fa-file-lines'],
        'lead'   => 'Загрузите PDF или скан, чтобы получить текст в Markdown. Подключение для других систем доступно через API.',
        'actions'=> [
            ['label' => 'API-доступ', 'href' => '/prx150_api_hub', 'icon' => 'fa-key', 'role' => 'secondary'],
            ['label' => 'Документация', 'href' => '/prx150_api_hub/docs', 'icon' => 'fa-book', 'role' => 'secondary'],
        ],
    ]); ?>

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="min-w-0 space-y-5">
            <?php if ($error): ?>
                <section id="prx450-feedback" tabindex="-1" class="prx-card border-rose-300 dark:border-rose-800" role="alert">
                    <h2 class="prx-h3 text-rose-700 dark:text-rose-300"><i class="fas fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>Не удалось распознать документ</h2>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars([
                        413 => 'Выберите файл меньшего размера. Учитывается также лимит загрузки PHP.',
                        415 => 'Выберите PDF или изображение одного из поддерживаемых форматов.',
                        422 => 'Шлюз не смог обработать документ. Проверьте файл и настройки распознавания.',
                        502 => 'Шлюз вернул некорректный или слишком большой ответ. Проверьте его настройки и лимиты результата.',
                        503 => 'OCR-шлюз недоступен. Проверьте его адрес и ключ или повторите позже.',
                        504 => 'Шлюз не успел ответить. Повторите позже или используйте меньший документ.',
                    ][$error['status']] ?? 'Выберите другой документ или проверьте настройки распознавания.'); ?></p>
                    <a href="#prx450-file" class="prx-btn-secondary prx-btn-sm mt-3">Вернуться к выбору файла</a>
                    <details class="mt-3">
                        <summary class="prx-hint cursor-pointer">Технические сведения</summary>
                        <p class="prx-hint mt-2">Код: <code><?php echo HTML::chars($error['code']); ?></code> · HTTP <?php echo (int) $error['status']; ?></p>
                        <?php if ( ! empty($error['details'])): ?>
                            <pre class="mt-2 overflow-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100"><?php echo HTML::chars(json_encode($error['details'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                        <?php endif; ?>
                    </details>
                </section>
            <?php endif; ?>

            <?php if ($result): ?>
                <section id="prx450-feedback" tabindex="-1" class="prx-card prx-print-area" aria-label="Результат распознавания" aria-live="polite">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="prx-h3"><i class="fas fa-circle-check mr-1.5 text-emerald-500"></i>Результат</h2>
                        <div class="flex flex-wrap gap-1.5">
                            <?php if ($result['cached']): ?><span class="prx-badge prx-badge-ok"><span class="prx-dot"></span>из кэша<?php echo $result['cache_source'] ? ' · '.HTML::chars($result['cache_source']) : ''; ?></span><?php endif; ?>
                            <?php if ($result['engine_used']): ?><span class="prx-badge prx-badge-info"><?php echo HTML::chars($result['engine_used']); ?></span><?php endif; ?>
                            <?php if ($result['llm_fixed']): ?><span class="prx-badge prx-badge-run">LLM-коррекция</span><?php endif; ?>
                        </div>
                    </div>
                    <p class="prx-hint mt-2">Файл: <?php echo HTML::chars($result['file']); ?> · <?php echo $fmt(strlen($result['content'])); ?> текста</p>
                    <div class="no-print mt-3 flex flex-wrap gap-2">
                        <button type="button" class="prx-btn-secondary prx-btn-sm" data-prx-copy="#prx450-content">Копировать</button>
                        <button type="button" class="prx-btn-secondary prx-btn-sm" id="prx450-download">Скачать .md</button>
                        <button type="button" class="prx-btn-secondary prx-btn-sm" id="prx450-print">Печать</button>
                    </div>
                    <pre id="prx450-content" class="mt-3 max-h-[32rem] overflow-auto print:max-h-none print:overflow-visible whitespace-pre-wrap break-words rounded-lg bg-gray-900 p-4 text-xs leading-5 text-gray-100" dir="auto"><?php echo HTML::chars($result['content']); ?></pre>
                    <?php if ( ! empty($result['raw_result'])): ?>
                        <details class="no-print mt-3">
                            <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">Сырой JSON шлюза</summary>
                            <pre class="mt-2 max-h-72 overflow-auto rounded-lg bg-gray-900 p-3 text-xs leading-4 text-gray-100"><?php echo HTML::chars(json_encode($result['raw_result'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                        </details>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="prx-card" aria-labelledby="prx450-form">
                <h2 id="prx450-form" class="prx-h3"><i class="fas fa-file-arrow-up mr-2 text-blue-500" aria-hidden="true"></i>Загрузить документ</h2>
                <p id="prx450-upload-hint" class="prx-hint mt-2">Файл отправляется на внешний OCR-шлюз и не хранится на портале.</p>
                <form method="post" action="/prx450_document_ocr" enctype="multipart/form-data" class="mt-4 space-y-4" id="prx450-upload"
                      data-max-bytes="<?php echo (int) $config['max_bytes']; ?>">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div class="relative rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-5 text-center transition focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500 data-[dragging=true]:border-blue-500 data-[dragging=true]:ring-2 data-[dragging=true]:ring-blue-500 dark:border-gray-600 dark:bg-gray-800/50"
                         data-prx450-drop>
                        <i class="fas fa-cloud-arrow-up text-2xl text-blue-500" aria-hidden="true"></i>
                        <label class="mt-2 block text-sm font-semibold text-gray-900 dark:text-white" for="prx450-file">Выбрать файл</label>
                        <p class="prx-hint mt-1" data-prx450-drag-hint hidden>или перетащите сюда один документ</p>
                        <p id="prx450-file-types" class="prx-hint mt-1">PDF, PNG, JPEG, TIFF, WebP, BMP · до <?php echo $fmt((int) $config['max_bytes']); ?></p>
                        <input class="absolute inset-0 h-full w-full cursor-pointer opacity-0" id="prx450-file" type="file" name="file"
                               accept="<?php echo HTML::chars(implode(',', array_merge(['.pdf', '.png', '.jpg', '.jpeg', '.tif', '.tiff', '.webp', '.bmp'], $config['mime']))); ?>"
                               aria-describedby="prx450-file-types prx450-upload-hint prx450-file-error prx450-file-info" required>
                    </div>
                    <div class="hidden flex flex-wrap items-center justify-between gap-2" data-prx450-selection>
                        <p id="prx450-file-info" class="prx-hint min-w-0 break-words" role="status"></p>
                        <button type="button" class="prx-btn-secondary prx-btn-sm" id="prx450-file-clear">Убрать файл</button>
                    </div>
                    <p id="prx450-file-error" class="prx-hint text-rose-700 dark:text-rose-300" role="alert" hidden></p>

                    <details class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700"<?php echo ($error['status'] ?? NULL) === 422 ? ' open' : ''; ?>>
                        <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-200">Настройки распознавания</summary>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="prx-label">Движок</span>
                                <select class="prx-select mt-1 w-full" name="engine">
                                    <?php foreach (['auto', 'docling-cpu', 'mineru', 'paddleocr-vl'] as $engine): ?>
                                        <option value="<?php echo $engine; ?>"<?php echo ($old['engine'] ?? $config['defaults']['engine']) === $engine ? ' selected' : ''; ?>><?php echo $engine; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="block">
                                <span class="prx-label">Формат</span>
                                <select class="prx-select mt-1 w-full" name="output_format">
                                    <?php foreach (['md' => 'md — текст', 'full' => 'full — с сырым JSON'] as $value => $label): ?>
                                        <option value="<?php echo $value; ?>"<?php echo ($old['output_format'] ?? $config['defaults']['output_format']) === $value ? ' selected' : ''; ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <label class="mt-4 block">
                            <span class="prx-label">Макс. сторона картинки</span>
                            <input class="prx-input mt-1 w-full" type="number" name="max_image_side" min="512" max="8192" step="64" value="<?php echo (int) ($old['max_image_side'] ?? $config['defaults']['max_image_side']); ?>">
                        </label>

                        <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2">
                            <?php
                            $flags = [
                                'preprocess'     => 'Предобработка PDF',
                                'ocr'            => 'OCR сканов',
                                'extract_tables' => 'Таблицы',
                                'extract_images' => 'Извлечь картинки',
                                'vlm_pictures'   => 'Описать картинки (VLM)',
                                'llm_fix'        => 'LLM-коррекция',
                                'force'          => 'Пересчитать без кэша шлюза',
                            ];
                            foreach ($flags as $name => $label): ?>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                    <input type="hidden" name="<?php echo $name; ?>" value="0">
                                    <input type="checkbox" name="<?php echo $name; ?>" value="1"<?php echo !empty($old[$name] ?? $config['defaults'][$name]) ? ' checked' : ''; ?> class="h-4 w-4 accent-blue-600">
                                    <?php echo $label; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <label class="mt-4 block">
                            <span class="prx-label">LLM-модель</span>
                            <input class="prx-input mt-1 w-full" name="llm_model" maxlength="64" value="<?php echo HTML::chars((string) ($old['llm_model'] ?? $config['defaults']['llm_model'])); ?>">
                        </label>
                        <label class="mt-4 block">
                            <span class="prx-label">Инструкция LLM (необязательно)</span>
                            <textarea class="prx-input mt-1 w-full" name="llm_prompt" rows="2" maxlength="4000"><?php echo HTML::chars((string) ($old['llm_prompt'] ?? '')); ?></textarea>
                        </label>
                        <label class="mt-4 block">
                            <span class="prx-label">Нативные параметры шлюза (JSON, необязательно)</span>
                            <input class="prx-input mt-1 w-full" name="extra_config" maxlength="4000" placeholder='{"lang":"ru","table_mode":"accurate"}' value="<?php echo HTML::chars((string) ($old['extra_config'] ?? '')); ?>">
                        </label>

                    </details>
                    <button class="prx-btn-process" type="submit" data-prx-busy-show="#prx450-busy">
                        <span data-prx-idle><i class="fas fa-wand-magic-sparkles mr-1" aria-hidden="true"></i>Распознать документ</span>
                        <span data-prx-busy class="hidden"><i class="fas fa-circle-notch prx-spin mr-1" aria-hidden="true"></i>Распознавание · <span data-prx-clock>0:00</span></span>
                    </button>
                    <p id="prx450-busy" class="prx-hint mt-2 hidden" role="status" aria-live="polite">Распознавание может занять до <?php echo (int) $config['timeout']; ?> с. Не закрывайте страницу до получения результата.</p>
                </form>
            </section>
        </div>

        <aside class="min-w-0 space-y-5" aria-label="Шлюз и подключение API">
            <section class="prx-card" aria-labelledby="prx450-upstream">
                <h2 id="prx450-upstream" class="prx-h3">Шлюз</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Адрес</dt><dd class="break-all text-right font-mono text-xs text-gray-800 dark:text-gray-100"><?php echo HTML::chars($config['base_url']); ?><?php echo HTML::chars($config['parse_path']); ?></dd></div>
                    <div class="flex items-center justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Таймаут</dt><dd class="tabular-nums"><?php echo (int) $config['timeout']; ?> с</dd></div>
                    <div class="flex items-center justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Ключ шлюза</dt><dd><?php echo $config['has_key'] ? '<span class="prx-badge prx-badge-ok"><span class="prx-dot"></span>задан</span>' : '<span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>не задан</span>'; ?></dd></div>
                    <div class="flex items-center justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Движок по умолчанию</dt><dd><code class="text-xs"><?php echo HTML::chars($config['defaults']['engine']); ?></code></dd></div>
                </dl>
                <p class="prx-hint mt-3">Меняется в <code>.env</code>: <code>PRX450_OCR_BASE_URL</code>, <code>PRX450_OCR_API_KEY</code>, <code>PRX450_OCR_TIMEOUT</code>. Для локального Tesseract — <a class="prx-link" href="/prx150_api_hub/docs">инструкция</a>.</p>
            </section>

            <section class="prx-card">
                <h2 class="prx-h3">API для потребителя</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Ключ выдаётся в <a class="prx-link" href="/prx150_api_hub">API-доступе</a> со scope <code>prx450.parse</code>.</p>
                <pre class="mt-3 overflow-auto rounded-lg bg-gray-900 p-3 text-xs leading-5 text-gray-100"><code>POST /api/v1/document-ocr/parse
Authorization: Bearer prx_…
Content-Type: multipart/form-data

file=@document.pdf</code></pre>
                <p class="prx-hint mt-2">Ответ: <code class="break-all">{"file","content","cached","engine_used","llm_fixed","raw_result"}</code>.</p>
            </section>

            <section class="prx-card">
                <h2 class="prx-h3">Что проверить</h2>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <li><i class="fas fa-check mr-1.5 w-4 text-emerald-500"></i>Адрес шлюза доступен с сервера PHP.</li>
                    <li><i class="fas fa-key mr-1.5 w-4 text-gray-400"></i>Ключ шлюза задан, если он требуется.</li>
                    <li><i class="fas fa-file-pdf mr-1.5 w-4 text-gray-400"></i>PDF не зашифрован и не больше лимита.</li>
                    <li><i class="fas fa-chart-line mr-1.5 w-4 text-gray-400"></i>Запросы видны в <a class="prx-link" href="/prx150_api_hub/usage">журнале API</a>.</li>
                </ul>
            </section>
        </aside>
    </div>
</div>

<?php echo View::factory('prx450/script'); ?>
