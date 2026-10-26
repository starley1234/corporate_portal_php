<?php
/**
 * Дополнение nj597_engine_stand: уборка очереди и ленты администратором.
 *
 * Владелец (16.10.2026): «может дать возможность администратору очистить
 * неотправленные сообщения — я смотрю там много мусорных» и показал вкладку
 * «Очередь» с таблицей ленты.
 *
 * Здесь проверяем поведение, а не наличие SQL: заглушка ведёт себя как
 * настоящие таблицы (удаляет строки, считает, помечает колокольчик), поэтому
 * проверки читаются как «после уборки осталось то-то».
 */
echo "\n-- уборка очереди и ленты --\n";

/** Ответ заглушки: тот же контракт, что у драйвера (as_array/current/get). */
class Nj597_Queue_Result
{
    public $rows;

    public function __construct($rows) { $this->rows = $rows; }

    public function as_array() { return $this->rows; }

    public function current() { return $this->rows ? $this->rows[0] : FALSE; }

    public function get($key, $default = NULL) { return $this->rows ? ($this->rows[0][$key] ?? $default) : $default; }
}

/**
 * Заглушка базы: очередь, лента, счётчики непрочитанного.
 *
 * Драйвер вызывается только методами движка, поэтому разбираем SQL по
 * ключевым словам — ровно тем запросам, которые движок и шлёт.
 */
class Nj597_Queue_Db
{
    /** Строки очереди: id, status, channel, user_id, event_alias, inbox_id. */
    public $outbox = [];

    /** Лента: id, user_id, event_alias, read_at, email_status. */
    public $feed = [];

    /** Счётчик непрочитанного: user_id => число. */
    public $unread = [];

    /** Вёдра лимитов: bucket => count (как таблица nj_597_rate). */
    public $rate = [];

    /** Настройки администратора: key => value (таблица nj_597_settings). */
    public $settings = [];

    /** Присутствие: user_id => TRUE (таблица nj_597_presence). */
    public $presence = [];

    public $sql = [];

    public function quote($value) { return "'".str_replace("'", "''", (string) $value)."'"; }

    /** Значения из «IN (...)» — так заглушка узнаёт, что именно удаляют. */
    protected function _in_list($sql, $column = 'status')
    {
        if ( ! preg_match('~"'.preg_quote($column, '~').'" IN \(([^)]*)\)~i', $sql, $m))
        {
            return [];
        }

        return array_map(function ($one) { return trim($one, " '\t\n"); }, explode(',', $m[1]));
    }

    /** Числа из «IN (1, 2, 3)» (для id и user_id). */
    protected function _ids($sql, $column)
    {
        return array_map('intval', $this->_in_list($sql, $column));
    }

