<?php
/**
 * Дополнение nj597_engine_stand: «уведомления из модулей».
 *
 * Владелец (15.10.2026): «подумай, что добавить в уведомления из модулей:
 * /trends и /graph3d добавь, настрой шаблоны».
 *
 * Здесь проверяем именно контракт: манифесты модулей объявляют события в
 * notify.events, тексты в notify.templates, синхронизация реестра заводит
 * ровно отсутствующее (правки администратора не затираются), а код модулей
 * действительно шлёт эти события в нужный момент.
 *
 * Расширение (третий аргумент) — реальные значения: 'channels' и 'channel'.
 */
echo "\n-- уведомления из модулей: /trends и /graph3d --\n";

/** Json-совместимый доступ к массиву (в стенде Arr без path). */
function nj597c_get($array, $key, $default = NULL)
{
    if ( ! is_array($array)) { return $default; }

    return array_key_exists($key, $array) ? $array[$key] : $default;
}

/** Все подстановки {{...}} в тексте. */
function nj597c_vars($text)
{
    $out = [];
    if (preg_match_all('~\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}~', (string) $text, $m)) {
        foreach ($m[1] as $one) { $out[strtolower($one)] = TRUE; }
    }

    return array_keys($out);
}

$nj597_manifests = [
    'nj593_trends'  => require '/app/application/modules/nj593_trends/config/nj_module.php',
    'nj667_graph3d' => require '/app/application/modules/nj667_graph3d/config/nj_module.php',
];

$nj597_channels = array_keys((array) Service_Nj597_Base::channels(FALSE));
$nj597_categories = ['system', 'security', 'jobs', 'content', 'billing', 'social', 'marketing'];
$nj597_roles = ['login', 'admin', 'moderator', 'guest'];
$nj597_globals = ['user_name', 'user_email', 'site_url', 'site_name', 'inbox_url', 'settings_url', 'date', 'time', 'year'];

foreach ($nj597_manifests as $module => $manifest)
{
    $notify = (array) nj597c_get($manifest, 'notify', []);
    $events = (array) nj597c_get($notify, 'events', []);
    $templates = (array) nj597c_get($notify, 'templates', []);

    check('модуль '.$module.': события объявлены', count($events) > 0, TRUE);

    foreach ($events as $alias => $def)
    {
        $def = (array) $def;
        check('событие '.$alias.': код в формате модуля', preg_match('~^'.substr($module, 0, 5).'\.[a-z0-9._]+$~', $alias), 1);
        check('событие '.$alias.': заголовок и описание есть', trim((string) nj597c_get($def, 'title', '')) !== '' && trim((string) nj597c_get($def, 'description', '')) !== '', TRUE);
        check('событие '.$alias.': категория из списка', in_array((string) nj597c_get($def, 'category', ''), $nj597_categories, TRUE), TRUE);
        check('событие '.$alias.': иконка указана', strpos((string) nj597c_get($def, 'icon', ''), 'fa') === 0, TRUE);
        check('событие '.$alias.': каналы понятны центру', count(array_diff(Service_Nj597_Base::_csv(nj597c_get($def, 'channels', '')), $nj597_channels)), 0);
        check('событие '.$alias.': роли понятны центру', count(array_diff(Service_Nj597_Base::_csv(nj597c_get($def, 'roles', '')), $nj597_roles)), 0);
        check('событие '.$alias.': в колокольчик попадает', in_array('in_app', Service_Nj597_Base::_csv(nj597c_get($def, 'channels', '')), TRUE), TRUE);
        check('событие '.$alias.': подстановки перечислены', Service_Nj597_Base::_csv(nj597c_get($def, 'placeholders', '')) !== [], TRUE);
        check('событие '.$alias.': digest — булево', is_bool(nj597c_get($def, 'digest', NULL)), TRUE);
    }

    check('модуль '.$module.': шаблоны объявлены', count($templates) > 0, TRUE);

    foreach ($templates as $alias => $channels)
    {
        check('шаблон '.$alias.': событие объявлено рядом', isset($events[$alias]), TRUE);

        $known = array_merge(Service_Nj597_Base::_csv(nj597c_get((array) $events[$alias], 'placeholders', '')), $nj597_globals);

        foreach ((array) $channels as $channel => $tpl)
        {
            $tpl = (array) $tpl;

            check('шаблон '.$alias.'/'.$channel.': канал известен', in_array((string) $channel, $nj597_channels, TRUE), TRUE);
            check('шаблон '.$alias.'/'.$channel.': текст не пуст', trim((string) nj597c_get($tpl, 'body', '')) !== '', TRUE);

            if ($channel === 'email')
            {
                check('шаблон '.$alias.'/email: тема письма не пуста', trim((string) nj597c_get($tpl, 'subject', '')) !== '', TRUE);
            }

            foreach (array_merge(nj597c_vars(nj597c_get($tpl, 'body', '')), nj597c_vars(nj597c_get($tpl, 'subject', ''))) as $var)
            {
                // Точки в подстановках Render не понимает: {{user.name}} остался
                // бы в письме как есть — это была настоящая ошибка подсказки.
                check('шаблон '.$alias.'/'.$channel.': подстановка {{'.$var.'}} известна', in_array($var, $known, TRUE), TRUE);
                check('шаблон '.$alias.'/'.$channel.': подстановка без точек', strpos($var, '.') === FALSE, TRUE);
            }
        }
    }

    // В письме должна быть ссылка на объект: иначе уведомление бесполезно.
    foreach (['email'] as $channel)
    {
        foreach ($templates as $alias => $channels)
        {
            if ( ! isset($channels[$channel])) { continue; }

            check('шаблон '.$alias.'/email: ведёт на объект ({{href}})', strpos((string) nj597c_get($channels[$channel], 'body', ''), '{{href}}') !== FALSE, TRUE);
        }
    }
}

