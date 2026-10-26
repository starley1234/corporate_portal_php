<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj607_industry: онтология карты отрасли.
 *
 * Владелец (26.09.2026): «отчёт хороший — его оставь, но и сделай из него
 * сущности, которые надо будет связать с другими модулями. Наша цель — связанная
 * онтология».
 *
 * Что здесь происходит:
 *   1. из готового Markdown-отчёта извлекаются СУЩНОСТИ (без выдумок — только
 *      то, что написано в отчёте): компании-игроки с сайтами, регионы, сегменты
 *      и продукты;
 *   2. сущность хранится ОДНОЙ записью на `kind + slug` — сколько бы отчётов её
 *      ни упомянули (это и есть онтология: «одна компания — одна запись»); какой
 *      отчёт её упомянул, помнит `nj_607_mentions` (миграция 003). Раньше связь
 *      «отчёт → сущность» держалась на `entities.report_id`, и отчёт, где
 *      компании уже были известны из другого отчёта, оставался без блока
 *      «Сущности отчёта» — со стороны это выглядело как «онтология пропала»;
 *   3. для компании ищутся РЕАЛЬНЫЕ объекты в нашей базе (профиль компании,
 *      организация, досье) — и связь сохраняется в `nj_607_links`; выдуманных
 *      ссылок не бывает: не нашли — связи нет;
 *   4. подсказки формы (`popular()`) берутся из нашей же базы, а не из
 *      автозаполнения браузера (владелец: «не используй автозаполнение браузером»).
 *
 * Ничего не блокирует: любая проблема с разбором/связями только пишется в журнал —
 * отчёт от этого не страдает.
 */
class Service_Nj607_Ontology {

    /** Виды сущностей (онтология модуля). */
    public static function kinds()
    {
        return [
            'company' => ['label' => 'Компании',     'one' => 'Компания',     'icon' => 'fas fa-industry'],
            'org'     => ['label' => 'Организации',  'one' => 'Организация',  'icon' => 'fas fa-building-columns'],
            'region'  => ['label' => 'Регионы',      'one' => 'Регион',       'icon' => 'fas fa-location-dot'],
            'segment' => ['label' => 'Сегменты',     'one' => 'Сегмент',      'icon' => 'fas fa-layer-group'],
        ];
    }

    public static function ensure_tables()
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        $done = TRUE;

