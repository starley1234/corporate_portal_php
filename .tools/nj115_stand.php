<?php
/* ---------------------------------------------------------------------------
   Стенд nj115_semantic: покрытие источников, догон по хешу, уборка мусора,
   порог/гибрид поиска, кэш векторов запроса.

   Прогоняется НАСТОЯЩИЙ код модуля (Service_Nj115_Semantic) и НАСТОЯЩИЙ конфиг
   (application/config/nj115.php); база — записывающая заглушка: PHP строит SQL,
   а сами SQL исполняет живой PostgreSQL (.tools/nj115_pg_stand.py).

   Запуск (нужен node + @php-wasm/node; в песочнице это /tmp/phpwasm):
     R=/path/to/n2j.ru; H=/tmp/phpwasm
     cd $H && node harness.mjs \
       "{\"/nj115_config.php\":\"$R/application/config/nj115.php\",\
         \"/nj115_semantic.php\":\"$R/application/modules/nj115_semantic/classes/Service/Nj115/Semantic.php\"}" \
       "$(cat $R/.tools/nj115_stand.php)" '{\"/nj115_sql.json\":\"/tmp/nj115_sql.json\"}'
     python3 $R/.tools/nj115_pg_stand.py     # тот же SQL на живом PostgreSQL 16

   Правите сервис/конфиг/миграцию — прогоните стенд: он скажет словами, что
   сломалось («ПЛОХО …»), вместо пятисотки в браузере.
   --------------------------------------------------------------------------- */
/* Стенд 60/3-6: покрытие источников, догон, уборка мусора, порог и гибрид поиска,
   кэш векторов запроса — на НАСТОЯЩЕМ коде Service_Nj115_Semantic (файл монтируется
   из репозитория) и НАСТОЯЩЕМ конфиге application/config/nj115.php.

   База — записывающий стенд: PHP строит SQL, а сами SQL проверяются на живом
   PostgreSQL отдельным стендом (sql_to_pg.py, файл /nj115_sql.json). */
define('SYSPATH', '/app');

class Log {
    const ERROR = 'error'; const WARNING = 'warning'; const INFO = 'info';
    public static $messages = [];
    public static function instance(){ return new self; }
    public function add($l, $m, $p = []){ self::$messages[] = $m; }
}
class Kohana {
    public static $log; public static $config; public static $charset = 'utf-8';
    const DEVELOPMENT = 'development'; public static $environment = 'production';
}
Kohana::$log = new Log;

$REAL_CONFIG = require '/nj115_config.php';   /* реальный application/config/nj115.php */

class Cfg {
    public $root;
    public function __construct($nj115){
        $this->root = [
            'nj115' => $nj115,
            'llm'   => ['embedding' => ['model' => 'stub-embed', 'dimensions' => 3, 'batch_size' => 2]],
        ];
    }
    public function load($key){
        $node = $this->root;
        foreach (explode('.', (string) $key) as $part) {
            if ( ! is_array($node) OR ! array_key_exists($part, $node)) return NULL;
            $node = $node[$part];
        }
        return $node;
    }
}
Kohana::$config = new Cfg($REAL_CONFIG);

/* Эмбеддинг-сервер: считаем вызовы — по ним видно, работает ли кэш запросов. */
class Llm {
    public static $calls = 0;
    public static $vector = [0.1, 0.2, 0.3];
    public static function get_embedding($text){ self::$calls++; return self::$vector; }
}

class FakeResult implements IteratorAggregate, Countable {
    public $rows; public $single;
    public function __construct($rows = [], $single = NULL){ $this->rows = $rows; $this->single = $single; }
    public function current(){ return $this->single; }
    public function getIterator(): Traversable { return new ArrayIterator($this->rows); }
    public function as_array(){ return $this->rows; }
    public function count(): int { return count($this->rows); }
}

class Database {
    const SELECT = 1; const UPDATE = 2; const INSERT = 3; const DELETE = 4;

