<?php
/**
 * Контракт prx400_specflow без БД: библиотека шаблонов и их валидация,
 * матчинг с НСИ (пороги доверия), правило статуса строки, построчные
 * проверки, приём результата воркера (clean_row/clean_math), коды ошибок
 * очереди и API, экспорт JSON/CSV/XLSX и закупочная ведомость, дерево BOM,
 * нормализация и перенос решений между разборами.
 * PHP CLI: php tests/Contracts/prx400_match_export.php
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

/** Доменная ошибка с ожидаемым кодом. */
function check_error(callable $fn, string $code, int $status): void
{
    try {
        $fn();
    } catch (Prx_DomainError $e) {
        check($e->getMessage() === $code, 'код ошибки: '.$e->getMessage().' вместо '.$code);
        check($e->getCode() === $status, 'HTTP-статус '.$code.': '.$e->getCode());
        return;
    }
    check(FALSE, 'ожидалась ошибка '.$code);
}

$root = dirname(__DIR__, 2);
$module = $root.'/application/modules/prx400_specflow';

// ---- Заглушки инфраструктуры: конфиг модуля — настоящий, БД нет.
class Kohana
{
    public static $config;
}
class ContractConfig
{
    private array $data;
    public function __construct(array $data) { $this->data = $data; }
    public function load($name) { return $this; }
    public function get($key) { return $this->data[$key] ?? NULL; }
}
class Log { const ERROR = 3; const WARNING = 4; const INFO = 6; }
class Log_Plain { public static array $events = []; public static function event($level, $message, array $values = NULL) { self::$events[] = $message; } }
class Model_Prx400_Template { public static function all(): array { return []; } }

require $root.'/application/classes/Prx/DomainError.php';
// Настоящий prx120: причину остановки задачи распознаёт владелец текстов очереди.
foreach (['JobQueue/Maintenance', 'JobQueue/Monitor', 'JobQueue'] as $part) {
    require $root.'/application/modules/prx120_jobs/classes/Service/Prx120/'.$part.'.php';
}
require $root.'/application/modules/prx120_jobs/classes/Prx120/JobOutcome.php';
require $module.'/classes/Prx400/Error.php';
require $module.'/classes/Prx400/Messages.php';
require $module.'/classes/Prx400/Xlsx.php';
require $root.'/application/classes/Prx/Spreadsheet.php';
foreach (['Limits', 'Templates', 'Match', 'Checks', 'Pipeline', 'Specs', 'Export', 'Rows'] as $class) {
    require $module.'/classes/Service/Prx400/'.$class.'.php';
}
Kohana::$config = new ContractConfig(require $module.'/config/prx400_specflow.php');

// ---- Конфиг: пороги и пределы читаются только через Limits, значения в границах
$t = Service_Prx400_Limits::match();
check($t['auto_accept'] > $t['manual_review'] && $t['manual_review'] > 0, 'пороги доверия упорядочены');
check(in_array(Service_Prx400_Limits::default_mode(), Service_Prx400_Limits::parse_modes(), TRUE), 'режим по умолчанию — из списка');
check(Service_Prx400_Limits::get('max_pdf_bytes') >= 1024 ** 2, 'лимит PDF не «тихий ноль»');
check(Service_Prx400_Limits::get('max_rows') >= 10);
check(Service_Prx400_Limits::get('max_result_bytes') > 0, 'лимит тела результата воркера задан');
$request_cap = max(Service_Prx400_Limits::get('max_pdf_bytes'), Service_Prx400_Limits::get('max_total_bytes'));
check_error(static fn () => Service_Prx400_Limits::assert_request_size($request_cap + 10 ** 6), 'file_too_large', 413);
check(Service_Prx400_Limits::get('max_files') >= 1 && Service_Prx400_Limits::get('max_total_bytes') >= Service_Prx400_Limits::get('max_pdf_bytes'), 'многофайловая загрузка: общий предел не меньше одного файла');
Service_Prx400_Limits::assert_request_size(1000);

