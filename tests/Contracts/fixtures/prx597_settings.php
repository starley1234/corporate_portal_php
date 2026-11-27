<?php
/** Дополнение prx597_engine_stand: настоящие сервисы, контроллер и вьюха; без сети/БД. */
echo "\n-- доступность каналов и сохранение настроек --\n";
check('in_app: правильный ключ', Service_Prx597_Channel_Inapp::id(), 'in_app');
check('in_app: правильный драйвер', Service_Prx597_Base::channel_driver('in_app'), 'Service_Prx597_Channel_Inapp');
check('in_app: участвует в доставке', Service_Prx597_Base::channel_enabled('in_app'), TRUE);
check('неизвестный драйвер запрещён', Service_Prx597_Base::channel_driver('alien'), '');
check('без настроек: только на сайте', array_keys(Service_Prx597_Base::user_channels()), ['in_app']);
check('администратор видит все каналы', count(Service_Prx597_Base::channels(FALSE)), 4);
check('скрытые и отсутствующие prefs не затираются', Service_Prx597_Notify::user_pref_fields([
    'in_app_enabled' => '0', 'telegram_enabled' => '0', 'max_enabled' => '0', 'email_enabled' => '0', 'csrf' => 'x',
]), ['in_app_enabled' => FALSE]);
check('пустой PATCH не отключает всё', Service_Prx597_Notify::user_pref_fields([]), []);
$event = ['alias' => 'check', 'enabled' => TRUE, 'channels' => ['in_app', 'email', 'telegram', 'max'], 'roles' => 'login'];
check('план: без выбранных клеток доходит только колокольчик', Service_Prx597_Registry::plan_for(7, $event, ['in_app_enabled' => TRUE], ['login']), ['in_app']);
check('план: отключение соблюдается', Service_Prx597_Registry::plan_for(7, $event, ['in_app_enabled' => FALSE], ['login']), []);

// Владелец (16.10.2026): «по умолчанию у пользователей должны быть отключены
// уведомления» — пустая настройка и пустая клетка значат «нет».
$def = Service_Prx597_Notify::prefs_default(7);
check('умолчания: почта выключена', $def['email_enabled'], FALSE);
check('умолчания: telegram выключен', $def['telegram_enabled'], FALSE);
check('умолчания: MAX выключен', $def['max_enabled'], FALSE);
check('умолчания: старые флаги писем выключены', [$def['on_done'], $def['on_failed']], [FALSE, FALSE]);
check('умолчания: колокольчик включён', $def['in_app_enabled'], TRUE);
check('умолчания: до человека доходит только колокольчик', Service_Prx597_Registry::plan_for(7, $event, $def, ['login']), ['in_app']);
check('клетка: для внешних каналов пустая значит «нет»', Service_Prx597_Registry::sub_enabled($event, 'email', $def, []), FALSE);
check('клетка: для колокольчика пустая значит «да»', Service_Prx597_Registry::sub_enabled($event, 'in_app', $def, []), TRUE);
check('клетка: снятый колокольчик молчит', Service_Prx597_Registry::sub_enabled($event, 'in_app', $def, ['check' => ['in_app' => FALSE]]), FALSE);
check('клетка: явное «да» на почту работает', Service_Prx597_Registry::sub_enabled($event, 'email', $def, ['check' => ['email' => TRUE]]), TRUE);
check('план: почта без галочки канала не шлётся', Service_Prx597_Registry::plan_for(7, $event, ['email_enabled' => TRUE, 'in_app_enabled' => TRUE], ['login']), ['in_app']);
$event['mandatory'] = TRUE;
check('план: обязательное остаётся на сайте', Service_Prx597_Registry::plan_for(7, $event, ['in_app_enabled' => FALSE], ['login']), ['in_app']);
$event['roles'] = 'admin';
check('план: обязательное не обходит роли', Service_Prx597_Registry::plan_for(7, $event, [], ['login']), []);
$old_event = ['alias' => 'prx120.job.done', 'mandatory' => FALSE];
check('старое отключение письма видно в матрице', Service_Prx597_Registry::sub_enabled($old_event, 'email', ['on_done' => FALSE], []), FALSE);
check('явное включение матрицы важнее старого флага', Service_Prx597_Registry::sub_enabled($old_event, 'email', ['on_done' => FALSE], ['prx120.job.done' => ['email' => TRUE]]), TRUE);
check('колокольчик от старых флагов писем не зависит', Service_Prx597_Registry::sub_enabled($old_event, 'in_app', ['on_done' => TRUE], []), TRUE);
check('старое значение по умолчанию больше не считается согласием', Service_Prx597_Registry::sub_enabled($old_event, 'email', [], []), FALSE);
check('явное отключение подписки соблюдается', Service_Prx597_Registry::sub_enabled($old_event, 'email', ['on_done' => TRUE], ['prx120.job.done' => ['email' => FALSE]]), FALSE);
check('уборка: разовое гашение включённых умолчаний объявлено', method_exists('Service_Prx597_Notify', '_defaults_off_once'), TRUE);
$off_src = file_get_contents(dirname(__DIR__, 3).'/application/modules/prx597_notifications/classes/Service/Prx597/Preferences.php');
check('уборка: трогает только тех, кто ничего не выбирал', strpos($off_src, 'NOT EXISTS (SELECT 1 FROM '.chr(34).'prx_597_subs'.chr(34)) !== FALSE, TRUE);
check('уборка: закрывается отметкой и не повторяется', strpos($off_src, "prefs.defaults_off") !== FALSE, TRUE);

