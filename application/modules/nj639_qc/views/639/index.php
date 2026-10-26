<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Контроль качества (nj639_qc) — страница /qc.
 * Ручной режим + источники авторежима + история. Проверка на сервере:
 * /qc/api/run ставит задачу канала nj639.analyze (воркер + VLM).
 */
$rules    = isset($rules) ? $rules : [];
$sources  = isset($sources) ? $sources : [];
$recent   = isset($recent) ? $recent : [];
$kinds    = isset($kinds) ? $kinds : [];
$triggers = isset($triggers) ? $triggers : [];
$rate_text= isset($rate_text) ? $rate_text : '5 операций в минуту';
$max_mb   = isset($max_img_mb) ? $max_img_mb : '15';
// Гость видит плашку входа и справку вместо формы (владелец, 08.10.2026).
$logged   = ! empty($logged);
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
        <h1 class="n2j-h1">Контроль качества</h1>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white shadow-xs">
            <i class="fas fa-clipboard-check text-[11px]"></i>VLM-проверка
        </span>
    </div>
</div>
<p class="mt-1.5 text-xs sm:text-sm text-gray-600 dark:text-gray-300">Эталон, правило и фото изделия — нейросеть сверит кадр с эталоном и вернёт вердикт по вашей JSON-схеме. Ручной режим или источник кадров: камера, FTP, очередь, вебхук.</p>