    public function query($type, $sql, $unused = FALSE)
    {
        $this->sql[] = $sql;

        // Счётчик непрочитанного движок правит двумя запросами без выборки:
        // сначала пересчитывает по факту, потом обнуляет там, где не осталось
        // ничего. Заглушка считает то же самое — по своему набору ленты.
        if ($type !== Database::SELECT)
        {
            // Настройки: запись надо запомнить — по ним панель узнаёт
            // о сработавшем предохранителе (alert.source).
            if (strpos($sql, 'nj_597_settings') !== FALSE)
            {
                preg_match_all("~'((?:[^']|'')*)'~", $sql, $sm);
                $vals = array_map(function ($v) { return str_replace("''", "'", $v); }, $sm[1]);

                if (count($vals) >= 2)
                {
                    $this->settings[$vals[0]] = $vals[1];
                }

                return 1;
            }

            if (strpos($sql, 'nj_597_presence') !== FALSE)
            {
                // Уборка старых отметок.
                if (strpos($sql, 'DELETE') !== FALSE)
                {
                    $this->presence = [];

                    return 1;
                }

                // Метка присутствия: INSERT ... VALUES (<user_id>, now()).
                if (preg_match('~VALUES \((\d+)~', $sql, $m))
                {
                    $this->presence[(int) $m[1]] = TRUE;
                }

                return 1;
            }

            if (strpos($sql, 'nj_597_unread') !== FALSE)
            {
                foreach (array_unique($this->_ids($sql, 'user_id')) as $user_id)
                {
                    $count = 0;

                    foreach ($this->feed as $row)
                    {
                        if ((int) $row['user_id'] === (int) $user_id AND $row['read_at'] === NULL)
                        {
                            $count++;
                        }
                    }

                    $this->unread[$user_id] = $count;
                }
            }

            return 1;
        }

        // --- настройки и вёдра -------------------------------------------------
        if (strpos($sql, 'nj_597_settings') !== FALSE)
        {
            $rows = [];

            foreach ($this->settings as $key => $value)
            {
                $rows[] = ['key' => $key, 'value' => $value];
            }

            return new Nj597_Queue_Result($rows);
        }

        // Присутствие читает только один запрос: «видели ли этого человека
        // за последние N секунд».
        if (strpos($sql, 'nj_597_presence') !== FALSE)
        {
            if ( ! preg_match('~"user_id" = (\d+)~', $sql, $m))
            {
                return new Nj597_Queue_Result([]);
            }

            return isset($this->presence[(int) $m[1]])
                ? new Nj597_Queue_Result([['seen_at' => '2026-10-16 07:00:00']])
                : new Nj597_Queue_Result([]);
        }

        if (strpos($sql, 'nj_597_rate') !== FALSE)
        {
            // Движок и увеличивает ведро, и читает его через _one() — то есть
            // типом SELECT. Поэтому смотрим на текст: upsert — «списать
            // попытку», «bucket" = …» — «сколько уже накапало».
            if (strpos($sql, 'ON CONFLICT') !== FALSE)
            {
                preg_match("~VALUES \('((?:[^']|'')*)'~", $sql, $bm);
                $bucket = empty($bm[1]) ? '' : str_replace("''", "'", $bm[1]);

                if ($bucket === '')
                {
                    return new Nj597_Queue_Result([]);
                }

                $this->rate[$bucket] = ($this->rate[$bucket] ?? 0) + 1;

                return new Nj597_Queue_Result([['count' => $this->rate[$bucket]]]);
            }

            preg_match('~"bucket" = \'((?:[^\']|\'\')*)\'~', $sql, $bm);
            $bucket = empty($bm[1]) ? '' : str_replace("''", "'", $bm[1]);

            return new Nj597_Queue_Result($bucket === '' ? [] : [['count' => $this->rate[$bucket] ?? 0]]);
        }

        // --- очередь -----------------------------------------------------------
        if (strpos($sql, 'SELECT DISTINCT "inbox_id" FROM "nj_597_outbox"') !== FALSE)
        {
            $statuses = $this->_in_list($sql);
            $ids = [];

            foreach ($this->outbox as $row)
            {
                if (in_array($row['status'], $statuses, TRUE) AND $row['inbox_id'] > 0)
                {
                    $ids[$row['inbox_id']] = ['inbox_id' => $row['inbox_id']];
                }
            }

            return new Nj597_Queue_Result(array_values($ids));
        }

        if (strpos($sql, 'DELETE FROM "nj_597_outbox"') !== FALSE)
        {
            $statuses = $this->_in_list($sql);
            $left = [];
            $gone = 0;

            foreach ($this->outbox as $row)
            {
                if (in_array($row['status'], $statuses, TRUE)) { $gone++; continue; }

                $left[] = $row;
            }

            $this->outbox = $left;

            return new Nj597_Queue_Result([['c' => $gone]]);
        }

        // --- лента -------------------------------------------------------------
        if (strpos($sql, 'GROUP BY "event_alias"') !== FALSE)
        {
            $sizes = [];

            foreach ($this->feed as $row)
            {
                $sizes[$row['event_alias']] = ($sizes[$row['event_alias']] ?? 0) + 1;
            }

            arsort($sizes);
            $out = [];

            foreach ($sizes as $alias => $count)
            {
                $out[] = ['event_alias' => $alias, 'c' => $count, 'last_at' => '2026-10-16 10:00:00'];
            }

            return new Nj597_Queue_Result($out);
        }

        if (strpos($sql, 'SELECT DISTINCT "user_id" FROM "nj_597_notifications"') !== FALSE)
        {
            $alias = $this->_alias($sql);
            $out = [];

            foreach ($this->feed as $row)
            {
                if ($row['event_alias'] === $alias AND ! isset($out[$row['user_id']]))
                {
                    $out[$row['user_id']] = ['user_id' => $row['user_id']];
                }
            }

            return new Nj597_Queue_Result(array_values($out));
        }

        if (strpos($sql, 'DELETE FROM "nj_597_notifications"') !== FALSE)
        {
            $alias = $this->_alias($sql);
            $left = [];
            $users = [];
            $gone = 0;

            foreach ($this->feed as $row)
            {
                if ($row['event_alias'] === $alias)
                {
                    $gone++;
                    $users[$row['user_id']] = TRUE;
                    continue;
                }

                $left[] = $row;
            }

            $this->feed = $left;

            return new Nj597_Queue_Result([['c' => $gone, 'users' => count($users)]]);
        }

        // Метка «ждёт письма» на строке колокольчика.
        if (strpos($sql, 'UPDATE "nj_597_notifications"') !== FALSE)
        {
            $ids = $this->_ids($sql, 'id');
            $marked = 0;

            foreach ($this->feed as $i => $row)
            {
                if (in_array((int) $row['id'], $ids, TRUE) AND $row['email_status'] !== 'sent')
                {
                    $this->feed[$i]['email_status'] = 'none';
                    $marked++;
                }
            }

            return new Nj597_Queue_Result([['c' => $marked]]);
        }

        return new Nj597_Queue_Result([]);
    }

