<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx400_specflow/api-docs — страница для интеграторов: контракт внешнего
 * API «спецификация PDF → структурированный JSON».
 *
 * Машиночитаемый контракт дублирует README модуля; страница показывает
 * ЧЕСТНОЕ состояние API на этом сервере (env PRX400_API_TOKEN задан?).
 *
 * Переменные: $api_enabled.
 */
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Оцифровка спецификаций', '/prx400_specflow'], ['API для интеграторов']],
        'title' => 'API: спецификация PDF → JSON',
        'pill' => ['prx400 · интеграции', 'fa-plug'],
        'lead' => 'Асинхронный контур для ERP/1C и других потребителей: загружаете PDF спецификации — забираете структурированный JSON с позициями, кодами НСИ и аудитом верификации.',
        'actions' => [
            ['html' => '<a href="/prx400_specflow" class="prx-btn-secondary prx-btn-sm"><i class="fas fa-file-pdf mr-1.5"></i>Спецификации</a>'],
        ],
    ]); ?>

    <div class="mt-5 flex flex-wrap items-center gap-3">
<?php if ($api_enabled): ?>
        <span class="prx-badge prx-badge-ok"><span class="prx-dot"></span>API включён на этом сервере</span>
<?php else: ?>
        <span class="prx-badge prx-badge-wait"><span class="prx-dot"></span>API выключен — задайте PRX400_API_TOKEN в .env</span>
<?php endif; ?>
        <span class="text-sm text-gray-500 dark:text-gray-400">Токен интегратора — в переменной окружения <code class="font-mono text-xs">PRX400_API_TOKEN</code>, передаётся заголовком <code class="font-mono text-xs">X-PRX-API-Token</code>.</span>
    </div>

    <div class="mt-5 gap-5 space-y-5 lg:grid lg:grid-cols-3 lg:items-start lg:space-y-0">
        <section class="prx-card lg:col-span-2">
            <h2 class="prx-h3"><i class="fas fa-upload mr-1.5 text-gray-400"></i>Шаг 1. Загрузить спецификацию</h2>
            <pre class="mt-2 overflow-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900">POST /prx400_specflow/api/spec
Content-Type: multipart/form-data
X-PRX-API-Token: &lt;токен&gt;

pdf         = файл спецификации (PDF, обязательный)
parse_mode  = ocr | ocr_vision | vision   (необязательно, по умолчанию ocr)
template_id = код шаблона                 (необязательно, пусто = авто-детекция)</pre>
            <p class="prx-hint mt-2">Ответ <strong>202</strong> — задача в очереди, разбор идёт на воркере (минуты, для vision — дольше):</p>
            <pre class="mt-1 overflow-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900">{
  "spec_id": 18,
  "status": "queued",
  "poll_url": "/prx400_specflow/api/spec/18"
}</pre>

            <h2 class="prx-h3 mt-6"><i class="fas fa-rotate mr-1.5 text-gray-400"></i>Шаг 2. Забрать результат (поллинг)</h2>
            <pre class="mt-2 overflow-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900">GET /prx400_specflow/api/spec/&lt;spec_id&gt;
X-PRX-API-Token: &lt;токен&gt;</pre>
            <table class="prx-table mt-2">
                <thead><tr><th>HTTP</th><th>status</th><th>Что в ответе</th></tr></thead>
                <tbody>
                    <tr><td class="font-mono text-xs">202</td><td class="font-mono text-xs">queued / running</td><td class="text-sm">В работе: <code class="font-mono text-xs">poll_url</code> — опрашивайте с интервалом 10–30 с.</td></tr>
                    <tr><td class="font-mono text-xs">200</td><td class="font-mono text-xs">review</td><td class="text-sm">Строки готовы, но ждут оператора: <code class="font-mono text-xs">result</code> + <code class="font-mono text-xs">awaiting_operator: true</code>.</td></tr>
                    <tr><td class="font-mono text-xs">200</td><td class="font-mono text-xs">done</td><td class="text-sm">Проверено и готово: <code class="font-mono text-xs">result</code> + <code class="font-mono text-xs">awaiting_operator: false</code>.</td></tr>
                    <tr><td class="font-mono text-xs">200</td><td class="font-mono text-xs">failed</td><td class="text-sm">Разбор не состоялся: <code class="font-mono text-xs">error.code</code> (no_text, header_not_found, no_rows…).</td></tr>
                    <tr><td class="font-mono text-xs">401 / 503</td><td class="font-mono text-xs">unauthorized / api_disabled</td><td class="text-sm">Токен неверен / API выключен на сервере.</td></tr>
                </tbody>
            </table>
            <p class="prx-hint mt-2">Схема <code class="font-mono text-xs">result</code> идентична экспорту ERP со страницы карточки спецификации — можно отладиться на живом примере.</p>

            <h2 class="prx-h3 mt-6"><i class="fas fa-cubes mr-1.5 text-gray-400"></i>Схема result</h2>
            <table class="prx-table mt-2">
                <thead><tr><th>Блок</th><th>Поля</th></tr></thead>
                <tbody>
                    <tr><td class="text-sm font-semibold">meta</td><td class="text-sm">template_id, specification_code, department, original_name, pages, text_sha256, processed_timestamp</td></tr>
                    <tr><td class="text-sm font-semibold">positions[]</td><td class="text-sm">line_number, raw_text, code_doc, catalog_id, catalog_code, standard_name, match_score, quantity, unit, okei_unit_code, unit_price, total_price, verification_status, warnings[]</td></tr>
                    <tr><td class="text-sm font-semibold">audit</td><td class="text-sm">auto_matched, confirmed_by_operator, new_positions, corrections[]</td></tr>
                </tbody>
            </table>
        </section>

        <aside class="prx-card">
            <h2 class="prx-h3">Пример curl</h2>
            <pre class="mt-2 overflow-auto rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-900">curl -X POST \
  -H "X-PRX-API-Token: $TOKEN" \
  -F "pdf=@spec.pdf" \
  -F "parse_mode=vision" \
  https://portal.toolforge.ru/prx400_specflow/api/spec

# затем до готовности:
curl -H "X-PRX-API-Token: $TOKEN" \
  https://portal.toolforge.ru/prx400_specflow/api/spec/18</pre>
            <p class="prx-hint mt-3">Режимы разбора: <strong>ocr</strong> — детерминированный текстовый (PDF с текстовым слоем, без LLM); <strong>ocr_vision</strong> — OCR с vision-фолбэком; <strong>vision</strong> — распознавание страниц LLM по изображениям (сканы).</p>
            <p class="prx-hint mt-2">Позиции со статусом <strong>review/new</strong> ждут решения оператора в интерфейсе «Платформы»; верификация отражается в <code class="font-mono text-xs">verification_status</code> и <code class="font-mono text-xs">audit</code>.</p>
            <p class="prx-hint mt-2">Лимиты: PDF до 20 МБ, страницы до 40 (vision — 8 на задачу). Полный перечень — <code class="font-mono text-xs">PRX400_*</code> в <code class="font-mono text-xs">.env.example</code>.</p>
        </aside>
    </div>
</div>
