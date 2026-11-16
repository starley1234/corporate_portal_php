<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
$status_badge = [
    'new' => ['wait', 'Новый'],
    'in_review' => ['run', 'На разборе'],
    'in_work' => ['run', 'В работе'],
    'on_hold' => ['wait', 'Приостановлен'],
    'closed' => ['ok', 'Закрыт'],
    'archived' => ['wait', 'В архиве'],
][$doc['status']] ?? ['wait', $doc['status']];

$priority_badge = ['urgent' => 'err', 'high' => 'run', 'normal' => 'info', 'low' => 'wait'][$doc['priority']] ?? 'info';
$risk_badge = ['high' => 'err', 'medium' => 'warn', 'low' => 'ok'][$doc['risk_level'] ?? ''] ?? 'wait';

$close_form = '<form method="post" action="/documents/'.($doc['status'] === 'closed' ? 'reopen' : 'close').'/'.(int) $doc['id'].'" class="inline-flex">'
    .'<input type="hidden" name="csrf" value="'.HTML::chars($csrf).'">'
    .'<button type="submit" class="'.($doc['status'] === 'closed' ? 'prl-btn-secondary' : 'prl-btn-secondary').'"><i class="fas '.($doc['status'] === 'closed' ? 'fa-rotate-left' : 'fa-circle-check').'"></i><span>'.($doc['status'] === 'closed' ? 'Вернуть в работу' : 'Закрыть').'</span></button>'
    .'</form>';

$actions = [];
if ($can_edit)
{
    $actions[] = ['html' => $close_form];
}
if ($can_draft)
{
    $actions[] = ['label' => 'Подготовить ответ', 'href' => '/replies/document/'.(int) $doc['id'], 'icon' => 'fa-reply', 'role' => 'cta'];
}
$actions[] = ['label' => 'К реестру', 'href' => '/documents', 'icon' => 'fa-arrow-left', 'role' => 'secondary'];

echo View::factory('partials/page_header', [
    'crumbs' => [['Кабинет', '/dashboard'], ['Реестр', '/documents'], [$doc['reg_no']]],
    'title' => $doc['subject'] ?: 'Без темы',
    'pill' => [$doc['reg_no'], 'fa-file-lines'],
    'actions' => $actions,
    'lead' => 'Тип: '.($doc['type_title'] ?: 'не определён').' · Корреспондент: '.($doc['correspondent_name'] ?: ($doc['sender_name'] ?: 'не определён')).' · Поступил '.date('d.m.Y H:i', strtotime($doc['received_at'])),
]);
echo View::factory('partials/docflow_flash', ['flash' => $flash]);
?>

