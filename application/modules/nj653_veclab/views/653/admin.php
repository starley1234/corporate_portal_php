<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Админка «Лаборатории векторов»: вся коллекция, отбор и массовая чистка.
 * Все действия — POST + CSRF (правило платформы: GET ничего не меняет).
 *
 * @var array  $stats    хранилище: pgvector, размерность, всего элементов
 * @var array  $filter   текущий отбор (visitor, kind, model, search, older_days)
 * @var array  $items    элементы по отбору
 * @var int    $total    сколько всего попадает под отбор
 * @var array  $visitors сводка по посетителям
 * @var array  $models   какими моделями считались векторы
 * @var array  $dupes    пары похожих элементов
 * @var float  $eps      порог «это копия» (косинусная дистанция)
 * @var array  $kinds    виды элементов
 * @var string $flash    сообщение после действия
 * @var string $csrf     токен формы
 */

$hidden = function (array $filter, $csrf) { ?>
    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
    <?php foreach (['visitor', 'kind', 'model', 'search', 'older_days'] as $key): ?>
        <?php if ((string) ($filter[$key] ?? '') !== '' AND (string) $filter[$key] !== '0'): ?>
            <input type="hidden" name="<?php echo $key; ?>" value="<?php echo HTML::chars((string) $filter[$key]); ?>">
        <?php endif; ?>
    <?php endforeach; ?>
<?php };
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
        <div class="n2j-flash n2j-flash-info mb-5 block">
            <i class="fas fa-circle-info mr-1"></i><?php echo HTML::chars($flash); ?>
        </div>
    <?php endif; ?>

    <h1 class="n2j-h1">Лаборатория векторов · коллекция</h1>
    <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
        Здесь видно всё, что посетители сложили в общее хранилище: подпись, вид, модель и паспорт
        исходной картинки. Отберите нужное и уберите пачкой — копии ловятся по вектору, а не только
        по одинаковому файлу.
    </p>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $cards = [
            ['Элементов всего', (int) ($stats['total'] ?? 0), 'в таблице nj_653_items'],
            ['Под отбором', (int) $total, 'показываем до 60'],
            ['Размерность', (int) ($stats['dims'] ?? 0), ! empty($stats['pgvector']) ? 'pgvector' : 'режим real[]'],
            ['Похожих пар', count($dupes), 'порог '.number_format((float) $eps, 3, ',', ' ')],
        ];
        foreach ($cards as $card): ?>
            <div class="n2j-card">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo $card[0]; ?></div>
                <div class="mt-1 text-xl font-bold text-gray-900 dark:text-white"><?php echo HTML::chars(is_int($card[1]) ? number_format($card[1], 0, ',', ' ') : $card[1]); ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($card[2]); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ============================ ОТБОР ============================ -->
    <form method="get" action="/veclab/admin"
          class="n2j-card mt-6">
        <h2 class="n2j-h3">Отбор</h2>

        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <label class="block">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Посетитель</span>
                <input type="text" name="visitor" value="<?php echo HTML::chars((string) $filter['visitor']); ?>"
                       placeholder="метка браузера"
                       class="n2j-input mt-1 w-full">
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Вид</span>
                <select name="kind"
                        class="n2j-input n2j-select mt-1 w-full">
                    <option value="">любой</option>
                    <?php foreach ($kinds as $code => $title): ?>
                    <option value="<?php echo HTML::chars($code); ?>" <?php echo $filter['kind'] === $code ? 'selected' : ''; ?>><?php echo HTML::chars($title); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Модель</span>
                <select name="model"
                        class="n2j-input n2j-select mt-1 w-full">
                    <option value="">любая</option>
                    <?php foreach ($models as $model): ?>
                    <option value="<?php echo HTML::chars((string) $model['model']); ?>" <?php echo $filter['model'] === (string) $model['model'] ? 'selected' : ''; ?>>
                        <?php echo HTML::chars(mb_substr((string) $model['model'], 0, 40)); ?> (<?php echo (int) $model['c']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Поиск по подписи</span>
                <input type="text" name="search" value="<?php echo HTML::chars((string) $filter['search']); ?>"
                       class="n2j-input mt-1 w-full">
            </label>

            <label class="block">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Старше, дней</span>
                <input type="number" name="older_days" min="0" max="3650" value="<?php echo (int) $filter['older_days']; ?>"
                       class="n2j-input mt-1 w-full">
            </label>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="submit" class="n2j-btn-process">
                <i class="fas fa-filter mr-1"></i>Показать
            </button>
            <a href="/veclab/admin" class="n2j-btn-secondary">
                сбросить
            </a>
        </div>
    </form>

    <!-- ===================== МАССОВЫЕ ОПЕРАЦИИ ===================== -->
    <div class="mt-4 flex flex-wrap items-end gap-2">
        <form method="post" action="/veclab/admin/delete_filtered">
            <?php $hidden($filter, $csrf); ?>
            <button data-n2j-confirm="Убрать всё, что попадает под отбор?" data-n2j-tone="danger" data-n2j-ok="Убрать" type="submit" class="n2j-btn-danger">
                <i class="fas fa-broom mr-1"></i>Убрать всё по отбору (<?php echo (int) $total; ?>)
            </button>
        </form>

        <form method="post" action="/veclab/admin/delete_duplicates" class="flex items-end gap-2">
            <?php $hidden($filter, $csrf); ?>
            <label class="block">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">порог копии</span>
                <input type="number" name="eps" step="0.001" min="0.001" max="0.2" value="<?php echo HTML::chars(number_format((float) $eps, 3, '.', '')); ?>"
                       class="n2j-input mt-1 w-24">
            </label>
            <button data-n2j-confirm="Убрать копии, оставив по одному элементу из группы?" data-n2j-tone="danger" data-n2j-ok="Убрать копии" type="submit" class="n2j-btn-danger">
                <i class="fas fa-clone mr-1"></i>Убрать копии (<?php echo count($dupes); ?>)
            </button>
        </form>

        <form method="post" action="/veclab/admin/truncate" class="flex items-end gap-2">
            <?php $hidden($filter, $csrf); ?>
            <label class="block">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">подтверждение</span>
                <input type="text" name="confirm" placeholder="да"
                       class="n2j-input mt-1 w-24">
            </label>
            <button data-n2j-confirm="Стереть коллекцию целиком? Это необратимо." data-n2j-tone="danger" data-n2j-ok="Стереть" type="submit" class="n2j-btn-danger">
                <i class="fas fa-trash mr-1"></i>Очистить коллекцию
            </button>
        </form>
    </div>

    <!-- ========================= СПИСОК ========================= -->
    <form method="post" action="/veclab/admin/delete"
          class="n2j-card mt-6">
        <?php $hidden($filter, $csrf); ?>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="n2j-h3">
                Элементы: показано <?php echo count($items); ?> из <?php echo (int) $total; ?>
            </h2>
            <div class="flex flex-wrap gap-2">
                <button type="button" data-nj653-check-all
                        class="n2j-btn-secondary n2j-btn-sm">
                    отметить все
                </button>
                <button data-n2j-confirm="Убрать отмеченные элементы?" data-n2j-tone="danger" data-n2j-ok="Убрать" type="submit"
                        class="n2j-btn-danger">
                    <i class="fas fa-trash mr-1"></i>Убрать отмеченные
                </button>
            </div>
        </div>

        <?php if ( ! $items): ?>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Под этот отбор ничего не попало.</p>
        <?php else: ?>
        <div class="mt-3 overflow-x-auto">
            <table class="n2j-table min-w-[720px]">
                <thead class="text-xs uppercase tracking-wide text-gray-400">
                    <tr>
                        <th class="px-2 py-2"></th>
                        <th class="px-2 py-2">Элемент</th>
                        <th class="px-2 py-2">Вид</th>
                        <th class="px-2 py-2">Посетитель</th>
                        <th class="px-2 py-2">Модель</th>
                        <th class="px-2 py-2">Когда</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $about = Service_Nj653_Veclab::about($item['meta'] ?? NULL); ?>
                    <tr class="border-t border-gray-100 align-top dark:border-gray-700">
                        <td class="px-2 py-2">
                            <input type="checkbox" name="ids[]" value="<?php echo (int) $item['id']; ?>" data-nj653-check
                                   class="h-4 w-4 rounded border-gray-300 text-blue-600 dark:border-gray-600">
                        </td>
                        <td class="px-2 py-2">
                            <div class="flex items-start gap-2">
                                <?php $preview = Service_Nj653_Veclab::safe_preview((string) ($item['preview'] ?? '')); ?>
                                <?php if ($preview !== ''): ?>
                                <img src="<?php echo HTML::chars($preview); ?>" alt="Элемент коллекции"
                                     class="h-10 w-10 shrink-0 rounded-md border border-gray-200 object-cover dark:border-gray-700">
                                <?php endif; ?>
                                <div class="min-w-0">
                                    <div class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars(mb_substr((string) $item['label'], 0, 80)); ?></div>
                                    <?php if ($about !== ''): ?>
                                    <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($about); ?></div>
                                    <?php endif; ?>
                                    <div class="text-xs text-gray-400">#<?php echo (int) $item['id']; ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-2 py-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) ($kinds[(string) $item['kind']] ?? $item['kind'])); ?></td>
                        <td class="px-2 py-2">
                            <a href="/veclab/admin?visitor=<?php echo urlencode((string) $item['visitor']); ?>"
                               class="font-mono text-xs text-blue-700 hover:underline dark:text-blue-300"><?php echo HTML::chars(mb_substr((string) $item['visitor'], 0, 12)); ?></a>
                        </td>
                        <td class="px-2 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $item['model'], 0, 28)); ?></td>
                        <td class="px-2 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $item['created_at'], 0, 16)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </form>

    <!-- ===================== ПОХОЖИЕ ПАРЫ ===================== -->
    <div class="n2j-card mt-6">
        <h2 class="n2j-h3">Похожие пары</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            Одинаковый файл ловится отпечатком и в коллекцию просто не попадает. Здесь — то, что
            отпечаток не видит: та же картинка, пересохранённая в другом качестве или размере.
            «Убрать копии» оставляет в каждой группе самый ранний элемент.
        </p>

        <?php if ( ! $dupes): ?>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Пар ближе порога не нашлось.</p>
        <?php else: ?>
        <ul class="mt-3 space-y-2">
            <?php foreach ($dupes as $pair): ?>
            <li class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 p-2.5 text-sm dark:border-gray-700">
                <span class="n2j-badge n2j-badge-run">
                    близость <?php echo number_format(1 - (float) $pair['distance'], 4, ',', ' '); ?>
                </span>
                <span class="text-gray-700 dark:text-gray-200">#<?php echo (int) $pair['keep_id']; ?> <?php echo HTML::chars(mb_substr((string) $pair['keep_label'], 0, 40)); ?></span>
                <i class="fas fa-arrow-right text-xs text-gray-400"></i>
                <span class="text-gray-500 line-through dark:text-gray-400">#<?php echo (int) $pair['drop_id']; ?> <?php echo HTML::chars(mb_substr((string) $pair['drop_label'], 0, 40)); ?></span>
                <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars(mb_substr((string) $pair['visitor'], 0, 12)); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- ===================== ПОСЕТИТЕЛИ ===================== -->
    <div class="n2j-card mt-6">
        <h2 class="n2j-h3">Кто сколько накопил</h2>

        <?php if ( ! $visitors): ?>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Коллекция пуста.</p>
        <?php else: ?>
        <div class="mt-3 overflow-x-auto">
            <table class="n2j-table min-w-[640px]">
                <thead class="text-xs uppercase tracking-wide text-gray-400">
                    <tr>
                        <th class="px-2 py-2">Посетитель</th>
                        <th class="px-2 py-2">Элементов</th>
                        <th class="px-2 py-2">Из них с картинкой</th>
                        <th class="px-2 py-2">Последний</th>
                        <th class="px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($visitors as $row): ?>
                    <tr class="border-t border-gray-100 dark:border-gray-700">
                        <td class="px-2 py-2 font-mono text-xs"><?php echo HTML::chars(mb_substr((string) $row['visitor'], 0, 16)); ?></td>
                        <td class="px-2 py-2"><?php echo (int) $row['items']; ?></td>
                        <td class="px-2 py-2"><?php echo (int) $row['images']; ?></td>
                        <td class="px-2 py-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $row['last_at'], 0, 16)); ?></td>
                        <td class="px-2 py-2">
                            <div class="flex flex-wrap gap-2">
                                <a href="/veclab/admin?visitor=<?php echo urlencode((string) $row['visitor']); ?>"
                                   class="n2j-btn-secondary n2j-btn-sm">показать</a>
                                <a href="/veclab/map?visitor=<?php echo urlencode((string) $row['visitor']); ?>"
                                   class="n2j-btn-secondary n2j-btn-sm">карта</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    'use strict';

    var toggle = document.querySelector('[data-nj653-check-all]');

    if (!toggle) { return; }

    toggle.addEventListener('click', function () {
        var boxes = document.querySelectorAll('[data-nj653-check]');
        var on = toggle.getAttribute('data-on') !== '1';

        Array.prototype.forEach.call(boxes, function (box) { box.checked = on; });

        toggle.setAttribute('data-on', on ? '1' : '0');
        toggle.textContent = on ? 'снять отметки' : 'отметить все';
    });
})();
</script>