<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

    <!-- ============================ форма (2/3) ============================ -->
    <div class="space-y-6 md:col-span-2">

        <?php if ( ! $logged): ?>
        <?php echo Gate::wall('qc', [
            'icon'   => 'fas fa-clipboard-check',
            'title'  => 'Контроль качества — по бесплатному аккаунту',
            'text'   => 'Каждый кадр уходит в vision-модель: это время и очередь сервера. Аккаунт отделяет людей от ботов, а правила, источники и историю проверок делает вашими — они открываются с любого устройства.',
            'points' => [
                'Сверка фото изделия с эталоном по вашему правилу брака',
                'Вердикт по вашей JSON-схеме — готовый контракт для API',
                'Авторежим: IP-камера, FTP-папка, очередь (Kafka/RabbitMQ/MQTT), вебхук датчика',
                'Журнал прогонов и демо-наборы — проверить сервис можно без своих файлов',
            ],
            'stats'  => ['Beta, бесплатно', 'Лимит: '.$rate_text],
            'cta'    => 'Войти и проверить изделие',
            'note'   => 'Кадры не хранятся вечно: в историю идёт вердикт и данные по вашей схеме, а снимок живёт столько, сколько нужно разбору.',
        ]); ?>

        <section class="n2j-card">
            <h2 class="n2j-h3">Как это работает</h2>
            <ul class="mt-3 space-y-2.5 text-xs leading-relaxed text-gray-600 dark:text-gray-300 sm:text-sm">
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Событие или триггер → захват кадра → VLM-анализ → JSON-схема → ответ API и запись в журнал.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Кадры не идут потоком: анализ только по триггеру — таймер, вебхук или детектор движения.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Ответ проверяется по вашей схеме перед записью в историю: мусор в журнал не попадает.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Демо-наборы («Царапины и сколы», «Этикетка», «Комплектность», «Сварной шов», «Пересчёт») заполняют правило и подставляют кадр — сервис видно в работе сразу.</span></li>
            </ul>
        </section>
        <?php else: ?>

        <div class="n2j-card">
            <div class="border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="n2j-h3">Ручная проверка</h2>
            </div>

            <div class="mt-4 space-y-4 sm:mt-5">

                <div>
                    <label for="nj639-photo" class="n2j-label">Фото изделия</label>
                    <input id="nj639-photo" type="file" accept="image/*"
                           class="mt-1.5 w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                    <p class="n2j-hint">jpg/png/webp/bmp/gif, до <?php echo HTML::chars($max_mb); ?> МБ.</p>
                </div>

                <div>
                    <label for="nj639-refs" class="n2j-label">Эталонные изображения <span class="font-normal text-gray-400">(необязательно, несколько)</span></label>
                    <input id="nj639-refs" type="file" accept="image/*" multiple
                           class="mt-1.5 w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 file:mr-3 file:rounded-md file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                    <p id="nj639-refs-hint" class="n2j-hint">С чем сверять кадр. Если не указать — возьмём эталоны выбранного правила.</p>
                </div>

                <!-- ===================== пресеты настроек ===================== -->
                <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50/60 p-3.5 dark:border-gray-600 dark:bg-gray-800/40">
                    <p class="text-xs sm:text-sm font-semibold text-gray-800 dark:text-gray-200">Быстрый старт: готовые наборы</p>
                    <p class="n2j-hint">Кнопка заполняет правило, варианты вердикта и выходную JSON-схему. Две последние ещё и подставляют демонстрационные снимки — проверить сервис можно без своих файлов.</p>

                    <div class="mt-2.5 flex flex-wrap gap-2">
                        <?php
                        $nj639_presets = [
                            'scratch'  => 'Царапины и сколы корпуса',
                            'label'    => 'Этикетка: наличие и ровность',
                            'assembly' => 'Комплектность сборки',
                            'weld'     => 'Сварной шов / пайка',
                            'count'    => 'Пересчёт изделий в таре',
                        ];
                        ?>
                        <?php foreach ($nj639_presets as $code => $label): ?>
                        <button type="button" data-nj639-preset="<?php echo HTML::chars($code); ?>"
                                class="n2j-btn-secondary n2j-btn-sm"><?php echo HTML::chars($label); ?></button>
                        <?php endforeach; ?>

                        <button type="button" data-nj639-preset="demo_defect"
                                class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-flask"></i> Демо: деталь с дефектом</button>
                        <button type="button" data-nj639-preset="demo_ok"
                                class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-flask"></i> Демо: годная деталь</button>
                    </div>

                    <div id="nj639-demo" class="mt-3 hidden items-center gap-3">
                        <img id="nj639-demo-frame" src="" alt="Демонстрационный кадр изделия"
                             class="h-16 w-24 rounded-md border border-gray-200 object-cover dark:border-gray-700">
                        <img id="nj639-demo-ref" src="" alt="Демонстрационный эталон"
                             class="h-16 w-24 rounded-md border border-gray-200 object-cover dark:border-gray-700">
                        <span id="nj639-demo-msg" class="text-xs text-gray-500 dark:text-gray-400"></span>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 sm:gap-5">
                    <div>
                        <label for="nj639-rule-select" class="n2j-label">Правило</label>
                        <select id="nj639-rule-select" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                            <option value="0">— новое правило —</option>
                            <?php foreach ($rules as $r): ?>
                            <option value="<?php echo (int) $r['id']; ?>"><?php echo HTML::chars($r['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="nj639-rule-name" class="n2j-label">Название правила</label>
                        <input id="nj639-rule-name" type="text" placeholder="Царапины на корпусе" autocomplete="off"
                               class="n2j-input mt-1.5 w-full">
                    </div>
                </div>

                <div>
                    <label for="nj639-rule-text" class="n2j-label">Что искать (правило брака)</label>
                    <textarea id="nj639-rule-text" rows="3" placeholder="Например: трещины, сколы, царапины на лицевой панели; смещение наклейки; посторонние включения" autocomplete="off" spellcheck="false"
                              class="n2j-textarea mt-1.5 min-h-[44px] w-full leading-relaxed"></textarea>
                </div>

                <div>
                    <label for="nj639-variants" class="n2j-label">Варианты вердикта <span class="font-normal text-gray-400">(через запятую)</span></label>
                    <input id="nj639-variants" type="text" placeholder="годен, брак: царапина, брак: трещина, брак: иное" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>

                <div>
                    <label for="nj639-schema" class="n2j-label">Выходная JSON-схема ответа <span class="font-normal text-gray-400">(контракт для API, необязательно)</span></label>
                    <textarea id="nj639-schema" rows="5" placeholder='{"type":"object","required":["verdict","defects"],"properties":{"verdict":{"type":"string"},"defects":{"type":"array"},"confidence":{"type":"number"}}}' autocomplete="off" spellcheck="false"
                              class="n2j-textarea mt-1.5 min-h-[44px] w-full font-mono leading-relaxed"></textarea>
                </div>

                <div class="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center">
                    <button type="button" id="nj639-go"
                            class="n2j-btn-process w-full sm:w-auto">
                        <i class="fas fa-magnifying-glass"></i> Проверить изделие
                    </button>
                    <p class="n2j-hint text-center sm:text-left">Лимит: <?php echo HTML::chars($rate_text); ?> с одного адреса.</p>
                </div>

                <div id="nj639-status" class="hidden items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <i class="fas fa-spinner n2j-spin text-base text-emerald-500"></i>
                    <span id="nj639-status-text">VLM анализирует кадр…</span>
                    <span id="nj639-timer" class="font-mono text-xs text-gray-400"></span>
                </div>

                <div id="nj639-error" class="n2j-flash n2j-flash-err hidden block"></div>
                <div id="nj639-result" class="n2j-flash n2j-flash-ok hidden block"></div>
            </div>
        </div>

        <!-- ============================ источники ============================ -->
        <div class="n2j-card">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 pb-3 dark:border-gray-700/60">
                <h2 class="n2j-h3">Автоматический режим — источники кадров</h2>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 sm:gap-5">
                <div>
                    <label for="nj639-src-kind" class="n2j-label">Тип источника</label>
                    <select id="nj639-src-kind" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <?php foreach ($kinds as $k => $label): ?>
                        <option value="<?php echo HTML::chars($k); ?>"><?php echo HTML::chars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="nj639-src-trigger" class="n2j-label">Триггер запуска</label>
                    <select id="nj639-src-trigger" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <?php foreach ($triggers as $t => $label): ?>
                        <option value="<?php echo HTML::chars($t); ?>"><?php echo HTML::chars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="nj639-src-name" class="n2j-label">Название источника</label>
                    <input id="nj639-src-name" type="text" placeholder="Камера линии №2" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
                <div>
                    <label for="nj639-src-rule" class="n2j-label">Правило для источника</label>
                    <select id="nj639-src-rule" class="n2j-select mt-1.5 min-h-[44px] w-full cursor-pointer rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-3 focus:ring-blue-500/15 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                        <option value="0">— выберите —</option>
                        <?php foreach ($rules as $r): ?>
                        <option value="<?php echo (int) $r['id']; ?>"><?php echo HTML::chars($r['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="nj639-src-cfg" class="n2j-label">Настройки источника <span class="font-normal text-gray-400">(JSON)</span></label>
                    <textarea id="nj639-src-cfg" rows="3" placeholder='{"snapshot_url":"http://cam/axis-cgi/jpg/image.cgi","login":"","password":""}' autocomplete="off" spellcheck="false"
                              class="n2j-textarea mt-1.5 min-h-[44px] w-full font-mono leading-relaxed"></textarea>
                </div>
                <div>
                    <label for="nj639-src-interval" class="n2j-label">Интервал опроса, сек <span class="font-normal text-gray-400">(для триггера «по таймеру»)</span></label>
                    <input id="nj639-src-interval" type="number" min="5" max="3600" value="60" autocomplete="off"
                           class="n2j-input mt-1.5 w-full">
                </div>
            </div>

            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <button type="button" id="nj639-src-add"
                        class="n2j-btn-cta">
                    <i class="fas fa-plus"></i> Добавить источник
                </button>
                <button type="button" id="nj639-src-demo"
                        class="n2j-btn-secondary n2j-btn-sm">
                    <i class="fas fa-flask"></i> Демо-источник (кадр с этого сайта)
                </button>
                <span id="nj639-src-msg" class="text-xs text-gray-400 dark:text-gray-500"></span>
            </div>

            <div id="nj639-src-list" class="mt-4 space-y-2">
                <?php if ( ! $sources): ?>
                <p class="n2j-hint">Источников пока нет.</p>
                <?php endif; ?>
                <?php foreach ($sources as $s): ?>
                <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 bg-gray-50/60 px-3.5 py-2.5 text-xs text-gray-700 dark:border-gray-700/60 dark:bg-gray-800/40 dark:text-gray-300">
                    <span class="truncate"><i class="fas fa-video text-gray-400"></i> <?php echo HTML::chars($s['name']); ?>
                        <span class="text-gray-400">· <?php echo HTML::chars($s['kind']); ?> · <?php echo HTML::chars($s['trigger']); ?><?php echo $s['enabled'] ? '' : ' · на паузе'; ?></span></span>
                    <button type="button" data-fire="<?php echo HTML::chars($s['code']); ?>"
                            class="n2j-btn-process n2j-btn-sm nj639-fire shrink-0">Тест</button>
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
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Событие/триггер → захват кадра → VLM-анализ → JSON-схема → ответ API + запись в журнал.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Кадры не шлются потоком: анализ только по триггеру (таймер, вебхук, детектор движения).</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Ответ проверяется по вашей схеме перед записью в историю.</span></li>
            </ul>
        </div>

        <div class="n2j-card">
            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                <i class="fas fa-flask text-emerald-600 dark:text-emerald-400"></i>
                <h3 class="text-sm font-bold">Чем протестировать</h3>
            </div>
            <ul class="mt-3 space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Кнопки «Демо» в форме: готовые кадр и эталон лежат на сайте, свои файлы не нужны.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Авторежим без камеры: кнопка «Демо-источник» заводит HTTP-источник на кадр
                    <a href="/assets/demo/nj639/part-defect.jpg" class="font-mono text-blue-600 underline dark:text-blue-400">/assets/demo/nj639/part-defect.jpg</a> — «Тест» прогоняет весь конвейер захвата.</span></li>
                <li class="flex items-start gap-1.5"><span class="font-bold text-emerald-500">•</span><span>Своя камера: в источник «IP-камера» годится любой публичный snapshot-URL, отдающий JPEG. Камеры в локальной сети закрыты, пока у воркера не задано <code class="font-mono">NJ639_ALLOW_LAN=1</code>.</span></li>
            </ul>
        </div>

        <div class="rounded-xl border border-gray-200/80 bg-gray-50/70 p-5 shadow-xs dark:border-gray-700/60 dark:bg-gray-800/40">
            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                <i class="fas fa-cloud-arrow-up text-blue-600 dark:text-blue-400"></i>
                <h3 class="text-sm font-bold">Серверный анализ</h3>
            </div>
            <p class="mt-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                Изображения сжимаются и уходят в vision-модель на сервере. Для локальной сети камер включите <code class="font-mono">NJ639_ALLOW_LAN=1</code> у воркера.
            </p>
        </div>

        <?php if ($logged): ?>
        <div class="n2j-card">
            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">История</h3>
            <p id="nj639-history-empty" class="mt-3 text-xs text-gray-500 dark:text-gray-400<?php echo $recent ? ' hidden' : ''; ?>">Прогонов пока нет.</p>
            <ul id="nj639-history-list" class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                <?php foreach ($recent as $r): ?>
                <li>
                    <button type="button" data-run="<?php echo (int) $r['id']; ?>"
                            class="nj639-history w-full rounded-lg border border-transparent px-2 py-1.5 text-left transition hover:border-gray-200 hover:bg-white dark:hover:border-gray-700/60 dark:hover:bg-gray-800/60">
                        <span class="flex items-center justify-between gap-2">
                            <span class="truncate">#<?php echo (int) $r['id']; ?> · <?php echo HTML::chars($r['mode']); ?><?php echo $r['trigger'] !== 'manual' ? ' · '.HTML::chars($r['trigger']) : ''; ?></span>
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
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php if ($logged): ?>
<?php
/**
 * Общий JS VLM-сервисов: нужен ради N2JVision.shrink — кадр уменьшается до
 * 1400 px ПРЯМО В БРАУЗЕРЕ. Снимок с телефона на 8 МБ иначе уезжал в тело
 * запроса целиком и дальше base64 в payload задачи очереди (урок nj646).
 */
echo View::factory('partials/photo_capture_js');
echo View::factory('639/script');
?>
<?php endif; ?>
