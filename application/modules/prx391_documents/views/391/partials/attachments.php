<?php defined('SYSPATH') or die('No direct script access.'); ?>

            <section class="prx-card">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white"><i class="fas fa-paperclip mr-2 text-blue-500"></i>Вложения и текст</h2>
                <?php if ( ! empty($doc['message']['files'])): ?>
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                        <?php foreach ($doc['message']['files'] as $file): ?>
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <div class="min-w-0">
                                    <span class="block truncate text-sm font-semibold"><i class="fas fa-file-pdf mr-1 text-red-500"></i><?php echo HTML::chars($file['filename']); ?></span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($file['mime']); ?> · <?php echo number_format((int) $file['size_bytes'] / 1024, 0, ',', ' '); ?> КБ</span>
                                </div>
                                <a class="prx-btn-secondary prx-btn-sm" href="/prx391_documents/file/<?php echo (int) $file['file_id']; ?>"><i class="fas fa-download"></i>Скачать PDF</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="prx-empty mt-3"><div class="prx-empty-text">Вложений нет — документ создан вручную.</div></div>
                <?php endif; ?>

                <?php if ( ! empty($doc['message']['text'])): ?>
                    <details class="mt-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <summary class="cursor-pointer text-sm font-semibold text-gray-800 dark:text-gray-100">Распознанный текст · <?php echo (int) $doc['message']['text']['chars']; ?> символов · <?php echo HTML::chars($doc['message']['text']['engine']); ?></summary>
                        <pre class="mt-3 max-h-[360px] overflow-auto whitespace-pre-wrap break-words rounded bg-gray-50 p-3 text-xs leading-relaxed text-gray-700 dark:bg-gray-900 dark:text-gray-200"><?php echo HTML::chars($doc['message']['text']['text']); ?></pre>
                    </details>
                <?php endif; ?>
            </section>