    public static $sql = [];             // все запросы: их проверит живой PostgreSQL
    public static $types = [];           // тип запроса (SELECT/DELETE…) — для «уборки»
    public static $fail_on = NULL;       // подстрока: на ней стенд «роняет» источник
    public static $counts = [];          // ответ запроса покрытия
    public static $pending = [];         // строки «догнать»
    public static $stored = [];          // уже лежащие векторы (хеши) для pending_items
    public static $orphans = 0;          // count(*) мусора
    public static $vector_rows = [];     // результат векторного поиска
    public static $text_rows = [];       // результат поиска по тексту (гибрид)
    public static $cache = [];           // nj_115_query_cache: qhash => json вектора
    public static $cache_hits = 0;       // сколько раз кэш отдал вектор
    public static $no_hash = 4;          // векторов без хеша (миграция 002)
    public static $fail_no_hash = FALSE; // «колонки content_hash нет»
    public static $fail_reindex = FALSE; // «источник догона недоступен»
    public static $counts_health = NULL; // ответ счётчиков health
    public static $fail_counts = FALSE;  // «таблиц нет»
    public static $search_rows = [];      // журнал поисков: последние запросы
    public static $search_stat = NULL;    // журнал поисков: сводка за сутки
    public static $fail_search = FALSE;   // «таблицы журнала нет» (до миграции)

    public static function instance($g = NULL){ return new self; }
    public function quote($v){ return "'".str_replace("'", "''", (string) $v)."'"; }

    public function query($type, $sql, $as = TRUE)
    {
        self::$sql[] = $sql;
        self::$types[] = $type;
        $low = stripos($sql, 'WITH src AS (') !== FALSE;

        if (self::$fail_on !== NULL AND stripos($sql, self::$fail_on) !== FALSE) {
            throw new Exception('стенд: источник недоступен');
        }

        // --- счётчики health(): до общей ветки про коллекции, иначе вернём не то
        if (stripos($sql, ') AS collections') !== FALSE) {
            if (self::$fail_counts) throw new Exception('стенд: таблиц nj_115_* нет');
            return new FakeResult([], self::$counts_health ?: ['collections' => 1, 'vectors' => 97]);
        }

        // --- колонка content_hash (миграция 002)
        if (stripos($sql, 'COUNT(*) AS c FROM nj_115_vectors WHERE content_hash') !== FALSE) {
            if (self::$fail_no_hash) throw new Exception('стенд: column "content_hash" does not exist');
            return new FakeResult([], ['c' => self::$no_hash]);
        }

        // --- источники
        if ($low AND stripos($sql, 'AS fresh') !== FALSE) {
            return new FakeResult([], self::$counts);
        }
        if (stripos($sql, 'WHERE v.id IS NULL OR v.content_hash') !== FALSE) {
            if (self::$fail_reindex) throw new Exception('стенд: relation "nj_560_docs" does not exist');
            return new FakeResult(self::$pending, NULL);
        }
        if (stripos($sql, 'COUNT(*) AS cnt') !== FALSE AND stripos($sql, 'nj_115_vectors v') !== FALSE) {
            return new FakeResult([], ['cnt' => self::$orphans]);
        }

        // --- журнал поисков (nj_115_searches, миграция 003)
        if (stripos($sql, 'nj_115_searches') !== FALSE) {
            if (self::$fail_search) throw new Exception('стенд: relation "nj_115_searches" does not exist');

            if (stripos($sql, 'COUNT(*) AS total') !== FALSE AND stripos($sql, 'FILTER') !== FALSE) {
                return new FakeResult([], self::$search_stat ?: ['total' => 0, 'empty' => 0, 'errors' => 0, 'cached' => 0, 'avg_ms' => 0, 'last_at' => NULL]);
            }
            if (stripos($sql, 'SELECT collection, entity, query') !== FALSE) {
                return new FakeResult(self::$search_rows, NULL);
            }
            if (stripos($sql, 'INSERT INTO nj_115_searches') !== FALSE) {
                return new FakeResult([], NULL);
            }
            if (stripos($sql, 'DELETE FROM nj_115_searches') !== FALSE) {
                return new FakeResult([], NULL);
            }
        }

        // --- кэш векторов запроса
        if (stripos($sql, 'FROM nj_115_query_cache WHERE qhash') !== FALSE) {
            if (preg_match("/qhash = '([0-9a-f]{32})'/", $sql, $m) AND isset(self::$cache[$m[1]])) {
                self::$cache_hits++;
                return new FakeResult([], ['vector' => self::$cache[$m[1]]]);
            }
            return new FakeResult([], NULL);
        }
        if (stripos($sql, 'INSERT INTO nj_115_query_cache') !== FALSE) {
            if (preg_match("/VALUES \('([0-9a-f]{32})'/", $sql, $m) AND preg_match("/'(\[[^\]]*\])'::jsonb/", $sql, $v)) {
                self::$cache[$m[1]] = $v[1];
            }
            return new FakeResult([], NULL);
        }
        if (stripos($sql, 'UPDATE nj_115_query_cache SET hits') !== FALSE) {
            return new FakeResult([], NULL);
        }

        // --- поиск
        if (stripos($sql, 'v.content ILIKE') !== FALSE) {
            return new FakeResult(self::$text_rows, NULL);
        }
        if (stripos($sql, 'nj_115_vec_dist') !== FALSE OR stripos($sql, '<=>') !== FALSE) {
            return new FakeResult(self::$vector_rows, NULL);
        }

        // --- векторы конкретных записей (pending_items)
        if (stripos($sql, 'FROM nj_115_vectors v') !== FALSE AND stripos($sql, 'v.entity_id IN (') !== FALSE) {
            return new FakeResult(self::$stored, NULL);
        }
        if (stripos($sql, 'FROM nj_115_collections') !== FALSE) {
            return new FakeResult([], ['id' => 115]);
        }

        return new FakeResult([], NULL);
    }