// Владелец (16.10.2026): «я плохо знаю теорию и могу дать неверные указания» —
// поэтому важность выводим из категории события, а не из памяти модулей.
// До этого правки никто не задавал приоритет, и ВСЁ было «обычным» (5).
check('важность: безопасность — критично', Service_Prx597_Registry::priority_for(['category' => 'security']), 1);
check('важность: деньги — почти критично', Service_Prx597_Registry::priority_for(['category' => 'billing']), 2);
check('важность: сбой площадки — важное', Service_Prx597_Registry::priority_for(['category' => 'system']), 3);
check('важность: задачи — обычное', Service_Prx597_Registry::priority_for(['category' => 'jobs']), 5);
check('важность: материалы — фоновое', Service_Prx597_Registry::priority_for(['category' => 'content']), 6);
check('важность: оценки и лайки — самое фоновое', Service_Prx597_Registry::priority_for(['category' => 'social']), 7);
check('важность: незнакомая категория — обычное', Service_Prx597_Registry::priority_for(['category' => 'чего-то нового']), 5);
check('важность: пустая категория — обычное', Service_Prx597_Registry::priority_for([]), 5);
check('важность: явное слово модуля важнее категории', Service_Prx597_Registry::priority_for(['category' => 'social'], 1), 1);
check('важность: за границы шкалы не выходит', [
    Service_Prx597_Registry::priority_for([], 99), Service_Prx597_Registry::priority_for([], 0), Service_Prx597_Registry::priority_for([], -5),
], [9, 5, 5]);
check('важность: категория из реестра событий', Service_Prx597_Registry::priority_for(['category' => 'SECURITY']), 1);
check('важность: категория с пробелами читается', Service_Prx597_Registry::priority_for(['category' => ' social ']), 7);

// Фасад действительно спрашивает важность у реестра, а не берёт её из воздуха.
$notify_src = file_get_contents(dirname(__DIR__, 3).'/application/modules/prx597_notifications/classes/Service/Prx597/Notify.php');
check('важность: emit спрашивает у реестра', strpos($notify_src, 'Service_Prx597_Registry::priority_for(') !== FALSE, TRUE);
check('важность: правило описано в конфиге', strpos(file_get_contents(dirname(__DIR__, 3).'/application/modules/prx597_notifications/config/prx597_notifications.php'), "'by_category'") !== FALSE, TRUE);

