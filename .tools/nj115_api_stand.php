<?php
/* ---------------------------------------------------------------------------
   Стенд точек API модуля nj115_semantic: health, coverage, reindex, prune,
   index (пропуск по хешу), search (порог, гибрид и запись в журнал поисков).

   Прогоняется НАСТОЯЩИЙ Controller_Nj115_Api (авторизация, коды ответов,
   разбор тела) с заглушкой сервиса, которая ЗАПИСЫВАЕТ вызовы: проверяем и
   «что ответили», и «что передали в сервис».

   Запуск:
     R=/path/to/n2j.ru; H=/tmp/phpwasm
     cd $H && node harness.mjs \
       "{\"/nj115_api.php\":\"$R/application/modules/nj115_semantic/classes/Controller/Nj115/Api.php\",\
         \"/nj120_jobqueue.php\":\"$R/application/modules/nj120_jobs/classes/Service/Nj120/JobQueue.php\"}" \
       "$(cat $R/.tools/nj115_api_stand.php)"
   --------------------------------------------------------------------------- */
define('SYSPATH', '/app');

class Log {
    const ERROR='error'; const WARNING='warning'; const INFO='info';
    public static function instance(){ return new self; }
    public function add($l,$m,$p=[]){}
}
class Kohana {
    public static $log; public static $config; public static $charset='utf-8';
    const DEVELOPMENT='development'; public static $environment='production';
}
Kohana::$log = new Log;
class Cfg implements ArrayAccess {
    public $data;
    public function __construct($data = []){ $this->data = $data; }
    public function as_array(){ return $this->data; }
    public function get($k, $d = NULL){ return $this->data[$k] ?? $d; }
    public function offsetExists($o): bool { return isset($this->data[$o]); }
    #[\ReturnTypeWillChange] public function offsetGet($o){ return $this->data[$o] ?? NULL; }
    public function offsetSet($o,$v): void { $this->data[$o] = $v; }
    public function offsetUnset($o): void { unset($this->data[$o]); }
}
/* Config::load() в Kohana отдаёт массив (или объект-обёртку): для смока — массив,
   иначе (array) от объекта даёт ['data' => []] и лишние ветки. */
Kohana::$config = new class { public function load($k){ return []; } };

class Request {
    const GET='GET'; const POST='POST';
    public static $client_ip = '127.0.0.1';
    public $m='GET'; public $b=''; public $p=[]; public $a='status'; public $q=[]; public $h=[];
    public function method(){ return $this->m; }
    public function body(){ return $this->b; }
    public function post(){ return $this->p; }
    public function action(){ return $this->a; }
    public function query($k=NULL,$d=NULL){ return $k===NULL ? $this->q : (isset($this->q[$k]) ? $this->q[$k] : $d); }
    public function headers($k=NULL){ return $k===NULL ? $this->h : (isset($this->h[$k]) ? $this->h[$k] : NULL); }
}
class Response {
    public $s=200; public $b='';
    public function status($c=NULL){ if($c===NULL) return $this->s; $this->s=(int)$c; return $this; }
    public function body($x=NULL){ if($x===NULL) return $this->b; $this->b=$x; return $this; }
    public function headers($k=NULL,$v=NULL){ return $this; }
}
class HTTP_Exception_403 extends Exception {}
class Controller_Base {
    public $request; public $response; public $auto_render=TRUE;
    protected $auth_required = TRUE;
    protected $public_actions = [];
    /* Как в настоящем Controller_Base: без входа и без записи в public_actions
       запрос получает HTML-страницу 403 ещё до собственной проверки токена.
       Именно на этом сломался приём векторов (задача 2234, 23.10.2026). */
    public function before(){
        if ($this->auth_required AND ! in_array($this->request->action(), $this->public_actions, TRUE) AND ! Auth::$user) {
            throw new HTTP_Exception_403('Access Denied. Please login.');
        }
    }
}

class Roles_Empty { public function find_all(){ return []; } }
class U_Admin { public $id = 7; public function has_role($r){ return $r === 'admin'; } }
class U_User  { public $roles; public function __construct(){ $this->roles = new Roles_Empty; } }