// ---- Шаблоны: валидные JSON, минимум один универсальный, поля разбора на месте
$templates = Service_Prx400_Templates::all();
check(count($templates) >= 1);
$generic = Service_Prx400_Templates::get('spec_generic_v1');
check($generic !== NULL);
check( ! empty($generic['detection_policy']['anchor_keywords']));
check( ! empty($generic['table_rules']['columns']['qty']['match']));
check(Service_Prx400_Templates::get('нет_такого') === NULL);
foreach ($templates as $tpl) {
    check(isset($tpl['template_id'], $tpl['template_name'], $tpl['table_rules']['columns']['pos']));
    $copy = $tpl;
    $copy['template_id'] = 'copy_'.$tpl['template_id']; // коды встроенных зарезервированы
    Service_Prx400_Templates::validate($copy); // встроенные проходят ту же валидацию, что и загружаемые
}
check(Service_Prx400_Templates::resolve('') === '', 'пусто — авто-детекция');
check(Service_Prx400_Templates::resolve('spec_generic_v1') === 'spec_generic_v1');
check_error(static fn () => Service_Prx400_Templates::resolve('нет_такого'), 'template_not_found', 400);
check_error(static fn () => Service_Prx400_Templates::validate(['template_id' => 'Bad Code']), 'template_invalid', 400);
check_error(static fn () => Service_Prx400_Templates::validate($generic), 'template_code_reserved', 409);
$broken = ['template_id' => 'custom_x'] + $generic;
$broken['table_rules']['columns']['synonyms_col'] = ['match' => ['x']];
check_error(static fn () => Service_Prx400_Templates::validate($broken), 'template_invalid_column', 400);
$no_pos = ['template_id' => 'custom_y'] + $generic;
unset($no_pos['table_rules']['columns']['pos']);
check_error(static fn () => Service_Prx400_Templates::validate($no_pos), 'template_invalid_column', 400);

// ---- Нормализация: регистр, ё, пунктуация
// Токены с цифрами (марки, типоразмеры) сводятся к латинице: «М12» из скана
// бывает и кириллицей, и латиницей — для НСИ это одна позиция.
check(Service_Prx400_Match::normalize('Гайка М12, ГОСТ 5915-70 (цинк)') === 'гайка m12 гост 5915 70 цинк');
check(Service_Prx400_Match::normalize('Ёлка-палка') === 'елка палка');

// ---- Скор: идентичное = 1, unrelated = низкий, опечатки/сокращения — высокий
$gaika = 'Гайка М12-6Н.5 (S18) ГОСТ 5915-70 цинк';
$cat_gaika = 'Гайка шестигранная М12 ГОСТ 5915-70 оцинкованная';
check(Service_Prx400_Match::score($gaika, $gaika) === 1.0);
$score_match = Service_Prx400_Match::score($gaika, $cat_gaika);
check($score_match > 0.5, 'синонимы/ГОСТ дают высокий скор: '.$score_match);
check(Service_Prx400_Match::score($gaika, 'Подшипник роликовый конический 7508') < 0.4);

// ---- Пороги доверия: auto_accept → ok, manual_review → review, ниже → new
$catalog = [
    ['id' => 1, 'code' => 'NSI-0001', 'name' => $cat_gaika, 'unit' => 'шт.', 'okei_code' => '796', 'keywords' => 'гайка м12 крепёж'],
    ['id' => 2, 'code' => 'NSI-0002', 'name' => 'Подшипник роликовый конический 7508', 'unit' => 'шт.', 'okei_code' => '796', 'keywords' => ''],
    ['id' => 3, 'code' => 'NSI-0003', 'name' => 'Кран шаровой Ду50 Ру16', 'unit' => 'шт.', 'okei_code' => '796', 'keywords' => 'арматура'],
];
$m = Service_Prx400_Match::apply($gaika, 'Гайка М12', $catalog);
// Описательные вариации («шестигранная», «оцинкованная») скорер оценивает
// ниже порога подтверждения, но верная позиция — первая подсказка оператору.
check(($m['match']['alternatives'][0]['code'] ?? '') === 'NSI-0001', 'лучшая подсказка — гайка: '.json_encode($m['match']));

$m2 = Service_Prx400_Match::apply('Совершенно неизвестная деталь №999', '', $catalog);
check($m2['status'] === 'new', 'низкий скор → новая позиция');
check(count($m2['match']['alternatives']) <= $t['top_k'], 'top-K подсказок');
check(Service_Prx400_Match::apply('Что угодно', '', [])['status'] === 'new', 'пустой справочник — честно «новая»');

