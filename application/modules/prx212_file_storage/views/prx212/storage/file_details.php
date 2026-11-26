<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx212 — карточка файла хранилища: предпросмотр, свойства, доступ.
 *
 * Предпросмотр идёт через /prx212_download/file/<id>?disposition=inline:
 * тот же ACL, что и при скачивании; inline разрешён только для PDF и
 * растровых картинок (Download::inlineAllowed), остальное — скачиванием.
 *
 * Переменные: $file (ORM Prx212_File), $service (Service_Prx212_Storage).
 */

$prx_download = Route::url('default', ['controller' => 'prx212_download', 'action' => 'file', 'id' => $file->id]);
$prx_inline = $prx_download.'?disposition=inline';
$prx_mime = (string) $file->mime_type;
$prx_is_pdf = ($prx_mime === 'application/pdf');
$prx_is_image = (strpos($prx_mime, 'image/') === 0);
$prx_links = $file->get_links_stats();
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

<?php echo View::factory('partials/page_header', [
    'crumbs' => [['Хранилище', '/prx212_file_storage'], [$file->filename]],
    'title'  => $file->filename,
    'pill'   => ['prx212 · хранилище', 'fa-file-lines'],
    'actions' => [
        ['label' => 'Скачать', 'href' => $prx_download, 'icon' => 'fa-download', 'role' => 'process'],
        ['label' => 'Изменить', 'href' => '/prx212_file_storage/edit/'.(int) $file->id, 'icon' => 'fa-pen', 'role' => 'secondary'],
        ['label' => 'Удалить', 'icon' => 'fa-trash', 'role' => 'danger', 'attrs' => [
            'id' => 'prx212-file-delete',
            'data-prx-confirm' => 'Файл будет удалён из хранилища.',
            'data-prx-title' => 'Удалить файл?',
        ]],
    ],
]); ?>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">

        <!-- Предпросмотр: PDF в iframe, картинка в img, остальное — подсказка -->
        <section class="prx-card p-0 overflow-hidden" aria-labelledby="prx212-preview-h">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
                <h2 id="prx212-preview-h" class="prx-h3">Предпросмотр</h2>
                <a class="prx-btn-secondary prx-btn-sm" href="<?php echo HTML::chars($prx_inline); ?>" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square text-xs"></i><span>Открыть отдельно</span></a>
            </div>
<?php if ($prx_is_pdf): ?>
            <iframe src="<?php echo HTML::chars($prx_inline); ?>" title="Предпросмотр PDF: <?php echo HTML::chars($file->filename); ?>" class="h-[70vh] w-full bg-gray-50 dark:bg-gray-900"></iframe>
<?php elseif ($prx_is_image): ?>
            <div class="flex justify-center bg-gray-50 p-4 dark:bg-gray-900">
                <img src="<?php echo HTML::chars($prx_download); ?>" alt="<?php echo HTML::chars($file->filename); ?>" class="max-h-[70vh] w-auto max-w-full rounded-lg border border-gray-200 dark:border-gray-700">
            </div>
<?php else: ?>
            <p class="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">Предпросмотр для этого типа файла недоступен — скачайте файл кнопкой выше.</p>