    public static function reset(){ self::$sql = []; self::$types = []; }
}

/* Очередь nj120 — стенд: пишем в неё, по-настоящему ничего не запускаем. */
class Service_Nj120_JobQueue {
    public static $pushed = [];
    public static function push($channel, $payload, $opts = [])
    {
        self::$pushed[] = ['channel' => $channel, 'items' => count($payload['items'] ?? [])];
        return 900 + count(self::$pushed);
    }
}

putenv('EMBEDDING_API_URL=https://embed.local/v1');

require '/nj115_semantic.php';     /* настоящий Service_Nj115_Semantic */

$FAIL = 0; $CHECKS = 0;

/* Снимки ключевых запросов: их прогоняет живой PostgreSQL (sql_to_pg.py). */
$SQL_COVERAGE = ''; $SQL_REINDEX = ''; $SQL_PRUNE_COUNT = ''; $SQL_PRUNE_DELETE = '';
function check($label, $cond, $extra = '')
{
    global $FAIL, $CHECKS;
    $CHECKS++;
    if ( ! $cond) $FAIL++;
    echo ($cond ? 'ОК   ' : 'ПЛОХО')." $label".($extra !== '' ? " :: $extra" : '')."\n";
}
function sql_all(){ return implode("\n", Database::$sql); }

echo "== Источники (реестр из application/config/nj115.php) ==\n";
$src = Service_Nj115_Semantic::sources();
check('источник nj560_knowledge найден', isset($src['nj560_knowledge']), implode(',', array_keys($src)));
check('коллекция и сущность как у nj560',
    ($src['nj560_knowledge']['collection'] ?? '') === 'nj560_knowledge'
    AND ($src['nj560_knowledge']['entity'] ?? '') === 'doc');
check('SQL источника — по таблице nj_560_docs', stripos($src['nj560_knowledge']['sql'] ?? '', 'FROM nj_560_docs') !== FALSE);
check('текст собирается как title + перевод строки + content',
    stripos($src['nj560_knowledge']['sql'] ?? '', "d.title || E'\\n' || d.content") !== FALSE);

