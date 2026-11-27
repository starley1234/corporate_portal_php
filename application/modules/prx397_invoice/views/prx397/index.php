<?php defined('SYSPATH') or die('No direct script access.');
$labels_limits = $limits['labels'];
$state = $row['status'] ?? '';
$labels = ['queued'=>'В очереди','running'=>'В обработке','done'=>'Готово','review'=>'Нужна проверка','failed'=>'Ошибка','canceled'=>'Отменено'];
$messages = [
    'worker_lost' => 'Воркер остановился посреди обработки (перезапуск или сбой). Загрузите файл ещё раз.',
    'worker_crash_loop' => 'Воркер падает именно на этом файле — повтор не поможет. Проверьте файл и журнал воркера.',
    'budget_exceeded' => 'Бюджет токенов исчерпан — задание не поставлено в очередь. Пополните бюджет или дождитесь сброса периода.',
    'job_timeout' => 'Обработка не уложилась в отведённое время. Попробуйте файл поменьше или повторите позже.',
    'worker_not_configured'=>'На стороне PHP не задан WORKER_API_TOKEN.',
    'file_required'=>'Выберите PDF-файл.', 'file_too_large'=>'Файл больше '.$labels_limits['max_pdf'].'.',
    'pdf_required'=>'Загрузите именно PDF.', 'page_limit'=>'Превышен лимит страниц: '.$labels_limits['max_pages'].'.',
    'text_too_large_for_llm'=>'Текст документа превышает лимит для LLM: '.$labels_limits['max_llm_chars'].'. Уменьшите документ или увеличьте PRX397_MAX_LLM_CHARS.',
    'encrypted_pdf'=>'Снимите пароль с PDF и загрузите заново.', 'invalid_pdf'=>'Не удалось прочитать PDF.',
    'not_found'=>'Задача недоступна или срок хранения истёк.',
    'ocr_unavailable'=>'Движок распознавания недоступен. Проверьте Tesseract/Poppler или OCR-шлюз.',
    'worker_config'=>'У воркера нет адреса сайта или токена. Проверьте PRX120_API_BASE и WORKER_API_TOKEN.',
    'worker_auth'=>'PHP не принял токен воркера. Сверьте WORKER_API_TOKEN.',
    'invalid_result'=>'Воркер вернул некорректный результат.',
    'service_unavailable'=>'Сервис временно недоступен. Повторите позже.',
];
$code = $error ?? $row['error']['code'] ?? NULL;
$pending = in_array($state, ['queued','running'], TRUE);
$fields = is_array($row['fields'] ?? NULL) ? $row['fields'] : [];
$checks = is_array($row['checks'] ?? NULL) ? $row['checks'] : [];
$reasons = is_array($row['reasons'] ?? NULL) ? $row['reasons'] : [];
$check_labels = [
    'required_number'=>'Номер', 'required_date'=>'Дата', 'required_seller_name'=>'Продавец',
    'required_buyer_name'=>'Покупатель', 'required_total'=>'Итого', 'date_parseable'=>'Дата читается',
    'seller_inn'=>'ИНН продавца', 'buyer_inn'=>'ИНН покупателя', 'total_matches_lines'=>'Итого = сумма строк', 'vat_range'=>'НДС в пределах итога',
];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-20 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Поля из счёта', '/prx397_invoice']],
        'title' => 'Поля из счёта',
        'pill' => ['Асинхронный сервис · prx397', 'fa-file-invoice'],
        'lead' => 'PDF счёта или УПД → OCR → структурированные поля и проверки. Текст документа не сохраняется в БД.',
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
            <section class="prx-card" aria-labelledby="prx397-upload-title">
                <h2 id="prx397-upload-title" class="prx-h3"><i class="fas fa-file-arrow-up mr-2 text-blue-500" aria-hidden="true"></i>Ваш документ</h2>
                <p class="prx-hint mt-2">PDF до <?php echo HTML::chars($labels_limits['max_pdf']); ?>, максимум <?php echo HTML::chars($labels_limits['max_pages']); ?>. Срок хранения: <?php echo HTML::chars($labels_limits['ttl']); ?>.</p>
                <form method="post" action="/prx397_invoice" enctype="multipart/form-data" class="mt-4 space-y-3" data-prx397-submit>
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div class="relative rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-5 text-center transition focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500 dark:border-gray-600 dark:bg-gray-800/50" data-prx397-drop>
                        <i class="fas fa-file-pdf text-2xl text-blue-500" aria-hidden="true"></i>
                        <label class="mt-2 block cursor-pointer text-sm font-semibold text-gray-900 dark:text-white" for="pdf-file">Нажмите, чтобы выбрать PDF</label>
                        <p class="prx-hint mt-1">или перетащите документ сюда</p>
                        <p id="prx397-file-info" class="mt-2 break-words text-xs text-gray-500 dark:text-gray-400" aria-live="polite">Файл пока не выбран · до <?php echo HTML::chars($labels_limits['max_pdf']); ?></p>
                        <input class="absolute inset-0 h-full w-full cursor-pointer opacity-0" id="pdf-file" type="file" name="file" accept=".pdf,application/pdf" aria-label="Выберите PDF-документ" aria-describedby="prx397-file-info" required
                               data-max-bytes="<?php echo (int) $limits['max_pdf_bytes']; ?>" data-max-label="<?php echo HTML::chars($labels_limits['max_pdf']); ?>">
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button class="prx-btn-process" type="submit">
                            <span data-prx-idle><i class="fas fa-play mr-1" aria-hidden="true"></i>Распознать счёт</span>
                            <span class="hidden" data-prx-busy><i class="fas fa-circle-notch prx-spin mr-1" aria-hidden="true"></i>Отправка · <span data-prx-clock>0:00</span></span>
                        </button>
                        <a class="prx-btn-outline" href="/prx397_invoice?op=example"><i class="fas fa-flask mr-1" aria-hidden="true"></i>Скачать пример PDF</a>
                    </div>
                </form>
            </section>
            <?php elseif ($row): ?>
            <section class="prx-card <?php echo $pending ? 'border-blue-200 dark:border-blue-900' : ($state==='review' ? 'border-amber-200 dark:border-amber-900' : ''); ?>" role="status" aria-live="polite" <?php if ($pending): ?>data-prx397-pending data-prx397-id="<?php echo (int)$id; ?>"<?php endif; ?>>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="prx-h3">
                        <i class="fas <?php echo $pending ? 'fa-circle-notch prx-spin text-blue-500' : ($state==='done' ? 'fa-circle-check text-emerald-500' : ($state==='review' ? 'fa-triangle-exclamation text-amber-500' : 'fa-circle-exclamation text-rose-500')); ?> mr-2" aria-hidden="true"></i>
                        <?php echo HTML::chars($labels[$state] ?? $state); ?>
                    </h2>
                    <?php if ($state==='review'): ?><span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Нужна проверка</span><?php endif; ?>
                </div>
                <p class="prx-hint mt-2">Задача #<?php echo (int)$id; ?> · попытка <?php echo (int)($row['attempt'] ?? 0); ?><?php if ($pending): ?> · статус обновляется автоматически<?php endif; ?></p>