        try
        {
            DB::query(NULL, 'SELECT 1 FROM "nj_607_entities" LIMIT 1')->execute();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj607: онтология — нет таблиц (миграция 002): :e',
                [':e' => $e->getMessage()]);
        }

        try
        {
            DB::query(NULL, 'SELECT 1 FROM "nj_607_mentions" LIMIT 1')->execute();
        }
        catch (Throwable $e)
        {
            // До миграции 003 упоминаний нет — чтение отчёта работает по
            // legacy-полю entities.report_id (см. entities_of). Это ожидаемое
            // состояние до применения миграций, поэтому INFO, а не WARNING.
            Kohana::$log->add(Log::INFO, 'nj607: онтология — нет таблицы упоминаний (миграция 003): :e',
                [':e' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // Разбор отчёта
    // ------------------------------------------------------------------

    /**
     * Извлечь сущности из Markdown-отчёта (без обращения к базе).
     *
     * @return array список [kind, title, url, domain, details, meta]
     */
    public static function extract($industry, $region, $md)
    {
        $out   = [];
        $names = [];

        foreach (self::_companies((string) $md) as $c)
        {
            $out[]              = $c;
            $names[self::slug((string) $c['title'])] = TRUE;
        }

        // Страховка: если разбор по разделу «Кто на рынке» дал меньше двух
        // компаний, отчёт мог назвать игроков иначе («Участники рынка»,
        // «Поставщики», таблица без заголовка). Тогда берём компании по всему
        // тексту: строки со ссылкой/доменом и жирным названием. Без выдумок —
        // только то, что реально написано в отчёте.
        if (count($out) < 2)
        {
            foreach (self::_companies_loose((string) $md) as $c)
            {
                $slug = self::slug((string) $c['title']);

                if ($slug === '' OR isset($names[$slug]))
                {
                    continue;
                }

                $names[$slug] = TRUE;
                $out[]        = $c;
            }
        }

        $segments = self::_segments((string) $md);

        // В отчёте всегда есть предмет разговора: если сегментов не выделилось,
        // берём саму отрасль — иначе у готового отчёта онтология была бы пуста.
        if (empty($segments) AND trim((string) $industry) !== '')
        {
            $segments[] = mb_substr(trim((string) $industry), 0, 120);
        }

        foreach ($segments as $s)
        {
            $out[] = [
                'kind'    => 'segment',
                'title'   => $s,
                'url'     => '',
                'domain'  => '',
                'details' => '',
                'meta'    => [],
            ];
        }

        // Регион отчёта — это тоже сущность: по ней связываются другие отчёты,
        // радары и потоки (регион + отрасль уже работают в nj605/nj606).
        $regions = [];

        if (trim((string) $region) !== '')
        {
            $regions[] = trim((string) $region);
        }

        foreach (self::_regions_in((string) $industry.' '.(string) $md) as $r)
        {
            $regions[] = $r;
        }

        foreach (array_values(array_unique($regions)) as $r)
        {
            $out[] = [
                'kind'    => 'region',
                'title'   => $r,
                'url'     => '',
                'domain'  => '',
                'details' => '',
                'meta'    => [],
            ];
        }

        return $out;
    }

    /** Игроки рынка: «## Кто на рынке …» — название, сайт, чем занимаются. */
    protected static function _companies($md)
    {
        $lines = preg_split('~\r?\n~', $md);
        $in    = FALSE;
        $out   = [];

        foreach ($lines as $ln)
        {
            $t = trim($ln);

            if (preg_match('~^#{1,3}\s+(.+)$~u', $t, $m))
            {
                $in = (bool) preg_match('~кто на рынке|игроки|производител~ui', $m[1]);

                continue;
            }

            if ( ! $in OR $t === '')
            {
                continue;
            }

            // списки и таблицы отчёта: «- **Имя** — сайт — описание» / «| Имя | сайт |»
            $t = preg_replace('~^\s*[-*+]\s+~u', '', $t);
            $t = preg_replace('~^\s*\d+[.)]\s+~u', '', $t);

            if (preg_match('~^\|~', $t))
            {
                $cells = array_values(array_filter(array_map('trim', explode('|', trim($t, '|'))), 'strlen'));

                if ( ! $cells OR preg_match('~^-+$~', str_replace(' ', '', $cells[0])))
                {
                    continue;
                }

                $line  = implode(' — ', array_map(function ($c) {
                    return trim(strip_tags($c), " \t*`");
                }, $cells));
            }
            else
            {
                $line = $t;
            }

            if (mb_stripos($line, 'нет данных') !== FALSE)
            {
                continue;
            }

            // URL: markdown-ссылка или «голый» домен
            $url = '';

            if (preg_match('~\[([^\]]+)\]\((https?://[^)\s]+)\)~u', $line, $m))
            {
                $url  = $m[2];
                $line = str_replace($m[0], $m[1], $line);
            }
            elseif (preg_match('~((?:https?://)?(?:[a-z0-9-]+\.)+(?:ru|com|net|org|io|su|biz|pro|tech|online|site)(?:/[^\s,;)]*)?)~i', $line, $m))
            {
                $url = $m[1];
            }

            $clean = trim(preg_replace('~\*\*|__|`~u', '', $line));

            if ($url !== '')
            {
                $clean = trim(str_replace([$url, 'http://', 'https://'], '', $clean), " \t—–-·.,;()");
            }

            // название — до первого разделителя (тире/двоеточие/скобка)
            $name = preg_split('~\s+[—–:]\s+|\s+-\s+|\(|,\s~u', $clean)[0];
            $name = trim((string) $name, " \t*`—–-·.,;");

            if (mb_strlen($name) < 3 OR mb_strlen($name) > 200)
            {
                continue;
            }

            // «описание» — хвост строки без имени и адреса
            $details = trim(mb_substr(trim(str_replace($name, '', $clean), " \t—–-·.,;"), 0, 600));

            $out[] = [
                'kind'    => 'company',
                'title'   => $name,
                'url'     => $url,
                'domain'  => self::domain_of($url),
                'details' => $details,
                'meta'    => [],
            ];

            if (count($out) >= 30)
            {
                break;
            }
        }

        // Дубли внутри отчёта не нужны: онтология — одна запись на сущность
        $seen = [];
        $uniq = [];

        foreach ($out as $c)
        {
            $key = self::slug($c['title']);

            if ($key === '' OR isset($seen[$key]))
            {
                continue;
            }

            $seen[$key] = TRUE;
            $uniq[]     = $c;
        }

        return $uniq;
    }

    /**
     * Разбор «по всему тексту» — страховка для отчётов, где игроки названы не в
     * разделе «Кто на рынке» (другой заголовок, таблица без шапки). Берём только
     * строки с реальными признаками компании: markdown-ссылкой/доменом или
     * жирным названием в списке. Ничего не выдумываем.
     */
    protected static function _companies_loose($md)
    {
        $out  = [];
        $seen = [];

        foreach (preg_split('~\r?\n~', (string) $md) as $ln)
        {
            $t = trim((string) $ln);

            if ($t === '' OR mb_strlen($t) > 400 OR preg_match('~^#{1,6}\s~u', $t))
            {
                continue;
            }

            // табличная строка: «| Название | сайт | чем занимаются |»
            if (preg_match('~^\|~', $t))
            {
                $cells = array_values(array_filter(array_map('trim', explode('|', trim($t, '|'))), 'strlen'));

                if (empty($cells) OR preg_match('~^-+$~', str_replace(' ', '', $cells[0])))
                {
                    continue;
                }

                $t = implode(' — ', array_map(function ($c) {
                    return trim(strip_tags((string) $c), " \t*`");
                }, $cells));
            }

            $t = preg_replace('~^\s*[-*+]\s+~u', '', $t);
            $t = preg_replace('~^\s*\d+[.)]\s+~u', '', $t);

            $url = self::_url_in($t);

            $bold = preg_match('~^\*\*[^*]{3,}\*\*~u', $t);

            if ($url === '' AND ! $bold)
            {
                continue;
            }

            $clean = trim(preg_replace('~\*\*|__|`~u', '', $t));

            if ($url !== '')
            {
                $clean = str_replace([$url, 'http://', 'https://'], ' ', $clean);
            }

            if (mb_stripos($clean, 'нет данных') !== FALSE)
            {
                continue;
            }

            // название — до первого разделителя (тире/двоеточие/скобка/запятая)
            $name = preg_split('~\s+[—–:]\s+|\s+-\s+\(|,|;|\|~u', $clean)[0];
            $name = trim((string) $name, " \t*`—–-·.,;:");

            if (mb_strlen($name) < 3 OR mb_strlen($name) > 200)
            {
                continue;
            }

            // слишком «общие» строки (заголовки таблиц, служебные слова) — мимо
            if (preg_match('~^(компан|назван|сайт|url|описан|регион|сегмент|продукт|итог|вывод|источник)~ui', $name))
            {
                continue;
            }

            if (mb_strpos($name, ' — ') !== FALSE OR substr_count($name, ' ') > 8)
            {
                continue;
            }

            $slug = self::slug($name);

            if ($slug === '' OR isset($seen[$slug]))
            {
                continue;
            }

            $seen[$slug] = TRUE;

            $out[] = [
                'kind'    => 'company',
                'title'   => $name,
                'url'     => $url,
                'domain'  => self::domain_of($url),
                'details' => trim(mb_substr(trim(str_replace($name, '', $clean), " \t—–-·.,;"), 0, 600)),
                'meta'    => ['source' => 'loose'],
            ];

            if (count($out) >= 20)
            {
                break;
            }
        }

        return $out;
    }

    /** Ссылка/домен в строке (markdown-ссылка или «голый» домен) — или пусто. */
    protected static function _url_in($line)
    {
        if (preg_match('~\[([^\]]+)\]\((https?://[^)\s]+)\)~u', (string) $line, $m))
        {
            return (string) $m[2];
        }

        if (preg_match('~((?:https?://)?(?:[a-z0-9-]+\.)+(?:ru|com|net|org|io|su|biz|pro|tech|online|site)(?:/[^\s,;)]*)?)~i', (string) $line, $m))
        {
            return (string) $m[1];
        }

        return '';
    }

    /** Сегменты/продукты: строки разделов «Сегменты», «Что и как производят», «Возможности». */
    protected static function _segments($md)
    {
        $lines = preg_split('~\r?\n~', $md);
        $in    = FALSE;
        $out   = [];

        foreach ($lines as $ln)
        {
            $t = trim($ln);

            if (preg_match('~^#{1,3}\s+(.+)$~u', $t, $m))
            {
                $in = (bool) preg_match('~сегмент|что и как производят|возможности входа|продукт~ui', $m[1]);

                continue;
            }

            if ( ! $in OR $t === '' OR ! preg_match('~^\s*([-*+]|\d+[.)])\s+~u', $t))
            {
                continue;
            }

            $t = trim(preg_replace('~^\s*([-*+]|\d+[.)])\s+~u', '', $t));
            $t = trim(preg_replace('~\*\*|__|`~u', '', $t));

            if (mb_stripos($t, 'нет данных') !== FALSE)
            {
                continue;
            }

            $head = preg_split('~\s+[—–:]\s+|\s+-\s+~u', $t)[0];
            $head = trim((string) $head, " \t*`—–-·.,;");

            if (mb_strlen($head) < 4 OR mb_strlen($head) > 80)
            {
                continue;
            }

            $out[] = $head;

            if (count($out) >= 20)
            {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    /** Регионы России, реально упомянутые в тексте (по узнаваемым основам). */
    protected static function _regions_in($text)
    {
        static $stems = [
            'Москва', 'Московская', 'Санкт-Петербург', 'Ленинградская', 'Татарстан',
            'Башкортостан', 'Свердловская', 'Челябинская', 'Новосибирская', 'Нижегородская',
            'Самарская', 'Ростовская', 'Краснодарский', 'Пермский', 'Воронежская',
            'Волгоградская', 'Саратовская', 'Тюменская', 'Иркутская', 'Кемеровская',
            'Омская', 'Оренбургская', 'Тульская', 'Ярославская', 'Владимирская',
            'Калужская', 'Рязанская', 'Тверская', 'Ульяновская', 'Пензенская',
            'Кировская', 'Удмуртия', 'Чувашия', 'Мордовия', 'Ставропольский',
            'Алтайский', 'Красноярский', 'Приморский', 'Хабаровский', 'Архангельская',
            'Вологодская', 'Мурманская', 'Калининградская', 'Смоленская', 'Брянская',
            'Липецкая', 'Белгородская', 'Курская', 'Тамбовская', 'Астраханская',
            'Дагестан', 'Крым', 'Севастополь', 'Томская', 'Курганская',
        ];

        $found = [];

        foreach ($stems as $s)
        {
            if (mb_stripos($text, $s) !== FALSE)
            {
                $found[] = $s;

                if (count($found) >= 12)
                {
                    break;
                }
            }
        }

        return $found;
    }

    // ------------------------------------------------------------------
    // Сохранение и связи
    // ------------------------------------------------------------------

    /**
     * Полный проход: разобрать отчёт, сохранить сущности, найти связи.
     * Идемпотентно: повторный вызов ничего не дублирует (upsert по kind+slug).
     *
     * @return array {entities: int, links: int}
     */
    public static function sync_report($report_id, $code, $industry, $region, $md, $with_links = TRUE)
    {
        self::ensure_tables();

        $report_id = (int) $report_id;

        if ($report_id <= 0 OR trim((string) $md) === '')
        {
            return ['entities' => 0, 'links' => 0];
        }

        $entities = 0;
        $links    = 0;

        try
        {
            foreach (self::extract($industry, $region, $md) as $e)
            {
                $id = self::save_entity($report_id, $e);

                if ($id <= 0)
                {
                    continue;
                }

                $entities++;

                if ($with_links)
                {
                    $links += self::match_links($id, $e);
                }
            }
        }
        catch (Throwable $ex)
        {
            Kohana::$log->add(Log::WARNING, 'nj607: онтология отчёта :c: :e',
                [':c' => (string) $code, ':e' => $ex->getMessage()]);

            return ['entities' => $entities, 'links' => $links];
        }

        return ['entities' => $entities, 'links' => $links];
    }

    /** Одна запись на kind+slug: при повторе обновляем то, что стало точнее. */
    protected static function save_entity($report_id, array $e)
    {
        $slug = self::slug((string) ($e['title'] ?? ''));

        if ($slug === '')
        {
            return 0;
        }

        $kind = in_array((string) ($e['kind'] ?? ''), array_keys(self::kinds()), TRUE)
            ? (string) $e['kind'] : 'company';

        $db = Database::instance();

        $db->query(Database::INSERT,
            'INSERT INTO nj_607_entities (report_id, kind, slug, title, url, domain, details, meta)
             VALUES ('.(int) $report_id.', '.$db->quote($kind).', '.$db->quote($slug).', '
            .$db->quote(mb_substr((string) $e['title'], 0, 300)).', '
            .$db->quote(mb_substr((string) ($e['url'] ?? ''), 0, 500)).', '
            .$db->quote(mb_substr((string) ($e['domain'] ?? ''), 0, 120)).', '
            .$db->quote(mb_substr((string) ($e['details'] ?? ''), 0, 2000)).', '
            .$db->quote(json_encode((array) ($e['meta'] ?? []), JSON_UNESCAPED_UNICODE)).'::jsonb)
             ON CONFLICT (kind, slug) DO UPDATE SET
                url        = CASE WHEN nj_607_entities.url    = \'\' THEN EXCLUDED.url    ELSE nj_607_entities.url    END,
                domain     = CASE WHEN nj_607_entities.domain = \'\' THEN EXCLUDED.domain ELSE nj_607_entities.domain END,
                details    = CASE WHEN nj_607_entities.details IS NULL THEN EXCLUDED.details ELSE nj_607_entities.details END,
                updated_at = now()', FALSE);

        // id берём отдельным SELECT-ом: INSERT с ON CONFLICT DO UPDATE не всегда
        // возвращает строку результата через драйвер.
        $row = $db->query(Database::SELECT,
            'SELECT id FROM nj_607_entities WHERE kind = '.$db->quote($kind)
            .' AND slug = '.$db->quote($slug), FALSE)->current();

        $id = $row ? (int) $row['id'] : 0;

        if ($id > 0)
        {
            // Реквизиты — в свои колонки (они есть с миграции 002): по ИНН/ОГРН
            // связь с организацией точнее, чем по названию. Пустое не затираем.
            $meta = (array) ($e['meta'] ?? []);
            $db->query(Database::UPDATE,
                'UPDATE nj_607_entities SET
                    region = COALESCE(NULLIF(region, \'\'), '.$db->quote(mb_substr((string) ($e['region'] ?? ''), 0, 120)).'),
                    inn    = COALESCE(NULLIF(inn, \'\'),    '.$db->quote(mb_substr((string) ($meta['inn'] ?? ''), 0, 12)).'),
                    ogrn   = COALESCE(NULLIF(ogrn, \'\'),   '.$db->quote(mb_substr((string) ($meta['ogrn'] ?? ''), 0, 15)).')
                  WHERE id = '.$id, FALSE);
        }

        if ($id > 0)
        {
            // «В каких отчётах встречается»: сущность одна на всю онтологию,
            // поэтому принадлежность отчёту храним упоминанием, а не полем
            // report_id (см. миграцию 003).
            self::add_mention($id, (int) $report_id);
        }

        return $id;
    }

    /** Отметить, что отчёт упомянул сущность (идемпотентно). */
    public static function add_mention($entity_id, $report_id)
    {
        $entity_id = (int) $entity_id;
        $report_id = (int) $report_id;

        if ($entity_id <= 0 OR $report_id <= 0)
        {
            return FALSE;
        }

        try
        {
            Database::instance()->query(Database::INSERT,
                'INSERT INTO nj_607_mentions (entity_id, report_id)
                 VALUES ('.$entity_id.', '.$report_id.')
                 ON CONFLICT (entity_id, report_id) DO NOTHING', FALSE);

            return TRUE;
        }
        catch (Throwable $e)
        {
            // до миграции 003 таблицы нет — не повод падать
            return FALSE;
        }
    }

    /**
     * Связи сущности с объектами ДРУГИХ модулей — только реально найденные.
     * Ничего не выдумываем: нет совпадения — нет связи.
     */
    protected static function match_links($entity_id, array $e)
    {
        $links = [];
        $db    = Database::instance();

        if (in_array((string) $e['kind'], ['company', 'org'], TRUE))
        {
            // 0. Организация по ИНН — точнее названия (ИНН приносят радар и досье)
            $inn = preg_replace('/\D/', '', (string) ($e['inn'] ?? ''));

            if ($inn !== '')
            {
                try
                {
                    $rows = $db->query(Database::SELECT,
                        'SELECT ogrn, short_name FROM nj_594_orgs WHERE inn = '.$db->quote($inn)
                        .' LIMIT 3', FALSE)->as_array();

                    foreach ($rows as $r)
                    {
                        $links[] = ['org', (string) $r['ogrn'], '/orgs/'.rawurlencode((string) $r['ogrn']),
                                    'Организация «'.(string) $r['short_name'].'»', 96];
                    }
                }
                catch (Throwable $ex)
                {
                    // нет таблицы — пропускаем
                }
            }

            // 1. Профиль компании по домену — самое точное совпадение
            if (($e['domain'] ?? '') !== '')
            {
                try
                {
                    $rows = $db->query(Database::SELECT,
                        'SELECT code, company FROM nj_591_profiles
                         WHERE lower(domain) = '.$db->quote(mb_strtolower((string) $e['domain']))
                        .' LIMIT 3', FALSE)->as_array();

                    foreach ($rows as $r)
                    {
                        $links[] = ['profile', (string) $r['code'], '/company/'.rawurlencode((string) $r['code']),
                                    'Профиль компании «'.(string) $r['company'].'»', 95];
                    }
                }
                catch (Throwable $ex)
                {
                    // таблицы может не быть — это не ошибка онтологии
                }
            }

            // 2. Организации (nj_594_orgs) по названию
            try
            {
                $rows = $db->query(Database::SELECT,
                    'SELECT ogrn, short_name FROM nj_594_orgs
                     WHERE short_name ILIKE '.$db->quote('%'.self::_like($e['title']).'%')
                    .' OR full_name ILIKE '.$db->quote('%'.self::_like($e['title']).'%')
                    .' ORDER BY updated_at DESC LIMIT 3', FALSE)->as_array();

                $self_ogrn = preg_replace('/\D/', '', (string) ($e['ogrn'] ?? ''));

                foreach ($rows as $r)
                {
                    // собственную карточку в связях не дублируем
                    if ($self_ogrn !== '' AND (string) $r['ogrn'] === $self_ogrn)
                    {
                        continue;
                    }

                    $links[] = ['org', (string) $r['ogrn'], '/orgs/'.rawurlencode((string) $r['ogrn']),
                                'Организация «'.(string) $r['short_name'].'»', 80];
                }
            }
            catch (Throwable $ex)
            {
                // нет таблицы — пропускаем
            }

            // 3. Общий поиск по базе (профили, досье, документы) — как страховка
            if (class_exists('Service_Nj100_Search'))
            {
                try
                {
                    foreach (Service_Nj100_Search::search((string) $e['title'], NULL, 2,
                                ['profile', 'orgdocs', 'dossier', 'org']) as $r)
                    {
                        $url = (string) ($r['url'] ?? '');

                        if ($url === '' OR strpos($url, 'http') === 0)
                        {
                            continue;
                        }

                        $links[] = [(string) ($r['type'] ?? 'search'), '', $url,
                                    (string) ($r['kind'] ?? 'Найдено').': '.mb_substr((string) ($r['title'] ?? ''), 0, 120), 60];
                    }
                }
                catch (Throwable $ex)
                {
                    // поиск не должен ломать онтологию
                }
            }
        }
        else
        {
            // Регион/сегмент: связываем с отчётами по тому же слову и с регионами радаров
            try
            {
                $col = ($e['kind'] === 'region') ? 'region' : 'industry';

                $rows = $db->query(Database::SELECT,
                    'SELECT code, industry FROM nj_607_reports
                     WHERE '.$col.' ILIKE '.$db->quote('%'.self::_like($e['title']).'%')
                    ." AND status = 'done' ORDER BY id DESC LIMIT 4", FALSE)->as_array();

                foreach ($rows as $r)
                {
                    $links[] = ['industry', (string) $r['code'], '/industry/'.rawurlencode((string) $r['code']),
                                'Отчёт: '.(string) $r['industry'], 50];
                }
            }
            catch (Throwable $ex)
            {
                // ничем не рискуем
            }
        }

        $saved = 0;

        foreach ($links as $l)
        {
            list($module, $target_code, $target_url, $label, $score) = $l;

            if (self::add_link($entity_id, $module, $target_code, $target_url, $label, $score))
            {
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * Сохранить связь сущности с объектом модуля (идемпотентно, без выдумок:
     * связь создаётся только когда объект реально существует).
     */
    public static function add_link($entity_id, $module, $target_code, $target_url, $label, $score = 70)
    {
        $entity_id = (int) $entity_id;

        if ($entity_id <= 0 OR (string) $module === '')
        {
            return FALSE;
        }

        $db = Database::instance();

        try
        {
            $db->query(Database::INSERT,
                'INSERT INTO nj_607_links (entity_id, module, target_code, target_url, label, score)
                 VALUES ('.$entity_id.', '.$db->quote(mb_substr((string) $module, 0, 24)).', '
                .$db->quote(mb_substr((string) $target_code, 0, 64)).', '
                .$db->quote(mb_substr((string) $target_url, 0, 500)).', '
                .$db->quote(mb_substr((string) $label, 0, 200)).', '.(int) $score.')
                 ON CONFLICT (entity_id, module, target_code, target_url) DO NOTHING', FALSE);

            return TRUE;
        }
        catch (Throwable $e)
        {
            // дубли/гонки/нет таблиц — не повод падать вызывающему модулю
            return FALSE;
        }
    }

    /**
     * Зарегистрировать компанию/организацию в онтологии ИЗ ДРУГОГО МОДУЛЯ и
     * связать с объектом этого модуля.
     *
     * Зачем: онтология — общая точка сборки бизнес-модулей (профиль компании,
     * досье, поток заказчиков, письма, документы, справочник организаций). Модуль
     * отдаёт то, что у него реально есть (название, сайт, ИНН/ОГРН), получает
     * единую запись сущности и связь «сущность → объект модуля»; обратные связи с
     * профилем/организацией/досье добираются по нашей базе.
     *
     * Ничего не выдумываем: нет названия — нет сущности; нет объекта — нет связи.
     * Модуль обязан вызывать это в try/catch (онтология не ломает его работу).
     *
     * @param  string $title название компании/организации
     * @param  array  $opts  kind, url, domain, inn, ogrn, details, module, code, path, label, score, source
     * @return int id сущности (0 — не удалось)
     */
    public static function remember($title, array $opts = [])
    {
        $title = trim(mb_substr((string) $title, 0, 300));

        if (mb_strlen($title) < 3)
        {
            return 0;
        }

        $kinds = array_keys(self::kinds());
        $kind  = in_array((string) ($opts['kind'] ?? 'company'), $kinds, TRUE)
            ? (string) $opts['kind'] : 'company';

        $url    = trim((string) ($opts['url'] ?? ''));
        $domain = trim((string) ($opts['domain'] ?? '')) !== ''
            ? trim((string) $opts['domain']) : self::domain_of($url);

        self::ensure_tables();

        try
        {
            $id = self::save_entity(0, [
                'kind'    => $kind,
                'title'   => $title,
                'url'     => $url,
                'domain'  => $domain,
                'details' => (string) ($opts['details'] ?? ''),
                'meta'    => [
                    'inn'    => (string) ($opts['inn'] ?? ''),
                    'ogrn'   => (string) ($opts['ogrn'] ?? ''),
                    'source' => (string) ($opts['source'] ?? 'module'),
                ],
            ]);
        }
        catch (Throwable $e)
        {
            return 0;
        }

        if ($id <= 0)
        {
            return 0;
        }

        // связь с объектом модуля-источника (если он назвал себя)
        if ((string) ($opts['module'] ?? '') !== '')
        {
            self::add_link($id, (string) $opts['module'], (string) ($opts['code'] ?? ''),
                (string) ($opts['path'] ?? ''), (string) ($opts['label'] ?? 'Объект модуля'),
                (int) ($opts['score'] ?? 70));
        }

        // Обратные связи по нашей базе: профиль компании, организация, досье.
        // Массовые вызовы (поток заказчиков приносит до 30 компаний за раз)
        // передают match => FALSE: сущность и связь с модулем есть, а дорогой
        // поиск по базе для каждой строки не нужен — он догонит при открытии
        // страницы сущности или разборе отчёта.
        if ((bool) ($opts['match'] ?? TRUE))
        {
            try
            {
                self::match_links($id, [
                    'kind'   => $kind,
                    'title'  => $title,
                    'url'    => $url,
                    'domain' => $domain,
                    'inn'    => (string) ($opts['inn'] ?? ''),
                    'ogrn'   => (string) ($opts['ogrn'] ?? ''),
                ]);
            }
            catch (Throwable $e)
            {
                // не нашли — значит не нашли
            }
        }

        return $id;
    }

    /** Ссылка на страницу сущности по её названию ('' — если сущность не выделяется). */
    public static function entity_url($title, $kind = 'company')
    {
        $slug = self::slug((string) $title);

        if ($slug === '')
        {
            return '';
        }

        return '/industry/entity/'.rawurlencode($slug).'?kind='.rawurlencode((string) $kind);
    }

    /** Сколько связей у сущности по названию (для компактной ссылки в модулях). */
    public static function links_count_by_slug($slug, $kind = 'company')
    {
        $slug = trim((string) $slug);

        if ($slug === '')
        {
            return 0;
        }

        try
        {
            $row = Database::instance()->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_607_links l
                   JOIN nj_607_entities e ON e.id = l.entity_id
                  WHERE e.kind = '.Database::instance()->quote((string) $kind)
                .' AND e.slug = '.Database::instance()->quote($slug), FALSE)->current();

            return $row ? (int) $row['c'] : 0;
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    /** Сущности отчёта (для блока «Сущности отчёта» на странице карты). */
    public static function entities_of($report_id)
    {
        self::ensure_tables();

        $report_id = (int) $report_id;

        // Сущности ОБЩИЕ для онтологии, поэтому «сущности отчёта» — это те, что
        // отчёт УПОМЯНУЛ (nj_607_mentions, миграция 003), плюс legacy-случай
        // «первый отчёт сущности» (entities.report_id).
        try
        {
            return Database::instance()->query(Database::SELECT,
                'SELECT e.* FROM nj_607_entities e
                  WHERE e.report_id = '.$report_id.'
                     OR EXISTS (SELECT 1 FROM nj_607_mentions m
                                 WHERE m.entity_id = e.id AND m.report_id = '.$report_id.')
                  ORDER BY e.kind, e.id', FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            try
            {
                return Database::instance()->query(Database::SELECT,
                    'SELECT * FROM nj_607_entities WHERE report_id = '.$report_id
                    .' ORDER BY kind, id', FALSE)->as_array();
            }
            catch (Throwable $e2)
            {
                return [];
            }
        }
    }

    /**
     * Догнать упоминания у СТАРЫХ отчётов (кнопка в управлении картой отрасли).
     *
     * Зачем: миграция 003 переносит только те связи «отчёт → сущность», которые
     * знал старый код (entities.report_id, то есть ПЕРВЫЙ отчёт сущности).
     * Остальные отчёты, упоминающие те же компании, надо разобрать заново —
     * это разовая операция, поэтому она делается кнопкой и пачками, а не на
     * каждом просмотре страницы.
     *
     * @param  int  $limit      сколько отчётов разобрать за вызов
     * @param  bool $with_links искать ли связи с другими модулями (дороже)
     * @return array {done, left, note}
     */
    public static function backfill($limit = 5, $with_links = FALSE)
    {
        self::ensure_tables();

        $limit = max(1, min(20, (int) $limit));

        try
        {
            $rows = self::_reports_without_mentions($limit);
            $left = self::_mentions_left();
        }
        catch (Throwable $e)
        {
            return ['done' => 0, 'left' => 0,
                    'note' => 'Упоминания недоступны: примените миграцию 003 (Управление → миграции).'];
        }

        $done = 0;

        foreach ($rows as $r)
        {
            $res = self::sync_report((int) $r['id'], (string) $r['code'],
                (string) $r['industry'], (string) $r['region'], (string) $r['report_md'], (bool) $with_links);

            if ( ! isset($res['error']))
            {
                $done++;
            }
        }

        $left = max(0, $left - $done);

        return [
            'done' => $done,
            'left' => $left,
            'note' => $done > 0
                ? 'Разобрано отчётов: '.$done.($left > 0 ? ', осталось: '.$left : ' — это был последний')
                : ($left > 0 ? 'Ни один отчёт не разобран — смотрите лог' : 'Все отчёты уже разобраны'),
        ];
    }

    /** Отчёты с текстом, у которых ещё нет ни одного упоминания (пачка). */
    protected static function _reports_without_mentions($limit)
    {
        return Database::instance()->query(Database::SELECT,
            'SELECT r.id, r.code, r.industry, r.region, r.report_md
               FROM nj_607_reports r
              WHERE r.status = \'done\' AND coalesce(r.report_md, \'\') <> \'\'
                AND NOT EXISTS (SELECT 1 FROM nj_607_mentions m WHERE m.report_id = r.id)
              ORDER BY r.id ASC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Сколько отчётов ещё не разобрано. */
    protected static function _mentions_left()
    {
        $row = Database::instance()->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_607_reports r
              WHERE r.status = \'done\' AND coalesce(r.report_md, \'\') <> \'\'
                AND NOT EXISTS (SELECT 1 FROM nj_607_mentions m WHERE m.report_id = r.id)', FALSE)->current();

        return $row ? (int) $row['c'] : 0;
    }

    /** В каких отчётах встречается сущность (для страницы сущности). */
    public static function reports_of($entity_id)
    {
        $entity_id = (int) $entity_id;

        if ($entity_id <= 0)
        {
            return [];
        }

        try
        {
            return Database::instance()->query(Database::SELECT,
                'SELECT r.code, r.industry, r.region, r.status,
                        to_char(r.created_at, \'DD.MM.YYYY\') AS created_day
                   FROM nj_607_mentions m
                   JOIN nj_607_reports r ON r.id = m.report_id
                  WHERE m.entity_id = '.$entity_id.'
                  ORDER BY m.id DESC LIMIT 12', FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            // до миграции 003 — показываем хотя бы первый отчёт сущности
            try
            {
                return Database::instance()->query(Database::SELECT,
                    'SELECT r.code, r.industry, r.region, r.status,
                            to_char(r.created_at, \'DD.MM.YYYY\') AS created_day
                       FROM nj_607_entities e
                       JOIN nj_607_reports r ON r.id = e.report_id
                      WHERE e.id = '.$entity_id.' LIMIT 3', FALSE)->as_array();
            }
            catch (Throwable $e2)
            {
                return [];
            }
        }
    }

    /** Сколько связей у сущностей отчёта (счётчик в блоке «Онтология отчёта»). */
    public static function links_count_for(array $entity_ids)
    {
        $ids = [];

        foreach ($entity_ids as $id)
        {
            if ((int) $id > 0)
            {
                $ids[] = (int) $id;
            }
        }

        if (empty($ids))
        {
            return 0;
        }

        try
        {
            $row = Database::instance()->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_607_links WHERE entity_id IN ('.implode(',', $ids).')', FALSE)->current();

            return $row ? (int) $row['c'] : 0;
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    /**
     * Диагностика онтологии отчёта: почему блок пуст (для администратора).
     *
     * @return array {tables: bool, entities: int, links: int, mentions: int, reason: string}
     */
    public static function diagnose($report_id)
    {
        $report_id = (int) $report_id;
        $db        = Database::instance();

        $tables = TRUE;

        try
        {
            $db->query(Database::SELECT, 'SELECT 1 FROM nj_607_entities LIMIT 1', FALSE)->current();
        }
        catch (Throwable $e)
        {
            $tables = FALSE;
        }

        $mentions = 0;

        try
        {
            $row = $db->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_607_mentions WHERE report_id = '.$report_id, FALSE)->current();
            $mentions = $row ? (int) $row['c'] : 0;
        }
        catch (Throwable $e)
        {
            $mentions = -1; // таблицы упоминаний нет (миграция 003 не применена)
        }

        $entities = self::entities_of($report_id);
        $ids      = [];

        foreach ($entities as $e)
        {
            $ids[] = (int) $e['id'];
        }

        $reason = '';

        if ( ! $tables)
        {
            $reason = 'Таблицы онтологии не созданы — администратору нужно применить миграции (/migrate_pg).';
        }
        elseif (empty($entities))
        {
            $reason = 'Из отчёта не удалось выделить сущности: в тексте нет ни компаний с сайтами, ни региона. Нажмите «Разобрать отчёт заново» — разбор пройдёт по всему тексту.';
        }

        return [
            'tables'   => $tables,
            'entities' => count($entities),
            'links'    => self::links_count_for($ids),
            'mentions' => $mentions,
            'reason'   => $reason,
        ];
    }

    /** Есть ли у отчёта разобранные сущности. */
    public static function count_of($report_id)
    {
        self::ensure_tables();

        try
        {
            $row = Database::instance()->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_607_entities WHERE report_id = '.(int) $report_id, FALSE)->current();

            return $row ? (int) $row['c'] : 0;
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    public static function by_slug($slug, $kind = NULL)
    {
        self::ensure_tables();

        $slug = (string) $slug;
        $kind = trim((string) $kind);
        $where = 'slug = '.Database::instance()->quote($slug);

        // Вид сущности необязателен, но полезен: одно и то же название бывает и
        // компанией, и регионом («Москва») — модули передают kind в ссылке.
        if ($kind !== '' AND array_key_exists($kind, self::kinds()))
        {
            $where .= ' AND kind = '.Database::instance()->quote($kind);
        }

        try
        {
            $row = Database::instance()->query(Database::SELECT,
                'SELECT * FROM nj_607_entities WHERE '.$where.' ORDER BY id LIMIT 1', FALSE)->current();

            return $row ? (array) $row : NULL;
        }
        catch (Throwable $e)
        {
            return NULL;
        }
    }

    /** Все записи онтологии по этому slug (одна сущность — из разных отчётов). */
    public static function twins_of($kind, $slug)
    {
        try
        {
            return Database::instance()->query(Database::SELECT,
                'SELECT e.*, r.code AS report_code, r.industry, r.region AS report_region,
                        to_char(r.created_at, \'DD.MM.YYYY\') AS report_date
                 FROM nj_607_entities e
                 LEFT JOIN nj_607_reports r ON r.id = e.report_id
                 WHERE e.kind = '.Database::instance()->quote((string) $kind)
                .' AND e.slug = '.Database::instance()->quote((string) $slug)
                .' ORDER BY e.id DESC LIMIT 50', FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }
    }

    /**
     * Связи сущности с другими модулями, собранные по ВСЕМ её записям
     * (одна и та же компания из разных отчётов даёт разные найденные объекты).
     */
    public static function links_by_slug($kind, $slug)
    {
        self::ensure_tables();

        try
        {
            return Database::instance()->query(Database::SELECT,
                'SELECT l.* FROM nj_607_links l
                 JOIN nj_607_entities e ON e.id = l.entity_id
                 WHERE e.kind = '.Database::instance()->quote((string) $kind)
                .' AND e.slug = '.Database::instance()->quote((string) $slug)
                .' ORDER BY l.score DESC, l.id LIMIT 60', FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }
    }

    /**
     * Пересобрать связи сущности по нашей базе (кнопка на странице сущности).
     * Нужно, когда сущность зарегистрировал модуль с match => FALSE (поток
     * заказчиков) — связи догоняются по запросу, а не молча.
     *
     * @return int сколько связей добавлено
     */
    public static function refresh_links($slug, $kind = NULL)
    {
        $e = self::by_slug($slug, $kind);

        if ( ! $e)
        {
            return 0;
        }

        try
        {
            return self::match_links((int) $e['id'], [
                'kind'   => (string) $e['kind'],
                'title'  => (string) $e['title'],
                'url'    => (string) $e['url'],
                'domain' => (string) $e['domain'],
            ]);
        }
        catch (Throwable $ex)
        {
            return 0;
        }
    }

    /** Связи сущности с другими модулями. */
    public static function links_of($entity_id)
    {
        self::ensure_tables();

        try
        {
            return Database::instance()->query(Database::SELECT,
                'SELECT * FROM nj_607_links WHERE entity_id = '.(int) $entity_id
                .' ORDER BY score DESC, id LIMIT 40', FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }
    }

    /**
     * Подсказки для формы: отрасли и регионы ИЗ НАШЕЙ БАЗЫ (а не автозаполнение
     * браузера). Порядок — по частоте и свежести запросов.
     */
    public static function popular($limit = 8)
    {
        self::ensure_tables();

        $out = ['industry' => [], 'region' => []];

        try
        {
            $out['industry'] = Database::instance()->query(Database::SELECT,
                "SELECT industry AS v, count(*) AS c FROM nj_607_reports
                 WHERE industry <> '' GROUP BY industry
                 ORDER BY c DESC, max(created_at) DESC LIMIT ".(int) $limit, FALSE)->as_array();

            $out['region'] = Database::instance()->query(Database::SELECT,
                "SELECT region AS v, count(*) AS c FROM nj_607_reports
                 WHERE region <> '' GROUP BY region
                 ORDER BY c DESC, max(created_at) DESC LIMIT ".(int) $limit, FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            // пустая подсказка — не ошибка страницы
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Мелкие помощники
    // ------------------------------------------------------------------

    /** Ключ онтологии: «ООО «Ромашка» / romashka.ru» → «ооо-ромашка-romashka-ru». */
    public static function slug($s)
    {
        $s = mb_strtolower(trim((string) $s));
        $s = str_replace(['«', '»', '"', "'", '(', ')', ',', '.', '/', '\\'], ' ', $s);
        $s = preg_replace('~[^\p{L}\p{N}]+~u', '-', $s);
        $s = trim((string) $s, '-');

        return mb_substr($s, 0, 160);
    }

    /** Домен из URL («https://www.zavod.ru/about» → «zavod.ru»). */
    public static function domain_of($url)
    {
        $host = parse_url(trim((string) $url), PHP_URL_HOST);

        if ( ! $host)
        {
            $host = preg_replace('~^https?://~i', '', trim((string) $url));
            $host = preg_split('~/~', (string) $host)[0];
        }

        return mb_strtolower(preg_replace('~^www\.~i', '', (string) $host));
    }

    protected static function _like($s)
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], trim((string) $s));
    }
}
