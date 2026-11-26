<?php
/**
 * Контракт prx400_specflow: библиотека шаблонов, матчинг с НСИ (пороги доверия),
 * экспорт JSON/CSV. PHP CLI: php tests/Contracts/prx400_match_export.php
 */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');

require dirname(__DIR__, 2).'/application/modules/prx400_specflow/classes/Service/Prx400/Templates.php';
require dirname(__DIR__, 2).'/application/modules/prx400_specflow/classes/Service/Prx400/Match.php';
require dirname(__DIR__, 2).'/application/modules/prx400_specflow/classes/Service/Prx400/Export.php';

// ---- Шаблоны: валидные JSON, минимум один универсальный, поля разбора на месте
$templates = Service_Prx400_Templates::all();
assert count($templates) >= 1;
$generic = Service_Prx400_Templates::get('spec_generic_v1');
assert $generic !== NULL;
assert ! empty($generic['detection_policy']['anchor_keywords']);
assert ! empty($generic['table_rules']['columns']['qty']['match']);
assert Service_Prx400_Templates::get('нет_такого') === NULL;
foreach ($templates as $tpl) {
    assert isset($tpl['template_id'], $tpl['template_name'], $tpl['table_rules']['columns']['pos']);
}

// ---- Нормализация: регистр, ё, пунктуация
assert Service_Prx400_Match::normalize('Гайка М12, ГОСТ 5915-70 (цинк)') === 'гайка м12 гост 5915 70 цинк';
assert Service_Prx400_Match::normalize('Ёлка-палка') === 'елка палка';

// ---- Скор: идентичное = 1, unrelated = низкий, опечатки/сокращения — высокий
$gaika = 'Гайка М12-6Н.5 (S18) ГОСТ 5915-70 цинк';
$cat_gaika = 'Гайка шестигранная М12 ГОСТ 5915-70 оцинкованная';
assert Service_Prx400_Match::score($gaika, $gaika) === 1.0;
$score_match = Service_Prx400_Match::score($gaika, $cat_gaika);
assert $score_match > 0.5, 'синонимы/ГОСТ дают высокий скор: '.$score_match;
assert Service_Prx400_Match::score($gaika, 'Подшипник роликовый конический 7508') < 0.4;

// ---- Пороги доверия: auto_accept → ok, manual_review → review, ниже → new
$catalog = [
    ['id' => 1, 'code' => 'NSI-0001', 'name' => $cat_gaika, 'unit' => 'шт.', 'okei_code' => '796', 'keywords' => 'гайка м12 крепёж'],
    ['id' => 2, 'code' => 'NSI-0002', 'name' => 'Подшипник роликовый конический 7508', 'unit' => 'шт.', 'okei_code' => '796', 'keywords' => ''],
    ['id' => 3, 'code' => 'NSI-0003', 'name' => 'Кран шаровой Ду50 Ру16', 'unit' => 'шт.', 'okei_code' => '796', 'keywords' => 'арматура'],
];
$m = Service_Prx400_Match::apply($gaika, 'Гайка М12', $catalog);
assert $m['status'] === 'ok' || $m['status'] === 'review', 'крепёж со словарём: '.$m['status'];
assert $m['match']['alternatives'] !== NULL;

$m2 = Service_Prx400_Match::apply('Совершенно неизвестная деталь №999', '', $catalog);
assert $m2['status'] === 'new', 'низкий скор → новая позиция';
assert count($m2['match']['alternatives']) <= 3, 'top-K подсказок';

// порог auto_accept: точное вхождение имени каталога
$m3 = Service_Prx400_Match::apply('Подшипник роликовый конический 7508', '', $catalog);
assert $m3['status'] === 'ok' && $m3['match']['code'] === 'NSI-0002';

// ---- Экспорт: схема приёмника (meta + positions + audit) и верификация
$spec = [
    'id' => 7, 'template_id' => 'spec_generic_v1', 'template_title' => 'Спецификация',
    'spec_number' => '41-ПЗ', 'department' => 'ПТО', 'original_name' => 'scan.pdf',
    'pages' => 3, 'text_sha256' => str_repeat('a', 64), 'updated_at' => '2026-11-25 12:00:00',
];
$rows = [
    ['seq' => 0, 'pos' => 1, 'raw_name' => $gaika, 'code_doc' => 'Гайка М12', 'qty' => 500.0, 'unit' => 'шт.',
     'unit_price' => 4.5, 'total_price' => 2250.0, 'flags' => [],
     'match' => ['catalog_id' => 1, 'code' => 'NSI-0001', 'name' => $cat_gaika, 'unit' => 'шт.', 'okei_code' => '796', 'score' => 0.94],
     'status' => 'ok', 'verified' => 'f'],
    ['seq' => 1, 'pos' => 2, 'raw_name' => 'Заглушка новую поставить', 'code_doc' => '', 'qty' => 2.0, 'unit' => 'шт.',
     'unit_price' => NULL, 'total_price' => NULL, 'flags' => [['type' => 'gap', 'after_pos' => 1]],
     'match' => null, 'status' => 'new', 'verified' => 't'],
];
$json = Service_Prx400_Export::json($spec, $rows);
assert $json['meta']['specification_code'] === '41-ПЗ';
assert $json['meta']['template_id'] === 'spec_generic_v1';
assert count($json['positions']) === 2;
$p0 = $json['positions'][0];
assert $p0['catalog_code'] === 'NSI-0001' && $p0['okei_unit_code'] === '796';
assert $p0['verification_status'] === 'ok';
assert $p0['quantity'] === 500.0 && $p0['total_price'] === 2250.0;
$p1 = $json['positions'][1];
assert $p1['catalog_code'] === NULL && $p1['verification_status'] === 'verified';
assert in_array('gap', $p1['warnings'], TRUE);
assert $json['audit']['auto_matched'] === 1 && $json['audit']['new_positions'] === 1;

// верификация вручную подтверждённой строки попадает в audit.corrections
$rows[0]['match']['manual'] = TRUE;
$json2 = Service_Prx400_Export::json($spec, $rows);
assert $json2['audit']['confirmed_by_operator'] === 1;
assert count($json2['audit']['corrections']) === 1;

// ---- CSV: заголовок, разделитель «;», BOM для Excel, статус по-русски
$csv = Service_Prx400_Export::csv($spec, $rows);
assert strpos($csv, "\xEF\xBB\xBF") === 0;
assert strpos($csv, 'Наименование') !== FALSE;
assert strpos($csv, 'NSI-0001') !== FALSE;
assert strpos($csv, 'новая позиция') !== FALSE;

echo "OK prx400_match_export\n";