// --- «по умолчанию у пользователей отключены уведомления» -------------------
// Владелец (16.10.2026) назвал ровно эти четыре события и их тексты. Проверяем
// и текст, и главное: событие из манифеста НЕ доходит до человека, который сам
// ничего не включал. Умолчания берём настоящие — из Notify::prefs_default().
$nj597_owner_events = [
    'nj593.review.done'  => ['Обзор трендов готов', 'Сборка обзора завершилась: позиции собраны, обзор опубликован на /trends.'],
    'nj593.review.error' => ['Обзор трендов не собрался', 'Сборка обзора прервалась ошибкой. Собранные позиции остаются, сборку можно повторить.'],
    'nj667.build.done'   => ['Сборка изделия готова', 'Модель графа собрана: STL (или GLB) отрендерен — можно смотреть и печатать.'],
    'nj667.build.error'  => ['Сборка изделия не удалась', 'Воркер не смог отрендерить модель: причина в тексте уведомления, сборку можно повторить.'],
];

$nj597_default_prefs = Service_Nj597_Notify::prefs_default(7);
$nj597_owner_defs = [];

foreach ($nj597_owner_events as $alias => $want)
{
    $def = NULL;

    foreach ($nj597_manifests as $manifest)
    {
        $events = (array) nj597c_get((array) nj597c_get($manifest, 'notify', []), 'events', []);

        if (isset($events[$alias])) { $def = (array) $events[$alias]; }
    }

    check('владелец: событие '.$alias.' объявлено', $def !== NULL, TRUE);

    if ($def === NULL) { continue; }

    check('владелец: заголовок «'.$want[0].'»', (string) nj597c_get($def, 'title', ''), $want[0]);
    check('владелец: описание события '.$alias, (string) nj597c_get($def, 'description', ''), $want[1]);

    // Событие обязательное — не выключить; для этих четырёх такого быть не должно.
    check('владелец: событие '.$alias.' можно не получать', empty($def['mandatory']), TRUE);

    $event = [
        'alias'     => $alias,
        'enabled'   => TRUE,
        'channels'  => Service_Nj597_Base::_csv(nj597c_get($def, 'channels', '')),
        'roles'     => Service_Nj597_Base::_csv(nj597c_get($def, 'roles', 'login')),
        'mandatory' => ! empty($def['mandatory']),
    ];

    // «Из коробки» наружу не уходит ничего; внутри сайта событие видно в
    // колокольчике — так решил владелец (колокольчик включён всегда).
    $quiet = Service_Nj597_Registry::plan_for(7, $event, $nj597_default_prefs, ['login']);

    check('владелец: '.$alias.' наружу не приходит «из коробки»',
        array_values(array_diff($quiet, ['in_app'])), []);
    check('владелец: '.$alias.' в колокольчике виден',
        $quiet, in_array('in_app', $event['channels'], TRUE) ? ['in_app'] : []);

    $nj597_owner_defs[$alias] = $event;
}

