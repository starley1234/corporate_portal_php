<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница опроса (nj520_poll): /p/<code>.
 *
 * Слева — голосование (пока не голосовал) и результаты полосками, справа —
 * ссылка с QR-кодом и другие опросы. Всё SSR: без JS страница работает,
 * JS только обновляет цифры и рисует QR.
 */
$poll = $results['poll'];
$is_closed = ! empty($poll['closed_at']);
$total = (int) $results['total'];
$leader = (int) $results['leader'];
$my_vote = (int) (isset($my_vote) ? $my_vote : 0);
$show_results = ! empty($show_results);
$recent = isset($recent) ? (array) $recent : [];

// Сообщение-«шапка»: показываем ОДНО (цвет, иконка, текст).
$note = NULL;

if ( ! empty($created))
{
    $note = ['emerald', 'fa-circle-check', 'Опрос создан. Отправьте участникам ссылку '.$share_url];
}
elseif ( ! empty($just_voted))
{
    $note = ['blue', 'fa-check', 'Голос учтён — спасибо!'];
}
elseif ( ! empty($already))
{
    $note = ['amber', 'fa-circle-info', 'С этого браузера голос уже принят: один голос на один опрос.'];
}

$note_class = [
    'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    'blue'    => 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    'amber'   => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="xl:flex xl:items-start xl:gap-10">
<div class="min-w-0 flex-1">

    <?php if ($note !== NULL): ?>
        <div class="mb-4 rounded-xl border px-4 py-3 text-sm <?php echo $note_class[$note[0]]; ?>">
            <i class="fas <?php echo $note[1]; ?> mr-1.5"></i><?php echo HTML::chars($note[2]); ?>
        </div>
    <?php endif; ?>

    <?php if ($is_closed OR ! empty($closed_req)): ?>
        <div class="mb-4 rounded-xl border border-gray-300 bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-600 dark:border-gray-600 dark:bg-gray-800/60 dark:text-gray-300">
            <i class="fas fa-lock mr-1.5"></i>Опрос закрыт — голосование прекращено. Итоги ниже.
        </div>
    <?php endif; ?>

    <div class="n2j-card">
        <div class="flex items-start justify-between gap-4">
            <h1 class="n2j-h1"><?php echo HTML::chars($poll['title']); ?></h1>
            <div class="flex shrink-0 items-center gap-2">
                <?php if ($is_closed): ?>
                    <span class="rounded-md bg-gray-200 px-2 py-1 text-xs font-bold uppercase text-gray-500 dark:bg-gray-700 dark:text-gray-400"><i class="fas fa-lock mr-1"></i>Закрыт</span>
                <?php endif; ?>
                <span class="rounded-xl bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                    <span data-poll-total><?php echo $total; ?></span> <?php echo Service_Nj520_Poll::votes_word($total); ?>
                </span>
            </div>
        </div>

        <?php if ( ! $voted AND ! $is_closed): ?>
            <!-- Голосование -->
            <form method="post" action="/p/<?php echo HTML::chars($poll['code']); ?>" class="mt-5 space-y-2.5">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <?php foreach ($results['options'] as $opt): ?>
                    <label class="flex min-h-[44px] cursor-pointer items-center gap-3 rounded-xl border border-gray-200 px-4 py-3 text-sm font-medium text-gray-700 transition hover:border-blue-400 hover:bg-blue-50/50 dark:border-gray-600 dark:text-gray-200 dark:hover:border-blue-500 dark:hover:bg-blue-900/20">
                        <input type="radio" name="option" value="<?php echo (int) $opt['id']; ?>" required
                               class="h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900">
                        <?php echo HTML::chars($opt['label']); ?>
                    </label>
                <?php endforeach; ?>
                <button type="submit"
                        class="n2j-btn-process mt-2">
                    <i class="fas fa-check"></i>Голосовать
                </button>
            </form>
        <?php endif; ?>

        <!-- Результаты -->
        <div class="<?php echo $voted ? 'mt-5' : 'mt-6 border-t border-gray-100 pt-5 dark:border-gray-700'; ?>">
            <?php if ( ! $show_results): ?>
                <?php // Цифры прячем до голоса: увидев «80% за первый вариант»,
                      // голосуют иначе — и опрос перестаёт мерить мнение. ?>
                <div class="rounded-xl border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
                    <i class="fas fa-eye-slash mr-1.5 text-gray-400"></i>Результаты откроются сразу после вашего голоса —
                    чтобы чужой выбор не влиял на ваш.<?php // Сколько человек уже проголосовало, видно в шапке карточки:
                        // число голосов ничего не подсказывает, а распределение — подсказывает. ?>
                </div>
            <?php else: ?>
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                <?php echo $is_closed ? 'Опрос завершён. Итоговые результаты:' : 'Ваш голос учтён. Результаты:'; ?>
            </p>

            <div class="space-y-3" data-poll-results>
                <?php foreach ($results['options'] as $opt): ?>
                    <?php $mine = ((int) $opt['id'] === $my_vote); ?>
                    <div data-option="<?php echo (int) $opt['id']; ?>">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="min-w-0 font-medium text-gray-700 dark:text-gray-200">
                                <?php if ($mine): ?><i class="fas fa-circle-check mr-1.5 text-xs text-blue-500" title="ваш голос"></i><?php endif; ?>
                                <?php echo HTML::chars($opt['label']); ?>
                                <span data-option-leader class="<?php echo ((int) $opt['id'] === $leader) ? '' : 'hidden '; ?>ml-1.5 rounded-md bg-blue-50 px-1.5 py-0.5 text-xs font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">лидирует</span>
                            </span>
                            <span class="shrink-0 text-xs font-semibold text-gray-400 tabular-nums dark:text-gray-500">
                                <span data-option-votes><?php echo (int) $opt['votes']; ?></span> ·
                                <span data-option-percent><?php echo number_format((float) $opt['percent'], 1, ',', ''); ?></span>%
                            </span>
                        </div>
                        <?php // Полоска доли голосов — не индикатор хода работы, а сам
                              // результат (решение владельца 04.10.2026): ширина = процент.
                        ?>
                        <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div data-option-bar
                                 class="h-full rounded-full <?php echo $mine ? 'bg-blue-600' : 'bg-blue-400 dark:bg-blue-500'; ?>"
                                 style="width: <?php echo (float) $opt['percent']; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total === 0): ?>
                <p class="n2j-hint mt-3">Голосов пока нет<?php echo $is_closed ? '' : ' — ваш будет первым'; ?>.</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Поделиться -->
        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
            <button type="button" id="poll-share"
                    class="n2j-btn-process n2j-btn-sm">
                <i class="fas fa-share"></i>Скопировать ссылку
            </button>
            <a href="/poll" class="n2j-btn-secondary n2j-btn-sm">
                <i class="fas fa-plus"></i>Создать свой опрос
            </a>
            <span class="text-xs text-gray-400 dark:text-gray-500" data-poll-updated></span>
        </div>
    </div>

</div>

<aside class="mt-4 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">

    <!-- Ссылка и QR-код -->
    <div class="n2j-card">
        <p class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Ссылка на опрос</p>
        <a href="<?php echo HTML::chars($share_url); ?>" class="mt-1 block break-all font-mono text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($share_url); ?></a>
        <?php // QR рисует локальная библиотека (qrcode-generator) прямо в браузере:
              // ни одного обращения к чужому хосту — адрес опроса никуда не уезжает. ?>
        <div class="mt-3 flex flex-col items-center rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900/60">
            <div id="poll-qr" data-qr-url="<?php echo HTML::chars($share_url); ?>"></div>
            <noscript><p class="n2j-hint text-center">QR-код рисуется в браузере — включите JavaScript или скопируйте ссылку.</p></noscript>
            <p class="n2j-hint mt-2 text-center">Наведите камеру — откроется этот опрос.</p>
        </div>
    </div>

    <!-- Другие активные опросы -->
    <?php if ($recent): ?>
    <div class="n2j-card">
        <p class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Другие активные опросы</p>
        <ul class="mt-2 space-y-0.5">
            <?php foreach ($recent as $r): ?>
            <li>
                <a href="/p/<?php echo HTML::chars($r['code']); ?>" class="group flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-600 transition hover:bg-gray-50 hover:text-blue-600 dark:text-gray-300 dark:hover:bg-gray-700/60">
                    <i class="fas fa-chart-simple w-4 text-center text-gray-400 group-hover:text-blue-500"></i><span class="min-w-0 flex-1 truncate"><?php echo HTML::chars($r['title']); ?></span>
                    <span class="shrink-0 text-xs font-semibold text-gray-400"><?php echo (int) $r['votes']; ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <p class="n2j-hint px-1">
        Один голос — с одного браузера. Мы не храним ни имён, ни IP: только случайный
        идентификатор браузера, который платформа и так ставит.
    </p>
</aside>
</div>
</div>

<script src="/assets/vendor/qrcode/qrcode.js"></script>
<?php echo View::factory('520/script', [
    'code' => $poll['code'],
    'live' => ($show_results AND ! $is_closed),
]); ?>
