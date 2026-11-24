<?php
/** Отказы диагностических зависимостей не требуют embedding/сети/живой БД. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
class Database {
    const SELECT = 1;
    const INSERT = 2;
    const DELETE = 3;
    public static function instance() { return new self; }
    public function quote($value) { return "'".str_replace("'", "''", (string) $value)."'"; }
    public function query($type, $sql, $objects) { throw new RuntimeException('test database unavailable'); }
}
class Kohana { public static $config; }
Kohana::$config = new class {
    public function load($key) {
        if ($key === 'prx115.sources') return [
            'missing' => ['collection' => 'fixture', 'entity' => 'item', 'sql' => 'SELECT 1'],
            'invalid' => ['collection' => 'invalid'],
        ];
        return [];
    }
};
$root = dirname(__DIR__, 2).'/application/modules/prx115_semantic/classes/Service/Prx115/';
foreach (['SearchLog', 'Sources', 'Diagnostics'] as $class) require $root.$class.'.php';
$n = 0;
function semantic_check($actual, $expected) {
    global $n;
    if ($actual !== $expected) throw new RuntimeException('Semantic diagnostics mismatch: '.var_export($actual, TRUE));
    $n++;
}
Service_Prx115_SearchLog::log_search('fixture', 'query', []); // сбой записи подавляется как раньше
semantic_check(Service_Prx115_SearchLog::recent_searches(), []);
semantic_check(Service_Prx115_SearchLog::search_stats(), ['total' => 0, 'empty' => 0, 'errors' => 0, 'cached' => 0, 'avg_ms' => 0, 'last_at' => '']);
semantic_check(count(Service_Prx115_Sources::sources()), 1);
semantic_check(Service_Prx115_Sources::coverage('other'), []);
$coverage = Service_Prx115_Sources::coverage()[0];
semantic_check($coverage['coverage'], NULL);
semantic_check($coverage['error'], 'test database unavailable');
semantic_check($coverage['total'], 0);
$health = Service_Prx115_Diagnostics::health('real[]', ['model', 1024], ['hybrid' => TRUE]);
semantic_check($health['tables'], FALSE);
semantic_check($health['ok'], FALSE);
semantic_check($health['engine'], 'real[]');
semantic_check($health['embed_dims'], 1024);
semantic_check($health['coverage'], []);
$panel = Service_Prx115_Diagnostics::panel('real[]', ['model', 1024], []);
semantic_check($panel['collections'], []);
semantic_check($panel['totals'], ['collections' => 0, 'vectors' => 0, 'entities' => 0]);
semantic_check($panel['queue']['ok'], FALSE);
semantic_check($panel['searches'], []);
echo "Semantic diagnostics: $n checks OK\n";