class Auth {
    public static $user = NULL;
    public static function instance(){ return new self; }
    public function get_user(){ return self::$user; }
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
    const SELECT=1; const UPDATE=2; const INSERT=3; const DELETE=4;
    public static $queries = [];
    public static $job = NULL;            // строка nj_120_jobs для get()
    public static $last_id = 0;           // id из last_status
    public static $progress = [];         // события progress
    public static $stuck_count = 0;       // count(*) в maybe_maintain
    public static $lock = 't';
    public static $new_id = 1200;
    public static $inserts = [];
    public static function instance($g = NULL){ return new self; }
    public function quote($v){ return "'".str_replace("'", "''", (string) $v)."'"; }
    public function query($type, $sql, $as = TRUE)
    {
        self::$queries[] = $sql;
        $s = strtolower($sql);
        if (str_contains($s, 'pg_try_advisory_lock'))  return new FakeResult([], ['locked' => self::$lock]);
        if (str_contains($s, 'pg_advisory_unlock'))    return new FakeResult([], NULL);
        if (str_contains($s, 'count(*) as c from nj_120_jobs')) return new FakeResult([], ['c' => self::$stuck_count]);
        if (str_contains($s, 'from nj_120_jobs where id ='))    return new FakeResult([], self::$job);
        if (str_contains($s, 'from nj_120_jobs') && str_contains($s, 'order by id desc limit 1'))
            return new FakeResult([], self::$last_id > 0 ? ['id' => self::$last_id] : NULL);
        if (str_contains($s, 'from nj_120_job_events') && str_contains($s, "event = 'progress'"))
            return new FakeResult(array_reverse(self::$progress), NULL);   // SQL: ORDER BY id DESC
        if (str_contains($s, 'insert into nj_120_jobs') && str_contains($s, 'returning id')) {
            self::$inserts[] = $sql;
            return new FakeResult([], ['id' => self::$new_id]);
        }
        if (str_contains($s, 'insert into nj_120_job_events')) {
            self::$inserts[] = $sql;
            return new FakeResult([], NULL);
        }
        return new FakeResult([], NULL);
    }
}

class Service_Nj115_Semantic {
    public static $calls = [];
    public static $throw = NULL;
    public static $logged = [];        // что ушло в журнал поисков
    public static $embed_cached = FALSE; // «вектор запроса взяли из кэша»
    public static $health = [
        'ok' => TRUE, 'engine' => 'pgvector', 'tables' => TRUE,
        'embed_url' => 'https://embed.local/v1', 'embed_model' => 'e5', 'embed_dims' => 1024,
        'collections' => 2, 'vectors' => 97, 'no_hash' => 0,
        'coverage_min' => 90.0, 'coverage' => [['key' => 'nj560_knowledge']],
        'last_job' => NULL, 'problems' => [],
        'search' => ['max_distance' => 0.6, 'hybrid' => TRUE, 'fulltext_min_length' => 3],
    ];

    public static function _rec($what, $args = []){ self::$calls[] = [$what, $args]; }
    public static function _throw(){ if (self::$throw !== NULL) throw new Exception(self::$throw); }

    public static function health(){ self::_rec('health'); self::_throw(); return self::$health; }
    public static function coverage($c = NULL){ self::_rec('coverage', [$c]); self::_throw(); return [['key' => 'nj560_knowledge', 'coverage' => 90.0]]; }
    public static function reindex($c, $limit, $opts = []){
        self::_rec('reindex', [$c, $limit, $opts]); self::_throw();
        return ['items' => 2, 'queued' => 1, 'skipped' => 1, 'jobs' => 1, 'sources' => [['key' => 'nj560_knowledge']]];
    }
    public static function prune($c, $days){ self::_rec('prune', [$c, $days]); self::_throw(); return 3; }
    public static function queue_index($c, $e, $items, $opts = []){
        self::_rec('queue_index', [$c, $e, count($items), $opts]); self::_throw();
        return ['job_ids' => [71], 'queued' => 1, 'skipped' => 1];
    }
    public static function store_vectors($c, $e, $items, $model = NULL){
        self::_rec('store_vectors', [$c, $e, count($items), $model]); self::_throw();
        return count($items);
    }
    public static function search($c, $q, $limit = 10, $entity = NULL, $opts = []){
        self::_rec('search', [$c, $q, $limit, $entity, $opts]); self::_throw();
        return [['entity' => 'doc', 'entity_id' => '9', 'content' => 'бюджет', 'distance' => NULL, 'match' => 'text']];
    }
    public static function search_config(){ return ['max_distance' => 0.6, 'hybrid' => TRUE, 'fulltext_min_length' => 3]; }

