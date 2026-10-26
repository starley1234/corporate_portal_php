<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Карточка компании» (nj635_scout): быстрая сводка по одной строке ввода —
 * название, ИНН/ОГРН, домен или ссылка на вакансию.
 *
 * Задача карточки — дать ответ СРАЗУ и бесплатно, без ожидания ИИ:
 *   * реквизиты и состояние — справочник организаций nj594 (ЕГРЮЛ/ЕГРИП);
 *   * вакансии компании      — nj642 (собранные с сайтов работодателей);
 *   * связанные материалы    — связи nj594 (досье, профили, письма, наблюдения);
 *   * сигналы и вывод        — правила по данным реестра, без модели.
 *
 * Безопасность и пределы:
 *   * по пользовательским ссылкам НИКУДА не ходим: адрес разбирается локально,
 *     вакансия ищется в своей базе, домен используется только как ключ;
 *   * сетевой вызов ровно один и только по явному номеру: если ИНН/ОГРН нет в
 *     справочнике — спрашиваем ФНС (Service_Nj594_Orgs::refresh) и сохраняем
 *     карточку, следующий запрос обслуживается локально;
 *   * ничего не пишем, кроме этой карточки ФНС (данные — собственность nj594).
 */
class Service_Nj635_Card
{
    /** Джоб-борды: ссылка ведёт на вакансию, а не на сайт работодателя. */
    protected static $_boards = [
        'hh.ru', 'superjob.ru', 'rabota.ru', 'zarplata.ru', 'gorodrabot.ru',
        'career.habr.com', 'habr.com', 'linkedin.com', 'dreamjob.ru', 'trudvsem.ru',
    ];

    /** Организационные формы, которые отбрасываем при сравнении названий. */
    protected static $_forms = [
        'ооо', 'оао', 'зао', 'пао', 'ао', 'ип', 'нко', 'анo', 'ано', 'гуп', 'муп',
        'фгуп', 'мбу', 'мкуп', 'спк', 'тсж', 'ск', 'кфх', 'лтд', 'инк',
    ];

    // -------------------------------------------------------------------------
    // Ввод
    // -------------------------------------------------------------------------

    /**
     * Разбор одной строки ввода: ИНН/ОГРН, ссылка на вакансию, домен, название.
     *
     * @return array ['kind' => inn|ogrn|vacancy|domain|name, ...] либо ['error']
     */
    public static function parse($raw)
    {
        $raw = trim((string) preg_replace('~\s+~u', ' ', (string) $raw));

        if ($raw === '')
        {
            return ['error' => 'Введите компанию, ИНН/ОГРН, домен сайта или ссылку на вакансию.'];
        }

        if (mb_strlen($raw) > 500)
        {
            return ['error' => 'Слишком длинный ввод — до 500 символов.'];
        }

        $out = [
            'kind'    => 'name',
            'query'   => mb_substr($raw, 0, 500),
            'company' => '',
            'domain'  => '',
            'inn'     => '',
            'ogrn'    => '',
            'url'     => '',
        ];

        // 1. ИНН/ОГРН: строка из цифр (возможны пробелы и дефисы).
        //    «ИНН 7707083893» / «ОГРН: 1027700132195» — срезаем подпись, но только
        //    если после неё ТОЛЬКО цифры (иначе «Инновации 2000» превратились бы
        //    в «овации 2000»).
        if (preg_match('~^\s*(?:инн|огрн|огрнип|кпп)\s*[:№#]?\s*([\d\s\-,]+)$~iu', $raw, $m))
        {
            $raw = trim($m[1]);
        }

        $digits = (string) preg_replace('~\D~', '', $raw);

        if ($digits !== '' AND preg_match('~^[\d\s\-,]+$~u', $raw))
        {
            $len = strlen($digits);

            if (($len === 13 OR $len === 15)
                AND class_exists('Service_Nj594_Orgs') AND Service_Nj594_Orgs::ogrn_valid($digits))
            {
                $out['kind'] = 'ogrn';
                $out['ogrn'] = $digits;

                return $out;
            }

            if (($len === 10 OR $len === 12)
                AND class_exists('Service_Nj594_Orgs') AND Service_Nj594_Orgs::inn_valid($digits))
            {
                $out['kind'] = 'inn';
                $out['inn']  = $digits;

                return $out;
            }

            return ['error' => 'Номер не похож на ИНН (10 или 12 цифр) или ОГРН (13 или 15 цифр) — проверьте цифры.'];
        }

        // 2. Ссылка: со схемой или короткий адрес сайта
        $scheme = (bool) preg_match('~^https?://~iu', $raw);

        if ($scheme OR preg_match('~^([a-z0-9-]+\.)+[a-z]{2,24}(/\S*)?$~iu', $raw))
        {
            $url  = $scheme ? $raw : 'http://'.$raw;
            $host = (string) parse_url($url, PHP_URL_HOST);
            $host = mb_strtolower((string) preg_replace('~^www\.~', '', $host));

            if ($host === '')
            {
                return ['error' => 'Не удалось разобрать адрес — укажите сайт компании или её название.'];
            }

            if (filter_var($host, FILTER_VALIDATE_IP) OR self::_local_host($host))
            {
                return ['error' => 'Это служебный или локальный адрес — укажите публичный сайт компании или её название.'];
            }

            if (mb_strpos($host, '.') === FALSE)
            {
                return ['error' => 'Адрес не похож на адрес сайта — укажите домен вида company.ru или название компании.'];
            }

            if (in_array($host, self::$_boards, TRUE))
            {
                $out['kind'] = 'vacancy';
                $out['url']  = mb_substr($url, 0, 600);

                return $out;
            }

            $out['kind']   = 'domain';
            $out['domain'] = $host;
            $out['url']    = mb_substr($url, 0, 600);

            return $out;
        }

        // чужая схема (ftp://, file://) — не наш случай
        if (mb_strpos($raw, '://') !== FALSE)
        {
            return ['error' => 'Поддерживаются только ссылки http/https — укажите сайт компании или её название.'];
        }

        // 3. Название компании
        if (mb_strlen($raw) < 3)
        {
            return ['error' => 'Слишком короткий запрос — минимум 3 символа.'];
        }

        $out['company'] = mb_substr($raw, 0, 300);

        return $out;
    }