$m3 = Service_Prx400_Match::apply('Подшипник роликовый конический 7508', '', $catalog);
check($m3['status'] === 'ok' && $m3['match']['code'] === 'NSI-0002', 'точное имя — автопривязка');

// query(): код документа без дублей имени
check(Service_Prx400_Match::query('Гайка М12', 'Гайка М12') === 'Гайка М12');
check(Service_Prx400_Match::query('Кран', 'Ду50') === 'Кран Ду50');

// ---- row_status: одно правило для воркера, правки и снятия «Проверено»
$ok_match = $m3['match'];
check(Service_Prx400_Match::row_status([], $ok_match) === 'ok');
check(Service_Prx400_Match::row_status([['type' => 'math_row', 'expected' => 10.0]], $ok_match) === 'error', 'арифметика важнее кода');
check(Service_Prx400_Match::row_status([['type' => 'unit_fractional', 'unit' => 'шт']], $ok_match) === 'review', 'дробные штуки — на подтверждение');
check(Service_Prx400_Match::row_status([['type' => 'gap', 'after_pos' => 3]], $ok_match) === 'ok', 'пропуск нумерации — контекст документа, не ошибка строки');
check(Service_Prx400_Match::row_status([], ['alternatives' => [['score' => $t['manual_review'] + 0.01]]]) === 'review');
check(Service_Prx400_Match::row_status([], ['alternatives' => [['score' => $t['manual_review'] - 0.01]]]) === 'new');
check(Service_Prx400_Match::row_status([], NULL) === 'new');

// ---- Checks::row_flags — зеркало run_math_checks воркера
check(Service_Prx400_Checks::row_flags(['qty' => 2, 'unit' => 'шт', 'unit_price' => 4.5, 'total_price' => 9.0]) === []);
$f = Service_Prx400_Checks::row_flags(['qty' => 2, 'unit' => 'кг', 'unit_price' => 4.5, 'total_price' => 10.0]);
check(count($f) === 1 && $f[0]['type'] === 'math_row' && $f[0]['expected'] === 9.0, 'расхождение суммы: '.json_encode($f));
$f = Service_Prx400_Checks::row_flags(['qty' => 1.5, 'unit' => 'Шт.', 'unit_price' => NULL, 'total_price' => NULL]);
check(count($f) === 1 && $f[0]['type'] === 'unit_fractional', 'дробные штуки');
check(Service_Prx400_Checks::row_flags(['qty' => 1.5, 'unit' => 'м']) === [], 'дробные метры — норма');
check(Service_Prx400_Checks::row_flags(['qty' => 'abc', 'unit' => 'шт', 'unit_price' => 1, 'total_price' => 5]) === [], 'нечисло не сравнивается');

// ---- Pipeline::clean_row: типы, длины, белый список флагов, строчные флаги — от сервера
$row = Service_Prx400_Pipeline::clean_row(4, [
    'pos' => 5, 'raw_name' => '  '.str_repeat('я', 5000), 'code_doc' => 'ГОСТ 1', 'qty' => '3', 'unit' => 'шт',
    'unit_price' => 2, 'total_price' => 7, 'section' => 'Крепёж',
    'flags' => [['type' => 'gap', 'after_pos' => 3], ['type' => 'evil', 'x' => 1], ['type' => 'math_row', 'expected' => 999]],
]);
check($row['seq'] === 4 && $row['pos'] === 5 && $row['qty'] === 3.0);
check(mb_strlen($row['raw_name']) === 4000, 'наименование обрезано до 4000');
$types = array_column($row['flags'], 'type');
sort($types);
check($types === ['gap', 'math_row'], 'флаги: '.json_encode($row['flags']));
foreach ($row['flags'] as $flag) {
    if ($flag['type'] === 'math_row') check($flag['expected'] === 6.0, 'expected пересчитан сервером, а не взят у воркера');
}
$row = Service_Prx400_Pipeline::clean_row(0, ['pos' => '7', 'qty' => INF, 'raw_name' => ['array'], 'flags' => 'oops']);
check($row['pos'] === NULL && $row['qty'] === NULL && $row['raw_name'] === '' && $row['flags'] === [], 'мусор воркера не проходит');

