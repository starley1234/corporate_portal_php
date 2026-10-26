<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис «Справочник организаций» (nj594_orgregistry).
 *
 * Единый хаб-справочник организаций/ИП для всех модулей платформы:
 *  * карточки по официальным данным ЕГРЮЛ/ЕГРИП (точечный поиск ФНС egrul.nalog.ru);
 *  * посев демо-данных (12 проверенных организаций; контрольные цифры ОГРН/ИНН сходятся);
 *  * связи «модуль → артефакт» (nj580 пакеты и др.), чтобы карточка организации
 *    показывала все материалы, привязанные к организации на платформе;
 *  * API для воркеров (пакетный импорт — задел под полный импорт открытых
 *    данных ЕГРЮЛ/ЕГРИП: https://www.nalog.gov.ru/opendata/7707329152-egrul/).
 *
 * Таблицы: nj_594_orgs (карточки), nj_594_links (связи артефактов).
 * Таблицы создаются ensure_tables() при первом обращении; миграция 001 дублирует DDL.
 */
class Service_Nj594_Orgs
{
    /** @var bool таблицы проверены в текущем запросе */
    protected static $_tables_ensured = FALSE;

    /** @var bool посев демо-данных выполнен */
    protected static $_seeded = FALSE;

    /** Демо-посев: 12 организаций из открытых источников (ФНС/агрегаторы),
     *  контрольные цифры ОГРН и ИНН проверены. Пустые поля дополнит
     *  «Обновить из ФНС» (egrul.nalog.ru). НЕ выдумываем: пусто = пусто. */
    public static function seed_rows()
    {
        return [
            ['ogrn' => '1027700132195', 'inn' => '7707083893', 'kpp' => '773601001', 'form' => 'ЮЛ',
             'short_name' => 'ПАО Сбербанк', 'full_name' => 'ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО «СБЕРБАНК»',
             'address' => '117312, г. Москва, ул. Вавилова, д. 19', 'okved' => '64.19', 'okved_name' => 'Денежное посредничество прочее',
             'status' => 'Действует', 'reg_date' => '20.06.1991', 'director' => 'Греф Герман Оскарович'],

            ['ogrn' => '1027700229193', 'inn' => '7736207543', 'kpp' => '770401001', 'form' => 'ЮЛ',
             'short_name' => 'ООО «Яндекс»', 'full_name' => 'ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ «ЯНДЕКС»',
             'address' => '119021, г. Москва, ул. Льва Толстого, д. 16', 'okved' => '62.01', 'okved_name' => 'Разработка компьютерного программного обеспечения',
             'status' => 'Действует', 'reg_date' => '14.09.2000', 'director' => ''],

            ['ogrn' => '1027700070518', 'inn' => '7736050003', 'kpp' => '781401001', 'form' => 'ЮЛ',
             'short_name' => 'ПАО «Газпром»', 'full_name' => 'ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО «ГАЗПРОМ»',
             'address' => '197229, г. Санкт-Петербург, Лахтинский пр-кт, д. 2, к. 3, стр. 1', 'okved' => '', 'okved_name' => '',
             'status' => 'Действует', 'reg_date' => '25.02.1993', 'director' => 'Миллер Алексей Борисович'],

            ['ogrn' => '1037739877295', 'inn' => '7708503727', 'kpp' => '770801001', 'form' => 'ЮЛ',
             'short_name' => 'ОАО «РЖД»', 'full_name' => 'ОТКРЫТОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО «РОССИЙСКИЕ ЖЕЛЕЗНЫЕ ДОРОГИ»',
             'address' => '107174, г. Москва, ул. Новая Басманная, д. 2/1, стр. 1', 'okved' => '', 'okved_name' => '',
             'status' => 'Действует', 'reg_date' => '23.09.2003', 'director' => 'Белозёров Олег Валентинович'],

            ['ogrn' => '1027700035769', 'inn' => '7708004767', 'kpp' => '770801001', 'form' => 'ЮЛ',
             'short_name' => 'ПАО «Лукойл»', 'full_name' => 'ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО «НЕФТЯНАЯ КОМПАНИЯ «ЛУКОЙЛ»',
             'address' => '101000, г. Москва, Сретенский б-р, д. 11', 'okved' => '', 'okved_name' => '',
             'status' => 'Действует', 'reg_date' => '22.04.1993', 'director' => ''],

            ['ogrn' => '1032304945947', 'inn' => '2309085638', 'kpp' => '231101001', 'form' => 'ЮЛ',
             'short_name' => 'ПАО «Магнит»', 'full_name' => 'ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО «МАГНИТ»',
             'address' => '350072, Краснодарский край, г. Краснодар, ул. Солнечная, д. 15, к. 5', 'okved' => '47.11', 'okved_name' => 'Торговля розничная преимущественно пищевыми продуктами в неспециализированных магазинах',
             'status' => 'Действует', 'reg_date' => '12.11.2003', 'director' => 'Случевский Евгений Сергеевич'],

            ['ogrn' => '1027700198767', 'inn' => '7707049388', 'kpp' => '784201001', 'form' => 'ЮЛ',
             'short_name' => 'ПАО «Ростелеком»', 'full_name' => 'ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО «РОСТЕЛЕКОМ»',
             'address' => '191124, г. Санкт-Петербург, наб. Синопская, д. 14, литера А', 'okved' => '', 'okved_name' => '',
             'status' => 'Действует', 'reg_date' => '09.09.2002', 'director' => ''],

            ['ogrn' => '1217700253671', 'inn' => '7734443270', 'kpp' => '503101001', 'form' => 'ЮЛ',
             'short_name' => 'АО «Вкусвилл»', 'full_name' => 'АКЦИОНЕРНОЕ ОБЩЕСТВО «ВКУСВИЛЛ»',
             'address' => '142432, Московская обл., г. о. Черноголовка, г. Черноголовка, ул. Лесная', 'okved' => '47.11', 'okved_name' => 'Торговля розничная преимущественно пищевыми продуктами в неспециализированных магазинах',
             'status' => 'Действует', 'reg_date' => '', 'director' => 'Фарафонов Алексей Владимирович'],

            ['ogrn' => '1027739244741', 'inn' => '7704217370', 'kpp' => '770301001', 'form' => 'ЮЛ',
             'short_name' => 'ООО «Интернет Решения» (Ozon)', 'full_name' => 'ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ «ИНТЕРНЕТ РЕШЕНИЯ»',
             'address' => '', 'okved' => '47.91.2', 'okved_name' => 'Торговля розничная через Интернет',
             'status' => 'Действует', 'reg_date' => '24.09.2002', 'director' => 'Окмянская Ольга Юрьевна'],

            ['ogrn' => '1247700471919', 'inn' => '9714053621', 'kpp' => '507401001', 'form' => 'ЮЛ',
             'short_name' => 'ООО «РВБ» (Wildberries)', 'full_name' => 'ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ «РВБ»',
             'address' => '142181, Московская обл., г. о. Подольск, д. Коледино, тер. Индустриальный парк Коледино, д. 6, стр. 1', 'okved' => '', 'okved_name' => '',
             'status' => 'Действует', 'reg_date' => '', 'director' => ''],

            ['ogrn' => '1247700463911', 'inn' => '9722079341', 'kpp' => '772201001', 'form' => 'ЮЛ',
             'short_name' => 'ПАО «Корпоративный центр ИКС 5» (X5)', 'full_name' => 'ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО «КОРПОРАТИВНЫЙ ЦЕНТР ИКС 5»',
             'address' => '109029, г. Москва, ул. Средняя Калитниковская, д. 28, стр. 4', 'okved' => '47.11', 'okved_name' => 'Торговля розничная преимущественно пищевыми продуктами в неспециализированных магазинах',
             'status' => 'Действует', 'reg_date' => '01.07.2024', 'director' => ''],

            ['ogrn' => '1027739642281', 'inn' => '7710140679', 'kpp' => '771301001', 'form' => 'ЮЛ',
             'short_name' => 'АО «ТБанк»', 'full_name' => 'АКЦИОНЕРНОЕ ОБЩЕСТВО «ТБАНК»',
             'address' => '127287, г. Москва, ул. Хуторская 2-я, д. 38А, стр. 26', 'okved' => '', 'okved_name' => '',
             'status' => 'Действует', 'reg_date' => '28.11.2002', 'director' => 'Близнюк Станислав Викторович'],
        ];
    }

    /** DDL таблиц справочника (миграция 001 == ensure_tables()). */
    public static function ensure_tables()
    {
        if (self::$_tables_ensured)
        {
            return;
        }

        self::$_tables_ensured = TRUE;

        try
        {
            DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_594_orgs" (
    "id"         BIGSERIAL PRIMARY KEY,
    "ogrn"       VARCHAR(15)  NOT NULL UNIQUE,
    "inn"        VARCHAR(12)  NOT NULL DEFAULT '',
    "kpp"        VARCHAR(9)   NOT NULL DEFAULT '',
    "form"       VARCHAR(8)   NOT NULL DEFAULT 'ЮЛ',
    "short_name" VARCHAR(300) NOT NULL DEFAULT '',
    "full_name"  VARCHAR(500) NOT NULL DEFAULT '',
    "address"    VARCHAR(500) NOT NULL DEFAULT '',
    "okved"      VARCHAR(16)  NOT NULL DEFAULT '',
    "okved_name" VARCHAR(300) NOT NULL DEFAULT '',
    "status"     VARCHAR(120) NOT NULL DEFAULT '',
    "reg_date"   VARCHAR(20)  NOT NULL DEFAULT '',
    "director"   VARCHAR(300) NOT NULL DEFAULT '',
    "source"     VARCHAR(40)  NOT NULL DEFAULT 'seed',
    "raw"        JSONB        NULL,
    "fetched_at" TIMESTAMPTZ  NULL,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_594_orgs_inn_idx"    ON "nj_594_orgs" ("inn");
CREATE INDEX IF NOT EXISTS "nj_594_orgs_name_idx"   ON "nj_594_orgs" (lower("short_name"));
COMMENT ON TABLE  "nj_594_orgs" IS 'NJ-594 справочник организаций (ЕГРЮЛ/ЕГРИП): карточки по данным ФНС';
COMMENT ON COLUMN "nj_594_orgs"."form"   IS 'ЮЛ | ИП';
COMMENT ON COLUMN "nj_594_orgs"."source" IS 'seed | fns | nj580 | import:<метка>';
COMMENT ON COLUMN "nj_594_orgs"."raw"    IS 'Полный ответ источника (JSON)';

CREATE TABLE IF NOT EXISTS "nj_594_links" (
    "id"         BIGSERIAL PRIMARY KEY,
    "org_id"     BIGINT       NOT NULL REFERENCES "nj_594_orgs"("id") ON DELETE CASCADE,
    "module"     VARCHAR(24)  NOT NULL,
    "item_code"  VARCHAR(24)  NOT NULL,
    "item_url"   VARCHAR(200) NOT NULL DEFAULT '',
    "title"      VARCHAR(300) NOT NULL DEFAULT '',
    "kind"       VARCHAR(40)  NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE ("org_id", "module", "item_code")
);
CREATE INDEX IF NOT EXISTS "nj_594_links_item_idx" ON "nj_594_links" ("module", "item_code");
COMMENT ON TABLE "nj_594_links" IS 'NJ-594 связи организация -> артефакты модулей (nj580 пакеты, nj591 профили и др.)';

CREATE TABLE IF NOT EXISTS "nj_594_fns_calls" (
    "id"         BIGSERIAL    PRIMARY KEY,
    "client_ip"  VARCHAR(64)  NOT NULL DEFAULT '',
    "query"      VARCHAR(64)  NOT NULL DEFAULT '',
    "kind"       VARCHAR(12)  NOT NULL DEFAULT 'search',
    "status"     VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_594_fns_calls_ip_idx"    ON "nj_594_fns_calls" ("client_ip", "created_at" DESC);
CREATE INDEX IF NOT EXISTS "nj_594_fns_calls_time_idx"  ON "nj_594_fns_calls" ("created_at" DESC);
CREATE INDEX IF NOT EXISTS "nj_594_fns_calls_query_idx" ON "nj_594_fns_calls" ("query", "created_at" DESC);
COMMENT ON TABLE  "nj_594_fns_calls" IS 'NJ-594 журнал запросов к ФНС: лимит (окна по IP) и телеметрия';
COMMENT ON COLUMN "nj_594_fns_calls"."kind"   IS 'search | card | refresh | blocked (отказ по лимиту)';
COMMENT ON COLUMN "nj_594_fns_calls"."status" IS 'ok | empty | error | limited (отказ по лимиту)';
SQL
            )->execute();
        }
        catch (Throwable $e)
        {
            self::$_tables_ensured = FALSE;
            Kohana::$log->add(Log::WARNING, 'nj594: ensure_tables: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Посев демо-данных (однократно; существующие строки не трогаем). */
    public static function seed()
    {
        self::ensure_tables();

        if (self::$_seeded)
        {
            return 0;
        }

        self::$_seeded = TRUE;

        try
        {
            $cnt = (int) self::_db()->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_594_orgs', FALSE)->get('c');

            if ($cnt > 0)
            {
                return 0;
            }

            $n = 0;

            foreach (self::seed_rows() as $f)
            {
                $n += self::upsert($f['ogrn'], $f, 'seed') ? 1 : 0;
            }

            return $n;
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj594: seed: :e', [':e' => $e->getMessage()]);

            return 0;
        }
    }

    protected static function _db()
    {
        return Database::instance();
    }

    /** ОГРН/ОГРНИП: контрольная цифра (13 знаков: mod 11; 15 знаков: mod 13). */
    public static function ogrn_valid($v)
    {
        $v = preg_replace('/\D/', '', (string) $v);

        if (strlen($v) === 13)
        {
            return ((int) substr((string) (int) substr($v, 0, 12) % 11, -1) === (int) $v[12]);
        }

        if (strlen($v) === 15)
        {
            return ((int) substr((string) (int) substr($v, 0, 14) % 13, -1) === (int) $v[14]);
        }

        return FALSE;
    }

    /** ИНН: контрольные цифры (10 знаков ЮЛ, 12 знаков ИП/физлицо). */
    public static function inn_valid($v)
    {
        $v = preg_replace('/\D/', '', (string) $v);
        $d = str_split($v);

        if (strlen($v) === 10)
        {
            $k = [2, 4, 10, 3, 5, 9, 4, 6, 8];
            $s = 0;

            for ($i = 0; $i < 9; $i++)
            {
                $s += (int) $d[$i] * $k[$i];
            }

            return ((int) substr((string) $s % 11, -1) === (int) $d[9]);
        }

        if (strlen($v) === 12)
        {
            $k1 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
            $k2 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
            $s1 = 0;

            for ($i = 0; $i < 10; $i++)
            {
                $s1 += (int) $d[$i] * $k1[$i];
            }

            $s2 = 0;

            for ($i = 0; $i < 11; $i++)
            {
                $s2 += (int) $d[$i] * $k2[$i];
            }

            return ((int) substr((string) $s1 % 11, -1) === (int) $d[10])
                AND ((int) substr((string) $s2 % 11, -1) === (int) $d[11]);
        }

        return FALSE;
    }

    /** Нормализуем поисковый запрос: ОГРН (13/15), ИНН (10/12) или строка. */
    protected static function _detect($q)
    {
        $d = preg_replace('/[^\dA-Za-zА-Яа-яЁё\/]/u', '', trim((string) $q));
        $num = preg_replace('/\D/', '', (string) $q);

        if (in_array(strlen($num), [13, 15], TRUE) AND self::ogrn_valid($num))
        {
            return ['type' => 'ogrn', 'num' => $num];
        }

        if (in_array(strlen($num), [10, 12], TRUE) AND self::inn_valid($num))
        {
            return ['type' => 'inn', 'num' => $num];
        }

        return ['type' => 'text', 'q' => $d !== '' ? $d : trim((string) $q)];
    }

    /**
     * Готовность «поиска в интернете», если организации нет в справочнике.
     * Существующие механизмы: живой запрос ФНС (egrul.nalog.ru) для ОГРН/ИНН
     * и сквозная «Разведка контрагента» (nj635) для названий/доменов.
     *
     * @param array|null $q      сырой поисковый запрос (без «детекта»)
     * @return array|null       NULL — механизм недоступен; при разведке:
     *                          ['scout' => TRUE, 'url' => '/scout']; иначе
     *                          ['scout' => FALSE, 'fns_url' => '/orgs/api/...']
     */
    public static function internet_lookup($q)
    {
        $q = trim((string) $q);

        if ($q === '')
        {
            return NULL;
        }

        $d = self::_detect($q);

        return [
            'num'       => ($d['type'] === 'ogrn' OR $d['type'] === 'inn') ? $d['num'] : '',
            'is_num'    => ($d['type'] === 'ogrn' OR $d['type'] === 'inn'),
            'q'         => $d['type'] === 'text' ? (string) ($d['q'] ?? '') : '',
            'has_scout' => class_exists('Service_Nj635_Scout'),
        ];
    }

    /** Карточка по ОГРН/ОГРНИП (NULL, если нет). */
    public static function by_ogrn($ogrn)
    {
        self::ensure_tables();
        $ogrn = preg_replace('/\D/', '', (string) $ogrn);

        if ($ogrn === '')
        {
            return NULL;
        }

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_594_orgs WHERE ogrn = '.self::_db()->quote($ogrn).' LIMIT 1', FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Поиск: точный по ОГРН/ИНН или «похожее название» (все слова, ранжирование). */
    public static function search($q, $limit = 30)
    {
        self::ensure_tables();
        $q   = trim((string) $q);
        $db  = self::_db();

        if ($q === '')
        {
            return $db->query(Database::SELECT,
                'SELECT * FROM nj_594_orgs ORDER BY short_name LIMIT '.(int) $limit, FALSE)->as_array();
        }

        $t = self::_detect($q);

        if ($t['type'] === 'ogrn')
        {
            return $db->query(Database::SELECT,
                'SELECT * FROM nj_594_orgs WHERE ogrn = '.$db->quote($t['num']), FALSE)->as_array();
        }

        if ($t['type'] === 'inn')
        {
            return $db->query(Database::SELECT,
                'SELECT * FROM nj_594_orgs WHERE inn = '.$db->quote($t['num']).' OR ogrn = '.$db->quote($t['num']), FALSE)->as_array();
        }

        // «похожее название»: каждое слово запроса (3+ знаков, ё→е) должно
        // встретиться хотя бы в одном поле; выше строки, где совпало больше слов
        $words = preg_split('/\s+/u', mb_strtolower(str_replace('ё', 'е', $q)), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_unique(array_filter($words, function ($w) {
            return mb_strlen($w) >= 3;
        })));

        if (empty($words))
        {
            $words = [mb_strtolower(str_replace('ё', 'е', $q))]; // короткий запрос — целиком
        }

        $name_sql = "replace(lower(short_name), 'ё', 'е')";
        $full_sql = "replace(lower(full_name), 'ё', 'е')";
        $score    = [];
        $conds    = [];

        foreach ($words as $w)
        {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $w).'%';
            $L    = $db->quote($like);
            $conds[]  = '('.$name_sql.' LIKE '.$L.' OR '.$full_sql.' LIKE '.$L.')';
            $score[]  = '(CASE WHEN ('.$name_sql.' LIKE '.$L.' OR '.$full_sql.' LIKE '.$L.') THEN 1 ELSE 0 END)';
        }

        return $db->query(Database::SELECT,
            'SELECT *'.(empty($score) ? '' : ', (ceil('.implode(' + ', $score).')::int) AS wscore').'
             FROM nj_594_orgs
             WHERE '.implode(' AND ', $conds).'
             ORDER BY wscore DESC, short_name LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Создать/обновить карточку. Возвращает id (0 — ОГРН не сошёлся). */
    public static function upsert($ogrn, array $f, $source = 'seed', $raw = NULL)
    {
        self::ensure_tables();
        $ogrn = preg_replace('/\D/', '', (string) $ogrn);
        $db   = self::_db();

        if ( ! self::ogrn_valid($ogrn))
        {
            return 0;
        }

        $g = function ($k, $max = 500) use ($f, $db) {
            $v = trim((string) ($f[$k] ?? ''));

            return $db->quote(mb_substr($v, 0, $max));
        };

        $raw_sql = ($raw === NULL)
            ? 'NULL'
            : $db->quote(json_encode($raw, JSON_UNESCAPED_UNICODE)).'::jsonb';

        $row = $db->query(Database::SELECT,
            <<<SQL
INSERT INTO nj_594_orgs (ogrn, inn, kpp, form, short_name, full_name, address, okved, okved_name, status, reg_date, director, source, raw, fetched_at)
VALUES ({$db->quote($ogrn)}, {$g('inn', 12)}, {$g('kpp', 9)}, {$g('form', 8)}, {$g('short_name', 300)}, {$g('full_name')},
        {$g('address')}, {$g('okved', 16)}, {$g('okved_name', 300)}, {$g('status', 120)}, {$g('reg_date', 20)},
        {$g('director', 300)}, {$db->quote(mb_substr((string) $source, 0, 40))}, {$raw_sql},
        CASE WHEN {$db->quote((string) $source)} = 'fns' THEN now() ELSE NULL END)
ON CONFLICT (ogrn) DO UPDATE SET
    inn        = COALESCE(NULLIF(EXCLUDED.inn, ''),        nj_594_orgs.inn),
    kpp        = COALESCE(NULLIF(EXCLUDED.kpp, ''),        nj_594_orgs.kpp),
    form       = COALESCE(NULLIF(EXCLUDED.form, ''),       nj_594_orgs.form),
    short_name = COALESCE(NULLIF(EXCLUDED.short_name, ''), nj_594_orgs.short_name),
    full_name  = COALESCE(NULLIF(EXCLUDED.full_name, ''),  nj_594_orgs.full_name),
    address    = COALESCE(NULLIF(EXCLUDED.address, ''),    nj_594_orgs.address),
    okved      = COALESCE(NULLIF(EXCLUDED.okved, ''),      nj_594_orgs.okved),
    okved_name = COALESCE(NULLIF(EXCLUDED.okved_name, ''), nj_594_orgs.okved_name),
    status     = COALESCE(NULLIF(EXCLUDED.status, ''),     nj_594_orgs.status),
    reg_date   = COALESCE(NULLIF(EXCLUDED.reg_date, ''),   nj_594_orgs.reg_date),
    director   = COALESCE(NULLIF(EXCLUDED.director, ''),   nj_594_orgs.director),
    source     = EXCLUDED.source,
    raw        = COALESCE(EXCLUDED.raw, nj_594_orgs.raw),
    fetched_at = CASE WHEN EXCLUDED.source = 'fns' THEN now() ELSE nj_594_orgs.fetched_at END,
    updated_at = now()
RETURNING id
SQL
            , FALSE)->current();

        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Поиск в ФНС (egrul.nalog.ru) по строке: номеру ИЛИ названию (даже
     * неточному — ФНС ищет вхождение). Поток: GET / (cookie) → POST {query}
     * → {"t": token} → GET /search-result/{token} → строки rows.
     * Возвращает список карточек (до $max): full_name, inn, kpp, ogrn, address, reg_date.
     *
     * Проходит через мягкий лимит (fns_quota): превышен — ФНС не спрашиваем,
     * возвращаем пусто и пишем причину в лог. $force — для внутренних вызовов,
     * которые уже проверили лимит сами (refresh спрашивает карточку).
     */
    public static function fns_search($q, $max = 10, $force = FALSE)
    {
        $q = trim((string) $q);

        if ($q === '')
        {
            return [];
        }

        if ( ! $force)
        {
            $quota = self::fns_quota();

            if ( ! $quota['ok'])
            {
                self::fns_log($q, 'limited', 'blocked');
                Kohana::$log->add(Log::WARNING, 'nj594: ФНС не спрошена — :m', [':m' => $quota['message']]);

                return [];
            }

            if (self::_fns_recent_empty($q))
            {
                return [];   // только что ничего не нашли — реестр не дёргаем
            }
        }

        $rows = self::_fns_search_raw($q, $max);
        self::fns_log($q, $rows ? 'ok' : 'empty', 'search');

        return $rows;
    }

    /** Сам запрос к ФНС — без лимита и журнала (внутренний). */
    protected static function _fns_search_raw($q, $max = 10)
    {
        if ( ! function_exists('curl_init'))
        {
            return [];
        }

        $q = trim((string) $q);

        if ($q === '')
        {
            return [];
        }

        $jar  = sys_get_temp_dir().'/nj594_fns_cookie.jar';
        $base = [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_TIMEOUT        => (int) self::fns_limits()['timeout'],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64)',
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_FOLLOWLOCATION => TRUE,
        ];

        $ch = curl_init('https://egrul.nalog.ru/');
        curl_setopt_array($ch, $base);
        curl_exec($ch);
        curl_close($ch);

        $ch = curl_init('https://egrul.nalog.ru/');
        curl_setopt_array($ch, $base + [
            CURLOPT_POST       => TRUE,
            CURLOPT_POSTFIELDS => http_build_query([
                'vyp3CaptchaToken'          => '',
                'page'                      => '',
                'query'                     => mb_substr($q, 0, 200),
                'region'                    => '',
                'PreventChromeAutocomplete' => '',
            ]),
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        $j     = json_decode((string) $res, TRUE);
        $token = (is_array($j) AND isset($j['t'])) ? (string) $j['t'] : NULL;

        if ( ! $token)
        {
            return [];
        }

        usleep(600000);

        for ($i = 0; $i < 5; $i++)
        {
            $ch = curl_init('https://egrul.nalog.ru/search-result/'.rawurlencode($token)
                .'?r='.round(microtime(TRUE) * 1000));
            curl_setopt_array($ch, $base);
            $res = curl_exec($ch);
            curl_close($ch);

            $j   = json_decode((string) $res, TRUE);
            $out = [];

            foreach (((isset($j['rows']) AND is_array($j['rows'])) ? $j['rows'] : []) as $row)
            {
                if ( ! is_array($row) OR trim((string) ($row['n'] ?? '')) === '')
                {
                    continue;
                }

                $card = [];

                foreach (['n' => 'full_name', 'i' => 'inn', 'p' => 'kpp', 'k' => 'kpp',
                          'o' => 'ogrn', 'a' => 'address', 'r' => 'reg_date'] as $src => $dst)
                {
                    $v = trim((string) ($row[$src] ?? ''));

                    if ($v !== '' AND trim((string) ($card[$dst] ?? '')) === '')
                    {
                        $card[$dst] = in_array($dst, ['full_name', 'address'], TRUE)
                            ? mb_substr($v, 0, 500)
                            : preg_replace('/\D/', '', $v);
                    }
                }

                if ( ! empty($card['ogrn']) AND self::ogrn_valid($card['ogrn']))
                {
                    $out[] = $card;
                }

                if (count($out) >= $max)
                {
                    return $out;
                }
            }

            if ($out)
            {
                return $out;
            }

            if (isset($j['status']) AND $j['status'] === 'wait')
            {
                usleep(600000);

                continue;
            }

            break;
        }

        return [];
    }

    /** Первая карточка ФНС по номеру (обёртка над fns_search) или NULL. */
    public static function fns_card($num, $force = FALSE)
    {
        $rows = self::fns_search($num, 1, $force);

        return $rows[0] ?? NULL;
    }

    /**
     * Обновить/создать карточку из ФНС по ОГРН или ИНН. [ok|error, ...].
     *
     * Мягкий лимит (NJ594_FNS_*): при исчерпании возвращаем ['limited' => TRUE]
     * и текст с советом подождать — вызывающий код показывает это человеку.
     * $force = TRUE обходит лимит ПО IP (кнопки администратора), но общий кап
     * платформы за час действует всегда.
     */
    public static function refresh($num, $force = FALSE)
    {
        $num = preg_replace('/\D/', '', (string) $num);

        if ( ! self::ogrn_valid($num) AND ! self::inn_valid($num))
        {
            return ['error' => 'ОГРН/ИНН с ошибкой (не сходится контрольная цифра)'];
        }

        $quota   = self::fns_quota();
        $allowed = $force ? $quota['global_ok'] : $quota['ok'];

        if ( ! $allowed)
        {
            self::fns_log($num, 'limited', 'blocked');

            return [
                'error'   => $force ? $quota['global_message'] : $quota['message'],
                'limited' => TRUE,
            ];
        }

        if ( ! $force AND self::_fns_recent_empty($num))
        {
            return [
                'error'   => 'По этому номеру ФНС недавно ничего не вернула — попробуйте примерно через '
                    .self::_fns_cooldown_text().'.',
                'limited' => TRUE,
            ];
        }

        $card = self::fns_card($num, TRUE);   // лимит уже проверен выше

        if ($card === NULL)
        {
            return ['error' => 'ФНС не вернула карточку по запросу '.$num.' (сервис временно недоступен или организация не найдена)'];
        }

        // по ИНН ОГРН берём из ответа ФНС; без валидного ОГРН сохранить нельзя
        $ogrn = self::ogrn_valid($num) ? $num : preg_replace('/\D/', '', (string) ($card['ogrn'] ?? ''));

        if ( ! self::ogrn_valid($ogrn))
        {
            return ['error' => 'ФНС не вернула ОГРН для ИНН '.$num.' — сохранение невозможно'];
        }

        $id = self::upsert($ogrn, $card, 'fns', ['src' => 'egrul.nalog.ru', 'card' => $card]);

        if ( ! $id)
        {
            return ['error' => 'Не удалось сохранить карточку'];
        }

        return ['ok' => TRUE, 'id' => $id, 'card' => self::by_ogrn($ogrn)];
    }

    // ------------------------------------------------------ лимит запросов в ФНС

    /**
     * Пороги запросов к ФНС: конфиг модуля (env NJ594_FNS_*) + значения по
     * умолчанию. Реестр не любит частых обращений — лимит мягкий, он не ломает
     * сервис, а просит подождать.
     */
    public static function fns_limits()
    {
        $cfg = (array) Kohana::$config->load('nj594_orgregistry.fns');

        return [
            'per_minute'     => max(1, (int) ($cfg['per_minute'] ?? 5)),
            'per_hour'       => max(1, (int) ($cfg['per_hour'] ?? 30)),
            'total_per_hour' => max(1, (int) ($cfg['total_per_hour'] ?? 120)),
            'cooldown_sec'   => max(0, (int) ($cfg['cooldown_sec'] ?? 120)),
            'timeout'        => max(3, (int) ($cfg['timeout'] ?? 12)),
        ];
    }

    /** Человекочитаемый лимит для интерфейса. */
    public static function fns_limit_text()
    {
        $l = self::fns_limits();

        return class_exists('Service_Nj100_Ratelimit')
            ? Service_Nj100_Ratelimit::limit_text($l['per_minute'], $l['per_hour'], 'запросов')
            : $l['per_minute'].' запросов в минуту, '.$l['per_hour'].' в час';
    }

    /**
     * Состояние лимита: можно ли спрашивать ФНС прямо сейчас.
     *
     * Разделены два ограничения: по IP (окна «минута/час») и общий кап платформы
     * за час — он нужен, чтобы «толпа» с разных адресов не перегрузила реестр.
     *
     * @param  string|null $ip IP клиента (по умолчанию — единый способ платформы)
     * @return array ok|ip_ok|global_ok|message|global_message|minute|hour|total|limits|retry_after
     */
    public static function fns_quota($ip = NULL)
    {
        self::ensure_tables();

        $l   = self::fns_limits();
        $ip  = ($ip !== NULL) ? (string) $ip : self::client_ip();
        $out = [
            'ok' => TRUE, 'ip_ok' => TRUE, 'global_ok' => TRUE,
            'message' => '', 'global_message' => '',
            'minute' => 0, 'hour' => 0, 'total' => 0,
            'limits' => $l, 'retry_after' => 0,
        ];

        try
        {
            // считаем ТОЛЬКО настоящие выходы в реестр: отказы по лимиту пишутся
            // с kind='blocked' и не должны сами себя продлевать
            $counts = class_exists('Service_Nj100_Ratelimit')
                ? Service_Nj100_Ratelimit::window_counts('nj_594_fns_calls', $ip, 'client_ip',
                    ['search', 'card', 'refresh'])
                : ['minute' => 0, 'hour' => 0];

            $out['minute'] = (int) ($counts['minute'] ?? 0);
            $out['hour']   = (int) ($counts['hour'] ?? 0);

            $row = self::_db()->query(Database::SELECT,
                "SELECT count(*) AS n FROM nj_594_fns_calls
                  WHERE created_at > now() - interval '1 hour' AND kind <> 'blocked'",
                FALSE)->current();

            $out['total'] = (int) ($row['n'] ?? 0);
        }
        catch (Throwable $e)
        {
            return $out;   // таблицы нет / БД прилегла — не блокируем сервис
        }

        $out['global_ok'] = ($out['total'] < $l['total_per_hour']);
        $out['ip_ok']     = ($out['minute'] < $l['per_minute'] AND $out['hour'] < $l['per_hour']);
        $out['ok']        = ($out['ip_ok'] AND $out['global_ok']);

        $out['global_message'] = 'Общий лимит запросов к ФНС на платформе исчерпан ('
            .$l['total_per_hour'].' в час) — реестр бережём от перегрузки, попробуйте позже.';

        if ( ! $out['ip_ok'])
        {
            $minute_block = ($out['minute'] >= $l['per_minute']);
            $out['retry_after'] = $minute_block ? 60 : 3600;
            $out['message'] = 'Запросов к ФНС сейчас слишком много (лимит: '.self::fns_limit_text().'). '
                .'Попробуйте через '.($minute_block ? 'минуту' : 'час')+' — или уточните название/номер.'
                    ;
        }
        elseif ( ! $out['global_ok'])
        {
            $out['retry_after'] = 3600;
            $out['message']    = $out['global_message'];
        }

        return $out;
    }

    /**
     * Записать выход в ФНС в журнал: лимит по окнам и телеметрия в админке.
     *
     * kind = search|card|refresh — настоящий выход в реестр (считается в лимитах),
     * kind = blocked — отказ по лимиту (виден в журнале, но лимит не продлевает).
     * Журнал вспомогательный: его сбой не должен ломать ответ пользователю.
     */
    public static function fns_log($query, $status, $kind = 'search', $ip = NULL)
    {
        try
        {
            $db = self::_db();

            $db->query(Database::UPDATE,
                'INSERT INTO nj_594_fns_calls (client_ip, query, kind, status) VALUES ('
                .$db->quote(mb_substr(($ip !== NULL ? (string) $ip : self::client_ip()), 0, 64)).', '
                .$db->quote(mb_substr(trim((string) $query), 0, 64)).', '
                .$db->quote(mb_substr((string) $kind, 0, 12)).', '
                .$db->quote(mb_substr((string) $status, 0, 12)).')', FALSE);
        }
        catch (Throwable $e)
        {
            // нет таблицы — работаем без журнала
        }
    }

    /** Сводка запросов к ФНС за N часов: сколько, с каким итогом. */
    public static function fns_stats($hours = 24)
    {
        self::ensure_tables();

        $out = ['total' => 0, 'ok' => 0, 'empty' => 0, 'limited' => 0, 'hour' => 0, 'hours' => (int) $hours];

        try
        {
            $row = self::_db()->query(Database::SELECT,
                "SELECT
                    count(*) FILTER (WHERE kind <> 'blocked')   AS total,
                    count(*) FILTER (WHERE status = 'ok')       AS ok_n,
                    count(*) FILTER (WHERE status = 'empty')    AS empty_n,
                    count(*) FILTER (WHERE kind = 'blocked')    AS limited_n,
                    count(*) FILTER (WHERE created_at > now() - interval '1 hour'
                                       AND kind <> 'blocked')   AS hour_n
                 FROM nj_594_fns_calls
                 WHERE created_at > now() - interval '".max(1, (int) $hours)." hours'", FALSE)->current();

            if ($row)
            {
                $out['total']   = (int) $row['total'];
                $out['ok']      = (int) $row['ok_n'];
                $out['empty']   = (int) $row['empty_n'];
                $out['limited'] = (int) $row['limited_n'];
                $out['hour']    = (int) $row['hour_n'];
            }
        }
        catch (Throwable $e)
        {
            // нет таблицы — нули
        }

        return $out;
    }

    /** Последние запросы к ФНС (журнал в админке). */
    public static function fns_recent($limit = 12)
    {
        self::ensure_tables();

        try
        {
            return self::_db()->query(Database::SELECT,
                'SELECT client_ip, query, kind, status, created_at FROM nj_594_fns_calls
                  ORDER BY id DESC LIMIT '.min(50, max(1, (int) $limit)), FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }
    }

    /**
     * Только что по этому же запросу ФНС ничего не нашла? Тогда не дёргаем
     * реестр повторно в течение cooldown_sec («мягкий» способ не злить ФНС).
     */
    protected static function _fns_recent_empty($query)
    {
        $l = self::fns_limits();

        if ($l['cooldown_sec'] <= 0)
        {
            return FALSE;
        }

        try
        {
            $row = self::_db()->query(Database::SELECT,
                "SELECT 1 FROM nj_594_fns_calls
                  WHERE query = ".self::_db()->quote(mb_substr(trim((string) $query), 0, 64))."
                    AND status = 'empty'
                    AND created_at > now() - interval '".(int) $l['cooldown_sec']." seconds'
                  LIMIT 1", FALSE)->current();

            return (bool) $row;
        }
        catch (Throwable $e)
        {
            return FALSE;
        }
    }

    /** Перерыв между одинаковыми запросами — человеческим языком. */
    protected static function _fns_cooldown_text()
    {
        $sec = (int) self::fns_limits()['cooldown_sec'];

        if ($sec < 60)
        {
            return $sec.' '.($sec === 1 ? 'секунду' : 'секунд');
        }

        if ($sec < 3600)
        {
            $m = (int) round($sec / 60);

            return $m.' '.($m === 1 ? 'минуту' : 'минут');
        }

        $h = (int) round($sec / 3600);

        return $h.' '.($h === 1 ? 'час' : 'часа');
    }

    /** IP клиента: единый способ платформы (X-Forwarded-For учитывается там). */
    public static function client_ip()
    {
        if (class_exists('Service_Nj100_Ratelimit'))
        {
            return Service_Nj100_Ratelimit::client_ip();
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** Связать артефакт модуля с организацией (idempotent). */
    public static function link($module, $item_code, $org_id, $title = '', $item_url = '', $kind = '')
    {
        self::ensure_tables();
        $org_id = (int) $org_id;

        if ($org_id <= 0 OR trim((string) $item_code) === '' OR trim((string) $module) === '')
        {
            return FALSE;
        }

        $db = self::_db();

        try
        {
            $db->query(Database::SELECT,
                'INSERT INTO nj_594_links (org_id, module, item_code, item_url, title, kind)
                 VALUES ('.$org_id.', '.$db->quote(mb_substr((string) $module, 0, 24)).',
                         '.$db->quote(mb_substr((string) $item_code, 0, 24)).',
                         '.$db->quote(mb_substr((string) $item_url, 0, 200)).',
                         '.$db->quote(mb_substr((string) $title, 0, 300)).',
                         '.$db->quote(mb_substr((string) $kind, 0, 40)).')
                 ON CONFLICT (org_id, module, item_code) DO UPDATE SET
                     title = EXCLUDED.title, item_url = EXCLUDED.item_url', FALSE);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj594: link: :e', [':e' => $e->getMessage()]);

            return FALSE;
        }

        return TRUE;
    }

    /** Организация по артефакту модуля (обратная связь) или NULL. */
    public static function org_for_item($module, $item_code)
    {
        self::ensure_tables();
        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'SELECT o.* FROM nj_594_links l JOIN nj_594_orgs o ON o.id = l.org_id
             WHERE l.module = '.$db->quote(mb_substr((string) $module, 0, 24)).'
               AND l.item_code = '.$db->quote(mb_substr((string) $item_code, 0, 24)).'
             LIMIT 1', FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Связанные материалы организации (для карточки, новые сверху). */
    public static function links_for_org($org_id, $limit = 50)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_594_links WHERE org_id = '.(int) $org_id.'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Человеческое имя модуля-владельца связи. */
    public static function module_label($module)
    {
        $map = [
            'nj580' => 'Пакет документов (nj580)',
            'nj591' => 'Профиль компании (nj591)',
            'nj540' => 'Отзыв (nj540)',
            'nj550' => 'Вакансия (nj550)',
            'nj592' => 'Письмо (nj592)',
        ];

        return $map[(string) $module] ?? ('Модуль '.$module);
    }

    /** Иконка вида артефакта. */
    public static function kind_icon($kind)
    {
        $map = [
            'docs'    => 'fas fa-file-lines',
            'profile' => 'fas fa-building',
            'review'  => 'fas fa-star',
            'job'     => 'fas fa-briefcase',
            'email'   => 'fas fa-envelope',
        ];

        return $map[(string) $kind] ?? 'fas fa-circle-dot';
    }

    /** Список всех карточек (админка). */
    public static function all($limit = 200)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT o.*, (SELECT count(*) FROM nj_594_links l WHERE l.org_id = o.id) AS links
             FROM nj_594_orgs o ORDER BY o.updated_at DESC, o.id LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Счётчики для KPI. */
    public static function counts()
    {
        self::ensure_tables();

        $db = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT count(*) AS total,
                    count(*) FILTER (WHERE source = \'fns\') AS fns,
                    count(*) FILTER (WHERE form = \'ИП\') AS ie
             FROM nj_594_orgs', FALSE)->current();

        $l = $db->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_594_links', FALSE)->get('c');

        return [
            'total' => (int) ($row['total'] ?? 0),
            'fns'   => (int) ($row['fns'] ?? 0),
            'ie'    => (int) ($row['ie'] ?? 0),
            'links' => (int) $l,
        ];
    }

    /** Полный слаг карточки для UI/API (без raw). */
    public static function card(array $o)
    {
        return [
            'ogrn'       => (string) $o['ogrn'],
            'inn'        => (string) $o['inn'],
            'kpp'        => (string) $o['kpp'],
            'form'       => (string) $o['form'],
            'short_name' => (string) $o['short_name'],
            'full_name'  => (string) $o['full_name'],
            'address'    => (string) $o['address'],
            'okved'      => (string) $o['okved'],
            'okved_name' => (string) $o['okved_name'],
            'status'     => (string) $o['status'],
            'reg_date'   => (string) $o['reg_date'],
            'director'   => (string) $o['director'],
            'source'     => (string) $o['source'],
            'fetched_at' => (string) ($o['fetched_at'] ?? ''),
            'url'        => '/orgs/'.$o['ogrn'],
        ];
    }

    /** MCP: nj594_org_search — поиск организаций по строке/ОГРН/ИНН. */
    public static function mcp_search(array $args)
    {
        $q     = trim((string) ($args['q'] ?? ''));
        $limit = min(20, max(1, (int) ($args['limit'] ?? 10)));

        if ($q === '')
        {
            return ['error' => 'Пустой поисковый запрос'];
        }

        $out = [];

        foreach (self::search($q, $limit) as $o)
        {
            $out[] = [
                'ogrn'       => (string) $o['ogrn'],
                'inn'        => (string) $o['inn'],
                'name'       => (string) ($o['short_name'] ?: $o['full_name']),
                'address'    => (string) $o['address'],
                'status'     => (string) $o['status'],
                'url'        => '/orgs/'.$o['ogrn'],
            ];
        }

        return ['query' => $q, 'count' => count($out), 'orgs' => $out];
    }

    /** MCP: nj594_org_card — карточка организации по ОГРН/ИНН. */
    public static function mcp_card(array $args)
    {
        $q = trim((string) ($args['ogrn'] ?? ($args['q'] ?? '')));

        if ($q === '')
        {
            return ['error' => 'Укажите ОГРН/ОГРНИП или ИНН'];
        }

        $t = self::_detect($q);

        $o = ($t['type'] === 'ogrn')
            ? self::by_ogrn($t['num'])
            : (($t['type'] === 'inn')
                ? (self::search($q, 1)[0] ?? NULL)
                : NULL);

        if ($o === NULL)
        {
            return ['error' => 'Карточка не найдена в справочнике (попробуйте nj594_org_search)'];
        }

        $card                = self::card($o);
        $card['links_count'] = count(self::links_for_org((int) $o['id'], 100));

        return $card;
    }
}
