<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Строка списка разборов: ТОВАР, а не «63/100 · нейтрально».
 *
 * Владелец (03.10.2026): «В БД ты должен хранить перечень товаров +
 * производитель с его составом — и показывать в разных списках (в моих
 * разборах, у админа и т.п.) разбор ТОВАРА, а не разбор с названием “63/100 ·
 * нейтрально”». Поэтому строка отвечает на вопросы человека: ЧТО за товар
 * (значок типа продукта + имя), КТО его сделал, КОГДА разбирали, ЧЕМ кончилось
 * (вердикт и оценка) и ЧТО важного (тезисы по составу).
 *
 * Партиал один на три списка (мои разборы, история админки, дашборд), потому что
 * строка везде одна и та же, а расхождения в списках — верный путь к спагетти.
 *
 * Ожидает:
 *   $item      — строка из Store::_list_sql() (или совместимая по полям);
 *   $row_extra — (необязательно) HTML-хвост строки: гостевая метка, ссылка на
 *                карточку товара, кнопка действия. Печатается перед значком
 *                вердикта, чтобы основная строка оставалась одинаковой;
 *   $flat      — (необязательно) без своей рамки: строка живёт внутри карточки
 *                (история админки, дашборд).
 *
 * Имя рабочее (придумано модулем) помечается значком: владелец просил «префикс,
 * чтобы было видно, что название придумано».
 */

$item = (array) $item;

$json = function ($value) {
    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode((string) $value, TRUE);

    return is_array($decoded) ? $decoded : [];
};

// Имя товара: карточка товара → название разбора → честное «без названия».
$name      = trim((string) ($item['product_name'] ?? ''));
$name_auto = Service_Nj646_Store::flag($item['product_name_auto'] ?? FALSE);

if ($name === '')
{
    $name      = trim((string) ($item['title'] ?? ''));
    $name_auto = Service_Nj646_Store::flag($item['title_auto'] ?? FALSE);
}

if ($name === '')
{
    $name      = 'Товар без названия';
    $name_auto = TRUE;
}

$kind_info = Service_Nj646_Label::product_kind_info(
    (string) ($item['product_kind_slug'] ?? '') !== ''
        ? (string) $item['product_kind_slug']
        : (string) ($item['product_kind'] ?? ''));

// Оценка и вердикт — итог последнего разбора ТОВАРА (список не пересчитывает
// состав заново); если товара нет (старая запись) — снимок самого разбора.
$verdict = (string) ($item['last_verdict'] ?? '');
$score   = (int) ($item['last_score'] ?? 0);

if ($verdict === '')
{
    $verdict = (string) ($item['verdict'] ?? 'neutral');
    $score   = (int) ($item['score'] ?? 0);
}

$verdict_class = [
    'useful'  => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    'neutral' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    'caution' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    'risky'   => 'bg-rose-50 text-rose-600 dark:bg-rose-900/20 dark:text-rose-300',
    'avoid'   => 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
];

$counts  = $json($item['counts'] ?? []);
$unknown = $json($item['unknown'] ?? []);
$risk    = (int) ($counts['risky'] ?? 0) + (int) ($counts['avoid'] ?? 0);

$notes = [];

// Тезисы — без падежных согласований: строка одна на все списки, а «1 незнакомых
// базе» читается как ошибка (склонения во вьюхах модуля не собираем).
if ($risk > 0)
{
    $notes[] = 'высокий риск: '.$risk;
}

if (count($unknown) > 0)
{
    $notes[] = 'незнакомых базе: '.count($unknown);
}

if ( ! $notes)
{
    $notes[] = 'спорных веществ не нашлось';
}

$producer = trim((string) ($item['producer_name'] ?? ''));
$brand    = trim((string) ($item['product_brand'] ?? ''));
$when     = Service_Nj646_Label::ago_ru((string) ($item['created_at'] ?? ''));

// Куда ведёт строка: сам разбор (там состав, вещества и предупреждения).
$href = '/label/analysis/'.(int) $item['id'];

$shell = ! empty($flat)
    ? 'flex items-center gap-3 py-2'
    : 'flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-xs transition hover:border-blue-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:hover:border-blue-600';
?>
<div class="<?php echo $shell; ?>">

    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"
          title="<?php echo HTML::chars($kind_info['title']); ?>">
        <i class="fas <?php echo HTML::chars($kind_info['icon']); ?>"></i>
    </span>

    <a href="<?php echo HTML::chars($href); ?>" class="min-w-0 flex-1">
        <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">
            <?php echo HTML::chars($name); ?>
            <?php if ($name_auto): ?>
                <span class="ml-1 rounded-full border border-dashed border-gray-300 px-1.5 py-0.5 align-middle text-xs font-normal text-gray-500 dark:border-gray-600 dark:text-gray-400"
                      title="Названия на этикетке не нашлось — модуль дал рабочее имя по типу продукта. Настоящее имя подставится, когда этикетка его даст или модель прочитает.">
                    имя рабочее
                </span>
            <?php endif; ?>
        </span>
        <span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">
            <?php echo HTML::chars($kind_info['title']); ?>
            <?php if ($producer !== ''): ?> · <?php echo HTML::chars($producer); ?><?php endif; ?>
            <?php if ($when !== ''): ?> · <?php echo HTML::chars($when); ?><?php endif; ?>
            · <?php echo HTML::chars(implode(', ', $notes)); ?>
        </span>
    </a>

    <?php if ( ! empty($row_extra)): ?>
        <span class="hidden shrink-0 sm:flex sm:items-center sm:gap-2"><?php echo $row_extra; ?></span>
    <?php endif; ?>

    <span class="shrink-0 whitespace-nowrap rounded-lg px-2.5 py-1 text-xs font-semibold <?php echo $verdict_class[$verdict] ?? $verdict_class['neutral']; ?>">
        <?php echo HTML::chars(Service_Nj646_Label::verdict_label($verdict)); ?>
        <?php if ($score > 0): ?><span class="font-normal opacity-70"><?php echo $score; ?></span><?php endif; ?>
    </span>

    <a href="<?php echo HTML::chars($href); ?>" class="shrink-0 text-gray-300 hover:text-blue-600 dark:text-gray-600 dark:hover:text-blue-400">
        <i class="fas fa-chevron-right text-xs"></i>
    </a>
</div>
