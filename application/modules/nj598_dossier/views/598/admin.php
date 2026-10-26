<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj598/admin — админка досье: KPI, все досье платформы, повтор/удаление.
 */
$st = [
    'done'       => ['готово', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'],
    'error'      => ['ошибка', 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'],
    'running'    => ['идёт', 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'],
    'new'        => ['в очереди', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'],
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dossier" class="transition hover:text-blue-600 dark:hover:text-blue-400">AI Досье</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Управление</span>
    </nav>

    <h1 class="n2j-h1 mt-2">
        <i class="fas fa-user-secret mr-2 text-blue-500"></i>AI Досье · управление
    </h1>
    <p class="n2j-hint mt-1.5">Все досье платформы: повтор разведки и удаление. Отчёты о ресурсах задач — на <a href="/nj120/usage" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">/nj120/usage</a>.</p>

    <?php if ( ! empty($flash)): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <?php foreach (['total' => 'Всего', 'new' => 'В очереди', 'running' => 'Идёт', 'done' => 'Готово', 'error' => 'Ошибки'] as $k => $label): ?>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase text-gray-400"><?php echo $label; ?></p>
            <p class="mt-1 text-2xl font-black <?php echo ($k === 'error' AND $stats[$k] > 0) ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-white'; ?>"><?php echo (int) $stats[$k]; ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="n2j-card p-0 mt-4 overflow-x-auto">
        <table class="n2j-table">
            <thead>
                <tr class="text-left text-xs text-gray-400">
                    <th class="px-4 py-2.5">Компания</th><th>Статус</th><th>Кто</th><th>Модель</th><th>Создано</th><th class="pr-4 text-right">Действия</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50 text-gray-700 dark:divide-gray-700/50 dark:text-gray-300">
                <?php foreach ($items as $d): [$stl, $stc] = $st[$d['status']] ?? [$d['status'], 'bg-gray-100 text-gray-500 dark:bg-gray-700']; ?>
                <tr>
                    <td class="max-w-[14rem] px-4 py-2.5">
                        <a href="/dossier/<?php echo HTML::chars($d['code']); ?>" class="block truncate font-semibold text-blue-600 hover:underline dark:text-blue-400" title="<?php echo HTML::chars($d['company'] ?: $d['query']); ?>"><?php echo HTML::chars($d['company'] ?: $d['query']); ?></a>
                        <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars($d['code']); ?></span>
                    </td>
                    <td class="px-3 py-2.5"><span class="whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-bold <?php echo $stc; ?>"><?php echo $stl; ?></span></td>
                    <td class="max-w-[8rem] truncate px-3 py-2.5 text-xs" title="<?php echo HTML::chars($d['visitor_id']); ?>"><?php echo HTML::chars($d['owner']); ?></td>
                    <td class="max-w-[10rem] truncate px-3 py-2.5 font-mono text-xs text-gray-400" title="<?php echo HTML::chars($d['model_used']); ?>"><?php echo HTML::chars($d['model_used'] ?: '—'); ?></td>
                    <td class="whitespace-nowrap px-3 py-2.5 text-xs text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $d['created_at'], 'd.m.y H:i')); ?></td>
                    <td class="whitespace-nowrap px-3 py-2.5 text-right">
                        <?php if ( ! in_array($d['status'], ['running', 'new'], TRUE)): ?>
                        <form method="post" action="/nj598/admin" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="retry"><input type="hidden" name="code" value="<?php echo HTML::chars($d['code']); ?>">
                            <button data-n2j-arm="Повторить?" type="submit" title="Повторить разведку" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-rotate-right"></i> Повторить</button>
                        </form>
                        <?php endif; ?>
                        <?php if ( ! in_array($d['status'], ['running'], TRUE)): ?>
                        <form method="post" action="/nj598/admin" class="inline">
                            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="code" value="<?php echo HTML::chars($d['code']); ?>">
                            <button data-n2j-arm="Удалить?" type="submit" title="Удалить" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-trash-can"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ( ! $items): ?><tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">Досье пока нет.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

