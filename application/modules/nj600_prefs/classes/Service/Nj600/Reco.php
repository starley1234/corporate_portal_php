<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Рекомендации сервисов под надзором администратора (nj600_prefs).
 *
 * Источники кандидатов (по убыванию доверия):
 *  * rule    — правило администратора (nj_600_reco_rules, kind=boost): «кто
 *              интересуется разделом X (или конкретный владелец) → предложить Y»;
 *  * covisit — совместные посещения: владельцы с теми же разделами интереса
 *              чаще других заходят в Y (считается по компактным профилям
 *              nj_600_profile, без сырья — дёшево);
 *  * group   — сервис из той же группы каталога, что и топ-раздел владельца.
 *
 * Фильтры: уже посещённые разделы, правила kind=block (всем или владельцу),
 * отклонённые самим владельцем (nj_600_reco_log.dismissed_at за 60 дней),
 * сервисы, которых нет в каталоге nj110 для этой аудитории.
 *
 * Надзор: каждая рекомендация несёт reason («почему») и пишется в журнал
 * показов; администратор видит CTR по источникам и сервисам, закрепляет или
 * запрещает рекомендации — глобально или одному человеку.
 *
 * Чистая часть — rank(): принимает готовые массивы и ничего не читает из БД,
 * её проверяет стенд .tools/nj600_reco_stand.php.
 */
class Service_Nj600_Reco
{
    /** Отклонённое владельцем не показываем столько дней. */
    const DISMISS_DAYS = 60;

    /** Повторный показ одного href одному владельцу не логируем чаще, чем раз в часы. */
    const SHOWN_THROTTLE_HOURS = 24;

    /** Сколько разделов владельца участвуют в подборе. */
    const TOP_SECTIONS = 5;

    protected static function _db()
    {
        return Database::instance();
    }

    // ------------------------------------------------------------ каталог

