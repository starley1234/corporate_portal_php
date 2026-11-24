<?php defined('SYSPATH') or die('No direct script access.');
$labels_limits = $limits['labels'];
$state = $row['status'] ?? '';
$labels = ['queued'=>'В очереди','running'=>'В обработке','done'=>'Маршрут определён','review'=>'Ручная проверка','failed'=>'Ошибка','canceled'=>'Отменено'];
$messages = [
    'worker_not_configured'=>'На стороне PHP не задан WORKER_API_TOKEN.',
    'file_required'=>'Выберите PDF-файл.', 'file_too_large'=>'Файл больше '.$labels_limits['max_pdf'].'.',
    'pdf_required'=>'Загрузите именно PDF.', 'page_limit'=>'Превышен лимит страниц: '.$labels_limits['max_pages'].'.',
    'graph_unavailable'=>'Чекпойнт графа недоступен. Проверьте PRX398_CHECKPOINT и доступность PostgreSQL/очереди.',
    'encrypted_pdf'=>'Снимите пароль с PDF и загрузите заново.', 'invalid_pdf'=>'Не удалось прочитать PDF.',
    'not_found'=>'Задача недоступна или срок хранения истёк.', 'service_unavailable'=>'Сервис временно недоступен.',
    'ocr_unavailable'=>'Движок распознавания недоступен.',
    'worker_config'=>'У воркера нет адреса сайта или токена. Проверьте PRX120_API_BASE и WORKER_API_TOKEN.',
    'worker_auth'=>'PHP не принял токен воркера. Сверьте WORKER_API_TOKEN.',
];
$code = $error ?? $row['error']['code'] ?? NULL;
$pending = in_array($state, ['queued','running'], TRUE);
$st = is_array($row['state'] ?? NULL) ? $row['state'] : [];
$fields = is_array($st['fields'] ?? NULL) ? $st['fields'] : [];
$reasons = is_array($row['reasons'] ?? NULL) ? $row['reasons'] : [];
$route_labels = ['erp'=>'ERP', 'legal'=>'Legal', 'inbox'=>'Inbox', ''=>'—'];
$type_labels = ['invoice'=>'Счёт', 'contract'=>'Договор', 'letter'=>'Письмо', 'other'=>'Другое'];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-20 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная','/'], ['Маршрутизация документов','/prx398_doc_router']],
        'title' => 'Маршрутизация документов',
        'pill' => ['Асинхронный сервис · prx398', 'fa-route'],
        'lead' => 'PDF → классификация → извлечение → валидация → маршрут или ручная проверка. Состояние графа чекпойнтится.',
        'actions' => [['label'=>'API-доступ','href'=>'/prx150_api_hub','icon'=>'fa-key','role'=>'secondary']],
    ]); ?>
    <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="min-w-0 space-y-5">
            <?php if ($code): ?>
            <section class="prx-card border-rose-200 dark:border-rose-900" role="alert">
                <h2 class="prx-h3 text-rose-700 dark:text-rose-300"><i class="fas fa-triangle-exclamation mr-2" aria-hidden="true"></i>Ошибка обработки</h2>
                <p class="mt-2 text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars($messages[$code] ?? 'Проверьте состояние воркера и повторите запрос.'); ?></p>
                <p class="prx-hint mt-2">Код: <code><?php echo HTML::chars($code); ?></code></p>
            </section>
            <?php endif; ?>

            <?php if (!$id): ?>
            <section class="prx-card">
                <h2 class="prx-h3"><i class="fas fa-file-arrow-up mr-2 text-blue-500" aria-hidden="true"></i>Ваш документ</h2>
                <p class="prx-hint mt-2">PDF до <?php echo HTML::chars($labels_limits['max_pdf']); ?>, до <?php echo HTML::chars($labels_limits['max_pages']); ?>. Срок хранения: <?php echo HTML::chars($labels_limits['ttl']); ?>.</p>
                <form method="post" action="/prx398_doc_router" enctype="multipart/form-data" class="mt-4 space-y-3" data-prx398-submit>
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div class="relative rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-5 text-center transition focus-within:border-blue-500 dark:border-gray-600 dark:bg-gray-800/50">
                        <i class="fas fa-file-pdf text-2xl text-blue-500" aria-hidden="true"></i>
                        <label class="mt-2 block cursor-pointer text-sm font-semibold text-gray-900 dark:text-white" for="pdf-file">Нажмите, чтобы выбрать PDF</label>
                        <p id="prx398-file-info" class="mt-2 break-words text-xs text-gray-500 dark:text-gray-400" aria-live="polite">Файл пока не выбран</p>
                        <input class="absolute inset-0 h-full w-full cursor-pointer opacity-0" id="pdf-file" type="file" name="file" accept=".pdf,application/pdf" aria-describedby="prx398-file-info" required
                               data-max-bytes="<?php echo (int)$limits['max_pdf_bytes']; ?>" data-max-label="<?php echo HTML::chars($labels_limits['max_pdf']); ?>">
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button class="prx-btn-process" type="submit"><span data-prx-idle><i class="fas fa-play mr-1" aria-hidden="true"></i>Маршрутизировать</span><span class="hidden" data-prx-busy><i class="fas fa-circle-notch prx-spin mr-1" aria-hidden="true"></i>Отправка</span></button>
                        <a class="prx-btn-outline" href="/prx398_doc_router?op=example"><i class="fas fa-flask mr-1" aria-hidden="true"></i>Скачать пример PDF</a>
                    </div>
                </form>
            </section>
            <?php elseif ($row): ?>
            <section class="prx-card <?php echo $pending ? 'border-blue-200 dark:border-blue-900' : ($state==='review' ? 'border-amber-200 dark:border-amber-900' : ''); ?>" <?php if ($pending): ?>data-prx398-pending data-prx398-id="<?php echo (int)$id; ?>"<?php endif; ?>>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="prx-h3">
                        <i class="fas <?php echo $pending ? 'fa-circle-notch prx-spin text-blue-500' : ($state==='done' ? 'fa-circle-check text-emerald-500' : ($state==='review' ? 'fa-triangle-exclamation text-amber-500' : 'fa-circle-exclamation text-rose-500')); ?> mr-2" aria-hidden="true"></i>
                        <?php echo HTML::chars($labels[$state] ?? $state); ?>
                    </h2>
                    <?php if ($state==='review'): ?><span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Ручная проверка</span><?php endif; ?>
                </div>
                <p class="prx-hint mt-2">Задача #<?php echo (int)$id; ?> · попыток графа: <?php echo (int)($row['attempts'] ?? 0); ?><?php if ($pending): ?> · статус обновляется автоматически<?php endif; ?></p>
                <?php if ($reasons): ?>
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                    <div class="font-semibold mb-1">Причины ручной проверки:</div>
                    <ul class="list-disc pl-5"><?php foreach ($reasons as $r): ?><li><code><?php echo HTML::chars((string)$r); ?></code></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <div class="mt-4 flex flex-wrap gap-3">
                    <?php if ($can_monitor): ?><a class="prx-link text-sm" href="/prx120/task/<?php echo (int)$id; ?>">Задача в мониторе</a><?php endif; ?>
                    <a class="prx-link text-sm" href="/prx398_doc_router">Другой PDF</a>
                </div>
            </section>

            <?php if (in_array($state, ['done','review','failed'], TRUE) && $row): ?>
            <section class="prx-card">
                <h2 class="prx-h3">Результат маршрутизации</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm"><tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr><th class="py-2 pr-4 text-left font-medium text-gray-600 dark:text-gray-300">Тип</th><td class="py-2"><?php echo HTML::chars($type_labels[$row['doc_type'] ?? ''] ?? ($row['doc_type'] ?? '—')); ?> <code class="text-xs text-gray-400"><?php echo HTML::chars((string)($row['doc_type'] ?? '')); ?></code></td></tr>
                        <tr><th class="py-2 pr-4 text-left font-medium text-gray-600 dark:text-gray-300">Маршрут</th><td class="py-2"><?php echo HTML::chars($route_labels[$row['route'] ?? ''] ?? ($row['route'] ?? '—')); ?></td></tr>
                        <tr><th class="py-2 pr-4 text-left font-medium text-gray-600 dark:text-gray-300">Уверенность</th><td class="py-2"><?php echo $row['confidence'] !== NULL ? HTML::chars(number_format((float)$row['confidence'], 3, '.', '')) : '—'; ?></td></tr>
                    </tbody></table>
                </div>
                <?php if ($fields): ?>
                <h3 class="mt-4 text-sm font-semibold text-gray-800 dark:text-gray-100">Извлечённый конверт</h3>
                <div class="mt-2 overflow-x-auto">
                    <table class="min-w-full text-sm"><tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php foreach (['title'=>'Название','counterparty'=>'Контрагент','date'=>'Дата','amount'=>'Сумма','summary'=>'Краткое содержание'] as $k=>$label): ?>
                        <tr><th class="py-2 pr-4 text-left font-medium text-gray-600 dark:text-gray-300"><?php echo HTML::chars($label); ?></th><td class="py-2"><?php echo HTML::chars(isset($fields[$k]) ? (string)$fields[$k] : ''); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
            <?php else: ?>
            <a class="prx-btn-process" href="/prx398_doc_router">Попробовать другой PDF</a>
            <?php endif; ?>
        </div>

        <aside class="min-w-0 space-y-5">
            <section class="prx-card">
                <h2 class="prx-h3">Маршруты</h2>
                <ul class="mt-3 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                    <li><code>invoice</code> → <strong>erp</strong></li>
                    <li><code>contract</code> → <strong>legal</strong></li>
                    <li><code>letter</code> → <strong>inbox</strong></li>
                    <li><code>other</code> → <strong>inbox</strong></li>
                </ul>
                <p class="prx-hint mt-3">Автомаршрут: уверенность ≥ <?php echo HTML::chars($labels_limits['confidence_min']); ?> и проверки пройдены. Иначе повтор извлечения (до <?php echo HTML::chars($labels_limits['max_retries']); ?>) или ручная проверка.</p>
            </section>
            <section class="prx-card">
                <h2 class="prx-h3">Лимиты</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div><dt class="text-gray-500 dark:text-gray-400">Макс. PDF</dt><dd><?php echo HTML::chars($labels_limits['max_pdf']); ?> <code class="text-xs">PRX398_MAX_PDF_BYTES</code></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Макс. страниц</dt><dd><?php echo HTML::chars($labels_limits['max_pages']); ?> <code class="text-xs">PRX398_MAX_PAGES</code></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Срок хранения</dt><dd><?php echo HTML::chars($labels_limits['ttl']); ?> <code class="text-xs">PRX398_TTL_HOURS</code></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Мин. уверенность</dt><dd><?php echo HTML::chars($labels_limits['confidence_min']); ?> <code class="text-xs">PRX398_CONFIDENCE_MIN</code></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Макс. повторы</dt><dd><?php echo HTML::chars($labels_limits['max_retries']); ?> <code class="text-xs">PRX398_MAX_RETRIES</code></dd></div>
                </dl>
                <p class="prx-hint mt-3">Лимиты задаются в <code>.env</code>.</p>
            </section>
        </aside>
    </div>
</div>
