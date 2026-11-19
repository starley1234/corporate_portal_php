<?php defined('SYSPATH') or die('No direct script access.');
$state = $job['status'] ?? '';
$labels = ['queued' => 'В очереди', 'running' => 'В обработке', 'succeeded' => 'Текст готов', 'failed' => 'Ошибка', 'canceled' => 'Отменено'];
$messages = [
    'worker_not_configured' => 'На стороне PHP не задан WORKER_API_TOKEN.',
    'file_required' => 'Выберите PDF-файл.', 'file_too_large' => 'Файл больше 10 МБ.',
    'pdf_required' => 'Загрузите именно PDF.', 'page_limit' => 'В документе больше 20 страниц.',
    'encrypted_pdf' => 'Снимите пароль с PDF и загрузите заново.', 'invalid_pdf' => 'Не удалось прочитать PDF.',
    'not_found' => 'Задача недоступна или срок хранения истёк.', 'no_text' => 'На страницах не найдено текста.',
    'ocr_unavailable' => 'Движок распознавания недоступен. Проверьте Tesseract и Poppler или адрес OCR-шлюза.',
    'ocr_page_failed' => 'Не удалось распознать страницу. Проверьте PDF и наличие языков rus и eng у Tesseract.',
    'worker_config' => 'У воркера нет адреса сайта или токена. Проверьте PRX120_API_BASE и WORKER_API_TOKEN.',
    'worker_auth' => 'PHP не принял токен воркера. Сверьте WORKER_API_TOKEN на PHP и Python.',
];
$code = $error ?? $job['error']['code'] ?? NULL;
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Распознавание PDF']],
        'title' => 'Распознавание PDF',
        'pill' => ['Проверка сервиса', 'fa-file-pdf'],
        'lead' => 'Загрузите PDF или запустите пробный файл. Текст и статус обработки появятся здесь.',
    ]); ?>
    <?php if ($code): ?>
        <div class="prx-card mt-4" role="alert"><strong><?php echo HTML::chars($messages[$code] ?? 'Обработка не завершилась.'); ?></strong> <code><?php echo HTML::chars($code); ?></code></div>
    <?php endif; ?>
    <?php if (!$id): ?>
        <section class="prx-panel mt-4">
            <div class="prx-panel-head"><h2 class="prx-h3">Проверить за один клик</h2></div>
            <form method="post" action="/document-extract" class="p-5">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <button class="prx-btn-process" type="submit" name="sample" value="1"><i class="fas fa-play"></i> Распознать пробный PDF</button>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Одностраничный PDF с готовым текстовым слоем. Проверяет PHP, очередь и Python, но не качество OCR сканов.</p>
            </form>
        </section>
        <section class="prx-panel mt-4">
            <div class="prx-panel-head"><h2 class="prx-h3">Загрузить свой документ</h2></div>
            <form method="post" action="/document-extract" enctype="multipart/form-data" class="p-5">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <label class="block text-sm font-semibold" for="pdf-file">PDF до 10 МБ и 20 страниц</label>
                <input class="prx-input mt-2" id="pdf-file" type="file" name="file" accept=".pdf,application/pdf" required>
                <button class="prx-btn-secondary mt-3" type="submit">Распознать мой PDF</button>
            </form>
        </section>
    <?php else: ?>
        <?php if ($job): ?>
        <section class="prx-panel mt-4" <?php if (in_array($state, ['queued', 'running'], TRUE)): ?>data-prx395-pending<?php endif; ?>>
            <div class="prx-panel-head"><h2 class="prx-h3"><?php echo HTML::chars($labels[$state]); ?> · #<?php echo $id; ?></h2></div>
            <div class="p-5">
                <?php if ($state === 'queued'): ?>
                    <p>Задача ждёт воркер. Если статус долго не меняется, проверьте канал <code>document.extract</code> в настройках воркера.</p>
                <?php elseif ($state === 'running'): ?>
                    <p>Python-воркер обрабатывает документ. Страница обновляется автоматически.</p>
                <?php elseif ($state === 'succeeded'): ?>
                    <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">Страниц: <?php echo (int) $job['result']['pages_total']; ?> · Режим: <?php echo HTML::chars($job['result']['provider']); ?></p>
                    <pre class="whitespace-pre-wrap break-words" dir="auto"><?php echo HTML::chars($job['result']['text']); ?></pre>
                <?php endif; ?>
                <?php if ($can_monitor): ?><a class="prx-link mt-3 inline-block" href="/prx120/task/<?php echo $id; ?>">Открыть задачу в мониторе</a><?php endif; ?>
                <?php if (in_array($state, ['queued', 'running'], TRUE)): ?><a class="prx-link mt-3 inline-block" href="/document-extract/<?php echo $id; ?>">Обновить статус</a><?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
        <a class="prx-btn-secondary mt-4" href="/document-extract">Распознать другой PDF</a>
    <?php endif; ?>
</div>
