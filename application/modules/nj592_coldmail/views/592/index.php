<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /coldmail — создание письма + мои прогоны.
 */

$status_ru = ['new' => 'в очереди', 'running' => 'работает', 'done' => 'готово', 'error' => 'ошибка'];
$max_n = (int) ($limits['max_variants'] ?? 5);
$def_n = (int) ($limits['default_variants'] ?? 3);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-3 block">
        <?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <div class="mb-4">
        <h1 class="n2j-h1"><i class="fas fa-envelope-open-text mr-2 text-blue-500"></i>Холодное письмо</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Укажите компанию и что вы предлагаете — ИИ изучит профиль и свежие новости и напишет персонализированный первый абзац: с конкретным крючком, а не «надеюсь, письмо нашло вас хорошо».</p>
    </div>

    <div class="gap-5 space-y-4 md:grid md:grid-cols-3 md:items-start md:space-y-0">

        <!-- ============ Левая часть (2/3): создание ============ -->
        <div class="space-y-4 md:col-span-2">
            <div class="n2j-card">

                <?php if ( ! $logged): ?>
                <?php echo Gate::wall('coldmail', [
                    'icon'   => 'fas fa-envelope-open-text',
                    'title'  => 'Холодное письмо — по бесплатному аккаунту',
                    'text'   => 'Письмо пишет LLM по профилю компании и свежим новостям: факты не выдумываются, а берутся из источников. Аккаунт нужен, чтобы очередь и время модели не расходовались анонимами, а черновики хранились у вас.',
                    'points' => [
                        '3–5 вариантов первого абзаца с конкретным крючком под компанию',
                        'Факты только из профиля компании и свежих новостей — без выдумок',
                        'Письмо можно отправить себе или сразу в серию касаний',
                        'Черновики хранятся в аккаунте: открыть с любого устройства',
                    ],
                    'stats'  => ['Beta, бесплатно', '3–5 вариантов за раз'],
                    'cta'    => 'Войти и написать письмо',
                    'note'   => 'Сначала лучше собрать профиль компании — тогда крючок будет точнее. Профиль доступен в сервисе «Профиль компании».',
                ]); ?>
                <?php else: ?>
                <form method="post" action="/coldmail" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

                    <div>
                        <label class="n2j-label">Профиль компании <span class="font-normal text-xs text-gray-400">(если уже собирали — точнее будет)</span></label>
                        <?php if (empty($profiles)): ?>
                        <p class="n2j-hint">Готовых профилей нет — соберите в сервисе <a href="/company" class="text-blue-600 hover:underline dark:text-blue-400">«Профиль компании»</a> или просто укажите домен/название ниже.</p>
                        <?php else: ?>
                        <?php $pre_profile = (string) Arr::get($_GET, 'profile', ''); ?>
                        <select name="profile_code" class="n2j-input n2j-select mt-1 w-full">
                            <option value="">— без профиля, взять компанию ниже —</option>
                            <?php foreach ($profiles as $pr): ?>
                            <option value="<?php echo HTML::chars($pr['code']); ?>"<?php echo $pre_profile === (string) $pr['code'] ? ' selected' : ''; ?>><?php echo HTML::chars($pr['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="n2j-label">Компания <span class="font-normal text-xs text-gray-400">(домен или название — если нет профиля)</span></label>
                        <input type="text" name="company" maxlength="300" placeholder="romashka.ru или ООО «Ромашка»"
                               value="<?php echo HTML::chars((string) Arr::get($_GET, 'company', '')); ?>"
                               class="n2j-input mt-1 w-full">
                    </div>

                    <div>
                        <label class="n2j-label">Что вы предлагаете <span class="text-rose-500">*</span></label>
                        <textarea name="product" required rows="3" maxlength="2000" placeholder="Например: внедряем CRM для оптовой торговли; берём на себя бухгалтерию производственных компаний; поставляем упаковку для food-ритейла…"
                                  class="n2j-textarea min-h-[44px] mt-1 w-full"></textarea>
                    </div>

                    <div>
                        <label class="n2j-label">Кому пишете <span class="font-normal text-xs text-gray-400">(необязательно)</span></label>
                        <input type="text" name="audience" maxlength="300" placeholder="коммерческий директор, владелец сети…"
                               class="n2j-input mt-1 w-full">
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <div>
                            <label class="n2j-label">Тон</label>
                            <select name="tone" class="n2j-input n2j-select mt-1 w-auto">
                                <option value="business">деловой</option>
                                <option value="friendly">дружелюбный</option>
                                <option value="bold">смелый</option>
                            </select>
                        </div>
                        <div>
                            <label class="n2j-label">Вариантов</label>
                            <select name="variants" class="min-h-[44px] mt-1 block w-24 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                <?php for ($i = 1; $i <= $max_n; $i++): ?>
                                <option value="<?php echo $i; ?>"<?php echo $i === $def_n ? ' selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="n2j-btn-process w-full">
                        <i class="fas fa-pen-nib mr-1"></i>Написать варианты
                    </button>
                    <p class="text-sm text-gray-400">Факты для персонализации — только из профиля компании и открытых новостей; выдуманных крючков не будет.</p>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ Правая часть (1/3): мои прогоны ============ -->
        <div class="space-y-4" id="my">
            <?php // У гостя своих писем ещё нет: пустой список не показываем. ?>
            <?php if ($logged): ?>
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-clock-rotate-left mr-1.5 text-gray-400"></i>Мои письма</p>

                <?php if (empty($my)): ?>
                <p class="mt-2 border border-dashed border-gray-300 p-4 text-center text-sm text-gray-400 dark:border-gray-600">Пока нет ни одного прогона.</p>
                <?php else: ?>
                <ul class="mt-2 space-y-1.5">
                    <?php foreach ($my as $r): ?>
                    <li class="rounded-xl border border-gray-100 px-3 py-2 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <a href="/coldmail/<?php echo HTML::chars($r['code']); ?>" class="font-mono text-xs font-bold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($r['code']); ?></a>
                            <span class="n2j-badge <?php echo $r['status'] === 'done' ? 'n2j-badge-ok' : ($r['status'] === 'error' ? 'n2j-badge-err' : 'n2j-badge-info'); ?> uppercase">
                                <?php echo $status_ru[$r['status']] ?? HTML::chars($r['status']); ?>
                            </span>
                            <span class="min-w-0 flex-1 truncate text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars($r['company_query']); ?></span>
                            <span class="text-xs text-gray-400"><?php echo HTML::chars((string) $r['created_at']); ?></span>
                            <form method="post" action="/coldmail/<?php echo HTML::chars($r['code']); ?>/delete" class="ml-auto">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <button data-n2j-confirm="Удалить прогон «<?php echo HTML::chars($r['company_query']); ?>» полностью? Отменить нельзя." data-n2j-title="Удалить прогон?" type="submit" class="n2j-btn-danger n2j-btn-sm">Удалить</button>
                            </form>
                        </div>
                        <?php if ($r['status'] === 'error' AND $r['error']): ?>
                        <div class="mt-1 w-full truncate text-sm text-rose-400" title="<?php echo HTML::chars($r['error']); ?>"><?php echo HTML::chars(mb_substr($r['error'], 0, 120)); ?></div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Как это работает</p>
                <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <li>Берём профиль компании из «Профиля компании» (или ищем сайт сами) и свежие новости о ней.</li>
                    <li>ИИ пишет несколько вариантов первого абзаца: конкретный крючок под эту компанию.</li>
                    <li>Факты — только из профиля и открытых новостей; выдуманных крючков не будет.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php echo View::factory('592/script'); ?>