    // -------------------------------------------------------------------------
    // Сборка карточки
    // -------------------------------------------------------------------------

    /**
     * Собрать карточку по строке ввода.
     *
     * @param  string $raw  что ввёл человек
     * @param  array  $opt  ['online' => FALSE] — не спрашивать ФНС
     * @return array        карточка либо ['error' => '…']
     */
    public static function build($raw, array $opt = [])
    {
        $online = ( ! array_key_exists('online', $opt) OR (bool) $opt['online']);

        $p = self::parse($raw);

        if (isset($p['error']))
        {
            return $p;
        }

        $card = [
            'ok'         => TRUE,
            'kind'       => (string) $p['kind'],
            'query'      => (string) $p['query'],
            'org'        => NULL,
            'candidates' => [],
            'vacancies'  => ['total' => 0, 'active' => 0, 'items' => []],
            'vacancy'    => NULL,
            'links'      => [],
            'signals'    => [],
            'verdict'    => NULL,
            'notes'      => [],
            'actions'    => [],
            'online'     => FALSE,
            'checked_at' => date('d.m.Y H:i'),
        ];

        $domain  = '';
        $company = '';
        $org_raw = NULL;

        // --- что именно проверяем -------------------------------------------
        if ($p['kind'] === 'vacancy')
        {
            $hit = class_exists('Service_Nj642_Vacancies')
                ? Service_Nj642_Vacancies::by_url((string) $p['url'])
                : NULL;

            if ($hit)
            {
                $card['vacancy'] = [
                    'title'   => (string) $hit['title'],
                    'company' => (string) $hit['company'],
                    'city'    => (string) $hit['city'],
                    'url'     => (string) $hit['url'],
                ];
                $company = (string) $hit['company'];

                $key = (string) $hit['company_key'];

                if (self::_looks_domain($key))
                {
                    $domain = $key;
                }

                $card['notes'][] = 'Вакансия найдена в базе собранных вакансий.';
            }
            else
            {
                $card['notes'][] = 'Этой вакансии нет в нашей базе, поэтому компанию из ссылки не определить. Введите название компании или адрес её сайта.';
            }
        }
        elseif ($p['kind'] === 'domain')
        {
            $domain = (string) $p['domain'];
        }
        elseif ($p['kind'] === 'name')
        {
            $company = (string) $p['company'];
        }

        // --- организация ----------------------------------------------------
        if ($p['kind'] === 'inn' OR $p['kind'] === 'ogrn')
        {
            $num = (string) ($p['kind'] === 'inn' ? $p['inn'] : $p['ogrn']);

            $org_raw = self::_org_by_number($num);

            if ( ! $org_raw AND $online AND class_exists('Service_Nj594_Orgs'))
            {
                $res = self::_refresh($num);
                $card['online'] = TRUE;

                if (isset($res['card']))
                {
                    $org_raw = (array) $res['card'];
                }
                elseif (isset($res['error']))
                {
                    $card['notes'][] = 'ФНС: '.$res['error'];
                    $card['fns']     = ['limited' => ! empty($res['limited']), 'message' => (string) $res['error']];
                }
            }
        }
        elseif ($company !== '')
        {
            $found = class_exists('Service_Nj594_Orgs') ? Service_Nj594_Orgs::search($company, 6) : [];

            $org_raw = self::_pick_org($found, $company);

            if ( ! $org_raw)
            {
                if (count($found) > 1)
                {
                    foreach ($found as $o)
                    {
                        $card['candidates'][] = [
                            'name' => (string) ($o['short_name'] !== '' ? $o['short_name'] : $o['full_name']),
                            'inn'  => (string) $o['inn'],
                            'ogrn' => (string) $o['ogrn'],
                            'url'  => '/check?q='.rawurlencode((string) $o['inn']),
                        ];
                    }
                }
                else
                {
                    $card['notes'][] = 'В справочнике нет организации с таким названием — уточните ИНН/ОГРН или проверьте написание.';
                }
            }
        }

        // --- вакансии -------------------------------------------------------
        if (class_exists('Service_Nj642_Vacancies'))
        {
            $keys = self::vacancy_keys($org_raw, $domain, $company);

            if ($keys)
            {
                $card['vacancies'] = Service_Nj642_Vacancies::for_company_keys($keys, 6);
            }

            // из ссылки на вакансию компания важнее, чем совпадение по названию
            if ($card['vacancy'] AND (int) $card['vacancies']['active'] === 0 AND $company !== '')
            {
                $again = Service_Nj642_Vacancies::for_company_keys([mb_strtolower($company)], 6);

                if ((int) $again['active'] > 0)
                {
                    $card['vacancies'] = $again;
                }
            }
        }

        // --- домен или ссылка: организация могла не найтись сразу — пробуем по
        //     названию компании из собранных вакансий («sberbank.ru» → «Сбербанк»)
        if ( ! $org_raw AND ! empty($card['vacancies']['items']) AND class_exists('Service_Nj594_Orgs'))
        {
            $name = trim((string) $card['vacancies']['items'][0]['company']);

            if (mb_strlen($name) >= 3)
            {
                $org_raw = self::_pick_org(Service_Nj594_Orgs::search($name, 6), $name);

                if ($org_raw)
                {
                    $card['notes'][] = 'Организация определена по названию компании из собранных вакансий.';

                    if (class_exists('Service_Nj642_Vacancies'))
                    {
                        $more = Service_Nj642_Vacancies::for_company_keys(
                            self::vacancy_keys($org_raw, $domain, $name), 6);

                        if ((int) $more['active'] > (int) $card['vacancies']['active'])
                        {
                            $card['vacancies'] = $more;
                        }
                    }
                }
            }
        }

        if ( ! $org_raw AND $p['kind'] === 'domain')
        {
            $card['notes'][] = 'По домену организация не определена — добавьте название или ИНН, если нужны реквизиты ЕГРЮЛ.';
        }

        // --- связанные материалы и выводы -----------------------------------
        if ($org_raw AND (int) ($org_raw['id'] ?? 0) > 0 AND class_exists('Service_Nj594_Orgs'))
        {
            $card['links'] = Service_Nj594_Orgs::links_for_org((int) $org_raw['id'], 8);
        }

        $card['signals'] = self::signals($org_raw, $card['vacancies']);

        $card['org']     = $org_raw ? self::_org_card($org_raw) : NULL;
        $card['verdict'] = self::verdict($card['org'], $card['signals']);
        $card['actions'] = self::_actions($card);

        return $card;
    }

