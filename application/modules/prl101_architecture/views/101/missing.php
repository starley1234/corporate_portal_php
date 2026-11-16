<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница-заглушка: HTML ещё не собран из YAML.
 * Показываем точную команду, а не «ошибка 500».
 */
?>
<div class="prl-ds w-full">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Платформа', '/'], ['Архитектура']],
        'title'  => 'Архитектура портала',
        'pill'   => ['Модуль PRL101', 'fa-sitemap'],
        'lead'   => 'Документация собирается из architecture/arch/*.yaml. Сейчас собранной страницы ещё нет.',
    ]); ?>

    <div class="prl-card">
        <h2 class="prl-h3">Собрать документацию</h2>
        <p class="prl-hint">Выполните в корне проекта:</p>
        <pre class="mt-3 overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-gray-100"><code>make architecture</code></pre>
        <p class="prl-hint mt-3">Команда проверит модель и положит <code>architecture/dist/architecture.html</code> и <code>.json</code>. После этого обновите страницу.</p>
    </div>

    <div class="prl-card mt-4">
        <h2 class="prl-h3">Что править</h2>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li><code>architecture/arch/meta.yaml</code> — заголовок, разделы и палитра.</li>
            <li><code>architecture/arch/architecture/*.yaml</code> — вкладки со схемами и таблицами.</li>
            <li><code>architecture/arch/decisions/*.yaml</code> — журнал архитектурных решений.</li>
        </ul>
    </div>
</div>