    /** Код события из «WHERE "event_alias" = '…'». */
    protected function _alias($sql)
    {
        return preg_match('~"event_alias" = \'([^\']*)\'~', $sql, $m) ? str_replace("''", "'", $m[1]) : '';
    }
}

$queue_db = Database::$db = new Nj597_Queue_Db;

/** Разложить очередь и ленту заново (в стенде база общая на все проверки). */
function nj597q_fixture()
{
    global $queue_db;

    $queue_db->outbox = [
        ['id' => 1, 'status' => 'pending', 'channel' => 'email',    'user_id' => 1, 'event_alias' => 'nj667.build.done', 'inbox_id' => 11],
        ['id' => 2, 'status' => 'sending', 'channel' => 'telegram', 'user_id' => 1, 'event_alias' => 'nj667.build.done', 'inbox_id' => 11],
        ['id' => 3, 'status' => 'failed',  'channel' => 'email',    'user_id' => 2, 'event_alias' => 'nj607.order',      'inbox_id' => 0],
        ['id' => 4, 'status' => 'dead',    'channel' => 'max',      'user_id' => 2, 'event_alias' => 'nj607.order',      'inbox_id' => 0],
        ['id' => 5, 'status' => 'sent',    'channel' => 'email',    'user_id' => 3, 'event_alias' => 'nj593.review.done', 'inbox_id' => 0],
        ['id' => 6, 'status' => 'skipped', 'channel' => 'email',    'user_id' => 3, 'event_alias' => 'nj593.review.done', 'inbox_id' => 0],
    ];

    $queue_db->feed = [
        ['id' => 11, 'user_id' => 1, 'event_alias' => 'nj667.build.done', 'read_at' => NULL,   'email_status' => 'pending'],
        ['id' => 12, 'user_id' => 1, 'event_alias' => 'nj597.legacy.nj605_radar', 'read_at' => NULL, 'email_status' => 'none'],
        ['id' => 13, 'user_id' => 2, 'event_alias' => 'nj597.legacy.nj605_radar', 'read_at' => NULL, 'email_status' => 'none'],
        ['id' => 14, 'user_id' => 2, 'event_alias' => 'nj597.legacy.nj605_radar', 'read_at' => '2026-10-16 09:00:00', 'email_status' => 'none'],
    ];

    $queue_db->unread = [1 => 2, 2 => 2];
    $queue_db->sql = [];
}

// --- 1. Отправленное и пропущенное уборка не трогает -------------------------
nj597q_fixture();
$res = Service_Nj597_Engine::purge_unsent('waiting');
check('уборка: снято только ожидающее', $res['waiting'], 2);
check('уборка: недоставленное не тронуто', $res['failed'], 0);
check('уборка: отправленное осталось', count(array_filter($queue_db->outbox, function ($r) { return $r['status'] === 'sent'; })), 1);
check('уборка: пропущенное осталось', count(array_filter($queue_db->outbox, function ($r) { return $r['status'] === 'skipped'; })), 1);
check('уборка: в очереди осталось четыре строки', count($queue_db->outbox), 4);
check('уборка: метка «ждёт письма» снята у отменённого', $queue_db->feed[0]['email_status'], 'none');
check('уборка: чужая запись ленты не тронута', $queue_db->feed[1]['email_status'], 'none');

// --- 2. Недоставленное (failed + dead) — своя кнопка -------------------------
nj597q_fixture();
$res = Service_Nj597_Engine::purge_unsent('failed');
check('уборка: снято недоставленное', $res['failed'], 2);
check('уборка: ожидающее осталось', count($queue_db->outbox), 4);
check('уборка: DLQ больше нет', count(array_filter($queue_db->outbox, function ($r) { return $r['status'] === 'dead'; })), 0);