// --- код модулей действительно шлёт эти события ------------------------------
$nj593_code  = file_get_contents('/app/application/modules/nj593_trends/classes/Service/Nj593/Trends.php');
$nj667_code  = file_get_contents('/app/application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php');

check('nj593: готовый обзор шлёт событие', strpos($nj593_code, "'nj593.review.done'") !== FALSE, TRUE);
check('nj593: сорванная сборка шлёт событие', strpos($nj593_code, "'nj593.review.error'") !== FALSE, TRUE);
check('nj593: повторный complete не шлёт второй раз', strpos($nj593_code, "\$already_done") !== FALSE, TRUE);
check('nj593: ссылка ведёт на страницу обзора', strpos($nj593_code, "'/trends/'.\$code") !== FALSE, TRUE);
check('nj667: готовая сборка шлёт событие', strpos($nj667_code, "'nj667.build.done'") !== FALSE, TRUE);
check('nj667: неудача сборки шлёт событие', strpos($nj667_code, "'nj667.build.error'") !== FALSE, TRUE);
check('nj667: шлём только на переход статуса', strpos($nj667_code, "\$before") !== FALSE, TRUE);
check('nj667: гостевая сборка не шлёт ничего', strpos($nj667_code, '$owner <= 0 OR ! class_exists') !== FALSE, TRUE);

// --- каналы задач модуля в центре названы по-человечески ---------------------
$map = Service_Nj597_Notify::channel_map();
check('канал сборки изделия назван', nj597c_get($map['nj667.build'] ?? [], 0), 'Сборка изделия');
check('канал сборки ведёт в /3d', nj597c_get($map['nj667.build'] ?? [], 1), '/3d/');
check('канал кадров проекта назван', nj597c_get($map['nj667.generate'] ?? [], 0), 'Кадр проекта');
check('канал трендов остался названным', nj597c_get($map['nj593.trends'] ?? [], 0), 'Тренды и отзывы');

// --- синхронизация реестра: события и шаблоны из манифестов ------------------
if ( ! class_exists('Manifest'))
{
    class Manifest
    {
        public static $sections = [];
        public static function section($name) { return self::$sections[$name] ?? []; }
    }
}

class Nj597_Contract_Db extends Nj597_Settings_Db
{
    public $events = [];
    public $templates = [];
    public $inserted = [];
    // Любая запись в шаблоны: и вставка, и правка. Синхронизация не имеет
    // права писать туда, если пара «событие × канал» уже есть.
    public $tpl_writes = 0;
    public function query($type, $sql, $unused = FALSE)
    {
        if (in_array($type, [Database::UPDATE, Database::DELETE], TRUE) && strpos($sql, 'nj_597_templates') !== FALSE) {
            $this->tpl_writes++;
            $this->queries[] = $sql;
            return 1;
        }
        // Вставку шаблона запоминаем: на втором проходе синхронизации он
        // должен быть виден как уже существующий (иначе получим дубли).
        if ($type === Database::INSERT && strpos($sql, 'INSERT INTO "nj_597_templates"') !== FALSE) {
            $this->tpl_writes++;
            $this->queries[] = $sql;
            preg_match_all("~'((?:[^']|'')*)'~", $sql, $m);
            $values = array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);

            if (count($values) >= 4) {
                $this->templates[] = [
                    'event_alias' => $values[0], 'channel' => $values[1],
                    'subject' => $values[2], 'body' => $values[3],
                    'format' => $values[4] ?? 'text', 'is_active' => 't', 'updated_at' => '2026-10-16',
                ];
                $this->inserted[] = $values[0].'/'.$values[1];
            }

            return 1;
        }
        // Настройки живут в таблице: запись надо запомнить, иначе следующий
        // проход прочитает старое значение и повторит уже сделанную правку.
        if ($type === Database::INSERT && strpos($sql, 'nj_597_settings') !== FALSE) {
            $this->queries[] = $sql;
            preg_match_all("~'((?:[^']|'')*)'~", $sql, $m);
            $values = array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);

