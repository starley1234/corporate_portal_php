<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * «Журнал администратора»: кто, когда, что изменил. Только чтение.
 *
 * @var array  $rows    записи (id DESC)
 * @var bool   $more    есть следующая страница
 * @var array  $filter  days, category, actor, object_id, q, before_id
 * @var array  $facets  categories[{category,n}], actors[{actor_id,actor,n}]
 * @var string $error
 */
$lbl = 'text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500';
$qs = function (array $over) use ($filter): string {
    $p = array_filter([
        'days' => $filter['days'], 'category' => $filter['category'], 'actor' => $filter['actor'],
        'object' => $filter['object_id'], 'q' => $filter['q'],
    ] + [], fn ($v) => $v !== '' AND $v !== NULL);
    $p = array_filter(array_merge($p, $over), fn ($v) => $v !== '' AND $v !== NULL);
    return '/prx100/audit'.($p ? '?'.http_build_query($p) : '');
};
$summary = function ($json): string {
    $data = is_array($json) ? $json : json_decode((string) $json, TRUE);
    if ( ! is_array($data) OR $data === []) return '';
    $parts = [];
    foreach ($data as $k => $v)
    {
        $parts[] = $k.': '.(is_scalar($v) || $v === NULL ? var_export($v, TRUE) : json_encode($v, JSON_UNESCAPED_UNICODE));
    }
    return implode(' · ', $parts);
};
?>
<div class="prx-ds w-full space-y-6 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-audit-log>
    <?php echo View::factory('partials/page_header', [
        'crumbs'  => [['Администрирование', '/admin'], ['Журнал администратора']],
        'title'   => 'Журнал администратора',
        'pill'    => ['prx100 · безопасность', 'fa-user-shield'],
        'actions' => [
            ['label' => 'Хвост ошибок', 'href' => '/prx100/errors', 'icon' => 'fa-heart-crack', 'role' => 'secondary'],
            ['label' => 'События', 'href' => '/prx208_events', 'icon' => 'fa-bolt', 'role' => 'secondary'],
        ],
        'lead'    => 'Кто, когда и что изменил: ключи API, пользователи и роли, лимиты и повторы очереди, чистки данных, реестр модулей, отклонённые антивирусом файлы. Записи не правятся; срок хранения — год.',
    ]); ?>

    <?php if ($error !== ''): ?>
        <div class="prx-flash prx-flash-err" role="alert"><?php echo HTML::chars($error); ?></div>
    <?php endif; ?>

    <form method="get" action="/prx100/audit" class="prx-card flex flex-wrap items-end gap-3">
        <label class="block">
            <span class="<?php echo $lbl; ?>">Период</span>
            <select name="days" class="prx-input mt-1">
                <?php foreach ([1 => 'сутки', 7 => '7 дней', 30 => '30 дней', 90 => '90 дней', 365 => 'год'] as $d => $t): ?>
                    <option value="<?php echo $d; ?>"<?php echo (int) $filter['days'] === $d ? ' selected' : ''; ?>><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="<?php echo $lbl; ?>">Область</span>
            <select name="category" class="prx-input mt-1">
                <option value="">все</option>
                <?php foreach ($facets['categories'] as $c): ?>
                    <option value="<?php echo HTML::chars($c['category']); ?>"<?php echo $filter['category'] === (string) $c['category'] ? ' selected' : ''; ?>><?php echo HTML::chars($c['category']); ?> (<?php echo (int) $c['n']; ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="<?php echo $lbl; ?>">Кто</span>
            <select name="actor" class="prx-input mt-1">
                <option value="">все</option>
                <?php foreach ($facets['actors'] as $a): ?>
                    <option value="<?php echo (int) $a['actor_id']; ?>"<?php echo $filter['actor'] !== '' && (int) $filter['actor'] === (int) $a['actor_id'] ? ' selected' : ''; ?>><?php echo HTML::chars($a['actor'] ?: '#'.$a['actor_id']); ?> (<?php echo (int) $a['n']; ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="<?php echo $lbl; ?>">Объект</span>
            <input class="prx-input mt-1 w-32" name="object" value="<?php echo HTML::chars($filter['object_id']); ?>" placeholder="id">
        </label>
        <label class="block">
            <span class="<?php echo $lbl; ?>">Действие</span>
            <input class="prx-input mt-1 w-40" name="q" value="<?php echo HTML::chars($filter['q']); ?>" placeholder="подстрока">
        </label>
        <button class="prx-btn-cta" type="submit"><i class="fas fa-filter mr-1.5"></i>Показать</button>
        <a class="prx-link text-sm" href="/prx100/audit">Сбросить</a>
    </form>

    <div class="prx-card">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3 dark:border-gray-700/60">
            <h2 class="prx-h3"><i class="fas fa-list mr-1.5 text-gray-400"></i>Записи</h2>
            <span class="prx-count"><?php echo count($rows); ?><?php echo $more ? '+' : ''; ?></span>
        </div>
        <?php if (empty($rows)): ?>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">За выбранный период записей нет.</p>
        <?php else: ?>
            <div class="mt-1 overflow-x-auto">
                <table class="prx-table">
                    <thead class="text-gray-400">
                        <tr>
                            <th class="py-2 pr-3 font-semibold">Когда</th>
                            <th class="py-2 pr-3 font-semibold">Кто</th>
                            <th class="py-2 pr-3 font-semibold">Область</th>
                            <th class="py-2 pr-3 font-semibold">Действие</th>
                            <th class="py-2 pr-3 font-semibold">Объект</th>
                            <th class="py-2 font-semibold">Подробности</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-700/40">
                        <?php foreach ($rows as $r): ?>
                        <tr class="align-top" data-audit-row="<?php echo (int) $r['id']; ?>">
                            <td class="whitespace-nowrap py-2 pr-3 text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars((string) $r['created_at']); ?>"><?php echo date('d.m.Y H:i:s', strtotime((string) $r['created_at'])); ?></td>
                            <td class="py-2 pr-3"><a class="prx-link" href="<?php echo HTML::chars($qs(['actor' => (int) $r['actor_id'], 'before' => ''])); ?>"><?php echo HTML::chars($r['actor'] ?: '#'.$r['actor_id']); ?></a></td>
                            <td class="py-2 pr-3"><a class="prx-badge prx-badge-info" href="<?php echo HTML::chars($qs(['category' => $r['category'], 'before' => ''])); ?>"><?php echo HTML::chars($r['category']); ?></a></td>
                            <td class="py-2 pr-3 font-mono text-xs text-gray-700 dark:text-gray-200"><?php echo HTML::chars($r['action']); ?></td>
                            <td class="py-2 pr-3 font-mono text-xs"><?php echo $r['object_id'] === NULL ? '—' : '<a class="prx-link" href="'.HTML::chars($qs(['object' => $r['object_id'], 'category' => $r['category'], 'before' => ''])).'">'.HTML::chars($r['object_id']).'</a>'; ?></td>
                            <td class="max-w-xl break-words py-2 text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars($summary($r['data'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($more): ?>
                <div class="mt-3 text-right">
                    <a class="prx-btn-secondary prx-btn-sm" href="<?php echo HTML::chars($qs(['before' => (int) end($rows)['id']])); ?>">Раньше <i class="fas fa-arrow-right ml-1"></i></a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
