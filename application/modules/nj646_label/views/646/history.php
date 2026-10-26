<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * История разборов состава (админка, /nj646/admin/history).
 *
 * Владелец (03.10.2026): «добавь страницу для админа с перечнем что
 * анализировали (последние n записей)». До неё в админке были только счётчики:
 * что именно присылали люди — не видно.
 *
 * Показываем честно то, что человек прислал: сырой текст, найденные позиции,
 * незнакомые вещества, оценку и время. Полный текст и списки прячем в
 * <details> — браузер раскрывает их без JavaScript, страница остаётся лёгкой.
 *
 * Перелистывание — формой POST (offset/limit в теле): владелец просил не
 * таскать состояние админки в адресной строке, а страница обязана оставаться
 * рабочей без JS.
 */

$fmt = function ($n) {
    return number_format((float) $n, 0, ',', ' ');
};

$verdict_class = [
    'useful'  => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    'neutral' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    'caution' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    'risky'   => 'bg-rose-50 text-rose-600 dark:bg-rose-900/20 dark:text-rose-300',
    'avoid'   => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-200',
];

$kind_label = ['food' => 'еда', 'cosmetic' => 'косметика', 'both' => 'всё', 'auto' => 'состав'];

// jsonb-колонки драйвер может отдать и строкой, и готовым массивом: принимаем оба.
$json = function ($value) {
    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode((string) $value, TRUE);

    return is_array($decoded) ? $decoded : [];
};

