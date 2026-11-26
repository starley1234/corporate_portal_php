<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx400_specflow/catalog — справочник НСИ (MVP): список + наполнение.
 *
 * Правильное место справочника — отдельный модуль НСИ/MDM (общий для
 * сервисов); до его появления позиции ведутся здесь и матчатся при разборе.
 *
 * Переменные: $catalog, $flash, $csrf.
 */
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Оцифровка спецификаций', '/prx400_specflow'], ['Справочник НСИ']],
        'title' => 'Справочник НСИ',
        'pill' => ['prx400 · номенклатура', 'fa-book'],
        'lead' => 'Коды номенклатуры для матчинга позиций: чем полнее справочник, тем больше строк зелёных без вашего участия. Совпадение по наименованию и ключевым словам — нечёткое.',
    ]); ?>

    <?php echo View::factory('partials/flash', ['flash' => $flash]); ?>

    <div class="prx-seg mt-5" role="tablist" aria-label="Разделы оцифровки спецификаций">
        <a class="prx-seg-item" role="tab" aria-selected="false" href="/prx400_specflow"><i class="fas fa-file-pdf mr-1.5"></i>Спецификации</a>
        <a class="prx-seg-item is-active" role="tab" aria-selected="true" href="/prx400_specflow/catalog"><i class="fas fa-book mr-1.5"></i>Справочник НСИ <span class="prx-count"><?php echo count($catalog); ?></span></a>
    </div>

    <div class="mt-5 gap-5 space-y-5 lg:grid lg:grid-cols-3 lg:items-start lg:space-y-0">
        <section class="prx-card lg:col-span-2 overflow-x-auto p-0">
            <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
                <h2 class="prx-h3">Позиции справочника <span class="prx-count ml-1"><?php echo count($catalog); ?></span></h2>
            </div>
<?php if (empty($catalog)): ?>
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Справочник пуст — все позиции спецификаций будут помечены «новая». Добавьте первую запись формой справа.</p>
<?php else: ?>
            <table class="prx-table">
                <thead><tr><th>Код</th><th>Наименование</th><th>Ед.</th><th>ОКЕИ</th><th>Ключевые слова</th><th></th></tr></thead>
                <tbody>
<?php foreach ($catalog as $c): ?>
                <tr>
                    <td class="font-mono text-xs font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($c['code']); ?></td>
                    <td class="max-w-[360px]"><span class="block text-sm text-gray-900 dark:text-white"><?php echo HTML::chars($c['name']); ?></span></td>
                    <td><?php echo HTML::chars($c['unit']); ?></td>
                    <td class="font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $c['okei_code']); ?></td>
                    <td class="max-w-[200px]"><span class="block truncate text-xs text-gray-400" title="<?php echo HTML::chars((string) $c['keywords']); ?>"><?php echo HTML::chars((string) $c['keywords']); ?></span></td>
                    <td class="text-right">
                        <form method="post" action="/prx400_specflow/catalog_delete/<?php echo (int) $c['id']; ?>" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <button type="submit" class="prx-btn-danger prx-btn-sm" data-prx-arm="Удалить запись справочника? Двойное нажатие."><i class="fas fa-trash"></i><span class="sr-only">Удалить</span></button>
                        </form>
                    </td>
                </tr>
<?php endforeach; ?>
                </tbody>
            </table>
<?php endif; ?>
        </section>

        <aside class="prx-card">
            <h2 class="prx-h3">Добавить позицию</h2>
            <form method="post" action="/prx400_specflow/catalog" class="mt-4 space-y-3">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <label class="block">
                    <span class="prx-label">Код номенклатуры *</span>
                    <input class="prx-input mt-1 w-full font-mono" name="code" maxlength="64" required placeholder="NSI-0001">
                </label>
                <label class="block">
                    <span class="prx-label">Наименование *</span>
                    <input class="prx-input mt-1 w-full" name="name" maxlength="500" required placeholder="Гайка шестигранная М12 ГОСТ 5915-70">
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="prx-label">Ед. изм.</span>
                        <input class="prx-input mt-1 w-full" name="unit" maxlength="16" value="шт.">
                    </label>
                    <label class="block">
                        <span class="prx-label">Код ОКЕИ</span>
                        <input class="prx-input mt-1 w-full font-mono" name="okei_code" maxlength="8" placeholder="796">
                    </label>
                </div>
                <label class="block">
                    <span class="prx-label">Ключевые слова</span>
                    <input class="prx-input mt-1 w-full" name="keywords" maxlength="500" placeholder="крепёж метиз гайка м12">
                    <span class="prx-hint mt-1">Повышают шансы матча: сокращения, синонимы, ГОСТ-марки.</span>
                </label>
                <button class="prx-btn-cta w-full justify-center" type="submit"><i class="fas fa-plus"></i>Добавить в справочник</button>
            </form>
            <p class="prx-hint mt-3">Матчинг нечёткий: «Гайка М12 ГОСТ 5915-70 цинк» найдёт «Гайка шестигранная М12…». ≥92% — автоматическая привязка, 65–92% — на подтверждение оператору.</p>
        </aside>
    </div>
</div>
