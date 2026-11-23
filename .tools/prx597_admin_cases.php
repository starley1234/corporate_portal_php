<?php
/** Дополнение engine_stand: реальные контракты реестра/вьюхи и адресное сообщение. */
echo "\n-- админка: шаблоны и сообщение пользователю --\n";
require dirname(__DIR__).'/application/modules/prx597_notifications/classes/Controller/Prx597/Admin.php';
class HTTP_Exception { public static function factory($code, $message) { return new RuntimeException($message, $code); } }
class Prx597_Admin_Controller extends Controller_Prx597_Admin
{
    public function __construct($data) { $this->request = new Request($data); }
    public function post() { $_POST = $this->request->data; $this->before(); $this->action_index(); }
}
class Prx597_Admin_Db extends Prx597_Settings_Db
{
    public $insert_id = 81;
    public $fail = FALSE;
    public $inserts = [];
    public $events = [];
    public $templates = [];
    public function query($type, $sql, $unused = FALSE)
    {
        if (strpos($sql, 'INSERT INTO "prx_597_notifications"') !== FALSE)
        {
            $this->queries[] = $sql;
            if ($this->fail) { throw new RuntimeException('Ошибка БД теста'); }
            $this->inserts[] = $sql;
            return new Prx597_Settings_Result($this->insert_id > 0 ? [['id' => $this->insert_id]] : []);
        }
        if (strpos($sql, 'INSERT INTO "prx_597_settings"') !== FALSE)
        {
            $this->queries[] = $sql;
            preg_match_all("~'((?:[^']|'')*)'~", $sql, $m);
            $vals = array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);
            $key = (string) array_shift($vals);
            $value = (string) array_shift($vals);
            $found = FALSE;
            foreach ($this->settings as $i => $row)
            {
                if ((string) $row['key'] === $key) { $this->settings[$i]['value'] = $value; $found = TRUE; }
            }
            if ( ! $found) { $this->settings[] = ['key' => $key, 'value' => $value]; }
            return new Prx597_Settings_Result([['key' => $key, 'value' => $value]]);
        }
        if (strpos($sql, 'SELECT * FROM "prx_597_events"') !== FALSE) { return new Prx597_Settings_Result($this->events); }
        if (strpos($sql, 'SELECT * FROM "prx_597_templates"') !== FALSE) { return new Prx597_Settings_Result($this->templates); }
        return parent::query($type, $sql, $unused);
    }
}
$db = Database::$db = new Prx597_Admin_Db;
$db->settings = [['key' => 'registry.synced_at', 'value' => (string) time()]];
Service_Prx597_Base::forget_settings();
$db->events = [['alias' => 'prx120.job.done', 'title' => 'Задача выполнена', 'enabled' => 't',
    'default_channels' => 'in_app,email', 'allowed_roles' => 'login', 'placeholders' => 'title,code,href']];
$db->templates = [['event_alias' => 'prx120.job.done', 'channel' => 'email', 'subject' => 'Тема "письма"',
    'body' => '<b>{{title}}</b>', 'format' => 'html', 'is_active' => 'f', 'updated_at' => '2026-10-15']];
