<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Страница разбора (/label/analysis/<id>).
 *
 * Владелец (03.10.2026): «не хватает страницы разбора». До неё разбор жил
 * только в ответе API и строкой в «Моих разборах» — вернуться к нему,
 * показать близким или прогнать заново было нечем.
 *
 * ПОЧЕМУ СТРАНИЦА ПОЛЕЗНА ДАЖЕ ЧЕРЕЗ МЕСЯЦ. Показываем не сохранённый снимок,
 * а сегодняшнее состояние базы: `$cards` — актуальные карточки позиций (их
 * могли изучить, поправить или скрыть после разбора), `$unknown` — что стало с
 * теми веществами, которых база не знала (изучены — со ссылкой на карточку,
 * изучаются, убраны как мусор). Совет пересчитан по актуальным карточкам,
 * поэтому страница не пугает тем, что давно разобрано, и не молчит о том,
 * что изменилось.
 *
 * ВИДНО НЕ ВСЕМ: контроллер пускает владельца разбора (метка браузера или
 * вход) и админа, остальным отдаёт 404. Поэтому и в тексте страницы написано,
 * что разбор привязан к браузеру.
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

$risk_dot = ['bg-emerald-500', 'bg-gray-400', 'bg-amber-500', 'bg-rose-400', 'bg-rose-600'];

$advice_style = [
    'danger' => 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900/60 dark:bg-rose-900/20 dark:text-rose-200',
    'warn'   => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-200',
    'info'   => 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/60 dark:bg-blue-900/20 dark:text-blue-200',
    'good'   => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-900/20 dark:text-emerald-200',
];

$conds = array_filter(array_map('strval', (array) ($row['profile']['conditions'] ?? [])));
// Оценка на СЕГОДНЯ: контроллер пересчитал её по актуальным карточкам, поэтому
// на странице видно, что разбор уточнён, если база пополнилась после него.
$verdict = (string) ($verdict_now ?? $row['verdict']);
$score   = (int) ($score_now ?? $row['score']);
$refined = ! empty($refined);
$studies = (array) ($studies ?? []);
$about   = (array) ($row['about'] ?? []);
$transcript = trim((string) ($about['transcript'] ?? ''));
$text    = (string) $row['raw_text'];
// След обрезки на записи (см. `Store::analysis_save`): текст длиннее предела
// хранится не целиком, и человек должен это видеть, а не догадываться.
$cut_raw = (array) ($about['cut']['raw_text'] ?? []);
$known   = 0;
$waiting = 0;