            if (count($values) >= 2) {
                $found = FALSE;
                foreach ($this->settings as $i => $row) {
                    if ($row['key'] === $values[0]) { $this->settings[$i]['value'] = $values[1]; $found = TRUE; }
                }
                if ( ! $found) { $this->settings[] = ['key' => $values[0], 'value' => $values[1]]; }
            }

            return 1;
        }

        // Разовая правка «склейка по умолчанию»: обновляем только те строки,
        // которые подходят под фильтр самого запроса (source = 'runtime').
        // Движок шлёт правку через _val(), а тот ходит в базу как SELECT —
        // поэтому смотрим на текст запроса, а не на тип вызова.
        if (strpos($sql, 'UPDATE '.chr(34).'nj_597_events'.chr(34)) !== FALSE) {
            $this->queries[] = $sql;
            $only_runtime = strpos($sql, chr(34).'source'.chr(34).' = '.chr(39).'runtime'.chr(39)) !== FALSE;
            $live_only = strpos($sql, chr(34).'updated_at'.chr(34).' <= '.chr(34).'created_at'.chr(34)) !== FALSE;
            $fixed = 0;
            foreach ($this->events as $i => $row) {
                $row_source = (string) ($row['source'] ?? 'db');
                $touched = (string) ($row['updated_at'] ?? '') > (string) ($row['created_at'] ?? '');
                if (($row['digest'] ?? FALSE) === FALSE
                    && ( ! $only_runtime || $row_source === 'runtime')
                    && ( ! $live_only || ! $touched)) {
                    $this->events[$i]['digest'] = TRUE;
                    $fixed++;
                }
            }
            // Как настоящий драйвер: результат — объект со строками.
            return new Nj597_Settings_Result([['c' => $fixed]]);
        }
        if (strpos($sql, 'nj_597_events') !== FALSE && $type === Database::SELECT) {
            $this->queries[] = $sql;
            preg_match('~"alias" = \'([^\']*)\'~', $sql, $m);
            $rows = $this->events;
            if ( ! empty($m[1])) { $rows = array_values(array_filter($rows, function ($r) use ($m) { return $r['alias'] === $m[1]; })); }
            return new Nj597_Settings_Result($rows);
        }
        if (strpos($sql, 'nj_597_templates') !== FALSE && $type === Database::SELECT) {
            $this->queries[] = $sql;
            preg_match('~"event_alias" = \'([^\']*)\'~', $sql, $m);
            $rows = $this->templates;
            if ( ! empty($m[1])) { $rows = array_values(array_filter($rows, function ($r) use ($m) { return $r['event_alias'] === $m[1]; })); }
            return new Nj597_Settings_Result($rows);
        }
        return parent::query($type, $sql, $unused);
    }
}

$contract_db = Database::$db = new Nj597_Contract_Db;
$contract_db->settings = [['key' => 'registry.synced_at', 'value' => '0']];

Manifest::$sections = [
    'notify' => [
        'nj593_trends'  => nj597c_get($nj597_manifests['nj593_trends'], 'notify', []),
        'nj667_graph3d' => nj597c_get($nj597_manifests['nj667_graph3d'], 'notify', []),
    ],
];

Service_Nj597_Base::forget_settings();
Service_Nj597_Registry::forget_events();

$sync = Service_Nj597_Registry::sync_manifests(TRUE);
check('синхронизация: события манифестов приняты', (int) nj597c_get($sync, 'added', 0) >= 4, TRUE);
check('синхронизация: шаблоны из манифестов заведены', (int) nj597c_get($sync, 'templates', 0) >= 6, TRUE);

$inserts = implode("\n", $contract_db->queries);
check('синхронизация: событие обзора записано в реестр', strpos($inserts, 'nj593.review.done') !== FALSE, TRUE);
check('синхронизация: шаблон письма обзора записан', strpos($inserts, 'Обзор готов: {{title}}') !== FALSE, TRUE);
check('синхронизация: шаблон сборки изделия записан', strpos($inserts, 'Сборка готова: {{title}}') !== FALSE, TRUE);