    // -------------------------------------------------------------------------
    // Сигналы и вывод — чистые правила (без модели, тестируются стендом)
    // -------------------------------------------------------------------------

    /**
     * Сигналы по данным реестра и вакансиям.
     *
     * @return array список ['level' => danger|warn|ok|info, 'title' => …, 'text' => …]
     */
    public static function signals($org, array $vac)
    {
        $out = [];

        if ( ! $org)
        {
            $out[] = [
                'level' => 'info',
                'title' => 'Организация не найдена в справочнике',
                'text'  => 'Проверьте ИНН/ОГРН или название. Если организация есть в ЕГРЮЛ, полная разведка найдёт её по сайту и документам.',
            ];
        }
        else
        {
            $status = mb_strtolower((string) ($org['status'] ?? ''));

            if ($status !== '' AND (mb_strpos($status, 'ликвидац') !== FALSE OR mb_strpos($status, 'прекращ') !== FALSE))
            {
                $out[] = [
                    'level' => 'danger',
                    'title' => 'Деятельность прекращена',
                    'text'  => 'В реестре статус: '.mb_substr((string) $org['status'], 0, 120).'. Договоры и трудоустройство с такой организацией — риск.',
                ];
            }
            elseif ($status !== '' AND mb_strpos($status, 'банкрот') !== FALSE)
            {
                $out[] = [
                    'level' => 'danger',
                    'title' => 'Процедура банкротства',
                    'text'  => 'В реестре статус: '.mb_substr((string) $org['status'], 0, 120).'.',
                ];
            }
            elseif ($status !== '' AND mb_strpos($status, 'недостовер') !== FALSE)
            {
                $out[] = [
                    'level' => 'warn',
                    'title' => 'Отметка о недостоверности сведений',
                    'text'  => 'ФНС отметила сведения в реестре как недостоверные — уточните адрес и руководителя.',
                ];
            }
            elseif ($status !== '' AND mb_strpos($status, 'реорганизац') !== FALSE)
            {
                $out[] = [
                    'level' => 'info',
                    'title' => 'Идёт реорганизация',
                    'text'  => 'В реестре статус: '.mb_substr((string) $org['status'], 0, 120).'.',
                ];
            }

            if (mb_strtoupper((string) ($org['form'] ?? '')) === 'ИП')
            {
                $out[] = [
                    'level' => 'info',
                    'title' => 'Индивидуальный предприниматель',
                    'text'  => 'ИП отвечает по обязательствам личным имуществом — это нормально, но проверяйте надёжнее.',
                ];
            }

            $age = self::_age_days((string) ($org['reg_date'] ?? ''));

            if ($age !== NULL AND $age < 365)
            {
                $out[] = [
                    'level' => 'warn',
                    'title' => 'Компания зарегистрирована недавно',
                    'text'  => 'Дата регистрации: '.(string) $org['reg_date'].' — меньше года истории.',
                ];
            }

            if (trim((string) ($org['director'] ?? '')) === '')
            {
                $out[] = [
                    'level' => 'info',
                    'title' => 'Руководитель не указан',
                    'text'  => 'В справочнике нет данных о руководителе — их можно уточнить в карточке ФНС.',
                ];
            }

            if (trim((string) ($org['address'] ?? '')) === '')
            {
                $out[] = [
                    'level' => 'info',
                    'title' => 'Адрес не заполнен',
                    'text'  => 'В справочнике нет адреса — карточку можно обновить из ФНС на странице организации.',
                ];
            }
        }

        $active = (int) ($vac['active'] ?? 0);

        if ($active > 0)
        {
            $out[] = [
                'level' => 'ok',
                'title' => 'Есть активные вакансии — '.$active,
                'text'  => 'Мы собираем вакансии с сайтов работодателей: значит, компания сейчас набирает людей.',
            ];
        }

        return $out;
    }