<div class="prl-ds w-full px-3.5 pb-16 sm:px-6 lg:px-8">
    <section class="mt-4 flex flex-wrap items-center gap-2">
        <span class="prl-badge prl-badge-<?php echo $status_badge[0]; ?>"><span class="prl-dot"></span><?php echo HTML::chars($status_badge[1]); ?></span>
        <span class="prl-badge prl-badge-<?php echo $priority_badge; ?>"><?php echo HTML::chars($priorities[$doc['priority']] ?? $doc['priority']); ?></span>
        <?php if ($doc['risk_level']): ?><span class="prl-badge prl-badge-<?php echo $risk_badge; ?>"><i class="fas fa-shield-halved"></i>риск: <?php echo HTML::chars(['high' => 'высокий', 'medium' => 'средний', 'low' => 'низкий'][$doc['risk_level']] ?? $doc['risk_level']); ?></span><?php endif; ?>
        <?php if ($doc['sensitivity'] !== 'internal'): ?><span class="prl-badge prl-badge-wait"><i class="fas fa-lock"></i><?php echo HTML::chars($sensitivities[$doc['sensitivity']] ?? $doc['sensitivity']); ?></span><?php endif; ?>
        <?php if ($doc['deadline_at']): ?>
            <span class="prl-badge prl-badge-<?php echo $doc['deadline_state'] === 'overdue' ? 'err' : ($doc['deadline_state'] === 'soon' ? 'run' : 'info'); ?>"><i class="fas fa-clock"></i>срок <?php echo HTML::chars(date('d.m.Y H:i', strtotime($doc['deadline_at']))); ?></span>
        <?php endif; ?>
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="min-w-0 space-y-5">
            <section class="prl-card">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-file-pen mr-2 text-blue-500"></i>Карточка документа</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Изменения полей пишутся в провенанс</span>
                </div>

                <?php if ($can_edit): ?>
                <form method="post" action="/documents/save/<?php echo (int) $doc['id']; ?>" class="mt-4">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label class="prl-label" for="doc-subject">Тема</label>
                            <input id="doc-subject" name="subject" type="text" maxlength="500" value="<?php echo HTML::chars($doc['subject']); ?>" class="prl-input mt-1 w-full" required>
                        </div>
                        <div>
                            <label class="prl-label" for="doc-type">Тип</label>
                            <select id="doc-type" name="type_id" class="prl-select mt-1 w-full">
                                <option value="0">Не определён</option>
                                <?php foreach ($types as $type): ?><option value="<?php echo (int) $type['id']; ?>" <?php echo (int) $doc['type_id'] === (int) $type['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($type['title']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="prl-label" for="doc-corr">Корреспондент</label>
                            <select id="doc-corr" name="correspondent_id" class="prl-select mt-1 w-full">
                                <option value="0">Не определён</option>
                                <?php foreach ($correspondents as $corr): ?><option value="<?php echo (int) $corr['id']; ?>" <?php echo (int) $doc['correspondent_id'] === (int) $corr['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($corr['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="prl-label" for="doc-sender">Отправитель (строка)</label>
                            <input id="doc-sender" name="sender_name" type="text" value="<?php echo HTML::chars($doc['sender_name']); ?>" class="prl-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prl-label" for="doc-inn">ИНН</label>
                            <input id="doc-inn" name="sender_inn" type="text" inputmode="numeric" maxlength="12" value="<?php echo HTML::chars($doc['sender_inn']); ?>" class="prl-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prl-label" for="doc-out-no">Исходящий №</label>
                            <input id="doc-out-no" name="outbound_no" type="text" value="<?php echo HTML::chars($doc['outbound_no']); ?>" class="prl-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prl-label" for="doc-out-date">Дата исходящего</label>
                            <input id="doc-out-date" name="outbound_date" type="date" value="<?php echo HTML::chars($doc['outbound_date']); ?>" class="prl-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prl-label" for="doc-contract">Договор</label>
                            <input id="doc-contract" name="contract_no" type="text" value="<?php echo HTML::chars($doc['contract_no']); ?>" class="prl-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prl-label" for="doc-amount">Сумма, ₽</label>
                            <input id="doc-amount" name="amount" type="text" inputmode="decimal" value="<?php echo $doc['amount'] !== NULL ? HTML::chars($doc['amount']) : ''; ?>" class="prl-input mt-1 w-full">
                        </div>
                        <div class="md:col-span-2">
                            <label class="prl-label" for="doc-summary">Краткое содержание</label>
                            <textarea id="doc-summary" name="summary" rows="4" class="prl-input mt-1 w-full"><?php echo HTML::chars($doc['summary']); ?></textarea>
                        </div>
                        <div>
                            <label class="prl-label" for="doc-priority">Приоритет</label>
                            <select id="doc-priority" name="priority" class="prl-select mt-1 w-full"><?php foreach ($priorities as $code => $label): ?><option value="<?php echo $code; ?>" <?php echo $doc['priority'] === $code ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option><?php endforeach; ?></select>
                        </div>
                        <div>
                            <label class="prl-label" for="doc-sens">Чувствительность</label>
                            <select id="doc-sens" name="sensitivity" class="prl-select mt-1 w-full"><?php foreach ($sensitivities as $code => $label): ?><option value="<?php echo $code; ?>" <?php echo $doc['sensitivity'] === $code ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option><?php endforeach; ?></select>
                        </div>
                        <div>
                            <label class="prl-label" for="doc-status">Статус</label>
                            <select id="doc-status" name="status" class="prl-select mt-1 w-full"><?php foreach ($statuses as $code => $label): ?><option value="<?php echo $code; ?>" <?php echo $doc['status'] === $code ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option><?php endforeach; ?></select>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <button class="prl-btn-cta" type="submit"><i class="fas fa-floppy-disk"></i>Сохранить карточку</button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">После сохранения срок пересчитается автоматически.</span>
                    </div>
                </form>
                <?php else: ?>
                    <dl class="mt-4 grid gap-4 text-sm md:grid-cols-2">
                        <div><dt class="text-gray-500 dark:text-gray-400">Тип</dt><dd class="font-semibold"><?php echo HTML::chars($doc['type_title'] ?: 'Не определён'); ?></dd></div>
                        <div><dt class="text-gray-500 dark:text-gray-400">Корреспондент</dt><dd class="font-semibold"><?php echo HTML::chars($doc['correspondent_name'] ?: ($doc['sender_name'] ?: '—')); ?></dd></div>
                        <div><dt class="text-gray-500 dark:text-gray-400">Исходящий</dt><dd class="font-semibold"><?php echo HTML::chars(trim(($doc['outbound_no'] ?: '—').' '.($doc['outbound_date'] ? 'от '.date('d.m.Y', strtotime($doc['outbound_date'])) : ''))); ?></dd></div>
                        <div><dt class="text-gray-500 dark:text-gray-400">Сумма</dt><dd class="font-semibold"><?php echo $doc['amount'] !== NULL ? HTML::chars(number_format((float) $doc['amount'], 2, ',', ' ')).' ₽' : '—'; ?></dd></div>
                        <div class="md:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Краткое содержание</dt><dd class="mt-1 whitespace-pre-line"><?php echo HTML::chars($doc['summary'] ?: '—'); ?></dd></div>
                    </dl>
                <?php endif; ?>
            </section>

            <?php if ($analysis): ?>
            <?php $payload = $analysis['payload']; ?>
            <section class="prl-card border-l-4 border-l-blue-500">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-wand-magic-sparkles mr-2 text-blue-500"></i>Предложение ИИ <span class="ml-2 font-mono text-xs text-gray-400">#<?php echo (int) $analysis['id']; ?></span></h2>
                    <div class="flex items-center gap-2">
                        <span class="prl-badge prl-badge-<?php echo $analysis['status'] === 'proposed' ? 'run' : ($analysis['status'] === 'rejected' ? 'err' : 'ok'); ?>"><span class="prl-dot"></span><?php echo HTML::chars(['proposed' => 'ждёт проверки', 'accepted' => 'применено', 'edited' => 'применено с правками', 'rejected' => 'отклонено'][$analysis['status']] ?? $analysis['status']); ?></span>
                        <span class="prl-badge prl-badge-info">уверенность <?php echo (int) round(((float) $analysis['confidence']) * 100); ?>%</span>
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Модель: <code class="font-mono"><?php echo HTML::chars($analysis['model']); ?></code> · профиль <?php echo HTML::chars($analysis['profile']); ?> · токенов <?php echo (int) $analysis['tokens']; ?> · <?php echo (int) $analysis['duration_ms']; ?> мс</p>

                <?php if ( ! empty($payload['summary'])): ?>
                    <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-800/60 dark:text-gray-200"><?php echo HTML::chars($payload['summary']); ?></div>
                <?php endif; ?>

                <?php if ( ! empty($payload['risk']['reasons'])): ?>
                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                        <div class="font-semibold"><i class="fas fa-shield-halved mr-1"></i>Почему риск <?php echo HTML::chars(['high' => 'высокий', 'medium' => 'средний', 'low' => 'низкий'][$payload['risk']['level']] ?? $payload['risk']['level']); ?></div>
                        <ul class="mt-1 list-disc pl-5"><?php foreach ($payload['risk']['reasons'] as $reason): ?><li><?php echo HTML::chars($reason); ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <div class="mt-4 overflow-x-auto">
                    <table class="prl-table min-w-[720px]">
                        <thead><tr><th>Поле</th><th>Предложение</th><th>Цитата</th><th>Уверенность</th></tr></thead>
                        <tbody>
                        <?php foreach (($payload['fields'] ?? []) as $field => $item): if (($item['value'] ?? '') === '') continue; ?>
                            <tr>
                                <td class="font-mono text-xs"><?php echo HTML::chars($field); ?></td>
                                <td class="font-semibold"><?php echo HTML::chars($item['value']); ?></td>
                                <td class="max-w-[320px] text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($item['quote'] ?: '—'); ?></td>
                                <td><?php $pc = (int) round(((float) ($item['confidence'] ?? 0)) * 100); ?><span class="prl-badge prl-badge-<?php echo $pc >= 80 ? 'ok' : ($pc >= 50 ? 'wait' : 'err'); ?>"><?php echo $pc; ?>%</span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty(array_filter($payload['fields'] ?? [], function ($v) { return ($v['value'] ?? '') !== ''; }))): ?><tr><td colspan="4" class="text-center text-gray-500">Полей не найдено — проверьте текст вручную.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($analysis['status'] === 'proposed' AND $can_edit): ?>
                <form method="post" action="/analysis/accept/<?php echo (int) $analysis['id']; ?>" class="mt-4">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="prl-label" for="ai-type">Тип (можно исправить)</label>
                            <select id="ai-type" name="type" class="prl-select mt-1 w-full"><?php foreach ($types as $type): ?><option value="<?php echo HTML::chars($type['code']); ?>" <?php echo ($payload['type'] ?? '') === $type['code'] ? 'selected' : ''; ?>><?php echo HTML::chars($type['title']); ?></option><?php endforeach; ?></select>
                        </div>
                        <div>
                            <label class="prl-label" for="ai-out-no">Исходящий №</label>
                            <input id="ai-out-no" name="fields[outbound_no]" type="text" value="<?php echo HTML::chars($payload['fields']['outbound_no']['value'] ?? ''); ?>" class="prl-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prl-label" for="ai-out-date">Дата исходящего</label>
                            <input id="ai-out-date" name="fields[outbound_date]" type="date" value="<?php echo HTML::chars($payload['fields']['outbound_date']['value'] ?? ''); ?>" class="prl-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prl-label" for="ai-contract">Договор</label>
                            <input id="ai-contract" name="fields[contract_no]" type="text" value="<?php echo HTML::chars($payload['fields']['contract_no']['value'] ?? ''); ?>" class="prl-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prl-label" for="ai-hint-days">Срок из текста, дней</label>
                            <input id="ai-hint-days" name="fields[deadline_hint_days]" type="number" min="1" max="365" value="<?php echo HTML::chars($payload['fields']['deadline_hint_days']['value'] ?? ''); ?>" class="prl-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prl-label" for="ai-hint-unit">Тип дней</label>
                            <select id="ai-hint-unit" name="fields[deadline_hint_unit]" class="prl-select mt-1 w-full"><option value="calendar">календарные</option><option value="business" <?php echo ($payload['fields']['deadline_hint_unit']['value'] ?? '') === 'business' ? 'selected' : ''; ?>>рабочие</option></select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="prl-label" for="ai-summary">Краткое содержание</label>
                            <textarea id="ai-summary" name="summary" rows="3" class="prl-input mt-1 w-full"><?php echo HTML::chars($payload['summary'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <label class="mt-3 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" name="overwrite" value="1" class="h-4 w-4 rounded border-gray-300 accent-blue-600">Перезаписать уже заполненные поля</label>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button class="prl-btn-cta" type="submit"><i class="fas fa-check"></i>Применить в карточку</button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Применение фиксируется в провенансе как источник «ИИ».</span>
                    </div>
                </form>
                <form method="post" action="/analysis/reject/<?php echo (int) $analysis['id']; ?>" class="mt-2">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button class="prl-btn-secondary prl-btn-sm" type="submit"><i class="fas fa-xmark"></i>Отклонить предложение</button>
                </form>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="prl-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-paperclip mr-2 text-blue-500"></i>Вложения и текст</h2>
                <?php if ( ! empty($doc['message']['files'])): ?>
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                        <?php foreach ($doc['message']['files'] as $file): ?>
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <div class="min-w-0">
                                    <span class="block truncate text-sm font-semibold"><i class="fas fa-file-pdf mr-1 text-red-500"></i><?php echo HTML::chars($file['filename']); ?></span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($file['mime']); ?> · <?php echo number_format((int) $file['size_bytes'] / 1024, 0, ',', ' '); ?> КБ</span>
                                </div>
                                <a class="prl-btn-secondary prl-btn-sm" href="/documents/file/<?php echo (int) $file['file_id']; ?>"><i class="fas fa-download"></i>Скачать PDF</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="prl-empty mt-3"><div class="prl-empty-text">Вложений нет — документ создан вручную.</div></div>
                <?php endif; ?>

                <?php if ( ! empty($doc['message']['text'])): ?>
                    <details class="mt-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <summary class="cursor-pointer text-sm font-semibold text-gray-800 dark:text-gray-100">Распознанный текст · <?php echo (int) $doc['message']['text']['chars']; ?> символов · <?php echo HTML::chars($doc['message']['text']['engine']); ?></summary>
                        <pre class="mt-3 max-h-[360px] overflow-auto whitespace-pre-wrap break-words rounded bg-gray-50 p-3 text-xs leading-relaxed text-gray-700 dark:bg-gray-900 dark:text-gray-200"><?php echo HTML::chars($doc['message']['text']['text']); ?></pre>
                    </details>
                <?php endif; ?>
            </section>

            <section class="prl-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-clock-rotate-left mr-2 text-blue-500"></i>Провенанс полей</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="prl-table min-w-[720px]">
                        <thead><tr><th>Поле</th><th>Было</th><th>Стало</th><th>Источник</th><th>Когда</th></tr></thead>
                        <tbody>
                        <?php foreach ($doc['field_events'] as $ev): ?>
                            <tr>
                                <td class="font-mono text-xs"><?php echo HTML::chars($ev['field']); ?></td>
                                <td class="max-w-[220px] truncate text-xs text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars($ev['old_value']); ?>"><?php echo HTML::chars($ev['old_value'] ?: '—'); ?></td>
                                <td class="max-w-[260px] truncate text-sm font-semibold" title="<?php echo HTML::chars($ev['new_value']); ?>"><?php echo HTML::chars($ev['new_value'] ?: '—'); ?></td>
                                <td><span class="prl-badge prl-badge-<?php echo $ev['source'] === 'llm' ? 'info' : 'wait'; ?>"><?php echo HTML::chars($ev['source'] === 'llm' ? 'ИИ #'.$ev['suggestion_id'] : 'вручную'); ?></span></td>
                                <td class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($ev['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($doc['field_events'])): ?><tr><td colspan="5" class="text-center text-gray-500">Полей ещё не меняли.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="space-y-4">
            <section class="prl-card">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-wand-magic-sparkles mr-2 text-blue-500"></i>ИИ-разбор</h2>
                    <span class="prl-badge prl-badge-<?php echo $analysis ? ($analysis['status'] === 'proposed' ? 'run' : 'ok') : 'wait'; ?>"><span class="prl-dot"></span><?php echo $analysis ? HTML::chars(['proposed' => 'ждёт проверки', 'accepted' => 'применено', 'edited' => 'применено', 'rejected' => 'отклонено'][$analysis['status']] ?? $analysis['status']) : 'не запускался'; ?></span>
                </div>
                <?php if ($analysis): ?>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Тип</dt><dd class="text-right font-semibold"><?php echo HTML::chars($analysis['payload']['type'] ?? '—'); ?></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Уверенность</dt><dd class="font-semibold"><?php echo (int) round(((float) $analysis['confidence']) * 100); ?>%</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Модель</dt><dd class="max-w-[190px] truncate text-right font-mono text-xs" title="<?php echo HTML::chars($analysis['model']); ?>"><?php echo HTML::chars($analysis['model']); ?></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Токенов</dt><dd class="font-semibold"><?php echo (int) $analysis['tokens']; ?></dd></div>
                    </dl>
                <?php endif; ?>
                <?php if ($can_run_ai): ?>
                    <form method="post" action="/analysis/run/<?php echo (int) $doc['id']; ?>" class="mt-3">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <button class="prl-btn-process w-full justify-center" type="submit"><i class="fas fa-play"></i><?php echo $analysis ? 'Разобрать заново' : 'Запустить разбор'; ?></button>
                    </form>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Задача уходит в воркер <code class="font-mono">doc.analyze</code>; страница не ждёт модель.</p>
                <?php endif; ?>
                <a class="mt-2 block text-center text-xs text-blue-600 hover:underline dark:text-blue-400" href="/analysis">Все предложения</a>
            </section>

            <section class="prl-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-clock mr-2 text-amber-500"></i>Срок и маршрут</h2>
                <?php if ($deadline): ?>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Срок</dt><dd class="font-semibold <?php echo $deadline['state'] === 'overdue' ? 'text-red-600 dark:text-red-400' : ''; ?>"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($deadline['due_at']))); ?></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Осталось</dt><dd class="font-semibold"><?php $hl = (float) $deadline['hours_left']; echo $hl < 0 ? 'просрочен на '.round(abs($hl)).' ч' : round($hl).' ч'; ?></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Отдел</dt><dd class="text-right font-semibold"><?php echo HTML::chars($deadline['department']); ?></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Роль</dt><dd class="font-mono text-xs"><?php echo HTML::chars($deadline['role_code'] ?: '—'); ?></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Источник</dt><dd class="font-semibold"><?php echo HTML::chars(['rule' => 'правило', 'hint' => 'текст письма', 'default' => 'по умолчанию'][$deadline['source']] ?? $deadline['source']); ?></dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($deadline['note']); ?></p>
                <?php else: ?>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Срок ещё не назначен.</p>
                <?php endif; ?>
                <?php if ($can_route): ?>
                    <form method="post" action="/sla/route/<?php echo (int) $doc['id']; ?>" class="mt-3">
                        <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                        <button class="prl-btn-secondary w-full justify-center" type="submit"><i class="fas fa-route"></i>Пересчитать маршрут</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="prl-card">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-reply mr-2 text-blue-500"></i>Черновики</h2>
                    <span class="prl-count"><?php echo count($drafts); ?></span>
                </div>
                <?php if ($drafts): ?>
                    <ul class="mt-3 space-y-2">
                        <?php foreach ($drafts as $draft): ?>
                            <li class="rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-semibold"><?php echo HTML::chars($draft['template_title'] ?: 'без шаблона'); ?></span>
                                    <span class="prl-badge prl-badge-<?php echo ['sent' => 'ok', 'approved' => 'info', 'draft' => 'run', 'failed' => 'err'][$draft['status']] ?? 'wait'; ?>"><?php echo HTML::chars($draft['status']); ?></span>
                                </div>
                                <span class="mt-1 block truncate text-[11px] text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr($draft['body'], 0, 120)); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Ответ ещё не готовили.</p>
                <?php endif; ?>
                <a class="prl-btn-secondary mt-3 w-full justify-center" href="/replies/document/<?php echo (int) $doc['id']; ?>"><i class="fas fa-pen"></i>Открыть ответы</a>
            </section>

            <section class="prl-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-list-check mr-2 text-blue-500"></i>Журнал</h2>
                <ul class="mt-3 space-y-3">
                    <?php foreach (array_slice($doc['history'], 0, 10) as $h): ?>
                        <li class="border-l-2 border-gray-200 pl-3 dark:border-gray-700">
                            <span class="block text-xs font-semibold text-gray-800 dark:text-gray-100"><?php echo HTML::chars($h['note'] ?: $h['event']); ?></span>
                            <span class="block text-[11px] text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($h['created_at']))); ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($doc['history'])): ?><li class="text-sm text-gray-500">Событий нет.</li><?php endif; ?>
                </ul>
            </section>
        </aside>
    </div>
</div>