echo "\n== Покрытие: свежий / изменённый / удалённый текст ==\n";
Database::$counts = ['total' => 100, 'have' => 97, 'fresh' => 90, 'orphans' => 3];
Database::reset();
$cov = Service_Nj115_Semantic::coverage();
$row = $cov[0] ?? [];
check('одна строка на источник', count($cov) === 1);
check('всего записей 100', ($row['total'] ?? -1) === 100);
check('векторов 97', ($row['have'] ?? -1) === 97);
check('актуальных (хеш совпал) 90', ($row['fresh'] ?? -1) === 90);
check('без вектора: 100−97 = 3', ($row['missing'] ?? -1) === 3, (string) ($row['missing'] ?? '?'));
check('текст изменился: 97−90 = 7', ($row['stale'] ?? -1) === 7, (string) ($row['stale'] ?? '?'));
check('мусор (записи больше нет) 3', ($row['orphans'] ?? -1) === 3);
check('покрытие 90,0 %', ($row['coverage'] ?? NULL) === 90.0, var_export($row['coverage'] ?? NULL, TRUE));
check('ошибки источника нет', ($row['error'] ?? 'X') === '');
$first = $SQL_COVERAGE = Database::$sql[0] ?? '';
check('SQL считает свежесть через md5 текста', stripos($first, 'md5(src.content)') !== FALSE);
check('запрос источника встроен как WITH src', stripos($first, 'WITH src AS (') !== FALSE);
check('векторы сопоставляются по entity_id как тексту', stripos($first, 'src.id::text = vec.entity_id') !== FALSE);

echo "\n== Догон: в очередь уходит только изменившееся ==\n";
Database::$pending = [
    ['id' => '12', 'content' => 'текст 12', 'metadata' => '{"kind":"note"}'],
    ['id' => '13', 'content' => 'текст 13', 'metadata' => ['kind' => 'faq']],
];
Database::$stored = [['entity_id' => '13', 'content_hash' => md5('текст 13')]];   // 13 уже актуален
Service_Nj120_JobQueue::$pushed = [];
Database::reset();

$re = Service_Nj115_Semantic::reindex(NULL, 200);
check('источник отдал 2 отставших текста', $re['items'] === 2, (string) $re['items']);
check('в очередь поставлен 1 (второй уже актуален)', $re['queued'] === 1, json_encode($re, JSON_UNESCAPED_UNICODE));
check('пропущен ровно 1', $re['skipped'] === 1);
check('одна задача на пачку (batch_size 2)', $re['jobs'] === 1, (string) $re['jobs']);
check('задача ушла в канал semantic.index', (Service_Nj120_JobQueue::$pushed[0]['channel'] ?? '') === 'semantic.index');
check('источник в отчёте — nj560_knowledge', ($re['sources'][0]['key'] ?? '') === 'nj560_knowledge');
foreach (Database::$sql as $q) { if (stripos($q, 'WHERE v.id IS NULL OR v.content_hash') !== FALSE) $SQL_REINDEX = $q; }

$sel = sql_all();
check('SELECT догона сравнивает хеш, а не только наличие вектора', stripos($sel, 'v.content_hash <> md5(src.content)') !== FALSE);
check('SELECT догона ограничен LIMIT 200', stripos($sel, 'LIMIT 200') !== FALSE);

Database::reset();
Service_Nj115_Semantic::reindex(NULL, 99999);
check('потолок догона: за один проход не больше 1000 записей источника',
    stripos(sql_all(), 'LIMIT 1000') !== FALSE, substr(sql_all(), -120));

Service_Nj120_JobQueue::$pushed = [];
$force = Service_Nj115_Semantic::reindex(NULL, 200, ['force' => TRUE]);
check('force игнорирует хеши: в очередь 2', $force['queued'] === 2, json_encode($force, JSON_UNESCAPED_UNICODE));

Database::$fail_reindex = TRUE;
Database::reset();
Service_Nj120_JobQueue::$pushed = [];
$broken = Service_Nj115_Semantic::reindex(NULL, 200);
check('сломанный источник догона не роняет кнопку: причина в отчёте',
    ($broken['errors'] ?? 0) === 1 AND $broken['queued'] === 0
    AND strpos((string) ($broken['sources'][0]['error'] ?? ''), 'nj_560_docs') !== FALSE,
    json_encode($broken, JSON_UNESCAPED_UNICODE));
