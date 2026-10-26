<?php
/** Настоящие Engine/Base/Registry/Render. БД и транспорт подменены: сеть не вызывается. */
defined('SYSPATH') OR define('SYSPATH', '/app/system/');
class Arr
{
    public static function get($a, $key, $default = NULL) { return $a[$key] ?? $default; }
    public static function path($a, $path, $default = NULL) { foreach (explode('.', $path) as $key) { if (!isset($a[$key])) return $default; $a = $a[$key]; } return $a; }
}
class Kohana { public static $config; public static $log; }
class Kohana_Config_Group { public function as_array() { return require '/app/application/modules/nj597_notifications/config/nj597_notifications.php'; } }
Kohana::$config = new class {
    public function load($name) { return new Kohana_Config_Group; }
};
class Log { const ERROR = 1; const WARNING = 2; }
class Log_Plain { public static $messages = []; public static function event($level, $message, $values = []) { self::$messages[] = $message; } }
class HTML { public static function chars($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); } }
class ORM { public static function factory($type, $id) { return new class { public function loaded() { return FALSE; } }; } }
class Database
{
    const SELECT = 0; const INSERT = 1; const UPDATE = 2; const DELETE = 3;
    public static $db;
    public static function instance() { return self::$db; }
}
class Test_Result
{
    public $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function current() { return $this->rows ? $this->rows[0] : FALSE; }
    public function as_array() { return $this->rows; }
}
class Test_Db
{
    public $queries = []; public $rows = []; public $events = []; public $subs = [];
    /** Строки колокольчика: id => ['read_at' => ..., 'archived_at' => ...]. */
    public $inbox = [];
    /** Сколько сводок уже ушло человеку сегодня (правило «одна в сутки»). */
    public $digest_sent_today = 0;
    public function quote($v) { return "'".str_replace("'", "''", (string) $v)."'"; }
    public function query($type, $sql, $unused = FALSE)
    {
        $this->queries[] = $sql;
        if ($type !== Database::SELECT) return 1;
        if (strpos($sql, 'advisory') !== FALSE) return new Test_Result([['ok' => TRUE]]);
        if (strpos($sql, 'nj_597_settings') !== FALSE) return new Test_Result([]);
        if (strpos($sql, 'nj_597_events') !== FALSE) return new Test_Result($this->events);
        if (strpos($sql, 'nj_597_subs') !== FALSE) return new Test_Result($this->subs);
        if (strpos($sql, 'nj_597_templates') !== FALSE) return new Test_Result([]);
        if (strpos($sql, 'nj_597_notifications') !== FALSE)
        {
            if (preg_match('/WHERE "id" = (\d+)/', $sql, $m)) return new Test_Result(isset($this->inbox[$m[1]]) ? [$this->inbox[$m[1]]] : []);
            return new Test_Result(array_values($this->inbox));
        }
        if (strpos($sql, 'nj_597_outbox') !== FALSE)
        {
            // «Сколько сводок уже отправлено сегодня» — считаем по своим данным.
            if (strpos($sql, 'count(*)') !== FALSE AND strpos($sql, 'digest:%') !== FALSE)
            {
                return new Test_Result([['c' => $this->digest_sent_today]]);
            }
            if (preg_match('/WHERE "id" = (\d+)/', $sql, $m)) return new Test_Result(array_values(array_filter($this->rows, function ($r) use ($m) { return $r['id'] == $m[1]; })));
            return new Test_Result($this->rows);
        }
        throw new RuntimeException('Неожиданный SQL: '.$sql);
    }
}
require '/app/application/modules/nj597_notifications/classes/Service/Nj597/Base.php';
require '/app/application/modules/nj597_notifications/classes/Service/Nj597/Registry.php';
require '/app/application/modules/nj597_notifications/classes/Service/Nj597/Render.php';
require '/app/application/modules/nj597_notifications/classes/Service/Nj597/Engine.php';
class Service_Nj597_Notify
{
    public static $prefs = [];
    /** Кто «на сайте» (таблица nj_597_presence): список id. */
    public static $online = [];
    public static function prefs($uid) { return self::$prefs[$uid] ?? []; }
    public static function safe_href($href) { return $href; }
    public static function seen_recently($uid, $seconds = 0) { return in_array((int) $uid, self::$online, TRUE); }
}
class Service_Nj597_Channel_Email
{
    public static $sent = []; public static $result = ['ok' => TRUE]; public static $ready = TRUE; public static $destination = TRUE;
    public static function enabled() { return self::$ready; }
    public static function destination($uid) { return self::$destination ? 'user'.$uid.'@example.test' : ''; }
    public static function send($message) { self::$sent[] = $message; return self::$result; }
}
class Service_Nj597_Channel_Telegram extends Service_Nj597_Channel_Email {}
class Service_Nj597_Channel_Inapp extends Service_Nj597_Channel_Email {}
class Service_Nj597_Limits
{
    public static $quiet = FALSE; public static $limit = FALSE;
    public static function quiet_now($prefs, $channel, $priority) { return self::$quiet; }
    public static function next_wakeup($prefs, $channel) { return time() + 600; }
    public static function check($uid, $channel, $event) { return self::$limit ? ['ok' => FALSE, 'reason' => 'Лимит', 'retry_after' => 300] : ['ok' => TRUE]; }
}
class Service_Nj597_Bind { public static $unbound = []; public static function unbind($uid, $channel) { self::$unbound[] = [$uid, $channel]; } }
$ok = 0; $bad = 0;
function check($label, $got, $want)
{
    global $ok, $bad;
    if ($got === $want) { $ok++; echo "ok   $label\n"; }
    else { $bad++; echo "ФЕЙЛ $label: ".json_encode([$got, $want], JSON_UNESCAPED_UNICODE)."\n"; }
}
function hidden($method, $args) { $r = new ReflectionMethod('Service_Nj597_Engine', $method); $r->setAccessible(TRUE); return $r->invokeArgs(NULL, $args); }
function fixture($uid, $id, $channel = 'email', $event = 'done')
{
    // inbox_id — строка колокольчика, из которой выросло сообщение: по ней
    // движок понимает, прочитал ли человек событие на сайте.
    return ['id' => $id, 'user_id' => $uid, 'channel' => $channel, 'event_alias' => $event, 'inbox_id' => $id,
        'group_key' => 'digest:email:123456789', 'subject' => 'Личное '.$uid.' #'.$id, 'body' => 'Текст '.$uid, 'href' => '/nj597', 'attempts' => 0];
}
function reset_delivery($rows = NULL)
{
    global $db;
    $db->queries = [];
    if ($rows !== NULL) $db->rows = $rows;
    Service_Nj597_Channel_Email::$sent = [];
    Service_Nj597_Channel_Email::$result = ['ok' => TRUE];
    Service_Nj597_Channel_Email::$ready = TRUE;
    Service_Nj597_Channel_Email::$destination = TRUE;
    Service_Nj597_Notify::$prefs = [];
    Service_Nj597_Limits::$quiet = FALSE;
    Service_Nj597_Limits::$limit = FALSE;
    Service_Nj597_Bind::$unbound = [];
    // В окружении по умолчанию никого нет на сайте: правило тишины молчит.
    Service_Nj597_Notify::$online = [];
    // По умолчанию человек НИЧЕГО не включал (владелец, 16.10.2026), поэтому
    // задания в очереди — это уже выбранные им события: ставим явные клетки.
    // Проверки, которым нужна тишина, перебивают $db->subs после reset.
    $db->subs = [
        ['user_id' => 1, 'event_alias' => 'done', 'channel' => 'email', 'enabled' => 't'],
        ['user_id' => 1, 'event_alias' => 'done', 'channel' => 'telegram', 'enabled' => 't'],
        ['user_id' => 1, 'event_alias' => 'done', 'channel' => 'in_app', 'enabled' => 't'],
        ['user_id' => 2, 'event_alias' => 'done', 'channel' => 'email', 'enabled' => 't'],
    ];
    $db->digest_sent_today = 0;
    Service_Nj597_Notify::$prefs = [
        1 => ['email_enabled' => TRUE, 'telegram_enabled' => TRUE, 'in_app_enabled' => TRUE],
        2 => ['email_enabled' => TRUE],
    ];
    // Строки ленты по умолчанию непрочитанные: наружу ничего не «съедается».
    $db->inbox = [];

    foreach ([11, 12, 13, 14, 21] as $inbox_id)
    {
        $db->inbox[$inbox_id] = ['read_at' => NULL, 'archived_at' => NULL];
    }
}
function sql_has($needle) { global $db; return strpos(implode("\n", $db->queries), $needle) !== FALSE; }
$db = Database::$db = new Test_Db;
$db->events = [
    ['alias' => 'done', 'enabled' => TRUE, 'default_channels' => 'in_app,email,telegram', 'allowed_roles' => 'login', 'title' => 'Готово'],
    ['alias' => 'secret', 'enabled' => TRUE, 'default_channels' => 'email', 'allowed_roles' => 'admin', 'mandatory' => TRUE, 'title' => 'Админу'],
    ['alias' => 'off', 'enabled' => FALSE, 'default_channels' => 'email', 'allowed_roles' => 'login', 'title' => 'Выключено'],
];
reset_delivery([fixture(1, 11), fixture(2, 21), fixture(1, 12)]);
$stat = Service_Nj597_Engine::flush_digests();
check('сводки: одинаковый group_key разделён по получателям', $stat['groups'], 2);
check('сводки: каждому своя отправка', $stat['sent'], 2);
check('сводки: первый адрес', Service_Nj597_Channel_Email::$sent[0]['destination'], 'user1@example.test');
check('сводки: второй адрес', Service_Nj597_Channel_Email::$sent[1]['destination'], 'user2@example.test');
check('сводки: у первого оба его уведомления', strpos(Service_Nj597_Channel_Email::$sent[0]['body'], '#12') !== FALSE, TRUE);
check('сводки: у первого нет чужого уведомления', strpos(Service_Nj597_Channel_Email::$sent[0]['body'], '#21') === FALSE, TRUE);
check('сводки: у второго нет чужого уведомления', strpos(Service_Nj597_Channel_Email::$sent[1]['body'], '#11') === FALSE, TRUE);
check('сводки: успех отмечает только свою группу', sql_has('WHERE "id" IN (11,12)'), TRUE);
$groups = hidden('_digest_groups', [[fixture(1, 11), fixture(1, 12, 'telegram')]]);
check('сводки: одинаковый получатель, разные каналы — отдельно', count($groups), 2);
reset_delivery([fixture(1, 11), fixture(2, 21)]);
check('сводки: защитный отказ при смешанной группе', hidden('_send_digest', ['x', $db->rows]), 'failed');
check('сводки: при смешанной группе ничего не ушло', count(Service_Nj597_Channel_Email::$sent), 0);
check('сводки: защитный отказ записан в лог', count(Log_Plain::$messages), 1);
reset_delivery([fixture(1, 11)]);
Service_Nj597_Notify::$prefs[1] = ['email_enabled' => FALSE];
$stat = Service_Nj597_Engine::flush_digests();
check('сводки: отписка до отправки учитывается', count(Service_Nj597_Channel_Email::$sent), 0);
check('сводки: отписка — пропуск, не ошибка транспорта', $stat['skipped'], 1);
check('сводки: отписанное не вернётся в очередь', sql_has('"status" = \'skipped\''), TRUE);
reset_delivery([fixture(1, 11)]);
$db->subs = [['event_alias' => 'done', 'channel' => 'email', 'enabled' => 'f']];
Service_Nj597_Engine::flush_digests();
check('сводки: клетка подписки учитывается', count(Service_Nj597_Channel_Email::$sent), 0);
reset_delivery([fixture(1, 11), fixture(1, 12, 'email', 'secret'), fixture(1, 13, 'email', 'off'), fixture(1, 14, 'email', 'missing')]);
$stat = Service_Nj597_Engine::flush_digests();
check('сводки: допустимое сообщение доставлено', $stat['sent'], 1);
check('сводки: чужое обязательное событие исключено', strpos(Service_Nj597_Channel_Email::$sent[0]['body'], '#12') === FALSE, TRUE);
check('сводки: выключенное событие исключено', strpos(Service_Nj597_Channel_Email::$sent[0]['body'], '#13') === FALSE, TRUE);
check('сводки: неизвестное событие исключено', strpos(Service_Nj597_Channel_Email::$sent[0]['body'], '#14') === FALSE, TRUE);
reset_delivery([fixture(1, 11)]);
$db->subs = [];
$stat = Service_Nj597_Engine::flush_digests();
check('сводки: без отметки человека не отправляем', count(Service_Nj597_Channel_Email::$sent), 0);
check('сводки: пустая клетка — это пропуск, не ошибка', $stat['skipped'], 1);
reset_delivery([fixture(1, 11)]);
Service_Nj597_Channel_Email::$ready = FALSE;
$stat = Service_Nj597_Engine::flush_digests();
check('сводки: недоступный транспорт не теряет очередь', $stat['deferred'], 1);
check('сводки: недоступный транспорт возвращает pending', sql_has('"status" = \'pending\''), TRUE);
foreach (['quiet', 'limit'] as $reason)
{
    reset_delivery([fixture(1, 11)]);
    Service_Nj597_Limits::${$reason} = TRUE;
    $stat = Service_Nj597_Engine::flush_digests();
    check('сводки: '.$reason.' откладывает', $stat['deferred'], 1);
    check('сводки: '.$reason.' не вызывает транспорт', count(Service_Nj597_Channel_Email::$sent), 0);
}
reset_delivery([fixture(1, 11), fixture(1, 12)]);
Service_Nj597_Channel_Email::$result = ['error' => 'SMTP timeout'];
$stat = Service_Nj597_Engine::flush_digests();
check('сводки: ошибка отражена в результате', $stat['failed'], 1);
check('сводки: повтор с задержкой', sql_has('interval \'60 seconds\''), TRUE);
check('сводки: попытка увеличена', sql_has('"attempts" = 1'), TRUE);
check('сводки: нет тупикового статуса failed', sql_has('"status" = \'failed\''), FALSE);
reset_delivery([array_merge(fixture(1, 11), ['attempts' => 4])]);
Service_Nj597_Channel_Email::$result = ['error' => 'SMTP timeout'];
Service_Nj597_Engine::flush_digests();
check('сводки: после пятой попытки DLQ', sql_has('"status" = \'dead\', "attempts" = 5'), TRUE);
reset_delivery([fixture(1, 11, 'telegram')]);
Service_Nj597_Channel_Email::$result = ['error' => 'blocked', 'blocked' => TRUE];
Service_Nj597_Engine::flush_digests();
check('сводки: заблокированный бот отвязан', Service_Nj597_Bind::$unbound, [[1, 'telegram']]);
check('сводки: заблокированному нет повторов', sql_has('"status" = \'skipped\''), TRUE);
reset_delivery([fixture(1, 11, 'email', 'secret')]);
check('одно сообщение: mandatory не обходит права', hidden('_deliver', [$db->rows[0]]), 'skipped');
check('одно сообщение: чужое обязательное не отправлено', count(Service_Nj597_Channel_Email::$sent), 0);
reset_delivery([fixture(1, 11, 'in_app')]);
check('одно сообщение: драйвер in_app найден', hidden('_deliver', [$db->rows[0]]), 'sent');
check('одно сообщение: in_app действительно вызван', count(Service_Nj597_Channel_Email::$sent), 1);
// ---------------------------------------------------------------------------
// Прочитано на сайте — наружу не шлём (владелец, 16.10.2026: «уведомление
// наружу — просьба о действии, а не отчёт»). Сообщение не теряется: уходит в
// журнал с причиной, чтобы администратор видел, почему письма не было.
$notif_cfg = require '/app/application/modules/nj597_notifications/config/nj597_notifications.php';
check('тишина: правило включено умолчанием', (bool) $notif_cfg['delivery']['skip_when_read'], TRUE);
check('своё: правило включено умолчанием', (bool) $notif_cfg['presence']['skip_self'], TRUE);

