<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx400_specflow/api-docs — контракт внешнего API (prx150: ключ + scope).
 *
 * Переменные: $scope, $parse_modes, $templates, $max_pdf, $can_keys,
 * $max_files, $max_total, $catalog_provider.
 */
$code = static fn (string $s): string => '<code class="font-mono text-xs">'.HTML::chars($s).'</code>';
$pre = 'mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900';
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
            Ключ выпускает администратор в хабе API (prx150) со scope <?php echo $code($scope); ?> (загрузка и чтение)
            и, если интеграция сама проверяет позиции, <?php echo $code('prx400.review'); ?> (правки строк, подтверждение, повторный разбор, удаление). Ключ передаётся в заголовке
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
  или files[]=@list1.pdf files[]=@list2.pdf …  (одна спецификация из нескольких PDF:
  до <?php echo (int) $max_files; ?> файлов, всего до <?php echo HTML::chars($max_total); ?>; порядок = порядок листов)
parse_mode=<?php echo HTML::chars(implode('|', $parse_modes)); ?>   (необязательно)
template_id=&lt;код шаблона&gt;  (необязательно; пусто — авто-детекция)

→ 202 {"spec_id": 42, "status": "queued", "status_url": "/api/v1/spec-extractions/42"}</pre>
        <p class="prx-hint mt-2">Повтор с тем же Idempotency-Key и тем же файлом вернёт ту же спецификацию; с другим файлом — 409 <?php echo $code('idempotency_conflict'); ?>.
            Продолжения листов без шапки таблицы разбираются шаблоном первого файла; лист, где таблица не найдена, пропускается с предупреждением <?php echo $code('file_<n>_<код>'); ?>.</p>
<?php if ($templates): ?>
        <p class="prx-hint mt-2">Шаблоны: <?php echo implode(', ', array_map(static fn ($t) => $code((string) $t['id']), $templates)); ?>.</p>
<?php endif; ?>
    </section>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-rotate mr-1.5 text-gray-400"></i>2. Опрашивать статус</h2>
        <pre class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900">GET /api/v1/spec-extractions/42
Authorization: Bearer &lt;ключ&gt;

→ 200 {"spec_id": 42, "status": "queued" | "running", "files": [{"file_no": 1, "original_name": "…", "sha256": "…"}]}
→ 200 {"spec_id": 42, "status": "failed" | "canceled", "error": {"code": "no_rows"}}
→ 200 {"spec_id": 42, "status": "review", "awaiting_operator": true,
       "counts": {"all": 40, "disputed": 3, "ok": 35, "review": 2, "new": 1, "error": 0, "verified": 4}, "result": {…}}