    public static function last_embed_cached(){ return self::$embed_cached; }
    public static function log_search($c, $q, $results, $meta = []){
        self::$logged[] = ['collection' => $c, 'query' => $q, 'results' => count($results), 'meta' => $meta];
    }
}

require '/nj115_api.php';

$FAIL = 0; $CHECKS = 0;
function check($label, $cond, $extra = '')
{
    global $FAIL, $CHECKS;
    $CHECKS++;
    if ( ! $cond) $FAIL++;
    echo ($cond ? 'ОК   ' : 'ПЛОХО')." $label".($extra !== '' ? " :: $extra" : '')."\n";
}

function call($action, $opts = [])
{
    $c = new Controller_Nj115_Api;
    $c->request = new Request; $c->response = new Response;
    $c->request->a = $action;
    $c->request->m = $opts['method'] ?? 'GET';
    $c->request->b = isset($opts['body']) ? json_encode($opts['body'], JSON_UNESCAPED_UNICODE) : '';
    $c->request->q = $opts['query'] ?? [];
    $c->request->h = $opts['headers'] ?? [];

    Auth::$user = array_key_exists('user', $opts) ? $opts['user'] : new U_Admin;
    Service_Nj115_Semantic::$calls = [];

    try {
        $c->before();
        $method = 'action_'.$action;
        if (method_exists($c, $method)) $c->$method();
    } catch (HTTP_Exception_403 $e) {
        // Kohana отдаёт на такое исключение HTML-страницу, а не JSON.
        $c->response->status(403);
        $c->response->body('<html><body>Access Denied</body></html>');
    }

    return $c;
}
function j($c){ return json_decode($c->response->b, TRUE); }
function last_call(){ $calls = Service_Nj115_Semantic::$calls; return end($calls) ?: NULL; }

putenv('WORKER_API_TOKEN=s3cret');

/* --- health: два пути доступа ------------------------------------------- */
echo "== health: воркер по токену, админ по сессии ==\n";
$c = call('health', ['user' => NULL, 'headers' => ['x-worker-token' => 's3cret']]);
$b = j($c);
check('health: воркер с верным токеном → 200', $c->response->s === 200 AND ($b['ok'] ?? NULL) === TRUE, 'HTTP '.$c->response->s);
check('health: состав ответа (движок, покрытие, проблемы)',
    isset($b['health']['engine'], $b['health']['coverage'], $b['health']['problems'], $b['health']['search']),
    substr($c->response->b, 0, 160));

$c = call('health', ['user' => NULL, 'headers' => ['x-worker-token' => 'не тот']]);
check('health: чужой токен без входа → 403 с подсказкой про оба пути',
    $c->response->s === 403 AND strpos((string) (j($c)['error'] ?? ''), 'Нужен токен воркера') !== FALSE, 'HTTP '.$c->response->s);

$c = call('health', ['user' => new U_User]);
check('health: обычный пользователь → 403', $c->response->s === 403, 'HTTP '.$c->response->s);

$c = call('health');   // админ по сессии, без токена
check('health: админ по сессии → 200', $c->response->s === 200 AND (j($c)['ok'] ?? NULL) === TRUE, 'HTTP '.$c->response->s);

