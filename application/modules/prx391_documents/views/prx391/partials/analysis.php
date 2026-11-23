<?php defined('SYSPATH') or die('No direct script access.'); ?>

            <?php if ($analysis): ?>
            <?php $payload = $analysis['payload']; ?>
            <section class="prx-card border-l-4 border-l-blue-500">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-wand-magic-sparkles mr-2 text-blue-500"></i>Предложение ИИ <span class="ml-2 font-mono text-xs text-gray-400">#<?php echo (int) $analysis['id']; ?></span></h2>
                    <div class="flex items-center gap-2">
                        <span class="prx-badge prx-badge-<?php echo $analysis['status'] === 'proposed' ? 'run' : ($analysis['status'] === 'rejected' ? 'err' : 'ok'); ?>"><span class="prx-dot"></span><?php echo HTML::chars(['proposed' => 'ждёт проверки', 'accepted' => 'применено', 'edited' => 'применено с правками', 'rejected' => 'отклонено'][$analysis['status']] ?? $analysis['status']); ?></span>
                        <span class="prx-badge prx-badge-info">уверенность <?php echo (int) round(((float) $analysis['confidence']) * 100); ?>%</span>
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
                    <table class="prx-table min-w-[720px]">
                        <thead><tr><th>Поле</th><th>Предложение</th><th>Цитата</th><th>Уверенность</th></tr></thead>
                        <tbody>
                        <?php foreach (($payload['fields'] ?? []) as $field => $item): if (($item['value'] ?? '') === '') continue; ?>
                            <tr>
                                <td class="font-mono text-xs"><?php echo HTML::chars($field); ?></td>
                                <td class="font-semibold"><?php echo HTML::chars($item['value']); ?></td>
                                <td class="max-w-[320px] text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($item['quote'] ?: '—'); ?></td>
                                <td><?php $pc = (int) round(((float) ($item['confidence'] ?? 0)) * 100); ?><span class="prx-badge prx-badge-<?php echo $pc >= 80 ? 'ok' : ($pc >= 50 ? 'wait' : 'err'); ?>"><?php echo $pc; ?>%</span></td>
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
                            <label class="prx-label" for="ai-type">Тип (можно исправить)</label>
                            <select id="ai-type" name="type" class="prx-select mt-1 w-full"><?php foreach ($types as $type): ?><option value="<?php echo HTML::chars($type['code']); ?>" <?php echo ($payload['type'] ?? '') === $type['code'] ? 'selected' : ''; ?>><?php echo HTML::chars($type['title']); ?></option><?php endforeach; ?></select>
                        </div>
                        <div>
                            <label class="prx-label" for="ai-out-no">Исходящий №</label>
                            <input id="ai-out-no" name="fields[outbound_no]" type="text" value="<?php echo HTML::chars($payload['fields']['outbound_no']['value'] ?? ''); ?>" class="prx-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prx-label" for="ai-out-date">Дата исходящего</label>
                            <input id="ai-out-date" name="fields[outbound_date]" type="date" value="<?php echo HTML::chars($payload['fields']['outbound_date']['value'] ?? ''); ?>" class="prx-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prx-label" for="ai-contract">Договор</label>
                            <input id="ai-contract" name="fields[contract_no]" type="text" value="<?php echo HTML::chars($payload['fields']['contract_no']['value'] ?? ''); ?>" class="prx-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prx-label" for="ai-hint-days">Срок из текста, дней</label>
                            <input id="ai-hint-days" name="fields[deadline_hint_days]" type="number" min="1" max="365" value="<?php echo HTML::chars($payload['fields']['deadline_hint_days']['value'] ?? ''); ?>" class="prx-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prx-label" for="ai-hint-unit">Тип дней</label>
                            <select id="ai-hint-unit" name="fields[deadline_hint_unit]" class="prx-select mt-1 w-full"><option value="calendar">календарные</option><option value="business" <?php echo ($payload['fields']['deadline_hint_unit']['value'] ?? '') === 'business' ? 'selected' : ''; ?>>рабочие</option></select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="prx-label" for="ai-summary">Краткое содержание</label>
                            <textarea id="ai-summary" name="summary" rows="3" class="prx-input mt-1 w-full"><?php echo HTML::chars($payload['summary'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <label class="mt-3 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" name="overwrite" value="1" class="h-4 w-4 rounded border-gray-300 accent-blue-600">Перезаписать уже заполненные поля</label>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button class="prx-btn-cta" type="submit"><i class="fas fa-check"></i>Применить в карточку</button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Применение фиксируется в провенансе как источник «ИИ».</span>
                    </div>
                </form>
                <form method="post" action="/analysis/reject/<?php echo (int) $analysis['id']; ?>" class="mt-2">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <button class="prx-btn-secondary prx-btn-sm" type="submit"><i class="fas fa-xmark"></i>Отклонить предложение</button>
                </form>
                <?php endif; ?>
            </section>
            <?php endif; ?>