$d1 = call_hidden('Service_Prx597_Notify', '_delivery_dedup', [7, 'email', str_repeat('x', 160)]);
$d2 = call_hidden('Service_Prx597_Notify', '_delivery_dedup', [8, 'email', str_repeat('x', 160)]);
check('дедуп: разные получатели не подавляют друг друга', $d1 !== $d2, TRUE);
check('дедуп: помещается в колонку без обрезки', strlen($d1) <= 160, TRUE);
check('дедуп: одно событие одному получателю стабильно', call_hidden('Service_Prx597_Notify', '_delivery_dedup', [7, 'email', str_repeat('x', 160)]), $d1);
check('дедуп: разные каналы не подавляют друг друга', call_hidden('Service_Prx597_Notify', '_delivery_dedup', [7, 'telegram', str_repeat('x', 160)]) !== $d1, TRUE);
check('дедуп: пустой ключ не вводит глобальный запрет', call_hidden('Service_Prx597_Notify', '_delivery_dedup', [7, 'email', '']), '');
putenv('TELEGRAM_BOT_TOKEN=old');
putenv('PRX597_TELEGRAM_BOT_TOKEN=short');
check('совместимость с прежней инструкцией', Service_Prx597_Base::env('TELEGRAM_BOT_TOKEN'), 'short');
putenv('PRX597_NOTIFICATIONS_TELEGRAM_BOT_TOKEN=new');
check('полный env важнее старых', Service_Prx597_Base::env('TELEGRAM_BOT_TOKEN'), 'new');
check('без имени бота канал не показываем', isset(Service_Prx597_Base::user_channels()['telegram']), FALSE);
putenv('PRX597_NOTIFICATIONS_TELEGRAM_BOT_NICK=@notify_bot');
check('бот с токеном и именем показывается', isset(Service_Prx597_Base::user_channels()['telegram']), TRUE);
check('ник Telegram без лишнего @', Service_Prx597_Bind::bot_url('telegram', 'abc'), 'https://t.me/notify_bot?start=abc');
putenv('PRX597_NOTIFICATIONS_TELEGRAM_BOT_TOKEN=');
check('пустой новый env не подхватывает старый токен', isset(Service_Prx597_Base::user_channels()['telegram']), FALSE);
foreach (['TELEGRAM_BOT_TOKEN', 'PRX597_TELEGRAM_BOT_TOKEN', 'PRX597_NOTIFICATIONS_TELEGRAM_BOT_TOKEN', 'PRX597_NOTIFICATIONS_TELEGRAM_BOT_NICK'] as $key) { putenv($key); }
putenv('EMAIL_DSN=smtp://localhost');
check('почта без отправителя скрыта', isset(Service_Prx597_Base::user_channels()['email']), FALSE);
putenv('EMAIL_FROM=notify@example.test');
check('почта появляется с DSN', isset(Service_Prx597_Base::user_channels()['email']), TRUE);
putenv('PRX597_EMAIL_ENABLED=0');
check('почту можно выключить флагом', isset(Service_Prx597_Base::user_channels()['email']), FALSE);
putenv('PRX597_EMAIL_ENABLED');
putenv('EMAIL_DSN=null://null');
check('пустой почтовый транспорт скрыт', isset(Service_Prx597_Base::user_channels()['email']), FALSE);
check('пустой транспорт не выдаёт фиктивный успех', isset(Service_Prx597_Notify::send_email('user@example.test', 'Тест', 'Тест')['error']), TRUE);
putenv('EMAIL_DSN'); putenv('EMAIL_FROM');

class Prx597_Settings_Result
{
    public $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function as_array() { return $this->rows; }
    public function current() { return $this->rows ? $this->rows[0] : FALSE; }
    public function get($key, $default = NULL) { return $this->rows ? ($this->rows[0][$key] ?? $default) : $default; }
}
class Prx597_Settings_Db
{
    public $queries = [];
    public $settings = [];
    public $subs = [];
    public $events = [];
    public $mode = 'webhook';
    public function quote($v) { return "'".str_replace("'", "''", (string) $v)."'"; }
    public function query($type, $sql, $unused = FALSE)
    {
        $this->queries[] = $sql;
        if ($type !== Database::SELECT) { return 1; }
        $rows = [];
        if (strpos($sql, 'prx_597_settings') !== FALSE) { $rows = $this->settings; }
        elseif (strpos($sql, 'prx_597_subs') !== FALSE)
        {
            // Матрица подписок: только то, что человек отметил сам.
            $rows = $this->subs;
            if (preg_match('~"user_id" = (\d+)~', $sql, $m)) {
                $rows = array_values(array_filter($rows, function ($r) use ($m) { return (int) $r['user_id'] === (int) $m[1]; }));
            }
        }
        elseif (strpos($sql, 'prx_597_events') !== FALSE) { $rows = $this->events; }
        elseif (strpos($sql, 'prx_597_bots') !== FALSE) { $rows = [['mode' => $this->mode]]; }
        return new Prx597_Settings_Result($rows);
    }
}
$db = Database::$db = new Prx597_Settings_Db;
Service_Prx597_Base::forget_settings();

