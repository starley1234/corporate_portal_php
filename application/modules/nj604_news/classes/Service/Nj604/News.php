<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Новости сайта» (nj604_news): что нового на N2J.ru — и владельцу, и
 * посетителям. Два источника:
 *  * АВТОМОМ: sync_from_git() читает git-лог репозитория с прошлого
 *    обновления и складывает «Обновление платформы: N улучшений» (лениво,
 *    при открытии /news или админки — без крона);
 *  * вручную: форма в админке (человеческий текст о фиче, ссылка, тег).
 * RSS: /news/rss.xml. MCP: nj604_latest_news.
 */
class Service_Nj604_News
{
    protected static function _db()
    {
        return Database::instance();
    }

    public static function ensure_tables()
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_604_news" (
    "id"          BIGSERIAL PRIMARY KEY,
    "kind"        VARCHAR(12)  NOT NULL DEFAULT 'manual',
    "title"       VARCHAR(300) NOT NULL,
    "body_md"     TEXT         NOT NULL DEFAULT '',
    "href"        VARCHAR(300) NOT NULL DEFAULT '',
    "tag"         VARCHAR(40)  NOT NULL DEFAULT '',
    "commits"     JSONB        NULL,
    "published"   BOOLEAN      NOT NULL DEFAULT TRUE,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "published_at" TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS "nj_604_news_pub_idx" ON "nj_604_news" ("published", "id" DESC);
COMMENT ON TABLE "nj_604_news" IS 'NJ-604 новости сайта: release (авто из git) | manual (из админки)';
SQL
        )->execute();

        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_604_state" (
    "key"   VARCHAR(40) PRIMARY KEY,
    "value" TEXT        NOT NULL DEFAULT ''
);
SQL
        )->execute();

        $done = TRUE;
    }

    protected static function _state($key, $default = '')
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT value FROM nj_604_state WHERE key = '.self::_db()->quote($key), FALSE)->current();

        return $row ? (string) $row['value'] : $default;
    }

    protected static function _state_set($key, $value)
    {
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT key FROM nj_604_state WHERE key = '.$db->quote($key), FALSE)->current();

        $sql = $row
            ? 'UPDATE nj_604_state SET value = '.$db->quote($value).' WHERE key = '.$db->quote($key)
            : 'INSERT INTO nj_604_state (key, value) VALUES ('.$db->quote($key).', '.$db->quote($value).')';

        $db->query(Database::INSERT, $sql, FALSE);
    }

    // ------------------------------------------------- авто из git-лога

    /** HEAD репозитория (или '' — exec недоступен/не git). */
    public static function git_head()
    {
        $root = realpath(DOCROOT.'..');
        $out  = [];

        if ($root === FALSE)
        {
            return '';
        }

        @exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD 2>/dev/null', $out);

        return ($out[0] ?? '');
    }

    /**
     * Дочитать новые коммиты с прошлого sync и добавить релиз «по-человечески»:
     * коммиты прогоняются через словарь config/nj604_news.php (rules/modules),
     * технические формулировки и мелкие правки в ленту не попадают.
     * Возвращает 1 (новость добавлена), 0 (нового нет) или -1 (git недоступен).
     */
    public static function sync_from_git()
    {
        self::ensure_tables();

        $head = self::git_head();

        if ($head === '')
        {
            return -1;
        }

        $last = self::_state('last_commit');

        if ($last === '')
        {
            // первый запуск: фиксируем текущий HEAD без новости (история длинная)
            self::_state_set('last_commit', $head);

            return 0;
        }

        if ($last === $head)
        {
            return 0;
        }

        $root = realpath(DOCROOT.'..');
        $out  = [];
        @exec('git -C '.escapeshellarg($root).' log --pretty=%s '.escapeshellarg($last).'..'.escapeshellarg($head).' 2>/dev/null', $out);

        // человеческий словарь
        $modules = (array) Kohana::$config->load('nj604_news.modules');
        $rules   = (array) Kohana::$config->load('nj604_news.rules');

        $features = [];
        $fixes    = 0;

        foreach ($out as $subj)
        {
            $subj = trim((string) $subj);

            // merge-коммиты и техническая мелочь в ленту не попадают
            if ($subj === '' OR stripos($subj, 'Merge') === 0 OR stripos($subj, 'merge branch') === 0)
            {
                continue;
            }

            if (preg_match('~^(\.env|AGENT-LESSONS|линт|докат|коммит|readme|chore|tests?|docs?/|миграц)~iu', $subj))
            {
                $fixes++;
                continue;
            }

            $human = '';

            foreach ($rules as $re => $phrase)
            {
                if (preg_match((string) $re, $subj))
                {
                    $human = (string) $phrase;
                    break;
                }
            }

            // правило не сработало — чистим формулировку от кодов модулей
            $features[$human !== '' ? $human : self::prettify_line($subj, $modules)] = TRUE;
        }

        if (empty($features) AND $fixes === 0)
        {
            self::_state_set('last_commit', $head);

            return 0;
        }

        $features = array_slice(array_keys($features), 0, 8);

        if (empty($features))
        {
            // одни правки — без перечисления, по-человечески
            $title = 'Обновление платформы: надёжность и мелкие улучшения';
            $body  = '- Повышаем надёжность и полируем мелочи ('.$fixes.' правок).'."\n";
        }
        else
        {
            $title = count($features) === 1
                ? mb_substr($features[0], 0, 120)
                : 'Что нового на N2J.ru: '.count($features).' улучшений';

            $body = '';

            foreach ($features as $f)
            {
                $body .= '- '.$f."\n";
            }

            if ($fixes > 0)
            {
                $body .= '- Мелкие исправления и полировка: '.$fixes.' шт.'."\n";
            }
        }

        $db = self::_db();
        $db->query(Database::INSERT,
            'INSERT INTO nj_604_news (kind, title, body_md, tag, commits, published, published_at)
             VALUES (\'release\', '.$db->quote($title).',
                     '.$db->quote($body).', \'обновление\',
                     '.$db->quote(json_encode(array_slice($out, 0, 40), JSON_UNESCAPED_UNICODE)).'::jsonb,
                     TRUE, now())', FALSE);

        self::_state_set('last_commit', $head);

        return 1;
    }

    /**
     * Коммит/строку релиза → по-человечески: срезаем технические префиксы
     * («ajax:», «workers:») и заменяем коды модулей (nj605_radar, nj605)
     * на понятные названия из config/nj604_news.php.
     */
    public static function prettify_line($s, ?array $modules = NULL)
    {
        $modules = $modules ?: (array) Kohana::$config->load('nj604_news.modules');

        $s = trim((string) $s);
        $s = preg_replace('~^[a-zа-я0-9_]{2,14}:\s+~u', '', $s);
        $s = preg_replace_callback('~\bnj(\d{3})(?:_[a-z0-9_]+)?\b~iu', function ($m) use ($modules) {
            return $modules['nj'.$m[1]] ?? ('nj'.$m[1]);
        }, (string) $s);

        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    // ------------------------------------------------- рукотворные заметки

    /**
     * Разовая посадка «человекопонятных» заметок о новых возможностях
     * (гвард в nj_604_state: вставляется один раз, не дублируется).
     */
    public static function curated_seed()
    {
        if (self::_state('curated_v1') !== '')
        {
            return 0;
        }

        self::ensure_tables();

        $notes = [
            ['Радар заказов: следит за закупками за вас',
             "Настройте радар на свой профиль — он сам ищет свежие закупки и заказы в интернете.\nКаждая находка — с оценкой релевантности и объяснением, почему она вам подходит.\nГорячие находки (85+) приносят уведомление на почту.",
             '/radar'],
            ['Поток заказчиков: компании под ваш оффер',
             "Опишите, кого ищете: что производят, какой регион.\nИИ находит производителей в открытых источниках и готовит зацепку для первого письма.\nОдно нажатие — и у компании появляется профиль в N2J.",
             '/leads'],
            ['Карта отрасли: отчёт по рынку за минуты',
             "Выберите отрасль и регион — получите обзор: игроки, что и как производят, сегменты и цены, каналы продаж, ниши для входа.\nОтчёт строится только по открытым источникам, ссылки прилагаются.",
             '/industry'],
            ['Сторож контрагентов: сигналы риска',
             "Добавьте клиентов и поставщиков под наблюдение.\nРаз в неделю проверяем суды, банкротства и новости.\nПриносим только реальные сигналы: риск, предупреждение или к сведению.",
             '/watchdog'],
            ['Входящие под контролем: ИИ-секретарь',
             "ИИ разбирает почту, готовит черновики ответов и следит, чтобы важное письмо не осталось без ответа.",
             '/inbox'],
            ['Ключи ИИ-агента: подключите агентов сами',
             "В разделе «Ключи ИИ-агента» выпускается персональный ключ.\nВаши ИИ-агенты получают доступ к сервисам N2J через MCP-протокол — без писем администратору.",
             '/mcp'],
            ['Баланс и кредиты: оплата картой',
             "Пакеты кредитов пополняются через Robokassa.\nКредиты тратятся на тяжёлые ИИ-операции, бесплатные лимиты остаются как были.",
             '/billing'],
            ['Контент-конвейер: ИИ пишет статьи',
             "Статьи собираются из свежих интернет-источников, со схемами и графиками.\nПеред публикацией — модерация; рост базы знаний виден на витрине «Рост знаний».",
             '/content'],
        ];

        $db = self::_db();

        foreach ($notes as [$title, $body, $href])
        {
            $db->query(Database::INSERT,
                'INSERT INTO nj_604_news (kind, title, body_md, href, tag, published, published_at)
                 VALUES (\'feature\', '.$db->quote($title).', '.$db->quote($body).',
                         '.$db->quote($href).', \'новая возможность\', TRUE, now())', FALSE);
        }

        self::_state_set('curated_v1', '1');

        return count($notes);
    }

    // ------------------------------------------------------- ручные

    public static function add_manual($title, $body, $href, $tag)
    {
        self::ensure_tables();

        $title = trim((string) $title);

        if ($title === '')
        {
            return ['error' => 'Заголовок обязателен'];
        }

        self::_db()->query(Database::INSERT,
            'INSERT INTO nj_604_news (kind, title, body_md, href, tag, published, published_at)
             VALUES (\'manual\', '.self::_db()->quote(mb_substr($title, 0, 300)).',
                     '.self::_db()->quote(mb_substr((string) $body, 0, 8000)).',
                     '.self::_db()->quote(mb_substr((string) $href, 0, 300)).',
                     '.self::_db()->quote(mb_substr((string) $tag, 0, 40)).',
                     TRUE, now())', FALSE);

        return ['ok' => TRUE];
    }

    // ------------------------------------------------------- выдача

    public static function latest_public($limit = 30)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT id, kind, title, body_md, href, tag, created_at, published_at
             FROM nj_604_news WHERE published = TRUE
             ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    public static function admin_list($limit = 80)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT id, kind, title, body_md, href, tag, published, created_at
             FROM nj_604_news ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    public static function set_published($id, $on)
    {
        self::ensure_tables();

        self::_db()->query(Database::INSERT,
            'UPDATE nj_604_news SET published = '.(((int) $on) ? 'TRUE' : 'FALSE').',
                published_at = COALESCE(published_at, '.(((int) $on) ? 'now()' : 'NULL').')
             WHERE id = '.(int) $id, FALSE);

        return ['ok' => TRUE];
    }

    public static function remove($id)
    {
        self::ensure_tables();

        self::_db()->query(Database::DELETE,
            'DELETE FROM nj_604_news WHERE id = '.(int) $id, FALSE);

        return ['ok' => TRUE];
    }

    // ------------------------------------------------------------ MCP

    /** MCP: последние новости сайта (для агентов). */
    public static function mcp_latest($args = [])
    {
        $limit = max(1, min(10, (int) ($args['limit'] ?? 5)));
        $out   = [];

        foreach (self::latest_public($limit) as $n)
        {
            $out[] = [
                'title'  => (string) $n['title'],
                'body'   => (string) $n['body_md'],
                'tag'    => (string) $n['tag'],
                'href'   => (string) $n['href'],
                'kind'   => (string) $n['kind'],
                'date'   => (string) ($n['published_at'] ?: $n['created_at']),
            ];
        }

        return ['news' => $out];
    }
}