$math = Service_Prx400_Pipeline::clean_math([
    'math' => ['row_errors' => [['pos' => 1], 'bad'], 'gaps' => [], 'totals' => ['declared' => '10', 'computed' => 9.5, 'ok' => FALSE], 'summary' => 'Итого не сходится.'],
    'warnings' => ['vision_pages_truncated', 'vision_page_3_failed', '<script>', 42],
    'stats' => ['via' => 'vision', 'pages_processed' => 2],
]);
check(count($math['row_errors']) === 1 && $math['totals']['declared'] === 10.0 && $math['totals']['ok'] === FALSE);
check($math['warnings'] === ['vision_pages_truncated', 'vision_page_3_failed'], 'предупреждения — только коды');
check($math['via'] === 'vision' && $math['pages_processed'] === 2);
check(Service_Prx400_Pipeline::clean_math([])['via'] === 'ocr' && Service_Prx400_Pipeline::clean_math([])['totals']['ok'] === NULL);
check(Prx400_Messages::warning('vision_page_3_failed') !== 'vision_page_3_failed', 'у предупреждения есть текст');

// ---- Коды ошибок: из очереди и для API — только белый список
$failed = static fn (string $error): array => ['status' => 'failed', 'error' => $error];
check(Service_Prx400_Specs::error_code($failed('PermanentJobError: no_text')) === 'no_text');
check(Service_Prx400_Specs::error_code($failed('DocumentJobError: header_not_found')) === 'header_not_found');
check(Service_Prx400_Specs::error_code($failed('PermanentJobError: /etc/passwd leaked')) === 'job_failed', 'произвольный текст очереди не становится кодом');
check(Service_Prx400_Specs::error_code($failed('Превышен таймаут задачи (600 с)')) === 'job_timeout');
check(Service_Prx400_Specs::public_code('no_rows') === 'no_rows');
check(Service_Prx400_Specs::public_code('no_rows: Строки не найдены') === 'no_rows', 'легаси «код: текст»');
check(Service_Prx400_Specs::public_code('Какой-то русский текст') === 'job_failed');
foreach (Service_Prx400_Specs::FAILED_CODES as $code) {
    check(Prx400_Messages::text($code) !== $code, 'нет текста для кода '.$code);
}

// ---- effective(): статус записи с учётом очереди (только чтение)
$spec_q = ['status' => 'queued', 'job_id' => 5, 'error' => NULL];
check(Service_Prx400_Specs::effective($spec_q, ['status' => 'pending']) === ['queued', NULL]);
check(Service_Prx400_Specs::effective($spec_q, ['status' => 'processing']) === ['running', NULL]);
check(Service_Prx400_Specs::effective($spec_q, ['status' => 'cancelled']) === ['failed', 'job_canceled']);
check(Service_Prx400_Specs::effective($spec_q, ['status' => 'failed', 'error' => 'PermanentJobError: no_text']) === ['failed', 'no_text']);
check(Service_Prx400_Specs::effective($spec_q, ['status' => 'done']) === ['failed', 'result_missing']);
check(Service_Prx400_Specs::effective($spec_q, NULL) === ['failed', 'job_unavailable']);
// Остановку «воркер замолчал» делает обслуживание очереди, а не человек — не «отменено».
foreach ([
    ['cancelled', 'Воркер не отвечал больше 4 мин — сбор остановлен автоматически. Найденное сохранено, запустите заново.', 'worker_lost'],
    ['cancelled', 'Воркер не выходил на связь больше 30 мин — сбор остановлен автоматически.', 'worker_lost'],
    ['failed', 'Воркер молчал дольше timeout — попытки исчерпаны', 'worker_lost'],
    ['failed', 'Воркер перестал подавать признаки жизни — задача отказана (попытки исчерпаны)', 'worker_lost'],
    ['failed', 'Воркер перезапускался на этой задаче, попытки исчерпаны. Проверьте входные данные.', 'worker_crash_loop'],
    ['cancelled', 'Прервана администратором: пульс задачи молчит дольше 10 мин', 'job_canceled'],
    ['cancelled', '', 'job_canceled'],
    ['failed', 'Превышен таймаут задачи (1800 с)', 'job_timeout'],
] as [$job_status, $error, $expected]) {
    check(Service_Prx400_Specs::effective($spec_q, ['status' => $job_status, 'error' => $error]) === ['failed', $expected], $error.' → '.$expected);
}
check(Service_Prx120_JobQueue::stop_reason(['status' => 'processing', 'error' => 'Воркер не отвечал больше 4 мин']) === NULL, 'живая задача — не потеряна');
check(Service_Prx400_Limits::get('crash_retries') === 1, 'автоповтор после падения воркера — один раз по умолчанию');
check(Service_Prx400_Specs::effective(['status' => 'review', 'job_id' => 5, 'error' => NULL], NULL) === ['review', NULL], 'разобранная запись от очереди не зависит');