// Матрица подписок живёт в базе: пока строки нет, клетка значит «нет».
// $event выше уже подпорчен другими проверками (mandatory/роли) — берём свой.
$sub_event = ['alias' => 'check', 'enabled' => TRUE, 'channels' => ['in_app'], 'roles' => 'login'];
$db->subs = [['user_id' => 7, 'event_alias' => 'check', 'channel' => 'in_app', 'enabled' => 't']];
check('план: отмеченная клетка шлёт', Service_Prx597_Registry::plan_for(7, $sub_event, ['in_app_enabled' => TRUE], ['login']), ['in_app']);
check('план: клетка есть, а канал выключен — тишина', Service_Prx597_Registry::plan_for(7, $sub_event, ['in_app_enabled' => FALSE], ['login']), []);
$sub_event['roles'] = 'admin';
check('план: чужое по роли событие не шлём даже с клеткой', Service_Prx597_Registry::plan_for(7, $sub_event, ['in_app_enabled' => TRUE], ['login']), []);
$sub_event['roles'] = 'login';
$db->subs = [];
check('кеш: первое значение по умолчанию', Service_Prx597_Base::setting('example', 'before'), 'before');
$db->settings = [['key' => 'example', 'value' => 'after']];
Service_Prx597_Base::set_setting('example', 'after');
check('кеш: запись видна в том же запросе', Service_Prx597_Base::setting('example', 'before'), 'after');
putenv('PRX597_NOTIFICATIONS_TELEGRAM_BOT_TOKEN=test');
putenv('PRX597_NOTIFICATIONS_TELEGRAM_BOT_NICK=notify_bot');
$db->mode = 'off';
check('выключенный приём скрывает бот', isset(Service_Prx597_Base::user_channels()['telegram']), FALSE);
$count = count($db->queries);
check('выключенному боту нельзя получить код', isset(Service_Prx597_Bind::start(7, 'telegram')['error']), TRUE);
check('для скрытого канала нет INSERT', strpos(implode("\n", array_slice($db->queries, $count)), 'INSERT INTO "prx_597_channels"') === FALSE, TRUE);
$db->mode = 'poll';
$bound = Service_Prx597_Bind::start(7, 'telegram');
check('привязка: код выдан', ! empty($bound['code']), TRUE);
check('привязка: срок 15 минут с часовым поясом', abs(strtotime($bound['expires_at']) - time() - 900) <= 2, TRUE);
check('привязка: готовая ссылка', $bound['url'], Service_Prx597_Bind::bot_url('telegram', $bound['code']));
putenv('PRX597_NOTIFICATIONS_MAX_BOT_TOKEN=test');
putenv('PRX597_NOTIFICATIONS_MAX_BOT_NICK=notify_bot');
check('MAX: настроенный бот виден', isset(Service_Prx597_Base::user_channels()['max']), TRUE);
$db->settings[] = ['key' => 'max.driver', 'value' => 'business'];
Service_Prx597_Base::forget_settings();
putenv('PRX597_NOTIFICATIONS_MAX_BUSINESS_KEY=test');
putenv('PRX597_NOTIFICATIONS_MAX_BUSINESS_ENDPOINT=https://example.test');
check('MAX: бизнес-транспорт доступен отдельно', Service_Prx597_Base::channel_enabled('max'), TRUE);
check('MAX: бизнес-API не выдаёт фиктивную привязку бота', isset(Service_Prx597_Base::user_channels()['max']), FALSE);
$db->settings = [];
Service_Prx597_Base::forget_settings();
foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_NICK', 'MAX_BOT_TOKEN', 'MAX_BOT_NICK', 'MAX_BUSINESS_KEY', 'MAX_BUSINESS_ENDPOINT'] as $key) { putenv('PRX597_NOTIFICATIONS_'.$key); }

