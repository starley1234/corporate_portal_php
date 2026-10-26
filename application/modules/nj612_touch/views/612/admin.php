<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /nj612/admin — все серии платформы (управление).
 */
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800';
$st_ru = ['active' => 'идёт', 'paused' => 'пауза', 'done' => 'завершена', 'stopped' => 'остановлена', 'answered' => 'ответил'];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/touches" class="transition hover:text-blue-600 dark:hover:text-blue-400">Серии касаний</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Управление</span>
    </nav>

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mt-2 block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <h1 class="n2j-h1"><i class="fas fa-hand-point-right mr-2 text-blue-500"></i>Все серии</h1>
        <span class="n2j-badge n2j-badge-wait"><?php echo count($rows); ?></span>
        <span class="n2j-badge <?php echo $autosend ? 'n2j-badge-ok' : 'n2j-badge-wait'; ?> uppercase">
            автоотправка: <?php echo $autosend ? 'вкл' : 'выкл (NJ612_TOUCH_AUTOSEND)'; ?>
        </span>
    </div>

    <div class="<?php echo $card; ?> mt-3 overflow-x-auto">
        <table class="n2j-table">
            <thead>
                <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700">
                    <th class="py-2 pr-3">Компания</th>
                    <th class="py-2 pr-3">Владелец</th>
                    <th class="py-2 pr-3">Шаги</th>
                    <th class="py-2 pr-3">Статус</th>
                    <th class="py-2 pr-3">Создана</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): $r = (array) $r; ?>
                <tr class="border-b border-gray-50 last:border-0 dark:border-gray-700/40">
                    <td class="py-2 pr-3">
                        <a href="/touches/<?php echo HTML::chars($r['code']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($r['company']); ?></a>
                        <span class="block font-mono text-xs text-gray-400"><?php echo HTML::chars($r['contact_email'] ?: 'без адреса'); ?></span>
                    </td>
                    <td class="py-2 pr-3 text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) ($r['owner_name'] ?? ('#'.$r['user_id']))); ?></td>
                    <td class="py-2 pr-3 text-gray-600 dark:text-gray-300"><?php echo (int) $r['current_step']; ?>/<?php echo (int) $r['steps_total']; ?></td>
                    <td class="py-2 pr-3"><?php echo HTML::chars($st_ru[$r['status']] ?? $r['status']); ?></td>
                    <td class="py-2 pr-3 text-xs text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $r['created_at'], 'd.m.y H:i')); ?></td>
                    <td class="py-2">
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                            <input type="hidden" name="act" value="delete">
                            <input type="hidden" name="code" value="<?php echo HTML::chars($r['code']); ?>">
                            <button data-n2j-confirm="Серия владельца удалится вместе с письмами." data-n2j-title="Удалить серию владельца?" type="submit" class="n2j-btn-icon-danger text-xs"><i class="fas fa-trash-can"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                <tr><td colspan="6" class="py-6 text-center text-sm text-gray-400">Серий пока нет.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

