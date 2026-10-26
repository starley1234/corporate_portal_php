<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Публичная страница вещества — /label/ingredient/<slug>.
 *
 * Владелец (03.10.2026), ревизия P1 п. 1: «публичные страницы веществ» — каждая
 * карточка базы получает обычный адрес, который можно послать знакомому и
 * проиндексировать. До неё у вещества был только /label/knowledge?slug= — то
 * есть инструмент, а не страница: заголовок у всех один, в карту сайта такой
 * адрес не положить, да и в переписке он выглядит как служебная ссылка.
 *
 * Страница статичная (SSR): никакого JS, кроме живого поиска в шапке модуля.
 * Вещество должно открываться и без скриптов, и медленным поисковым роботом.
 * Оценку «согласен/спорно» здесь не поставить — она живёт в базе знаний
 * (`/label/knowledge?slug=`), тут только счётчик и ссылка туда.
 *
 * Обязанности страницы: объяснить, что это за вещество (пояснение, подробности),
 * показать риск и вердикт словами, назвать категорию и E-код, предупредить,
 * кому обратить внимание, дать источники и увести к соседям по категории.
 */

$fmt = function ($n) {
    return number_format((float) $n, 0, ',', ' ');
};

$status = (string) $card['status'];
$name   = (string) $card['name_ru'] !== '' ? (string) $card['name_ru'] : (string) $card['name_int'];

$kind_labels = ['food' => 'еда', 'cosmetic' => 'косметика', 'both' => 'еда и косметика'];

$verdict_class = [
    'useful'  => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    'neutral' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    'caution' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    'risky'   => 'bg-rose-50 text-rose-600 dark:bg-rose-900/20 dark:text-rose-300',
    'avoid'   => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-200',
];

$risk_dot = ['bg-emerald-500', 'bg-gray-400', 'bg-amber-500', 'bg-rose-400', 'bg-rose-600'];
$risk_word = ['безопасное', 'низкий риск', 'умеренный риск', 'высокий риск', 'опасное'];

$risk = max(0, min(4, (int) $card['risk']));
$list = function ($value) {
    $items = is_array($value) ? $value : ($value !== '' ? [$value] : []);
    return array_values(array_filter(array_map('strval', $items), 'strlen'));
};

$synonyms = $list($card['synonyms']);
$avoid    = $list($card['avoid']);
$sources  = $list($card['sources']);