// Настоящая вьюха и POST-контроллер; формы проверяем и с активным ботом.
class View
{
    /** Общая шапка страницы рендерит заголовок (как partials/page_header); прочие partial — пусто. */
    public static function factory($view, $vars = [])
    {
        if ($view === 'partials/page_header') return '<h1 class="prx-h1">'.htmlspecialchars((string) ($vars['title'] ?? ''), ENT_QUOTES, 'UTF-8').'</h1>';
        return '';
    }
}
class Controller_Prx102_Base
{
    public $request; public $location; public static $allowed = TRUE;
    public function before() {}
    public function can($ability) { return self::$allowed; }
    public function set_title($title) {}
    public function redirect($url) { $this->location = $url; }
}
class Security { public static function check($token) { return $token === 'csrf-test'; } }
// Базовый Controller: страница отказа (prx597/unsub) не наследует Prx100 — она
// работает без сессии, поэтому ей нужен только каркас «запрос + ответ».
class Controller
{
    public $request; public $response; public $auto_render = TRUE;
    public function __construct($request = NULL, $response = NULL) { $this->request = $request; $this->response = $response; }
    public function before() {}
}
class Response
{
    public $code = 200; public $body = ''; public $headers_list = [];
    public function status($code = NULL) { $this->code = $code; return $this; }
    public function body($body = NULL) { $this->body = $body; return $this; }
    public function headers($name, $value) { $this->headers_list[$name] = $value; return $this; }
}
class Request
{
    const POST = 'POST';
    const GET  = 'GET';
    public $data; public $get; public $http;
    public function method() { return $this->http; }
    public function __construct($data = [], $get = [], $http = self::POST) { $this->data = $data; $this->get = $get; $this->http = $http; }
    public function post($key = NULL, $default = NULL) { return $key === NULL ? $this->data : ($this->data[$key] ?? $default); }
    public function query($key = NULL, $default = NULL) { return $key === NULL ? $this->get : ($this->get[$key] ?? $default); }
}
class Service_Prx597_Schema { public static function ensure() { return []; } }
require dirname(__DIR__, 3).'/application/modules/prx597_notifications/classes/Controller/Prx597/Notifications.php';
require dirname(__DIR__, 3).'/application/modules/prx597_notifications/classes/Controller/Prx597/Unsub.php';
class Prx597_Test_Controller extends Controller_Prx597_Notifications
{
    public function __construct($data) { $this->_user_id = 7; $this->request = new Request($data); }
    public function post() { $_POST = $this->request->data; $this->_action_post(); }
}
function prx597_render($tab, $channels, $binding = [], $items = [])
{
    $csrf = 'csrf-test'; $email = 'user@example.test'; $flash = ''; $unread = count($items);
    $prefs = Service_Prx597_Notify::prefs_default(7);
    $matrix = ['jobs' => [['alias' => 'test', 'title' => 'Готово', 'description' => '', 'mandatory' => FALSE, 'channels' => array_keys($channels), 'state' => array_fill_keys(array_keys($channels), TRUE)]]];
    ob_start();
    include dirname(__DIR__, 3).'/application/modules/prx597_notifications/views/prx597/index.php';
    return ob_get_clean();
}
set_error_handler(function ($code, $message) { throw new RuntimeException($message); }, E_WARNING | E_NOTICE);
$html = prx597_render('settings', Service_Prx597_Base::user_channels());
check('экран: нет названий и полей скрытых ботов', preg_match('/Telegram|MAX|telegram_enabled|max_enabled|Привязка мессенджеров/', $html), 0);
check('экран: без внешних каналов не нужны тихие часы', strpos($html, 'name="quiet_from"') === FALSE, TRUE);
check('экран: checkbox имеет явное выключенное значение', strpos($html, 'name="in_app_enabled" value="0"') !== FALSE, TRUE);
putenv('PRX597_NOTIFICATIONS_TELEGRAM_BOT_TOKEN=test'); putenv('PRX597_NOTIFICATIONS_TELEGRAM_BOT_NICK=notify_bot');
$html = prx597_render('settings', Service_Prx597_Base::user_channels(), ['telegram' => [
    'key' => 'telegram', 'label' => 'Telegram', 'icon' => 'fab fa-telegram', 'bind' => TRUE, 'enabled' => TRUE,
    'destination' => '123', 'status' => 'active', 'bot_url' => 'https://t.me/notify_bot?start=__CODE__',
]]);
check('экран: настроенный бот показан', strpos($html, 'Привязка мессенджеров') !== FALSE, TRUE);
check('экран: кнопка отвязки ссылается на отдельную форму', strpos($html, 'form="prx597-unbind-telegram"') !== FALSE, TRUE);
preg_match_all('~<(/?)form\b~', $html, $tags, PREG_SET_ORDER);
$depth = 0; $max_depth = 0;
foreach ($tags as $tag) { $depth += $tag[1] === '/' ? -1 : 1; $max_depth = max($max_depth, $depth); }
check('экран: нет вложенных форм', $max_depth, 1);
check('экран: все формы закрыты', $depth, 0);
$html = prx597_render('feed', Service_Prx597_Base::user_channels(), [], [
    ['id' => 17, 'title' => 'Первое', 'body' => '', 'href' => '/prx597', 'created_at' => '2026-10-15 12:00:00', 'read_at' => NULL],
    ['id' => 18, 'title' => 'Второе', 'body' => '', 'href' => '/prx597', 'created_at' => '2026-10-15 12:01:00', 'read_at' => NULL],
]);
check('лента: read_all только у общей кнопки', substr_count($html, 'value="read_all"'), 1);
check('лента: отдельный id каждого сообщения', substr_count($html, 'name="id"'), 2);
$c = new Prx597_Test_Controller(['act' => 'read', 'id' => 17, 'csrf' => 'csrf-test']);
$c->post();
$last = end($db->queries);
check('прочтение: только свой пользователь и одно id', strpos($last, 'WHERE user_id = 7 AND id IN (17)') !== FALSE, TRUE);
$c = new Prx597_Test_Controller(['act' => 'unbind', 'channel' => 'telegram', 'csrf' => 'csrf-test']);
$c->post();
check('возврат в настройки с корректным query string', strpos($c->location, '/prx597?tab=settings&flash=') === 0, TRUE);
restore_error_handler();
putenv('PRX597_NOTIFICATIONS_TELEGRAM_BOT_TOKEN'); putenv('PRX597_NOTIFICATIONS_TELEGRAM_BOT_NICK');
// ---------------------------------------------------------------------------
// Отказ от уведомлений по ссылке из письма (владелец, 16.10.2026: «у каждого
// письма должен быть способ выключить именно его, иначе человек выключит всё»).
echo "\n-- отказ от уведомлений ссылкой из письма --\n";

