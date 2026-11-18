<?php defined('SYSPATH') or die('No direct script access.'); ?>

            <section class="prx-card">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-file-pen mr-2 text-blue-500"></i>Карточка документа</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Изменения полей пишутся в провенанс</span>
                </div>

                <?php if ($can_edit): ?>
                <form method="post" action="/documents/save/<?php echo (int) $doc['id']; ?>" class="mt-4">
                    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label class="prx-label" for="doc-subject">Тема</label>
                            <input id="doc-subject" name="subject" type="text" maxlength="500" value="<?php echo HTML::chars($doc['subject']); ?>" class="prx-input mt-1 w-full" required>
                        </div>
                        <div>
                            <label class="prx-label" for="doc-type">Тип</label>
                            <select id="doc-type" name="type_id" class="prx-select mt-1 w-full">
                                <option value="0">Не определён</option>
                                <?php foreach ($types as $type): ?><option value="<?php echo (int) $type['id']; ?>" <?php echo (int) $doc['type_id'] === (int) $type['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($type['title']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="prx-label" for="doc-corr">Корреспондент</label>
                            <select id="doc-corr" name="correspondent_id" class="prx-select mt-1 w-full">
                                <option value="0">Не определён</option>
                                <?php foreach ($correspondents as $corr): ?><option value="<?php echo (int) $corr['id']; ?>" <?php echo (int) $doc['correspondent_id'] === (int) $corr['id'] ? 'selected' : ''; ?>><?php echo HTML::chars($corr['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="prx-label" for="doc-sender">Отправитель (строка)</label>
                            <input id="doc-sender" name="sender_name" type="text" value="<?php echo HTML::chars($doc['sender_name']); ?>" class="prx-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prx-label" for="doc-inn">ИНН</label>
                            <input id="doc-inn" name="sender_inn" type="text" inputmode="numeric" maxlength="12" value="<?php echo HTML::chars($doc['sender_inn']); ?>" class="prx-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prx-label" for="doc-out-no">Исходящий №</label>
                            <input id="doc-out-no" name="outbound_no" type="text" value="<?php echo HTML::chars($doc['outbound_no']); ?>" class="prx-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prx-label" for="doc-out-date">Дата исходящего</label>
                            <input id="doc-out-date" name="outbound_date" type="date" value="<?php echo HTML::chars($doc['outbound_date']); ?>" class="prx-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prx-label" for="doc-contract">Договор</label>
                            <input id="doc-contract" name="contract_no" type="text" value="<?php echo HTML::chars($doc['contract_no']); ?>" class="prx-input mt-1 w-full">
                        </div>
                        <div>
                            <label class="prx-label" for="doc-amount">Сумма, ₽</label>
                            <input id="doc-amount" name="amount" type="text" inputmode="decimal" value="<?php echo $doc['amount'] !== NULL ? HTML::chars($doc['amount']) : ''; ?>" class="prx-input mt-1 w-full">
                        </div>
                        <div class="md:col-span-2">
                            <label class="prx-label" for="doc-summary">Краткое содержание</label>
                            <textarea id="doc-summary" name="summary" rows="4" class="prx-input mt-1 w-full"><?php echo HTML::chars($doc['summary']); ?></textarea>
                        </div>
                        <div>
                            <label class="prx-label" for="doc-priority">Приоритет</label>
                            <select id="doc-priority" name="priority" class="prx-select mt-1 w-full"><?php foreach ($priorities as $code => $label): ?><option value="<?php echo $code; ?>" <?php echo $doc['priority'] === $code ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option><?php endforeach; ?></select>
                        </div>
                        <div>
                            <label class="prx-label" for="doc-sens">Чувствительность</label>
                            <select id="doc-sens" name="sensitivity" class="prx-select mt-1 w-full"><?php foreach ($sensitivities as $code => $label): ?><option value="<?php echo $code; ?>" <?php echo $doc['sensitivity'] === $code ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option><?php endforeach; ?></select>
                        </div>
                        <div>
                            <label class="prx-label" for="doc-status">Статус</label>
                            <select id="doc-status" name="status" class="prx-select mt-1 w-full"><?php foreach ($statuses as $code => $label): ?><option value="<?php echo $code; ?>" <?php echo $doc['status'] === $code ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option><?php endforeach; ?></select>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <button class="prx-btn-cta" type="submit"><i class="fas fa-floppy-disk"></i>Сохранить карточку</button>
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