reset_delivery([fixture(1, 11)]);
$db->inbox[11] = ['read_at' => '2026-10-16 09:00:00', 'archived_at' => NULL];
check('тишина: прочитанное на сайте письмо не шлём', hidden('_deliver', [$db->rows[0]]), 'skipped');
check('тишина: транспорт не тревожили', count(Service_Nj597_Channel_Email::$sent), 0);
check('тишина: причина записана в строку', sql_has('Прочитано на сайте'), TRUE);
check('тишина: причина попала в журнал доставки', sql_has('INSERT INTO "nj_597_log"'), TRUE);

reset_delivery([fixture(1, 11)]);
$db->inbox[11] = ['read_at' => NULL, 'archived_at' => '2026-10-16 09:00:00'];
check('тишина: убранное в архив не шлём', hidden('_deliver', [$db->rows[0]]), 'skipped');
check('тишина: архив тоже без транспорта', count(Service_Nj597_Channel_Email::$sent), 0);

reset_delivery([fixture(1, 11)]);
check('тишина: непрочитанное доходит как обычно', hidden('_deliver', [$db->rows[0]]), 'sent');
check('тишина: ровно одно письмо', count(Service_Nj597_Channel_Email::$sent), 1);

reset_delivery([fixture(1, 11)]);
$db->inbox = [];   // строки ленты нет (её удалили уборкой) — не выдумываем запрет
check('тишина: без строки ленты доставляем', hidden('_deliver', [$db->rows[0]]), 'sent');