Service_Nj115_Semantic::$health['ok'] = FALSE;
Service_Nj115_Semantic::$health['problems'] = ['EMBEDDING_API_URL не задан'];
$c = call('health');
check('health: есть проблемы → 503 (мониторинг заметит)', $c->response->s === 503, 'HTTP '.$c->response->s);
check('health: причина видна в ответе', strpos($c->response->b, 'EMBEDDING_API_URL') !== FALSE);
Service_Nj115_Semantic::$health['ok'] = TRUE;
Service_Nj115_Semantic::$health['problems'] = [];

echo "\n== coverage: покрытие источников ==\n";
$c = call('coverage');
check('coverage: админ → 200 и список источников', $c->response->s === 200 AND count(j($c)['sources']) === 1, 'HTTP '.$c->response->s);
check('coverage: без ?collection спрашиваем все источники', last_call() === ['coverage', [NULL]], json_encode(last_call()));

$c = call('coverage', ['query' => ['collection' => 'nj560_knowledge']]);
check('coverage: ?collection доходит до сервиса', (last_call()[1][0] ?? 'X') === 'nj560_knowledge');

$c = call('coverage', ['user' => new U_User]);
check('coverage: не-админ → 403', $c->response->s === 403, 'HTTP '.$c->response->s);

echo "\n== reindex: кнопка «Догнать» ==\n";
$c = call('reindex', ['method' => 'POST', 'body' => ['collection' => 'nj560_knowledge', 'limit' => 50]]);
$b = j($c);
check('reindex: POST админа → 200', $c->response->s === 200 AND ($b['ok'] ?? NULL) === TRUE, 'HTTP '.$c->response->s);
check('reindex: сервис получил коллекцию и лимит', last_call() === ['reindex', ['nj560_knowledge', 50, []]], json_encode(last_call(), JSON_UNESCAPED_UNICODE));
check('reindex: в ответе items/queued/skipped/jobs', isset($b['items'], $b['queued'], $b['skipped'], $b['jobs']), $c->response->b);
$c = call('reindex', ['method' => 'POST', 'body' => ['limit' => 10]]);
check('reindex: без collection — все источники', last_call() === ['reindex', [NULL, 10, []]], json_encode(last_call()));

$c = call('reindex', ['method' => 'POST', 'body' => ['collection' => 'nj560_knowledge', 'force' => TRUE]]);
check('reindex: force доходит до сервиса (пересчитать всё)', (last_call()[1][2] ?? []) === ['force' => TRUE], json_encode(last_call(), JSON_UNESCAPED_UNICODE));
check('reindex: без limit берём 200 по умолчанию', (last_call()[1][1] ?? 0) === 200);

$c = call('reindex', ['method' => 'GET']);
check('reindex: GET запрещён (405)', $c->response->s === 405, 'HTTP '.$c->response->s);

$c = call('reindex', ['method' => 'POST', 'user' => new U_User, 'body' => []]);
check('reindex: не-админ → 403', $c->response->s === 403, 'HTTP '.$c->response->s);

echo "\n== prune: «Убрать мусор» ==\n";
$c = call('prune', ['method' => 'POST', 'body' => ['older_days' => 3]]);
$b = j($c);
check('prune: POST админа → 200 и число удалённых', $c->response->s === 200 AND ($b['deleted'] ?? NULL) === 3, $c->response->b);
check('prune: сервис получил срок 3 дня и «все коллекции»', last_call() === ['prune', [NULL, 3]], json_encode(last_call()));

$c = call('prune', ['method' => 'POST', 'body' => ['collection' => 'nj560_knowledge']]);
check('prune: срок по умолчанию — 7 дней (не удаляем свежее)', (last_call()[1][1] ?? 0) === 7, json_encode(last_call()));
check('prune: GET запрещён (405)', call('prune', ['method' => 'GET'])->response->s === 405);

echo "\n== index / search: хеши и порог ==\n";
$c = call('index', ['method' => 'POST', 'body' => [
    'collection' => 'nj560_knowledge', 'entity' => 'doc',
    'items' => [['id' => '1', 'text' => 'текст'], ['id' => '2']],
]]);
$b = j($c);
check('index: элемент без text отброшен до сервиса', (last_call()[1][2] ?? 0) === 1, json_encode(last_call(), JSON_UNESCAPED_UNICODE));
check('index: ответ несёт, сколько пропущено по хешу', ($b['skipped'] ?? NULL) === 1 AND ($b['queued'] ?? NULL) === 1, $c->response->b);

