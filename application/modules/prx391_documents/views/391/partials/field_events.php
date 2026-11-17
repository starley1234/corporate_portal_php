<?php defined('SYSPATH') or die('No direct script access.'); ?>

            <section class="prx-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-clock-rotate-left mr-2 text-blue-500"></i>Провенанс полей</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="prx-table min-w-[720px]">
                        <thead><tr><th>Поле</th><th>Было</th><th>Стало</th><th>Источник</th><th>Когда</th></tr></thead>
                        <tbody>
                        <?php foreach ($doc['field_events'] as $ev): ?>
                            <tr>
                                <td class="font-mono text-xs"><?php echo HTML::chars($ev['field']); ?></td>
                                <td class="max-w-[220px] truncate text-xs text-gray-500 dark:text-gray-400" title="<?php echo HTML::chars($ev['old_value']); ?>"><?php echo HTML::chars($ev['old_value'] ?: '—'); ?></td>
                                <td class="max-w-[260px] truncate text-sm font-semibold" title="<?php echo HTML::chars($ev['new_value']); ?>"><?php echo HTML::chars($ev['new_value'] ?: '—'); ?></td>
                                <td><span class="prx-badge prx-badge-<?php echo $ev['source'] === 'llm' ? 'info' : 'wait'; ?>"><?php echo HTML::chars($ev['source'] === 'llm' ? 'ИИ #'.$ev['suggestion_id'] : 'вручную'); ?></span></td>
                                <td class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars(date('d.m.Y H:i', strtotime($ev['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($doc['field_events'])): ?><tr><td colspan="5" class="text-center text-gray-500">Полей ещё не меняли.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
