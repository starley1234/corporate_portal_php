<?php defined('SYSPATH') or die('No direct script access.'); ?>

            <section class="prx-card">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-reply mr-2 text-blue-500"></i>Черновики</h2>
                    <span class="prx-count"><?php echo count($drafts); ?></span>
                </div>
                <?php if ($drafts): ?>
                    <ul class="mt-3 space-y-2">
                        <?php foreach ($drafts as $draft): ?>
                            <li class="rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-semibold"><?php echo HTML::chars($draft['template_title'] ?: 'без шаблона'); ?></span>
                                    <span class="prx-badge prx-badge-<?php echo ['sent' => 'ok', 'approved' => 'info', 'draft' => 'run', 'failed' => 'err'][$draft['status']] ?? 'wait'; ?>"><?php echo HTML::chars($draft['status']); ?></span>
                                </div>
                                <span class="mt-1 block truncate text-[11px] text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr($draft['body'], 0, 120)); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Ответ ещё не готовили.</p>
                <?php endif; ?>
                <a class="prx-btn-secondary mt-3 w-full justify-center" href="/replies/document/<?php echo (int) $doc['id']; ?>"><i class="fas fa-pen"></i>Открыть ответы</a>
            </section>
