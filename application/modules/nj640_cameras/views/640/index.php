<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Камеры цеха (nj640_cameras) — страница /cameras.
 * Сценарии (пресеты тем) + камеры (источник/расписание) + журнал событий +
 * ручная проверка кадра. Проверка на сервере: /cameras/api/run ставит задачу
 * канала nj640.analyze (воркер + VLM).
 */
$scenes   = isset($scenes) ? $scenes : [];
$cameras  = isset($cameras) ? $cameras : [];
$events   = isset($events) ? $events : [];
$recent   = isset($recent) ? $recent : [];
$topics   = isset($topics) ? $topics : [];
$kinds    = isset($kinds) ? $kinds : [];
$triggers = isset($triggers) ? $triggers : [];
$rate_text = isset($rate_text) ? $rate_text : '5 операций в минуту';
$max_mb   = isset($max_img_mb) ? $max_img_mb : '15';
// Гость видит плашку входа и справку вместо формы (владелец, 08.10.2026).
$logged   = ! empty($logged);
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">Камеры цеха</h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-video text-[11px]"></i>Видеонаблюдение
        </span>
    </div>
</div>
<p class="mt-1.5 text-xs sm:text-sm text-gray-600 dark:text-gray-300">Камеры в цеху или офисе: трудовая дисциплина, средства защиты (СИЗ) и загрузка оборудования. Кадр снимается по триггеру, анализируется VLM и проверяется схемой; нарушения — в журнал событий с уведомлениями.</p>

