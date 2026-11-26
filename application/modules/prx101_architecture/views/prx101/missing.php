<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница-заглушка: HTML ещё не собран из YAML.
 * Показываем точную команду, а не «ошибка 500».
 */
?>
<div class="prx-ds w-full">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Платформа', '/'], ['Архитектура']],
        'title'  => 'Архитектура платформы',
        'pill'   => ['Модуль PRX101', 'fa-sitemap'],
        'lead'   => 'Документация собирается из docs/architecture/model/*.yaml. Сейчас собранной страницы ещё нет.',
        'actions' => [['label' => 'Памятка программиста', 'href' => '/architecture/guide', 'icon' => 'fa-compass', 'role' => 'secondary']],
    ]); ?>

    <div class="prx-card">
        <h2 class="prx-h3">Собрать документацию</h2>
        <p class="prx-hint">Выполните в корне проекта:</p>
        <pre class="mt-3 overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100"><code>make architecture</code></pre>
        <p class="prx-hint mt-3">Команда проверит модель и положит <code>docs/architecture/dist/architecture.html</code> и <code>.json</code>. После этого обновите страницу.</p>
    </div>

    <div class="prx-card mt-4">
        <h2 class="prx-h3">Что править</h2>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li><code>docs/architecture/model/meta.yaml</code> — заголовок, разделы и палитра.</li>
            <li><code>docs/architecture/model/architecture/*.yaml</code> — вкладки со схемами и таблицами.</li>
            <li><code>docs/architecture/model/decisions/*.yaml</code> — журнал архитектурных решений.</li>
        </ul>
    </div>
</div>
