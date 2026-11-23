<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API-доступ: документация для потребителя.
 *
 * Переменные: $scopes, $flash.
 */
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Администрирование', '/admin'], ['API-доступ', '/prx150_api_hub'], ['Документация']],
        'title'  => 'Документация API',
        'pill'   => ['что выдать потребителю', 'fa-book'],
        'lead'   => 'Минимальный контракт: адрес, ключ, право на сервис и формат ошибок. Внутренние детали портала потребителю не нужны.',
        'actions'=> [
            ['label' => 'Ключи', 'href' => '/prx150_api_hub', 'icon' => 'fa-key', 'role' => 'secondary'],
            ['label' => 'Использование', 'href' => '/prx150_api_hub/usage', 'icon' => 'fa-chart-line', 'role' => 'secondary'],
        ],
    ]); ?>

    <?php echo View::factory('partials/flash', ['flash' => $flash]); ?>

    <div class="prx-seg mt-5" role="tablist" aria-label="Разделы API-доступа">
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx150_api_hub"><i class="fas fa-key mr-1.5"></i>Ключи</a>
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx150_api_hub/usage"><i class="fas fa-chart-line mr-1.5"></i>Использование</a>
        <a class="prx-seg-item is-active" role="tab" aria-selected="true" href="/prx150_api_hub/docs"><i class="fas fa-book mr-1.5"></i>Документация</a>
    </div>

    <div class="mt-5 gap-6 space-y-6 lg:grid lg:grid-cols-3 lg:items-start lg:space-y-0">
        <div class="space-y-6 lg:col-span-2">
            <section class="prx-card" aria-labelledby="prx150-docs-auth">
                <h2 id="prx150-docs-auth" class="prx-h3">1. Адрес и ключ</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Базовый адрес — домен портала. Машинные маршруты живут под <code>/api/v1/&lt;service&gt;/…</code>; страница модуля — <code>/prxNNN_slug</code>. Ключ передаётся заголовком:</p>
                <pre class="mt-3 overflow-auto rounded-lg bg-gray-900 p-3 text-xs leading-5 text-gray-100"><code>Authorization: Bearer prx_…</code></pre>
                <ul class="mt-3 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <li><i class="fas fa-check mr-1.5 w-4 text-emerald-500"></i>Ключ выдаётся администратором на странице «Ключи» и показывается один раз.</li>
                    <li><i class="fas fa-shield-halved mr-1.5 w-4 text-gray-400"></i>Права определяет scope ключа, а не роль сотрудника и не сессия.</li>
                    <li><i class="fas fa-gauge-high mr-1.5 w-4 text-gray-400"></i>Лимит — запросов в минуту; превышение даёт <code>429 rate_limited</code>.</li>
                    <li><i class="fas fa-rotate mr-1.5 w-4 text-gray-400"></i>Отозванный ключ сразу даёт <code>403 key_revoked</code>; новый ключ — новая запись.</li>
                </ul>
            </section>

            <section class="prx-card" aria-labelledby="prx150-docs-scopes">
                <h2 id="prx150-docs-scopes" class="prx-h3">2. Права на сервисы</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Сервис объявляет свои scope в манифесте модуля (<code>api_scopes</code>). Ключу можно выдать любое подмножество.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="prx-table min-w-[620px]">
                        <thead><tr><th>Scope</th><th>Сервис</th><th>Модуль</th><th>Лимит по умолчанию</th></tr></thead>
                        <tbody>
                        <?php foreach ($scopes as $scope => $meta): ?>
                            <tr>
                                <td><code class="font-mono text-xs"><?php echo HTML::chars($scope); ?></code></td>
                                <td><?php echo HTML::chars($meta['title']); ?><?php echo $meta['description'] !== '' ? '<span class="block text-xs text-gray-400">'.HTML::chars($meta['description']).'</span>' : ''; ?></td>
                                <td><code class="font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($meta['module']); ?></code></td>
                                <td class="tabular-nums"><?php echo (int) $meta['rate_limit']; ?>/мин</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($scopes)): ?>
                            <tr><td colspan="4" class="py-6 text-center text-sm text-gray-500">Сервисы ещё не объявили права.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="prx-card" aria-labelledby="prx150-docs-errors">
                <h2 id="prx150-docs-errors" class="prx-h3">3. Ошибки</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Всегда JSON, всегда с кодом. Внутренние пути, токены и чужие данные в ответ не попадают.</p>
                <pre class="mt-3 overflow-auto rounded-lg bg-gray-900 p-3 text-xs leading-5 text-gray-100"><code>{"error":{"code":"rate_limited","details":{"retry_after":60,"limit_per_min":60,"scope":"prx450.parse"}}}</code></pre>
                <div class="mt-3 overflow-x-auto">
                    <table class="prx-table min-w-[520px]">
                        <thead><tr><th>HTTP</th><th>Код</th><th>Что значит</th></tr></thead>
                        <tbody>
                            <tr><td>401</td><td><code>unauthorized</code></td><td>Нет заголовка, неверный формат или неизвестный ключ.</td></tr>
                            <tr><td>403</td><td><code>forbidden</code></td><td>У ключа нет нужного scope.</td></tr>
                            <tr><td>403</td><td><code>key_revoked</code> / <code>key_expired</code></td><td>Ключ отозван или истёк срок.</td></tr>
                            <tr><td>429</td><td><code>rate_limited</code></td><td>Превышен лимит запросов в минуту.</td></tr>
                            <tr><td>503</td><td><code>service_unavailable</code></td><td>Внутренний сбой; повторить позже.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="space-y-4">
            <section class="prx-card" aria-labelledby="prx150-docs-example">
                <h2 id="prx150-docs-example" class="prx-h3">Пример запроса</h2>
                <pre class="mt-3 overflow-auto rounded-lg bg-gray-900 p-3 text-xs leading-5 text-gray-100"><code>curl --fail-with-body \
  "https://portal.example/api/v1/document-ocr/parse?engine=auto" \
  -H "Authorization: Bearer $PRX_API_KEY" \
  -F "file=@document.pdf"</code></pre>
                <p class="prx-hint mt-2">Адрес и формат конкретного сервиса — в его документации. Общий контракт ошибок и ключей — здесь.</p>
                <a class="prx-link mt-2 inline-block text-sm" href="/prx450_document_ocr">Распознавание документов →</a>
            </section>
            <section class="prx-card">
                <h2 class="prx-h3">Как добавить сервис</h2>
                <ol class="mt-2 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <li>Объявите <code>api_scopes</code> в манифесте модуля.</li>
                    <li>Машинный контроллер наследует <code>Controller_Prx150_Machine</code> и задаёт <code>SCOPE</code>.</li>
                    <li>Маршрут — <code>/api/v1/&lt;service&gt;/…</code>; страница — <code>/prxNNN_slug</code>.</li>
                </ol>
                <p class="prx-hint mt-2">Подробности — <code>docs/integrations/api.md</code> и <code>docs/MODULE_GUIDE.md</code>.</p>
            </section>
            <section class="prx-card">
                <h2 class="prx-h3">Мониторинг</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Каждый запрос попадает в журнал: ключ, scope, путь, статус, время и объём. Смотрите вкладку «Использование».</p>
                <a class="prx-link mt-2 inline-block text-sm" href="/prx150_api_hub/usage">Открыть журнал →</a>
            </section>
        </aside>
    </div>
</div>
