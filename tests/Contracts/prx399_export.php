<?php
/**
 * Контракт экспорта prx399: оформление делового договора (ГОСТ Р 7.0.97-2016)
 * — титул из текста, разделы по центру без точки, пункты «1.1. …» единым
 * абзацем, поля 30/10/20/20 мм, нумерация страниц со второй, без служебных строк.
 */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');

/** Проверка контракта: не зависит от zend.assertions (в prod-ini assert() выключен). */
function check(bool $condition, string $message = 'contract violated'): void
{
    if ( ! $condition) {
        $at = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'] ?? 0;
        fwrite(STDERR, 'FAIL line '.$at.': '.$message."\n");
        exit(1);
    }
}

require dirname(__DIR__, 2).'/application/modules/prx399_docmerge/classes/Service/Prx399/Export.php';

function export_nodes(): array
{
    $node = static fn (string $path, string $title, string $text, string $status = 'base'): array =>
        ['path' => $path, 'title' => $title, 'text' => $text, 'status' => $status, 'source' => 'base', 'history' => []];

    return [
        // преамбула: титульная строка (уезжает в заголовок), город/дата, стороны
        $node('0', 'Преамбула', "ДОГОВОР ПОСТАВКИ №41\nг. Королев\nОбщество с ограниченной ответственностью «Лютик», именуемое в дальнейшем Поставщик, и ООО «Клевер», именуемое в дальнейшем Покупатель, заключили настоящий Договор о нижеследующем:"),
        // раздел-заголовок: верхний уровень, капс, без точки
        $node('1', 'Пункт 1.', 'ПРЕДМЕТ ДОГОВОРА'),
        $node('1.1', 'Пункт 1.1.', 'Поставщик обязуется передать товар & сопутствующую документацию'),
        $node('1.1.1', 'Пункт 1.1.1.', 'Ассортимент — в Приложении 1.'),
        // словесный заголовок
        $node('раздел-2', 'Раздел 2. Порядок расчётов', ''),
        $node('2.1', 'Пункт 2.1.', 'Цена — 480 000 руб., НДС включён.'),
        $node('2.2', 'Пункт 2.2.', 'Исключено ДС.', 'annulled'),
        $node('2.3', 'Пункт 2.3.', 'Отсрочка платежа — 21 календарный день.', 'added'),
        // артефакт разбора скана: точный дубль узла — вычищается
        $node('2.3', 'Пункт 2.3.', 'Отсрочка платежа — 21 календарный день.', 'added'),
    ];
}

// ---- title_of: титул из текста, строка изымается из преамбулы
[$title, $nodes] = Service_Prx399_Export::title_of(export_nodes());
check($title === 'ДОГОВОР ПОСТАВКИ №41');
$preamble = '';
foreach ($nodes as $n) { if ($n['path'] === '0') { $preamble = (string) $n['text']; } }
check(strpos($preamble, 'ДОГОВОР ПОСТАВКИ') === FALSE, 'титульная строка изъята из преамбулы');
check(strpos($preamble, 'г. Королев') === 0);

// ---- file_base: имя из титула, мусор вычищен
check(Service_Prx399_Export::file_base('ДОГОВОР ПОСТАВКИ №41', 10) === 'ДОГОВОР ПОСТАВКИ №41');
check(Service_Prx399_Export::file_base('', 10) === 'contract-10-consolidated');
check(Service_Prx399_Export::file_base('Договор <а> "б"/в?', 10) === 'Договор а бв');

// ---- markdown: структура договора, без «Пункт N.», без служебных строк
$md = Service_Prx399_Export::markdown($title, $nodes);
check(strpos($md, "# ДОГОВОР ПОСТАВКИ №41\n") === 0);
check(strpos($md, '## 1. ПРЕДМЕТ ДОГОВОРА') !== FALSE);
check(strpos($md, "1.1. Поставщик обязуется") !== FALSE, 'номер — часть абзаца');
check(strpos($md, '## Раздел 2. Порядок расчётов') !== FALSE);
check(strpos($md, '2.3. Отсрочка платежа') !== FALSE);
check(substr_count($md, '2.3. Отсрочка платежа') === 1, 'дубль узла вычищен');
check(strpos($md, 'Пункт 1.') === FALSE, 'синтетических заголовков нет');
check(strpos($md, 'prx399') === FALSE && strpos($md, 'Актуальная редакция') === FALSE, 'отписок нет');
check(strpos($md, 'Исключено ДС') === FALSE, 'annulled не выводится');
check(strpos($md, "г. Королев") !== FALSE);

