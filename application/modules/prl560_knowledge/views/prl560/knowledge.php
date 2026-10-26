<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="space-y-4">
    <h1 class="prl-h1">Техническая база знаний · RAG</h1>
    <p class="text-sm text-gray-500">Кэш наработок платформы. Не является хранилищем кадровых, секретных или персональных документов.</p>
    <form method="get" class="prl-panel flex flex-wrap gap-3 p-4">
        <input class="prl-input" name="q" value="<?= HTML::chars($q) ?>" placeholder="Поиск по базе знаний">
        <label><input type="checkbox" name="semantic" value="1" <?= $semantic ? 'checked' : '' ?>> По смыслу</label>
        <button class="prl-btn-process">Найти</button>
    </form>
    <pre class="prl-panel overflow-auto p-4 text-sm"><?= HTML::chars(json_encode($result ?? $recent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</div>