reset_delivery([fixture(1, 11, 'in_app')]);
$db->inbox[11] = ['read_at' => '2026-10-16 09:00:00', 'archived_at' => NULL];
check('тишина: колокольчик не глушим этим правилом', hidden('_deliver', [$db->rows[0]]), 'sent');

// ---------------------------------------------------------------------------
// «Своё» событие и человек на сайте: письмо — отчёт о том, что он и так видит
// (владелец, 16.10.2026: «запустил обзор, он сделался, я его смотрю — и мне
// приходит внутреннее уведомление! Зачем?»). Признак «своё» едет вместе со
// строкой очереди (payload.self), поэтому движок перепроверяет присутствие
// в момент отправки: очередь ждёт минутами, а то и сутками (сводка), и человек
// успевает зайти на сайт уже после того, как событие встало в очередь.
$own_row = array_merge(fixture(1, 11), ['payload' => '{"data":{},"self":true}']);

reset_delivery([$own_row]);
Service_Nj597_Notify::$online = [1];
check('своё: на сайте — наружу не шлём', hidden('_deliver', [$db->rows[0]]), 'skipped');
check('своё: транспорта не тревожили', count(Service_Nj597_Channel_Email::$sent), 0);
check('своё: причина записана в строку', sql_has('Своё событие, вы на сайте'), TRUE);

