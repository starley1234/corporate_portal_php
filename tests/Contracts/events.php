<?php
/** Фасад событий с подключённой и отсутствующей шиной, без bootstrap/БД. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
class Log { const WARNING = 4; }
class EventContractLog {
    public array $entries = [];
    public function add($level, $message, $context) { $this->entries[] = [$level, $context]; }
}
class Kohana { public static $log; }
Kohana::$log = new EventContractLog;
class Service_Prx208_EventBus {
    public static array $published = [];
    public static array $listeners = [];
    public static function instance() { return new self; }
    public function publish($name, $payload, $initiator) { self::$published[] = [$name, $payload, $initiator]; }
    public function listen($name, $callback) { self::$listeners[$name] = $callback; }
    public function has_listeners($name) { return isset(self::$listeners[$name]); }
}
require dirname(__DIR__, 2).'/application/classes/Event.php';
class EventContractWithoutBus extends Event {
    protected static function bus() { return NULL; }
}
$checks = 0;
function event_check($actual, $expected) {
    global $checks;
    if ($actual !== $expected) throw new RuntimeException('Unexpected event result: '.var_export($actual, TRUE));
    $checks++;
}
Event::publish('prx999.test.connected', ['id' => 1], 17);
event_check(Service_Prx208_EventBus::$published, [['prx999.test.connected', ['id' => 1], 17]]);
Event::listen('prx999.test.connected', function () {});
event_check(Event::has('prx999.test.connected'), TRUE);
event_check(Event::has('prx999.test.other'), FALSE);
EventContractWithoutBus::publish('prx999.test.disconnected', ['id' => 2], 18);
EventContractWithoutBus::listen('prx999.test.disconnected', function () {});
event_check(EventContractWithoutBus::has('prx999.test.disconnected'), FALSE);
event_check(EventContractWithoutBus::has('prx999.test.connected'), FALSE);
event_check(Event::has('prx999.test.disconnected'), FALSE);
event_check(count(Service_Prx208_EventBus::$published), 1);
event_check(Kohana::$log->entries, [[Log::WARNING, [':event' => 'prx999.test.disconnected']]]);
foreach (['publish', 'listen'] as $method) {
    try {
        $method === 'publish'
            ? EventContractWithoutBus::publish('invalid', [])
            : EventContractWithoutBus::listen('invalid', function () {});
        throw new RuntimeException('Missing bus must not bypass validation');
    } catch (InvalidArgumentException $e) { $checks++; }
}
echo "Events: $checks checks OK\n";
