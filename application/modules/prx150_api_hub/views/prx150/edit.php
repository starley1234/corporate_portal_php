<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx150 — правка ключа БЕЗ отзыва: имя, скоупы, rate-limit, срок, заметка,
 * файловые лимиты. key_hash/key_prefix не меняются — потребителю ничего
 * передавать заново не нужно. Отзыв остаётся отдельной кнопкой в списке.
 */

?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['API-ключи', '/prx150_api_hub'], ['Правка']],
        'title'  => 'Ключ «'.HTML::chars($key['name']).'» — правка',
        'pill'   => ['prx150 · API-доступ', 'fa-key'],
        'lead'   => 'Значение ключа и его префикс не меняются — потребителю ничего обновлять не нужно. Пустое поле файлового лимита = безлимит по ключу (глобальные лимиты модуля остаются в силе).',
        'actions' => [
            ['label' => 'К ключам', 'href' => '/prx150_api_hub', 'icon' => 'fa-arrow-left', 'role' => 'secondary'],
        ],
    ]); ?>

    <?php if ((string) $flash !== ''): ?>
    <div class="prx-flash prx-flash-info mt-3" role="status"><i class="fas fa-circle-info shrink-0"></i><span><?php echo HTML::chars($flash); ?></span></div>
    <?php endif; ?>

    <form method="post" action="/prx150_api_hub/save/<?php echo (int) $key['id']; ?>" autocomplete="off" class="mt-5 max-w-3xl space-y-4">

        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

        <div class="prx-card p-4">
            <label class="prx-label" for="k-name">Название</label>
            <input id="k-name" type="text" name="name" required maxlength="120" autocomplete="off" value="<?php echo HTML::chars((string) $key['name']); ?>"
                   class="prx-input mt-1.5 w-full">
        </div>

        <div class="prx-card p-4">
            <p class="prx-label">Права (scopes)</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <?php foreach ($scopes_all as $scope => $meta): ?>
                <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" name="scopes[]" value="<?php echo HTML::chars((string) $scope); ?>"
                           class="mt-0.5" <?php echo in_array((string) $scope, $scopes_have, TRUE) ? 'checked' : ''; ?>>
                    <span>
                        <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) ($meta['title'] ?? $scope)); ?></span>
                        <span class="block font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $scope); ?></span>
                        <span class="block text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars((string) ($meta['module'] ?? '')); ?><?php echo (string) ($meta['description'] ?? '') !== '' ? ' · '.HTML::chars((string) $meta['description']) : ''; ?></span>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="prx-hint mt-2">Изменения действуют с следующего запроса ключа.</p>
        </div>

        <div class="prx-card p-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="prx-label" for="k-rate">Запросов в минуту (1–600)</label>
                <input id="k-rate" type="number" name="rate_limit" min="1" max="600" autocomplete="off" value="<?php echo (int) $key['rate_limit_per_min']; ?>"
                       class="prx-input mt-1.5 w-full">
            </div>
            <div>
                <label class="prx-label" for="k-exp">Действует до (пусто — бессрочно)</label>
                <input id="k-exp" type="text" name="expires_at" autocomplete="off" value="<?php echo HTML::chars((string) ($key['expires_at'] ?? '')); ?>" placeholder="YYYY-MM-DD"
                       class="prx-input mt-1.5 w-full">
            </div>
        </div>

        <div class="prx-card p-4">
            <p class="prx-label">Файловые лимиты (безлимит = поле пусто)</p>
            <div class="mt-2 grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="prx-label text-xs" for="k-fbytes">Макс. размер файла, байт</label>
                    <input id="k-fbytes" type="number" name="max_file_bytes" min="0" step="1024" autocomplete="off"
                           value="<?php echo $limits['max_file_bytes'] !== NULL ? (int) $limits['max_file_bytes'] : ''; ?>"
                           placeholder="безлимит"
                           class="prx-input mt-1 w-full">
                </div>
                <div>
                    <label class="prx-label text-xs" for="k-fcount">Файлов в одном запросе</label>
                    <input id="k-fcount" type="number" name="max_files_per_request" min="0" autocomplete="off"
                           value="<?php echo $limits['max_files_per_request'] !== NULL ? (int) $limits['max_files_per_request'] : ''; ?>"
                           placeholder="безлимит"
                           class="prx-input mt-1 w-full">
                </div>
                <div>
                    <label class="prx-label text-xs" for="k-fmt">Разрешённые форматы (через запятую)</label>
                    <input id="k-fmt" type="text" name="allowed_formats" autocomplete="off" value="<?php echo HTML::chars(implode(', ', $limits['allowed_formats'])); ?>"
                           placeholder="pdf, png, jpg"
                           class="prx-input mt-1 w-full">
                </div>
            </div>
            <p class="prx-hint mt-2">Лимиты проверяет модуль-потребитель при приёме файла (prx450: размер и формат).</p>
        </div>

        <div class="prx-card p-4">
            <label class="prx-label" for="k-note">Заметка</label>
            <textarea id="k-note" name="note" rows="2" maxlength="500" autocomplete="off"
                      class="prx-input mt-1.5 w-full"><?php echo HTML::chars((string) ($key['note'] ?? '')); ?></textarea>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="prx-btn-cta"><i class="fas fa-floppy-disk mr-1.5"></i>Сохранить</button>
            <a href="/prx150_api_hub" class="prx-btn-secondary">Отмена</a>
        </div>
    </form>
</div>