check('и ни одной задачи в очередь не поставлено', Service_Nj120_JobQueue::$pushed === []);
Database::$fail_reindex = FALSE;

echo "\n== Уборка мусора ==\n";
Database::$orphans = 3;
Database::reset();
$gone = Service_Nj115_Semantic::prune(NULL, 7);
check('удалено 3 мусорных вектора', $gone === 3, (string) $gone);
$deletes = [];
foreach (Database::$types as $i => $t) {
    if (stripos(Database::$sql[$i], 'COUNT(*) AS cnt') !== FALSE) $SQL_PRUNE_COUNT = Database::$sql[$i];
    if ($t === Database::DELETE) { $deletes[] = Database::$sql[$i]; $SQL_PRUNE_DELETE = Database::$sql[$i]; }
}
check('DELETE выполнен ровно один раз', count($deletes) === 1, (string) count($deletes));
check('удаляем только то, чего нет в источнике', stripos($deletes[0] ?? '', 'NOT EXISTS (SELECT 1 FROM (SELECT d.id') !== FALSE);
check('предохранитель по времени: updated_at < now() − 7 дней',
    stripos($deletes[0] ?? '', 'updated_at < now()') !== FALSE AND stripos($deletes[0] ?? '', "interval '1 day'") !== FALSE);
check('уборка ограничена своей коллекцией и сущностью',
    stripos($deletes[0] ?? '', "'nj560_knowledge'") !== FALSE AND stripos($deletes[0] ?? '', "'doc'") !== FALSE);

Database::$orphans = 0;
Database::reset();
check('нет мусора — DELETE не выполняется', Service_Nj115_Semantic::prune(NULL, 7) === 0,
    json_encode(Database::$types));

echo "\n== Источник недоступен: честная ошибка, а не «100 %» ==\n";
Database::$fail_on = 'WITH src AS (';
$cov = Service_Nj115_Semantic::coverage();
check('строка источника в ответе осталась', count($cov) === 1);
check('покрытие не выдумано (NULL)', array_key_exists('coverage', $cov[0]) AND $cov[0]['coverage'] === NULL, var_export($cov[0]['coverage'] ?? 'X', TRUE));
check('причина попала в ответ', strpos((string) ($cov[0]['error'] ?? ''), 'источник недоступен') !== FALSE);
check('И покрытие, и мусор обнулены (панель не покажет ложные 100 %)',
    $cov[0]['total'] === 0 AND $cov[0]['have'] === 0 AND $cov[0]['orphans'] === 0);
check('уборка мусора при недоступном источнике не падает', Service_Nj115_Semantic::prune(NULL, 7) === 0);
Database::$fail_on = NULL;

echo "\n== health(): что показать владельцу ==\n";
Database::$counts = ['total' => 100, 'have' => 97, 'fresh' => 90, 'orphans' => 3];
Database::$counts_health = ['collections' => 1, 'vectors' => 97, 'no_hash' => 4];
$health = Service_Nj115_Semantic::health();
check('модуль считает себя рабочим (таблицы есть, сервер задан)', $health['ok'] === TRUE);
check('движок определён (real[] без pgvector)', $health['engine'] === 'real[]', $health['engine']);
check('покрытие источников внутри health', count($health['coverage']) === 1);
check('худшее покрытие 90 %', $health['coverage_min'] === 90.0, var_export($health['coverage_min'], TRUE));
check('векторов без хеша видно отдельно', $health['no_hash'] === 4, (string) $health['no_hash']);
$problems = implode(' | ', $health['problems']);
check('проблема «проиндексировано 90 %» объяснена', strpos($problems, 'проиндексировано 90') !== FALSE);
check('сказано, сколько без векторов и сколько устарело',
    strpos($problems, 'без векторов 3') !== FALSE AND strpos($problems, 'устарело 7') !== FALSE, $problems);
check('проблема «мусор» объяснена', strpos($problems, 'мусорных векторов 3') !== FALSE);
check('проблема «векторов без хеша» объяснена', strpos($problems, 'без хеша текста: 4') !== FALSE);

