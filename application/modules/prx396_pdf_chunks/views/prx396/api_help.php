<?php defined('SYSPATH') or die('No direct script access.');
$labels = $limits['labels'];
?>
<section class="prx-card" aria-labelledby="prx396-api-title">
    <div class="flex items-center justify-between gap-3">
        <h2 id="prx396-api-title" class="prx-h3"><i class="fas fa-plug mr-2 text-blue-500" aria-hidden="true"></i>Подключение API</h2>
        <span class="prx-badge prx-badge-info">v1 · async</span>
    </div>
    <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-200">
        <li>В <a class="prx-link" href="/prx150_api_hub">API-доступе</a> создайте ключ с правом <code>prx396.extract</code>. Сохраните ключ сразу: повторно он не показывается.</li>
        <li>Отправьте PDF с <code>Authorization: Bearer</code> и уникальным <code>Idempotency-Key</code>. Ответ <strong>202</strong> содержит <code>batch_id</code>, <code>status_url</code> и <code>result_url</code>.</li>
        <li>Опрашивайте <code>status_url</code> тем же ключом. При <code>succeeded</code> скачайте текст по <code>result_url</code> (поток <code>text/plain</code>).</li>
    </ol>
    <p class="prx-hint mt-3">UI и API — разные владельцы. Задания этой страницы нельзя читать машинным ключом. Чужие и истёкшие задания возвращают 404.</p>
</section>
<section class="prx-card" aria-labelledby="prx396-api-examples">
    <h2 id="prx396-api-examples" class="prx-h3">Запросы подключения</h2>
    <p class="prx-hint mt-2">Задайте <code>PRX_URL</code> — HTTPS-адрес портала, <code>PRX_API_KEY</code> — секретный ключ. Не помещайте ключ в URL или логи.</p>
    <div class="mt-4 flex items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">1. Загрузить PDF</h3>
        <button type="button" class="prx-btn-icon" data-prx-copy="#prx396-api-submit" aria-label="Копировать запрос загрузки"><i class="fas fa-copy" aria-hidden="true"></i></button>
    </div>
    <pre id="prx396-api-submit" class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs leading-5 text-gray-100"><code>curl --fail-with-body "$PRX_URL/api/v1/pdf-chunks" \
  -H "Authorization: Bearer $PRX_API_KEY" \
  -H 'Idempotency-Key: erp-order-42-v1' \
  -F 'file=@document.pdf;type=application/pdf'</code></pre>
    <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200"><code>{"batch_id":42,"status_url":"/api/v1/pdf-chunks/42","result_url":"/api/v1/pdf-chunks/42/result"}</code></pre>
    <div class="mt-4 flex items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">2. Получить статус</h3>
        <button type="button" class="prx-btn-icon" data-prx-copy="#prx396-api-status" aria-label="Копировать запрос статуса"><i class="fas fa-copy" aria-hidden="true"></i></button>
    </div>
    <pre id="prx396-api-status" class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs leading-5 text-gray-100"><code>curl --fail-with-body "$PRX_URL/api/v1/pdf-chunks/42" \
  -H "Authorization: Bearer $PRX_API_KEY"</code></pre>
    <div class="mt-4 flex items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">3. Скачать результат</h3>
        <button type="button" class="prx-btn-icon" data-prx-copy="#prx396-api-result" aria-label="Копировать запрос результата"><i class="fas fa-copy" aria-hidden="true"></i></button>
    </div>
    <pre id="prx396-api-result" class="mt-2 overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs leading-5 text-gray-100"><code>curl --fail-with-body -o result.txt "$PRX_URL/api/v1/pdf-chunks/42/result" \
  -H "Authorization: Bearer $PRX_API_KEY"</code></pre>
    <p class="prx-hint mt-2">42 — пример. Используйте <code>status_url</code> и <code>result_url</code> из ответа своего запроса.</p>
    <p class="prx-hint mt-3"><code>queued → running → succeeded</code>; при отказе — <code>failed</code> и <code>error.code</code>. Возможна отмена <code>canceled</code>. HTTP 200 на статусе не означает готовность OCR: проверяйте поле <code>status</code>. Результат отдаётся потоком <code>text/plain</code> по частям, в JSON его нет.</p>
</section>
<section class="prx-card" aria-labelledby="prx396-limits">
    <h2 id="prx396-limits" class="prx-h3">Лимиты и повторные запросы</h2>
    <dl class="mt-3 space-y-2 text-sm">
        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">PDF</dt><dd class="text-right font-semibold">до <?php echo HTML::chars($labels['max_pdf']); ?> · максимум <?php echo HTML::chars($labels['max_pages']); ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Часть</dt><dd class="text-right font-semibold"><?php echo HTML::chars($labels['chunk_pages']); ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Ответ</dt><dd class="text-right font-semibold">текст до <?php echo HTML::chars($labels['max_text']); ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Срок хранения</dt><dd class="text-right">доступен <?php echo HTML::chars($labels['ttl']); ?>, затем удаляется при очистке</dd></div>
        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Скорость</dt><dd class="text-right">до <?php echo (int) $rate_limit; ?> запросов в минуту по умолчанию · настраивается на ключе</dd></div>
    </dl>
    <details class="mt-3">
        <summary class="prx-hint cursor-pointer font-semibold">Где менять лимиты и что учитывать</summary>
        <p class="prx-hint mt-2">Задайте <code>PRX396_*</code> в корневом <code>.env</code>. Значения по умолчанию и диапазоны PHP описаны в <code class="break-all">application/modules/prx396_pdf_chunks/config/prx396_pdf_chunks.php</code>. После изменений перезапустите PHP и воркер. <code>workers/.env</code> переопределяет настройки только для воркера.</p>
        <ul class="prx-hint mt-2 list-disc space-y-1 pl-5">
            <li><code>PRX396_MAX_PDF_BYTES</code> — больше PDF: дольше обработка, проверьте лимиты загрузки PHP/прокси.</li>
            <li><code>PRX396_MAX_PAGES</code> — больше страниц: дольше обработка.</li>
            <li><code>PRX396_CHUNK_PAGES</code> — размер части: меньше — чаще прогресс и возобновление, больше — меньше накладных расходов.</li>
            <li><code>PRX396_MAX_TEXT_BYTES</code> — больше итогового текста: больше данных в ответе.</li>
            <li><code>PRX396_TTL_HOURS</code> — дольше срок: дольше хранятся документы.</li>
            <li><code>PRX396_TASK_TIMEOUT_SEC</code> и <code>PRX396_MAX_ATTEMPTS</code> — больше попыток/таймаут: дольше занят воркер при сбоях.</li>
            <li><code>PRX396_PDF_MEMORY_MB</code> — лимит памяти для разбора PDF.</li>
        </ul>
    </details>
    <p class="prx-hint mt-3"><code>Idempotency-Key</code>: 1–128 символов <code>A–Z a–z 0–9 . _ : -</code>. Тот же ключ и файл возвращают ту же партию. Другой файл — 409; истёкший ключ — 409 <code>idempotency_key_expired</code>. Для нового документа используйте новый ключ.</p>
    <p class="prx-hint mt-3">401/403 — ключ или право доступа; 404 — задание недоступно; 413/415 — размер или формат; 429 — лимит; 503 — инфраструктура. Исходник и текст приватны, не публикуйте их в логах.</p>
    <p class="prx-hint mt-3">Для обработки нужен воркер <code>pdf.chunks</code>. Этот API не использует LLM.</p>
</section>
