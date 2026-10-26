<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
$json = (string) ($json ?? '');
$result = is_array($result ?? NULL) ? $result : NULL;
$labels = ['critical' => 'Критические', 'high' => 'Высокие', 'medium' => 'Средние', 'low' => 'Низкие'];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Сервисы', '/services'], ['RAG Guard']],
        'title'  => 'RAG Guard',
        'lead'   => 'Осмотрите извлечённые документы перед передачей модели: проверьте tenant-границу, инструкции внутри источников и потенциально чувствительные данные.',
    ]); ?>
    <section class="mt-6 n2j-card" aria-labelledby="rag-input-title">
        <h2 id="rag-input-title" class="n2j-h2">Набор извлечённого контекста</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">JSON: <code>expected_tenant</code> и <code>documents</code> с полями <code>id</code>, <code>tenant</code>, <code>text</code>, <code>trusted</code>. Максимум 128 КБ, 100 документов.</p>
        <form method="post" action="/ragguard" class="mt-4 space-y-3">
            <input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
            <label for="rag-context-json" class="n2j-label">JSON извлечённых документов</label>
            <textarea id="rag-context-json" name="context_json" rows="13" maxlength="131072" spellcheck="false" class="n2j-textarea font-mono text-xs" placeholder='{"expected_tenant":"tenant-a","documents":[{"id":"doc-1","tenant":"tenant-a","trusted":false,"text":"Текст извлечённого документа"}]}' required><?php echo HTML::chars($json); ?></textarea>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="n2j-btn-process">Проверить контекст <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i></button>
                <p class="n2j-hint">Не вставляйте реальные ключи или персональные данные. Текст и URL источников не выводятся в отчёте.</p>
            </div>
        </form>
    </section>

    <?php if ($result): ?>
        <?php if ($result['error'] !== ''): ?>
        <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200" role="alert"><?php echo HTML::chars($result['error']); ?></div>
        <?php else: ?>
        <section class="mt-6" aria-labelledby="rag-result-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><h2 id="rag-result-title" class="n2j-h2">Результат проверки контекста</h2><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Документов: <?php echo (int) $result['document_count']; ?><?php if ($result['expected_tenant'] !== ''): ?> · ожидаемый tenant: <code><?php echo HTML::chars($result['expected_tenant']); ?></code><?php endif; ?></p></div>
                <span class="n2j-badge <?php echo $result['risk'] === 'critical' ? 'n2j-badge-err' : ($result['risk'] === 'high' ? 'n2j-badge-err' : ($result['risk'] === 'review' ? 'n2j-badge-run' : 'n2j-badge-ok')); ?>"><?php echo $result['risk'] === 'critical' ? 'Нарушена граница доступа' : ($result['risk'] === 'high' ? 'Найдены серьёзные сигналы' : ($result['risk'] === 'review' ? 'Нужна проверка' : 'Сильных сигналов не найдено')); ?></span>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <?php foreach ($labels as $key => $label): ?>
                <div class="n2j-card"><p class="text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars($label); ?></p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) $result['counts'][$key]; ?></p></div>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 space-y-3">
                <?php foreach ($result['documents'] as $doc): ?>
                <article class="n2j-card">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="n2j-h3 break-all"><?php echo HTML::chars($doc['id']); ?></h3><span class="n2j-badge <?php echo $doc['trusted'] ? 'n2j-badge-info' : 'n2j-badge-wait'; ?>"><?php echo $doc['trusted'] ? 'Помечен доверенным' : 'Источник не доверен'; ?></span></div>
                    <?php if ($doc['findings']): ?>
                    <ul class="mt-3 space-y-2">
                        <?php foreach ($doc['findings'] as $finding): ?>
                        <li class="flex items-start gap-2 text-sm leading-relaxed text-gray-700 dark:text-gray-200"><span class="n2j-badge shrink-0 <?php echo in_array($finding['severity'], ['critical', 'high'], TRUE) ? 'n2j-badge-err' : ($finding['severity'] === 'medium' ? 'n2j-badge-run' : 'n2j-badge-info'); ?>"><?php echo HTML::chars($labels[$finding['severity']] ?? 'Замечание'); ?></span><span><?php echo HTML::chars($finding['message']); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?><p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Локальные правила не нашли известных сигналов. Это не подтверждает корректность ACL или поведения модели.</p><?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
            <p class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-relaxed text-blue-900 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-200"><?php echo HTML::chars($result['scope']); ?></p>
        </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
