<?php defined('SYSPATH') or die('No direct script access.');
$labels_limits = $limits['labels'];
$state = $batch['status'] ?? '';
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
    'result_not_ready' => 'Результат ещё не готов.',
    'batch_incomplete' => 'Обработаны не все части.',
    'budget_exceeded' => 'Бюджет токенов исчерпан — задание не поставлено в очередь. Пополните бюджет или дождитесь сброса периода.',
    'file_unavailable' => 'Исходный PDF недоступен: файл повреждён или удалён.',
    'lease_lost' => 'Задание перезапущено другой попыткой. Обновите страницу.',
];
$code = $error ?? $batch['error']['code'] ?? NULL;
$pending = in_array($state, ['queued', 'running'], TRUE);
$elapsed = max(0, (int) ($progress['elapsed_sec'] ?? 0));
$parts_done = (int) ($batch['parts_done'] ?? 0);
$parts_total = (int) ($batch['parts_total'] ?? 0);
$next_part = $batch['next_part'] ?? NULL;
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-20 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Большие PDF по частям', '/prx396_pdf_chunks']],
        'title' => 'Большие PDF по частям',
        'pill' => ['Асинхронный сервис · prx396', 'fa-file-pdf'],
        'lead' => 'Большой PDF → текст по частям через очередь и Python-воркер. Обработка продолжается с места остановки после таймаута или рестарта воркера.',
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
            <section class="prx-card" aria-labelledby="prx396-upload-title">
                <h2 id="prx396-upload-title" class="prx-h3"><i class="fas fa-file-arrow-up mr-2 text-blue-500" aria-hidden="true"></i>Ваш документ</h2>
                <p class="prx-hint mt-2">PDF до <?php echo HTML::chars($labels_limits['max_pdf']); ?>, максимум <?php echo HTML::chars($labels_limits['max_pages']); ?>. Без пароля. Срок доступа к файлу и результату: <?php echo HTML::chars($labels_limits['ttl']); ?>.</p>
                <form method="post" action="/prx396_pdf_chunks" enctype="multipart/form-data" class="mt-4 space-y-3" data-prx396-submit>
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div class="relative rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-5 text-center transition focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500 dark:border-gray-600 dark:bg-gray-800/50" data-prx396-drop>
                        <i class="fas fa-file-pdf text-2xl text-blue-500" aria-hidden="true"></i>
                        <label class="mt-2 block cursor-pointer text-sm font-semibold text-gray-900 dark:text-white" for="pdf-file">Нажмите, чтобы выбрать PDF</label>
                        <p class="prx-hint mt-1">или перетащите документ сюда</p>
                        <p id="prx396-file-info" class="mt-2 break-words text-xs text-gray-500 dark:text-gray-400" aria-live="polite">Файл пока не выбран · до <?php echo HTML::chars($labels_limits['max_pdf']); ?></p>
                        <input class="absolute inset-0 h-full w-full cursor-pointer opacity-0" id="pdf-file" type="file" name="file" accept=".pdf,application/pdf" aria-label="Выберите или перетащите PDF-документ" aria-describedby="prx396-file-info" required
                               data-max-bytes="<?php echo (int) $limits['max_pdf_bytes']; ?>" data-max-label="<?php echo HTML::chars($labels_limits['max_pdf']); ?>">
                    </div>
                    <button class="prx-btn-process" type="submit">
                        <span data-prx-idle><i class="fas fa-play mr-1" aria-hidden="true"></i>Распознать PDF</span>
                        <span class="hidden" data-prx-busy><i class="fas fa-circle-notch prx-spin mr-1" aria-hidden="true"></i>Отправка · <span data-prx-clock>0:00</span></span>
                    </button>
                </form>
            </section>
            <?php elseif ($batch): ?>
            <section class="prx-card <?php echo $pending ? 'border-blue-200 dark:border-blue-900' : ''; ?>" role="status" aria-live="polite" <?php if ($pending): ?>data-prx396-pending<?php endif; ?>>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="prx-h3">
                        <i class="fas <?php echo $pending ? 'fa-circle-notch prx-spin text-blue-500' : ($state === 'succeeded' ? 'fa-circle-check text-emerald-500' : 'fa-circle-exclamation text-rose-500'); ?> mr-2" aria-hidden="true"></i>
                        <?php echo HTML::chars($labels[$state] ?? $state); ?>
                    </h2>
                    <span class="prx-clock" <?php if ($pending): ?>data-prx-stopwatch="<?php echo $elapsed; ?>"<?php endif; ?>><?php echo sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60); ?></span>
                </div>
                <p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($progress['stage'] ?? ''); ?></p>
                <p class="prx-hint mt-2">Партия #<?php echo (int) $id; ?> · попытка <?php echo (int) $batch['attempt']; ?><?php if ($pending): ?> · статус обновляется автоматически<?php endif; ?></p>
                <?php if ($parts_total > 0): ?>
                <div class="mt-3">
                    <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>Части: <?php echo $parts_done; ?>/<?php echo $parts_total; ?></span>
                        <?php if ($next_part !== NULL): ?><span>следующая: <?php echo (int) $next_part; ?></span><?php endif; ?>
                    </div>
                    <div class="mt-1 h-2 rounded bg-gray-200 dark:bg-gray-700">
                        <div class="h-2 rounded bg-blue-500" style="width: <?php echo $parts_total > 0 ? (int) round($parts_done * 100 / $parts_total) : 0; ?>%"></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($state === 'queued'): ?><p class="prx-hint mt-2">Ожидаем воркер канала <code>pdf.chunks</code>.</p><?php endif; ?>
                <div class="mt-4 flex flex-wrap gap-3">
                    <?php if ($can_monitor && ! empty($batch['job_id'])): ?><a class="prx-link text-sm" href="/prx120/task/<?php echo (int) $batch['job_id']; ?>">Задача в мониторе</a><?php endif; ?>
                    <?php if ($pending): ?><a class="prx-link text-sm" href="/prx396_pdf_chunks/<?php echo (int) $id; ?>">Обновить статус</a><?php endif; ?>
                    <a class="prx-link text-sm" href="/prx396_pdf_chunks">Другой PDF</a>
                </div>
            </section>
            <?php if ($state === 'succeeded'): ?>
            <section class="prx-card" aria-labelledby="prx396-result-title">
                <h2 id="prx396-result-title" class="prx-h3">Результат</h2>
                <p class="prx-hint mt-2">Страниц: <?php echo (int) ($batch['pages_total'] ?? 0); ?> · частей: <?php echo $parts_total; ?> · текст собран по порядку.</p>
                <div class="mt-4">
                    <a class="prx-btn-process prx-btn-sm" href="/prx396_pdf_chunks/<?php echo (int) $id; ?>/download"><i class="fas fa-download mr-1" aria-hidden="true"></i>Скачать текст</a>
                </div>
            </section>
            <?php endif; ?>
            <?php else: ?>
            <a class="prx-btn-process" href="/prx396_pdf_chunks">Попробовать другой PDF</a>
            <?php endif; ?>
        </div>
        <aside class="min-w-0 space-y-5" aria-label="Подключение API">
<?php echo View::factory('prx396/api_help', ['limits' => $limits, 'rate_limit' => $rate_limit]); ?>
        </aside>
    </div>
</div>