// --- 3. «Всё неотправленное» -------------------------------------------------
nj597q_fixture();
$res = Service_Nj597_Engine::purge_unsent('all');
check('уборка: всего снято', $res['total'], 4);
check('уборка: осталась только история', count($queue_db->outbox), 2);

// --- 4. Незнакомая область — ничего не удаляем ------------------------------
nj597q_fixture();
$res = Service_Nj597_Engine::purge_unsent('совсем-другое');
check('уборка: непонятная область — пустой результат', $res['total'], 0);
check('уборка: непонятная область — очередь цела', count($queue_db->outbox), 6);
check('уборка: непонятная область названа в ответе', $res['scope'], 'unknown');

// --- 5. Что наполняет ленту --------------------------------------------------
nj597q_fixture();
$events = Service_Nj597_Engine::feed_events(7, 5);
check('лента: события сгруппированы', count($events), 2);
check('лента: мусорное событие сверху', $events[0]['event_alias'], 'nj597.legacy.nj605_radar');
check('лента: у мусорного события три записи', (int) $events[0]['c'], 3);
check('лента: у второго события одна запись', (int) $events[1]['c'], 1);

// --- 6. Уборка одного события из ленты --------------------------------------
nj597q_fixture();
$res = Service_Nj597_Engine::purge_feed_event('nj597.legacy.nj605_radar');
check('лента: убрано три записи', $res['deleted'], 3);
check('лента: затронуто два получателя', $res['users'], 2);
check('лента: чужое событие осталось', count($queue_db->feed), 1);
check('лента: счётчик непрочитанного сверен', $queue_db->unread[1], 1);
check('лента: у второго прочитанное стало нулём', $queue_db->unread[2], 0);
check('лента: очередь уборкой не затронута', count($queue_db->outbox), 6);

check('лента: без кода события не убираем', Service_Nj597_Engine::purge_feed_event('')['ok'], FALSE);

nj597q_fixture();
$res = Service_Nj597_Engine::purge_feed_event('такогобытьне может');
check('лента: пустое событие — ничего не убрано', $res['deleted'], 0);
check('лента: пустое событие не роняет ответ', $res['ok'], TRUE);
check('лента: записи на месте', count($queue_db->feed), 4);

nj597q_fixture();
$res = Service_Nj597_Engine::purge_feed_event('nj597.legacy.nj605_radar');
$left = array_filter($queue_db->feed, function ($row) { return $row['id'] === 11; });
check('лента: запись о готовой сборке осталась', count($left), 1);
check('лента: у неё по-прежнему «ждёт письма»', array_values($left)[0]['email_status'], 'pending');

// --- 7. Предохранитель на источник: одно событие не должно заваливать всех ----
$cap = Service_Nj597_Limits::limit('source_hour', 'email', 120);
check('предохранитель: лимит источника есть в настройках', $cap > 0, TRUE);
check('предохранитель: лимит источника для почты — из конфига', $cap, 120);
check('предохранитель: ведро источника отдельное у каждого канала',
    Service_Nj597_Limits::bucket_source('email', 'nj667.build.done', 'h') !== Service_Nj597_Limits::bucket_source('telegram', 'nj667.build.done', 'h'), TRUE);
check('предохранитель: ведро источника отдельное у каждого события',
    Service_Nj597_Limits::bucket_source('email', 'nj667.build.done', 'h') !== Service_Nj597_Limits::bucket_source('email', 'nj593.review.done', 'h'), TRUE);
check('предохранитель: ведро источника живёт в пределах часа',
    substr(Service_Nj597_Limits::bucket_source('email', 'nj667.build.done', 'h'), 0, 3), 'ge:');

nj597q_fixture();
$burst = Service_Nj597_Limits::bucket_source('email', 'nj667.build.done', 'h');
$queue_db->rate[$burst] = $cap;   // ровно лимит уже израсходован
$check = Service_Nj597_Limits::check(1, 'email', 'nj667.build.done');
check('предохранитель: при исчерпании лимита источник встаёт', $check['ok'], FALSE);
check('предохранитель: причина называет событие', strpos($check['reason'], 'nj667.build.done') !== FALSE, TRUE);
check('предохранитель: пауза до конца часа', $check['retry_after'] > 0 AND $check['retry_after'] <= 3600, TRUE);
check('предохранитель: отметка администратору записана', strpos(implode("\n", $queue_db->sql), 'alert.source') !== FALSE, TRUE);