reset_delivery([$own_row]);
check('своё: ушёл с сайта — доставляем', hidden('_deliver', [$db->rows[0]]), 'sent');

reset_delivery([fixture(1, 11)]);
Service_Nj597_Notify::$online = [1];
check('своё: чужое событие будим и когда человек на сайте', hidden('_deliver', [$db->rows[0]]), 'sent');

reset_delivery([$own_row]);
Service_Nj597_Notify::$online = [2];
check('своё: присутствие другого человека не глушит', hidden('_deliver', [$db->rows[0]]), 'sent');

reset_delivery([array_merge(fixture(1, 11), ['payload' => '{"data":{"parts":3},"self":true}'])]);
check('своё: без присутствия доставляем', hidden('_deliver', [$db->rows[0]]), 'sent');
check('своё: данные шаблона доезжают до канала', Service_Nj597_Channel_Email::$sent[0]['data'], ['parts' => 3]);

// Тот же принцип в сводке: прочитанное не должно приезжать письмом «раз в сутки».
reset_delivery([fixture(1, 11), fixture(1, 12)]);
$db->inbox[11] = ['read_at' => '2026-10-16 09:00:00', 'archived_at' => NULL];
$db->inbox[12] = ['read_at' => NULL, 'archived_at' => NULL];
$stat = Service_Nj597_Engine::flush_digests();
check('тишина: сводка уходит', $stat['sent'], 1);
check('тишина: в сводке нет прочитанного', strpos(Service_Nj597_Channel_Email::$sent[0]['body'], '#11') === FALSE, TRUE);
check('тишина: в сводке есть непрочитанное', strpos(Service_Nj597_Channel_Email::$sent[0]['body'], '#12') !== FALSE, TRUE);
check('тишина: прочитанное вычеркнуто из очереди', sql_has('Прочитано на сайте'), TRUE);
check('тишина: строка получения стала skipped', sql_has('\'skipped\''), TRUE);

