<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /company — создание профиля компании + мои профили.
 */

$status_ru = ['new' => 'в очереди', 'running' => 'работает', 'done' => 'готово', 'error' => 'ошибка'];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <?php if ($flash !== ''): ?>
    <div class="n2j-flash n2j-flash-info mb-3 text-xs block">
        <?php echo HTML::chars($flash); ?>
    </div>
    <?php endif; ?>

    <div class="mb-4">
        <h1 class="n2j-h1"><i class="fas fa-building mr-2 text-blue-500"></i>Профиль компании</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Укажите домен (например, <span class="font-mono">romashka.ru</span>) или название — сервис сам найдёт сайт и соберёт JSON: сфера, размер, контакты, стек технологий, ЛПР.</p>
    </div>

    <div class="gap-4 space-y-4 md:grid md:grid-cols-2 md:items-start md:space-y-0">

        <!-- ============ Левая половина: создание ============ -->
        <?php // Гость видит одну колонку на всю ширину: своей истории профилей у него
              // ещё нет, и пустая правая половина только раздувала бы страницу. ?>
        <div class="space-y-4<?php echo $logged ? '' : ' md:col-span-2'; ?>">
            <div class="n2j-card">

                <?php if ( ! $logged): ?>
                <?php echo Gate::wall('company', [
                    'icon'   => 'fas fa-building',
                    'title'  => 'Профиль компании — по бесплатному аккаунту',
                    'text'   => 'Профиль собирает агент: читает сайт, ищет контакты и ЛПР. Аккаунт нужен, чтобы чтение не расходовалось анонимами, а готовые профили переиспользовались в письмах и досье.',
                    'points' => [
                        'Сфера, описание и примерный масштаб компании — за минуту',
                        'Контакты, найденные на страницах: email, телефоны, адрес',
                        'Стек технологий — детерминированно по разметке сайта',
                        'Сохранённые профили: открываются в «Холодном письме» и досье',
                    ],
                    'stats'  => ['Beta, бесплатно', 'обычно меньше минуты'],
                    'cta'    => 'Войти и собрать профиль',
                    'note'   => 'Честность данных: если на сайте чего-то нет, в профиле будет «не найдено», а не догадка.',
                ]); ?>
                <?php else: ?>
                <form method="post" action="/company" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div>
                        <label class="n2j-label">Домен или название компании <span class="text-rose-500">*</span></label>
                        <input type="text" name="query" required maxlength="300" placeholder="romashka.ru или ООО «Ромашка»"
                               value="<?php echo HTML::chars((string) Arr::get($_GET, 'q', '')); ?>"
                               class="n2j-input mt-1 w-full">
                        <p class="n2j-hint">Название — найдём официальный сайт поиском. Сайты-агрегаторы (соцсети, справочники) игнорируем.</p>
                    </div>
                    <button type="submit" class="n2j-btn-process w-full">
                        <i class="fas fa-magnifying-glass-chart mr-1"></i>Собрать профиль
                    </button>
                    <p class="n2j-hint">Воркер читает страницы в режиме «только текст» (без браузера), обычно это 1–3 минуты. Результат — структурированный JSON, его можно скачать файлом.</p>
                </form>
                <?php endif; ?>
            </div>

            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-1.5 text-gray-400"></i>Что будет в профиле</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-gray-600 dark:text-gray-300">
                    <li><b>Сфера деятельности</b> и краткое описание — чем занимается компания.</li>
                    <li><b>Примерный размер</b> — оценка по тексту сайта (честно: «не найдено», если нет данных).</li>
                    <li><b>Контакты</b> — email, телефоны, адрес, найденные на страницах.</li>
                    <li><b>Стек технологий</b> — CMS, фреймворки, аналитика (детерминированно по разметке).</li>
                    <li><b>ЛПР</b> — имена и роли, если они названы на сайте (только реальные факты).</li>
                </ul>
            </div>
        </div>

        <!-- ============ Правая половина: мои профили ============ -->
        <?php if ($logged): ?>
        <div class="space-y-4" id="my">
            <?php // Список своих профилей — только для вошедших: у гостя их ещё нет. ?>
            <div class="n2j-card">
                <p class="text-sm font-bold text-gray-900 dark:text-white"><i class="fas fa-clock-rotate-left mr-1.5 text-gray-400"></i>Мои профили</p>

                <?php if (empty($my)): ?>
                <p class="n2j-hint mt-2">Пока нет ни одного профиля.</p>
                <?php else: ?>
                <ul class="mt-2 space-y-1.5">
                    <?php foreach ($my as $r): ?>
                    <li class="rounded-xl border border-gray-100 px-3 py-2 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <a href="/company/<?php echo HTML::chars($r['code']); ?>" class="font-mono text-xs font-bold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars($r['code']); ?></a>
                            <span class="n2j-badge <?php echo $r['status'] === 'done' ? 'n2j-badge-ok' : ($r['status'] === 'error' ? 'n2j-badge-err' : 'n2j-badge-info'); ?> uppercase">
                                <?php echo $status_ru[$r['status']] ?? HTML::chars($r['status']); ?>
                            </span>
                            <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(($r['company'] !== '' ? $r['company'] : $r['query'])); ?></span>
                            <span class="text-xs text-gray-400"><?php echo HTML::chars((string) $r['created_at']); ?></span>
                            <form method="post" action="/company/<?php echo HTML::chars($r['code']); ?>/delete" class="ml-auto">
                                <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                <button data-n2j-confirm="Удалить профиль «<?php echo HTML::chars(($r['company'] !== '' ? $r['company'] : $r['query'])); ?>» полностью? Отменить нельзя." data-n2j-title="Удалить профиль?" type="submit" class="n2j-btn-danger n2j-btn-sm">Удалить</button>
                            </form>
                        </div>
                        <?php if ($r['status'] === 'error' AND $r['error']): ?>
                        <div class="mt-1 w-full truncate text-xs text-rose-400" title="<?php echo HTML::chars($r['error']); ?>"><?php echo HTML::chars(mb_substr($r['error'], 0, 120)); ?></div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php echo View::factory('591/script'); ?>
