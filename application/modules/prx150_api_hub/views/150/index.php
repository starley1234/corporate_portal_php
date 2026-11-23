<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API-доступ: ключи внешних потребителей.
 *
 * Переменные: $keys, $scopes, $created_key, $csrf, $flash, $rate_limit, $ttl_days.
 */
$tab = $tab ?? 'keys';
$scopes_json = function (string $json): array {
    $list = json_decode($json, TRUE);

    return is_array($list) ? $list : [];
};
$when = function ($value): string {
    return $value ? date('d.m.Y H:i', strtotime((string) $value)) : '—';
};
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Администрирование', '/admin'], ['API-доступ']],
        'title'  => 'API-доступ',
        'pill'   => ['prx150 · ключи и использование', 'fa-key'],
        'lead'   => 'Выдавайте внешним системам ключи с явными правами на сервисы. Каждый запрос пишется в журнал: кто, куда, с каким статусом и сколько занял.',
        'actions'=> [
            ['label' => 'Документация', 'href' => '/prx150_api_hub?tab=docs', 'icon' => 'fa-book', 'role' => 'secondary'],
            ['label' => 'Использование', 'href' => '/prx150_api_hub?tab=usage', 'icon' => 'fa-chart-line', 'role' => 'secondary'],
        ],
    ]); ?>

    <?php echo View::factory('partials/flash', ['flash' => $flash]); ?>

    <div class="prx-seg mt-5" role="tablist" aria-label="Разделы API-доступа">
        <a class="prx-seg-item is-active" role="tab" aria-selected="true" href="/prx150_api_hub"><i class="fas fa-key mr-1.5"></i>Ключи</a>
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx150_api_hub/usage"><i class="fas fa-chart-line mr-1.5"></i>Использование</a>
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx150_api_hub/docs"><i class="fas fa-book mr-1.5"></i>Документация</a>
    </div>

    <?php if ( ! empty($created_key)): ?>
        <section class="prx-card mt-5 border-emerald-300 dark:border-emerald-700" role="status">
            <h2 class="prx-h3 text-emerald-700 dark:text-emerald-300"><i class="fas fa-circle-check mr-1.5"></i>Ключ создан — скопируйте сейчас</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Полный ключ показывается один раз и не хранится на сервере. Если потеряете — отзовите и создайте новый.</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <code class="break-all rounded-lg bg-gray-900 px-3 py-2 font-mono text-xs text-emerald-200"><?php echo HTML::chars($created_key['token']); ?></code>
                <button type="button" class="prx-btn-secondary prx-btn-sm" data-prx-copy="<?php echo HTML::chars($created_key['token']); ?>"><i class="fas fa-copy"></i>Скопировать</button>
            </div>
            <p class="prx-hint mt-2">Ключ #<?php echo (int) $created_key['id']; ?> · <?php echo HTML::chars($created_key['name']); ?></p>
        </section>
    <?php endif; ?>

    <div class="mt-5 gap-6 space-y-6 lg:grid lg:grid-cols-3 lg:items-start lg:space-y-0">
        <div class="space-y-6 lg:col-span-2">
            <section class="prx-card" aria-labelledby="prx150-new-key">
                <h2 id="prx150-new-key" class="prx-h3"><i class="fas fa-plus mr-1.5 text-gray-400"></i>Новый ключ</h2>
                <form method="post" action="/prx150_api_hub/create" class="mt-4">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block">
                            <span class="prx-label">Название потребителя</span>
                            <input class="prx-input mt-1 w-full" name="name" maxlength="120" required placeholder="ERP, сайт-партнёра, мобильное приложение">
                        </label>
                        <label class="block">
                            <span class="prx-label">Лимит, запросов в минуту</span>
                            <input class="prx-input mt-1 w-full" type="number" name="rate_limit" min="1" max="600" value="<?php echo (int) $rate_limit; ?>">
                        </label>
                        <label class="block">
                            <span class="prx-label">Действует до (необязательно)</span>
                            <input class="prx-input mt-1 w-full" type="date" name="expires_at"<?php echo $ttl_days > 0 ? ' value="'.date('Y-m-d', time() + $ttl_days * 86400).'"' : ''; ?>>
                        </label>
                        <label class="block">
                            <span class="prx-label">Заметка</span>
                            <input class="prx-input mt-1 w-full" name="note" maxlength="500" placeholder="Кому выдан, зачем, срок">
                        </label>
                    </div>
                    <fieldset class="mt-4">
                        <legend class="prx-label">Права на сервисы (scope)</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <?php foreach ($scopes as $scope => $meta): ?>
                            <label class="flex min-h-[40px] items-start gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                <input type="checkbox" name="scopes[]" value="<?php echo HTML::chars($scope); ?>" class="mt-0.5 h-4 w-4 accent-blue-600">
                                <span>
                                    <span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($meta['title']); ?></span>
                                    <span class="block font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($scope); ?></span>
                                    <span class="block text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($meta['module']); ?><?php echo $meta['description'] !== '' ? ' · '.HTML::chars($meta['description']) : ''; ?></span>
                                </span>
                            </label>
                            <?php endforeach; ?>
                            <?php if (empty($scopes)): ?>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Ни один модуль пока не объявил `api_scopes`. Добавьте сервис-модуль — права появятся здесь.</p>
                            <?php endif; ?>
                        </div>
                    </fieldset>
                    <button class="prx-btn-cta mt-4" type="submit"><i class="fas fa-key"></i>Создать ключ</button>
                </form>
            </section>

            <section class="prx-card overflow-x-auto p-0" aria-labelledby="prx150-keys">
                <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
                    <h2 id="prx150-keys" class="prx-h3">Ключи <span class="prx-count ml-1"><?php echo count($keys); ?></span></h2>
                </div>
                <table class="prx-table min-w-[900px]">
                    <thead><tr><th>Потребитель</th><th>Ключ</th><th>Права</th><th>Лимит</th><th>Статус</th><th>Последний запрос</th><th>24 ч</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($keys as $key): ?>
                        <?php $key_scopes = $scopes_json((string) $key['scopes']); ?>
                        <tr>
                            <td>
                                <span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($key['name']); ?></span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">создан <?php echo $when($key['created_at']); ?></span>
                                <?php if ($key['note'] !== ''): ?><span class="block max-w-[260px] truncate text-xs text-gray-400 dark:text-gray-500" title="<?php echo HTML::chars($key['note']); ?>"><?php echo HTML::chars($key['note']); ?></span><?php endif; ?>
                            </td>
                            <td><code class="font-mono text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($key['key_prefix']); ?>…</code></td>
                            <td>
                                <?php foreach ($key_scopes as $scope): ?>
                                    <span class="prx-badge prx-badge-info mb-1 mr-1"><?php echo HTML::chars($scopes[$scope]['title'] ?? $scope); ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="tabular-nums"><?php echo (int) $key['rate_limit_per_min']; ?>/мин</td>
                            <td>
                                <?php if ($key['status'] === 'active'): ?><span class="prx-badge prx-badge-ok"><span class="prx-dot"></span>активен</span>
                                <?php else: ?><span class="prx-badge prx-badge-err"><span class="prx-dot"></span>отозван</span><?php endif; ?>
                                <?php if ($key['expires_at']): ?><span class="block text-xs text-gray-400 dark:text-gray-500">до <?php echo date('d.m.Y', strtotime((string) $key['expires_at'])); ?></span><?php endif; ?>
                            </td>
                            <td class="text-xs text-gray-500 dark:text-gray-400"><?php echo $when($key['last_used_at']); ?></td>
                            <td class="tabular-nums"><?php echo (int) $key['requests_24h']; ?></td>
                            <td class="text-right">
                                <?php if ($key['status'] === 'active'): ?>
                                <form method="post" action="/prx150_api_hub/revoke/<?php echo (int) $key['id']; ?>" class="inline">
                                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                    <button type="submit" class="prx-btn-danger prx-btn-sm" data-prx-arm="Отозвать ключ?"><i class="fas fa-ban"></i><span data-prx-arm-label>Отозвать</span></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($keys)): ?>
                        <tr><td colspan="8" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">Ключей ещё нет. Создайте первый — внешняя система получит URL, ключ и формат запроса.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>

        <aside class="space-y-4">
            <section class="prx-card" aria-labelledby="prx150-how">
                <h2 id="prx150-how" class="prx-h3">Как это работает</h2>
                <ol class="mt-3 space-y-3 text-sm text-gray-600 dark:text-gray-300">
                    <li class="flex gap-2"><span class="prx-count">1</span><span>Создайте ключ и отметьте права — только те сервисы, которые нужны потребителю.</span></li>
                    <li class="flex gap-2"><span class="prx-count">2</span><span>Выдайте ключ, адрес портала и формат запроса. Полный ключ больше не показывается.</span></li>
                    <li class="flex gap-2"><span class="prx-count">3</span><span>Смотрите использование: ошибки, лимиты, кто и куда звал. Отзовите ключ одним действием.</span></li>
                </ol>
            </section>
            <section class="prx-card">
                <h2 class="prx-h3">Что получает потребитель</h2>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <li><i class="fas fa-link mr-1.5 w-4 text-gray-400"></i>Адрес: <code>/api/v1/&lt;service&gt;/…</code></li>
                    <li><i class="fas fa-key mr-1.5 w-4 text-gray-400"></i>Заголовок: <code>Authorization: Bearer …</code></li>
                    <li><i class="fas fa-shield-halved mr-1.5 w-4 text-gray-400"></i>Права: scope ключа, а не роль сотрудника</li>
                    <li><i class="fas fa-gauge-high mr-1.5 w-4 text-gray-400"></i>Лимит: запросов в минуту на ключ</li>
                </ul>
                <a class="prx-link mt-3 inline-block text-sm" href="/prx150_api_hub/docs">Открыть документацию →</a>
            </section>
            <section class="prx-card">
                <h2 class="prx-h3">Сервисы</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Свой сервис добавляет модуль в манифесте: <code>api_scopes</code>. Центрального списка нет.</p>
                <p class="prx-hint mt-2">Сейчас прав: <?php echo count($scopes); ?>.</p>
            </section>
        </aside>
    </div>
</div>