// Правило оформления (владелец, 16.10.2026): «письмо — одна мысль». Тема
// короткая, без «Уведомление:» и без имени площадки, тело не пустое.
// Проверяем сами манифесты, чтобы новый модуль не принёс «Уведомление: …».
$rule_rows = 0;
$rule_mail = 0;

foreach (['nj593_trends', 'nj667_graph3d'] as $rule_module)
{
    $block = (array) nj597c_get($nj597_manifests[$rule_module], 'notify', []);

    foreach ((array) nj597c_get($block, 'templates', []) as $rule_alias => $rule_channels)
    {
        foreach ((array) $rule_channels as $rule_channel => $rule_tpl)
        {
            $rule_tpl     = (array) $rule_tpl;
            $rule_subject = trim((string) nj597c_get($rule_tpl, 'subject', ''));
            $rule_body    = (string) nj597c_get($rule_tpl, 'body', '');
            $rule_rows++;

            // У письма тема обязательна: по ней человек решает, открывать ли.
            if ((string) $rule_channel === 'email')
            {
                $rule_mail++;
                check('правило: у письма '.$rule_alias.' есть тема', $rule_subject !== '', TRUE);
            }

            if ($rule_subject !== '')
            {
                check('правило: тема '.$rule_alias.'/'.$rule_channel.' короче 60 знаков', mb_strlen($rule_subject) <= 60, TRUE);
                check('правило: тема '.$rule_alias.'/'.$rule_channel.' без служебных слов',
                    preg_match('~^\s*(уведомление|notification|n2j)~iu', $rule_subject), 0);
            }

            check('правило: тело '.$rule_alias.'/'.$rule_channel.' не пустое', trim($rule_body) !== '', TRUE);
            check('правило: в теле '.$rule_alias.'/'.$rule_channel.' есть ссылка',
                preg_match('~\{\{(href|site_url|settings_url|inbox_url)\}\}~', $rule_body), 1);
        }
    }
}

check('правило: шаблоны манифестов действительно проверены', $rule_rows >= 4, TRUE);
check('правило: письма манифестов попали в проверку', $rule_mail >= 2, TRUE);

// Второй проход не должен ничего добавлять: идемпотентность.
$contract_db->queries = [];
Service_Nj597_Registry::set_setting('registry.synced_at', '0');
$again = Service_Nj597_Registry::sync_manifests(TRUE);
check('синхронизация: повторно шаблоны не дублируются', (int) nj597c_get($again, 'templates', 0), 0);
check('синхронизация: первый проход действительно писал шаблоны', count($contract_db->inserted) >= 6, TRUE);
check('синхронизация: событие и шаблон обзора — парой', in_array('nj593.review.done/email', $contract_db->inserted, TRUE), TRUE);
check('синхронизация: событие и шаблон сборки — парой', in_array('nj667.build.done/email', $contract_db->inserted, TRUE), TRUE);

// А вот человек, отметивший событие сам, получает его по всем объявленным
// каналам: выключено «по умолчанию», а не «навсегда».
$contract_db->subs = [];

foreach ($nj597_owner_defs as $alias => $event)
{
    foreach ($event['channels'] as $channel)
    {
        $contract_db->subs[] = ['user_id' => 7, 'event_alias' => $alias, 'channel' => $channel, 'enabled' => 't'];
    }
}

$nj597_all_on = ['in_app_enabled' => TRUE, 'email_enabled' => TRUE, 'telegram_enabled' => TRUE, 'max_enabled' => TRUE];


foreach ($nj597_owner_defs as $alias => $event)
{
    $plan = Service_Nj597_Registry::plan_for(7, $event, $nj597_all_on, ['login', 'admin']);

    // Сравниваем с каналами, которые на этой площадке вообще настроены:
    // без SMTP/ботов доставлять нечем, и это не про подписки человека.
    $ready = array_values(array_filter($event['channels'], ['Service_Nj597_Registry', 'channel_enabled']));

    check('владелец: отмеченное '.$alias.' доходит', $plan, $ready);
}

$contract_db->subs = [];

// Правки администратора не затираются: текст, поправленный человеком,
// остаётся как есть, и синхронизация к этой паре вообще не притрагивается.
foreach ($contract_db->templates as $i => $row)
{
    if ($row['event_alias'] === 'nj667.build.done' AND $row['channel'] === 'telegram')
    {
        $contract_db->templates[$i]['body'] = 'Правка администратора';
    }
}

