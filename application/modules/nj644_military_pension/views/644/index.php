<?php defined('SYSPATH') or die('No direct script access.');

/** @var array $settings Нормативные значения и источники Service_Nj644_Pension. */
/** @var string $today Дата по умолчанию, переданная контроллером. */
$defaults = $settings['defaults'];
$limits = $settings['limits'];
$allowances = $settings['allowances'];
$label_class = 'n2j-label';
$field_class = 'n2j-input mt-1.5 w-full';
$small_field_class = 'n2j-input mt-1.5 w-full';
$min_service_years = intdiv((int) $defaults['mixed_min_service_months'], 12);
$min_service_remainder = (int) $defaults['mixed_min_service_months'] % 12;
?>
<div id="pension-app" class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-date="<?php echo HTML::chars($today); ?>">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-medal text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Калькулятор военной пенсии</h1>
            <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Ориентировочный расчёт пенсии за выслугу лет по Закону РФ №4468-1 — с окладами, надбавками, увеличениями и районным коэффициентом.</p>
        </div>
        <span class="n2j-badge n2j-badge-ok"><i class="fas fa-lock" aria-hidden="true"></i>Расчёт в браузере</span>
    </div>

    <div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_21rem]">
        <main class="min-w-0 space-y-5">
            <section class="n2j-card" aria-labelledby="pension-input-heading">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="pension-input-heading" class="n2j-h3">1. Основание и период</h2>
                    <span class="text-xs text-gray-400 dark:text-gray-500">Поля со знаком ₽ — в рублях</span>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="pension-method" class="<?php echo $label_class; ?>">Основание расчёта</label>
                        <select id="pension-method" class="n2j-input n2j-select mt-1.5 w-full">
                            <option value="service">Выслуга лет — п. «а» ст. 14</option>
                            <option value="mixed">Смешанный стаж — п. «б» ст. 14</option>
                        </select>
                    </div>
                    <div>
                        <label for="pension-date" class="<?php echo $label_class; ?>">Дата, на которую считать</label>
                        <input id="pension-date" type="date" value="<?php echo HTML::chars($today); ?>" class="<?php echo $field_class; ?>">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="pension-period" class="<?php echo $label_class; ?>">Сценарий понижающей доли</label>
                        <select id="pension-period" class="n2j-input n2j-select mt-1.5 w-full">
                            <?php foreach ($settings['periods'] as $key => $period): ?>
                                <option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($period['label']); ?></option>
                            <?php endforeach; ?>
                            <option value="custom">Свой сценарий — в настройках ниже</option>
                        </select>
                        <p id="pension-period-note" class="n2j-hint mt-2">Действующая по умолчанию доля: <?php echo HTML::chars((string) $defaults['coefficient_percent']); ?>%.</p>
                        <p id="pension-proposal-warning" class="n2j-flash n2j-flash-warn mt-2 hidden text-xs leading-relaxed block"><b>Сценарий, а не действующая норма.</b> Значение 97,34% показано только для моделирования по законопроекту и не подтверждает право на такой размер.</p>
                    </div>
                </div>

                <div id="pension-service-fields" class="mt-4 rounded-lg border border-blue-100 bg-blue-50/50 p-4 dark:border-blue-900/50 dark:bg-blue-950/20">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Выслуга для обычного варианта</h3>
                    <p class="n2j-hint">Укажите подтверждённую выслугу в полных годах и месяцах. Льготный зачёт и периоды службы калькулятор не проверяет.</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label for="pension-service-years" class="<?php echo $label_class; ?>">Полные годы службы</label><input id="pension-service-years" type="number" min="0" max="<?php echo (int) $limits['max_years']; ?>" step="1" value="20" class="<?php echo $field_class; ?>"></div>
                        <div><label for="pension-service-months" class="<?php echo $label_class; ?>">Дополнительные месяцы</label><input id="pension-service-months" type="number" min="0" max="11" step="1" value="0" class="<?php echo $field_class; ?>"></div>
                    </div>
                </div>

                <div id="pension-mixed-fields" class="mt-4 hidden rounded-lg border border-blue-100 bg-blue-50/50 p-4 dark:border-blue-900/50 dark:bg-blue-950/20">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Условия смешанного стажа</h3>
                    <p class="n2j-hint">Нужны общий стаж, соответствующая служба, возраст на день увольнения и предусмотренное п. «б» ст. 13 основание увольнения.</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label for="pension-total-years" class="<?php echo $label_class; ?>">Полные годы общего стажа</label><input id="pension-total-years" type="number" min="0" max="<?php echo (int) $limits['max_years']; ?>" step="1" value="25" class="<?php echo $field_class; ?>"></div>
                        <div><label for="pension-total-months" class="<?php echo $label_class; ?>">Дополнительные месяцы общего стажа</label><input id="pension-total-months" type="number" min="0" max="11" step="1" value="0" class="<?php echo $field_class; ?>"></div>
                        <div><label for="pension-mixed-service-years" class="<?php echo $label_class; ?>">Полные годы соответствующей службы</label><input id="pension-mixed-service-years" type="number" min="0" max="<?php echo (int) $limits['max_years']; ?>" step="1" value="12" class="<?php echo $field_class; ?>"></div>
                        <div><label for="pension-mixed-service-months" class="<?php echo $label_class; ?>">Дополнительные месяцы службы</label><input id="pension-mixed-service-months" type="number" min="0" max="11" step="1" value="6" class="<?php echo $field_class; ?>"></div>
                        <div><label for="pension-age" class="<?php echo $label_class; ?>">Возраст на день увольнения, лет</label><input id="pension-age" type="number" min="0" max="<?php echo (int) $limits['max_age']; ?>" step="1" value="45" class="<?php echo $field_class; ?>"></div>
                        <div><label for="pension-dismissal-reason" class="<?php echo $label_class; ?>">Основание увольнения</label><select id="pension-dismissal-reason" class="n2j-input n2j-select mt-1.5 w-full"><option value="">Выберите предусмотренное законом</option><option value="age_limit">Предельный возраст пребывания на службе</option><option value="health">Состояние здоровья</option><option value="staffing">Организационно-штатные мероприятия</option></select></div>
                    </div>
                </div>
            </section>

            <section class="n2j-card" aria-labelledby="pension-pay-heading">
                <h2 id="pension-pay-heading" class="n2j-h3">2. Денежное довольствие для расчёта</h2>
                <p class="n2j-hint">Введите суммы из расчётных документов. Калькулятор сначала формирует базу из ОВД, ОВЗ и выбранных учитываемых надбавок.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div><label for="pension-position-salary" class="<?php echo $label_class; ?>">Оклад по должности, ₽</label><input id="pension-position-salary" type="number" min="0" max="<?php echo (int) $limits['max_monthly_rub']; ?>" step="0.01" inputmode="decimal" autocomplete="off" placeholder="Например, 50 000" class="<?php echo $field_class; ?>"></div>
                    <div><label for="pension-rank-salary" class="<?php echo $label_class; ?>">Оклад по званию, ₽</label><input id="pension-rank-salary" type="number" min="0" max="<?php echo (int) $limits['max_monthly_rub']; ?>" step="0.01" inputmode="decimal" autocomplete="off" placeholder="Например, 15 000" class="<?php echo $field_class; ?>"></div>
                    <div><label for="pension-nuclear-multiplier" class="<?php echo $label_class; ?>">Работа с ядерным оружием</label><select id="pension-nuclear-multiplier" class="n2j-input n2j-select mt-1.5 w-full"><option value="1">Нет — коэффициент 1</option><option value="1.25">Да — коэффициент 1,25 к ОВД</option></select></div>
                    <div><label for="pension-qualification-percent" class="<?php echo $label_class; ?>">Квалификационная категория</label><select id="pension-qualification-percent" class="n2j-input n2j-select mt-1.5 w-full"><?php foreach ($allowances['qualification_percent'] as $option): ?><option value="<?php echo HTML::chars((string) $option['value']); ?>"><?php echo HTML::chars($option['label']); ?></option><?php endforeach; ?></select></div>
                    <div><label for="pension-service-allowance-percent" class="<?php echo $label_class; ?>">Надбавка за выслугу</label><select id="pension-service-allowance-percent" class="n2j-input n2j-select mt-1.5 w-full"><?php foreach ($allowances['service_percent'] as $option): ?><option value="<?php echo HTML::chars((string) $option['value']); ?>"><?php echo HTML::chars($option['label']); ?></option><?php endforeach; ?></select></div>
                    <div><label for="pension-other-allowance" class="<?php echo $label_class; ?>">Прочие учитываемые надбавки, ₽</label><input id="pension-other-allowance" type="number" min="0" max="<?php echo (int) $limits['max_monthly_rub']; ?>" step="0.01" value="0" inputmode="decimal" autocomplete="off" class="<?php echo $field_class; ?>"></div>
                </div>
                <p class="n2j-hint mt-3">«Прочие» вводите только если их допустимо учитывать в конкретном деле. Не включайте сумму, если не уверены в её правовом основании.</p>
            </section>

            <section class="n2j-card" aria-labelledby="pension-additions-heading">
                <h2 id="pension-additions-heading" class="n2j-h3">3. Увеличения и коэффициент</h2>
                <p class="n2j-hint">Опции ниже — отдельные ориентировочные увеличения на базе расчётного размера пенсии <?php echo number_format((float) $defaults['rpp_rub'], 2, ',', ' '); ?> ₽. Выбирайте только подтверждённые основания.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200"><input id="pension-combat-veteran" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">Ветеран боевых действий<br><span class="text-xs text-gray-500">+32% РРП</span></label>
                    <div><label for="pension-dependents" class="<?php echo $label_class; ?>">Нетрудоспособные иждивенцы</label><select id="pension-dependents" class="n2j-input n2j-select mt-1.5 w-full"><option value="0">Нет</option><option value="1">1 — 32% РРП</option><option value="2">2 — 64% РРП</option><option value="3">3 и более — 100% РРП</option></select></div>
                    <label class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200"><input id="pension-age80-or-group1" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">80 лет или инвалидность I группы<br><span class="text-xs text-gray-500">+100% РРП; не суммируется внутри поля</span></label>
                    <div><label for="pension-disability-percent" class="<?php echo $label_class; ?>">Увеличение по инвалидности</label><select id="pension-disability-percent" class="n2j-input n2j-select mt-1.5 w-full"><option value="0">Нет / не учитывать</option><option value="150">150% РРП</option><option value="175">175% РРП</option><option value="200">200% РРП</option><option value="250">250% РРП</option><option value="300">300% РРП</option></select></div>
                    <div><label for="pension-special-monthly" class="<?php echo $label_class; ?>">Подтверждённая ежемесячная доплата, ₽</label><input id="pension-special-monthly" type="number" min="0" max="<?php echo (int) $limits['max_monthly_rub']; ?>" step="0.01" value="0" inputmode="decimal" autocomplete="off" class="<?php echo $field_class; ?>"></div>
                    <div><label for="pension-district-coefficient" class="<?php echo $label_class; ?>">Районный коэффициент</label><select id="pension-district-coefficient" class="n2j-input n2j-select mt-1.5 w-full"><?php foreach ($allowances['district_coefficients'] as $coefficient): ?><option value="<?php echo HTML::chars((string) $coefficient); ?>"><?php echo HTML::chars((string) $coefficient); ?></option><?php endforeach; ?></select></div>
                </div>
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900 dark:bg-amber-950/30 dark:text-amber-100"><b>Проверьте основания у пенсионного органа.</b> Поля могут пересекаться; этот инструмент не устанавливает право, не проверяет несовместимость льгот и не заменяет документы. Для ориентира районный коэффициент применяется к сумме основной пенсии и выбранных увеличений; ЕДК и другие выплаты в пенсию не включаются.</p>
            </section>

            <details class="n2j-card p-0 group">
                <summary class="min-h-[54px] cursor-pointer px-5 py-4 text-sm font-semibold text-gray-700 dark:text-gray-200"><i class="fas fa-sliders mr-2 text-blue-500" aria-hidden="true"></i>Настройки формулы и расчётного размера пенсии</summary>
                <div class="border-t border-gray-100 p-5 dark:border-gray-700">
                    <p class="n2j-hint">Значения проверены <?php echo HTML::chars($settings['reviewed_on']); ?>. Изменения нужны только для личного сценария и сохраняются локально как технические настройки; оклады, стаж, возраст и выбранные льготы не записываются.</p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div><label for="pension-setting-coefficient-percent" class="<?php echo $label_class; ?>">Понижающая доля, %</label><input id="pension-setting-coefficient-percent" type="number" min="0" max="100" step="0.01" class="<?php echo $small_field_class; ?>"></div>
                        <div><label for="pension-setting-coefficient-from" class="<?php echo $label_class; ?>">Действует с</label><input id="pension-setting-coefficient-from" type="date" class="<?php echo $small_field_class; ?>"></div>
                        <div><label for="pension-setting-coefficient-to" class="<?php echo $label_class; ?>">Действует по</label><input id="pension-setting-coefficient-to" type="date" class="<?php echo $small_field_class; ?>"></div>
                        <div><label for="pension-setting-rpp-rub" class="<?php echo $label_class; ?>">Расчётный размер пенсии, ₽</label><input id="pension-setting-rpp-rub" type="number" min="0" max="<?php echo (int) $limits['max_monthly_rub']; ?>" step="0.01" class="<?php echo $small_field_class; ?>"></div>
                    </div>
                    <div class="mt-4 rounded-lg bg-gray-50 p-3 dark:bg-gray-900/60">
                        <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Обычная выслуга — пункт «а» статьи 14</h3>
                        <div class="mt-3 grid gap-3 sm:grid-cols-4">
                            <div><label for="pension-setting-service-min-years" class="<?php echo $label_class; ?>">Порог, лет</label><input id="pension-setting-service-min-years" type="number" min="1" max="<?php echo (int) $limits['max_years']; ?>" step="1" class="<?php echo $small_field_class; ?>"></div>
                            <div><label for="pension-setting-service-base-percent" class="<?php echo $label_class; ?>">База, %</label><input id="pension-setting-service-base-percent" type="number" min="0" max="100" step="0.01" class="<?php echo $small_field_class; ?>"></div>
                            <div><label for="pension-setting-service-percent-per-year" class="<?php echo $label_class; ?>">За год сверх порога, п.п.</label><input id="pension-setting-service-percent-per-year" type="number" min="0" max="100" step="0.01" class="<?php echo $small_field_class; ?>"></div>
                            <div><label for="pension-setting-service-max-percent" class="<?php echo $label_class; ?>">Предел, %</label><input id="pension-setting-service-max-percent" type="number" min="0" max="100" step="0.01" class="<?php echo $small_field_class; ?>"></div>
                        </div>
                    </div>
                    <div class="mt-4 rounded-lg bg-gray-50 p-3 dark:bg-gray-900/60">
                        <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Смешанный стаж — пункты «б» статей 13 и 14</h3>
                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <div><label for="pension-setting-mixed-min-total-years" class="<?php echo $label_class; ?>">Общий стаж, лет</label><input id="pension-setting-mixed-min-total-years" type="number" min="0" max="<?php echo (int) $limits['max_years']; ?>" step="1" class="<?php echo $small_field_class; ?>"></div>
                            <div><label class="<?php echo $label_class; ?>">Минимум службы</label><div class="grid grid-cols-2 gap-2"><input id="pension-setting-mixed-min-service-years" aria-label="Минимум службы для смешанного варианта, лет" type="number" min="0" max="<?php echo (int) $limits['max_years']; ?>" step="1" class="<?php echo $small_field_class; ?>"><input id="pension-setting-mixed-min-service-months" aria-label="Минимум службы для смешанного варианта, дополнительных месяцев" type="number" min="0" max="11" step="1" class="<?php echo $small_field_class; ?>"></div><p class="n2j-hint">лет · месяцев</p></div>
                            <div><label for="pension-setting-mixed-min-age" class="<?php echo $label_class; ?>">Возраст, лет</label><input id="pension-setting-mixed-min-age" type="number" min="0" max="<?php echo (int) $limits['max_age']; ?>" step="1" class="<?php echo $small_field_class; ?>"></div>
                            <div><label for="pension-setting-mixed-base-percent" class="<?php echo $label_class; ?>">База, %</label><input id="pension-setting-mixed-base-percent" type="number" min="0" max="100" step="0.01" class="<?php echo $small_field_class; ?>"></div>
                            <div><label for="pension-setting-mixed-percent-per-year" class="<?php echo $label_class; ?>">За год сверх порога, п.п.</label><input id="pension-setting-mixed-percent-per-year" type="number" min="0" max="100" step="0.01" class="<?php echo $small_field_class; ?>"></div>
                            <p class="n2j-hint flex items-end">Для смешанного варианта отдельный предел 85% в формуле не используется.</p>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button id="pension-settings-reset" type="button" class="n2j-btn-secondary">Сбросить официальные значения</button>
                        <span id="pension-settings-status" class="text-xs text-gray-500 dark:text-gray-400">Настройки из конфигурации сайта.</span>
                    </div>
                    <p id="pension-settings-error" role="alert" class="n2j-flash n2j-flash-err mt-3 hidden block"></p>
                </div>
            </details>

            <p id="pension-error" role="alert" class="n2j-flash n2j-flash-err hidden block"></p>

            <section class="n2j-card border-blue-200 dark:border-blue-900/60" aria-labelledby="pension-result-heading" aria-live="polite">
                <h2 id="pension-result-heading" class="n2j-h3">4. Оценка размера пенсии</h2>
                <p id="pension-result-empty" class="mt-3 text-sm text-gray-500 dark:text-gray-400">Введите оба оклада — результат появится здесь.</p>
                <div id="pension-ineligible" class="n2j-flash n2j-flash-warn mt-3 hidden block"><p class="font-semibold">По введённым данным условия выбранного варианта не выполнены:</p><ul id="pension-reasons" class="mt-2 list-disc space-y-1 pl-5"></ul></div>
                <div id="pension-result-body" class="mt-4 hidden">
                    <div class="flex flex-wrap items-end justify-between gap-3"><div><p id="pension-result-label" class="text-sm font-medium text-gray-600 dark:text-gray-300"></p><p id="pension-result-amount" class="mt-2 text-3xl font-extrabold tracking-tight text-blue-700 dark:text-blue-300"></p><p class="text-sm text-gray-500 dark:text-gray-400">в месяц, ориентировочно</p></div><span id="pension-result-district" class="n2j-badge n2j-badge-info"></span></div>
                    <dl class="mt-4 grid gap-2 sm:grid-cols-2">
                        <div class="flex justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900"><dt class="text-gray-500 dark:text-gray-400">Расчётная база</dt><dd id="pension-breakdown-base" class="font-semibold text-gray-900 dark:text-gray-100"></dd></div>
                        <div class="flex justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900"><dt class="text-gray-500 dark:text-gray-400">Процент пенсии</dt><dd id="pension-breakdown-percent" class="font-semibold text-gray-900 dark:text-gray-100"></dd></div>
                        <div class="flex justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900"><dt class="text-gray-500 dark:text-gray-400">Основная пенсия после доли</dt><dd id="pension-breakdown-main" class="font-semibold text-gray-900 dark:text-gray-100"></dd></div>
                        <div class="flex justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900"><dt class="text-gray-500 dark:text-gray-400">Выбранные увеличения</dt><dd id="pension-breakdown-additions" class="font-semibold text-gray-900 dark:text-gray-100"></dd></div>
                    </dl>
                    <div class="mt-4 grid gap-3 lg:grid-cols-2"><div class="rounded-lg border border-gray-100 p-3 text-xs leading-relaxed text-gray-600 dark:border-gray-700 dark:text-gray-300"><p class="font-semibold text-gray-800 dark:text-gray-100">База</p><p id="pension-breakdown-base-detail" class="mt-1"></p></div><div class="rounded-lg border border-gray-100 p-3 text-xs leading-relaxed text-gray-600 dark:border-gray-700 dark:text-gray-300"><p class="font-semibold text-gray-800 dark:text-gray-100">Увеличения</p><ul id="pension-breakdown-additions-detail" class="mt-1 space-y-1"></ul></div></div>
                    <p id="pension-result-formula" class="n2j-hint mt-3"></p>
                </div>
            </section>

            <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900/60 dark:bg-amber-950/30" aria-labelledby="pension-disclaimer-heading">
                <h2 id="pension-disclaimer-heading" class="text-sm font-bold text-amber-900 dark:text-amber-200"><i class="fas fa-circle-info mr-1.5" aria-hidden="true"></i>Важно: расчёт ориентировочный</h2>
                <p class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80">Калькулятор не устанавливает право на пенсию, не проверяет документальный состав стажа, льготный зачёт, совместимость оснований, минимальные гарантии и все ведомственные особенности. Решение о праве и размере принимает пенсионный орган по документам.</p>
                <p class="mt-2 text-xs leading-relaxed text-amber-900/80 dark:text-amber-100/80">Оклады, стаж, возраст и выбранные льготы обрабатываются только в этой вкладке и на сервер не отправляются. В браузере могут сохраняться только обезличенные настройки формулы — их можно сбросить кнопкой выше.</p>
            </section>
        </main>

        <aside class="space-y-4">
            <section class="n2j-card"><h2 class="n2j-h3">Как устроен расчёт</h2><ol class="mt-3 space-y-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><li><b class="text-blue-700 dark:text-blue-300">1.</b> Складываются оклады и выбранные надбавки денежного довольствия.</li><li><b class="text-blue-700 dark:text-blue-300">2.</b> Применяется процент пенсии по выбранному основанию.</li><li><b class="text-blue-700 dark:text-blue-300">3.</b> Применяется понижающая доля, затем выбранные увеличения и районный коэффициент.</li></ol></section>
            <section class="n2j-card"><h2 class="n2j-h3">Формулы по умолчанию</h2><ul class="mt-3 space-y-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><li><b>Обычная выслуга:</b> с <?php echo (int) $defaults['service_min_years']; ?> лет — <?php echo HTML::chars((string) $defaults['service_base_percent']); ?>%; затем +<?php echo HTML::chars((string) $defaults['service_percent_per_year']); ?> п.п. за полный год, максимум <?php echo HTML::chars((string) $defaults['service_max_percent']); ?>%.</li><li><b>Смешанный стаж:</b> от <?php echo (int) $defaults['mixed_min_total_years']; ?> лет, из них <?php echo $min_service_years; ?> лет <?php echo $min_service_remainder; ?> месяцев службы, возраст <?php echo (int) $defaults['mixed_min_age']; ?>+; начало <?php echo HTML::chars((string) $defaults['mixed_base_percent']); ?>%, затем +<?php echo HTML::chars((string) $defaults['mixed_percent_per_year']); ?> п.п. за полный год.</li><li><b>РРП:</b> <?php echo number_format((float) $defaults['rpp_rub'], 2, ',', ' '); ?> ₽.</li></ul></section>
            <section class="n2j-card"><h2 class="n2j-h3">Источники</h2><ul class="mt-3 space-y-2 text-xs leading-relaxed"><?php foreach ($settings['sources'] as $source): ?><li><a href="<?php echo HTML::chars($source['url']); ?>" target="_blank" rel="noopener noreferrer" class="font-semibold text-blue-700 underline decoration-blue-300 underline-offset-2 hover:text-blue-900 dark:text-blue-300"><?php echo HTML::chars($source['title']); ?></a></li><?php endforeach; ?></ul></section>
        </aside>
    </div>
</div>
<script id="pension-settings" type="application/json"><?php echo json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php echo View::factory('644/script'); ?>