Service_Prx597_Registry::forget_events();
function prx597_admin_render($tab, $placeholders = NULL, array $extra = [])
{
    $csrf = 'csrf-test'; $flash = ''; $stats = Service_Prx597_Engine::stats();
    $events = Service_Prx597_Registry::events();
    if ($placeholders !== NULL) { $events['prx120.job.done']['placeholders'] = $placeholders; }
    $events_on = $events;
    $templates = Service_Prx597_Registry::templates();
    $channels = [];
    foreach (Service_Prx597_Base::channels(FALSE) as $key => $row) {
        $channels[$key] = $row + ['enabled' => FALSE, 'whoami' => NULL, 'bot' => NULL];
    }
    $limits = Service_Prx597_Limits::table();
    $limits_extra = ['dedup_window' => 300, 'max_attempts' => 5, 'batch_limit' => 50, 'max_driver' => 'bot'];
    $cleanup = ['inbox' => 180, 'outbox' => 60, 'log' => 60]; $journal = []; $dead = [];
    // Шкала важности: строки и пороги — ровно как в Controller/Prx597/Admin.php.
    $priority        = Service_Prx597_Registry::priority_rows();
    $priority_skip   = (int) Service_Prx597_Registry::cfg('priority.quiet_skip', 1);
    $priority_digest = (int) Service_Prx597_Registry::cfg('priority.digest_from', 5);
    // Очередь и лента приходят из Engine; в стенде подкладываем по строке,
    // чтобы таблицы проверялись на данных, а не на пустоте.
    $queue = [
        [
            'id' => 41, 'user_id' => 7, 'event_alias' => 'prx120.job.done', 'channel' => 'email',
            'subject' => 'Задача выполнена', 'status' => 'pending', 'attempts' => 2,
            'next_attempt_at' => '2026-10-16 09:15:00', 'last_error' => 'Лимит канала: 20 в час',
            'reason' => 'Лимит канала: 20 в час',
        ],
        [
            'id' => 42, 'user_id' => 8, 'event_alias' => 'system.message', 'channel' => 'in_app',
            'subject' => '', 'status' => 'sending', 'attempts' => 0,
            'next_attempt_at' => '2026-10-16 09:05:00', 'last_error' => NULL,
            'reason' => 'Взято движком: доставка идёт прямо сейчас',
        ],
    ];
    $sent = [[
        'id' => 40, 'user_id' => 7, 'event_alias' => 'prx120.job.done', 'channel' => 'email',
        'subject' => 'Задача выполнена', 'created_at' => '2026-10-16 08:00:00',
        'response_code' => '250', 'message' => 'OK',
    ]];
    $feed = [[
        'id' => 39, 'user_id' => 8, 'kind' => 'system.message', 'title' => 'Объявление всем',
        'body' => "Первая строка\nвторая строка", 'read_at' => NULL, 'created_at' => '2026-10-16 07:30:00',
    ]];
    // Значения по умолчанию — как их передаёт контроллер: сработавшего
    // предохранителя нет, лента пуста.
    $feed_events  = [];
    $feed_cap     = Service_Prx597_Limits::limit('source_hour', 'email', 120);
    $source_alert = NULL;
    extract($extra, EXTR_OVERWRITE);
    ob_start();
    try { include dirname(__DIR__).'/application/modules/prx597_notifications/views/prx597/admin.php'; return ob_get_contents(); }
    finally { ob_end_clean(); }
}
set_error_handler(function ($code, $message) { throw new RuntimeException($message); }, E_WARNING | E_NOTICE);
check('реестр: placeholders остаётся CSV-строкой', Service_Prx597_Registry::event('prx120.job.done')['placeholders'], 'title,code,href');
foreach (Controller_Prx597_Admin::TABS as $tab)
{
    $html = prx597_admin_render($tab);
    check('админка: '.$tab.' рендерится без предупреждений', strpos($html, '>Управление</h1>') !== FALSE, TRUE);
}
$html = prx597_admin_render('templates');
check('шаблоны: подстановки показаны чипами для вставки', strpos($html, 'data-prx597-insert="{{title}}"') !== FALSE, TRUE);
check('шаблоны: чип есть и для глобальной подстановки', strpos($html, 'data-prx597-insert="{{user_name}}"') !== FALSE, TRUE);
check('шаблоны: есть поиск по событиям', strpos($html, 'data-prx597-tpl-filter') !== FALSE, TRUE);
check('шаблоны: есть фильтр «только без текста»', strpos($html, 'data-prx597-tpl-gaps') !== FALSE, TRUE);
check('шаблоны: счётчик заполненности показан', strpos($html, 'data-prx597-tpl-count') !== FALSE && strpos($html, 'заполнено') !== FALSE, TRUE);
check('шаблоны: незаполненный канал помечен «нет текста»', strpos($html, 'нет текста') !== FALSE, TRUE);
check('шаблоны: карточка события сворачивается', strpos($html, '<details open') !== FALSE && strpos($html, 'data-prx597-tpl-filled=') !== FALSE, TRUE);
check('шаблоны: заполненный шаблон посчитан в карточке', strpos($html, '1 из 4 каналов') !== FALSE, TRUE);
check('шаблоны: сохранённая тема не теряется', strpos($html, 'value="Тема &quot;письма&quot;"') !== FALSE, TRUE);
check('шаблоны: сохранённый текст экранирован', strpos($html, '&lt;b&gt;{{title}}&lt;/b&gt;') !== FALSE, TRUE);
check('шаблоны: сохранённый формат выбран', preg_match('/value="html"\s+selected/', $html), 1);
check('шаблоны: сохранённая дата показана', strpos($html, 'изменён: 2026-10-15') !== FALSE, TRUE);
preg_match('~<form[^>]*data-prx597-tpl-channel="email"[^>]*>(.*?)</form>~s', $html, $email_form);
check('шаблоны: выключенный шаблон не включается в форме', preg_match('/name="is_active"[^>]*checked/', $email_form[1]), 0);
check('шаблоны: без подстановок чипы глобальных остаются', strpos(prx597_admin_render('templates', ''), 'data-prx597-insert="{{site_url}}"') !== FALSE, TRUE);
check('шаблоны: массив подстановок поддержан', strpos(prx597_admin_render('templates', ['title', 'count']), 'data-prx597-insert="{{count}}"') !== FALSE, TRUE);
check('шаблоны: подстановки не исполняются как HTML', strpos(prx597_admin_render('templates', '<img src=x>'), '&lt;img src=x&gt;') !== FALSE, TRUE);
$html = prx597_admin_render('templates');
check('шаблоны: когда ИИ не настроен, панель говорит об этом', strpos($html, 'Составить шаблон с ИИ') !== FALSE && strpos($html, 'ИИ недоступен') !== FALSE, TRUE);
check('админка: вкладка очереди и отправки есть в списке', in_array('queue', Controller_Prx597_Admin::TABS, TRUE), TRUE);