    /**
     * Короткий вывод «с чем имеем дело». Опирается только на сигналы.
     *
     * @return array ['level' => …, 'title' => …, 'text' => …]
     */
    public static function verdict($org, array $signals)
    {
        $level = 'ok';
        $title = 'Критичных сигналов в реестре нет';
        $text  = 'Организация действует, явных стоп-сигналов в данных ЕГРЮЛ/ЕГРИП не видно.';

        if ( ! $org)
        {
            return [
                'level' => 'info',
                'title' => 'Данных для вывода мало',
                'text'  => 'Организация не найдена в справочнике — проверьте реквизиты вручную или запустите полную разведку.',
            ];
        }

        foreach ($signals as $s)
        {
            $st = (string) ($s['title'] ?? '');

            if ((string) ($s['level'] ?? '') === 'danger')
            {
                return [
                    'level' => 'danger',
                    'title' => 'Есть серьёзный сигнал',
                    'text'  => ($st !== '' ? $st.'. ' : '').'Проверьте документы и досье перед сделкой или откликом.',
                ];
            }

            if ((string) ($s['level'] ?? '') === 'warn' AND $level === 'ok')
            {
                $level = 'warn';
                $title = 'Есть предупреждения';
                $text  = ($st !== '' ? $st.' — ' : '').'посмотрите детали и досье.';
            }
        }

        return ['level' => $level, 'title' => $title, 'text' => $text];
    }