$contract_db->queries  = [];
$contract_db->tpl_writes = 0;
Service_Nj597_Registry::set_setting('registry.synced_at', '0');
Service_Nj597_Registry::sync_manifests(TRUE);

check('синхронизация: правка администратора не перезаписана', strpos(implode("\n", $contract_db->queries), 'Правка администратора') === FALSE, TRUE);
check('синхронизация: к уже заведённой паре не притрагивается', $contract_db->tpl_writes, 0);
$kept = '';
foreach ($contract_db->templates as $row)
{
    if ($row['event_alias'] === 'nj667.build.done' AND $row['channel'] === 'telegram')
    {
        $kept = (string) $row['body'];
    }
}
check('синхронизация: текст правки остался в шаблоне', $kept, 'Правка администратора');

// Разовая правка «склейка по умолчанию»: события, которые модули завели сами
// (source = runtime), раньше не умели копиться в сводку — а именно они и
// наполняли ленту. Метку ставим один раз, чужие решения не перебиваем.
$contract_db->events = [
    ['alias' => 'nj597.legacy.nj605_radar', 'source' => 'runtime', 'digest' => FALSE, 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01'],
    ['alias' => 'nj597.legacy.nj666_report', 'source' => 'runtime', 'digest' => FALSE, 'created_at' => '2026-10-02', 'updated_at' => '2026-10-02'],
    ['alias' => 'nj593.review.done', 'source' => 'manifest', 'digest' => FALSE, 'created_at' => '2026-10-03', 'updated_at' => '2026-10-03'],
    ['alias' => 'nj597.legacy.ручная-правка', 'source' => 'runtime', 'digest' => FALSE, 'created_at' => '2026-10-04', 'updated_at' => '2026-10-10'],
    ['alias' => 'nj597.ручное-событие', 'source' => 'db', 'digest' => FALSE, 'created_at' => '2026-10-05', 'updated_at' => '2026-10-05'],
];

$contract_db->settings = [['key' => 'registry.synced_at', 'value' => '0']];
Service_Nj597_Base::forget_settings();
$contract_db->queries = [];

$fixed = Service_Nj597_Registry::apply_digest_default_once();
check('сводка: заведённым модулями событиям включена склейка', $fixed, 2);
check('сводка: строка манифеста не тронута', $contract_db->events[2]['digest'], FALSE);
check('сводка: решение администратора не перебито', $contract_db->events[3]['digest'], FALSE);
check('сводка: событие, заведённое человеком, не тронуто', $contract_db->events[4]['digest'], FALSE);
check('сводка: у мусорного события склейка включена', $contract_db->events[0]['digest'], TRUE);
$fix_sql = array_values(array_filter($contract_db->queries, function ($q) { return strpos($q, 'UPDATE '.chr(34).'nj_597_events'.chr(34)) !== FALSE; }));
check('сводка: правка идёт одним запросом', count($fix_sql), 1);
check('сводка: правим только свои строки', strpos($fix_sql[0], chr(39).'runtime'.chr(39)) !== FALSE, TRUE);
check('сводка: чужие решения фильтруются в самом запросе', strpos($fix_sql[0], chr(34).'updated_at'.chr(34).' <= '.chr(34).'created_at'.chr(34)) !== FALSE, TRUE);

$contract_db->queries = [];
$second = Service_Nj597_Registry::apply_digest_default_once();
check('сводка: повторно ничего не правим', $second, 0);
check('сводка: второй раз строки не правим',
    count(array_filter($contract_db->queries, function ($q) { return strpos($q, 'UPDATE '.chr(34).'nj_597_events'.chr(34)) !== FALSE; })), 0);

$contract_db->events[0]['digest'] = FALSE;
$contract_db->queries = [];
$again_fix = Service_Nj597_Registry::apply_digest_default_once();
check('сводка: после отметки правка не повторяется', $again_fix, 0);
check('сводка: ручное выключение склейки остаётся', $contract_db->events[0]['digest'], FALSE);

// Стенды дальше пользуются админской заглушкой БД — возвращаем её.
Database::$db = $db;
Service_Nj597_Registry::forget_events();
Service_Nj597_Base::forget_settings();
