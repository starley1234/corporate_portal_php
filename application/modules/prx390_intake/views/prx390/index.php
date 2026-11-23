<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php
    $scan_form = '<form method="post" action="/inbox/scan" class="inline-flex">'
        .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
        .'<button type="submit" class="prx-btn-process"><i class="fas fa-folder-open"></i><span>Сканировать папку</span></button>'
        .'</form>';

    echo View::factory('partials/page_header', [
        'crumbs' => [['Кабинет', '/dashboard'], ['Документооборот'], ['Входящие']],
        'title' => 'Входящие документы',
        'pill' => ['Тестовый режим', 'fa-inbox'],
        'actions' => $can_manage ? [
            ['html' => '<form method="post" action="/inbox/upload"><input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
                .'<button class="prx-btn-process" type="submit" name="sample" value="1"><i class="fas fa-play"></i>Принять пробное письмо</button></form>'],
            ['html' => $scan_form],
            ['label' => 'Реестр', 'href' => '/prx391_documents', 'icon' => 'fa-folder-open', 'role' => 'secondary'],
        ] : [['label' => 'Реестр', 'href' => '/prx391_documents', 'icon' => 'fa-folder-open', 'role' => 'secondary']],
        'lead' => 'Нажмите «Принять пробное письмо» или загрузите свой тестовый PDF. После распознавания появится карточка в реестре. Не используйте реальные документы на этом стенде.',
    ]);
    echo View::factory('partials/docflow_flash', ['flash' => $flash]);
    ?>

    <section class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" aria-label="Показатели приёма">
        <?php
        $kpis = [
            ['В папке', $inbox_count, 'fas fa-box-open', $inbox_count ? 'warn' : 'ok', 'файлов ждут разбора'],
            ['Всего принято', $stats['total'], 'fas fa-inbox', 'info', 'конвертов в журнале'],
            ['Текст готов', $stats['text_ready'] + $stats['registered'], 'fas fa-file-lines', 'ok', 'можно анализировать'],
            ['Нужен OCR', $stats['needs_ocr'], 'fas fa-eye', 'wait', 'текста мало или нет'],
            ['Ошибки', $stats['error'], 'fas fa-triangle-exclamation', $stats['error'] ? 'err' : 'ok', 'требуют повтора'],
            ['В реестре', $stats['registered'], 'fas fa-folder-open', 'info', 'карточек создано'],
        ];
        foreach ($kpis as $kpi): ?>
            <div class="prx-card">
                <div class="flex items-start justify-between gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kpi[0]); ?></span>
                    <i class="<?php echo HTML::chars($kpi[2]); ?> text-gray-400"></i>
                </div>
                <div class="mt-2 text-3xl font-bold text-gray-900 dark:text-white"><?php echo (int) $kpi[1]; ?></div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($kpi[4]); ?></div>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="min-w-0">
            <form method="get" action="/inbox" class="prx-card">
                <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_200px_auto]">
                    <div>
                        <label class="prx-label" for="inbox-q">Поиск по конвертам</label>
                        <input id="inbox-q" name="q" type="search" value="<?php echo HTML::chars($filters['q']); ?>" placeholder="тема, файл, отправитель" class="prx-input mt-1 w-full">
                    </div>
                    <div>
                        <label class="prx-label" for="inbox-status">Статус</label>
                        <select id="inbox-status" name="status" class="prx-select mt-1 w-full">
                            <option value="">Любой</option>
                            <?php foreach (['received' => 'Принят', 'text_ready' => 'Текст готов', 'registered' => 'В реестре', 'needs_ocr' => 'Нужен OCR', 'error' => 'Ошибка'] as $code => $label): ?>
                                <option value="<?php echo $code; ?>" <?php echo $filters['status'] === $code ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button class="prx-btn-process" type="submit"><i class="fas fa-filter"></i>Применить</button>
                        <a class="prx-btn-secondary" href="/inbox">Сбросить</a>
                    </div>
                </div>
            </form>

            <div class="prx-card mt-4 overflow-x-auto p-0">
                <table class="prx-table min-w-[920px]">
                    <thead>
                    <tr>
                        <th>Конверт</th>
                        <th>Статус</th>
                        <th>Текст</th>
                        <th>Карточка</th>
                        <th>Получен</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($messages as $m): ?>
                        <tr>
                            <td>
                                <span class="block font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($m['subject'] ?: 'Без темы'); ?></span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($m['source_ref'] ?: $m['source']); ?> · файлов: <?php echo (int) $m['files_count']; ?></span>
                                <span class="block font-mono text-[11px] text-gray-400 dark:text-gray-500">sha256: <?php echo HTML::chars(substr($m['sha256'], 0, 16)); ?>…</span>
                            </td>
                            <td>
                                <?php
                                $badge = [
                                    'received' => ['wait', 'принят'],
                                    'text_ready' => ['ok', 'текст готов'],
                                    'registered' => ['info', 'в реестре'],
                                    'needs_ocr' => ['wait', 'нужен OCR'],
                                    'error' => ['err', 'ошибка'],
                                ][$m['status']] ?? ['wait', $m['status']];
                                ?>
                                <span class="prx-badge prx-badge-<?php echo $badge[0]; ?>"><span class="prx-dot"></span><?php echo HTML::chars($badge[1]); ?></span>
                                <?php if ( ! empty($m['error'])): ?><span class="mt-1 block max-w-[220px] truncate text-xs text-red-600 dark:text-red-400" title="<?php echo HTML::chars($m['error']); ?>"><?php echo HTML::chars($m['error']); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <span class="block text-sm font-semibold"><?php echo (int) ($m['text_chars'] ?? 0); ?> симв.</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo $m['text_chars'] ? 'распознан' : 'ещё нет'; ?></span>
                            </td>
                            <td>
                                <?php if ( ! empty($m['document_id'])): ?>
                                    <a class="prx-btn-secondary prx-btn-sm" href="/prx391_documents/<?php echo (int) $m['document_id']; ?>">Открыть</a>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">ожидает текста</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="block"><?php echo HTML::chars(date('d.m.Y', strtotime($m['received_at']))); ?></span><span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(date('H:i', strtotime($m['received_at']))); ?></span></td>
                            <td class="text-right">
                                <?php if ($can_manage AND in_array($m['status'], ['error', 'received', 'needs_ocr'], TRUE)): ?>
                                    <form method="post" action="/inbox/retry/<?php echo (int) $m['id']; ?>" class="inline-flex">
                                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                                        <button type="submit" class="prx-btn-secondary prx-btn-sm" title="Повторить распознавание"><i class="fas fa-rotate"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($messages)): ?>
                        <tr><td colspan="6" class="text-center text-gray-500 dark:text-gray-400">Пока пусто. Примите пробное письмо или загрузите PDF.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <aside class="space-y-4">
            <section class="prx-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-diagram-project mr-2 text-blue-500"></i>Конвейер</h2>
                <ol class="mt-3 space-y-2 text-sm">
                    <li class="flex items-start gap-3"><span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">1</span><span><b>PDF</b> из папки → проверка типа и размера</span></li>
                    <li class="flex items-start gap-3"><span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">2</span><span><b>prx212</b> хранит файл, prx390 — конверт и SHA-256</span></li>
                    <li class="flex items-start gap-3"><span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">3</span><span><b>doc.normalize</b> в воркере извлекает текст (pypdf)</span></li>
                    <li class="flex items-start gap-3"><span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">4</span><span><b>prx391</b> регистрирует карточку по событию</span></li>
                </ol>
                <div class="mt-4 rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-800/60 dark:text-gray-300">
                    <div class="font-semibold text-gray-800 dark:text-gray-100">Папка входящих</div>
                    <code class="mt-1 block break-all font-mono text-[11px]"><?php echo HTML::chars($inbox_dir); ?></code>
                    <?php if ($channel): ?>
                        <div class="mt-2">Последнее сканирование: <?php echo $channel['last_scan_at'] ? HTML::chars(date('d.m.Y H:i', strtotime($channel['last_scan_at']))) : 'ещё не было'; ?></div>
                        <?php if ( ! empty($channel['last_error'])): ?><div class="mt-1 text-red-600 dark:text-red-400"><?php echo HTML::chars($channel['last_error']); ?></div><?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if ($inbox_sample): ?>
                    <div class="mt-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Ждут разбора</div>
                        <ul class="mt-1 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                            <?php foreach ($inbox_sample as $name): ?><li class="truncate"><i class="fas fa-file-pdf mr-1 text-red-400"></i><?php echo HTML::chars($name); ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($can_manage): ?>
            <section class="prx-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-upload mr-2 text-blue-500"></i>Загрузить PDF</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Ручной приём для проверки: файл сразу уходит в тот же конвейер.</p>
                <form method="post" action="/inbox/upload" enctype="multipart/form-data" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <input type="file" name="file" accept="application/pdf,.pdf" required class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 dark:text-gray-200">
                    <button class="prx-btn-cta w-full" type="submit"><i class="fas fa-arrow-up-from-bracket"></i>Принять файл</button>
                </form>
            </section>
            <?php endif; ?>

            <section class="prx-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-circle-info mr-2 text-blue-500"></i>Что дальше</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">После текста документ автоматически появляется в <a class="text-blue-600 hover:underline dark:text-blue-400" href="/prx391_documents">реестре</a>. Разбор ИИ запускается в карточке или на странице <a class="text-blue-600 hover:underline dark:text-blue-400" href="/analysis">ИИ-разбор</a>.</p>
            </section>
        </aside>
    </div>
</div>