$c = call('index', ['method' => 'POST', 'body' => ['collection' => 'nj560_knowledge', 'entity' => 'doc', 'items' => [['id' => '1']]]]);
check('index: без текстов → 400', $c->response->s === 400, 'HTTP '.$c->response->s);

$c = call('search', ['query' => ['q' => 'бюджет', 'collection' => 'nj560_knowledge', 'max_distance' => '0.4', 'hybrid' => '1']]);
$b = j($c);
$opts = last_call()[1][4] ?? [];
check('search: порог из запроса доходит до сервиса', ($opts['max_distance'] ?? NULL) === 0.4, json_encode($opts));
check('search: hybrid из запроса доходит', ($opts['hybrid'] ?? NULL) === TRUE);
check('search: в ответе видны настройки поиска и способ совпадения',
    isset($b['search']['max_distance']) AND ($b['results'][0]['match'] ?? '') === 'text', substr($c->response->b, 0, 160));

echo "\n== ошибки сервиса → 500 с причиной (панель не молчит) ==\n";
Service_Nj115_Semantic::$throw = 'таблица nj_115_vectors не найдена';
$c = call('reindex', ['method' => 'POST', 'body' => []]);
check('reindex: ошибка сервиса → 500 и текст причины', $c->response->s === 500 AND strpos($c->response->b, 'не найдена') !== FALSE, 'HTTP '.$c->response->s);
$c = call('health');
check('health: ошибка сервиса → 500', $c->response->s === 500, 'HTTP '.$c->response->s);
$c = call('coverage');
check('coverage: ошибка сервиса → 500', $c->response->s === 500, 'HTTP '.$c->response->s);
Service_Nj115_Semantic::$throw = NULL;

echo "\n== приём векторов: воркер без сессии проходит (задача 2234) ==\n";
$c = call('vectors', ['user' => NULL, 'method' => 'POST',
    'headers' => ['x-worker-token' => 's3cret'],
    'body' => ['collection' => 'nj560_knowledge', 'entity' => 'doc', 'model' => 'e5',
               'items' => [['id' => '1', 'vector' => [0.1, 0.2], 'text' => 'бюджет']]]]);
$b = j($c);
check('vectors: токен воркера без сессии → 200 и JSON (а не HTML 403)',
    $c->response->s === 200 AND ($b['ok'] ?? NULL) === TRUE AND ($b['stored'] ?? 0) === 1,
    'HTTP '.$c->response->s.' :: '.substr($c->response->b, 0, 120));
check('vectors: векторы ушли в сервис с коллекцией и сущностью',
    (last_call()[0] ?? '') === 'store_vectors' AND (last_call()[1][0] ?? '') === 'nj560_knowledge',
    json_encode(last_call(), JSON_UNESCAPED_UNICODE));

$c = call('vectors', ['user' => NULL, 'method' => 'POST',
    'headers' => ['x-worker-token' => 'чужой'],
    'body' => ['collection' => 'nj560_knowledge', 'entity' => 'doc',
               'items' => [['id' => '1', 'vector' => [0.1]]]]]);
check('vectors: чужой токен без сессии → 403 JSON с причиной',
    $c->response->s === 403 AND is_array(j($c)) AND strpos((string) (j($c)['error'] ?? ''), 'Токен воркера не совпал') !== FALSE,
    'HTTP '.$c->response->s.' :: '.substr($c->response->b, 0, 120));

$c = call('search', ['user' => NULL, 'query' => ['q' => 'бюджет', 'collection' => 'nj560_knowledge']]);
check('остальные точки API без входа по-прежнему закрыты', $c->response->s === 403, 'HTTP '.$c->response->s);