putenv('PRX597_NOTIFICATIONS_UNSUB_SECRET=test-secret');

$un_token = Service_Prx597_Notify::unsub_token(7, 'email', 'prx593.review.done', 'event');
check('отказ: подпись непустая и разумной длины', strlen($un_token), 40);
check('отказ: своя подпись подходит', Service_Prx597_Notify::unsub_verify(7, 'email', 'prx593.review.done', 'event', $un_token), TRUE);
check('отказ: чужая подпись не подходит', Service_Prx597_Notify::unsub_verify(7, 'email', 'prx593.review.done', 'event', str_repeat('a', 40)), FALSE);
check('отказ: пустая подпись не подходит', Service_Prx597_Notify::unsub_verify(7, 'email', 'prx593.review.done', 'event', ''), FALSE);
check('отказ: чужой пользователь не подходит', Service_Prx597_Notify::unsub_verify(8, 'email', 'prx593.review.done', 'event', $un_token), FALSE);
check('отказ: другой вид отказа не подходит', Service_Prx597_Notify::unsub_verify(7, 'email', 'prx593.review.done', 'channel', $un_token), FALSE);
check('отказ: другой канал не подходит', Service_Prx597_Notify::unsub_verify(7, 'telegram', 'prx593.review.done', 'event', $un_token), FALSE);
check('отказ: подпись зависит от секрета', (function () use ($un_token) {
    putenv('PRX597_NOTIFICATIONS_UNSUB_SECRET=другой');
    $other = Service_Prx597_Notify::unsub_token(7, 'email', 'prx593.review.done', 'event');
    putenv('PRX597_NOTIFICATIONS_UNSUB_SECRET=test-secret');
    return $other !== $un_token;
})(), TRUE);
check('отказ: непонятный вид отказа не принимается', Service_Prx597_Notify::unsub_verify(7, 'email', 'prx593.review.done', 'всё', $un_token), FALSE);

$un_url = Service_Prx597_Notify::unsub_url(7, 'email', 'prx593.review.done', 'event');
check('отказ: ссылка ведёт на страницу отказа', strpos($un_url, '/prx597/unsub?') !== FALSE, TRUE);
check('отказ: в ссылке есть получатель', strpos($un_url, 'u=7') !== FALSE, TRUE);
check('отказ: ссылка несёт подпись', strpos($un_url, 't='.$un_token) !== FALSE, TRUE);
check('отказ: ссылка не содержит пробелов', strpos($un_url, ' ') === FALSE, TRUE);
unset($GLOBALS['_GET']);
check('отказ: ссылка-отказ для канала отличается', Service_Prx597_Notify::unsub_url(7, 'email', 'prx593.review.done', 'channel') !== $un_url, TRUE);

