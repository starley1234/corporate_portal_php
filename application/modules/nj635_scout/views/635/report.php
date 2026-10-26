<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Единый отчёт /check: блоки шагов + живой опрос.
 *
 * Первый кадр рисует сервер (SSR) — видно сразу, даже без JS; дальше
 * script.php раз в 4 с спрашивает /check/api/status и подменяет блоки,
 * у которых изменилось состояние (готовые показывают результат на месте).
 */

// значения по умолчанию: вид открывается и в тестовом рендере
$q     = isset($q) ? (string) $q : '';
$csrf  = isset($csrf) ? (string) $csrf : '';
$ref_q = isset($ref_q) ? (string) $ref_q : '';

$api = '/check/api/status'.($ref_q !== '' ? '?'.$ref_q : '');
$running = 0;
$ready   = 0;

foreach ($blocks as $b)
{
    if (in_array((string) $b['status'], ['queued', 'running'], TRUE))
    {
        $running++;
    }

    if ((string) $b['status'] === 'done')
    {
        $ready++;
    }
}
?>

<div class="mt-5" data-nj635-report data-api="<?php echo HTML::chars($api); ?>" data-ready="<?php echo (int) $ready; ?>" data-total="<?php echo count($blocks); ?>">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-clipboard-list mr-1.5 text-blue-500"></i>Полный отчёт</p>
        <div class="flex flex-wrap items-center gap-2">
            <span class="n2j-badge n2j-badge-wait" data-nj635-counter>готово <?php echo (int) $ready; ?> из <?php echo count($blocks); ?></span>
            <?php if ($running > 0): ?>
            <span class="text-xs text-gray-500 dark:text-gray-400" data-nj635-hint><i class="fas fa-circle-notch fa-spin mr-1 text-blue-500"></i>собираем — блоки обновятся сами</span>
            <?php else: ?>
            <span class="text-xs text-gray-500 dark:text-gray-400" data-nj635-hint>обновляется автоматически</span>
            <?php endif; ?>
            <?php if ($q !== ''): ?>
            <form method="post" action="/check">
                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                <input type="hidden" name="act" value="run">
                <input type="hidden" name="q" value="<?php echo HTML::chars($q); ?>">
                <button type="submit" class="n2j-btn-secondary n2j-btn-sm">
                    <i class="fas fa-rotate mr-1.5 text-gray-400"></i>Обновить отчёт
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-2.5 space-y-3" data-nj635-blocks>
        <?php foreach ($blocks as $b): ?>
        <?php echo $b['html']; ?>
        <?php endforeach; ?>
    </div>

    <?php if ( ! empty($letters)): ?>
    <p class="n2j-hint mt-3">
        Письмо компании собирается отдельно — ему нужен ваш оффер.
        <a href="/coldmail" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Составить первое письмо →</a>
    </p>
    <?php endif; ?>
</div>

<?php echo View::factory('635/script', ['report' => TRUE]); ?>
