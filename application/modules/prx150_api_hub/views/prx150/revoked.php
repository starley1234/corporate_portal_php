<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx150 — отозванные ключи, отдельным списком (аудит): кто, когда отозван,
 * какие права были. Действий нет: отозвать повторно или «оживить» нельзя —
 * ключ создаётся заново, если нужен.
 */

$when = function ($value): string {
    return $value ? date('d.m.Y H:i', strtotime((string) $value)) : '—';
};
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

<?php echo View::factory('partials/page_header', [
    'crumbs' => [['Кабинет', '/dashboard'], ['API-ключи', '/prx150_api_hub'], ['Отозванные']],
    'title'  => 'Отозванные ключи',
    'pill'   => ['prx150 · API-доступ', 'fa-ban'],
    'lead'   => 'Отозванный ключ не работает с момента отзыва и не восстанавливается: список — для аудита. Нужен новый ключ — создайте на странице «Ключи».',
    'actions' => [['label' => 'К ключам', 'href' => '/prx150_api_hub', 'icon' => 'fa-arrow-left', 'role' => 'secondary']],
]); ?>

<?php echo View::factory('partials/flash', ['flash' => $flash]); ?>

    <section class="prx-card mt-5 overflow-x-auto p-0" aria-labelledby="prx150-revoked">
        <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
            <h2 id="prx150-revoked" class="prx-h3">Отозванные <span class="prx-count ml-1"><?php echo count($keys); ?></span></h2>
        </div>
<?php if (empty($keys)): ?>
        <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Отозванных ключей нет.</p>
<?php else: ?>
        <table class="prx-table min-w-[760px]">
            <thead><tr><th>Потребитель</th><th>Ключ</th><th>Права</th><th>Отозван</th><th>Заметка</th></tr></thead>
            <tbody>
<?php foreach ($keys as $key): ?>
            <tr>
                <td>
                    <span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($key['name']); ?></span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">создан <?php echo $when($key['created_at']); ?></span>
                </td>
                <td><code class="font-mono text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($key['key_prefix']); ?>…</code></td>
                <td class="text-xs text-gray-600 dark:text-gray-300">
<?php $key_scopes = json_decode((string) $key['scopes'], TRUE); ?>
<?php foreach ((array) $key_scopes as $scope): ?>
                    <span class="prx-badge prx-badge-info mb-1 mr-1"><?php echo HTML::chars((string) $scope); ?></span>
<?php endforeach; ?>
                </td>
                <td class="tabular-nums text-xs text-gray-500 dark:text-gray-400"><?php echo $when($key['revoked_at']); ?></td>
                <td class="max-w-[280px] break-words text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $key['note']); ?></td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php endif; ?>
    </section>
</div>
