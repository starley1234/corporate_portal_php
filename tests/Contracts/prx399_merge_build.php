<?php
/** Золотая сборка prx399: крайние случаи детерминированного Merge — БЕЗ LLM и БД. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');

class Prx399_Error extends Exception {}

require dirname(__DIR__, 2).'/application/modules/prx399_docmerge/classes/Service/Prx399/Merge.php';

function base_nodes(): array
{
    return [
        ['path' => '0', 'title' => 'Преамбула', 'text' => 'Стороны заключили договор.', 'status' => 'base', 'source' => 'base', 'history' => []],
        ['path' => '1', 'title' => 'Пункт 1.', 'text' => 'Предмет — поставка товара.', 'status' => 'base', 'source' => 'base', 'history' => []],
        ['path' => '1.1', 'title' => 'Пункт 1.1.', 'text' => 'Неустойка — по пункта 3.1.', 'status' => 'base', 'source' => 'base', 'history' => []],
        ['path' => '2', 'title' => 'Пункт 2.', 'text' => 'Порядок расчётов.', 'status' => 'base', 'source' => 'base', 'history' => []],
        ['path' => '2.1', 'title' => 'Пункт 2.1.', 'text' => 'Цена — 480 000 руб.', 'status' => 'base', 'source' => 'base', 'history' => []],
        ['path' => '2.2', 'title' => 'Пункт 2.2.', 'text' => 'НДС включён в цену.', 'status' => 'base', 'source' => 'base', 'history' => []],
        ['path' => 'раздел-3', 'title' => 'Раздел 3. Ответственность', 'text' => '', 'status' => 'base', 'source' => 'base', 'history' => []],
        ['path' => '3.1', 'title' => 'Пункт 3.1.', 'text' => 'Неустойка 0,1% в день.', 'status' => 'base', 'source' => 'base', 'history' => []],
    ];
}

function contract(): array
{
    return ['id' => 1, 'title' => 'Тестовый пакет', 'deal_card' => NULL];
}

function files(): array
{
    return [
        ['id' => 1, 'kind' => 'base', 'ordinal' => 0, 'ast' => json_encode(['nodes' => base_nodes()], JSON_UNESCAPED_UNICODE)],
        ['id' => 2, 'kind' => 'ds', 'ordinal' => 1],
    ];
}

function delta(array $row): array
{
    return $row + ['file_id' => 2, 'included' => 'yes', 'body_in_ds' => NULL, 'confidence' => NULL, 'note' => NULL];
}

function paths(array $nodes): array
{
    return array_map(static fn ($n) => strtolower((string) $n['path']), $nodes);
}

function status_of(array $nodes, string $path): string
{
    foreach ($nodes as $node) {
        if (strtolower((string) $node['path']) === $path) return (string) $node['status'];
    }
    return 'absent';
}

// ---- 1. replace по существующему пункту; ссылок нет — битых нет
[$nodes, $broken] = Service_Prx399_Merge::build(contract(), files(), [
    delta(['seq' => 0, 'op' => 'replace', 'anchor' => '2.1', 'body' => 'Цена — 512 000 руб.']),
]);
assert status_of($nodes, '2.1') === 'changed';
assert strpos((string) $nodes[4]['text'], '512 000') !== FALSE;
assert $broken === [];

// ---- 2. add к пункту с детьми: 2 + дети 2.1, 2.2 → новый 2.3
[$nodes] = Service_Prx399_Merge::build(contract(), files(), [
    delta(['seq' => 0, 'op' => 'add', 'anchor' => '2', 'body' => 'Отсрочка — 21 календарный день.']),
]);
assert in_array('2.3', paths($nodes), TRUE);
assert status_of($nodes, '2.3') === 'added';

// ---- 3. add к листовому пункту → подпункт 1.1.1 (семантика «ребёнок», не «сосед»)
[$nodes] = Service_Prx399_Merge::build(contract(), files(), [
    delta(['seq' => 0, 'op' => 'add', 'anchor' => '1.1', 'body' => 'Доставка силами Поставщика.']),
]);
assert in_array('1.1.1', paths($nodes), TRUE);

// ---- 4. annul целого раздела гасит поддерево: раздел-3 → и 3.1 annulled
[$nodes] = Service_Prx399_Merge::build(contract(), files(), [
    delta(['seq' => 0, 'op' => 'annul', 'anchor' => 'раздел-3']),
]);
assert status_of($nodes, 'раздел-3') === 'annulled';
assert status_of($nodes, '3.1') === 'annulled';

// ---- 5. битая ссылка: живой 1.1 ссылается («пункта 3.1») на annulled 3.1
[$nodes, $broken] = Service_Prx399_Merge::build(contract(), files(), [
    delta(['seq' => 0, 'op' => 'annul', 'anchor' => '3.1']),
]);
assert status_of($nodes, '3.1') === 'annulled';
assert count($broken) === 1, 'ожидаем одну битую ссылку из 1.1';
assert $broken[0]['ref'] === '3.1';
assert $broken[0]['where'] === '1.1';
assert strpos($broken[0]['excerpt'], 'пункта') !== FALSE, 'словоформа «пункта» должна ловиться';

// ---- 6. render: annulled узлы не выводятся при экспорте
[$nodes] = Service_Prx399_Merge::build(contract(), files(), [
    delta(['seq' => 0, 'op' => 'annul', 'anchor' => 'раздел-3']),
]);
$text = Service_Prx399_Merge::render_text($nodes);
assert strpos($text, '0,1% в день') === FALSE;
assert strpos($text, '480 000') !== FALSE;

// ---- 7. validate_op строгость: хвостовая точка в якоре, неизвестный op, пустой body
foreach ([
    ['op' => 'replace', 'anchor' => '2.1.'],
    ['op' => 'frobnicate', 'anchor' => '2.1'],
    ['op' => 'replace', 'anchor' => '2.1', 'body' => '   '],
] as $bad) {
    try {
        Service_Prx399_Merge::validate_op($bad);
        assert FALSE, 'validate_op должен отклонить: '.json_encode($bad, JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException $e) {
        // ожидаемо
    }
}

// ---- 8. validate_op: нормализация якоря и confidence вне диапазона → NULL
$clean = Service_Prx399_Merge::validate_op(['op' => 'add', 'anchor' => ' 2.3 ', 'body' => 'x', 'confidence' => 5, 'body_in_ds' => 'no']);
assert $clean['anchor'] === '2.3';
assert $clean['confidence'] === NULL;
assert $clean['body_in_ds'] === 'no';
$clean = Service_Prx399_Merge::validate_op(['op' => 'annul', 'anchor' => '2.2', 'body_in_ds' => 'hmm']);
assert $clean['body_in_ds'] === NULL;

echo "OK prx399_merge_build\n";