    /**
     * Карта «раздел (первый сегмент href) → сервис каталога» для аудитории
     * владельца: гостю — только публичные, пользователю — публичные и
     * аккаунтные. Draft/deprecated и скрытые из каталога не рекомендуем.
     */
    public static function catalog($owner_key = '')
    {
        static $cache = [];

        $logged = (strpos((string) $owner_key, 'u:') === 0);
        $ck = $logged ? 'u' : 'g';

        if (isset($cache[$ck]))
        {
            return $cache[$ck];
        }

        $out = [];

        if ( ! class_exists('Service_Nj110_Catalog'))
        {
            return $cache[$ck] = $out;
        }

        try
        {
            $ctx = [
                'guest'  => ! $logged,
                'logged' => $logged,
                'roles'  => $logged ? ['login'] : [],
                'admin'  => FALSE,
                'agent'  => FALSE,
            ];

            foreach (Service_Nj110_Catalog::visible_modules($ctx) as $m)
            {
                $section = self::section_of_href((string) $m['href']);

                if ($section === '' OR isset($out[$section]))
                {
                    continue;
                }

                $out[$section] = [
                    'section' => $section,
                    'href'    => (string) $m['href'],
                    'title'   => (string) $m['title'],
                    'short'   => (string) ($m['short'] ?? ''),
                    'icon'    => (string) ($m['icon'] ?? 'fas fa-puzzle-piece'),
                    'group'   => (string) ($m['group'] ?? ''),
                    'slug'    => (string) ($m['slug'] ?? $m['module_name'] ?? ''),
                ];
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj600: reco catalog: :e', [':e' => $e->getMessage()]);
        }

        return $cache[$ck] = $out;
    }

    /** Раздел = первый сегмент пути href («/docs/wizard» → «docs»). */
    public static function section_of_href($href)
    {
        $path = trim((string) parse_url((string) $href, PHP_URL_PATH), '/');

        if ($path === '')
        {
            return '';
        }

        $seg = explode('/', $path);

        return mb_substr((string) $seg[0], 0, 60);
    }

    // ------------------------------------------------------- чистый ранкер

    /**
     * Ранжирование кандидатов БЕЗ базы.
     *
     * @param array $sections  разделы владельца => счётчик (как в профиле)
     * @param array $catalog   раздел => карточка сервиса (см. catalog())
     * @param array $rules     строки nj_600_reco_rules (enabled уже отфильтрованы)
     * @param array $covisit   [[section, owners], …] — совместные посещения
     * @param array $dismissed href'ы, отклонённые владельцем
     * @param int   $limit
     * @return array  список [href, title, short, icon, section, score, source, rule_id, reason]
     */
    public static function rank(array $sections, array $catalog, array $rules, array $covisit, array $dismissed, $limit = 4)
    {
        arsort($sections);
        $top = array_slice(array_keys($sections), 0, self::TOP_SECTIONS);
        $visited = array_fill_keys(array_keys($sections), TRUE);
        $dismissed = array_fill_keys(array_map('strval', $dismissed), TRUE);

        // «Запрещено» — глобально или для владельца (правила уже отобраны под владельца)
        $blocked = [];

        foreach ($rules as $r)
        {
            if ((string) ($r['kind'] ?? '') === 'block')
            {
                $blocked[self::_norm_href((string) $r['href'])] = TRUE;
            }
        }

        $cand = [];

        $put = function ($href, $score, $source, $reason, $rule_id = NULL, $title = '', $note = '')
            use (&$cand, $catalog, $visited, $blocked, $dismissed)
        {
            $href = self::_norm_href($href);
            $section = self::section_of_href($href);

            if ($href === '' OR $section === '' OR isset($blocked[$href]) OR isset($dismissed[$href]))
            {
                return;
            }

            $card = $catalog[$section] ?? NULL;

            // ручное правило может вести на страницу вне каталога — у него свой заголовок
            if ($card === NULL AND ($source !== 'rule' OR $title === ''))
            {
                return;
            }

            if (isset($visited[$section]) AND $source !== 'rule')
            {
                return; // уже пользовался — советовать нечего (правило админа сильнее)
            }

            $row = [
                'href'    => $href,
                'title'   => $title !== '' ? $title : (string) $card['title'],
                'short'   => $note !== '' ? $note : (string) ($card['short'] ?? ''),
                'icon'    => (string) ($card['icon'] ?? 'fas fa-puzzle-piece'),
                'section' => $section,
                'score'   => (int) $score,
                'source'  => $source,
                'rule_id' => $rule_id ? (int) $rule_id : NULL,
                'reason'  => $reason,
            ];

            if ( ! isset($cand[$href]) OR $cand[$href]['score'] < $row['score'])
            {
                $cand[$href] = $row;
            }
        };

        // 1. Правила администратора: самые доверенные
        foreach ($rules as $r)
        {
            if ((string) ($r['kind'] ?? '') !== 'boost')
            {
                continue;
            }

            $ws = (string) ($r['when_section'] ?? '');

            if ($ws !== '' AND ! isset($visited[$ws]))
            {
                continue; // правило «для интересующихся X», а X у владельца нет
            }

            $why = (string) ($r['when_owner'] ?? '') !== ''
                ? 'подобрано администратором лично для вас'
                : ($ws !== '' ? 'вы интересовались разделом «'.$ws.'» — администратор советует это рядом' : 'совет администратора');

            $put((string) $r['href'], 1000 + (int) ($r['weight'] ?? 50), 'rule', $why,
                (int) ($r['id'] ?? 0), (string) ($r['title'] ?? ''), (string) ($r['note'] ?? ''));
        }

        // 2. Совместные посещения: нормируем по самому частому СОСЕДУ
        //    (уже посещённые и неизвестные каталогу разделы в максимум не входят)
        $neighbours = [];
        $max = 0;

        foreach ($covisit as $c)
        {
            $section = (string) ($c['section'] ?? $c[0] ?? '');
            $owners  = (int) ($c['owners'] ?? $c[1] ?? 0);

            if ($section === '' OR $owners <= 0 OR ! isset($catalog[$section]) OR isset($visited[$section]))
            {
                continue;
            }

            $neighbours[$section] = $owners;
            $max = max($max, $owners);
        }

        foreach ($neighbours as $section => $owners)
        {
            $score = 100 + (int) round(100 * $owners / max(1, $max));
            $put($catalog[$section]['href'], $score, 'covisit',
                'те, кто пользовался «'.self::_title_of($catalog, $top[0] ?? '').'», часто заходят и сюда ('.$owners.')');
        }

        // 3. Та же группа каталога, что у топ-разделов владельца
        foreach ($top as $i => $section)
        {
            $group = (string) ($catalog[$section]['group'] ?? '');

            if ($group === '')
            {
                continue;
            }

            foreach ($catalog as $sec => $card)
            {
                if ((string) $card['group'] !== $group OR isset($visited[$sec]))
                {
                    continue;
                }

                $put($card['href'], 50 - $i * 5, 'group',
                    'из того же раздела каталога, что и «'.$catalog[$section]['title'].'»');
            }
        }

        uasort($cand, function ($a, $b) {
            return [$b['score'], $a['title']] <=> [$a['score'], $b['title']];
        });

        return array_slice(array_values($cand), 0, max(1, (int) $limit));
    }

    protected static function _title_of(array $catalog, $section)
    {
        return (string) ($catalog[$section]['title'] ?? $section);
    }

    /** Только внутренний путь («/vat», «/docs/wizard»); внешние адреса отбрасываем. */
    protected static function _norm_href($href)
    {
        $href = trim((string) $href);

        if ($href === '' OR $href[0] !== '/' OR strpos($href, '//') === 0 OR preg_match('/[\s<>"\']/', $href))
        {
            return '';
        }

        return mb_substr($href, 0, 200);
    }

    /** Публичная нормализация адреса (для контроллеров). '' = адрес не годится. */
    public static function clean_href($href)
    {
        return self::_norm_href($href);
    }

    // --------------------------------------------------- данные из базы

    /** Разделы владельца из компактного профиля (раздел => счётчик). */
    public static function owner_sections($owner_key)
    {
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT sections FROM nj_600_profile WHERE owner_key = '.$db->quote((string) $owner_key), FALSE)->current();

        if ( ! $row)
        {
            return [];
        }

        $sections = json_decode((string) $row['sections'], TRUE) ?: [];
        $ignore = (array) Service_Nj600_Prefs::cfg('sections_ignore', []);

        foreach ($ignore as $s)
        {
            unset($sections[$s]);
        }

        arsort($sections);

        return $sections;
    }

    /**
     * Совместные посещения по компактным профилям: среди владельцев, у кого
     * есть хотя бы один из разделов $top, считаем другие разделы (по числу
     * владельцев, не по страницам — чтобы один активный не перевешивал всех).
     */
    public static function covisit(array $top, $limit = 20)
    {
        $top = array_values(array_filter(array_map('strval', $top), 'strlen'));

        if ( ! $top)
        {
            return [];
        }

        $db = self::_db();
        $arr = 'ARRAY['.implode(',', array_map([$db, 'quote'], $top)).']::text[]';

        return $db->query(Database::SELECT,
            'SELECT s.key AS section, count(DISTINCT p.owner_key) AS owners
             FROM nj_600_profile p, jsonb_each(p.sections) s
             WHERE p.sections ?| '.$arr.'
               AND NOT (s.key = ANY('.$arr.'))
             GROUP BY s.key
             HAVING count(DISTINCT p.owner_key) >= 1
             ORDER BY owners DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Включённые правила, применимые к владельцу (общие + адресные). */
    public static function rules_for($owner_key)
    {
        $db = self::_db();

        return $db->query(Database::SELECT,
            'SELECT * FROM nj_600_reco_rules
             WHERE enabled = TRUE AND (when_owner = \'\' OR when_owner = '.$db->quote((string) $owner_key).')
             ORDER BY weight DESC, id', FALSE)->as_array();
    }

    /** href'ы, отклонённые владельцем за DISMISS_DAYS дней. */
    public static function dismissed_for($owner_key)
    {
        $db = self::_db();
        $out = [];

        foreach ($db->query(Database::SELECT,
            'SELECT DISTINCT href FROM nj_600_reco_log
             WHERE owner_key = '.$db->quote((string) $owner_key).'
               AND dismissed_at IS NOT NULL
               AND dismissed_at > now() - interval \''.(int) self::DISMISS_DAYS.' days\'', FALSE)->as_array() as $r)
        {
            $out[] = (string) $r['href'];
        }

        return $out;
    }

    /**
     * Рекомендации владельцу. $log=TRUE — записать показы (виджет); на
     * страницах администратора показы не логируем, чтобы не портить CTR.
     *
     * Каждая строка получает 'log_id' (для ссылки-перехода /prefs/api/go),
     * если показ записан.
     */
    public static function for_owner($owner_key = NULL, $limit = 4, $log = FALSE)
    {
        $owner_key = (string) ($owner_key ?: Visitor::uid());

        if ($owner_key === '' OR $owner_key === 'anon')
        {
            return [];
        }

        Service_Nj600_Prefs::ensure_tables();

        $sections = self::owner_sections($owner_key);
        $catalog  = self::catalog($owner_key);
        $rules    = self::rules_for($owner_key);

        // у новичка без профиля ещё нет сигналов: работают только общие правила администратора
        $covisit = $sections ? self::covisit(array_slice(array_keys($sections), 0, self::TOP_SECTIONS)) : [];

        $list = self::rank($sections, $catalog, $rules, $covisit, self::dismissed_for($owner_key), $limit);

        if ($log AND $list)
        {
            foreach ($list as $i => $row)
            {
                $list[$i]['log_id'] = self::log_shown($owner_key, $row);
            }
        }

        return $list;
    }

    /** Записать показ (не чаще SHOWN_THROTTLE_HOURS на пару владелец+href). @return int id строки */
    public static function log_shown($owner_key, array $row)
    {
        $db = self::_db();

        $recent = $db->query(Database::SELECT,
            'SELECT id FROM nj_600_reco_log
             WHERE owner_key = '.$db->quote((string) $owner_key).' AND href = '.$db->quote((string) $row['href']).'
               AND shown_at > now() - interval \''.(int) self::SHOWN_THROTTLE_HOURS.' hours\'
             ORDER BY id DESC LIMIT 1', FALSE)->current();

        if ($recent)
        {
            return (int) $recent['id'];
        }

        $res = $db->query(Database::INSERT,
            'INSERT INTO nj_600_reco_log (owner_key, href, source, rule_id, reason)
             VALUES ('.$db->quote((string) $owner_key).', '.$db->quote((string) $row['href']).', '
                .$db->quote((string) $row['source']).', '.(empty($row['rule_id']) ? 'NULL' : (int) $row['rule_id']).', '
                .$db->quote(mb_substr((string) $row['reason'], 0, 200)).')', FALSE);

        return is_array($res) ? (int) ($res[0] ?? 0) : 0;
    }

    /**
     * Клик по рекомендации: отмечаем и отдаём адрес перехода. Чужой log_id
     * не сработает — строка должна принадлежать текущему владельцу.
     * @return string href или '' (нет такой строки)
     */
    public static function click($log_id, $owner_key = NULL)
    {
        $db = self::_db();
        $owner_key = (string) ($owner_key ?: Visitor::uid());

        $row = $db->query(Database::SELECT,
            'SELECT id, href FROM nj_600_reco_log WHERE id = '.(int) $log_id
            .' AND owner_key = '.$db->quote($owner_key), FALSE)->current();

        if ( ! $row)
        {
            return '';
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_600_reco_log SET clicked_at = COALESCE(clicked_at, now()) WHERE id = '.(int) $row['id'], FALSE);

        return self::_norm_href((string) $row['href']);
    }

    /** Владелец отклонил рекомендацию («не предлагать»). */
    public static function dismiss($href, $owner_key = NULL)
    {
        $db = self::_db();
        $owner_key = (string) ($owner_key ?: Visitor::uid());
        $href = self::_norm_href($href);

        if ($href === '' OR $owner_key === '' OR $owner_key === 'anon')
        {
            return FALSE;
        }

        $n = $db->query(Database::UPDATE,
            'UPDATE nj_600_reco_log SET dismissed_at = now()
             WHERE id = (SELECT id FROM nj_600_reco_log WHERE owner_key = '.$db->quote($owner_key).'
                         AND href = '.$db->quote($href).' ORDER BY id DESC LIMIT 1)', FALSE);

        if ( ! $n)
        {
            $db->query(Database::INSERT,
                'INSERT INTO nj_600_reco_log (owner_key, href, source, reason, dismissed_at)
                 VALUES ('.$db->quote($owner_key).', '.$db->quote($href).', \'manual\', \'отклонено без показа\', now())', FALSE);
        }

        return TRUE;
    }

    // --------------------------------------------------- правила (админ)

    public static function rules($limit = 200)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_600_reco_rules ORDER BY enabled DESC, when_owner <> \'\', weight DESC, id DESC
             LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /**
     * Создать правило. @return array ['ok'=>TRUE,'id'=>…] | ['error'=>…]
     */
    public static function save_rule(array $in, $admin_id = NULL)
    {
        $kind = ((string) ($in['kind'] ?? 'boost') === 'block') ? 'block' : 'boost';
        $href = self::_norm_href((string) ($in['href'] ?? ''));
        $ws   = mb_substr(trim((string) ($in['when_section'] ?? '')), 0, 60);
        $wo   = trim((string) ($in['when_owner'] ?? ''));

        if ($href === '')
        {
            return ['error' => 'Адрес сервиса — внутренний путь вида /vat или /docs/wizard'];
        }

        if ($ws !== '' AND ! preg_match('/^[a-z0-9_\-]{1,60}$/i', $ws))
        {
            return ['error' => 'Раздел интереса — первый сегмент адреса (латиница/цифры), например «jobs»'];
        }

        if ($wo !== '' AND ! preg_match('/^(u:\d+|g:[a-z0-9]{6,40})$/i', $wo))
        {
            return ['error' => 'Владелец — u:<id> или g:<ubid>, либо пусто (всем)'];
        }

        $weight = max(0, min(999, (int) ($in['weight'] ?? 50)));
        $title  = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120);
        $note   = mb_substr(trim((string) ($in['note'] ?? '')), 0, 300);

        $db = self::_db();

        // одно и то же правило дважды не плодим — включаем существующее
        $dup = $db->query(Database::SELECT,
            'SELECT id FROM nj_600_reco_rules WHERE kind = '.$db->quote($kind).' AND href = '.$db->quote($href)
            .' AND when_section = '.$db->quote($ws).' AND when_owner = '.$db->quote($wo).' LIMIT 1', FALSE)->current();

        if ($dup)
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_600_reco_rules SET enabled = TRUE, weight = '.$weight.', title = '.$db->quote($title)
                .', note = '.$db->quote($note).' WHERE id = '.(int) $dup['id'], FALSE);

            return ['ok' => TRUE, 'id' => (int) $dup['id'], 'dup' => TRUE];
        }

        $res = $db->query(Database::INSERT,
            'INSERT INTO nj_600_reco_rules (kind, when_section, when_owner, href, title, note, weight, created_by)
             VALUES ('.$db->quote($kind).', '.$db->quote($ws).', '.$db->quote($wo).', '.$db->quote($href).', '
                .$db->quote($title).', '.$db->quote($note).', '.$weight.', '.($admin_id ? (int) $admin_id : 'NULL').')', FALSE);

        return ['ok' => TRUE, 'id' => is_array($res) ? (int) ($res[0] ?? 0) : 0];
    }

    public static function toggle_rule($id)
    {
        return (int) self::_db()->query(Database::UPDATE,
            'UPDATE nj_600_reco_rules SET enabled = NOT enabled WHERE id = '.(int) $id, FALSE);
    }

    public static function delete_rule($id)
    {
        return (int) self::_db()->query(Database::DELETE,
            'DELETE FROM nj_600_reco_rules WHERE id = '.(int) $id, FALSE);
    }

    // ------------------------------------------------- надзор (статистика)

    /** KPI журнала показов за $days дней + разрезы по источникам и сервисам. */
    public static function stats($days = 30)
    {
        Service_Nj600_Prefs::ensure_tables();
        $db = self::_db();
        $days = max(1, (int) $days);
        $since = 'shown_at > now() - interval \''.$days.' days\'';

        $kpi = $db->query(Database::SELECT,
            'SELECT count(*) AS shown,
                    count(*) FILTER (WHERE clicked_at IS NOT NULL) AS clicked,
                    count(*) FILTER (WHERE dismissed_at IS NOT NULL) AS dismissed,
                    count(DISTINCT owner_key) AS owners
             FROM nj_600_reco_log WHERE '.$since, FALSE)->current();

        $by_source = $db->query(Database::SELECT,
            'SELECT source, count(*) AS shown,
                    count(*) FILTER (WHERE clicked_at IS NOT NULL) AS clicked,
                    count(*) FILTER (WHERE dismissed_at IS NOT NULL) AS dismissed
             FROM nj_600_reco_log WHERE '.$since.'
             GROUP BY source ORDER BY shown DESC', FALSE)->as_array();

        $by_href = $db->query(Database::SELECT,
            'SELECT href, count(*) AS shown,
                    count(*) FILTER (WHERE clicked_at IS NOT NULL) AS clicked,
                    count(*) FILTER (WHERE dismissed_at IS NOT NULL) AS dismissed
             FROM nj_600_reco_log WHERE '.$since.'
             GROUP BY href ORDER BY shown DESC LIMIT 20', FALSE)->as_array();

        $recent = $db->query(Database::SELECT,
            'SELECT id, owner_key, href, source, rule_id, reason, shown_at, clicked_at, dismissed_at
             FROM nj_600_reco_log ORDER BY id DESC LIMIT 40', FALSE)->as_array();

        $rules_total = (int) $db->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_600_reco_rules WHERE enabled = TRUE', FALSE)->get('c');

        $ctr = function ($shown, $clicked) {
            return $shown > 0 ? round(100 * $clicked / $shown, 1) : 0.0;
        };

        $kpi = $kpi ? (array) $kpi : ['shown' => 0, 'clicked' => 0, 'dismissed' => 0, 'owners' => 0];
        $kpi['ctr'] = $ctr((int) $kpi['shown'], (int) $kpi['clicked']);

        foreach ($by_source as $i => $r)
        {
            $by_source[$i]['ctr'] = $ctr((int) $r['shown'], (int) $r['clicked']);
        }

        foreach ($by_href as $i => $r)
        {
            $by_href[$i]['ctr'] = $ctr((int) $r['shown'], (int) $r['clicked']);
        }

        return [
            'days'        => $days,
            'kpi'         => $kpi,
            'by_source'   => $by_source,
            'by_href'     => $by_href,
            'recent'      => $recent,
            'rules_total' => $rules_total,
        ];
    }

    /** Журнал показов одного владельца (карточка). */
    public static function owner_log($owner_key, $limit = 30)
    {
        $db = self::_db();

        return $db->query(Database::SELECT,
            'SELECT id, href, source, rule_id, reason, shown_at, clicked_at, dismissed_at
             FROM nj_600_reco_log WHERE owner_key = '.$db->quote((string) $owner_key).'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Человеческое имя источника. */
    public static function source_label($source)
    {
        $map = [
            'rule'    => 'правило админа',
            'covisit' => 'похожие посетители',
            'group'   => 'тот же раздел',
            'manual'  => 'вручную',
        ];

        return $map[(string) $source] ?? (string) $source;
    }

    // ------------------------------------------------------------- виджет

    /**
     * HTML-блок «Вам может пригодиться» для текущего посетителя (кабинет,
     * главная). Пустая строка — нечего показать; любая ошибка — пустая строка:
     * рекомендации не могут ломать страницу.
     */
    public static function widget($limit = 4, $title = 'Вам может пригодиться')
    {
        try
        {
            $items = self::for_owner(NULL, $limit, TRUE);

            if ( ! $items)
            {
                return '';
            }

            return (string) View::factory('600/widget', [
                'items' => $items,
                'title' => $title,
                'csrf'  => Security::token(),
            ])->render();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj600: reco widget: :e', [':e' => $e->getMessage()]);

            return '';
        }
    }

    // ---------------------------------------------------------------- MCP

    /** MCP: рекомендации владельцу с причинами (агентам администратора). */
    public static function mcp_recommend($args = [])
    {
        $key = (string) ($args['owner_key'] ?? '');

        if ( ! preg_match('/^(u:\d+|g:[a-z0-9]{6,40})$/i', $key))
        {
            return ['error' => 'owner_key ожидается вида u:<id> или g:<ubid>'];
        }

        $limit = max(1, min(10, (int) ($args['limit'] ?? 5)));

        return [
            'owner_key'       => $key,
            'recommendations' => array_map(function ($r) {
                return [
                    'href'   => $r['href'],
                    'title'  => $r['title'],
                    'source' => $r['source'],
                    'reason' => $r['reason'],
                    'score'  => $r['score'],
                ];
            }, self::for_owner($key, $limit, FALSE)),
        ];
    }
}