    // -------------------------------------------------------------------------
    // Помощники
    // -------------------------------------------------------------------------

    /**
     * Ключи company_key, по которым искать вакансии компании: домен и варианты
     * названия (полное, без организационной формы, ё→е).
     *
     * @return array
     */
    public static function vacancy_keys($org, $domain = '', $company = '')
    {
        $keys = [];

        if (trim((string) $domain) !== '')
        {
            $keys[] = mb_strtolower(trim((string) $domain));
        }

        $names = [];

        if (is_array($org))
        {
            $names[] = (string) ($org['short_name'] ?? '');
            $names[] = (string) ($org['full_name'] ?? '');
        }

        if (trim((string) $company) !== '')
        {
            $names[] = (string) $company;
        }

        foreach ($names as $name)
        {
            $name = trim((string) $name);

            if ($name === '')
            {
                continue;
            }

            $keys[] = mb_strtolower($name);

            foreach (self::name_variants($name) as $v)
            {
                $keys[] = $v;
            }
        }

        $out = [];

        foreach ($keys as $k)
        {
            $k = trim((string) $k);

            if ($k !== '' AND mb_strlen($k) >= 3)
            {
                $out[$k] = TRUE;
            }
        }

        return array_keys($out);
    }

    /**
     * Варианты нормализации названия: без кавычек, без организационной формы,
     * без «ё», без лишних пробелов. Чистая функция — проверяется стендом.
     *
     * @return array
     */
    public static function name_variants($name)
    {
        $base = mb_strtolower(trim((string) $name));
        $base = str_replace('ё', 'е', $base);
        $base = (string) preg_replace('~[«»"\'`]~u', ' ', $base);
        $base = trim((string) preg_replace('~\s+~u', ' ', $base));

        $out = [];

        if ($base !== '')
        {
            $out[] = $base;
        }

        $words = explode(' ', $base);

        if (count($words) > 1 AND in_array($words[0], self::$_forms, TRUE))
        {
            array_shift($words);
            $short = trim(implode(' ', $words));

            if ($short !== '')
            {
                $out[] = $short;
            }
        }

        // «ооо ромашка» → «ромашка» на случай формы в скобках и хвостов
        $stripped = (string) preg_replace('~^(ооо|оао|зао|пао|ао|ип|нко|гуп|муп|фгуп|мбу|ск|тсж)\s+~u', '', $base);

        if ($stripped !== '' AND $stripped !== $base)
        {
            $out[] = trim($stripped);
        }

        return array_values(array_unique($out));
    }

    /** Есть ли организация в справочнике по номеру (локально, без сети). */
    protected static function _org_by_number($num)
    {
        if ( ! class_exists('Service_Nj594_Orgs'))
        {
            return NULL;
        }

        $org = Service_Nj594_Orgs::by_ogrn($num);

        if ($org)
        {
            return $org;
        }

        foreach (Service_Nj594_Orgs::search($num, 3) as $o)
        {
            if ((string) $o['inn'] === (string) $num OR (string) $o['ogrn'] === (string) $num)
            {
                return (array) $o;
            }
        }

        return NULL;
    }

    /** Спросить ФНС и сохранить карточку (сбой сети — мягкая ошибка). */
    protected static function _refresh($num)
    {
        try
        {
            $res = Service_Nj594_Orgs::refresh($num);

            if (isset($res['ok']) AND ! empty($res['card']))
            {
                return ['card' => (array) $res['card']];
            }

            // 'limited' пробрасываем: страница скажет «реестр на паузе», а не «ошибка»
            return [
                'error'   => (string) ($res['error'] ?? 'карточку получить не удалось'),
                'limited' => ! empty($res['limited']),
            ];
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj635: ФНС refresh: :e', [':e' => $e->getMessage()]);

            return ['error' => 'сервис ФНС недоступен, попробуйте позже'];
        }
    }

