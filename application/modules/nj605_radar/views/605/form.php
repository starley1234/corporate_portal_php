<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj605_radar: форма создания наблюдения (инклюдится в index).
 * Лаконично: что искать — главное поле, остальное необязательное.
 */
?>
<form method="post" action="/radar" class="mt-3 space-y-3">
    <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
    <div>
        <label class="n2j-label">Что ищем <span class="text-rose-500">*</span></label>
        <textarea name="query" required rows="2" maxlength="300" placeholder="Например: авиационные агрегаты, сварочные работы, изготовление ёмкостей"
                  class="n2j-textarea mt-1 w-full"><?php echo HTML::chars((string) Arr::get($_GET, 'query', '')); ?></textarea>
        <p class="n2j-hint">Конкретнее — точнее находки: продукт + ключевые слова.</p>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label class="n2j-label">Название <span class="font-normal text-gray-400">(для себя)</span></label>
            <input type="text" name="title" maxlength="120" placeholder="Авиационные агрегаты"
                   autocomplete="off" autocapitalize="off" spellcheck="false"
                   value="<?php echo HTML::chars((string) Arr::get($_GET, 'title', '')); ?>"
                   class="n2j-input mt-1 w-full">
        </div>
        <div>
            <label class="n2j-label">Регион <span class="font-normal text-gray-400">(необязательно)</span></label>
            <input type="text" name="region" maxlength="120" placeholder="Москва и область"
                   autocomplete="off" autocapitalize="off" spellcheck="false"
                   value="<?php echo HTML::chars((string) Arr::get($_GET, 'region', '')); ?>"
                   class="n2j-input mt-1 w-full">
        </div>
    </div>
    <button type="submit" class="n2j-btn-cta w-full">
        <i class="fas fa-satellite-dish mr-1"></i>Создать радар
    </button>
</form>