// Предохранитель и «за час» видны администратору на вкладке очереди.
$html = prx597_admin_render('queue', NULL, [
    'feed_events'  => [
        ['event_alias' => 'prx667.loop', 'c' => 940, 'c_hour' => 180, 'last_at' => '2026-10-16 10:00:00'],
        ['event_alias' => 'prx593.review.done', 'c' => 12, 'c_hour' => 0, 'last_at' => '2026-10-16 09:00:00'],
    ],
    'source_alert' => ['alias' => 'prx667.loop', 'channel' => 'email', 'count' => 121, 'limit' => 120, 'at' => time()],
]);
check('очередь: нагрузка за час показана', strpos($html, 'за час: 180') !== FALSE, TRUE);
check('очередь: шумное событие названо', strpos($html, 'prx667.loop') !== FALSE, TRUE);
check('очередь: предупреждение о предохранителе есть', strpos($html, 'Предохранитель') !== FALSE, TRUE);
check('очередь: в предупреждении счётчик и лимит', strpos($html, '121') !== FALSE && strpos($html, '120') !== FALSE, TRUE);
check('очередь: канал назван по-человечески', strpos($html, 'Электронная почта') !== FALSE, TRUE);
check('очередь: из предупреждения ведут к событиям', strpos($html, '/prx597/admin?tab=events') !== FALSE, TRUE);
check('очередь: тихое событие без отметки «за час»', substr_count($html, 'за час:'), 1);
check('очередь: имя события экранировано', strpos(prx597_admin_render('queue', NULL, [
    'feed_events' => [['event_alias' => '<img src=x>', 'c' => 1, 'c_hour' => 1, 'last_at' => '2026-10-16 10:00:00']],
])['0'], '<img src=x>'), FALSE);