Database::$counts_health = NULL;
Database::$fail_no_hash = TRUE;
$health = Service_Nj115_Semantic::health();
$problems = implode(' | ', $health['problems']);
check('нет колонки content_hash → «примените миграции», а не «таблиц нет»',
    $health['hash_column'] === FALSE AND $health['tables'] === TRUE
    AND strpos($problems, 'колонки content_hash нет') !== FALSE, $problems);
check('модуль при этом всё ещё рабочий (векторы есть, хешей нет)', $health['ok'] === TRUE AND $health['vectors'] === 97);
Database::$fail_no_hash = FALSE;

Database::$fail_counts = TRUE;
$health = Service_Nj115_Semantic::health();
check('нет таблиц → своя причина «выполните миграции»',
    $health['tables'] === FALSE AND $health['hash_column'] === TRUE
    AND strpos(implode(' | ', $health['problems']), 'таблиц nj_115_* нет') !== FALSE);
check('без таблиц health не считает себя рабочим', $health['ok'] === FALSE);
Database::$fail_counts = FALSE;

putenv('EMBEDDING_API_URL=');
$health = Service_Nj115_Semantic::health();
check('без EMBEDDING_API_URL модуль честно не «ok»', $health['ok'] === FALSE);
check('подсказка про EMBEDDING_API_URL есть', strpos(implode(' | ', $health['problems']), 'EMBEDDING_API_URL не задан') !== FALSE);
putenv('EMBEDDING_API_URL=https://embed.local/v1');

echo "\n== Поиск: порог расстояния, гибрид, чипы ==\n";
check('настройки поиска из конфига', Service_Nj115_Semantic::search_config() === [
    'max_distance' => 0.6, 'hybrid' => TRUE, 'fulltext_min_length' => 3,
], json_encode(Service_Nj115_Semantic::search_config(), JSON_UNESCAPED_UNICODE));

Llm::$calls = 0; Database::$cache = []; Database::$cache_hits = 0; Database::reset();
Database::$vector_rows = [['entity' => 'doc', 'entity_id' => '7', 'content' => 'бюджет на квартал', 'metadata' => NULL, 'model' => 'stub-embed', 'distance' => '0.21']];
$res = Service_Nj115_Semantic::search('nj560_knowledge', 'бюджет');
check('векторный результат помечен match=vector', ($res[0]['match'] ?? '') === 'vector', json_encode($res, JSON_UNESCAPED_UNICODE));
check('расстояние приведено к числу', ($res[0]['distance'] ?? NULL) === 0.21);
check('порог из конфига попал в SQL', strpos(sql_all(), '<= 0.6') !== FALSE);
check('порог обёрнут вокруг расстояния (AND (…) <= 0.6), а не сравнение ORDER BY',
    stripos(sql_all(), 'AND (nj_115_vec_dist(') !== FALSE);
check('эмбеддинг запроса спрошен один раз', Llm::$calls === 1, (string) Llm::$calls);

$vec = Service_Nj115_Semantic::embed_query('бюджет');
check('повторный запрос — из кэша, без эмбеддинг-сервера', Llm::$calls === 1, (string) Llm::$calls);
check('кэш вернул тот же вектор', $vec === [0.1, 0.2, 0.3], json_encode($vec));
check('кэш отдал вектор (hits+1, used_at)', Database::$cache_hits === 1);
$sweep = sql_all();
check('уборка кэша по сроку: TTL 30 дней из конфига', strpos($sweep, "30 * interval '1 day'") !== FALSE);
check('уборка кэша по размеру: не больше 500 строк', strpos($sweep, 'OFFSET 500') !== FALSE);
check('кэш пишется с ключом md5(модель+запрос)', strpos($sweep, 'INSERT INTO nj_115_query_cache (qhash') !== FALSE);

