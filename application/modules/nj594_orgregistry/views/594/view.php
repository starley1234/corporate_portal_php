<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Карточка организации (nj594): реквизиты ЕГРЮЛ/ЕГРИП + связанные материалы модулей.
 */

$name    = (string) ($o['short_name'] ?: ($o['full_name'] ?: $o['ogrn']));
$active  = (mb_stripos((string) $o['status'], 'действ') !== FALSE);
$rowkv   = function ($label, $value, $mono = FALSE) {
    return '<div class="py-2.5 sm:grid sm:grid-cols-[10rem_1fr] sm:gap-3">'
        .'<dt class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 sm:pt-0.5">'.HTML::chars($label).'</dt>'
        .'<dd class="mt-0.5 break-words text-sm text-gray-800 dark:text-gray-100'.($mono ? ' font-mono' : '').'">'
        .($value !== '' ? HTML::chars($value) : '<span class="text-gray-400 dark:text-gray-500">—</span>').'</dd></div>';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <!-- Хедер-карточка: крошки, код + статус, название, действия -->
    <div class="n2j-card">
        <nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
            <a href="/orgs" class="transition hover:text-blue-600 dark:hover:text-blue-400">Справочник организаций</a>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
            <span class="font-mono font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($o['ogrn']); ?></span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-4 dark:border-gray-700/60">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="n2j-badge n2j-badge-info font-mono"><?php echo HTML::chars($o['ogrn']); ?></span>
                    <span class="n2j-badge <?php echo $active ? 'n2j-badge-ok' : 'n2j-badge-wait'; ?> uppercase">
                        <?php echo HTML::chars((string) ($o['status'] !== '' ? $o['status'] : 'статус уточняется')); ?>
                    </span>
                    <span class="n2j-badge n2j-badge-wait uppercase"><?php echo HTML::chars($o['form'] ?: 'ЮЛ'); ?></span>
                </div>
                <h1 class="n2j-h1 mt-1.5"><?php echo HTML::chars($name); ?></h1>
                <div class="mt-2"><?php echo View::factory('partials/ontology_link', [
                    'title' => $name,
                    'kind'  => 'org',
                ]); ?></div>
                <?php if ((string) $o['full_name'] !== '' AND $o['full_name'] !== $name): ?>
                <p class="n2j-hint"><?php echo HTML::chars($o['full_name']); ?></p>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <?php if ($is_admin): ?>
                <form method="post" action="/orgs/admin/refresh/<?php echo (int) $o['id']; ?>" class="nj594-refresh">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button type="submit" class="n2j-btn-secondary">
                        <i class="fas fa-arrows-rotate text-xs text-gray-400"></i><span class="nj594-refresh-label">Обновить из ФНС</span>
                    </button>
                </form>
                <?php endif; ?>
                <a href="/orgs" class="n2j-btn-secondary">
                    <i class="fas fa-arrow-left text-xs text-gray-400"></i>К справочнику
                </a>
            </div>
        </div>
    </div>

    <div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Левая колонка (2/3): реквизиты + связанные материалы ============ -->
        <div class="min-w-0 space-y-4 md:col-span-2">

            <div class="n2j-card">
                <h2 class="n2j-h3 border-b border-gray-100 pb-3"><i class="fas fa-file-contract mr-1.5 text-blue-500"></i>Реквизиты (ЕГРЮЛ/ЕГРИП)</h2>
                <dl class="mt-1 divide-y divide-gray-50 dark:divide-gray-700/40">
                    <?php echo $rowkv('ОГРН / ОГРНИП', (string) $o['ogrn'], TRUE); ?>
                    <?php echo $rowkv('ИНН', (string) $o['inn'], TRUE); ?>
                    <?php echo $rowkv('КПП', (string) $o['kpp'], TRUE); ?>
                    <?php echo $rowkv('Дата регистрации', (string) $o['reg_date']); ?>
                    <?php echo $rowkv('Руководитель', (string) $o['director']); ?>
                    <?php echo $rowkv('Основной ОКВЭД', trim((string) $o['okved'].((string) $o['okved_name'] !== '' ? ' — '.$o['okved_name'] : ''))); ?>
                    <?php echo $rowkv('Юридический адрес', (string) $o['address']); ?>
                </dl>
                <?php if ( ! $active): ?>
                <p class="n2j-flash n2j-flash-warn mt-3 text-xs block"><i class="fas fa-triangle-exclamation mr-1.5"></i>Статус в ЕГРЮЛ отличается от «действует» — перед сделками запросите свежую выписку из ФНС.</p>
                <?php endif; ?>
            </div>

            <!-- Проверка компании (nj635): те же сигналы и вакансии, что на /check -->
            <div class="n2j-card">
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 pb-3 dark:border-gray-700/60">
                    <h2 class="n2j-h3"><i class="fas fa-shield-halved mr-1.5 text-blue-500"></i>Проверка: сигналы и вакансии</h2>
                    <a href="/check?q=<?php echo rawurlencode((string) $o['inn'] !== '' ? (string) $o['inn'] : (string) $o['ogrn']); ?>" class="n2j-btn-secondary n2j-btn-sm ml-auto">Карточка проверки →</a>
                </div>

                <?php if ( ! empty($check['signals'])): ?>
                <ul class="mt-3 space-y-2">
                    <?php foreach ($check['signals'] as $s): ?>
                    <?php
                        $sl = [
                            'danger' => 'fas fa-triangle-exclamation text-rose-500',
                            'warn'   => 'fas fa-circle-exclamation text-amber-500',
                            'ok'     => 'fas fa-circle-check text-emerald-500',
                            'info'   => 'fas fa-circle-info text-gray-400',
                        ];
                        $sl_i = (string) ($s['level'] ?? 'info');
                    ?>
                    <li class="flex items-start gap-2 text-xs text-gray-600 dark:text-gray-300">
                        <i class="<?php echo $sl[$sl_i] ?? $sl['info']; ?> mt-0.5 shrink-0"></i>
                        <span class="min-w-0"><b class="text-gray-900 dark:text-white"><?php echo HTML::chars($s['title']); ?></b> — <?php echo HTML::chars($s['text']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php $vac = $check['vacancies']; ?>
                <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-700/60">
                    <p class="text-xs font-semibold text-gray-900 dark:text-white">Вакансии компании в собранной базе: активных — <?php echo (int) $vac['active']; ?>, всего — <?php echo (int) $vac['total']; ?></p>
                    <?php if ( ! empty($vac['items'])): ?>
                    <ul class="mt-2 space-y-1.5">
                        <?php foreach ($vac['items'] as $v): ?>
                        <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                            <a href="<?php echo HTML::chars((string) $v['url']); ?>" target="_blank" rel="noopener noreferrer nofollow" class="min-w-0 flex-1 break-words font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $v['title']); ?></a>
                            <span class="text-gray-400"><?php echo HTML::chars((string) $v['city']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="n2j-hint">Вакансий этой компании мы пока не собирали — <a href="/vacancies" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">поставьте монитор вакансий</a>.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="n2j-card">
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 pb-3 dark:border-gray-700/60">
                    <h2 class="n2j-h3"><i class="fas fa-link mr-1.5 text-blue-500"></i>Материалы платформы по организации</h2>
                    <span class="n2j-badge n2j-badge-wait ml-auto"><?php echo count($links); ?></span>
                </div>
                <?php if (empty($links)): ?>
                <p class="n2j-hint mt-3">Пока ничего не привязано. Модули платформы (например, «Документы организации») добавляют сюда свои материалы автоматически, когда работают с этой организацией.</p>
                <?php else: ?>
                <ul class="mt-3 space-y-2">
                    <?php foreach ($links as $l): ?>
                    <li class="rounded-lg border border-gray-100 px-3 py-2.5 transition-colors hover:bg-gray-50/70 dark:border-gray-700 dark:hover:bg-gray-700/25">
                        <div class="flex flex-wrap items-center gap-2">
                            <i class="<?php echo HTML::chars(Service_Nj594_Orgs::kind_icon($l['kind'])); ?> text-xs text-blue-500"></i>
                            <?php if ((string) $l['item_url'] !== ''): ?>
                            <a href="<?php echo HTML::chars($l['item_url']); ?>" class="min-w-0 flex-1 truncate text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($l['title'] ?: $l['item_code']); ?></a>
                            <?php else: ?>
                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($l['title'] ?: $l['item_code']); ?></span>
                            <?php endif; ?>
                            <span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars(Service_Nj594_Orgs::module_label($l['module'])); ?></span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ Компаньон (1/3) ============ -->
        <div class="hidden space-y-5 md:block">
            <div class="n2j-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Источник данных</h3>
                <dl class="mt-2 space-y-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Первичный посев</dt><dd class="text-right"><?php echo (string) $o['source'] === 'seed' ? 'да' : 'нет'; ?></dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Из ФНС</dt><dd class="text-right"><?php echo (string) $o['source'] === 'fns' ? 'да' : ($o['fetched_at'] ? 'да' : 'нет'); ?></dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Обновлено</dt><dd class="text-right"><?php echo (string) ($o['fetched_at'] ? Date::formatted_time((string) $o['fetched_at'], 'd.m.Y H:i') : '—'); ?></dd></div>
                </dl>
                <p class="n2j-hint mt-2 border-t border-gray-100 pt-2">Точечное обновление — сервис ФНС egrul.nalog.ru. Полнота гарантируется официальной выпиской.</p>
            </div>

            <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-4 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40 sm:p-5">
                <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    <h3 class="text-sm font-bold">Советы</h3>
                </div>
                <ul class="mt-2.5 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Ссылку на карточку можно давать коллегам: <span class="font-mono">/orgs/<?php echo HTML::chars($o['ogrn']); ?></span> — открывается без входа.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Для бухгалтерии и сделок запрашивайте выписку с ЭЦП ФНС — карточка здесь для быстрых решений.</span></li>
                    <li class="flex items-start gap-1.5"><span class="font-bold text-blue-500">•</span><span>Создаёте документы по организации — они появятся в блоке «Материалы платформы».</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($is_admin) { echo View::factory('594/script'); } ?>