$alert = Service_Nj597_Limits::source_alert(24);
check('предохранитель: панель видит событие', (string) Arr::get($alert, 'alias', ''), 'nj667.build.done');
check('предохранитель: панель видит канал', (string) Arr::get($alert, 'channel', ''), 'email');
check('предохранитель: панель видит счётчик', (int) Arr::get($alert, 'count', 0), $cap + 1);
check('предохранитель: панель видит лимит', (int) Arr::get($alert, 'limit', 0), $cap);

// Отметка переписывается только на первом превышении — панель не шумит.
$before = $queue_db->settings['alert.source'];
Service_Nj597_Limits::check(1, 'email', 'nj667.build.done');
check('предохранитель: отметка не дрожит на каждом сообщении', $queue_db->settings['alert.source'], $before);

$queue_db->settings['alert.source'] = json_encode(['alias' => 'x', 'channel' => 'email', 'count' => 1, 'limit' => 1, 'at' => time() - 90000]);
Service_Nj597_Base::forget_settings();
check('предохранитель: старая отметка панель не пугает', Service_Nj597_Limits::source_alert(24), NULL);

nj597q_fixture();
$queue_db->settings['alert.source'] = 'не json';
Service_Nj597_Base::forget_settings();
check('предохранитель: мусор в отметке не роняет панель', Service_Nj597_Limits::source_alert(24), NULL);

nj597q_fixture();
$cap_bell = Service_Nj597_Limits::limit('source_hour', 'in_app', 2000);
check('предохранитель: колокольчик не глушим раньше времени', $cap_bell >= 1000, TRUE);
check('предохранитель: личные лимиты на месте', Service_Nj597_Limits::limit('per_user_hour', 'email', 6), 6);

// --- 8. Панель показывает предохранитель и «за час» ---------------------------
$admin_tpl = file_get_contents('/app/application/modules/nj597_notifications/views/597/admin.php');
$admin_ctrl = file_get_contents('/app/application/modules/nj597_notifications/classes/Controller/Nj597/Admin.php');
check('панель: администратору передают отметку предохранителя', strpos($admin_ctrl, 'Service_Nj597_Limits::source_alert(') !== FALSE, TRUE);
check('панель: администратору передают лимит источника', strpos($admin_ctrl, "limit('source_hour'") !== FALSE, TRUE);
check('панель: в ленте видно нагрузку за час', strpos($admin_tpl, 'за час:') !== FALSE, TRUE);
check('панель: сработавший предохранитель назван', strpos($admin_tpl, 'Предохранитель') !== FALSE, TRUE);
check('панель: из предупреждения можно выключить событие', strpos($admin_tpl, '/nj597/admin?tab=events') !== FALSE, TRUE);

$engine_src = file_get_contents('/app/application/modules/nj597_notifications/classes/Service/Nj597/Engine.php');
check('лента: счётчик за час считается запросом', strpos($engine_src, 'FILTER (WHERE ') !== FALSE, TRUE);

// --- 9. Вкладка очереди показывает новые кнопки ------------------------------
check('админка: кнопка «отменить ожидающие» есть', strpos($admin_tpl, 'value="purge_queue"') !== FALSE, TRUE);
check('админка: у кнопки область «waiting»', strpos($admin_tpl, 'name="scope" value="waiting"') !== FALSE, TRUE);
check('админка: у кнопки область «failed»', strpos($admin_tpl, 'name="scope" value="failed"') !== FALSE, TRUE);
check('админка: уборка ленты по коду события', strpos($admin_tpl, 'name="act" value="purge_feed"') !== FALSE, TRUE);
check('админка: уборка в два клика (data-n2j-arm)', substr_count($admin_tpl, 'data-n2j-arm="') >= 4, TRUE);
$arm_js = (string) file_get_contents('/app/application/modules/nj597_notifications/views/597/script.php');
// Взвод — общий data-n2j-arm из app.js; видимость днём (не белое на белом)
// держит общее правило [data-n2j-arm].is-armed в сборке, своего кода нет.
check('админка: своего взвода в скрипте нет — общий data-n2j-arm',
	strpos($arm_js, "classList.add('!bg-rose-600'") === FALSE AND strpos($arm_js, 'data-nj597-arm]') === FALSE, TRUE);
check('админка: подсказка «записи в ленте остаются»', strpos($admin_tpl, 'Записи в ленте пользователей остаются, история отправленного не трогается') !== FALSE, TRUE);
check('админка: видно, чем наполнена лента', strpos($admin_tpl, 'Чем наполнена лента за 7 дней') !== FALSE, TRUE);

