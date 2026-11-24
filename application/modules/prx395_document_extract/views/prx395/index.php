<?php defined('SYSPATH') or die('No direct script access.');
$labels_limits = $limits['labels'];
$state = $job['status'] ?? '';
$labels = ['queued' => 'В очереди', 'running' => 'В обработке', 'succeeded' => 'Текст готов', 'failed' => 'Ошибка', 'canceled' => 'Отменено'];
$messages = [
    'worker_not_configured' => 'На стороне PHP не задан WORKER_API_TOKEN.',
    'file_required' => 'Выберите PDF-файл.', 'file_too_large' => 'Файл больше '.$labels_limits['max_pdf'].'.',
    'pdf_required' => 'Загрузите именно PDF.', 'page_limit' => 'Превышен лимит страниц: '.$labels_limits['max_pages'].'.',
    'result_too_large' => 'Результат превышает '.$labels_limits['max_text'].' текста.',
    'encrypted_pdf' => 'Снимите пароль с PDF и загрузите заново.', 'invalid_pdf' => 'Не удалось прочитать PDF.',
    'not_found' => 'Задача недоступна или срок хранения истёк.', 'no_text' => 'На страницах не найдено текста.',
    'ocr_unavailable' => 'Движок распознавания недоступен. Проверьте Tesseract и Poppler или адрес OCR-шлюза.',
    'ocr_page_failed' => 'Не удалось распознать страницу. Проверьте PDF и наличие языков rus и eng у Tesseract.',
    'worker_config' => 'У воркера нет адреса сайта или токена. Проверьте PRX120_API_BASE и WORKER_API_TOKEN.',
    'worker_auth' => 'PHP не принял токен воркера. Сверьте WORKER_API_TOKEN на PHP и Python.',
];
$code = $error ?? $job['error']['code'] ?? NULL;
$pending = in_array($state, ['queued', 'running'], TRUE);
$elapsed = max(0, (int) ($progress['elapsed_sec'] ?? 0));
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-20 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Распознавание PDF', '/prx395_document_extract']],
        'title' => 'Распознавание PDF',
        'pill' => ['Асинхронный сервис · prx395', 'fa-file-pdf'],
        'lead' => 'PDF → текст через очередь и Python-воркер. Проверьте сервис здесь или подключите свою систему по API.',
        'actions' => [['label' => 'API-доступ', 'href' => '/prx150_api_hub', 'icon' => 'fa-key', 'role' => 'secondary']],
    ]); ?>
    <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="min-w-0 space-y-5">
            <?php if ($code): ?>
            <section class="prx-card border-rose-200 dark:border-rose-900" role="alert">
                <h2 class="prx-h3 text-rose-700 dark:text-rose-300"><i class="fas fa-triangle-exclamation mr-2" aria-hidden="true"></i>Не удалось завершить обработку</h2>
                <p class="mt-2 text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars($messages[$code] ?? 'Проверьте состояние воркера и повторите запрос.'); ?></p>
                <p class="prx-hint mt-2">Код: <code><?php echo HTML::chars($code); ?></code></p>
            </section>
            <?php endif; ?>

            <?php if (!$id): ?>
            <section class="prx-card" aria-labelledby="prx395-upload-title">
                <h2 id="prx395-upload-title" class="prx-h3"><i class="fas fa-file-arrow-up mr-2 text-blue-500" aria-hidden="true"></i>Ваш документ</h2>
                <p class="prx-hint mt-2">PDF до <?php echo HTML::chars($labels_limits['max_pdf']); ?>, максимум <?php echo HTML::chars($labels_limits['max_pages']); ?>. Без пароля. Срок доступа к файлу и результату: <?php echo HTML::chars($labels_limits['ttl']); ?>.</p>
                <form method="post" action="/prx395_document_extract" enctype="multipart/form-data" class="mt-4 space-y-3" data-prx395-submit>
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div class="relative rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-5 text-center transition focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500 dark:border-gray-600 dark:bg-gray-800/50" data-prx395-drop>
                        <i class="fas fa-file-pdf text-2xl text-blue-500" aria-hidden="true"></i>
                        <label class="mt-2 block cursor-pointer text-sm font-semibold text-gray-900 dark:text-white" for="pdf-file">Нажмите, чтобы выбрать PDF</label>
                        <p class="prx-hint mt-1">или перетащите документ сюда</p>
                        <p id="prx395-file-info" class="mt-2 break-words text-xs text-gray-500 dark:text-gray-400" aria-live="polite">Файл пока не выбран · до <?php echo HTML::chars($labels_limits['max_pdf']); ?></p>
                        <input class="absolute inset-0 h-full w-full cursor-pointer opacity-0" id="pdf-file" type="file" name="file" accept=".pdf,application/pdf" aria-label="Выберите или перетащите PDF-документ" aria-describedby="prx395-file-info" required
                               data-max-bytes="<?php echo (int) $limits['max_pdf_bytes']; ?>" data-max-label="<?php echo HTML::chars($labels_limits['max_pdf']); ?>">
                    </div>
                    <button class="prx-btn-process" type="submit">
                        <span data-prx-idle><i class="fas fa-play mr-1" aria-hidden="true"></i>Распознать PDF</span>
                        <span class="hidden" data-prx-busy><i class="fas fa-circle-notch prx-spin mr-1" aria-hidden="true"></i>Отправка · <span data-prx-clock>0:00</span></span>
                    </button>
                </form>
            </section>
            <section class="prx-card" aria-labelledby="prx395-sample-title">
                <h2 id="prx395-sample-title" class="prx-h3"><i class="fas fa-flask mr-2 text-blue-500" aria-hidden="true"></i>Проверка без загрузки</h2>
                <p class="prx-hint mt-2">Пробный PDF с текстовым слоем проверяет PHP, очередь и Python. Это не тест качества OCR сканов.</p>
                <form method="post" action="/prx395_document_extract" class="mt-4" data-prx395-submit>
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="hidden" name="sample" value="1">
                    <button class="prx-btn-process prx-btn-sm" type="submit">
                        <span data-prx-idle><i class="fas fa-play mr-1" aria-hidden="true"></i>Распознать пробный PDF</span>
                        <span class="hidden" data-prx-busy><i class="fas fa-circle-notch prx-spin mr-1" aria-hidden="true"></i>Отправка · <span data-prx-clock>0:00</span></span>
                    </button>
                </form>
            </section>
            <?php elseif ($job): ?>
            <section class="prx-card <?php echo $pending ? 'border-blue-200 dark:border-blue-900' : ''; ?>" role="status" aria-live="polite" <?php if ($pending): ?>data-prx395-pending<?php endif; ?>>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="prx-h3">
                        <i class="fas <?php echo $pending ? 'fa-circle-notch prx-spin text-blue-500' : ($state === 'succeeded' ? 'fa-circle-check text-emerald-500' : 'fa-circle-exclamation text-rose-500'); ?> mr-2" aria-hidden="true"></i>
                        <?php echo HTML::chars($labels[$state] ?? $state); ?>
                    </h2>
                    <span class="prx-clock" <?php if ($pending): ?>data-prx-stopwatch="<?php echo $elapsed; ?>"<?php endif; ?>><?php echo sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60); ?></span>
                </div>
                <p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($progress['stage'] ?? ''); ?></p>
                <p class="prx-hint mt-2">Задача #<?php echo (int) $id; ?> · попытка <?php echo (int) $job['attempt']; ?><?php if ($pending): ?> · статус обновляется автоматически<?php endif; ?></p>
                <?php if ($state === 'queued'): ?><p class="prx-hint mt-2">Ожидаем воркер канала <code>document.extract</code>.</p><?php endif; ?>
                <div class="mt-4 flex flex-wrap gap-3">
                    <?php if ($can_monitor): ?><a class="prx-link text-sm" href="/prx120/task/<?php echo (int) $id; ?>">Задача в мониторе</a><?php endif; ?>
                    <?php if ($pending): ?><a class="prx-link text-sm" href="/prx395_document_extract/<?php echo (int) $id; ?>">Обновить статус</a><?php endif; ?>
                    <a class="prx-link text-sm" href="/prx395_document_extract">Другой PDF</a>
                </div>
            </section>
            <?php if ($state === 'succeeded'): ?>
            <section class="prx-card" aria-labelledby="prx395-result-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="prx395-result-title" class="prx-h3">Распознанный текст</h2>
                    <button class="prx-btn-secondary prx-btn-sm" type="button" data-prx-copy="#prx395-text"><i class="fas fa-copy" aria-hidden="true"></i>Копировать</button>
                </div>
                <p class="prx-hint mt-2">Страниц: <?php echo (int) $job['result']['pages_total']; ?> · режим: <?php echo HTML::chars($job['result']['provider']); ?> · формат: <?php echo HTML::chars($job['result']['format']); ?></p>
                <pre id="prx395-text" class="mt-4 max-h-[32rem] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-900 p-4 text-xs leading-5 text-gray-100" dir="auto"><?php echo HTML::chars($job['result']['text']); ?></pre>
            </section>
            <?php endif; ?>
            <?php else: ?>
            <a class="prx-btn-process" href="/prx395_document_extract">Попробовать другой PDF</a>
            <?php endif; ?>
        </div>
        <aside class="min-w-0 space-y-5" aria-label="Подключение API">
<?php echo View::factory('prx395/api_help', ['limits' => $limits, 'rate_limit' => $rate_limit]); ?>
        </aside>
    </div>
</div>