$html = prx597_admin_render('queue');
check('очередь: без предохранителя предупреждения нет', strpos($html, 'Предохранитель'), FALSE);
ORM::$users = [7 => 'user@example.test', 8 => 'other@example.test'];
$html = prx597_admin_render('queue');
check('очередь: кто ждёт и почему видно', strpos($html, 'Лимит канала: 20 в час') !== FALSE && strpos($html, 'Очередь доставки') !== FALSE, TRUE);
check('очередь: получатель показан человеком, а не только id', strpos($html, 'user@example.test') !== FALSE, TRUE);
check('очередь: статусы pending и sending подписаны по-разному', strpos($html, 'в очереди') !== FALSE && strpos($html, 'отправляется') !== FALSE, TRUE);
check('очередь: причина ожидания у sending тоже видна', strpos($html, 'Взято движком: доставка идёт прямо сейчас') !== FALSE, TRUE);
check('очередь: есть кнопка прохода доставки', strpos($html, 'value="run"') !== FALSE, TRUE);
check('отправка: журнал за сутки отдаёт код и текст ответа', strpos($html, 'Отправлено за сутки (каналы)') !== FALSE && strpos($html, '250') !== FALSE, TRUE);
check('лента: видно, кому и что ушло в колокольчик', strpos($html, 'В ленту за сутки (колокольчик)') !== FALSE && strpos($html, 'Объявление всем') !== FALSE, TRUE);
check('лента: многострочный текст схлопнут в строку', strpos($html, 'Первая строка вторая строка') !== FALSE, TRUE);
$html = prx597_admin_render('summary');
check('сводка: «в очереди» ведёт на разбор очереди', strpos($html, '/prx597/admin?tab=queue#queue') !== FALSE, TRUE);
check('сводка: отправленное ведёт к журналу каналов', strpos($html, '/prx597/admin?tab=queue#sent') !== FALSE, TRUE);
check('сводка: лента за сутки показана отдельной цифрой', strpos($html, 'В ленту за сутки') !== FALSE, TRUE);
// Автозаполнение: владелец просил убрать подсказки браузера во всех формах.
$forms_total = 0; $forms_no_ac = 0; $fields_no_ac = []; $inputs_total = 0;
foreach (Controller_Prx597_Admin::TABS as $tab) {
    $html = prx597_admin_render($tab);
    preg_match_all('~<form\b[^>]*>~', $html, $forms);
    foreach ($forms[0] as $tag) {
        $forms_total++;
        if (strpos($tag, 'autocomplete="off"') === FALSE) { $forms_no_ac++; }
    }
    preg_match_all('~<(input|textarea)\b[^>]*>~s', $html, $fields);
    foreach ($fields[0] as $tag) {
        if (preg_match('~type="(hidden|checkbox|radio|submit|button|file|image)"~', $tag)) { continue; }
        $inputs_total++;
        if (strpos($tag, 'autocomplete=') === FALSE) { $fields_no_ac[] = $tag; }
    }
    check('админка: '.$tab.' — без autocomplete="on"', strpos($html, 'autocomplete="on"') === FALSE, TRUE);
}
check('админка: формы есть (проверка не пустая)', $forms_total > 10, TRUE);
check('админка: у всех форм выключено автозаполнение', $forms_no_ac, 0);
check('админка: у всех текстовых полей выключено автозаполнение', $inputs_total > 10 ? count($fields_no_ac) : -1, 0);

// ИИ-помощник. Сначала — честный отказ, когда провайдер не настроен.
putenv('LOCAL_API_URL');
$ai_ready = Service_Prx597_Ai::ready($ai_why);
check('ИИ: без адреса провайдера помощник честно недоступен', $ai_ready, FALSE);
check('ИИ: причина отказа названа человеку', strpos((string) $ai_why, 'LOCAL_API_URL') !== FALSE, TRUE);
$html = prx597_admin_render('templates');
check('ИИ: при недоступном ИИ панель объясняет причину', strpos($html, 'ИИ недоступен') !== FALSE && strpos($html, 'LOCAL_API_URL') !== FALSE, TRUE);
check('ИИ: без ИИ форма сборки не показывается', strpos($html, 'data-prx597-ai-run') === FALSE, TRUE);

