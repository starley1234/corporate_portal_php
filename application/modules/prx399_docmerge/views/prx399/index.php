<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Список пакетов + создание. Шапка — по эталону (partials/page_header):
 * крошки → H1 + плашка → действия → lead. Форма «Zero-Config»: обязательны
 * только название и файлы; роль аудита и политика — необязательные уточнения.
 */

$badges = [
    'draft' => 'prx-badge-wait', 'parsing' => 'prx-badge-run', 'auditing' => 'prx-badge-run',
    'audited' => 'prx-badge-ok', 'failed' => 'prx-badge-err',
];
?>

<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

<?php echo View::factory('partials/page_header', [
    'crumbs' => [['Кабинет', '/dashboard'], ['Слияние договоров']],
    'title' => 'Слияние договоров',
    'pill' => ['prx399 · слияние договоров', 'fa-file-contract'],
    'lead' => 'Сканы договора и допсоглашений → актуальная редакция + карта рисков. Сборку текста делает код по инструкциям модели — «галлюцинации» в документ не попадают.',
]); ?>

<?php echo View::factory('partials/docflow_flash', ['flash' => $flash]); ?>

<div class="mt-6 gap-6 space-y-6 md:grid md:grid-cols-3 md:items-start md:space-y-0">

    <!-- ==== Новая сделка (1/3) ==== -->
    <div class="prx-card md:col-span-1">
        <div class="prx-card-head"><h2 class="prx-h3">Новый пакет</h2></div>
        <form method="post" action="/prx399_docmerge" enctype="multipart/form-data" class="space-y-3">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <div>
                <label class="prx-label" for="prx399-title">Название пакета</label>
                <input class="prx-input mt-1 w-full" id="prx399-title" name="title" maxlength="<?php echo (int) $limits['max_title']; ?>" autocomplete="off" placeholder="Договор поставки №17-2026 и ДС">
                <p class="prx-hint mt-1">Необязательно: без названия подставим имя загруженного PDF.</p>
            </div>
            <div>
                <label class="prx-label" for="prx399-focus">Фокус риска (необязательно)</label>
                <select class="prx-input mt-1 w-full" id="prx399-focus" name="focus">
<?php foreach ($focus as $key => $label): ?>
                    <option value="<?php echo HTML::chars($key); ?>"<?php echo $key === 'balanced' ? ' selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
<?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="prx-label" for="prx399-policy">Политика рисков (необязательно)</label>
                <textarea class="prx-input mt-1 w-full" id="prx399-policy" name="policy" rows="3" placeholder="Например: неустойка не выше 0,1%/день; постоплата до 30 дней; суд — АС Москвы"></textarea>
            </div>