reset_delivery([fixture(1, 11)]);
$db->inbox[11] = ['read_at' => '2026-10-16 09:00:00', 'archived_at' => NULL];
$stat = Service_Nj597_Engine::flush_digests();
check('тишина: сводка из одного прочитанного не уходит', $stat['sent'], 0);
check('тишина: и транспорта не было', count(Service_Nj597_Channel_Email::$sent), 0);

// Своё событие в сводке: человек на сайте — строку не шлём, остальное остаётся.
reset_delivery([
    array_merge(fixture(1, 11), ['payload' => '{"data":{},"self":true}']),
    fixture(1, 12),
]);
Service_Nj597_Notify::$online = [1];
$stat = Service_Nj597_Engine::flush_digests();
check('своё: сводка без своего события уходит', $stat['sent'], 1);
check('своё: в сводке нет своего события', strpos(Service_Nj597_Channel_Email::$sent[0]['body'], '#11') === FALSE, TRUE);
check('своё: остальные события сводки на месте', strpos(Service_Nj597_Channel_Email::$sent[0]['body'], '#12') !== FALSE, TRUE);
check('своё: причина видна в очереди', sql_has('Своё событие, вы на сайте'), TRUE);

// ---------------------------------------------------------------------------
// Сводка — раз в сутки, и одинаковые события одной строкой (владелец,
// 16.10.2026: «я за лаконичность и разумные решения»).
$digest_cfg = require '/app/application/modules/nj597_notifications/config/nj597_notifications.php';
check('сводка: правило «одно письмо в сутки» включено', (int) $digest_cfg['digest']['per_day'], 1);

