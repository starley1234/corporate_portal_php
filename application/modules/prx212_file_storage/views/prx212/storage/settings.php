<?php defined('SYSPATH') or die('No direct script access.'); ?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Файловое хранилище', '/prx212_file_storage'], ['Настройки']],
        'title' => 'Настройки хранилища',
        'lead' => 'Действующие параметры записи файлов. Страница только для просмотра.',
        'actions' => [['label' => 'К списку файлов', 'href' => '/prx212_file_storage', 'icon' => 'fa-folder-open']],
    ]); ?>

    <section class="prx-panel mt-6">
        <div class="prx-panel-head"><h2 class="prx-h3">Активные хранилища</h2></div>
        <?php if ( ! $backends): ?>
            <p class="p-5" role="alert">Нет активного хранилища. Загрузка файлов недоступна.</p>
        <?php else: ?>
            <div class="space-y-4 p-5">
                <?php foreach ($backends as $backend): ?>
                    <div class="prx-card">
                        <h3 class="prx-h3">
                            <?php echo HTML::chars(strtoupper($backend['driver'])); ?> #<?php echo $backend['id']; ?>
                            <?php if ($backend['default']): ?><span class="prx-badge prx-badge-info">По умолчанию</span><?php endif; ?>
                        </h3>
                        <p class="mt-2 break-all">
                            <?php echo $backend['driver'] === 'local' ? 'Папка' : 'Бакет'; ?>:
                            <code><?php echo HTML::chars($backend['invalid_path'] ? 'недопустимый путь' : ($backend['destination'] ?: 'не задан')); ?></code>
                        </p>
                        <?php if ($backend['source']): ?><p class="prx-hint mt-1"><?php echo HTML::chars($backend['source']); ?></p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="prx-panel mt-6">
        <div class="prx-panel-head"><h2 class="prx-h3">Ограничения загрузки</h2></div>
        <div class="space-y-4 p-5">
            <p>Лимит хранилища: <strong><?php echo Text::bytes($max_file_size, ''); ?></strong></p>
            <p>PHP: файл — <code><?php echo HTML::chars($php_upload_limit); ?></code>, POST — <code><?php echo HTML::chars($php_post_limit); ?></code></p>
            <div>
                <p class="prx-label mb-2">Разрешённые MIME-типы</p>
                <div class="flex flex-wrap gap-1">
                    <?php foreach ($allowed_types as $mime): ?>
                        <span class="prx-count"><?php echo HTML::chars($mime); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
</div>