// ---- Экспорт: схема приёмника (meta + positions + audit) и верификация
$spec = [
    'id' => 7, 'status' => 'review', 'template_id' => 'spec_generic_v1', 'template_title' => 'Спецификация',
    'spec_number' => '41-ПЗ', 'department' => 'ПТО', 'original_name' => 'scan.pdf', 'parse_mode' => 'ocr',
    'pages' => 3, 'text_sha256' => str_repeat('a', 64), 'updated_at' => '2026-11-25 12:00:00',
];
$rows = [
    ['seq' => 0, 'pos' => 1, 'section' => 'Крепёж', 'raw_name' => $gaika, 'code_doc' => 'Гайка М12', 'qty' => 500.0, 'unit' => 'шт.',
     'unit_price' => 4.5, 'total_price' => 2250.0, 'flags' => [],
     'match' => ['catalog_id' => 1, 'code' => 'NSI-0001', 'name' => $cat_gaika, 'unit' => 'шт.', 'okei_code' => '796', 'score' => 0.94],
     'status' => 'ok', 'verified' => FALSE],
    ['seq' => 1, 'pos' => 2, 'section' => '', 'raw_name' => '=HYPERLINK("http://x")', 'code_doc' => '', 'qty' => 2.0, 'unit' => 'шт.',
     'unit_price' => NULL, 'total_price' => NULL, 'flags' => [['type' => 'gap', 'after_pos' => 1]],
     'match' => NULL, 'status' => 'new', 'verified' => TRUE],
];
$json = Service_Prx400_Export::json($spec, $rows);
check($json['meta']['specification_code'] === '41-ПЗ');
check($json['meta']['template_id'] === 'spec_generic_v1' && $json['meta']['spec_id'] === 7 && $json['meta']['status'] === 'review');
check(count($json['positions']) === 2);
$p0 = $json['positions'][0];
check($p0['catalog_code'] === 'NSI-0001' && $p0['okei_unit_code'] === '796' && $p0['section'] === 'Крепёж');
check($p0['verification_status'] === 'ok');
check($p0['quantity'] === 500.0 && $p0['total_price'] === 2250.0);
$p1 = $json['positions'][1];
check($p1['catalog_code'] === NULL && $p1['verification_status'] === 'verified');
check(in_array('gap', $p1['warnings'], TRUE));
check($json['audit']['auto_matched'] === 1 && $json['audit']['new_positions'] === 1);

// вручную назначенный код попадает в audit.corrections
$rows[0]['match']['manual'] = TRUE;
$json2 = Service_Prx400_Export::json($spec, $rows);
check($json2['audit']['confirmed_by_operator'] === 1);
check(count($json2['audit']['corrections']) === 1);

// ---- CSV: заголовок, «;», BOM для Excel, статус по-русски, без формул из скана
$csv = Service_Prx400_Export::csv($spec, $rows);
check(strpos($csv, "\xEF\xBB\xBF") === 0);
check(strpos($csv, 'Наименование') !== FALSE);
check(strpos($csv, 'NSI-0001') !== FALSE);
check(strpos($csv, 'проверено') !== FALSE, 'подтверждённая строка помечена');
check(strpos($csv, ';=HYPERLINK') === FALSE && strpos($csv, "'=HYPERLINK") !== FALSE, 'CSV-инъекция нейтрализована');


// ---- Нормализация НСИ: кириллица/латиница, х/x, запятая, ø, единицы
check(Service_Prx400_Match::normalize('Болт М16х60') === Service_Prx400_Match::normalize('Болт M16x60'), 'М/M и х/x — одно и то же');
check(Service_Prx400_Match::unit_key('Шт.') === Service_Prx400_Match::unit_key('шт'), 'Шт. = шт');
check(Service_Prx400_Match::unit_key('пог.м') === 'м' && Service_Prx400_Match::unit_key('м²') === 'м2');

