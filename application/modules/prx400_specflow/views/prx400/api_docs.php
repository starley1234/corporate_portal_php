<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx400_specflow/api-docs — контракт внешнего API (prx150: ключ + scope).
 *
 * Переменные: $scope, $parse_modes, $templates, $max_pdf, $can_keys.
 */
$code = static fn (string $s): string => '<code class="font-mono text-xs">'.HTML::chars($s).'</code>';
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Оцифровка спецификаций', '/prx400_specflow'], ['API для интеграторов']],
        'title' => 'API: спецификация PDF → JSON',
        'pill' => ['prx400 · интеграции', 'fa-plug'],
        'lead' => 'Асинхронный контур для ERP/1C и других потребителей: отправляете PDF спецификации — забираете позиции с кодами НСИ и статусом проверки оператором.',
        'actions' => $can_keys ? [['label' => 'Ключи API', 'href' => '/prx150', 'icon' => 'fa-key', 'role' => 'secondary']] : [],
    ]); ?>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-key mr-1.5 text-gray-400"></i>Доступ</h2>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Ключ выпускает администратор в хабе API (prx150) со scope <?php echo $code($scope); ?>. Ключ передаётся в заголовке
            <?php echo $code('Authorization: Bearer <ключ>'); ?>. Лимит запросов, журнал вызовов и отзыв ключа — общие для платформы.
            Ключ видит только спецификации, отправленные им самим; чужие идентификаторы отвечают 404.
        </p>
    </section>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-upload mr-1.5 text-gray-400"></i>1. Отправить PDF</h2>
        <pre class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900">POST /api/v1/spec-extractions
Authorization: Bearer &lt;ключ&gt;
Idempotency-Key: &lt;уникальный ключ запроса, до 128 символов [A-Za-z0-9._:-]&gt;
Content-Type: multipart/form-data

file=@spec.pdf            (до <?php echo HTML::chars($max_pdf); ?>)
parse_mode=<?php echo HTML::chars(implode('|', $parse_modes)); ?>   (необязательно)
template_id=&lt;код шаблона&gt;  (необязательно; пусто — авто-детекция)

→ 202 {"spec_id": 42, "status": "queued", "status_url": "/api/v1/spec-extractions/42"}</pre>
        <p class="prx-hint mt-2">Повтор с тем же Idempotency-Key и тем же файлом вернёт ту же спецификацию; с другим файлом — 409 <?php echo $code('idempotency_conflict'); ?>.</p>
<?php if ($templates): ?>
        <p class="prx-hint mt-2">Шаблоны: <?php echo implode(', ', array_map(static fn ($t) => $code((string) $t['id']), $templates)); ?>.</p>
<?php endif; ?>
    </section>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-rotate mr-1.5 text-gray-400"></i>2. Опрашивать статус</h2>
        <pre class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900">GET /api/v1/spec-extractions/42
Authorization: Bearer &lt;ключ&gt;

→ 200 {"spec_id": 42, "status": "queued" | "running"}
→ 200 {"spec_id": 42, "status": "failed" | "canceled", "error": {"code": "no_rows"}}
→ 200 {"spec_id": 42, "status": "review", "awaiting_operator": true, "result": {…}}
→ 200 {"spec_id": 42, "status": "done", "awaiting_operator": false, "result": {…}}</pre>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            <?php echo $code('review'); ?> — позиции извлечены, оператор ещё сверяет их с документом (результат предварительный);
            <?php echo $code('done'); ?> — проверка завершена. Рекомендуемый интервал опроса — 5–10 секунд.
        </p>
        <p class="prx-hint mt-2">Коды ошибок разбора: <?php echo implode(', ', array_map($code, ['no_text', 'header_not_found', 'no_rows', 'vision_unavailable', 'invalid_pdf', 'page_limit', 'result_too_large', 'job_timeout', 'job_failed'])); ?>.</p>
    </section>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-file-code mr-1.5 text-gray-400"></i>Формат result</h2>
        <pre class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900">{
  "meta": {"spec_id": 42, "status": "review", "template_id": "gost_21_110", "specification_code": "41-ПЗ",
           "department": "ПТО", "original_name": "spec.pdf", "pages": 3, "parse_mode": "ocr",
           "text_sha256": "…", "processed_timestamp": "2026-11-27T12:00:00+00:00"},
  "positions": [
    {"line_number": 1, "section": "…", "raw_text": "Гайка М12 ГОСТ 5915-70", "code_doc": "…",
     "quantity": 12, "unit": "шт", "unit_price": null, "total_price": null,
     "catalog_id": 123, "catalog_code": "NSI-000123", "standard_name": "…", "okei_unit_code": "796",
     "match_score": 0.97, "verification_status": "ok" | "review" | "new" | "error" | "verified",
     "warnings": ["math_row" | "gap" | "unit_fractional"]}
  ],
  "audit": {"auto_matched": 10, "confirmed_by_operator": 2, "new_positions": 1, "corrections": […]}
}</pre>
    </section>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-triangle-exclamation mr-1.5 text-gray-400"></i>Ошибки запроса</h2>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Тело ошибки — <?php echo $code('{"error": {"code": "…"}}'); ?>.
            401 — нет/неверный ключ; 403 — у ключа нет scope; 404 — спецификация не найдена или чужая; 409 — конфликт ключа идемпотентности;
            413 — файл больше лимита; 422 — не PDF / неизвестный режим или шаблон; 429 — лимит запросов или бюджет задач; 503 — хранилище или очередь недоступны.
        </p>
    </section>
</div>
