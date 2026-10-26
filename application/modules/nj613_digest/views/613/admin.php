<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /nj613/admin — подписки и последние сводки.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <h1 class="n2j-h1"><i class="fas fa-mug-hot mr-2 text-blue-500"></i>Дайджест · управление</h1>
        <span class="n2j-badge n2j-badge-wait">подписок: <?php echo (int) $subs['on_cnt']; ?> из <?php echo (int) $subs['all_cnt']; ?></span>
    </div>

    <div class="<?php echo $card; ?> overflow-x-auto">
        <table class="n2j-table">
            <thead>
                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700">
                    <th class="py-2 pr-3">Период</th>
                    <th class="py-2 pr-3">Владелец</th>
                    <th class="py-2 pr-3">Статус</th>
                    <th class="py-2 pr-3">Цифры</th>
                    <th class="py-2">Резюме</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($digests as $d): $d = (array) $d; ?>
                <tr class="border-b border-gray-50 last:border-0 align-top dark:border-gray-700/40">
                    <td class="py-2 pr-3 whitespace-nowrap text-gray-600 dark:text-gray-300"><?php echo HTML::chars(Date::formatted_time((string) $d['date_from'], 'd.m')); ?> — <?php echo HTML::chars(Date::formatted_time((string) $d['date_to'], 'd.m')); ?></td>
                    <td class="py-2 pr-3 text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) ($d['owner_name'] ?? ('#'.$d['user_id']))); ?></td>
                    <td class="py-2 pr-3 text-gray-500"><?php echo HTML::chars((string) $d['status']); ?></td>
                    <td class="py-2 pr-3 text-xs text-gray-400"><?php $s2 = json_decode((string) ($d['stats'] ?? ''), TRUE); echo $s2 ? HTML::chars(json_encode($s2, JSON_UNESCAPED_UNICODE)) : '—'; ?></td>
                    <td class="py-2 max-w-md whitespace-pre-line text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $d['summary'], 0, 240)); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($digests)): ?>
                <tr><td colspan="5" class="py-6 text-center text-sm text-gray-400">Сводок пока нет.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