    /** Из найденных строк выбрать одну: точное совпадение названия, иначе единственную. */
    protected static function _pick_org(array $found, $q)
    {
        if (empty($found))
        {
            return NULL;
        }

        $needle = mb_strtolower(trim((string) $q));

        foreach ($found as $o)
        {
            $short = mb_strtolower((string) $o['short_name']);
            $full  = mb_strtolower((string) $o['full_name']);

            if ($short === $needle OR $full === $needle)
            {
                return (array) $o;
            }

            foreach (self::name_variants((string) $o['short_name']) as $v)
            {
                if ($v === $needle)
                {
                    return (array) $o;
                }
            }
        }

        return count($found) === 1 ? (array) $found[0] : NULL;
    }

    /** Карточка организации в виде, удобном шаблону (id + поля nj594::card). */
    protected static function _org_card(array $org)
    {
        $card = class_exists('Service_Nj594_Orgs')
            ? Service_Nj594_Orgs::card($org)
            : ['ogrn' => (string) $org['ogrn'], 'short_name' => (string) $org['short_name']];

        $card['id'] = (int) ($org['id'] ?? 0);

        return $card;
    }

    /** Кнопки «что дальше» — ведут в существующие сервисы. */
    protected static function _actions(array $card)
    {
        $company = '';

        if ($card['org'])
        {
            $company = (string) ($card['org']['short_name'] !== '' ? $card['org']['short_name'] : $card['org']['full_name']);
        }
        elseif ($card['vacancy'])
        {
            $company = (string) $card['vacancy']['company'];
        }

        $q = $company !== '' ? $company : (string) $card['query'];
        $out = [];

        if ($card['org'])
        {
            $out[] = [
                'label' => 'Карточка в справочнике',
                'hint'  => 'Реквизиты, статус, руководитель, связи',
                'icon'  => 'fas fa-landmark',
                'href'  => (string) $card['org']['url'],
            ];
        }

        // «Досье одной кнопкой»: плитка не ссылка, а форма — один клик создаёт
        // досье (POST /check) и сразу ведёт к результату. Профиль сайта и письмо
        // остаются отдельными шагами: там нужен ввод от человека.
        $out[] = [
            'label'  => 'Собрать AI-досье сейчас',
            'hint'   => 'Отзывы, стек, красные флаги — запуск одним нажатием (2–4 минуты)',
            'icon'   => 'fas fa-bolt',
            'href'   => '/dossier?company='.rawurlencode($q),
            'action' => 'dossier',
            'query'  => $q,
        ];

        $out[] = [
            'label' => 'Профиль сайта',
            'hint'  => 'Контакты, ЛПР, стек — обход открытых страниц',
            'icon'  => 'fas fa-building',
            'href'  => '/company',
        ];

        $out[] = [
            'label' => 'Вакансии компании',
            'hint'  => 'Мониторы вакансий: город, направление, компании',
            'icon'  => 'fas fa-briefcase',
            'href'  => '/vacancies',
        ];

        $out[] = [
            'label' => 'Наблюдение за рисками',
            'hint'  => 'Суды, банкротство, изменения ЕГРЮЛ — раз в неделю',
            'icon'  => 'fas fa-shield-halved',
            'href'  => '/watchdog',
        ];

        return $out;
    }

    /** Домен ли это (company_key может быть и названием). */
    protected static function _looks_domain($s)
    {
        return (bool) preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+$~', mb_strtolower(trim((string) $s)));
    }

    /** Локальные и служебные адреса — не принимаем (SSRF-гигиена ввода). */
    protected static function _local_host($host)
    {
        $host = rtrim(mb_strtolower((string) $host), '.');

        if (in_array($host, ['localhost', 'localhost.localdomain', '0', '::1'], TRUE))
        {
            return TRUE;
        }

        return (bool) preg_match('~\.(local|internal|lan|home|corp|test)$~', $host);
    }

    /** Возраст организации в днях (NULL, если дату не разобрать). */
    protected static function _age_days($reg_date)
    {
        $reg_date = trim((string) $reg_date);

        if ($reg_date === '')
        {
            return NULL;
        }

        $ts = strtotime($reg_date);

        if ( ! $ts)
        {
            return NULL;
        }

        return (int) floor((time() - $ts) / 86400);
    }
}