$prev_offset = max(0, (int) $offset - (int) $limit);
$next_offset = (int) $offset + (int) $limit;
$shown_from  = $total > 0 ? (int) $offset + 1 : 0;
$shown_to    = min((int) $total, (int) $offset + count($rows));
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
        <div class="mb-5 rounded-xl border px-4 py-3 text-sm <?php echo $flash_kind === 'warn'
            ? 'border-amber-300/80 bg-amber-50/90 text-amber-900 dark:border-amber-800/80 dark:bg-amber-950/40 dark:text-amber-200'
            : 'border-blue-300/80 bg-blue-50/90 text-blue-900 dark:border-blue-800/80 dark:bg-blue-950/40 dark:text-blue-200'; ?>">
            <?php echo HTML::chars($flash); ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="n2j-h1">История разборов</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Что присылали на разбор: текст, оценка и найденные позиции. Записи анонимные — видно метку
                посетителя и время, персональных данных тут нет.
            </p>
        </div>
        <a href="/nj646/admin"
           class="n2j-btn-secondary n2j-btn-sm">
            ← в админку
        </a>
    </div>

    <!-- Управление списком: сколько показывать и листание. POST — без GET-параметров.
         Поле offset одно: его приносит нажатая кнопка листания, а «Показать»
         без offset означает первую страницу (так надёжнее, чем дублировать
         offset скрытым полем и кнопкой — какое из них доедет, зависит от браузера). -->
    <form method="post" action="/nj646/admin/history"
          class="n2j-card p-0 mt-4 flex flex-wrap items-center gap-3 px-4 py-3 text-xs">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
        <label class="inline-flex items-center gap-2 text-gray-600 dark:text-gray-300">
            показывать
            <select name="limit"
                    class="n2j-input n2j-select py-1 text-xs min-h-0 w-auto">
                <?php foreach ([10, 20, 30, 50, 100] as $option): ?>
                    <option value="<?php echo (int) $option; ?>" <?php echo (int) $option === (int) $limit ? 'selected' : ''; ?>>
                        <?php echo (int) $option; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"
                class="n2j-btn-process n2j-btn-sm">Показать</button>

        <span class="text-gray-400">
            <?php if ($total > 0): ?>
                показано <?php echo $fmt($shown_from); ?>–<?php echo $fmt($shown_to); ?> из <?php echo $fmt($total); ?>
            <?php else: ?>
                разборов пока не было
            <?php endif; ?>
        </span>

        <span class="ml-auto flex items-center gap-2">
            <?php if ($prev_offset < (int) $offset): ?>
                <button type="submit" name="offset" value="<?php echo (int) $prev_offset; ?>"
                        class="n2j-btn-secondary n2j-btn-sm">
                    ← свежее
                </button>
            <?php endif; ?>
            <?php if ($next_offset < (int) $total): ?>
                <button type="submit" name="offset" value="<?php echo (int) $next_offset; ?>"
                        class="n2j-btn-secondary n2j-btn-sm">
                    раньше →
                </button>
            <?php endif; ?>
        </span>
    </form>

    <?php if ( ! $rows): ?>
        <div class="n2j-card mt-4 text-sm text-gray-500 dark:text-gray-400">
            Разборов ещё не было — как только посетитель пришлёт состав, запись появится здесь.
        </div>
    <?php else: ?>
        <div class="mt-4 space-y-2">
            <?php foreach ($rows as $row):
                $items   = $json($row['items']);
                $unknown = $json($row['unknown']);
                $text    = trim(preg_replace('/\s+/u', ' ', (string) $row['raw_text']));
                $verdict = (string) $row['verdict'];

                // Строка — про ТОВАР (общий партиал со списком «Мои разборы»):
                // справа от неё админские подробности, которые бывают нужны
                // только модератору: метка посетителя, источник и карточка товара.
                $extra = '';

                if ((int) ($row['product_id'] ?? 0) > 0)
                {
                    $extra .= '<a href="/label/product/'.(int) $row['product_id'].'" class="rounded border border-gray-300 px-2 py-0.5 font-semibold text-gray-600 hover:border-blue-400 hover:text-blue-700 dark:border-gray-600 dark:text-gray-300">карточка товара</a>';
                }

                if ((int) ($row['producer_id'] ?? 0) > 0)
                {
                    $extra .= '<a href="/label/producer/'.(int) $row['producer_id'].'" class="rounded border border-gray-300 px-2 py-0.5 font-semibold text-gray-600 hover:border-blue-400 hover:text-blue-700 dark:border-gray-600 dark:text-gray-300">производитель</a>';
                }
                ?>
                <div class="n2j-card p-0 px-4 py-3">
                    <div class="border-b border-gray-100 pb-1 dark:border-gray-700">
                        <?php echo View::factory('646/list_row', ['item' => $row, 'flat' => TRUE, 'row_extra' => $extra]); ?>
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kind_label[(string) $row['kind']] ?? (string) $row['kind']); ?></span>
                        <span class="text-gray-400">·</span>
                        <span class="text-gray-500 dark:text-gray-400">
                            <?php echo $row['source'] === 'photo' ? 'фото этикетки' : 'текст'; ?>
                        </span>
                        <span class="text-gray-400">· <?php echo HTML::chars(substr((string) $row['created_at'], 0, 16)); ?></span>
                        <span class="text-gray-400">· метка <?php echo HTML::chars(mb_substr((string) $row['visitor'], 0, 10)); ?><?php echo (int) $row['user_id'] > 0 ? ' · пользователь #'.(int) $row['user_id'] : ' · аноним'; ?></span>
                        <span class="ml-auto flex items-center gap-2 text-gray-400">
                            <span>позиций: <?php echo count($items); ?><?php if ($unknown): ?> · новых: <?php echo count($unknown); ?><?php endif; ?></span>
                            <span class="rounded-full px-2 py-0.5 font-bold <?php echo $verdict_class[$verdict] ?? 'bg-gray-100 text-gray-600'; ?>">
                                <?php echo (int) $row['score']; ?>/100
                            </span>
                        </span>
                    </div>

                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-200"><?php echo HTML::chars(mb_substr($text, 0, 220)); ?><?php echo mb_strlen($text) > 220 ? '…' : ''; ?></p>

                    <?php if ($items || $unknown): ?>
                        <details class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                            <summary class="cursor-pointer select-none text-blue-600 hover:underline dark:text-blue-400">
                                подробности разбора
                            </summary>
                            <?php if ($items): ?>
                                <p class="mt-2"><span class="text-gray-400">Позиции:</span>
                                    <?php
                                    $names = [];

                                    foreach (array_slice($items, 0, 60) as $item)
                                    {
                                        $names[] = (string) (is_array($item) ? ($item['name_ru'] ?? $item['name'] ?? '') : $item);
                                    }

                                    echo HTML::chars(implode(', ', array_filter($names)));
                                    ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($unknown): ?>
                                <p class="mt-1 text-amber-700 dark:text-amber-300">
                                    <span class="text-gray-400">База не знала:</span> <?php echo HTML::chars(implode(', ', array_map('strval', $unknown))); ?>
                                </p>
                            <?php endif; ?>
                            <details class="mt-2">
                                <summary class="cursor-pointer select-none text-gray-500 hover:underline dark:text-gray-400">полный текст, как он пришёл</summary>
                                <p class="mt-2 whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-200"><?php echo HTML::chars((string) $row['raw_text']); ?></p>
                            </details>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