// --- 10. Присутствие: «своё» событие не будит того, кто на сайте ------------
nj597q_fixture();
$queue_db->presence = [];

check('присутствие: видимая вкладка отмечает человека', Service_Nj597_Notify::touch_presence(7), TRUE);
check('присутствие: метка запомнена', isset($queue_db->presence[7]), TRUE);
check('присутствие: запись не чаще раза в 20 секунд',
    strpos(implode("\n", $queue_db->sql), "interval '20 seconds'") !== FALSE, TRUE);
check('присутствие: мусорный id не пишем', Service_Nj597_Notify::touch_presence(0), FALSE);
check('присутствие: человека, который только что был, видно', Service_Nj597_Notify::seen_recently(7), TRUE);
check('присутствие: окно — три минуты', strpos($queue_db->sql[count($queue_db->sql) - 1], "interval '180 seconds'") !== FALSE, TRUE);
check('присутствие: чужого не видно', Service_Nj597_Notify::seen_recently(8), FALSE);
check('присутствие: своё окно можно назвать',
    Service_Nj597_Notify::seen_recently(7, 60) AND strpos($queue_db->sql[count($queue_db->sql) - 1], "interval '60 seconds'") !== FALSE, TRUE);

// «Своё» событие + человек на сайте → тишина
nj597q_fixture();
$queue_db->presence[7] = TRUE;
$sent = Service_Nj597_Notify::emit('nj667.build.done', [
    'user_id' => 7,
    'title'   => 'Сборка готова: тестовая деталь',
    'self'    => TRUE,
]);
check('присутствие: своё событие на сайте — колокольчик молчит', $sent['inbox'], 0);
check('присутствие: тишина — не ошибка модуля', $sent['ok'], TRUE);
check('присутствие: причина названа', implode(' ', (array) $sent['skipped']), 'вы на сайте');

// ...и то же событие, когда человек ушёл с сайта
nj597q_fixture();
$queue_db->presence = [];
$sent = Service_Nj597_Notify::emit('nj667.build.done', [
    'user_id' => 7,
    'title'   => 'Сборка готова: тестовая деталь',
    'self'    => TRUE,
]);
check('присутствие: ушёл с сайта — уведомление приходит', $sent['inbox'], 1);

// Чужое событие (вам заказали, вам ответили) будим и когда человек на сайте
nj597q_fixture();
$queue_db->presence[7] = TRUE;
$sent = Service_Nj597_Notify::emit('nj607.order', [
    'user_id' => 7,
    'title'   => 'Новый заказ',
]);
check('присутствие: чужое событие на сайте всё равно будит', $sent['inbox'], 1);

// Выключатель правила
nj597q_fixture();
$queue_db->presence[7] = TRUE;
$queue_db->settings['presence.skip_self'] = '0';
Service_Nj597_Base::forget_settings();
$sent = Service_Nj597_Notify::emit('nj667.build.done', [
    'user_id' => 7,
    'title'   => 'Сборка готова: тестовая деталь',
    'self'    => TRUE,
]);
check('присутствие: выключатель возвращает уведомления', $sent['inbox'], 1);

// Молчание «себе не пишем» — штатная тишина, а не «не указан получатель»
nj597q_fixture();
$queue_db->presence = [];
$queue_db->settings = [];
Service_Nj597_Base::forget_settings();
$sent = Service_Nj597_Notify::emit('nj593.review.done', [
    'user_id'      => 7,
    'initiator_id' => 7,
    'title'        => 'Обзор готов',
]);
check('получатели: себе не пишем — ответ «обработано»', $sent['ok'], TRUE);
check('получатели: причина — инициатор', implode(' ', (array) $sent['skipped']), 'вы — инициатор');
check('получатели: без получателя — по-прежнему ошибка',
    Service_Nj597_Notify::emit('nj593.review.done', ['title' => 'Обзор готов'])['ok'], FALSE);

// Уборка отметок присутствия
Service_Nj597_Notify::touch_presence(7);
Service_Nj597_Notify::presence_gc();
check('присутствие: старые отметки убираются',
    strpos($queue_db->sql[count($queue_db->sql) - 1], 'DELETE FROM "nj_597_presence"') !== FALSE, TRUE);
check('присутствие: срок хранения назван',
    strpos($queue_db->sql[count($queue_db->sql) - 1], "'30 days'") !== FALSE, TRUE);
check('присутствие: уборка не роняет модуль', Service_Nj597_Notify::presence_gc(), NULL);

Database::$db = NULL;