→ 200 {"spec_id": 42, "status": "done", "awaiting_operator": false, "result": {…}}</pre>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            <?php echo $code('review'); ?> — позиции извлечены, оператор ещё сверяет их с документом (результат предварительный);
            <?php echo $code('done'); ?> — проверка завершена. Рекомендуемый интервал опроса — 5–10 секунд; вместо опроса можно подписаться на webhook (ниже).
        </p>
        <p class="prx-hint mt-2">Коды ошибок разбора: <?php echo implode(', ', array_map($code, ['no_text', 'header_not_found', 'no_rows', 'vision_unavailable', 'invalid_pdf', 'page_limit', 'result_too_large', 'job_timeout', 'worker_lost', 'worker_crash_loop', 'job_failed'])); ?>.</p>
    </section>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-file-code mr-1.5 text-gray-400"></i>Формат result</h2>
        <pre class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900">{
  "meta": {"spec_id": 42, "status": "review", "template_id": "gost_21_110", "specification_code": "41-ПЗ",
           "department": "ПТО", "original_name": "spec.pdf", "pages": 3, "parse_mode": "ocr",
           "text_sha256": "…", "processed_timestamp": "2026-11-27T12:00:00+00:00"},
  "positions": [
    {"row_id": 501, "line_number": 1, "position": "1.2", "level": 1, "parent_row_id": 500,
     "file_no": 1, "page": 2, "origin": "parsed" | "manual" | "split",
     "section": "…", "raw_text": "Гайка М12 ГОСТ 5915-70", "code_doc": "…",
     "quantity": 12, "unit": "шт", "unit_price": null, "total_price": null,
     "catalog_id": 123, "catalog_code": "NSI-000123", "standard_name": "…", "okei_unit_code": "796",
     "match_score": 0.97, "match_via": "fuzzy" | "code" | "memory" | "manual",
     "verification_status": "ok" | "review" | "new" | "error" | "verified",
     "warnings": ["math_row" | "gap" | "unit_fractional"]}
  ],
  "audit": {"auto_matched": 10, "confirmed_by_operator": 2, "new_positions": 1, "corrections": […]}
}</pre>
        <p class="prx-hint mt-2">Иерархия BOM: <?php echo $code('position'); ?> — номер как в документе («1.2.3»), <?php echo $code('parent_row_id'); ?> — строка-сборка.
            Номер без точек и колонка «Уровень» тоже дают дерево. <?php echo $code('page'); ?> и <?php echo $code('file_no'); ?> ведут к месту в исходном PDF.</p>
    </section>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-file-export mr-1.5 text-gray-400"></i>3. Выгрузки и исходники</h2>
        <pre class="<?php echo $pre; ?>">GET /api/v1/spec-extractions/42/export?format=json            (по умолчанию — как result)
GET /api/v1/spec-extractions/42/export?format=csv | xlsx       (xlsx: «Позиции», «Закупка», «Сводка»)
GET /api/v1/spec-extractions/42/export?format=aggregate&amp;bom=as_is | per_parent
→ 200 {"spec_id": 42, "bom": "per_parent", "items": [{"catalog_code": "NSI-1", "name": "…", "unit": "шт",
       "quantity": 14, "total_price": 140.0, "rows": 2, "positions": ["1.1", "2"], "verified": false, "warnings": []}]}
GET /api/v1/spec-extractions/42/files/2                        исходный PDF №2</pre>
        <p class="prx-hint mt-2">Закупочная ведомость суммирует одинаковые коды НСИ (без кода — по наименованию и единице); сборки, у которых есть состав, не закупаются.
            <?php echo $code('per_parent'); ?> умножает количество на количество всех сборок выше (2 узла × 4 болта = 8).</p>
    </section>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-user-check mr-1.5 text-gray-400"></i>4. Проверка позиций из своей системы <span class="text-sm font-normal text-gray-500">scope prx400.review</span></h2>
        <pre class="<?php echo $pre; ?>">GET    /api/v1/spec-extractions/42/rows?filter=all|disputed|ok|review|new|error|verified
→ 200 {"spec_id": 42, "status": "review", "filter": "disputed", "counts": {…},
       "rows": [{…позиция…, "status": "review", "verified": false, "flags": […], "candidates": [{"catalog_code", "name", "unit", "score"}]}]}