// ---- clean_row: иерархия BOM, файл, страница
$r = Service_Prx400_Pipeline::clean_row(0, ['pos_path' => '1.2.3', 'raw_name' => 'Болт', 'file_no' => 2, 'page' => 4], 3);
check($r['pos_path'] === '1.2.3' && $r['pos'] === 3 && $r['level'] === 2 && $r['file_no'] === 2 && $r['page'] === 4, json_encode($r));
$r = Service_Prx400_Pipeline::clean_row(0, ['pos_path' => '1..2', 'pos' => 5, 'file_no' => 9, 'page' => -1], 2);
check($r['pos_path'] === '5' && $r['file_no'] === 1 && $r['page'] === NULL, 'мусорный путь/файл/страница отброшены');
$r = Service_Prx400_Pipeline::clean_row(0, ['pos' => 1, 'level' => 2, 'flags' => [['type' => 'gap', 'after_pos' => '1.2']]]);
check($r['level'] === 2 && $r['explicit_level'] === TRUE && $r['flags'][0]['after_pos'] === '1.2', 'явный уровень и пропуск «1.2»');

// ---- link_tree: родитель по номеру в том же файле, иначе по явному уровню
$tree = Service_Prx400_Pipeline::link_tree([
    ['pos_path' => '1', 'level' => 0, 'file_no' => 1],
    ['pos_path' => '1.1', 'level' => 1, 'file_no' => 1],
    ['pos_path' => '1.1.1', 'level' => 2, 'file_no' => 1],
    ['pos_path' => '2', 'level' => 0, 'file_no' => 1],
    ['pos_path' => '1.2', 'level' => 1, 'file_no' => 2], // другой файл — свой «1» не найден
    ['pos_path' => '', 'level' => 0, 'explicit_level' => TRUE, 'file_no' => 3],
    ['pos_path' => '', 'level' => 1, 'explicit_level' => TRUE, 'file_no' => 3],
]);
check(array_column($tree, 'parent_seq') === [NULL, 0, 1, NULL, NULL, NULL, 5], 'дерево: '.json_encode(array_column($tree, 'parent_seq')));
check( ! array_key_exists('explicit_level', $tree[6]), 'служебный ключ убран');

// ---- merge_decisions: подтверждение и ручной код переезжают, ручные строки сохраняются
$base = ['raw_name' => 'Болт М16х60', 'code_doc' => 'ГОСТ 7798', 'qty' => 4.0, 'unit' => 'шт', 'unit_price' => NULL, 'total_price' => NULL,
    'flags' => [], 'match' => NULL, 'status' => 'new', 'verified' => FALSE, 'origin' => 'parsed', 'verified_by' => NULL];
$old = [
    array_merge($base, ['verified' => TRUE, 'verified_by' => -5, 'match' => ['code' => 'NSI-1', 'manual' => TRUE, 'score' => 1.0]]),
    array_merge($base, ['raw_name' => 'Шайба 16', 'verified' => TRUE, 'verified_by' => -5]),
    array_merge($base, ['raw_name' => 'Добавлено вручную', 'origin' => 'manual']),
];
$merged = Service_Prx400_Pipeline::merge_decisions([array_merge($base, ['raw_name' => 'Болт  M16x60'])], $old);
check($merged['inherited'] === 1 && $merged['lost'] === 1 && $merged['kept'] === 1, json_encode(array_diff_key($merged, ['rows' => 1])));
check($merged['rows'][0]['verified'] === TRUE && $merged['rows'][0]['match']['code'] === 'NSI-1' && $merged['rows'][0]['status'] === 'ok');
check($merged['rows'][1]['raw_name'] === 'Добавлено вручную');