// Теперь как на боевом сайте: адрес модели задан, модель отвечает JSON.
putenv('LOCAL_API_URL=http://127.0.0.1:1234/v1');
if ( ! class_exists('Llm')) {
    class Llm
    {
        public static $answer = '{"subject":"Обзор ждёт публикации","body":"Здравствуйте! Обзор собран и ждёт публикации.","notes":"коротко, на вы"}';
        public static $calls = [];
        public static function chat($messages, $options = [])
        {
            self::$calls[] = [$messages, $options];
            return ['choices' => [['message' => ['content' => self::$answer]]]];
        }
    }
}
check('ИИ: с настроенным провайдером помощник доступен', Service_Prx597_Ai::ready($ai_why2), TRUE);
check('ИИ: модель показана в интерфейсе', strpos(Service_Prx597_Ai::model_label(), '::') !== FALSE, TRUE);
$html = prx597_admin_render('templates');
check('ИИ: панель предлагает вставить имеющееся сообщение', strpos($html, 'name="source"') !== FALSE && strpos($html, 'data-prx597-ai-run') !== FALSE, TRUE);
check('ИИ: текст подсказки про «имеющееся сообщение» на месте', strpos($html, 'Имеющееся сообщение') !== FALSE, TRUE);
check('ИИ: в панели есть событие, канал и тон', strpos($html, 'name="event"') !== FALSE && strpos($html, 'name="channel"') !== FALSE && strpos($html, 'name="tone"') !== FALSE, TRUE);
check('ИИ: результат можно подставить в карточку и пересобрать', strpos($html, 'data-prx597-ai-apply') !== FALSE && strpos($html, 'data-prx597-ai-again') !== FALSE, TRUE);
check('ИИ: ни одно поле панели не автозаполняется', substr_count($html, 'data-prx597-ai '), 1);
check('ИИ: можно взять готовое сообщение за сутки', strpos($html, 'data-prx597-ai-recent') !== FALSE && strpos($html, 'Взять готовое сообщение') !== FALSE, TRUE);
check('ИИ: в списке источник — и лента, и отправленное', strpos($html, 'В ленте: Объявление всем') !== FALSE && strpos($html, 'Отправлено (') !== FALSE, TRUE);

Llm::$calls = [];
$ai = Service_Prx597_Ai::compose('prx120.job.done', 'email', str_repeat('Напоминаем: обзор собран. ', 4), 'short', 'обзор ждёт публикации', 7);
check('ИИ: шаблон собран', $ai['ok'], TRUE);
check('ИИ: тема письма взята из ответа модели', $ai['subject'], 'Обзор ждёт публикации');
check('ИИ: текст письма взят из ответа модели', $ai['body'], 'Здравствуйте! Обзор собран и ждёт публикации.');
check('ИИ: подсказка «о чём уведомляем» ушла в запрос', strpos(Llm::$calls[0][0][1]['content'], 'обзор ждёт публикации') !== FALSE, TRUE);
check('ИИ: имеющееся сообщение ушло в запрос целиком', strpos(Llm::$calls[0][0][1]['content'], 'Напоминаем: обзор собран.') !== FALSE, TRUE);
check('ИИ: подстановки события перечислены модели', strpos(Llm::$calls[0][0][0]['content'], '{{title}}') !== FALSE, TRUE);
check('ИИ: тон передан словами', strpos(Llm::$calls[0][0][0]['content'], 'максимально сжато') !== FALSE, TRUE);
check('ИИ: письму без разметки оставлен текстовый формат', $ai['format'], 'text');
check('ИИ: ответ помечен событием и каналом для подстановки', $ai['event_alias'].'/'.$ai['channel'], 'prx120.job.done/email');
check('ИИ: время ответа измерено', $ai['ms'] >= 0, TRUE);