PATCH  /api/v1/spec-extractions/42/rows/501   {"qty": "12", "unit": "шт", "raw_name": "…", "unit_price": "1,50", "total_price": "18"}
POST   /api/v1/spec-extractions/42/rows/501/verify   {"verified": true | false}   (без тела — подтвердить)
POST   /api/v1/spec-extractions/42/rows/501/code     {"code": "NSI-000123"}   DELETE — снять ручной код
POST   /api/v1/spec-extractions/42/rows/501/split    {"quantities": [4, 8]}   (сумма = количеству строки)
POST   /api/v1/spec-extractions/42/rows              {"raw_name": "…", "qty": 1, "unit": "шт", "after_row_id"?, "parent_row_id"?, "pos_path"?}
DELETE /api/v1/spec-extractions/42/rows/501          (строку с составом — 409 row_has_children)
POST   /api/v1/spec-extractions/42/verify-bulk       {"min_score": 0.9}  → {"verified": 17}
POST   /api/v1/spec-extractions/42/rematch           → {"checked": 40, "changed": 12}  (строки без решения оператора — заново с НСИ)
POST   /api/v1/spec-extractions/42/finish            {"accept_issues": false}
POST   /api/v1/spec-extractions/42/relaunch          {"parse_mode"?, "template_id"?}  → 202
GET    /api/v1/spec-catalog?q=болт м16&amp;limit=20     → {"provider": "<?php echo HTML::chars($catalog_provider); ?>", "items": [{"code", "name", "unit", "okei_code"}]}</pre>
        <p class="prx-hint mt-2">Действие над строкой отвечает её новым состоянием. Правки возможны в статусах <?php echo $code('review'); ?> и <?php echo $code('done'); ?>, каждая пишется в журнал.
            Ручной код запоминается: следующие разборы подставят его сами (<?php echo $code('match_via: memory'); ?>).
            Повторный разбор переносит подтверждения и ручные коды на совпавшие строки и сохраняет добавленные вручную; потерянные подтверждения — предупреждение <?php echo $code('verified_rows_lost'); ?>.</p>
    </section>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-bell mr-1.5 text-gray-400"></i>5. Webhook вместо опроса</h2>
        <pre class="<?php echo $pre; ?>">PUT    /api/v1/spec-extractions/webhook  {"url": "https://erp.example/prx400", "events": ["spec.review", "spec.done", "spec.failed"]}
→ 200 {"url": "…", "events": […], "secret": "…"}     (секрет показывается один раз; PUT заново — новый секрет)
GET    /api/v1/spec-extractions/webhook  → {"url", "events", "deliveries": [последние 20 доставок]}
DELETE /api/v1/spec-extractions/webhook

POST https://erp.example/prx400
X-PRX-Event: spec.done
X-PRX-Delivery: 913
X-PRX-Signature: t=1795780800,v1=&lt;hex HMAC-SHA256(secret, "t.тело")&gt;
{"id": 913, "event": "spec.done", "spec_id": 42, "status": "done", "status_url": "/api/v1/spec-extractions/42", "occurred_at": "…"}</pre>
        <p class="prx-hint mt-2">В теле только ссылка — данные забирайте по <?php echo $code('status_url'); ?> своим ключом. Ответ 2xx — доставлено; иначе повтор через 1, 5, 15, 60 мин, 3, 6, 12, 24 ч.
            Проверяйте подпись и отклоняйте <?php echo $code('t'); ?> старше 5 минут. Адрес — только https и публичный IP.</p>
    </section>

    <section class="prx-card mt-5">
        <h2 class="prx-h3"><i class="fas fa-triangle-exclamation mr-1.5 text-gray-400"></i>Ошибки запроса</h2>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Тело ошибки — <?php echo $code('{"error": {"code": "…"}}'); ?>.
            401 — нет/неверный ключ; 403 — у ключа нет scope; 404 — спецификация или строка не найдена либо чужая; 409 — конфликт ключа идемпотентности;
            400 — неверные параметры (<?php echo $code('invalid_parse_mode'); ?>, <?php echo $code('qty_invalid'); ?>, <?php echo $code('invalid_filter'); ?>, <?php echo $code('invalid_field'); ?> — массив вместо строки…); 405 — метод не поддерживается;
            409 — состояние не позволяет (<?php echo $code('wrong_state'); ?>, <?php echo $code('issues_pending'); ?>, <?php echo $code('row_has_children'); ?>, <?php echo $code('split_sum_mismatch'); ?>);
            410 — исходный PDF удалён по сроку хранения; 413 — файл больше лимита или слишком много файлов; 415 — не PDF;
            429 — лимит запросов или бюджет задач; 503 — хранилище, очередь или внешний справочник НСИ недоступны.
        </p>
    </section>
</div>