<?php if ($pending AND ! empty($stale)): ?>
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100" role="alert">
                    <i class="fas fa-triangle-exclamation mr-1.5" aria-hidden="true"></i><?php echo HTML::chars((string) $stale); ?>
                    Проверьте воркеры (workers/.env: WORKER_API_URL и WORKER_API_TOKEN) или <a class="font-semibold underline" href="/prx120_jobs">монитор очереди</a>.
                </div>
<?php endif; ?>
                <?php if ($state==='queued'): ?><p class="prx-hint mt-2">Ожидаем воркер канала <code>invoice.extract</code>.</p><?php endif; ?>
                <?php if ($reasons): ?>
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                    <div class="font-semibold mb-1">Причины проверки:</div>
                    <ul class="list-disc pl-5"><?php foreach ($reasons as $r): ?><li><?php echo HTML::chars($check_labels[$r] ?? $r); ?> (<code><?php echo HTML::chars($r); ?></code>)</li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <div class="mt-4 flex flex-wrap gap-3">
                    <?php if ($can_monitor): ?><a class="prx-link text-sm" href="/prx120/task/<?php echo (int)$id; ?>">Задача в мониторе</a><?php endif; ?>
                    <?php if ($pending): ?><a class="prx-link text-sm" href="/prx397_invoice/<?php echo (int)$id; ?>">Обновить статус</a><?php endif; ?>
                    <a class="prx-link text-sm" href="/prx397_invoice">Другой PDF</a>
                </div>
            </section>

            <?php if (in_array($state, ['done','review'], TRUE)): ?>
            <section class="prx-card" aria-labelledby="prx397-fields-title">
                <h2 id="prx397-fields-title" class="prx-h3">Извлечённые поля</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php
                        $rows = [
                            'Номер' => $fields['number'] ?? '',
                            'Дата' => $fields['date'] ?? '',
                            'Продавец' => ($fields['seller']['name'] ?? '').' / ИНН '.($fields['seller']['inn'] ?? ''),
                            'Покупатель' => ($fields['buyer']['name'] ?? '').' / ИНН '.($fields['buyer']['inn'] ?? ''),
                            'Итого' => isset($fields['total']) ? (string)$fields['total'] : '',
                            'НДС' => isset($fields['vat']) ? (string)$fields['vat'] : '',
                            'Валюта' => $fields['currency'] ?? '',
                        ];
                        foreach ($rows as $k=>$v): ?>
                            <tr><th class="py-2 pr-4 text-left font-medium text-gray-600 dark:text-gray-300"><?php echo HTML::chars($k); ?></th><td class="py-2 text-gray-900 dark:text-gray-100"><?php echo HTML::chars((string)$v); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php $lines = is_array($fields['lines'] ?? NULL) ? $fields['lines'] : []; if ($lines): ?>
                <h3 class="mt-4 text-sm font-semibold text-gray-800 dark:text-gray-100">Строки</h3>
                <div class="mt-2 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th class="py-1 pr-3">Наименование</th><th class="py-1 pr-3">Кол-во</th><th class="py-1 pr-3">Цена</th><th class="py-1 pr-3">Сумма</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($lines as $ln): if(!is_array($ln)) continue; ?>
                            <tr>
                                <td class="py-1 pr-3"><?php echo HTML::chars((string)($ln['name'] ?? '')); ?></td>
                                <td class="py-1 pr-3"><?php echo HTML::chars(isset($ln['qty']) ? (string)$ln['qty'] : ''); ?></td>
                                <td class="py-1 pr-3"><?php echo HTML::chars(isset($ln['price']) ? (string)$ln['price'] : ''); ?></td>
                                <td class="py-1 pr-3"><?php echo HTML::chars(isset($ln['sum']) ? (string)$ln['sum'] : ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </section>

            <section class="prx-card" aria-labelledby="prx397-checks-title">
                <h2 id="prx397-checks-title" class="prx-h3">Проверки</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <?php foreach ($checks as $c): if(!is_array($c)) continue; $ok=!empty($c['ok']); $cc=(string)($c['code']??''); ?>
                    <li class="flex items-start gap-2">
                        <i class="fas <?php echo $ok ? 'fa-circle-check text-emerald-500' : 'fa-circle-xmark text-rose-500'; ?> mt-0.5" aria-hidden="true"></i>
                        <span><span class="<?php echo $ok ? 'text-gray-800 dark:text-gray-100' : 'text-rose-700 dark:text-rose-300 font-medium'; ?>"><?php echo HTML::chars($check_labels[$cc] ?? $cc); ?></span> <code class="text-xs text-gray-400"><?php echo HTML::chars($cc); ?></code></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
            <?php else: ?>
            <a class="prx-btn-process" href="/prx397_invoice">Попробовать другой PDF</a>
            <?php endif; ?>
        </div>

        <aside class="min-w-0 space-y-5" aria-label="Лимиты и API">
            <section class="prx-card">
                <h2 class="prx-h3">Лимиты</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div><dt class="text-gray-500 dark:text-gray-400">Макс. PDF</dt><dd><?php echo HTML::chars($labels_limits['max_pdf']); ?> <code class="text-xs">PRX397_MAX_PDF_BYTES</code></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Макс. страниц</dt><dd><?php echo HTML::chars($labels_limits['max_pages']); ?> <code class="text-xs">PRX397_MAX_PAGES</code></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Макс. текст для LLM</dt><dd><?php echo HTML::chars($labels_limits['max_llm_chars']); ?> <code class="text-xs">PRX397_MAX_LLM_CHARS</code></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Срок хранения</dt><dd><?php echo HTML::chars($labels_limits['ttl']); ?> <code class="text-xs">PRX397_TTL_HOURS</code></dd></div>
                </dl>
                <p class="prx-hint mt-3">Лимиты задаются в <code>.env</code> и читаются при старте.</p>
            </section>
            <section class="prx-card">
                <h2 class="prx-h3">API</h2>
                <p class="prx-hint mt-2">POST <code>/api/v1/invoice-extractions</code> (Bearer + Idempotency-Key + file) → 202. GET <code>/api/v1/invoice-extractions/&lt;id&gt;</code> → статус/поля/проверки.</p>
            </section>
        </aside>
    </div>
</div>