foreach ((array) $row['unknown'] as $name)
{
    if (isset($unknown[$name]) AND (string) $unknown[$name]['status'] === 'studied')
    {
        $known++;
    }
    else
    {
        $waiting++;
    }
}
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="n2j-hint">
                <a href="/label" class="hover:underline">Разбор состава</a> ·
                разбор №<?php echo (int) $row['id']; ?>
                <?php if ($admin): ?>
                    · <a href="/nj646/admin/history" class="text-blue-600 hover:underline dark:text-blue-400">вся история</a>
                <?php endif; ?>
            </p>
            <?php $tovar = (array) ($tovar ?? ['id' => 0, 'name' => (string) $row['title'], 'name_auto' => FALSE]); ?>
            <h1 class="n2j-h1 mt-1">
                <?php echo HTML::chars($tovar['name'] !== '' ? $tovar['name'] : 'Разбор состава'); ?>
                <?php if ( ! empty($tovar['name_auto'])): ?>
                    <span class="ml-1 align-middle rounded-full border border-dashed border-gray-300 px-2 py-0.5 text-xs font-normal text-gray-500 dark:border-gray-600 dark:text-gray-400"
                          title="Названия на этикетке не нашлось — модуль дал рабочее имя по типу продукта. Настоящее имя подставится, когда этикетка его даст или модель прочитает.">
                        имя рабочее
                    </span>
                <?php endif; ?>
            </h1>
            <p class="n2j-hint">
                <i class="<?php echo HTML::chars($kind_info['icon']); ?> mr-1"></i><?php echo HTML::chars($kind_info['title']); ?>
                <?php if ( ! empty($tovar['producer'])): ?>
                    · <?php if ( ! empty($tovar['producer_id'])): ?><a href="/label/producer/<?php echo (int) $tovar['producer_id']; ?>" class="hover:underline"><?php echo HTML::chars($tovar['producer']); ?></a><?php else: ?><?php echo HTML::chars($tovar['producer']); ?><?php endif; ?>
                <?php endif; ?>
                · <?php echo HTML::chars((string) ($kind_labels[(string) $row['kind']] ?? (string) $row['kind'])); ?>
                · <?php echo $row['source'] === 'photo' ? 'фото этикетки' : 'состав текстом'; ?>
                · <?php echo HTML::chars(Service_Nj646_Label::ago_ru((string) $row['created_at'])); ?>
                <?php if ( ! empty($tovar['id'])): ?>
                    · <a href="/label/product/<?php echo (int) $tovar['id']; ?>" class="hover:underline">карточка товара</a>
                <?php endif; ?>
            </p>
        </div>

        <div class="flex flex-wrap gap-2 text-xs">
            <form method="post" action="/label/replay/<?php echo (int) $row['id']; ?>">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <button type="submit"
                        class="n2j-btn-process">
                    <i class="fas fa-rotate-right mr-1"></i>разобрать заново
                </button>
            </form>
            <a href="/label"
               class="n2j-btn-secondary">
                новый разбор
            </a>
            <?php if ($admin): ?>
                <a href="/nj646/admin/catalog"
                   class="n2j-btn-secondary">
                    справочник
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================ ИТОГ ============================ -->
    <div class="n2j-card mt-4">
        <div class="flex flex-wrap items-center gap-5">
            <div class="flex h-20 w-20 shrink-0 flex-col items-center justify-center rounded-full border-4 border-gray-100 dark:border-gray-700">
                <span class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $score; ?></span>
                <span class="text-xs uppercase text-gray-400">из 100</span>
            </div>
            <div class="min-w-0">
                <span class="rounded-full px-3 py-1 text-xs font-bold <?php echo $verdict_class[$verdict] ?? $verdict_class['neutral']; ?>">
                    <?php echo HTML::chars(Service_Nj646_Label::verdict_label($verdict)); ?>
                </span>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    <?php echo HTML::chars(Service_Nj646_Label::score_label($score)); ?>
                    <?php if ($refined): ?>
                        <span class="n2j-badge n2j-badge-info ml-1"
                              title="База пополнилась после разбора — здесь показана оценка по сегодняшним карточкам (в истории разбора осталось <?php echo (int) $row['score']; ?>/100)">
                            уточнено: было <?php echo (int) $row['score']; ?>/100
                        </span>
                    <?php endif; ?>
                </p>
                <p class="n2j-hint">
                    позиций в составе: <?php echo count((array) $row['items']) + count((array) $row['unknown']); ?>
                    · базе знакомы <?php echo count((array) $row['items']); ?>
                    <?php if (count((array) $row['unknown'])): ?>
                        · из незнакомых разобрано <?php echo (int) $known; ?> из <?php echo (int) ($known + $waiting); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php
        // Шкала-градиент: где разбор стоит среди пяти градаций.
        //
        // Владелец (03.10.2026): «наглядный ответ полезен продукт или нет и
        // возможность пролистать информацию о градиентах» — вот эта шкала:
        // слева «полезный», справа «лучше избегать», текущая ступень подсвечена.
        // Подписи-ступени появляются при наведении (title) — полоса остаётся
        // одной строкой и не спорит с кругом оценки.
        $scale = [
            'useful'  => ['полезный', 'bg-emerald-500'],
            'neutral' => ['умеренный', 'bg-gray-400'],
            'caution' => ['спорный', 'bg-amber-500'],
            'risky'   => ['много поводов', 'bg-rose-400'],
            'avoid'   => ['лучше избегать', 'bg-rose-600'],
        ];
        ?>
        <div class="mt-4">
            <?php // Шкала — ТОЧКАМИ: полосы убраны везде (владелец, 03.10.2026). ?>
            <div class="flex items-center gap-2">
                <?php foreach ($scale as $step_key => $step): ?>
                    <span class="h-2.5 w-2.5 rounded-full <?php echo $step_key === $verdict ? $step[1] : 'bg-gray-200 dark:bg-gray-700'; ?>"
                          title="<?php echo HTML::chars($step[0]); ?>"></span>
                <?php endforeach; ?>
            </div>
            <div class="mt-1 flex justify-between text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
                <span>полезный</span>
                <span class="font-bold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($scale[$verdict][0] ?? ''); ?></span>
                <span>лучше избегать</span>
            </div>
        </div>

        <?php if ($advice): ?>
            <div class="mt-4 space-y-2">
                <?php foreach ($advice as $line): ?>
                    <div class="rounded-lg border px-3 py-2 text-sm <?php echo $advice_style[(string) ($line['level'] ?? 'info')] ?? $advice_style['info']; ?>">
                        <span class="font-semibold"><?php echo HTML::chars((string) ($line['title'] ?? '')); ?>.</span>
                        <?php echo HTML::chars((string) ($line['text'] ?? '')); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($conds OR (string) ($row['profile']['skin'] ?? '') !== '' OR (string) ($row['profile']['diet'] ?? 'none') !== 'none'): ?>
            <p class="n2j-hint mt-3">
                Совет считался с вашим профилем:
                <?php
                $bits = [];

                if ((string) ($row['profile']['skin'] ?? '') !== '')
                {
                    $bits[] = 'кожа — '.HTML::chars((string) $row['profile']['skin']);
                }

                if ((string) ($row['profile']['diet'] ?? 'none') !== 'none')
                {
                    $bits[] = 'питание — '.HTML::chars((string) $row['profile']['diet']);
                }

                if ($conds)
                {
                    $bits[] = 'ограничения — '.HTML::chars(implode(', ', $conds));
                }

                echo implode(' · ', $bits);
                ?>.
                <a href="/label/profile" class="text-blue-600 hover:underline dark:text-blue-400">изменить профиль</a>
            </p>
        <?php endif; ?>
    </div>

    <!-- ============================ ПОЗИЦИИ ============================ -->
    <div class="n2j-card mt-4">
        <h2 class="n2j-h3">Что в составе</h2>
        <p class="n2j-hint">
            Показаны карточки на сегодня: если вещество изучили или поправили после разбора, здесь уже новое описание.
        </p>

        <?php if ( ! $row['items']): ?>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Ни одной знакомой позиции — состав ниже, в полном тексте.</p>
        <?php else: ?>
            <div class="mt-3 space-y-2">
                <?php foreach ((array) $row['items'] as $item):
                    $slug = is_array($item) ? (string) ($item['slug'] ?? '') : '';
                    $card = $cards[$slug] ?? NULL;

                    // Карточка удалена после разбора: показываем снимок и честно
                    // помечаем, что актуального описания уже нет.
                    $name    = (string) ($card['name_ru'] ?? ($item['name'] ?? $slug));
                    $risk    = (int) ($card['risk'] ?? ($item['risk'] ?? 2));
                    $verdict_item = (string) ($card['verdict'] ?? ($item['verdict'] ?? 'neutral'));
                    $risk    = max(0, min(4, $risk));
                    ?>
                    <div class="flex items-start gap-3 rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-700">
                        <span class="mt-1.5 inline-block h-2.5 w-2.5 shrink-0 rounded-full <?php echo $risk_dot[$risk]; ?>"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline gap-2">
                                <?php if ($card): ?>
                                    <a href="/label/ingredient/<?php echo urlencode($slug); ?>"
                                       class="text-sm font-semibold text-gray-800 hover:text-blue-700 hover:underline dark:text-gray-100 dark:hover:text-blue-300">
                                        <?php echo HTML::chars($name); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($name); ?></span>
                                    <span class="rounded-full border border-gray-300 px-2 py-0.5 text-xs text-gray-500 dark:border-gray-600 dark:text-gray-400"
                                          title="Карточку удалили после разбора — показан снимок на момент разбора">карточка удалена</span>
                                <?php endif; ?>

                                <?php if ($card AND (string) $card['name_int'] !== ''): ?>
                                    <span class="text-xs text-gray-400"><?php echo HTML::chars((string) $card['name_int']); ?></span>
                                <?php endif; ?>

                                <?php if ($card AND (string) $card['e_code'] !== ''): ?>
                                    <span class="n2j-badge n2j-badge-wait font-mono">
                                        <?php echo HTML::chars((string) $card['e_code']); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($card AND (string) $card['status'] === 'draft'): ?>
                                    <span class="rounded-full border border-amber-300 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:border-amber-700 dark:text-amber-300"
                                          title="Карточку составил ИИ; редактор её ещё не проверял">изучено ИИ · не проверено</span>
                                <?php endif; ?>

                                <span class="ml-auto text-xs font-semibold <?php echo $verdict_class[$verdict_item] ?? ''; ?>">
                                    <?php echo HTML::chars(Service_Nj646_Label::verdict_label($verdict_item)); ?>
                                </span>
                            </div>

                            <?php if ($card AND (string) $card['summary'] !== ''): ?>
                                <p class="n2j-hint mt-0.5"><?php echo HTML::chars((string) $card['summary']); ?></p>
                            <?php endif; ?>

                            <?php if ($card AND $card['avoid']): ?>
                                <p class="mt-0.5 text-xs text-amber-600 dark:text-amber-400">
                                    Ограничения: <?php echo HTML::chars(implode(', ', array_map('strval', (array) $card['avoid']))); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($studies): ?>
            <!-- Изучение идёт прямо сейчас. Владелец (03.10.2026): «изучаемые
                 компоненты показывай как плитки (а не на всю ширину экрана), и не
                 показывай полосу прогресса — достаточно колёсика и секундомера».
                 Поэтому плитки в сетке и `compact`-ряд общего статус-механизма:
                 спиннер + название вещества + секундомер. Страница обновится сама,
                 когда задачи закончатся, — разбор станет полнее. -->
            <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($studies as $study): ?>
                    <div class="rounded-xl border border-blue-200 bg-blue-50/40 p-3 dark:border-blue-900/60 dark:bg-blue-950/20">
                        <?php echo View::factory('partials/live_status', [
                            'url'     => '/label/api/status?id='.(int) $study['run_id'],
                            'title'   => (string) $study['name'],
                            'status'  => 'queued',
                            'compact' => TRUE,
                        ]); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($row['unknown']): ?>
            <div class="n2j-flash n2j-flash-warn mt-4 block">
                <p class="font-semibold">База не знала <?php echo count((array) $row['unknown']); ?> позиций — на момент разбора</p>
                <ul class="mt-2 space-y-1 text-xs">
                    <?php foreach ((array) $row['unknown'] as $name):
                        $state  = (string) ($unknown[$name]['status'] ?? 'new');
                        $slug   = (string) ($unknown[$name]['slug'] ?? '');
                        ?>
                        <li class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold"><?php echo HTML::chars((string) $name); ?></span>
                            <?php if ($state === 'studied' AND $slug !== ''): ?>
                                <span class="n2j-badge n2j-badge-ok">изучено</span>
                                <a href="/label/ingredient/<?php echo urlencode($slug); ?>" class="font-semibold underline">открыть карточку</a>
                            <?php elseif ($state === 'queued'): ?>
                                <span class="n2j-badge n2j-badge-run">изучается</span>
                            <?php elseif ($state === 'ignored'): ?>
                                <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">убрано как мусор</span>
                            <?php else: ?>
                                <span class="n2j-badge n2j-badge-run">ещё не изучено</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($waiting > 0): ?>
                    <p class="mt-2 text-xs">
                        Нажмите «разобрать заново» — в развёрнутом режиме база изучит их и ответ станет полным.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== ТОВАР И ПРОИЗВОДИТЕЛЬ ====================
         Сопутствующая информация с этикетки сохраняется в общих таблицах
         проекта (Store::goods_save) и связана с онтологией предприятий и
         справочником организаций. Показываем и актуальные карточки, и снимок
         с этикетки: если карточку поправили позже — видно и то, и другое. -->
    <?php
    $goods    = Service_Nj646_Store::goods_view($row['product'] ?? NULL, $row['producer'] ?? NULL);
    $goods_v  = (! empty($goods['product']) OR ! empty($goods['producer'])) ? $goods : NULL;
    $about    = (array) ($row['about'] ?? []);
    $about_p = (array) ($about['product'] ?? []);
    $about_pr = (array) ($about['producers'] ?? []);
    ?>

    <?php if ($goods_v OR $about_p OR $about_pr): ?>
    <div class="n2j-card mt-4">
        <h2 class="n2j-h3">Товар и производитель</h2>
        <p class="n2j-hint">
            Разобрано из строк этикетки автоматически (без модели) и сохранено в общих таблицах проекта:
            производители связываются с онтологией предприятий и справочником организаций по ИНН/ОГРН.
        </p>

        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <?php if ($goods_v AND $goods_v['product']): ?>
                <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-700">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Товар</p>
                    <a href="<?php echo HTML::chars($goods_v['product']['url']); ?>"
                       class="mt-1 block break-words text-sm font-semibold text-blue-700 hover:underline dark:text-blue-300">
                        <?php echo HTML::chars($goods_v['product']['name'] !== '' ? $goods_v['product']['name'] : 'карточка товара'); ?>
                    </a>
                    <dl class="mt-2 space-y-1 text-xs">
                        <?php foreach ([
                            ['Бренд', $goods_v['product']['brand']],
                            ['Штрихкод', $goods_v['product']['barcode']],
                            ['Масса / объём', $goods_v['product']['weight']],
                            ['ГОСТ / ТУ', $goods_v['product']['gost']],
                            ['Срок годности', $goods_v['product']['shelf_life']],
                            ['Хранение', $goods_v['product']['storage']],
                            ['Страна', $goods_v['product']['country']],
                        ] as $line): ?>
                            <?php if (trim((string) $line[1]) === '') { continue; } ?>
                            <div class="flex gap-2">
                                <dt class="shrink-0 text-gray-400"><?php echo $line[0]; ?></dt>
                                <dd class="min-w-0 break-words text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $line[1]); ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                    <?php // ПИЩЕВАЯ ЦЕННОСТЬ в разборе — коротко: калорийность и то, чего
                          // человек ищет глазами (сахара, соль). Полная таблица — в карточке
                          // товара; здесь строка, чтобы не отодвигать сам состав. ?>
                    <?php $nutri = (array) ($goods_v['product']['nutrition'] ?? []); ?>
                    <?php if ( ! empty($nutri['values'])): ?>
                        <?php
                        $nutri_bits = [];

                        foreach (Service_Nj646_About::nutrition_fields() as $nf => $nspec)
                        {
                            if ( ! isset($nutri['values'][$nf]))
                            {
                                continue;
                            }

                            $nutri_bits[] = mb_strtolower($nspec['title']).' '
                                .Service_Nj646_About::nutrition_number($nutri['values'][$nf]).' '.$nspec['unit'];
                        }
                        ?>
                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                            <span class="text-gray-400">Пищевая ценность
                                <?php echo HTML::chars(Service_Nj646_About::nutrition_bases()[$nutri['basis'] ?? '100g'] ?? ''); ?>:</span>
                            <?php echo HTML::chars(implode(' · ', array_slice($nutri_bits, 0, 6))); ?>
                        </p>
                        <?php foreach ((array) ($goods_v['product']['nutrition_flags'] ?? []) as $flag): ?>
                            <p class="mt-1 text-xs <?php echo $flag['kind'] === 'warn'
                                ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300'; ?>">
                                <?php echo HTML::chars($flag['text']); ?>
                            </p>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <p class="n2j-hint mt-2">разборов этого товара: <?php echo (int) $goods_v['product']['hits']; ?></p>
                    <?php // Сравнение — сразу после разбора: «а тот, второй, чище?» ?>
                    <a href="/label/compare/<?php echo (int) ($goods_v['product']['id'] ?? 0); ?>"
                       class="mt-2 inline-flex min-h-[44px] items-center text-xs font-semibold text-blue-700 hover:underline dark:text-blue-300">
                        <i class="fas fa-scale-balanced mr-1.5"></i>сравнить с другим товаром
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($goods_v AND $goods_v['producer']): ?>
                <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-700">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                        <?php echo HTML::chars(Service_Nj646_About::roles()[(string) $goods_v['producer']['role']] ?? 'Производитель'); ?>
                    </p>
                    <a href="<?php echo HTML::chars($goods_v['producer']['url']); ?>"
                       class="mt-1 block break-words text-sm font-semibold text-blue-700 hover:underline dark:text-blue-300">
                        <?php echo HTML::chars($goods_v['producer']['name']); ?>
                    </a>
                    <?php if ($goods_v['producer']['address'] !== ''): ?>
                        <p class="mt-1 break-words text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($goods_v['producer']['address']); ?></p>
                    <?php endif; ?>
                    <?php if ($goods_v['producer']['inn'] !== '' OR $goods_v['producer']['ogrn'] !== ''): ?>
                        <p class="n2j-hint font-mono">
                            <?php if ($goods_v['producer']['inn'] !== ''): ?>ИНН <?php echo HTML::chars($goods_v['producer']['inn']); ?><?php endif; ?>
                            <?php if ($goods_v['producer']['ogrn'] !== ''): ?> · ОГРН <?php echo HTML::chars($goods_v['producer']['ogrn']); ?><?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                        <?php if ($goods_v['producer']['ontology_url'] !== ''): ?>
                            <a href="<?php echo HTML::chars($goods_v['producer']['ontology_url']); ?>"
                               class="n2j-btn-secondary n2j-btn-sm">онтология предприятий</a>
                        <?php endif; ?>
                        <?php if ($goods_v['producer']['org_url'] !== ''): ?>
                            <a href="<?php echo HTML::chars($goods_v['producer']['org_url']); ?>"
                               class="n2j-btn-secondary n2j-btn-sm">справочник организаций</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ( ! $goods_v AND ($about_p OR $about_pr)): ?>
            <p class="n2j-hint mt-3">
                Карточки этого товара/производителя уже нет в базе (её удалили), но снимок с этикетки остался:
                <?php
                $bits = [];
                foreach ($about_p as $key => $value) { $bits[] = $key.' — '.$value; }
                foreach ($about_pr as $org) { $bits[] = 'производитель — '.$org['name']; }
                echo HTML::chars(implode(' · ', array_slice($bits, 0, 8)));
                ?>
            </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($transcript !== ''): ?>
        <!-- Строки этикетки, какими их прочитала модель на фото: сверить с
             снимком — единственный способ увидеть, где модель угадывала. -->
        <div class="n2j-card mt-4">
            <details>
                <summary class="cursor-pointer select-none text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">
                    Строки этикетки, как их прочитала модель на фото
                </summary>
                <pre class="mt-3 whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-mono text-xs leading-relaxed text-gray-700 dark:bg-gray-900 dark:text-gray-200"><?php echo HTML::chars($transcript); ?></pre>
            </details>
        </div>
    <?php endif; ?>

    <!-- ============================ ТЕКСТ ============================ -->
    <div class="n2j-card mt-4">
        <details>
            <summary class="cursor-pointer select-none text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">
                Состав, как он пришёл (<?php echo $fmt(mb_strlen($text)); ?> знаков)
            </summary>

            <?php if ( ! empty($cut_raw['was'])): ?>
                <!-- Честная пометка об обрезке на записи: без неё сокращённый
                     состав выглядел бы как полный. -->
                <p class="n2j-flash n2j-flash-warn mt-3 flex items-start gap-2 text-xs leading-relaxed">
                    <i class="fas fa-scissors mt-0.5 text-[10px]"></i>
                    <span>
                        Текст был длиннее предела хранения —
                        сохранили <?php echo $fmt((int) round(((int) $cut_raw['kept']) / 1024)); ?> КБ
                        из <?php echo $fmt((int) round(((int) $cut_raw['was']) / 1024)); ?> КБ.
                        Разбор построен по сохранённой части.
                    </span>
                </p>
            <?php endif; ?>

            <p class="mt-3 whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-200"><?php echo HTML::chars($text); ?></p>
        </details>

        <p class="n2j-hint mt-3">
            Разбор привязан к этому браузеру (анонимная метка) и открывается только у вас
            <?php if ($admin): ?> и у администратора<?php endif; ?>.
            Сервис носит справочный характер и не заменяет врача или дерматолога.
        </p>
    </div>
</div>

<?php // Живые ряды изучения: тот же механизм, что и на /label (partials/live_status). ?>
<?php echo View::factory('partials/live_status_js'); ?>
