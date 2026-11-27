<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx400_specflow/aliases — память сопоставлений: решения операторов
 * «наименование + обозначение (нормализованные) → код НСИ». Следующие
 * разборы применяют их раньше fuzzy-матчинга (via = memory). Ошибочное
 * решение забывается здесь (отзыв, история сохраняется).
 *
 * Переменные: $aliases, $total, $query, $page_no, $pages, $actor_names,
 * $can_edit, $flash, $csrf.
 */
$source_ru = ['operator' => 'оператор', 'api' => 'API', 'import' => 'импорт'];
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Оцифровка спецификаций', '/prx400_specflow'], ['Память сопоставлений']],
        'title' => 'Память сопоставлений',
        'pill' => ['prx400 · решения операторов', 'fa-brain'],
        'lead' => 'Когда оператор назначает строке код НСИ, решение запоминается: та же позиция в следующих спецификациях получит этот код сразу, без fuzzy-поиска.',
    ]); ?>

    <?php echo View::factory('partials/flash', ['flash' => $flash]); ?>

    <?php echo View::factory('partials/filters', [
        'id' => 'prx400-aliases',
        'action' => '/prx400_specflow/aliases',
        'fields' => [['type' => 'search', 'name' => 'q', 'label' => 'Поиск', 'placeholder' => 'наименование, обозначение или код НСИ', 'value' => $query]],
        'reset' => $query !== '' ? '/prx400_specflow/aliases' : '',
    ]); ?>

    <section class="prx-card mt-5 overflow-x-auto p-0">
        <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-700/60">
            <h2 class="prx-h3">Сопоставления <span class="prx-count ml-1"><?php echo (int) $total; ?></span></h2>
        </div>
<?php if (empty($aliases)): ?>
        <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400"><?php echo $query !== '' ? 'Ничего не нашлось.' : 'Пока пусто — память наполняется, когда оператор назначает код НСИ строке спецификации.'; ?></p>
<?php else: ?>
        <table class="prx-table">
            <thead><tr><th>Наименование (норм.)</th><th>Обозначение</th><th>Код НСИ</th><th>Источник</th><th>Применено</th><th>Обновлено</th><th></th></tr></thead>
            <tbody>
<?php foreach ($aliases as $a): $actor = (int) ($a['actor_id'] ?? 0); ?>
            <tr>
                <td class="max-w-[360px] text-sm text-gray-900 dark:text-white"><?php echo HTML::chars((string) $a['name_norm']); ?></td>
                <td class="font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $a['code_doc_norm']); ?></td>
                <td><span class="prx-badge prx-badge-ok"><span class="prx-dot"></span><?php echo HTML::chars((string) $a['catalog_code']); ?></span></td>
                <td class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars($source_ru[(string) $a['source']] ?? (string) $a['source']); ?><?php echo $actor !== 0 ? ' · '.HTML::chars((string) ($actor_names[$actor] ?? '#'.$actor)) : ''; ?></td>
                <td class="tabular-nums"><?php echo (int) $a['hits']; ?></td>
                <td class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400"><?php echo date('d.m.Y H:i', strtotime((string) $a['updated_at'])); ?></td>
                <td class="text-right">
<?php if ($can_edit): ?>
                    <form method="post" action="/prx400_specflow/alias_revoke/<?php echo (int) $a['id']; ?>" class="inline"><input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>"><button type="submit" class="prx-btn-secondary prx-btn-sm" data-prx-confirm="Следующие разборы перестанут подставлять этот код автоматически." data-prx-title="Забыть сопоставление?" data-prx-ok="Забыть"><i class="fas fa-eraser"></i>Забыть</button></form>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
<?php if ($pages > 1): ?>
        <?php echo View::factory('partials/pagination', [
            'total' => $total, 'page' => $page_no, 'pages' => $pages, 'per' => 100, 'per_options' => [100],
            'url_base' => '/prx400_specflow/aliases', 'params' => $query !== '' ? ['q' => $query] : [],
        ]); ?>
<?php endif; ?>
<?php endif; ?>
    </section>
</div>
