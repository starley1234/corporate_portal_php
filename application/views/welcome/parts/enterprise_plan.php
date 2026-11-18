<?php defined('SYSPATH') or die('No direct script access.'); ?>

<ol class="mt-7 space-y-6" aria-label="Этапы производственного цикла">
    <?php foreach ($phases as $i => $phase): ?>
    <li>
        <div class="mb-3 flex items-center gap-3">
            <span class="prx-badge prx-badge-info tabular-nums" aria-hidden="true"><?php echo sprintf('%02d', $i + 1); ?></span>
            <h2 class="prx-h3"><?php echo HTML::chars($phase['title']); ?></h2>
        </div>
        <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($phase['pipelines'] as $pipeline): ?>
            <li class="prx-card flex flex-col gap-3">
                <h3 class="prx-h3"><?php echo HTML::chars($pipeline['title']); ?></h3>
                <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($pipeline['flow']); ?></p>
                <p class="mt-auto border-t border-gray-100 pt-3 text-sm leading-relaxed dark:border-gray-700/60">
                    <span class="font-semibold">На выходе:</span> <?php echo HTML::chars($pipeline['result']); ?>
                </p>
            </li>
            <?php endforeach; ?>
        </ul>
    </li>
    <?php endforeach; ?>
</ol>

<p class="mt-4 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
    <strong>Связь этапов:</strong> номер проекта → ревизия изделия → партия или серийный номер → обращение заказчика.
</p>