$un_headers = Service_Prx597_Notify::unsub_headers($un_url);
check('отказ: заголовок List-Unsubscribe есть', $un_headers['List-Unsubscribe'], '<'.$un_url.'>');
check('отказ: правило одного клика объявлено', $un_headers['List-Unsubscribe-Post'], 'List-Unsubscribe=One-Click');
check('отказ: без ссылки заголовков нет', Service_Prx597_Notify::unsub_headers(''), []);
check('отказ: перевод строки в ссылке не проходит заголовком', Service_Prx597_Notify::unsub_headers("https://prx.ru/x
Bcc: чужой@example.test"), []);

// Подвал письма: две кнопки (событие и канал) плюс прежние ссылки.
$shell = Service_Prx597_Notify::email_shell('Тема', '<p>Тело</p>', ['user_id' => 7, 'channel' => 'email', 'event_alias' => 'prx593.review.done']);
check('подвал: есть отказ от события', strpos($shell, 'Больше не присылать это событие') !== FALSE, TRUE);
check('подвал: есть отказ от писем', strpos($shell, 'Отключить письма') !== FALSE, TRUE);
check('подвал: остались настройки', strpos($shell, '/prx597') !== FALSE, TRUE);
check('подвал: без получателя ссылок отказа нет', strpos(Service_Prx597_Notify::email_shell('Тема', '<p>Тело</p>'), 'Отключить письма'), FALSE);
check('подвал: у сводки нет отказа «это событие»', strpos(Service_Prx597_Notify::email_shell('Тема', '<p>Тело</p>', ['user_id' => 7, 'channel' => 'email', 'event_alias' => 'digest']), 'Больше не присылать это событие'), FALSE);

// Что происходит по нажатию: канал или одно событие, колокольчик не трогаем.
$db->queries = [];
$res = Service_Prx597_Notify::unsub_apply(7, 'email', '', 'channel');
check('отказ: отключение писем проходит', $res['ok'], TRUE);
check('отказ: письма выключены в настройках', strpos(implode("\n", $db->queries), 'email_enabled') !== FALSE, TRUE);
check('отказ: текст объясняет, что колокольчик остался', strpos($res['message'], 'Колокольчик') !== FALSE, TRUE);
check('отказ: колокольчик ссылкой не выключается', Service_Prx597_Notify::unsub_apply(7, 'in_app', '', 'channel')['ok'], FALSE);
check('отказ: без получателя не работает', Service_Prx597_Notify::unsub_apply(0, 'email', 'prx593.review.done', 'event')['ok'], FALSE);

// Строки реестра — как их отдаёт база (default_channels строкой): иначе
// Registry::_shape() подставит «только колокольчик», и проверка ничего не значит.
$db->events = [
    ['alias' => 'prx593.review.done', 'title' => 'Обзор трендов готов', 'enabled' => TRUE, 'default_channels' => 'in_app,email,telegram', 'allowed_roles' => 'login', 'category' => 'content'],
    ['alias' => 'prx597.bank.lock', 'title' => 'Списание не прошло', 'enabled' => TRUE, 'default_channels' => 'in_app,email', 'allowed_roles' => 'login', 'mandatory' => TRUE, 'category' => 'billing'],
];
Service_Prx597_Registry::forget_events();

$db->queries = [];
$res = Service_Prx597_Notify::unsub_apply(7, 'email', 'prx593.review.done', 'event');
check('отказ: событие отключается', $res['ok'], TRUE);
$un_sql = implode("\n", $db->queries);
check('отказ: клетка снята для почты', strpos($un_sql, "'prx593.review.done', 'email',") !== FALSE, TRUE);
check('отказ: клетка снята для мессенджера', strpos($un_sql, "'prx593.review.done', 'telegram',") !== FALSE, TRUE);
check('отказ: колокольчик в клетках не трогаем', strpos($un_sql, "'prx593.review.done', 'in_app',") === FALSE, TRUE);
check('отказ: объявленные каналы берём у события', stripos($un_sql, "'max'") === FALSE, TRUE);
check('отказ: текст обещает колокольчик', strpos($res['message'], 'колокольчике') !== FALSE, TRUE);

check('отказ: обязательное событие не отключается', Service_Prx597_Notify::unsub_apply(7, 'email', 'prx597.bank.lock', 'event')['ok'], FALSE);
check('отказ: и объясняет почему', strpos(Service_Prx597_Notify::unsub_apply(7, 'email', 'prx597.bank.lock', 'event')['message'], 'обязательное') !== FALSE, TRUE);
check('отказ: незнакомое событие не отключаем', Service_Prx597_Notify::unsub_apply(7, 'email', 'такогобытьне может', 'event')['ok'], FALSE);
check('отказ: без кода события не отключаем', Service_Prx597_Notify::unsub_apply(7, 'email', '', 'event')['ok'], FALSE);
check('отказ: выключенное событие всё равно отключается', Service_Prx597_Notify::unsub_apply(7, 'email', 'prx593.review.done', 'event')['ok'], TRUE);

// Страница отказа: без входа, маршрут зарегистрирован раньше страницы prx597.
$un_ctrl = file_get_contents(dirname(__DIR__, 3).'/application/modules/prx597_notifications/classes/Controller/Prx597/Unsub.php');
$un_init = file_get_contents(dirname(__DIR__, 3).'/application/modules/prx597_notifications/init.php');
check('страница отказа: сессию не требует', strpos($un_ctrl, 'logged_in') === FALSE, TRUE);
check('страница отказа: пропуск — подпись ссылки', strpos($un_ctrl, 'unsub_verify') !== FALSE, TRUE);
check('страница отказа: умеет ответить почтовому клиенту', strpos($un_ctrl, 'Request::POST') !== FALSE, TRUE);
check('страница отказа: без верной подписи отвечает 403', strpos($un_ctrl, ', 403,') !== FALSE, TRUE);
check('страница отказа: уводит в настройки уведомлений', strpos($un_ctrl, '/prx597?tab=settings') !== FALSE, TRUE);
check('маршрут отказа зарегистрирован', strpos($un_init, "Route::set('prx597_unsub', 'prx597/unsub')") !== FALSE, TRUE);
check('маршрут отказа стоит раньше страницы /prx597',
    strpos($un_init, "'prx597/unsub'") < strpos($un_init, "Route::set('prx597_page', 'prx597')"), TRUE);

// Страница отказа целиком: запрос → ответ (без сессии).
function prx597_unsub_page(array $get, array $post = [], $method = 'POST')
{
    $controller = new Controller_Prx597_Unsub(new Request($post, $get, $method), new Response);
    $controller->before();
    $controller->action_index();

    return $controller->response;
}

$db->events[] = ['alias' => 'prx667.build.done', 'title' => 'Сборка изделия готова', 'enabled' => TRUE, 'default_channels' => 'in_app,email', 'allowed_roles' => 'login', 'category' => 'jobs'];
Service_Prx597_Registry::forget_events();

$good = [
    'u' => 7, 'c' => 'email', 'e' => 'prx593.review.done', 's' => 'event',
    't' => Service_Prx597_Notify::unsub_token(7, 'email', 'prx593.review.done', 'event'),
];
$page = prx597_unsub_page($good, [], 'GET');
check('страница отказа: отвечает 200', $page->code, 200);
check('страница отказа: страница отдана', strpos($page->body, 'Готово') !== FALSE, TRUE);
check('страница отказа: объясняет, что колокольчик остался', strpos($page->body, 'колокольчике') !== FALSE, TRUE);
check('страница отказа: ведёт в настройки', strpos($page->body, '/prx597?tab=settings') !== FALSE, TRUE);
check('страница отказа: тип ответа — html', $page->headers_list['Content-Type'] ?? '', 'text/html; charset=utf-8');

$page = prx597_unsub_page(array_merge($good, ['t' => str_repeat('f', 40)]), [], 'GET');
check('страница отказа: подделка получает 403', $page->code, 403);
check('страница отказа: и понятный текст', strpos($page->body, 'Ссылка не подходит') !== FALSE, TRUE);

$page = prx597_unsub_page(array_merge($good, ['s' => 'channel']), [], 'GET');
check('страница отказа: подпись для другого вида не подходит', $page->code, 403);

// «Один клик» почтового клиента: POST на тот же адрес, ответ без страницы.
$db->queries = [];
$one_click = array_merge($good, ['s' => 'channel', 'e' => '']);
$one_click['t'] = Service_Prx597_Notify::unsub_token(7, 'email', '', 'channel');
$page = prx597_unsub_page($one_click, $one_click, 'POST');
check('один клик: отвечает 200', $page->code, 200);
check('один клик: страницу не показываем', $page->body, '');
check('один клик: отказ выполнен', strpos(implode("\n", $db->queries), 'email_enabled') !== FALSE, TRUE);

$db->queries = [];
$bell = ['u' => 7, 'c' => 'in_app', 'e' => '', 's' => 'channel'];
$bell['t'] = Service_Prx597_Notify::unsub_token(7, 'in_app', '', 'channel');
$page = prx597_unsub_page($bell, [], 'GET');
check('страница отказа: колокольчик ссылкой не выключается', strpos($page->body, 'только в настройках') !== FALSE, TRUE);
check('страница отказа: и ничего не пишет в базу', count($db->queries), 0);

putenv('PRX597_NOTIFICATIONS_UNSUB_SECRET');

Database::$db = NULL;
Service_Prx597_Base::forget_settings();
