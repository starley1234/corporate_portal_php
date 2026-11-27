<?php
/** Batch-граница prx392 → prx391: минимальная проекция, без N+1, SQL injection и IO для пустого набора. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
class Database {
    const SELECT = 1;
    public static array $calls = [];
    public static array $rows = [];
    public static function instance() { return new self; }
    public function quote($value) { return "'".str_replace("'", "''", (string) $value)."'"; }
    public function query($type, $sql, $objects) {
        self::$calls[] = $sql;
        return new ArrayIterator(self::$rows);
    }
}
require __DIR__.'/fixtures/db_bridge.php';
$root = dirname(__DIR__, 2);
require $root.'/application/modules/prx391_documents/classes/Service/Prx391/Registry.php';
require $root.'/application/modules/prx391_documents/classes/Service/Prx391/Documents.php';
$checks = 0;
function header_check($actual, $expected) {
    global $checks;
    if ($actual !== $expected) throw new RuntimeException('Header contract mismatch: '.var_export($actual, TRUE));
    $checks++;
}
header_check(Service_Prx391_Documents::headers_for_processing([]), []);
header_check(Service_Prx391_Documents::headers_for_processing([0, -1, NULL, TRUE, 1.5, '1 OR 1=1', 'no']), []);
header_check(Database::$calls, []);
Database::$rows = [['id' => '7', 'reg_no' => 'DOC-7', 'subject' => 'Тест']];
header_check(Service_Prx391_Documents::headers_for_processing(['7', 7, 99, 'invalid']),
    [7 => ['id' => 7, 'reg_no' => 'DOC-7', 'subject' => 'Тест']]);
header_check(count(Database::$calls), 1);
header_check(Database::$calls[0], 'SELECT id, reg_no, subject FROM prx_391_documents WHERE id IN (7,99)');
echo "Analysis headers: $checks checks OK\n";
