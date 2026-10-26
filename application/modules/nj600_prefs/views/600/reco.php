<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj600/admin/reco — рекомендации под надзором: KPI показов (30 дн.), CTR по
 * источникам и сервисам, правила администратора (создание/вкл/выкл/удаление),
 * свежий журнал показов.
 */

$on = function ($v) {
    return $v === TRUE OR $v === 't' OR $v === '1' OR $v === 1;
};

$fmt = function ($ts, $f = 'd.m.y H:i') {
    $ts = (string) $ts;

    return ($ts !== '' AND $ts !== '0') ? HTML::chars(Date::formatted_time($ts, $f)) : '—';
};

$source_badge = function ($source) {
    static $cls = ['rule' => 'n2j-badge-info', 'covisit' => 'n2j-badge-ok', 'group' => 'n2j-badge-wait', 'manual' => 'n2j-badge-run'];

    return '<span class="n2j-badge '.($cls[$source] ?? 'n2j-badge-wait').'">'.HTML::chars(Service_Nj600_Reco::source_label($source)).'</span>';
};

$kpi = $stats['kpi'];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
        <a href="/dashboard" class="transition hover:text-blue-600 dark:hover:text-blue-400">Кабинет</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <a href="/nj600/admin" class="transition hover:text-blue-600 dark:hover:text-blue-400">Предпочтения</a>
        <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
        <span class="font-semibold text-gray-700 dark:text-gray-300">Рекомендации · надзор</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1"><i class="fas fa-wand-magic-sparkles mr-2 text-blue-500"></i>Рекомендации под надзором</h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-xs"><i class="fas fa-user-shield text-[11px]"></i>правил: <?php echo (int) $stats['rules_total']; ?></span>
    </div>
    <p class="n2j-hint mt-1.5 max-w-3xl">
        Что платформа советует людям в кабинете («Вам может пригодиться»), почему, и как они на это реагируют.
        Источники: правила администратора → похожие посетители (совместные посещения по компактным профилям) → тот же раздел каталога.
        Уже посещённое, запрещённое и отклонённое самим человеком не предлагается.
    </p>

    <?php if ((string) $flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mt-4 text-xs font-semibold block"><?php echo HTML::chars($flash); ?></div>
    <?php endif; ?>

    <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Показов (<?php echo (int) $stats['days']; ?> дн.)</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) $kpi['shown']; ?></p>
            <p class="n2j-hint">владельцев: <?php echo (int) $kpi['owners']; ?></p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Кликов</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) $kpi['clicked']; ?></p>
            <p class="n2j-hint">CTR <?php echo number_format((float) $kpi['ctr'], 1, ',', ' '); ?> %</p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Отказов</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) $kpi['dismissed']; ?></p>
            <p class="n2j-hint">«не предлагать» от самих людей</p>
        </div>
        <div class="n2j-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Правил активно</p>
            <p class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl dark:text-white"><?php echo (int) $stats['rules_total']; ?></p>
            <p class="n2j-hint">всего в списке: <?php echo count($rules); ?></p>
        </div>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Правила + статистика (2/3) ============ -->
        <div class="min-w-0 space-y-6 md:col-span-2">

            <div class="n2j-card p-0 overflow-x-auto">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h2 class="n2j-h3"><i class="fas fa-scale-balanced mr-1.5 text-blue-500"></i>Правила администратора</h2>
                    <span class="text-xs text-gray-400">boost — советовать, block — не советовать; пустые «кому» = всем</span>
                </div>
                <table class="n2j-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pl-4 pr-3 sm:pl-5">Вид</th>
                            <th class="py-2.5 px-3">Кому</th>
                            <th class="py-2.5 px-3">Что</th>
                            <th class="py-2.5 px-3">Вес</th>
                            <th class="py-2.5 pr-4 text-right sm:pr-5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                        <?php foreach ($rules as $r): $en = $on($r['enabled']); ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25 <?php echo $en ? '' : 'opacity-50'; ?>">
                            <td class="py-2.5 pl-4 pr-3 sm:pl-5"><span class="n2j-badge <?php echo $r['kind'] === 'block' ? 'n2j-badge-err' : 'n2j-badge-info'; ?>"><?php echo $r['kind'] === 'block' ? 'не советовать' : 'советовать'; ?></span></td>
                            <td class="px-3 py-2.5 text-xs">
                                <?php if ((string) $r['when_owner'] !== ''): ?>
                                <a href="/nj600/admin/owner/<?php echo rawurlencode((string) $r['when_owner']); ?>" class="font-mono font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($r['when_owner']); ?></a>
                                <?php elseif ((string) $r['when_section'] !== ''): ?>
                                интересующимся <span class="font-mono font-semibold"><?php echo HTML::chars($r['when_section']); ?></span>
                                <?php else: ?><span class="text-gray-400">всем</span><?php endif; ?>
                            </td>
                            <td class="max-w-[18rem] truncate px-3 py-2.5">
                                <a href="<?php echo HTML::chars($r['href']); ?>" class="font-mono text-xs font-semibold text-gray-800 hover:text-blue-600 dark:text-gray-200"><?php echo HTML::chars($r['href']); ?></a>
                                <?php if ((string) $r['title'] !== '' OR (string) $r['note'] !== ''): ?><span class="block truncate text-xs text-gray-400" title="<?php echo HTML::chars($r['title'].' '.$r['note']); ?>"><?php echo HTML::chars(trim($r['title'].' — '.$r['note'], ' —')); ?></span><?php endif; ?>
                            </td>
                            <td class="px-3 py-2.5 font-mono text-xs"><?php echo (int) $r['weight']; ?></td>
                            <td class="py-2.5 pr-4 text-right sm:pr-5">
                                <div class="inline-flex gap-1.5">
                                    <form method="post" action="/nj600/admin/rule_toggle/<?php echo (int) $r['id']; ?>">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <button type="submit" class="n2j-btn-secondary n2j-btn-sm" title="<?php echo $en ? 'Выключить' : 'Включить'; ?>"><i class="fas <?php echo $en ? 'fa-toggle-on text-emerald-500' : 'fa-toggle-off text-gray-400'; ?>"></i></button>
                                    </form>
                                    <form method="post" action="/nj600/admin/rule_delete/<?php echo (int) $r['id']; ?>">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <button type="submit" data-n2j-arm="Удалить?" class="n2j-btn-danger-soft n2j-btn-sm" title="Удалить правило"><i class="fas fa-trash text-xs"></i><span data-n2j-arm-label class="sr-only">Удалить</span></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rules)): ?>
                        <tr><td colspan="5" class="py-6 pl-4 text-center text-sm text-gray-400 sm:pl-5">Правил нет — работают только автоматические источники (похожие посетители, тот же раздел).</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="n2j-card">
                    <p class="text-sm font-bold text-gray-900 dark:text-white">По источникам (<?php echo (int) $stats['days']; ?> дн.)</p>
                    <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                        <?php foreach ($stats['by_source'] as $r): ?>
                        <li class="flex items-center justify-between gap-3 border-b border-gray-50 pb-1.5 dark:border-gray-700/50">
                            <span><?php echo $source_badge($r['source']); ?></span>
                            <span class="font-mono text-xs text-gray-500"><?php echo (int) $r['shown']; ?> → <?php echo (int) $r['clicked']; ?> <span class="text-gray-400">(<?php echo number_format((float) $r['ctr'], 1, ',', ' '); ?> %)</span><?php echo (int) $r['dismissed'] ? ' <span class="text-rose-400">✕'.(int) $r['dismissed'].'</span>' : ''; ?></span>
                        </li>
                        <?php endforeach; ?>
                        <?php if (empty($stats['by_source'])): ?><li class="text-xs text-gray-400">Показов пока не было — виджет появится в кабинете, когда накопятся профили.</li><?php endif; ?>
                    </ul>
                </div>
                <div class="n2j-card">
                    <p class="text-sm font-bold text-gray-900 dark:text-white">По сервисам</p>
                    <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                        <?php foreach ($stats['by_href'] as $r): $sec = Service_Nj600_Reco::section_of_href($r['href']); ?>
                        <li class="flex items-center justify-between gap-3 border-b border-gray-50 pb-1.5 dark:border-gray-700/50">
                            <span class="min-w-0 truncate"><a href="<?php echo HTML::chars($r['href']); ?>" class="font-semibold hover:text-blue-600 hover:dark:text-blue-400"><?php echo HTML::chars(isset($catalog[$sec]) ? $catalog[$sec]['title'] : $r['href']); ?></a> <span class="font-mono text-xs text-gray-400"><?php echo HTML::chars($r['href']); ?></span></span>
                            <span class="shrink-0 font-mono text-xs text-gray-500"><?php echo (int) $r['shown']; ?> → <?php echo (int) $r['clicked']; ?> <span class="text-gray-400">(<?php echo number_format((float) $r['ctr'], 1, ',', ' '); ?> %)</span></span>
                        </li>
                        <?php endforeach; ?>
                        <?php if (empty($stats['by_href'])): ?><li class="text-xs text-gray-400">Пусто.</li><?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="n2j-card p-0 overflow-x-auto">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3.5 sm:px-5 dark:border-gray-700/60">
                    <h2 class="n2j-h3">Последние показы</h2>
                    <span class="n2j-badge n2j-badge-wait"><?php echo count($stats['recent']); ?></span>
                </div>
                <table class="n2j-table whitespace-nowrap">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700/60 dark:bg-gray-900/30 dark:text-gray-400">
                            <th class="py-2.5 pl-4 pr-3 sm:pl-5">Когда</th>
                            <th class="py-2.5 px-3">Кому</th>
                            <th class="py-2.5 px-3">Что</th>
                            <th class="py-2.5 px-3">Источник</th>
                            <th class="py-2.5 pr-4 text-right sm:pr-5">Итог</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm text-gray-600 dark:divide-gray-700/60 dark:text-gray-300">
                        <?php foreach ($stats['recent'] as $l): ?>
                        <tr class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-700/25">
                            <td class="py-2 pl-4 pr-3 text-xs text-gray-500 sm:pl-5"><?php echo $fmt($l['shown_at'], 'd.m H:i'); ?></td>
                            <td class="px-3 py-2"><a href="/nj600/admin/owner/<?php echo rawurlencode((string) $l['owner_key']); ?>" class="font-mono text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($l['owner_key']); ?></a></td>
                            <td class="max-w-[16rem] truncate px-3 py-2 font-mono text-xs" title="<?php echo HTML::chars($l['reason']); ?>"><?php echo HTML::chars($l['href']); ?></td>
                            <td class="px-3 py-2"><?php echo $source_badge($l['source']); ?></td>
                            <td class="py-2 pr-4 text-right sm:pr-5">
                                <?php if ($l['clicked_at']): ?><span class="n2j-badge n2j-badge-ok">клик</span>
                                <?php elseif ($l['dismissed_at']): ?><span class="n2j-badge n2j-badge-err">отказ</span>
                                <?php else: ?><span class="text-xs text-gray-300 dark:text-gray-600">показано</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($stats['recent'])): ?>
                        <tr><td colspan="5" class="py-6 pl-4 text-center text-sm text-gray-400 sm:pl-5">Журнал пуст.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============ Новое правило + справка (1/3) ============ -->
        <div class="min-w-0 space-y-5 md:col-span-1">
            <div class="n2j-card">
                <h3 class="n2j-h3 border-b border-gray-100 pb-3 dark:dark:border-gray-800"><i class="fas fa-plus mr-1.5 text-blue-500"></i>Новое правило</h3>
                <form method="post" action="/nj600/admin/rule" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div>
                        <label class="n2j-label" for="rule-kind">Вид</label>
                        <select id="rule-kind" name="kind" class="n2j-select mt-1 w-full">
                            <option value="boost">советовать (boost)</option>
                            <option value="block">не советовать (block)</option>
                        </select>
                    </div>
                    <div>
                        <label class="n2j-label" for="rule-href">Что (сервис)</label>
                        <input list="rule-hrefs" id="rule-href" name="href" required placeholder="/vat" class="n2j-input mt-1 w-full">
                        <datalist id="rule-hrefs">
                            <?php foreach ($catalog as $c): ?><option value="<?php echo HTML::chars($c['href']); ?>"><?php echo HTML::chars($c['title']); ?></option><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label class="n2j-label" for="rule-section">Кому: интересующимся разделом <span class="font-normal text-gray-400">(пусто — всем)</span></label>
                        <input list="rule-sections" id="rule-section" name="when_section" placeholder="jobs" class="n2j-input mt-1 w-full">
                        <datalist id="rule-sections">
                            <?php foreach ($catalog as $c): ?><option value="<?php echo HTML::chars($c['section']); ?>"><?php echo HTML::chars($c['title']); ?></option><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label class="n2j-label" for="rule-owner">Кому: конкретному владельцу <span class="font-normal text-gray-400">(u:12 / g:…)</span></label>
                        <input type="text" id="rule-owner" name="when_owner" placeholder="" class="n2j-input mt-1 w-full">
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="n2j-label" for="rule-weight">Вес</label>
                            <input type="number" id="rule-weight" name="weight" value="50" min="0" max="999" class="n2j-input mt-1 w-full">
                        </div>
                        <div class="col-span-2">
                            <label class="n2j-label" for="rule-title">Заголовок <span class="font-normal text-gray-400">(необяз.)</span></label>
                            <input type="text" id="rule-title" name="title" maxlength="120" class="n2j-input mt-1 w-full">
                        </div>
                    </div>
                    <div>
                        <label class="n2j-label" for="rule-note">Почему <span class="font-normal text-gray-400">(увидит пользователь)</span></label>
                        <input type="text" id="rule-note" name="note" maxlength="300" placeholder="рядом с расчётом НДС пригодится…" class="n2j-input mt-1 w-full">
                    </div>
                    <button type="submit" class="n2j-btn-cta w-full">Добавить правило</button>
                </form>
            </div>

            <div class="hidden rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs md:block dark:border-gray-700/60 dark:bg-gray-800/40">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-circle-info text-blue-600 dark:text-blue-400"></i>
                    <h3 class="text-sm font-bold">Как ранжируется</h3>
                </div>
                <ol class="mt-2.5 list-decimal space-y-1.5 pl-4 text-xs text-gray-600 dark:text-gray-300">
                    <li><b>Правила администратора</b> (1000 + вес) — всегда первые; адресные (владельцу) сильнее общих.</li>
                    <li><b>Похожие посетители</b> (100–200): у кого те же топ-разделы, что ещё смотрят. Считается по компактным профилям, сырьё не нужно.</li>
                    <li><b>Тот же раздел каталога</b> (до 50): сервисы из группы топ-разделов владельца.</li>
                    <li>Не предлагается: уже посещённое (кроме правил), block-правила, отклонённое человеком за <?php echo (int) Service_Nj600_Reco::DISMISS_DAYS; ?> дн., скрытое из каталога.</li>
                    <li>Каждый показ — строка журнала с причиной; клик идёт через <span class="font-mono">/prefs/api/go</span>, отказ — крестик в виджете. Журнал чистится через /nj100/cleanup (90 дн.).</li>
                </ol>
            </div>
        </div>
    </div>
</div>