echo "\n== без токена воркера: приём векторов закрыт, health не падает ==\n";
putenv('WORKER_API_TOKEN=');
$c = call('health', ['user' => NULL]);
check('health без токена в .env и без входа → 403', $c->response->s === 403, 'HTTP '.$c->response->s);
$c = call('vectors', ['user' => NULL, 'body' => ['collection' => 'x', 'entity' => 'y', 'items' => []]]);
check('vectors без токена в .env → отказ с подсказкой', $c->response->s === 403 AND strpos($c->response->b, 'WORKER_API_TOKEN') !== FALSE, 'HTTP '.$c->response->s);
putenv('WORKER_API_TOKEN=s3cret');

echo "\n== Поиск пишет журнал (nj_115_searches) ==\n";

Service_Nj115_Semantic::$logged = [];
Service_Nj115_Semantic::$embed_cached = FALSE;
$c = call('search', ['query' => ['q' => 'бюджет на квартал', 'collection' => 'nj560_knowledge', 'limit' => 5]]);
$b = j($c);
check('search: 200 и результаты', $c->response->s === 200 AND $b['ok'] === TRUE AND count($b['results']) === 1, substr($c->response->b, 0, 120));
check('search: журнал получил одну запись', count(Service_Nj115_Semantic::$logged) === 1, json_encode(Service_Nj115_Semantic::$logged, JSON_UNESCAPED_UNICODE));
$log = Service_Nj115_Semantic::$logged[0] ?? [];
check('search: в журнале запрос, коллекция и число результатов',
    ($log['query'] ?? '') === 'бюджет на квартал' AND ($log['collection'] ?? '') === 'nj560_knowledge' AND ($log['results'] ?? -1) === 1,
    json_encode($log, JSON_UNESCAPED_UNICODE));
check('search: время вызова замерено и запись помечена как «не из кэша»',
    ($log['meta']['ms'] ?? -1) >= 0 AND ($log['meta']['cached'] ?? NULL) === FALSE, json_encode($log['meta'] ?? []));
check('search: в журнале записан автор запроса', ($log['meta']['user_id'] ?? 0) === 7, json_encode($log['meta'] ?? []));

Service_Nj115_Semantic::$logged = [];
Service_Nj115_Semantic::$embed_cached = TRUE;   // вектор взяли из кэша
call('search', ['query' => ['q' => 'бюджет', 'collection' => 'nj560_knowledge']]);
check('search: ответ из кэша виден в журнале', (Service_Nj115_Semantic::$logged[0]['meta']['cached'] ?? NULL) === TRUE);
Service_Nj115_Semantic::$embed_cached = FALSE;

Service_Nj115_Semantic::$logged = [];
Service_Nj115_Semantic::$throw = 'эмбеддинг-сервер молчит';
$c = call('search', ['query' => ['q' => 'бюджет', 'collection' => 'nj560_knowledge']]);
$b = j($c);
check('search: ошибка → 500 и причина', $c->response->s === 500 AND ($b['error'] ?? '') === 'эмбеддинг-сервер молчит', substr($c->response->b, 0, 120));
check('search: ошибка тоже попала в журнал (с текстом и без результатов)',
    count(Service_Nj115_Semantic::$logged) === 1
    AND (Service_Nj115_Semantic::$logged[0]['meta']['error'] ?? '') === 'эмбеддинг-сервер молчит'
    AND Service_Nj115_Semantic::$logged[0]['results'] === 0, json_encode(Service_Nj115_Semantic::$logged, JSON_UNESCAPED_UNICODE));
Service_Nj115_Semantic::$throw = NULL;

Service_Nj115_Semantic::$logged = [];
$c = call('search', ['query' => ['q' => 'без коллекции']]);
check('search: без коллекции — 400 и журнал не трогаем',
    $c->response->s === 400 AND Service_Nj115_Semantic::$logged === []);

echo "\n".($FAIL === 0 ? "ВСЁ ОК: новые точки /nj115 (health, coverage, reindex, prune, журнал поисков)" : "ПРОВАЛОВ: $FAIL")." (проверок: $CHECKS)\n";