Llm::$answer = '{"subject":"","body":"Здравствуйте! Обзор готов.","notes":"","extra":"мусор"}';
$ai = Service_Prx597_Ai::compose('prx120.job.done', 'in_app', str_repeat('Обзор готов. ', 5), 'neutral', '', 7);
check('ИИ: для колокольчика тема не выдумывается', $ai['subject'], '');
check('ИИ: лишние ключи ответа не ломают шаблон', $ai['body'], 'Здравствуйте! Обзор готов.');

Llm::$answer = '```json\n{"subject":"Тема","body":"Текст с {{title}} и {{foo.bar}}","notes":"учтено"}\n```';
$ai = Service_Prx597_Ai::compose('prx120.job.done', 'telegram', str_repeat('Текст сообщения. ', 4), 'friendly', '', 7);
check('ИИ: ```-обёртка вокруг JSON разобрана', $ai['ok'], TRUE);
check('ИИ: знакомые подстановки не считаются ошибкой', $ai['used'], ['title', 'foo.bar']);
check('ИИ: выдуманная подстановка названа в примечании', strpos($ai['notes'], 'foo.bar') !== FALSE, TRUE);
check('ИИ: чужие подстановки перечислены отдельно', $ai['unknown'], ['foo.bar']);

Llm::$answer = 'Извините, я не могу помочь с этим запросом.';
$ai = Service_Prx597_Ai::compose('prx120.job.done', 'email', str_repeat('Текст письма. ', 4), 'friendly', '', 7);
check('ИИ: ответ прозой не превращается в шаблон', $ai['ok'], FALSE);
check('ИИ: про формат ответа сказано человеку', strpos($ai['error'], 'не в формате') !== FALSE, TRUE);
check('ИИ: сырой ответ модели показан администратору', strpos($ai['raw'], 'Извините') !== FALSE, TRUE);

$ai = Service_Prx597_Ai::compose('prx120.job.done', 'email', 'коротко', 'friendly', '', 7);
check('ИИ: слишком короткое сообщение не уходит в модель', $ai['ok'], FALSE);
check('ИИ: про слишком короткий текст сказано человеку', strpos($ai['error'], 'хотя бы пару строк') !== FALSE, TRUE);

$ai = Service_Prx597_Ai::compose('нет.такого', 'email', str_repeat('Текст письма. ', 4), 'friendly');
check('ИИ: незнакомое событие не роняет помощник', strpos($ai['error'], 'не найдено') !== FALSE, TRUE);

check('ИИ: разбор ответа модели устойчив к ```-обёртке', Service_Prx597_Ai::parse_answer('```json\n{"subject":"Тема","body":"Текст письма","notes":"учтено"}\n```')['body'], 'Текст письма');
check('ИИ: JSON в прозе тоже находится', Service_Prx597_Ai::parse_answer('Вот результат: {"subject":"Тема","body":"Текст"} — готово')['subject'], 'Тема');
check('ИИ: мусор вместо JSON не считается шаблоном', Service_Prx597_Ai::parse_answer('никакого json тут нет'), []);
check('ИИ: обрезка текста считает символы, а не байты', mb_strlen(Service_Prx597_Ai::cut_text(str_repeat('я', 3000), 100)), 100);

$html = prx597_admin_render('message');
check('сообщение: отдельная форма одного получателя', strpos($html, 'name="recipient" required') !== FALSE, TRUE);
check('сообщение: есть CSRF', strpos($html, 'name="csrf" value="csrf-test"') !== FALSE, TRUE);
check('сообщение: нет действия массовой рассылки', strpos($html, 'value="notify_all"') === FALSE, TRUE);
check('сообщение: настройки пользователя описаны честно', strpos($html, 'даже при выключенных уведомлениях') !== FALSE, TRUE);

