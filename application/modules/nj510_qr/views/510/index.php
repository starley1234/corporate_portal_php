<?php defined('SYSPATH') or die('No direct script access.');

/** nj510_qr: форма без отправки на сервер, предпросмотр и единый стиль пакета. */
$field_class = 'n2j-input mt-1.5 w-full';
$label_class = 'n2j-label';
$faq = [
    ['Как создать QR-код?', 'Выберите ссылку, текст, Wi-Fi или визитку, заполните поля и скачайте PNG, SVG или JPEG. Код пересчитывается сразу. Регистрация не нужна.'],
    ['Как сделать много кодов?', 'Переключитесь на «Пакет», вставьте список по одному значению на строку или загрузите CSV/TSV с колонками «имя;данные». Можно сгенерировать уникальные ID по маске и распечатать пакет на листах A4. До 200 кодов скачиваются ZIP-архивом.'],
    ['Как задаётся маска ID?', '# генерирует случайную цифру 0–9, * — заглавную букву A–Z или цифру. Буквы, цифры, подчёркивание, дефис и точка без специальных знаков остаются как есть: например, prefix_******. Для буквальных # и * используйте \\# и \\*, а для буквальной обратной косой черты — \\\\.'],
    ['Можно добавить логотип?', 'Да. Загрузите PNG, JPEG или WebP до 2 МБ: изображение будет в центре. Генератор автоматически включит максимальную защиту H. Начните с размера 16% и обязательно проверьте код камерой перед печатью.'],
    ['Куда отправляются мои данные?', 'Никуда: содержимое кодов, пароль Wi-Fi, контакты, файлы и логотип остаются в этой вкладке. Кодирование и ZIP выполняются локально. Сохранять можно только стиль — без данных и изображения.'],
    ['У кода есть срок действия?', 'Нет. Это обычный статический QR-код, без рекламы и промежуточного редиректа. Ссылка открывается напрямую; её доступность зависит от самого сайта. Изменить содержимое уже скачанного кода нельзя.'],
    ['Какой формат выбрать для печати?', 'SVG масштабируется без потери качества. Для экрана подойдёт PNG. Оставляйте светлое поле вокруг кода и высокий контраст; проверка в браузере не заменяет сканирование камерой на готовом носителе.'],
];
?>
<div id="qr-app" class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-mode="<?php echo HTML::chars($state['mode']); ?>" data-type="<?php echo HTML::chars($state['type']); ?>">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-qrcode text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Генератор QR-кодов</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Один код или целый пакет. Ваши цвета, формы и логотип — без лишних шагов.</p>
        </div>
        <span class="n2j-badge n2j-badge-ok"><i class="fas fa-shield-halved" aria-hidden="true"></i>Локально · бесплатно · без регистрации</span>
    </div>
    <noscript><p class="n2j-flash n2j-flash-warn mt-4 block">Для локальной генерации QR-кодов нужен JavaScript. Включите его в настройках браузера.</p></noscript>

    <div class="mt-6 xl:flex xl:items-start xl:gap-10">
        <main class="min-w-0 flex-1 space-y-5">
            <div class="n2j-seg w-full gap-1 sm:w-auto" role="group" aria-label="Режим генерации">
                <button type="button" id="qr-mode-single" data-mode-button="single" aria-pressed="true" class="n2j-seg-item flex-1 py-2 sm:flex-none"><i class="fas fa-qrcode mr-2" aria-hidden="true"></i>Один QR-код</button>
                <button type="button" id="qr-mode-batch" data-mode-button="batch" aria-pressed="false" class="n2j-seg-item flex-1 py-2 sm:flex-none"><i class="fas fa-layer-group mr-2" aria-hidden="true"></i>Пакет <span class="ml-1 text-xs opacity-70">до <?php echo (int) $settings['limits']['batch']; ?></span></button>
            </div>

            <section class="n2j-card" aria-labelledby="qr-content-heading">
                <h2 id="qr-content-heading" class="n2j-h3 flex items-center gap-2"><span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950 dark:text-blue-300">1</span>Что закодировать</h2>
                <div id="qr-single-panel" class="mt-4">
                    <label for="qr-type" class="<?php echo $label_class; ?>">Тип содержимого</label>
                    <select id="qr-type" class="n2j-input n2j-select mt-1.5 w-full">
                        <?php foreach ($settings['types'] as $key => $title): ?>
                        <option value="<?php echo HTML::chars($key); ?>"<?php if ($key === $state['type']) echo ' selected'; ?>><?php echo HTML::chars($title); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div data-qr-fields="url" class="mt-4">
                        <label for="qr-url" class="<?php echo $label_class; ?>">Ссылка на сайт</label>
                        <input id="qr-url" type="url" value="https://n2j.ru" placeholder="https://example.com" maxlength="2000" autocomplete="off" autocapitalize="off" spellcheck="false" class="<?php echo $field_class; ?>">
                        <p class="n2j-hint mt-2">Сейчас здесь пример. Замените ссылку на свою — код обновится сам.</p>
                    </div>
                    <div data-qr-fields="text" class="mt-4 hidden">
                        <label for="qr-text" class="<?php echo $label_class; ?>">Любой текст</label>
                        <textarea id="qr-text" rows="4" maxlength="2000" placeholder="Сообщение, номер заказа или любой текст" class="n2j-textarea mt-1.5 w-full"></textarea>
                    </div>
                    <div data-qr-fields="wifi" class="mt-4 hidden space-y-4">
                        <div><label for="qr-wifi-ssid" class="<?php echo $label_class; ?>">Название сети</label><input id="qr-wifi-ssid" type="text" maxlength="64" autocomplete="off" spellcheck="false" placeholder="Например, Coffee Guest" class="<?php echo $field_class; ?>"></div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><label for="qr-wifi-security" class="<?php echo $label_class; ?>">Защита сети</label><select id="qr-wifi-security" class="n2j-input n2j-select mt-1.5 w-full"><option value="WPA">WPA / WPA2 / WPA3</option><option value="WEP">WEP (устаревшая)</option><option value="nopass">Открытая сеть, без пароля</option></select></div>
                            <div><label for="qr-wifi-password" class="<?php echo $label_class; ?>">Пароль сети</label><input id="qr-wifi-password" type="password" maxlength="128" autocomplete="off" class="<?php echo $field_class; ?>"></div>
                        </div>
                        <div class="flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-300"><label class="inline-flex items-center gap-2"><input id="qr-wifi-show" type="checkbox" class="rounded border-gray-300">Показать пароль</label><label class="inline-flex items-center gap-2"><input id="qr-wifi-hidden" type="checkbox" class="rounded border-gray-300">Скрытая сеть</label></div>
                    </div>
                    <div data-qr-fields="vcard" class="mt-4 hidden">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><label for="qr-card-first" class="<?php echo $label_class; ?>">Имя</label><input id="qr-card-first" type="text" maxlength="100" autocomplete="off" placeholder="Анна" class="<?php echo $field_class; ?>"></div>
                            <div><label for="qr-card-last" class="<?php echo $label_class; ?>">Фамилия</label><input id="qr-card-last" type="text" maxlength="100" autocomplete="off" placeholder="Иванова" class="<?php echo $field_class; ?>"></div>
                            <div><label for="qr-card-phone" class="<?php echo $label_class; ?>">Телефон</label><input id="qr-card-phone" type="tel" maxlength="40" autocomplete="off" placeholder="+7 999 123-45-67" class="<?php echo $field_class; ?>"></div>
                            <div><label for="qr-card-email" class="<?php echo $label_class; ?>">Электронная почта</label><input id="qr-card-email" type="email" maxlength="200" autocomplete="off" placeholder="anna@example.com" class="<?php echo $field_class; ?>"></div>
                            <div><label for="qr-card-organization" class="<?php echo $label_class; ?>">Компания</label><input id="qr-card-organization" type="text" maxlength="150" placeholder="Название компании" class="<?php echo $field_class; ?>"></div>
                            <div><label for="qr-card-position" class="<?php echo $label_class; ?>">Должность</label><input id="qr-card-position" type="text" maxlength="100" placeholder="Должность" class="<?php echo $field_class; ?>"></div>
                            <div class="sm:col-span-2"><label for="qr-card-url" class="<?php echo $label_class; ?>">Сайт</label><input id="qr-card-url" type="url" maxlength="500" autocomplete="off" placeholder="https://example.com" class="<?php echo $field_class; ?>"></div>
                        </div>
                    </div>
                    <div data-qr-fields="email" class="mt-4 hidden space-y-4">
                        <div><label for="qr-email-address" class="<?php echo $label_class; ?>">Кому</label><input id="qr-email-address" type="email" maxlength="200" autocomplete="off" placeholder="hello@example.com" class="<?php echo $field_class; ?>"></div>
                        <div><label for="qr-email-subject" class="<?php echo $label_class; ?>">Тема</label><input id="qr-email-subject" type="text" maxlength="200" placeholder="Тема письма (необязательно)" class="<?php echo $field_class; ?>"></div>
                        <div><label for="qr-email-body" class="<?php echo $label_class; ?>">Текст письма</label><textarea id="qr-email-body" rows="3" maxlength="1000" class="n2j-textarea mt-1.5 w-full"></textarea></div>
                    </div>
                    <div data-qr-fields="phone" class="mt-4 hidden"><label for="qr-phone" class="<?php echo $label_class; ?>">Номер телефона</label><input id="qr-phone" type="tel" maxlength="40" autocomplete="off" placeholder="+7 999 123-45-67" class="<?php echo $field_class; ?>"></div>
                    <div data-qr-fields="sms" class="mt-4 hidden space-y-4">
                        <div><label for="qr-sms-phone" class="<?php echo $label_class; ?>">Номер получателя</label><input id="qr-sms-phone" type="tel" maxlength="40" autocomplete="off" placeholder="+7 999 123-45-67" class="<?php echo $field_class; ?>"></div>
                        <div><label for="qr-sms-body" class="<?php echo $label_class; ?>">Сообщение</label><textarea id="qr-sms-body" rows="3" maxlength="1000" class="n2j-textarea mt-1.5 w-full"></textarea></div>
                    </div>
                </div>

                <div id="qr-batch-panel" class="mt-4 hidden space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="qr-batch-mode" class="<?php echo $label_class; ?>">Формат списка</label><select id="qr-batch-mode" class="n2j-input n2j-select mt-1.5 w-full"><option value="lines">По одному значению на строку</option><option value="csv">CSV / TSV — имя и содержимое</option></select></div>
                        <div id="qr-delimiter-wrap" class="hidden"><label for="qr-delimiter" class="<?php echo $label_class; ?>">Разделитель колонок</label><select id="qr-delimiter" class="n2j-input n2j-select mt-1.5 w-full"><option value="auto">Определить автоматически</option><option value=";">Точка с запятой ;</option><option value=",">Запятая ,</option><option value="tab">Табуляция (TSV)</option></select></div>
                    </div>
                    <section class="rounded-lg border border-blue-100 bg-blue-50/60 p-4 dark:border-blue-900/50 dark:bg-blue-950/20" aria-labelledby="qr-id-heading">
                        <div class="flex flex-wrap items-center justify-between gap-2"><h3 id="qr-id-heading" class="text-sm font-semibold text-gray-800 dark:text-gray-100">Сгенерировать ID для пакета</h3><span class="text-xs text-gray-500 dark:text-gray-400">случайно и без повторов</span></div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_9rem]">
                            <div><label for="qr-id-mask" class="<?php echo $label_class; ?>">Маска</label><input id="qr-id-mask" type="text" maxlength="<?php echo (int) $settings['limits']['id_mask_chars']; ?>" value="ID-******" autocomplete="off" spellcheck="false" class="<?php echo $field_class; ?>"></div>
                            <div><label for="qr-id-count" class="<?php echo $label_class; ?>">Количество</label><input id="qr-id-count" type="number" min="1" max="<?php echo (int) $settings['limits']['batch']; ?>" step="1" value="10" class="<?php echo $field_class; ?>"></div>
                        </div>
                        <p class="n2j-hint mt-2"><b>#</b> — цифра 0–9, <b>*</b> — заглавная буква или цифра. Остальные буквы, цифры, _, - и . — как есть; буквальные # и * пишите как \# и \*; для буквальной обратной косой черты используйте \\. Например: <code class="rounded bg-white px-1 dark:bg-gray-800">prefix_******</code>. Дубликаты отклоняются; если вариантов маски меньше числа ID, добавьте случайные знаки.</p>
                        <button id="qr-id-generate" type="button" class="n2j-btn-secondary mt-3"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>Сгенерировать и заменить список</button>
                    </section>
                    <div>
                        <label for="qr-batch-text" class="<?php echo $label_class; ?>">Список ссылок или любых данных</label>
                        <textarea id="qr-batch-text" rows="7" spellcheck="false" placeholder="https://example.com/product-1&#10;https://example.com/product-2&#10;https://example.com/product-3" class="n2j-textarea mt-1.5 w-full"></textarea>
                        <p id="qr-batch-hint" class="n2j-hint mt-2">Каждая непустая строка станет отдельным кодом. Данные кодируются как есть, без сокращения ссылок.</p>
                    </div>
                    <div id="qr-drop-zone" class="flex flex-wrap items-center gap-3 rounded-lg border border-dashed border-gray-300 bg-gray-50 p-3 text-sm dark:border-gray-600 dark:bg-gray-900/50">
                        <label for="qr-import" class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 font-medium focus-within:ring-2 focus-within:ring-blue-500 text-gray-700 hover:border-blue-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"><i class="fas fa-file-arrow-up" aria-hidden="true"></i>Загрузить список<input id="qr-import" type="file" accept=".txt,.csv,.tsv,text/plain,text/csv,text/tab-separated-values" class="sr-only"></label>
                        <span class="text-xs text-gray-500 dark:text-gray-400">или перетащите TXT / CSV / TSV · UTF-8 · до 1 МБ</span>
                        <button id="qr-batch-example" type="button" class="n2j-link ml-auto min-h-[44px] px-2 text-xs">Вставить пример</button>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-sm"><span id="qr-batch-count" class="font-semibold text-gray-700 dark:text-gray-200">Кодов: 0</span><button id="qr-batch-clear" type="button" class="ml-auto min-h-[44px] px-2 text-xs text-gray-500 hover:text-rose-600 dark:text-gray-400">Очистить список</button></div>
                    <div id="qr-batch-summary" class="hidden overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700"><table class="n2j-table"><caption class="sr-only">Первые файлы пакета</caption><thead class="bg-gray-50 text-gray-500 dark:bg-gray-900 dark:text-gray-400"><tr><th scope="col" class="px-3 py-2">Файл</th><th scope="col" class="px-3 py-2">Содержимое</th></tr></thead><tbody id="qr-batch-rows" class="divide-y divide-gray-100 dark:divide-gray-700"></tbody></table></div>
                </div>
                <p id="qr-input-error" class="n2j-flash n2j-flash-err mt-4 hidden block"></p>
            </section>

            <section id="qr-design" class="n2j-card" aria-labelledby="qr-design-heading">
                <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="qr-design-heading" class="n2j-h3 flex items-center gap-2"><span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950 dark:text-blue-300">2</span>Оформление</h2><span class="text-xs text-gray-500 dark:text-gray-400">Один стиль для всего пакета</span></div>
                <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4" role="group" aria-label="Готовые стили">
                    <?php foreach ($settings['presets'] as $key => $preset): ?>
                    <button type="button" data-preset="<?php echo HTML::chars($key); ?>" aria-pressed="<?php echo $key === 'classic' ? 'true' : 'false'; ?>" class="flex min-h-[56px] items-center gap-2 rounded-lg border border-gray-200 px-3 py-3 text-sm font-medium text-gray-700 transition hover:border-blue-400 dark:border-gray-600 dark:text-gray-200">
                        <i class="fas fa-qrcode text-xl" aria-hidden="true" style="color:<?php echo HTML::chars($preset['accent']); ?>"></i><?php echo HTML::chars($preset['title']); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div><label for="qr-foreground" class="<?php echo $label_class; ?>">Цвет кода</label><div class="mt-1.5 flex items-center gap-2"><input id="qr-foreground" type="color" value="#0f172a" class="h-11 w-14 cursor-pointer rounded-lg border border-gray-300 bg-white p-1 dark:border-gray-600"><input id="qr-foreground-hex" type="text" value="#0f172a" maxlength="7" spellcheck="false" aria-label="Цвет кода, HEX" class="n2j-input min-w-0 flex-1 font-mono"></div></div>
                    <div><label for="qr-background" class="<?php echo $label_class; ?>">Цвет фона</label><div class="mt-1.5 flex items-center gap-2"><input id="qr-background" type="color" value="#ffffff" class="h-11 w-14 cursor-pointer rounded-lg border border-gray-300 bg-white p-1 dark:border-gray-600"><input id="qr-background-hex" type="text" value="#ffffff" maxlength="7" spellcheck="false" aria-label="Цвет фона, HEX" class="n2j-input min-w-0 flex-1 font-mono"></div></div>
                    <div><label for="qr-accent" class="<?php echo $label_class; ?>">Второй цвет</label><div class="mt-1.5 flex items-center gap-2"><input id="qr-accent" type="color" value="#2563eb" class="h-11 w-14 cursor-pointer rounded-lg border border-gray-300 bg-white p-1 dark:border-gray-600"><input id="qr-accent-hex" type="text" value="#2563eb" maxlength="7" spellcheck="false" aria-label="Второй цвет, HEX" class="n2j-input min-w-0 flex-1 font-mono"></div></div>
                </div>
                <label class="mt-3 inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300"><input id="qr-gradient" type="checkbox" class="rounded border-gray-300">Плавный переход между двумя цветами</label>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div><label for="qr-shape" class="<?php echo $label_class; ?>">Форма элементов</label><select id="qr-shape" class="n2j-input n2j-select mt-1.5 w-full"><?php foreach ($settings['shapes'] as $key => $title): ?><option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($title); ?></option><?php endforeach; ?></select></div>
                    <div><label for="qr-eye" class="<?php echo $label_class; ?>">Угловые метки</label><select id="qr-eye" class="n2j-input n2j-select mt-1.5 w-full"><?php foreach ($settings['eyes'] as $key => $title): ?><option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($title); ?></option><?php endforeach; ?></select></div>
                    <div><label for="qr-size" class="<?php echo $label_class; ?>">Размер картинки</label><select id="qr-size" class="n2j-input n2j-select mt-1.5 w-full"><?php foreach ($settings['sizes'] as $size): ?><option value="<?php echo (int) $size; ?>"<?php if ($size === 512) echo ' selected'; ?>><?php echo (int) $size; ?> × <?php echo (int) $size; ?> пикселей</option><?php endforeach; ?></select></div>
                </div>
                <div class="mt-5 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-white"><img id="qr-logo-thumb" alt="Загруженный логотип" class="hidden h-10 w-10 object-contain"><i id="qr-logo-icon" class="fas fa-image text-lg text-gray-400" aria-hidden="true"></i></span>
                        <div class="min-w-0 flex-1"><p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Логотип по центру</p><p id="qr-logo-name" class="n2j-hint break-words">PNG, JPEG или WebP · до 2 МБ</p></div>
                        <label for="qr-logo-file" class="inline-flex min-h-[44px] cursor-pointer items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium focus-within:ring-2 focus-within:ring-blue-500 text-gray-700 hover:border-blue-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">Выбрать картинку<input id="qr-logo-file" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only"></label>
                        <button id="qr-logo-demo" type="button" class="n2j-link min-h-[44px] px-2 text-xs">Пример</button>
                        <button id="qr-logo-remove" type="button" class="hidden min-h-[44px] px-2 text-xs font-medium text-rose-600 dark:text-rose-400">Убрать</button>
                    </div>
                    <div id="qr-logo-settings" class="mt-3 hidden"><label for="qr-logo-size" class="flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300">Размер логотипа <input id="qr-logo-size" type="range" min="12" max="20" step="1" value="16" class="min-w-0 flex-1 accent-blue-600"><output id="qr-logo-size-value" for="qr-logo-size" class="w-10 text-right text-xs font-semibold">16%</output></label><p class="n2j-hint mt-2">Защита H включается автоматически. Чем меньше логотип, тем надёжнее сканирование.</p></div>
                </div>
                <details class="mt-4 rounded-lg border border-gray-200 dark:border-gray-700"><summary class="min-h-[44px] cursor-pointer px-4 py-3 text-sm font-medium text-gray-600 dark:text-gray-300">Дополнительные настройки</summary><div class="grid gap-4 px-4 pb-4 sm:grid-cols-2"><div><label for="qr-level" class="<?php echo $label_class; ?>">Защита от повреждений</label><select id="qr-level" class="n2j-input n2j-select mt-1.5 w-full"><?php foreach ($settings['levels'] as $key => $title): ?><option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($title); ?></option><?php endforeach; ?></select></div><div><label for="qr-margin" class="<?php echo $label_class; ?>">Светлое поле вокруг кода</label><select id="qr-margin" class="n2j-input n2j-select mt-1.5 w-full"><option value="4">Стандартное — 4 модуля</option><option value="6">Побольше — 6 модулей</option><option value="8">Широкое — 8 модулей</option></select></div></div></details>
                <div class="mt-3 flex flex-wrap items-center gap-3 text-xs"><button id="qr-style-save" type="button" class="n2j-link min-h-[44px] px-2">Сохранить мой стиль</button><button id="qr-style-reset" type="button" class="min-h-[44px] px-2 text-gray-500 hover:text-blue-600 dark:text-gray-400">Сбросить оформление</button><span class="text-gray-400 dark:text-gray-500">Без содержимого и логотипа</span></div>
                <p id="qr-style-error" class="n2j-flash n2j-flash-err mt-3 hidden block"></p>
            </section>

            <section class="n2j-card border-blue-200 dark:border-blue-900/60" aria-labelledby="qr-result-heading">
                <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="qr-result-heading" class="n2j-h3 flex items-center gap-2"><span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-50 text-xs font-bold text-blue-600 dark:bg-blue-950 dark:text-blue-300">3</span>Ваш QR-код</h2><span id="qr-preview-label" class="max-w-full break-words text-xs text-gray-500 dark:text-gray-400">Предпросмотр</span></div>
                <div class="mt-4 flex flex-col items-center gap-4 sm:flex-row sm:items-start sm:gap-6">
                    <div class="flex w-full shrink-0 flex-col items-center sm:w-64">
                        <div id="qr-preview-frame" class="n2j-card p-0 flex aspect-square w-full max-w-xs items-center justify-center overflow-hidden">
                            <img id="qr-preview" alt="Предпросмотр вашего QR-кода" class="hidden block h-full w-full object-contain">
                            <p id="qr-placeholder" class="p-6 text-center text-sm text-gray-400">Заполните данные — здесь появится код</p>
                        </div>
                        <div id="qr-pager" class="mt-3 hidden items-center gap-3"><button id="qr-prev" type="button" aria-label="Предыдущий код" class="n2j-btn-icon"><i class="fas fa-chevron-left" aria-hidden="true"></i></button><span id="qr-page" class="text-xs font-semibold text-gray-500 dark:text-gray-400">1 / 1</span><button id="qr-next" type="button" aria-label="Следующий код" class="n2j-btn-icon"><i class="fas fa-chevron-right" aria-hidden="true"></i></button></div>
                    </div>
                    <div class="min-w-0 w-full flex-1 space-y-3">
                        <p id="qr-meta" class="n2j-hint">Готовим предпросмотр…</p>
                        <p id="qr-scan-status" role="status" aria-live="polite" class="text-sm text-gray-600 dark:text-gray-300">Проверяем читаемость в браузере…</p>
                        <p id="qr-warning" class="n2j-flash n2j-flash-warn hidden text-xs block"></p>
                        <div><label for="qr-format" class="<?php echo $label_class; ?>">Формат файла</label><select id="qr-format" class="n2j-input n2j-select mt-1.5 w-full"><?php foreach ($settings['formats'] as $key => $title): ?><option value="<?php echo HTML::chars($key); ?>"><?php echo HTML::chars($title); ?></option><?php endforeach; ?></select></div>
                        <button id="qr-download" type="button" disabled class="n2j-btn-process w-full"><i class="fas fa-download" aria-hidden="true"></i><span id="qr-download-label">Скачать PNG</span></button>
                        <button id="qr-batch-print" type="button" disabled class="n2j-btn-secondary hidden w-full"><i class="fas fa-print" aria-hidden="true"></i>Печать пакета на A4</button>
                        <button id="qr-copy-image" type="button" disabled class="n2j-btn-secondary w-full"><i class="fas fa-copy" aria-hidden="true"></i>Копировать картинку</button>
                        <label id="qr-verify-wrap" class="hidden items-start gap-2 text-xs text-gray-500 dark:text-gray-400"><input id="qr-verify" type="checkbox" checked class="mt-0.5 rounded border-gray-300">Проверять читаемость каждого кода перед добавлением в архив</label>
                        <p id="qr-export-note" class="n2j-hint">Без водяных знаков. Ссылка открывается напрямую.</p>
                        <div id="qr-progress-wrap" class="hidden space-y-2"><div class="flex items-center justify-between gap-2"><span id="qr-progress-text" role="status" aria-live="polite" class="text-xs text-gray-500 dark:text-gray-400"></span><button id="qr-cancel" type="button" class="min-h-[44px] px-2 text-xs font-semibold text-rose-600 dark:text-rose-400">Отменить</button></div></div>
                    </div>
                </div>
                <p id="qr-error" role="alert" class="n2j-flash n2j-flash-err mt-4 hidden block"></p>
                <p id="qr-message" role="status" aria-live="polite" class="mt-3 hidden text-sm font-medium text-emerald-700 dark:text-emerald-400"></p>
            </section>
        </main>

        <aside class="mt-6 w-full shrink-0 space-y-4 xl:mt-0 xl:w-80">
            <section class="rounded-xl border border-blue-200 bg-blue-50/60 p-5 dark:border-blue-900/60 dark:bg-blue-950/30"><h2 class="text-sm font-semibold text-blue-900 dark:text-blue-200">От ссылки до готовой картинки</h2><ol class="mt-3 space-y-3 text-sm text-blue-800 dark:text-blue-300"><li><strong>1.</strong> Вставьте данные или список.</li><li><strong>2.</strong> Выберите стиль и добавьте логотип.</li><li><strong>3.</strong> Скачайте картинку или ZIP.</li></ol><p class="mt-4 text-xs text-blue-700 dark:text-blue-400">Для меню кафе, визиток, упаковки, товаров и мероприятий.</p></section>
            <section class="n2j-card"><h2 class="n2j-h3"><i class="fas fa-camera mr-2 text-blue-500" aria-hidden="true"></i>Перед печатью</h2><ul class="mt-3 space-y-2 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><li>Сканируйте код камерой телефона именно в размере, в котором будете его использовать.</li><li>Не обрезайте светлое поле. На пёстром фоне оставляйте белую подложку.</li><li>Начните с логотипа 16% и тёмных цветов. Большой логотип или мелкая печать ухудшают читаемость.</li><li>SVG не теряет качество при увеличении. PNG лучше скачивать от 512 пикселей.</li></ul></section>
            <section class="n2j-card"><h2 class="n2j-h3">Частые вопросы</h2><div class="mt-2 divide-y divide-gray-100 dark:divide-gray-700"><?php foreach ($faq as $item): ?><details class="py-2"><summary class="cursor-pointer py-1 text-sm font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars($item[0]); ?></summary><p class="n2j-hint mt-2"><?php echo HTML::chars($item[1]); ?></p></details><?php endforeach; ?></div></section>
            <p class="n2j-hint px-1"><i class="fas fa-lock mr-1" aria-hidden="true"></i>Пароли, контакты и картинки не отправляются на сервер — они остаются в этой вкладке. Скачанные файлы содержат ваши данные — делитесь ими осознанно.</p>
        </aside>
    </div>
</div>
<script id="qr-settings" type="application/json"><?php echo json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="application/ld+json"><?php echo json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($item) { return ['@type' => 'Question', 'name' => $item[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]]]; }, $faq)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php echo View::factory('510/script'); ?>