// ---- Экспорт иерархии и закупочная ведомость: сборки не закупаются, per_parent умножает
$bom = [
    ['id' => 10, 'seq' => 0, 'pos' => 1, 'pos_path' => '1', 'level' => 0, 'parent_id' => NULL, 'raw_name' => 'Узел', 'code_doc' => 'КБ20.01',
     'qty' => 2.0, 'unit' => 'шт', 'unit_price' => NULL, 'total_price' => NULL, 'flags' => [], 'match' => NULL, 'status' => 'new', 'verified' => FALSE, 'section' => ''],
    ['id' => 11, 'seq' => 1, 'pos' => 1, 'pos_path' => '1.1', 'level' => 1, 'parent_id' => 10, 'raw_name' => 'Болт М16х60', 'code_doc' => '',
     'qty' => 4.0, 'unit' => 'шт.', 'unit_price' => 10.0, 'total_price' => 40.0, 'flags' => [],
     'match' => ['code' => 'NSI-1', 'name' => 'Болт М16х60 ГОСТ 7798', 'unit' => 'шт', 'score' => 0.97], 'status' => 'ok', 'verified' => FALSE, 'section' => ''],
    ['id' => 12, 'seq' => 2, 'pos' => 2, 'pos_path' => '2', 'level' => 0, 'parent_id' => NULL, 'raw_name' => 'Болт M16x60', 'code_doc' => '',
     'qty' => 1.0, 'unit' => 'шт', 'unit_price' => 10.0, 'total_price' => 10.0, 'flags' => [],
     'match' => ['code' => 'NSI-1', 'name' => 'Болт М16х60 ГОСТ 7798', 'unit' => 'шт', 'score' => 1.0, 'manual' => TRUE], 'status' => 'ok', 'verified' => TRUE, 'section' => ''],
];
$agg = Service_Prx400_Export::aggregate($bom);
check(count($agg) === 1 && $agg[0]['catalog_code'] === 'NSI-1' && $agg[0]['quantity'] === 5.0 && $agg[0]['rows'] === 2, json_encode($agg, JSON_UNESCAPED_UNICODE));
check($agg[0]['positions'] === ['1.1', '2'] && $agg[0]['total_price'] === 50.0 && $agg[0]['warnings'] === []);
$agg = Service_Prx400_Export::aggregate($bom, 'per_parent');
check($agg[0]['quantity'] === 9.0, 'per_parent: 4 × 2 узла + 1');
check_error(static fn () => Service_Prx400_Export::aggregate($bom, 'x'), 'invalid_bom_mode', 400);
$pos = Service_Prx400_Export::json($spec, $bom)['positions'];
check($pos[1]['position'] === '1.1' && $pos[1]['level'] === 1 && $pos[1]['parent_row_id'] === 10 && $pos[2]['match_via'] === 'manual');
$hitl = Service_Prx400_Export::position($bom[1], TRUE);
check($hitl['status'] === 'ok' && $hitl['verified'] === FALSE && is_array($hitl['candidates']));

// ---- XLSX: три листа, читается общим ридером, формулы из скана — текст
$xlsx = Service_Prx400_Export::xlsx($spec, $bom);
$tmp = tempnam(sys_get_temp_dir(), 'prx400x');
file_put_contents($tmp, $xlsx);
$sheet = Prx_Spreadsheet::read($tmp, 100);
unlink($tmp);
$cells = json_encode($sheet, JSON_UNESCAPED_UNICODE);
check(strpos($cells, 'Наименование') !== FALSE && strpos($cells, 'Узел') !== FALSE, 'лист «Позиции» читается');
check(substr($xlsx, 0, 2) === 'PK', 'zip-контейнер');

// ---- Пакетные/строковые правила HITL без БД
check(Service_Prx400_Rows::passes(['status' => 'review', 'verified' => FALSE], 'disputed'));
check( ! Service_Prx400_Rows::passes(['status' => 'review', 'verified' => TRUE], 'disputed'), 'подтверждённая — не спорная');
check(Service_Prx400_Rows::label(['pos_path' => '1.2', 'raw_name' => 'Болт']) === '1.2 · Болт');
check(Service_Prx400_Rows::fields(['qty' => '1 234,5', 'unit_price' => ''])['qty'] === 1234.5);
check_error(static fn () => Service_Prx400_Rows::fields(['qty' => 'abc']), 'qty_invalid', 400);
check_error(static fn () => Service_Prx400_Rows::fields(['total_price' => '1e5']), 'price_invalid', 400);
check(Service_Prx400_Rows::subtree_end(array_map(static fn (array $r): array => $r + ['parent_id' => NULL], [
    ['id' => 1, 'seq' => 0], ['id' => 2, 'seq' => 1, 'parent_id' => 1], ['id' => 3, 'seq' => 2, 'parent_id' => 2], ['id' => 4, 'seq' => 3],
]), ['id' => 1, 'seq' => 0]) === 2, 'вставка «после сборки» — за всем её составом');
check(Prx400_Messages::warning('file_2_header_not_found') !== 'file_2_header_not_found', 'пропущенный файл объяснён');

echo "OK prx400_match_export\n";