Database::$vector_rows = []; Database::reset();
Database::$text_rows = [['entity' => 'doc', 'entity_id' => '9', 'content' => 'Бюджет: план на квартал и лимиты', 'metadata' => '{"kind":"note"}', 'model' => 'stub-embed']];
$res = Service_Nj115_Semantic::search('nj560_knowledge', 'бюджет');
check('векторный порог не прошёл → фолбэк по тексту', ($res[0]['match'] ?? '') === 'text', json_encode($res, JSON_UNESCAPED_UNICODE));
check('у текстового совпадения нет расстояния', array_key_exists('distance', $res[0]) AND $res[0]['distance'] === NULL);
check('метаданные-строка разобрана в массив', is_array($res[0]['metadata'] ?? NULL));
check('текстовый фолбэк ищет по ILIKE', stripos(sql_all(), 'v.content ILIKE') !== FALSE);

Database::$vector_rows = []; Database::$text_rows = [['entity' => 'doc', 'entity_id' => '9', 'content' => 'Бюджет', 'metadata' => NULL, 'model' => 'stub-embed']];
$res = Service_Nj115_Semantic::search('nj560_knowledge', 'бюджет', 10, NULL, ['hybrid' => FALSE]);
check('hybrid = FALSE — фолбэка нет, пусто', $res === [], json_encode($res));

Database::reset();
check('запрос короче 3 символов по тексту не ищем', Service_Nj115_Semantic::fulltext_search('nj560_knowledge', 'ок') === []);
Database::reset();
Service_Nj115_Semantic::fulltext_search('nj560_knowledge', '50%_тест');
check('в ILIKE экранированы % и _', stripos(sql_all(), '50\\%\\_тест') !== FALSE, sql_all());

Database::reset();
Database::$text_rows = [];
Service_Nj115_Semantic::fulltext_search('nj560_knowledge', 'бюджет', 10, 'doc');
check('фильтр по сущности попадает в текстовый поиск', stripos(sql_all(), "AND v.entity = 'doc'") !== FALSE, sql_all());
Database::$text_rows = [];

echo "\n== Журнал поисков: что искали и что нашлось ==\n";
$SQL_SEARCH_INS = ''; $SQL_SEARCH_SEL = ''; $SQL_SEARCH_STATS = ''; $SQL_SEARCH_SWEEP = '';

Database::reset();
Service_Nj115_Semantic::log_search('nj560_knowledge', "бюджет на 'квартал'", [
    ['entity' => 'doc', 'entity_id' => '1', 'match' => 'vector'],
    ['entity' => 'doc', 'entity_id' => '2', 'match' => 'text'],
], ['entity' => 'doc', 'ms' => 42, 'cached' => FALSE, 'user_id' => 7]);

$ins = '';
foreach (Database::$sql as $q) { if (stripos($q, 'INSERT INTO nj_115_searches') !== FALSE) { $ins = $q; $SQL_SEARCH_INS = $q; } }
check('запрос попал в журнал одной строкой', $ins !== '', substr(sql_all(), 0, 120));
$ins_flat = preg_replace('/\s+/', ' ', $ins);
check('журнал считает попадания: 1 по смыслу и 1 по словам', strpos($ins_flat, ' 2, 1, 1, ') !== FALSE, $ins_flat);
check('видно, что ответил не кэш', strpos($ins_flat, ' FALSE, ') !== FALSE);
check('время и автор записаны', strpos($ins_flat, ' 42, ') !== FALSE AND substr(rtrim($ins_flat), -2) === '7)', $ins_flat);
check('кавычки в запросе экранированы, инъекции нет', substr_count($ins, "''") >= 2 AND strpos($ins, "бюджет на ''квартал''") !== FALSE, $ins);
check('после записи журнал подрезается до searches.keep',
    stripos(sql_all(), 'DELETE FROM nj_115_searches') !== FALSE AND strpos(sql_all(), 'LIMIT 200') !== FALSE);
foreach (Database::$sql as $q) { if (stripos($q, 'DELETE FROM nj_115_searches') !== FALSE) $SQL_SEARCH_SWEEP = $q; }