<?php endif; ?>
        </section>

        <div class="space-y-5">

            <!-- Свойства -->
            <section class="prx-card p-0 overflow-hidden" aria-labelledby="prx212-props-h">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
                    <h2 id="prx212-props-h" class="prx-h3">Свойства</h2>
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 p-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500 dark:text-gray-400">Размер</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100"><?php echo Text::bytes($file->file_size); ?></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">MIME-тип</dt><dd class="mt-0.5 break-all font-mono text-xs text-gray-800 dark:text-gray-100"><?php echo HTML::chars($prx_mime); ?></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Создан</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100"><?php echo date('d.m.Y H:i', strtotime((string) $file->created_at)); ?></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Владелец</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100"><?php echo $file->user->loaded() ? HTML::chars((string) $file->user->username) : '<span class="italic text-gray-400">система</span>'; ?></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Хранилище</dt><dd class="mt-0.5 text-gray-800 dark:text-gray-100"><?php echo HTML::chars((string) $file->backend->name); ?> <span class="text-xs text-gray-400">(<?php echo HTML::chars((string) $file->backend->driver); ?>)</span></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Доступ</dt><dd class="mt-0.5"><span class="prx-badge <?php echo $file->is_public ? 'prx-badge-info' : 'prx-badge-wait'; ?>"><span class="prx-dot"></span><?php echo $file->is_public ? 'Публичный' : 'Приватный'; ?></span></dd></div>
                    <div class="sm:col-span-2"><dt class="text-gray-500 dark:text-gray-400">SHA-256</dt><dd class="mt-0.5 break-all font-mono text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) ($file->hash ?: 'не вычислен')); ?></dd></div>
                </dl>
            </section>

            <!-- Доступ и временные ссылки -->
            <section class="prx-card p-0 overflow-hidden" aria-labelledby="prx212-access-h">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
                    <h2 id="prx212-access-h" class="prx-h3">Доступ и ссылки</h2>
                </div>
                <div class="space-y-3 p-4 text-sm">
                    <div class="flex items-center justify-between"><span class="text-gray-500 dark:text-gray-400">Права (ACL)</span><span class="prx-count"><?php echo count($file->permissions->find_all()); ?> записей</span></div>
                    <div class="flex items-center justify-between"><span class="text-gray-500 dark:text-gray-400">Активные ссылки</span><span class="prx-count"><?php echo (int) $prx_links['active_links']; ?></span></div>
                    <div class="flex items-center justify-between"><span class="text-gray-500 dark:text-gray-400">Переходов по ссылкам</span><span class="prx-count"><?php echo (int) $prx_links['total_clicks']; ?></span></div>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <a class="prx-btn-secondary" href="/prx212_file_storage/permissions/<?php echo (int) $file->id; ?>"><i class="fas fa-user-shield"></i><span>Права доступа</span></a>
                        <button type="button" id="prx212-link-create" class="prx-btn-secondary"><i class="fas fa-link"></i><span>Создать ссылку</span></button>
                    </div>
                    <p class="prx-hint">Временная ссылка создаётся на час и копируется в буфер обмена.</p>
                </div>
            </section>

        </div>
    </div>
</div>

<script>
// Удаление файла: клик доходит только после подтверждения data-prx-confirm
// на кнопке «Удалить» (модалка app.js). Эндпойнт тот же, что в списке файлов.
document.getElementById('prx212-file-delete').addEventListener('click', function () {
    fetch('/prx212_api/delete/' + <?php echo (int) $file->id; ?>, { method: 'DELETE' })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                window.location.href = '/prx212_file_storage';
            } else {
                PRX.toast(data.error || 'Не удалось удалить файл', 0, 'err');
            }
        })
        .catch(function () { PRX.toast('Не удалось удалить файл: сеть не ответила', 0, 'err'); });
});

// Временная ссылка (prx212_link/create, как в списке файлов): URL копируется
// в буфер обмена; срок жизни — 1 час.
document.getElementById('prx212-link-create').addEventListener('click', function () {
    var body = new FormData();
    body.append('file_id', <?php echo (int) $file->id; ?>);
    body.append('ttl', 3600);
    fetch('/prx212_link/create', { method: 'POST', body: body })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (!result.success) {
                PRX.toast(result.error || 'Ссылку создать не удалось', 0, 'err');
                return;
            }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(result.data.url).then(function () {
                    PRX.toast('Временная ссылка скопирована в буфер обмена', 0, 'ok');
                }, function () {
                    PRX.toast('Ссылка создана: ' + result.data.url, 0, 'ok');
                });
            } else {
                PRX.toast('Ссылка создана: ' + result.data.url, 0, 'ok');
            }
        })
        .catch(function () { PRX.toast('Ссылку создать не удалось: сеть не ответила', 0, 'err'); });
});
</script>