ORM::$users = [7 => 'user@example.test', 8 => 'other@example.test', 5 => 'admin@example.test'];
$payload = ['act' => 'notify_user', 'tab' => 'message', 'recipient' => '7', 'title' => 'Сообщение от системы', 'body' => 'Проверка', 'csrf' => 'csrf-test'];
Auth::$user = NULL;
$c = new Prx597_Admin_Controller($payload);
try { $c->post(); check('аноним: закрыто', FALSE, TRUE); }
catch (RuntimeException $e) { check('аноним: закрыто', $e->getCode(), 403); }
Auth::$user = (object) ['id' => 7]; Controller_Prx102_Base::$allowed = FALSE;
try { $c->post(); check('обычный пользователь: закрыто', FALSE, TRUE); }
catch (RuntimeException $e) { check('обычный пользователь: закрыто', $e->getCode(), 403); }
check('без права сообщение не записывается', count($db->inserts), 0);
Controller_Prx102_Base::$allowed = TRUE; Auth::$user = (object) ['id' => 5];
$c = new Prx597_Admin_Controller(array_merge($payload, ['csrf' => 'bad'])); $c->post();
check('плохой CSRF: сообщение не записывается', count($db->inserts), 0);
check('плохой CSRF: понятная ошибка', strpos(urldecode($c->location), 'Сессия устарела') !== FALSE, TRUE);
$c = new Prx597_Admin_Controller($payload + ['admin_id' => 999]); $c->post();
check('админ: одна запись', count($db->inserts), 1);
$sql = $db->inserts[0];
check('админ: запись нужному пользователю', strpos($sql, 'VALUES (7,') !== FALSE, TRUE);
check('админ: событие от системы', strpos($sql, "'system.admin_message'") !== FALSE, TRUE);
check('админ: настоящий автор в аудите, не из POST', strpos($sql, '"admin_id":5') !== FALSE, TRUE);
check('админ: нет внешней очереди', strpos(implode("\n", $db->queries), 'INSERT INTO "prx_597_outbox"') === FALSE, TRUE);
check('админ: email_status none', strpos($sql, "'none'") !== FALSE, TRUE);
check('админ: нет read_at — новое непрочитанное', strpos($sql, 'read_at') === FALSE, TRUE);
check('админ: возврат на вкладку сообщения', strpos($c->location, '/prx597/admin?tab=message&flash=') === 0, TRUE);
check('админ: подтверждение получателя', strpos(urldecode($c->location), 'пользователя #7') !== FALSE, TRUE);
$res = Service_Prx597_Notify::system_message('other@example.test', 'Тема', 'Текст', 5);
check('точный email: второй адресат найден', $res['user_id'], 8);
check('email: ровно ещё одно сообщение', count($db->inserts), 2);
$res = Service_Prx597_Notify::system_message('5', 'Себе', 'Проверка', 5);
check('админ может написать себе', $res['user_id'], 5);
foreach (['', '0', '-1', '999', 'user@missing.test', '7 OR 1=1', '99999999999999999999999999'] as $recipient)
{
    $before = count($db->inserts);
    $res = Service_Prx597_Notify::system_message($recipient, 'Тема', 'Текст', 5);
    check('некорректный получатель: '.($recipient ?: '(пусто)'), isset($res['error']) && count($db->inserts) === $before, TRUE);
}
foreach ([['', 'Текст'], ['Тема', ' '], [str_repeat('я', 181), 'Текст'], ['Тема', str_repeat('я', 2001)]] as $i => $texts)
{
    $before = count($db->inserts);
    $res = Service_Prx597_Notify::system_message('7', $texts[0], $texts[1], 5);
    check('валидация текста '.$i, isset($res['error']) && count($db->inserts) === $before, TRUE);
}
$db->fail = TRUE;
$c = new Prx597_Admin_Controller($payload); $c->post();
check('ошибка БД: нет ложного успеха', strpos(urldecode($c->location), 'Не отправлено') !== FALSE, TRUE);
$db->fail = FALSE; $db->insert_id = 0;
check('нет RETURNING id: отказ', isset(Service_Prx597_Notify::system_message('7', 'Тема', 'Текст', 5)['error']), TRUE);
$html = prx597_render('feed', Service_Prx597_Base::user_channels(), [], [[
    'id' => 81, 'event_alias' => 'system.admin_message', 'title' => '<img src=x>', 'body' => '<script>alert(1)</script>',
    'href' => '/prx597', 'created_at' => '2026-10-15 12:00:00', 'read_at' => NULL,
]]);
check('получатель: подпись От системы', strpos($html, 'От системы') !== FALSE, TRUE);
check('получатель: текст и заголовок экранированы', strpos($html, '&lt;script&gt;') !== FALSE && strpos($html, '&lt;img src=x&gt;') !== FALSE, TRUE);
// --- Шкала важности: блок на вкладке «Лимиты» и порядок кнопками -------------
$db->settings = [['key' => 'priority.by_category', 'value' => 'security=1,billing=2,system=3,jobs=5,content=6,social=7']];
Service_Prx597_Base::forget_settings();
$limits_html = prx597_admin_render('limits');
check('важность: блок шкалы есть на вкладке «Лимиты»', strpos($limits_html, 'Важность событий по категориям') !== FALSE, TRUE);
check('важность: категории подписаны по-русски', strpos($limits_html, 'Задачи и отчёты') !== FALSE, TRUE);
check('важность: у каждой категории две стрелки', substr_count($limits_html, 'name="move"'), 12);
check('важность: верхняя стрелка у самой важной выключена', strpos($limits_html, 'value="security:-1" disabled') !== FALSE, TRUE);
check('важность: нижняя стрелка у самой тихой выключена', strpos($limits_html, 'value="social:1" disabled') !== FALSE, TRUE);
check('важность: подсказка называет порог тихих часов', strpos($limits_html, 'не ждёт тихих часов') !== FALSE, TRUE);
check('важность: подсказка называет порог сводки', strpos($limits_html, 'может уехать в суточную сводку') !== FALSE, TRUE);
check('важность: объяснено, почему числа не меняются', strpos($limits_html, 'Числа берутся из конфига модуля') !== FALSE, TRUE);
check('важность: форма помнит, куда вернуться', strpos($limits_html, '<input type="hidden" name="act" value="priority_move">') !== FALSE, TRUE);