<?php // Приём файлов — по эталону /pages/required_design_sample#file-upload:
      // drop-зона поверх скрытого input, drag&drop, статус выбранного файла. ?>
            <div>
                <span class="prx-label">Базовый договор (PDF)</span>
                <div data-prx399-drop class="relative mt-1 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-5 text-center transition focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500 data-[dragging=true]:border-blue-500 data-[dragging=true]:ring-2 data-[dragging=true]:ring-blue-500 dark:border-gray-600 dark:bg-gray-800/50">
                    <i class="fas fa-file-contract text-2xl text-blue-500" aria-hidden="true"></i>
                    <label class="prx-label mt-2 block" for="prx399-base">Выбрать файл</label>
                    <p class="prx-hint mt-1" data-prx399-drag-hint hidden>или перетащите его сюда</p>
                    <p class="prx-hint mt-1">PDF · один файл</p>
                    <input id="prx399-base" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" type="file" name="base"
                           accept="application/pdf,.pdf" required data-prx399-max="1"
                           aria-describedby="prx399-base-status prx399-base-error">
                    <p class="prx-hint mt-1 min-w-0 break-words" id="prx399-base-status" data-prx399-status role="status"></p>
                </div>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <button type="button" class="prx-btn-secondary prx-btn-sm hidden" data-prx399-clear>Убрать файл</button>
                    <p class="prx-hint min-w-0 flex-1 break-words text-rose-700 dark:text-rose-300" id="prx399-base-error" data-prx399-error role="alert"></p>
                </div>
            </div>
            <div>
                <span class="prx-label">Допсоглашения в хронологии (PDF, можно несколько)</span>
                <div data-prx399-drop class="relative mt-1 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-5 text-center transition focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500 data-[dragging=true]:border-blue-500 data-[dragging=true]:ring-2 data-[dragging=true]:ring-blue-500 dark:border-gray-600 dark:bg-gray-800/50">
                    <i class="fas fa-file-circle-plus text-2xl text-blue-500" aria-hidden="true"></i>
                    <label class="prx-label mt-2 block" for="prx399-ds">Выбрать файлы</label>
                    <p class="prx-hint mt-1" data-prx399-drag-hint hidden>или перетащите их сюда</p>
                    <p class="prx-hint mt-1">PDF · несколько файлов</p>
                    <input id="prx399-ds" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" type="file" name="ds[]"
                           accept="application/pdf,.pdf" multiple data-prx399-max="0"
                           aria-describedby="prx399-ds-status prx399-ds-error">
                    <p class="prx-hint mt-1 min-w-0 break-words" id="prx399-ds-status" data-prx399-status role="status"></p>
                </div>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <button type="button" class="prx-btn-secondary prx-btn-sm hidden" data-prx399-clear>Убрать файлы</button>
                    <p class="prx-hint min-w-0 flex-1 break-words text-rose-700 dark:text-rose-300" id="prx399-ds-error" data-prx399-error role="alert"></p>
                </div>
            </div>
<?php if ($can_run): ?>
            <button type="submit" id="prx399-create" class="prx-btn-cta w-full justify-center">
                <span data-prx-idle><i class="fas fa-layer-group mr-2"></i>Создать пакет</span>
                <span data-prx-busy class="hidden"><i class="fas fa-circle-notch fa-spin mr-2"></i>Загружаю файлы и ставлю задачи…</span>
            </button>
<?php endif; ?>
            <p class="prx-hint">Формат — PDF (сканы или с текстовым слоем), до <?php echo (int) $limits['max_files']; ?> файлов. DOCX — планируемое расширение. Проверка размера и содержимого — на сервере.</p>
        </form>
    </div>

    <!-- ==== Список пакетов (2/3) ==== -->
    <div class="prx-card md:col-span-2">
        <div class="prx-card-head"><h2 class="prx-h3">Пакеты</h2></div>
<?php if (empty($contracts)): ?>
        <div class="prx-empty">
            <div class="prx-empty-icon"><i class="fas fa-file-contract" aria-hidden="true"></i></div>
            <p class="prx-empty-text mt-4">Пакетов пока нет. Загрузите договор и цепочку ДС слева — остальное система сделает сама.</p>
        </div>
<?php else: ?>
        <table class="prx-table w-full">
            <thead>
            <tr>
                <th scope="col">Пакет</th>
                <th scope="col">Статус</th>
                <th scope="col">Создан</th>
                <th scope="col"><span class="sr-only">Действия</span></th>
            </tr>
            </thead>
            <tbody>
<?php foreach ($contracts as $row): ?>
            <tr>
                <td class="font-semibold"><a class="prx-link" href="/prx399_docmerge/contract/<?php echo (int) $row['id']; ?>"><?php echo HTML::chars($row['title']); ?></a></td>
                <td><span class="prx-badge <?php echo $badges[$row['status']] ?? 'prx-badge-wait'; ?>"><span class="prx-dot"></span><?php echo HTML::chars($statuses[$row['status']][0] ?? $row['status']); ?></span></td>
                <td class="text-xs text-gray-500"><?php echo HTML::chars(Date::formatted_time((string) $row['created_at'], 'd.m.Y H:i')); ?></td>
                <td class="text-right"><a class="prx-btn-secondary prx-btn-sm" href="/prx399_docmerge/contract/<?php echo (int) $row['id']; ?>">открыть</a></td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php endif; ?>
    </div>
