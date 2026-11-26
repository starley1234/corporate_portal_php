<?php
/** Контракт экспорта prx399: Markdown, OOXML-тело, Word-HTML fallback. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');

require dirname(__DIR__, 2).'/application/modules/prx399_docmerge/classes/Service/Prx399/Export.php';

$nodes = [
    ['path' => '0', 'title' => 'Преамбула', 'text' => 'Стороны заключили договор.', 'status' => 'base', 'source' => 'base', 'history' => []],
    ['path' => 'раздел-2', 'title' => 'Раздел 2. Порядок расчётов', 'text' => '', 'status' => 'base', 'source' => 'base', 'history' => []],
    ['path' => '2.1', 'title' => 'Пункт 2.1.', 'text' => 'Цена — 480 000 руб. & НДС включён', 'status' => 'changed', 'source' => 'ДС №1', 'history' => []],
    ['path' => '2.1.1', 'title' => 'Пункт 2.1.1.', 'text' => 'Оплата — в течение 10 дней.', 'status' => 'base', 'source' => 'base', 'history' => []],
    ['path' => '2.2', 'title' => 'Пункт 2.2.', 'text' => 'Исключён ДС.', 'status' => 'annulled', 'source' => 'ДС №1', 'history' => [['rev' => 'ДС №1', 'op' => 'annul']]],
    ['path' => '2.3', 'title' => 'Пункт 2.3.', 'text' => 'Новый пункт.', 'status' => 'added', 'source' => 'ДС №1', 'history' => []],
];

// ---- Markdown: структура заголовков, annulled пропущен, экранирования не ломают
$md = Service_Prx399_Export::markdown('Договор №41', $nodes);
assert strpos($md, '# Договор №41') === 0;
assert strpos($md, '## Раздел 2. Порядок расчётов') !== FALSE;
assert strpos($md, '### Пункт 2.1.') !== FALSE;
assert strpos($md, '**Пункт 2.1.1.**') !== FALSE;
assert strpos($md, 'Новый пункт') !== FALSE && strpos($md, 'Исключён ДС') === FALSE;

// ---- OOXML: экранирование спецсимволов, стили, annulled не попал, секция A4
$xml = Service_Prx399_Export::document_xml('Договор <№41> & «тест»', $nodes);
assert substr_count($xml, '&lt;№41&gt;') === 1;
assert strpos($xml, ' & НДС') === FALSE; // сырой & не должен проскочить
assert strpos($xml, 'pStyle w:val="Title"') !== FALSE;
assert strpos($xml, 'pStyle w:val="Heading1"') !== FALSE;
assert strpos($xml, 'pStyle w:val="Heading2"') !== FALSE;
assert strpos($xml, 'Исключён ДС') === FALSE;
assert strpos($xml, 'pgSz w:w="11906"') !== FALSE;
assert substr_count($xml, '<w:body>') === 1;

// ---- Word-HTML fallback: заголовки + отсутствие annulled
$html = Service_Prx399_Export::wordhtml('Договор №41', $nodes);
assert strpos($html, '<h2>Раздел 2') !== FALSE && strpos($html, '<h3>Пункт 2.1.') !== FALSE;
assert strpos($html, 'Исключён ДС') === FALSE;
assert strpos($html, 'charset="utf-8"') !== FALSE;

// ---- docx(): при наличии php-zip — валидный ZIP-пакет с OOXML-частями
$file = Service_Prx399_Export::docx('Договор №41', $nodes, 'contract-1-consolidated');
if (class_exists('ZipArchive') && str_ends_with($file['name'], '.docx')) {
    assert $file['mime'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $probe = tempnam(sys_get_temp_dir(), 'prx399t');
    file_put_contents($probe, $file['body']);
    $zip = new ZipArchive;
    assert $zip->open($probe) === TRUE;
    assert $zip->locateName('word/document.xml') !== FALSE;
    assert $zip->locateName('word/styles.xml') !== FALSE;
    assert $zip->locateName('[Content_Types].xml') !== FALSE;
    $doc = $zip->getFromName('word/document.xml');
    assert strpos($doc, 'Новый пункт') !== FALSE && strpos($doc, 'Исключён ДС') === FALSE;
    $zip->close(); unlink($probe);
} else {
    // расширения нет — честный fallback в Word-HTML с именем .doc
    assert $file['name'] === 'contract-1-consolidated.doc' && $file['mime'] === 'application/msword';
}

echo "OK prx399_export\n";