check('важность: строки идут от важного к фону', array_column(Service_Prx597_Registry::priority_rows(), 'priority'), [1, 2, 3, 5, 6, 7]);
check('важность: событие берёт число из категории', Service_Prx597_Registry::priority_for(['category' => 'jobs']), 5);
check('важность: названное модулем число сильнее категории', Service_Prx597_Registry::priority_for(['category' => 'social'], 1), 1);
check('важность: неизвестная категория — обычная', Service_Prx597_Registry::priority_for(['category' => 'нет-такой']), 5);

// «Задачи» поднимаются выше «Системных»: числа переставляются местами, набор
// значений остаётся конфиговым — пороги тихих часов и сводки не двигаются.
check('важность: категорию можно поднять', Service_Prx597_Registry::priority_move('jobs', -1), 3);
check('важность: порядок после подъёма', array_column(Service_Prx597_Registry::priority_rows(), 'category'),
    ['security', 'billing', 'jobs', 'system', 'content', 'social']);
check('важность: лестница чисел та же', array_column(Service_Prx597_Registry::priority_rows(), 'priority'), [1, 2, 3, 5, 6, 7]);
check('важность: в настройки записан весь порядок',
    Service_Prx597_Base::setting('priority.by_category', ''), 'security=1,billing=2,jobs=3,system=5,content=6,social=7');
check('важность: событие поднятой категории стало важнее', Service_Prx597_Registry::priority_for(['category' => 'jobs']), 3);
check('важность: выше самого важного не поднять', Service_Prx597_Registry::priority_move('security', -1), 0);
check('важность: ниже самого тихого не опустить', Service_Prx597_Registry::priority_move('social', 1), 0);
check('важность: незнакомая категория не двигается', Service_Prx597_Registry::priority_move('нет-такой', 1), 0);
check('важность: битая настройка не ломает шкалу',
    implode(' ', array_column(Service_Prx597_Registry::priority_map(), NULL)) !== '', TRUE);

restore_error_handler();
Database::$db = NULL; ORM::$users = []; Service_Prx597_Base::forget_settings(); Service_Prx597_Registry::forget_events();