reset_delivery([fixture(1, 11)]);
$db->digest_sent_today = 1;
$stat = Service_Nj597_Engine::flush_digests();
check('сводка: вторая за сутки не уходит', $stat['deferred'], 1);
check('сводка: транспорта не было', count(Service_Nj597_Channel_Email::$sent), 0);
check('сводка: причина понятна в очереди', sql_has('Сводка за сутки уже отправлена'), TRUE);
check('сводка: сообщение не потеряно, а отложено', sql_has('\'pending\''), TRUE);
check('сводка: перенос в пределах суток', sql_has('86400 -'), FALSE);

reset_delivery([fixture(1, 11)]);
$stat = Service_Nj597_Engine::flush_digests();
check('сводка: первая за сутки уходит', $stat['sent'], 1);
check('сводка: тема без имени площадки', Service_Nj597_Channel_Email::$sent[0]['subject'], 'Сводка уведомлений (1)');
check('сводка: тема короткая', mb_strlen(Service_Nj597_Channel_Email::$sent[0]['subject']) <= 60, TRUE);

reset_delivery([
    array_merge(fixture(1, 11), ['subject' => 'Обзор готов: Отчёт', 'body' => 'Позиций: 10']),
    array_merge(fixture(1, 12), ['subject' => 'Обзор готов: Отчёт', 'body' => 'Позиций: 10']),
    array_merge(fixture(1, 13), ['subject' => 'Обзор готов: Другой', 'body' => 'Позиций: 5']),
]);
Service_Nj597_Engine::flush_digests();
$digest_body = Service_Nj597_Channel_Email::$sent[0]['body'];
check('сводка: одинаковые события одной строкой', substr_count($digest_body, 'Обзор готов: Отчёт'), 1);
check('сводка: повтор посчитан в строке', strpos($digest_body, '(и ещё 1)') !== FALSE, TRUE);
check('сводка: разные события остаются разными строками', strpos($digest_body, 'Обзор готов: Другой') !== FALSE, TRUE);
check('сводка: всего две строки на три события', substr_count($digest_body, '• '), 2);
check('сводка: счётчик в теме считает события', Service_Nj597_Channel_Email::$sent[0]['subject'], 'Сводка уведомлений (3)');

reset_delivery();
Service_Nj597_Engine::dead();
Service_Nj597_Engine::retry_dead();
check('исторические failed доступны ручному повтору', substr_count(implode("\n", $db->queries), '"status" IN (\'dead\', \'failed\')'), 2);
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad ? 1 : 0);
