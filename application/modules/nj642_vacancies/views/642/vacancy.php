<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj642_vacancies: карточка вакансии (общая для ленты и страницы монитора).
 *
 * Показываем ровно то, что прочитано на сайте работодателя: должность, компания,
 * город, зарплата (если указана), почему подошла монитору и ссылка на источник.
 * Исчезнувшие вакансии не исчезают из истории — у них серая плашка «больше нет».
 */
$v = (array) $v;
$is_gone  = ((string) ($v['status'] ?? '') === 'gone');
$match    = (string) ($v['match_status'] ?? '');
$title    = (string) ($v['title'] ?? '');
$company  = (string) ($v['company'] ?? '');
$city     = (string) ($v['city'] ?? '');
$salary   = (string) ($v['salary'] ?? '');
$url      = (string) ($v['url'] ?? '');
$src      = (string) ($v['source_url'] ?? '');
$why      = (string) ($v['why'] ?? '');
$score    = (int) ($v['score'] ?? 0);
$snippet  = trim((string) ($v['snippet'] ?? ''));
$seen     = (string) ($v['first_seen_at'] ?? '');
$csrff    = isset($csrf) ? (string) $csrf : '';
?>
<div class="rounded-xl border <?php echo $is_gone ? 'border-gray-200 bg-gray-50/70 dark:border-gray-700/70 dark:bg-gray-900/40' : 'border-gray-200 bg-white dark:border-gray-700/80 dark:bg-gray-800'; ?> p-4 shadow-xs"
     data-nj642-card="<?php echo (int) $v['id']; ?>" data-status="<?php echo HTML::chars($is_gone ? 'gone' : (string) $v['status']); ?>">

    <div class="flex flex-wrap items-start gap-x-3 gap-y-1.5">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="n2j-h3">
                    <?php if ($url !== ''): ?>
                    <a href="<?php echo HTML::chars($url); ?>" target="_blank" rel="noopener nofollow" class="hover:text-blue-600 hover:underline dark:hover:text-blue-400"><?php echo HTML::chars($title); ?></a>
                    <?php else: ?>
                    <?php echo HTML::chars($title); ?>
                    <?php endif; ?>
                </h3>

                <?php if ($is_gone): ?>
                <span class="n2j-badge n2j-badge-wait">больше нет</span>
                <?php elseif ($match === 'saved'): ?>
                <span class="n2j-badge n2j-badge-run"><i class="fas fa-star mr-1 text-[11px]"></i>избранное</span>
                <?php endif; ?>

                <?php if ($score > 0): ?>
                <span class="rounded-full border border-gray-200 px-2.5 py-0.5 text-xs font-semibold text-gray-600 dark:border-gray-700 dark:text-gray-300" title="Насколько вакансия подошла монитору">подходит <?php echo $score; ?>/100</span>
                <?php endif; ?>
            </div>

            <p class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                <?php if ($company !== ''): ?><span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars($company); ?></span><?php endif; ?>
                <?php if ($city !== ''): ?><span class=""><i class="fas fa-location-dot mr-1 text-[11px] text-gray-400"></i><?php echo HTML::chars($city); ?></span><?php endif; ?>
                <?php if ($salary !== ''): ?><span class=""><i class="fas fa-ruble-sign mr-1 text-[11px] text-gray-400"></i><?php echo HTML::chars($salary); ?></span><?php endif; ?>
                <?php if ($seen !== ''): ?><span class="text-gray-500 dark:text-gray-400">увидели <?php echo HTML::chars(mb_substr($seen, 0, 10)); ?></span><?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ($why !== ''): ?>
    <p class="n2j-hint mt-2"><i class="fas fa-circle-check mr-1 text-[11px] text-emerald-500"></i><?php echo HTML::chars($why); ?></p>
    <?php endif; ?>

    <?php if ($snippet !== ''): ?>
    <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($snippet); ?></p>
    <?php endif; ?>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <?php if ($url !== ''): ?>
        <a href="<?php echo HTML::chars($url); ?>" target="_blank" rel="noopener nofollow"
           class="n2j-btn-secondary n2j-btn-sm">
            <i class="fas fa-up-right-from-square text-[11px]"></i>Открыть вакансию
        </a>
        <?php endif; ?>

        <?php if ( ! $is_gone): ?>
        <form method="post" class="inline-flex">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrff); ?>">
            <input type="hidden" name="act" value="match">
            <input type="hidden" name="match_id" value="<?php echo (int) ($v['match_id'] ?? 0); ?>">
            <input type="hidden" name="status" value="<?php echo $match === 'saved' ? 'new' : 'saved'; ?>">
            <button type="submit" class="n2j-chip<?php echo $match === 'saved' ? ' is-on' : ''; ?>">
                <i class="fas fa-star text-[11px]"></i><?php echo $match === 'saved' ? 'В избранном' : 'В избранное'; ?>
            </button>
        </form>
        <?php endif; ?>

        <?php if ($src !== '' AND $src !== $url): ?>
        <a href="<?php echo HTML::chars($src); ?>" target="_blank" rel="noopener nofollow"
           class="inline-flex min-h-[34px] items-center gap-1.5 rounded-lg px-2.5 text-sm font-semibold text-gray-500 transition hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400">
            <i class="fas fa-list-check text-[11px]"></i>Все вакансии компании
        </a>
        <?php endif; ?>
    </div>
</div>