$agree   = (int) ($votes['agree'] ?? 0);
$dispute = (int) ($votes['dispute'] ?? 0);
$hits    = (int) $card['hits'];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <p class="n2j-hint">
        <a href="/label" class="hover:underline">Разбор состава</a> ·
        <a href="/label/knowledge" class="hover:underline">База знаний</a> ·
        вещество
    </p>

    <?php if ($status !== 'published'): ?>
        <div class="n2j-flash n2j-flash-warn mt-3 block">
            <i class="fas fa-triangle-exclamation mr-1"></i>
            Карточка в статусе «<?php echo HTML::chars(Service_Nj646_Label::status_label($status, 'card')); ?>» — посетители её не видят, страница открыта вам как админу.
        </div>
    <?php endif; ?>

    <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="n2j-h1">
                <?php echo HTML::chars($name); ?>
                <?php if ((string) $card['name_int'] !== '' AND (string) $card['name_int'] !== $name): ?>
                    <span class="text-base font-normal text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $card['name_int']); ?></span>
                <?php endif; ?>
            </h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full px-2.5 py-1 font-bold <?php echo $verdict_class[(string) $card['verdict']] ?? 'bg-gray-100 text-gray-600'; ?>">
                    <?php echo HTML::chars(Service_Nj646_Label::verdict_label((string) $card['verdict'])); ?>
                </span>
                <?php if ((string) $card['e_code'] !== ''): ?>
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 font-mono font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $card['e_code']); ?></span>
                <?php endif; ?>
                <?php if ((string) $card['category'] !== ''): ?>
                    <span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $card['category']); ?></span>
                <?php endif; ?>
                <span class="text-gray-400">·</span>
                <span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kind_labels[(string) $card['kind']] ?? 'состав'); ?></span>
            </div>
        </div>

        <a href="/label/knowledge?slug=<?php echo HTML::chars((string) $card['slug']); ?>"
           class="n2j-btn-secondary">
            <i class="fas fa-star-half-stroke mr-1"></i>оценить в базе знаний
        </a>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">

        <!-- ============================ ПОЯСНЕНИЕ ============================ -->
        <div class="space-y-4">
            <div class="n2j-card">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Что это такое</p>
                <p class="mt-1 text-base leading-relaxed text-gray-800 dark:text-gray-100">
                    <?php echo HTML::chars(trim((string) $card['summary']) !== '' ? (string) $card['summary'] : 'Пояснения к этому веществу пока нет — карточка появилась недавно.'); ?>
                </p>

                <?php if (trim((string) $card['details']) !== ''): ?>
                    <p class="mt-4 whitespace-pre-wrap text-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $card['details']); ?></p>
                <?php endif; ?>
            </div>

            <?php if ($avoid): ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-4 sm:p-5 dark:border-amber-900/60 dark:bg-amber-950/20">
                    <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                        <i class="fas fa-circle-exclamation mr-1"></i>Кому обратить внимание
                    </p>
                    <ul class="mt-2 space-y-1.5 text-sm text-amber-900/90 dark:text-amber-200/90">
                        <?php foreach ($avoid as $code): ?>
                            <li>· <?php echo HTML::chars($reasons[$code] ?? $code); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mt-2 text-xs text-amber-900/70 dark:text-amber-200/70">
                        Предупреждения общие: личные ограничения учитываются, если заполнить
                        <a class="underline" href="/label/profile">профиль</a> и разобрать состав со своего браузера.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($synonyms): ?>
                <div class="n2j-card text-sm">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">На этикетке может быть написано</span>
                    <p class="mt-1 text-gray-700 dark:text-gray-200"><?php echo HTML::chars(implode(', ', $synonyms)); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($sources): ?>
                <div class="n2j-card text-sm">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Источники</span>
                    <ul class="mt-1 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                        <?php foreach ($sources as $source): ?>
                            <li class="break-words">
                                <?php if (preg_match('~^https?://~', $source)): ?>
                                    <a class="text-blue-600 hover:underline dark:text-blue-400" href="<?php echo HTML::chars($source); ?>" target="_blank" rel="nofollow noopener noreferrer"><?php echo HTML::chars($source); ?></a>
                                <?php else: ?>
                                    <?php echo HTML::chars($source); ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- CTA: разобрать состав, в котором встретилось это вещество -->
            <div class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 text-sm sm:p-5 dark:border-blue-900/60 dark:bg-blue-950/20">
                <p class="font-semibold text-blue-900 dark:text-blue-200">
                    <i class="fas fa-bottle-droplet mr-1"></i>Встретилось на упаковке?
                </p>
                <p class="mt-1 text-xs text-blue-900/80 dark:text-blue-200/80">
                    Вставьте состав целиком — сервис покажет все позиции, оценку от 0 до 100 и предупреждения под ваш профиль.
                </p>
                <a href="/label" class="n2j-btn-process mt-3">
                    <i class="fas fa-wand-magic-sparkles mr-1"></i>разобрать состав
                </a>
            </div>
        </div>

        <!-- ============================ КАРТОЧКА-САЙДБАР ============================ -->
        <div class="space-y-4 lg:self-start">

            <div class="n2j-card">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Риск</p>
                <div class="mt-2 flex items-center gap-2">
                    <?php // Уровень риска — точками: полосы убраны везде (владелец, 03.10.2026). ?>
                    <div class="flex gap-1.5">
                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <span class="h-2.5 w-2.5 rounded-full <?php echo $i <= $risk ? ($risk_dot[$risk] ?? 'bg-gray-300') : 'bg-gray-200 dark:bg-gray-700'; ?>"></span>
                        <?php endfor; ?>
                    </div>
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100"><?php echo $risk; ?>/4</span>
                </div>
                <p class="n2j-hint"><?php echo HTML::chars($risk_word[$risk] ?? ''); ?></p>

                <dl class="mt-4 space-y-2 border-t border-gray-100 pt-3 text-xs dark:border-gray-700">
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-500 dark:text-gray-400">Встречается в разборах</dt>
                        <dd class="font-semibold text-gray-800 dark:text-gray-100"><?php echo $hits > 0 ? $fmt($hits) : '—'; ?></dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-500 dark:text-gray-400">Оценки посетителей</dt>
                        <dd class="text-gray-800 dark:text-gray-100">
                            <?php if ($agree + $dispute > 0): ?>
                                <span class="font-semibold text-emerald-600 dark:text-emerald-400"><?php echo $agree; ?></span> за,
                                <span class="font-semibold text-amber-600 dark:text-amber-400"><?php echo $dispute; ?></span> против
                            <?php else: ?>
                                <span class="text-gray-400">пока нет</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-500 dark:text-gray-400">Карточку собрал</dt>
                        <dd class="text-gray-800 dark:text-gray-100">
                            <?php
                            $source_labels = ['seed' => 'справочник сервиса', 'llm' => 'модель', 'admin' => 'админ', 'user' => 'посетитель', 'csv' => 'словарь владельца'];
                            echo HTML::chars($source_labels[(string) $card['source']] ?? (string) $card['source']);
                            ?>
                        </dd>
                    </div>
                </dl>
            </div>

            <?php if ($similar): ?>
                <div class="n2j-card">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Рядом в категории</p>
                    <ul class="mt-2 space-y-1.5 text-sm">
                        <?php foreach ($similar as $item): ?>
                            <li>
                                <a class="flex items-center gap-2 text-gray-700 hover:text-blue-700 hover:underline dark:text-gray-200 dark:hover:text-blue-300"
                                   href="/label/ingredient/<?php echo HTML::chars((string) $item['slug']); ?>">
                                    <span class="h-2 w-2 shrink-0 rounded-full <?php echo $risk_dot[max(0, min(4, (int) $item['risk']))]; ?>"></span>
                                    <span class="min-w-0 flex-1 truncate"><?php echo HTML::chars((string) $item['name_ru']); ?></span>
                                    <?php if ((string) $item['e_code'] !== ''): ?>
                                        <span class="shrink-0 font-mono text-xs text-gray-400"><?php echo HTML::chars((string) $item['e_code']); ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ((string) $card['category'] !== ''): ?>
                        <a class="mt-3 inline-block text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400"
                           href="/label/knowledge?q=<?php echo urlencode((string) $card['category']); ?>">
                            вся категория «<?php echo HTML::chars((string) $card['category']); ?>» →
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 text-xs text-gray-500 sm:p-5 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
                Страница справочная: она рассказывает, что известно об этом веществе в среднем, и не заменяет врача или дерматолога.
                Решение о покупке и лечении принимаете вы.
            </div>
        </div>
    </div>
</div>