// ---- артефакт скана: титул+стороны, попавшие в «пункт 1» — рендер как преамбула
$artifact = export_nodes();
foreach ($artifact as $i => $n) {
    if ($n['path'] === '1') { $artifact[$i]['text'] = "ДОГОВОР ПОСТАВКИ №41\nг. Королев\nОбщество «Лютик», именуемое в дальнейшем Поставщик, заключило договор."; }
}
[$art_title, $artifact] = Service_Prx399_Export::title_of($artifact);
$md_art = Service_Prx399_Export::markdown($art_title, $artifact);
check($art_title === 'ДОГОВОР ПОСТАВКИ №41');
check(strpos($md_art, "1. ДОГОВОР ПОСТАВКИ") === FALSE, 'номер не приписан к титулу/преамбуле');
check(strpos($md_art, 'именуемое в дальнейшем Поставщик') !== FALSE);
check(strpos($md_art, '## 1.1. ') === FALSE && strpos($md_art, '1.1. ') !== FALSE);

// ---- OOXML: ГОСТ-параметры, экранирование, стили, футер, поля
$xml = Service_Prx399_Export::document_xml($title, $nodes);
check(substr_count($xml, '&amp;') === 1 && strpos($xml, ' & ') === FALSE);
check(strpos($xml, 'pStyle w:val="Title"') !== FALSE && strpos($xml, 'pStyle w:val="Heading1"') !== FALSE);
check(strpos($xml, 'w:jc w:val="both"') !== FALSE, 'текст по ширине');
check(strpos($xml, 'w:firstLine="709"') !== FALSE, 'абзацный отступ 1,25 см');
check(strpos($xml, 'w:left="1701"') !== FALSE && strpos($xml, 'w:right="567"') !== FALSE, 'поля 30/10 мм');
check(strpos($xml, 'w:top="1134"') !== FALSE, 'поля 20 мм');
check(strpos($xml, 'footerReference') !== FALSE && strpos($xml, 'titlePg') !== FALSE, 'нумерация со 2-й страницы');
check(strpos($xml, 'Пункт 1.') === FALSE);
check(strpos($xml, '2.3. Отсрочка платежа — 21 календарный день.') !== FALSE);

// ---- footer/styles части
$footer = (Closure::bind(static fn () => Service_Prx399_Export::_footer_xml(), NULL, Service_Prx399_Export::class))();
check(strpos($footer, 'PAGE') !== FALSE && strpos($footer, '<w:jc w:val="center"/>') !== FALSE);
$styles = (Closure::bind(static fn () => Service_Prx399_Export::_styles_xml(), NULL, Service_Prx399_Export::class))();
check(strpos($styles, 'Times New Roman') !== FALSE && strpos($styles, 'w:sz w:val="28"') !== FALSE);
check(strpos($styles, 'keepNext') !== FALSE);

// ---- docx(): ZIP-пакет с тремя частями либо честный Word-HTML fallback
$file = Service_Prx399_Export::docx($title, $nodes, 'ДОГОВОР ПОСТАВКИ №41');
if (class_exists('ZipArchive') && str_ends_with($file['name'], '.docx')) {
    check($file['mime'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    $probe = tempnam(sys_get_temp_dir(), 'prx399t');
    file_put_contents($probe, $file['body']);
    $zip = new ZipArchive;
    check($zip->open($probe) === TRUE);
    foreach (['word/document.xml', 'word/styles.xml', 'word/footer1.xml', '[Content_Types].xml'] as $part) {
        check($zip->locateName($part) !== FALSE, $part);
    }
    $doc = $zip->getFromName('word/document.xml');
    check(strpos($doc, 'ДОГОВОР ПОСТАВКИ №41') !== FALSE && strpos($doc, 'Исключено ДС') === FALSE);
    $zip->close(); unlink($probe);
} else {
    check($file['name'] === 'ДОГОВОР ПОСТАВКИ №41.doc' && $file['mime'] === 'application/msword');
}

// ---- Word-HTML fallback: заголовки и отступы
$html = Service_Prx399_Export::wordhtml($title, $nodes);
check(strpos($html, '<h1>ДОГОВОР ПОСТАВКИ №41</h1>') !== FALSE);
check(strpos($html, '<h2>1. ПРЕДМЕТ ДОГОВОРА</h2>') !== FALSE);
check(strpos($html, 'text-indent:1.25cm') !== FALSE);
check(strpos($html, 'Исключено ДС') === FALSE);

echo "OK prx399_export\n";
