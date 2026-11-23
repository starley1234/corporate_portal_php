<?php defined('SYSPATH') or die('No direct script access.'); ?>

            <section class="prx-card">
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
