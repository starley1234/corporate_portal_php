<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php $search_id = HTML::chars($id); ?>
<div class="mt-6 max-w-2xl" data-service-search hidden>
    <label class="sr-only" for="<?php echo $search_id; ?>"><?php echo HTML::chars($label); ?></label>
    <div class="relative">
        <i class="fas fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400" aria-hidden="true"></i>
        <input id="<?php echo $search_id; ?>" type="search" autocomplete="off" spellcheck="false"
               aria-controls="<?php echo HTML::chars($controls); ?>" aria-describedby="<?php echo $search_id; ?>-hint <?php echo $search_id; ?>-empty"
               placeholder="Найти сервис по названию или ключевому слову…" class="prx-input pl-10">
    </div>
    <p id="<?php echo $search_id; ?>-hint" class="prx-hint mt-2">Поиск по списку сервисов. Поиск по содержимому портала — в шапке.</p>
    <p id="<?php echo $search_id; ?>-empty" class="prx-hint mt-2" role="status" hidden><?php echo HTML::chars($empty_message); ?></p>
</div>
