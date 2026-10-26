<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
$json = (string) ($json ?? '');
$result = is_array($result ?? NULL) ? $result : NULL;
$labels = ['high' => 'Высокие', 'medium' => 'Средние', 'low' => 'Низкие'];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Сервисы', '/services'], ['MCP Guard']],
        'title'  => 'MCP Guard',
        'lead'   => 'Проверьте JSON-описания инструментов MCP до подключения к агенту. Разбор локальный: никаких запросов к MCP-серверу.',
    ]); ?>
    <section class="mt-6 n2j-card" aria-labelledby="mcp-input-title">
        <h2 id="mcp-input-title" class="n2j-h2">Описание tools/list</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Вставьте массив <code>tools</code> или ответ MCP <code>result.tools</code>. Максимум 64 КБ и 100 инструментов.</p>
        <form method="post" action="/mcpguard" class="mt-4 space-y-3">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <label for="mcp-tools-json" class="n2j-label">JSON описаний инструментов</label>
            <textarea id="mcp-tools-json" name="tools_json" rows="12" maxlength="65536" spellcheck="false" class="n2j-textarea font-mono text-xs" placeholder='{"tools":[{"name":"lookup_customer","description":"Read-only lookup","inputSchema":{"type":"object","properties":{"id":{"type":"string","description":"Customer ID"}},"required":["id"],"additionalProperties":false},"annotations":{"readOnlyHint":true}}]}' required><?php echo HTML::chars($json); ?></textarea>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="n2j-btn-process">Проверить описания <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i></button>
                <p class="n2j-hint">Данные не сохраняются. Не вставляйте секреты и персональные данные.</p>
            </div>
        </form>
    </section>

    <?php if ($result): ?>
        <?php if ($result['error'] !== ''): ?>
        <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200" role="alert"><?php echo HTML::chars($result['error']); ?></div>
        <?php else: ?>
        <section class="mt-6" aria-labelledby="mcp-result-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><h2 id="mcp-result-title" class="n2j-h2">Результат статической проверки</h2><p class="mt-1 text-sm text-gray-600 dark:text-gray-300"><?php echo (int) $result['tool_count']; ?> инструментов · выводы требуют ручной проверки</p></div>
                <span class="n2j-badge <?php echo $result['risk'] === 'high' ? 'n2j-badge-err' : ($result['risk'] === 'review' ? 'n2j-badge-run' : 'n2j-badge-ok'); ?>"><?php echo $result['risk'] === 'high' ? 'Есть высокоприоритетные замечания' : ($result['risk'] === 'review' ? 'Нужна проверка' : 'Критичных сигналов не найдено'); ?></span>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <?php foreach ($labels as $key => $label): ?>
                <div class="n2j-card"><p class="text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars($label); ?></p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) $result['counts'][$key]; ?></p></div>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 space-y-3">
                <?php foreach ($result['tools'] as $tool): ?>
                <article class="n2j-card">
                    <h3 class="n2j-h3 break-all"><?php echo HTML::chars($tool['name']); ?></h3>
                    <?php if ($tool['findings']): ?>
                    <ul class="mt-3 space-y-2">
                        <?php foreach ($tool['findings'] as $finding): ?>
                        <li class="flex items-start gap-2 text-sm leading-relaxed text-gray-700 dark:text-gray-200">
                            <span class="n2j-badge shrink-0 <?php echo $finding['severity'] === 'high' ? 'n2j-badge-err' : ($finding['severity'] === 'medium' ? 'n2j-badge-run' : 'n2j-badge-info'); ?>"><?php echo HTML::chars($labels[$finding['severity']] ?? 'Замечание'); ?></span>
                            <span><?php echo HTML::chars($finding['message']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="mt-2 text-sm text-emerald-700 dark:text-emerald-300">Эвристики не нашли замечаний в описании. Это не подтверждает безопасность реализации инструмента.</p>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
            <p class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-relaxed text-blue-900 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-200"><?php echo HTML::chars($result['scope']); ?></p>
        </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
