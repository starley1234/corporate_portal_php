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
    'title' => 'Слияние договоров (DocMergeAI)',
    'pill' => ['prx399 · DocMergeAI', 'fa-file-contract'],
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
                <input class="prx-input mt-1 w-full" id="prx399-title" name="title" maxlength="<?php echo (int) $limits['max_title']; ?>" required placeholder="Договор поставки №17-2026 и ДС">
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
            <div>
                <label class="prx-label" for="prx399-base">Базовый договор (PDF)</label>
                <input class="prx-input mt-1 w-full" id="prx399-base" type="file" name="base" accept="application/pdf" required>
            </div>
            <div>
                <label class="prx-label" for="prx399-ds">Допсоглашения в хронологии (PDF, можно несколько)</label>
                <input class="prx-input mt-1 w-full" id="prx399-ds" type="file" name="ds[]" accept="application/pdf" multiple>
            </div>
<?php if ($can_run): ?>
            <button type="submit" class="prx-btn-cta w-full justify-center"><i class="fas fa-layer-group mr-2"></i>Создать пакет</button>
<?php endif; ?>
            <p class="prx-hint">Формат — PDF (сканы или с текстовым слоем), до <?php echo (int) $limits['max_files']; ?> файлов. DOCX — планируемое расширение.</p>
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