Database::$search_stat = ['total' => 5, 'empty' => 2, 'errors' => 1, 'cached' => 1, 'avg_ms' => 33.4, 'last_at' => '2026-09-28 10:00:00'];
$stats = Service_Nj115_Semantic::search_stats(24);
check('сводка за сутки посчитана', $stats['total'] === 5 AND $stats['empty'] === 2 AND $stats['errors'] === 1 AND $stats['cached'] === 1, json_encode($stats));
check('среднее время округлено до миллисекунд', $stats['avg_ms'] === 33, (string) $stats['avg_ms']);
foreach (Database::$sql as $q) { if (stripos($q, 'COUNT(*) AS total') !== FALSE) $SQL_SEARCH_STATS = $q; }
check('окно сводки — сутки (24 * interval)', strpos($SQL_SEARCH_STATS, "24 * interval '1 hour'") !== FALSE);

$row = [
    'collection' => 'nj560_knowledge', 'entity' => 'doc', 'query' => 'бюджет', 'results' => 3,
    'vector_hits' => 3, 'text_hits' => 0, 'cached' => TRUE, 'ms' => 0, 'error' => '', 'created_at' => '2026-09-28 10:00:00',
];
Database::$search_rows = [$row];
Database::reset();
$recent = Service_Nj115_Semantic::recent_searches(10);
check('«последние запросы» читаются и типизируются',
    ($recent[0]['query'] ?? '') === 'бюджет' AND ($recent[0]['results'] ?? NULL) === 3 AND ($recent[0]['cached'] ?? NULL) === TRUE
    AND ($recent[0]['ms'] ?? NULL) === 0, json_encode($recent, JSON_UNESCAPED_UNICODE));
check('лимит «последних запросов» уходит в SQL', stripos(sql_all(), 'ORDER BY id DESC LIMIT 10') !== FALSE);
foreach (Database::$sql as $q) { if (stripos($q, 'SELECT collection, entity, query') !== FALSE) $SQL_SEARCH_SEL = $q; }

// Журнала ещё нет (миграция 003 не применена) — поиск обязан работать, панель открываться
Database::$fail_search = TRUE;
Database::reset();
Service_Nj115_Semantic::log_search('nj560_knowledge', 'бюджет', []);
check('нет таблицы журнала — запись не роняет поиск', TRUE);
check('«последние запросы» без таблицы — пустой список', Service_Nj115_Semantic::recent_searches(10) === []);
$st = Service_Nj115_Semantic::search_stats(24);
check('сводка без таблицы — нули, а не ошибка', $st['total'] === 0 AND $st['avg_ms'] === 0);
Database::$fail_search = FALSE;

// Панель показывает журнал и его сводку
Database::$search_rows = [$row];
Database::$search_stat = ['total' => 5, 'empty' => 2, 'errors' => 1, 'cached' => 1, 'avg_ms' => 33, 'last_at' => '2026-09-28 10:00:00'];
Database::$counts = ['total' => 0, 'have' => 0, 'fresh' => 0, 'orphans' => 0];
Database::$counts_health = ['collections' => 1, 'vectors' => 3];
Database::reset();
$panel = Service_Nj115_Semantic::panel();
check('панель отдаёт последние поиски', count($panel['searches'] ?? []) === 1, json_encode(array_keys($panel)));
check('панель отдаёт сводку за сутки', ($panel['search_stats']['total'] ?? 0) === 5);
check('панель не сломалась и отдаёт состояние модуля', isset($panel['health']['engine']) AND isset($panel['queue']));
Database::$search_rows = []; Database::$search_stat = NULL;

echo "\n".($FAIL === 0 ? "ВСЁ ОК" : "ПРОВАЛОВ: $FAIL")." (проверок: $CHECKS)\n";

/* SQL уходит на живой PostgreSQL отдельным стендом (sql_to_pg.py). */
file_put_contents('/nj115_sql.json', json_encode([
    'sources'    => $REAL_CONFIG['sources'],
    'coverage'   => $SQL_COVERAGE,
    'reindex'    => $SQL_REINDEX,
    'prune_cnt'  => $SQL_PRUNE_COUNT,
    'prune_del'  => $SQL_PRUNE_DELETE,
    'search_ins'    => $SQL_SEARCH_INS,
    'search_sel'    => $SQL_SEARCH_SEL,
    'search_stats'  => $SQL_SEARCH_STATS,
    'search_sweep'  => $SQL_SEARCH_SWEEP,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

exit(0);