<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

    <!-- ============================ форма (2/3) ============================ -->
    <div class="space-y-6 md:col-span-2">

        <?php if ( ! $logged): ?>
        <?php echo Gate::wall('cameras', [
            'icon'   => 'fas fa-video',
            'title'  => 'Камеры цеха — по бесплатному аккаунту',
            'text'   => 'Кадр с камеры уходит в vision-модель, а события и статистика относятся к вашему производству. Аккаунт отделяет людей от ботов и привязывает сценарии, камеры и журнал к вам.',
            'points' => [
                'Сценарии: трудовая дисциплина, СИЗ (каска, жилет, очки), загрузка оборудования',
                'Ручная проверка кадра и авторежим: камера, HTTP-кадр, вебхук, таймер',
                'Журнал событий: подтверждение, ложная тревога, пауза между тревогами',
                'Уведомления о нарушениях и защита от повторных находок с той же камеры',
            ],
            'stats'  => ['Beta, бесплатно', 'Лимит: '.$rate_text],
            'cta'    => 'Войти и подключить камеру',
            'note'   => 'Видео не шлётся потоком: один кадр по триггеру. Для камер в локальной сети у воркера включается NJ640_ALLOW_LAN=1 — адреса остаются у вас.',
        ]); ?>

        <section class="n2j-card">
            <h2 class="n2j-h3">Как это работает</h2>
            <ul class="mt-3 space-y-2.5 text-xs leading-relaxed text-gray-600 dark:text-gray-300 sm:text-sm">
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Триггер (таймер или вебхук) → захват кадра → VLM → JSON-схема → ответ API и запись в журнал.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Видео не шлётся потоком: один кадр только по событию — трафик и приватность под контролем.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Повторные находки с той же камеры не плодят события: dedupe и пауза между тревогами.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Вы подтверждаете событие или помечаете ложной тревогой — статистику сбоев видно.</span></li>
            </ul>
        </section>
        <?php else: ?>

        <!-- ----------------------------- ручная проверка ----------------------------- -->
        <div class="n2j-card">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="n2j-h3">Ручная проверка кадра</h2>
            </div>

            <div class="mt-4 space-y-4 sm:mt-5">

                <div>
                    <label for="nj640-photo" class="n2j-label">Кадр с камеры</label>
                    <input id="nj640-photo" type="file" accept="image/*"
                           class="mt-1.5 w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                    <p class="n2j-hint">jpg/png/webp/bmp/gif, до <?php echo HTML::chars($max_mb); ?> МБ.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                    <div>
                        <label for="nj640-scene-select" class="n2j-label">Сценарий</label>
                        <select id="nj640-scene-select" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                            <option value="0">— новый сценарий —</option>
                            <?php foreach ($scenes as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>"><?php echo HTML::chars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="nj640-topic" class="n2j-label">Тема (пресет)</label>
                        <select id="nj640-topic" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                            <?php foreach ($topics as $tk => $tv): ?>
                            <option value="<?php echo HTML::chars($tk); ?>"><?php echo HTML::chars($tv['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="nj640-scene-name" class="n2j-label">Название сценария</label>
                    <input id="nj640-scene-name" type="text" placeholder="СКУД входа, ц.3" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>

                <div>
                    <label for="nj640-rule-text" class="n2j-label">Что проверять <span class="font-normal text-gray-400">(текст сценария; пусто — пресет темы)</span></label>
                    <textarea id="nj640-rule-text" rows="3" placeholder="Например: все ли на рабочих местах; нет ли посторонних; надеты ли жилеты" autocomplete="off" spellcheck="false"
                              class="n2j-textarea mt-1.5 min-h-[44px] w-full leading-relaxed"></textarea>
                </div>

                <div>
                    <label for="nj640-schema" class="n2j-label">Выходная JSON-схема ответа <span class="font-normal text-gray-400">(контракт для API, пусто — схема темы)</span></label>
                    <textarea id="nj640-schema" rows="4" placeholder='{"type":"object","required":["verdict"],"properties":{"verdict":{"type":"string"},"violations":{"type":"array"},"confidence":{"type":"number"}}}' autocomplete="off" spellcheck="false"
                              class="n2j-textarea mt-1.5 min-h-[44px] w-full font-mono leading-relaxed"></textarea>
                </div>

                <div class="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center">
                    <button type="button" id="nj640-go"
                            class="n2j-btn-process w-full sm:w-auto">
                        <i class="fas fa-magnifying-glass"></i> Проверить кадр
                    </button>
                    <p class="n2j-hint text-center sm:text-left">Лимит: <?php echo HTML::chars($rate_text); ?> с одного адреса.</p>
                </div>

                <div id="nj640-status" class="hidden items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <i class="fas fa-spinner n2j-spin text-base text-emerald-500"></i>
                    <span id="nj640-status-text">VLM анализирует кадр…</span>
                    <span id="nj640-timer" class="font-mono text-xs text-gray-400"></span>
                </div>

                <div id="nj640-error" class="n2j-flash n2j-flash-err hidden block"></div>
                <div id="nj640-result" class="n2j-flash n2j-flash-ok hidden block"></div>
            </div>
        </div>

        <!-- ------------------------------- камеры ------------------------------- -->
        <div class="n2j-card">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="n2j-h3">Автоматический режим — камеры</h2>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 sm:gap-5">
                <div>
                    <label for="nj640-cam-kind" class="n2j-label">Тип камеры</label>
                    <select id="nj640-cam-kind" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <?php foreach ($kinds as $k => $label): ?>
                        <option value="<?php echo HTML::chars($k); ?>"><?php echo HTML::chars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="nj640-cam-trigger" class="n2j-label">Триггер запуска</label>
                    <select id="nj640-cam-trigger" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <?php foreach ($triggers as $t => $label): ?>
                        <option value="<?php echo HTML::chars($t); ?>"><?php echo HTML::chars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="nj640-cam-name" class="n2j-label">Название камеры</label>
                    <input id="nj640-cam-name" type="text" placeholder="Камера входа, цех №3" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
                <div>
                    <label for="nj640-cam-location" class="n2j-label">Локация <span class="font-normal text-gray-400">(необязательно)</span></label>
                    <input id="nj640-cam-location" type="text" placeholder="Вход / линия №2 / склад" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
                <div>
                    <label for="nj640-cam-scene" class="n2j-label">Сценарий для камеры</label>
                    <select id="nj640-cam-scene" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <option value="0">— выберите —</option>
                        <?php foreach ($scenes as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>"><?php echo HTML::chars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="nj640-cam-cfg" class="n2j-label">Настройки камеры <span class="font-normal text-gray-400">(JSON)</span></label>
                    <textarea id="nj640-cam-cfg" rows="3" placeholder='{"snapshot_url":"http://cam/axis-cgi/jpg/image.cgi","login":"","password":""}' autocomplete="off" spellcheck="false"
                              class="n2j-textarea mt-1.5 min-h-[44px] w-full font-mono leading-relaxed"></textarea>
                </div>
                <div>
                    <label for="nj640-cam-interval" class="n2j-label">Интервал опроса, сек <span class="font-normal text-gray-400">(для «по таймеру»)</span></label>
                    <input id="nj640-cam-interval" type="number" min="5" max="3600" value="60" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="nj640-cam-start" class="n2j-label">Работает с <span class="font-normal text-gray-400">(пусто = круглосуточно)</span></label>
                        <input id="nj640-cam-start" type="time" placeholder="08:00" autocomplete="off"
                               class="n2j-input mt-1.5 w-full">
                    </div>
                    <div>
                        <label for="nj640-cam-end" class="n2j-label">До</label>
                        <input id="nj640-cam-end" type="time" placeholder="20:00" autocomplete="off"
                               class="n2j-input mt-1.5 w-full">
                    </div>
                </div>
                <div>
                    <label for="nj640-cam-cooldown" class="n2j-label">Пауза между тревогами, сек <span class="font-normal text-gray-400">(не спамить)</span></label>
                    <input id="nj640-cam-cooldown" type="number" min="0" max="86400" value="300" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
            </div>

            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <button type="button" id="nj640-cam-add"
                        class="n2j-btn-cta">
                    <i class="fas fa-plus"></i> Добавить камеру
                </button>
                <span id="nj640-cam-msg" class="text-xs text-gray-400 dark:text-gray-500"></span>
            </div>

            <div id="nj640-cam-list" class="mt-4 space-y-2">
                <?php if ( ! $cameras): ?>
                <p class="n2j-hint">Камер пока нет.</p>
                <?php endif; ?>
                <?php foreach ($cameras as $c): ?>
                <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 bg-gray-50/60 px-3.5 py-2.5 text-xs text-gray-700 dark:border-gray-700/60 dark:bg-gray-800/40 dark:text-gray-300">
                    <span class="truncate"><i class="fas fa-video text-gray-400"></i> <?php echo HTML::chars($c['name']); ?>
                        <span class="text-gray-400">· <?php echo HTML::chars($c['location'] !== '' ? $c['location'] : $c['kind']); ?> · <?php echo HTML::chars($c['trigger']); ?><?php echo $c['enabled'] ? '' : ' · на паузе'; ?></span></span>
                    <button type="button" data-fire="<?php echo HTML::chars($c['code']); ?>"
                            class="n2j-btn-process n2j-btn-sm nj640-fire shrink-0">Тест</button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ----------------------------- журнал событий ----------------------------- -->
        <div class="n2j-card">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="n2j-h3">Журнал событий</h2>
                <span class="text-xs text-gray-400 dark:text-gray-500">подтвердите или снимите тревогу</span>
            </div>

            <div id="nj640-events" class="mt-4 space-y-2">
                <?php if ( ! $events): ?>
                <p class="n2j-hint">Событий пока нет.</p>
                <?php endif; ?>
                <?php foreach ($events as $e): ?>
                <div class="n2j-badge <?php echo $e['dismissed'] ? 'n2j-badge-wait' : ($e['severity'] === 'major' ? 'n2j-badge-run' : 'n2j-badge-info'); ?>">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-800 dark:text-gray-200">
                                <?php if ($e['severity'] === 'major'): ?><span class="mr-1.5 inline-block h-2 w-2 rounded-full bg-amber-500"></span><?php endif; ?><?php echo HTML::chars($e['title']); ?>
                                <?php if ($e['dismissed']): ?><span class="ml-2 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-400">обработано</span><?php endif; ?>
                            </p>
                            <p class="mt-1 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($e['detail']); ?></p>
                            <p class="n2j-hint">#<?php echo (int) $e['id']; ?> · камера <?php echo (int) $e['camera_id']; ?> · <?php echo HTML::chars($e['last_seen_at']); ?></p>
                        </div>
                        <?php if ( ! $e['dismissed']): ?>
                        <div class="flex shrink-0 gap-1.5">
                            <button type="button" data-confirm="<?php echo (int) $e['id']; ?>" data-v="confirmed"
                                    class="n2j-btn-cta n2j-btn-sm nj640-confirm">Подтв.</button>
                            <button type="button" data-confirm="<?php echo (int) $e['id']; ?>" data-v="false_alarm"
                                    class="n2j-btn-secondary n2j-btn-sm nj640-confirm">Ложная</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================ компаньон (1/3) ============================ -->
    <div class="hidden space-y-5 md:block">
        <div class="n2j-card">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Как это работает</h3>
            <ul class="mt-3 space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Триггер (таймер/вебхук) → захват кадра → VLM → JSON-схема → ответ API + запись в журнал.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Видео не шлётся потоком: один кадр только по событию.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Повторные находки с той же камеры не плодят события: dedupe + пауза между тревогами.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Вы подтверждаете событие или помечаете ложной тревогой — статистику сбоев видно.</span></li>
            </ul>
        </div>

        <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-5 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40">
            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                <i class="fas fa-cloud-arrow-up text-blue-600 dark:text-blue-400"></i>
                <h3 class="text-sm font-bold">Серверный анализ</h3>
            </div>
            <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                Кадры сжимаются и уходят в vision-модель на сервере. Для камер в локальной сети включите <code class="font-mono">NJ640_ALLOW_LAN=1</code> у воркера.
            </p>
        </div>

        <?php if ($logged): ?>
        <div class="n2j-card">
            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">История</h3>
            <?php if ( ! $recent): ?>
            <p class="n2j-hint mt-3">Проверок пока нет.</p>
            <?php else: ?>
            <ul class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                <?php foreach ($recent as $r): ?>
                <li>
                    <button type="button" data-run="<?php echo (int) $r['id']; ?>"
                            class="nj640-history w-full rounded-lg border border-transparent px-2 py-1.5 text-left transition hover:border-gray-200 hover:bg-white dark:hover:border-gray-700/60 dark:hover:bg-gray-800/60">
                        <span class="flex items-center justify-between gap-2">
                            <span class="truncate">#<?php echo (int) $r['id']; ?> · <?php echo HTML::chars($r['topic']); ?><?php echo $r['trigger'] !== 'manual' ? ' · '.HTML::chars($r['trigger']) : ''; ?></span>
                            <span class="n2j-badge <?php echo $r['status'] === 'done' ? 'n2j-badge-ok' : ($r['status'] === 'error' ? 'n2j-badge-err' : 'n2j-badge-wait'); ?> shrink-0"><?php echo HTML::chars($r['status']); ?></span>
                        </span>
                        <?php if ( ! empty($r['summary'])): ?>
                        <span class="mt-1 block truncate text-gray-400 dark:text-gray-500"><?php echo HTML::chars($r['summary']); ?></span>
                        <?php elseif ( ! empty($r['error'])): ?>
                        <span class="mt-1 block truncate text-rose-500 dark:text-rose-400"><?php echo HTML::chars($r['error']); ?></span>
                        <?php endif; ?>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php if ($logged): ?>
<script type="application/json" id="nj640-topics-json"><?php echo json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
<?php echo View::factory('640/script'); ?>
<?php endif; ?>