</div>
</div>

<script>
// Приём файлов по эталону (required_design_sample#file-upload): клик по зоне —
// выбор, drag&drop — перенос; статус и ошибки — по месту; лимиты проверяет сервер.
// Создание пакета: POST с файлами занимает секунды — на это время кнопка
// занята (PRX.busy) и повторный клик невозможен; redirect/ошибка рисуют
// страницу заново и снимают блокировку.
(function () {
    'use strict';

    var form = document.querySelector('form[action="/prx399_docmerge"]');
    var create = document.getElementById('prx399-create');
    if (form && create && window.PRX) {
        form.addEventListener('submit', function () {
            window.PRX.run(create, true);
        });
    }

    var sizeFormat = new Intl.NumberFormat('ru', { maximumFractionDigits: 1 });

    function humanSize(bytes) {
        if (bytes < 1024) return bytes + ' Б';
        if (bytes < 1048576) return sizeFormat.format(bytes / 1024) + ' КиБ';
        return sizeFormat.format(bytes / 1048576) + ' МиБ';
    }

    document.querySelectorAll('[data-prx399-drop]').forEach(function (zone) {
        var input = zone.querySelector('input[type="file"]');
        var status = zone.querySelector('[data-prx399-status]');
        var error = zone.parentElement.querySelector('[data-prx399-error]');
        var hint = zone.querySelector('[data-prx399-drag-hint]');
        var clear = zone.parentElement.querySelector('[data-prx399-clear]');
        if (!input || !status || !error) return;
        var multiple = input.getAttribute('data-prx399-max') !== '1';

        function showError(message) {
            input.setCustomValidity(message);
            if (message) input.setAttribute('aria-invalid', 'true');
            else input.removeAttribute('aria-invalid');
            error.textContent = message;
        }

        function showFiles() {
            var files = Array.prototype.slice.call(input.files || []);
            clear && clear.classList.toggle('hidden', !files.length);
            status.textContent = '';
            var message = '';
            if (files.length) {
                if (multiple) {
                    status.textContent = files.length + ' ' + (files.length === 1 ? 'файл' : files.length < 5 ? 'файла' : 'файлов')
                        + ' · ' + files.map(function (file) { return file.name; }).join(', ');
                } else {
                    status.textContent = files[0].name + ' · ' + humanSize(files[0].size);
                }
                var bad = files.filter(function (file) {
                    return !file.size || !/\.pdf$/i.test(file.name) && file.type !== 'application/pdf';
                });
                if (bad.length) message = 'PDF нужен только: ' + bad[0].name + '.';
                else if (!multiple && files.length > 1) message = 'Выберите один файл.';
            }
            showError(message);
        }

        input.addEventListener('change', showFiles);
        if (clear) clear.addEventListener('click', function () {
            input.value = '';
            showFiles();
            input.focus();
        });
        zone.addEventListener('dragover', function (event) {
            if (!event.dataTransfer || !Array.prototype.slice.call(event.dataTransfer.types || []).includes('Files')) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
            zone.dataset.dragging = 'true';
            hint && (hint.hidden = false);
        });
        zone.addEventListener('dragleave', function () {
            delete zone.dataset.dragging;
            hint && (hint.hidden = true);
        });
        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            delete zone.dataset.dragging;
            hint && (hint.hidden = true);
            var files = event.dataTransfer && event.dataTransfer.files;
            if (!files || !files.length) return;
            try {
                if (!multiple && files.length > 1) {
                    showError('Перетащите один файл.');
                    return;
                }
                input.files = files;
                showFiles();
            } catch (exception) {
                showError('Не удалось перетащить файл. Нажмите «Выбрать файл».');
            }
        });
    });
})();
</script>
