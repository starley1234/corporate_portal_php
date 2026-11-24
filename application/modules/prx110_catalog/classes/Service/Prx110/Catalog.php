<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Каталог prx-модулей (PRX-110) — реестр публичных сервисов платформы.
 *
 * Каждый сервисный модуль описывает себя файлом config/prx_module.php:
 *
 *   return [
 *       'id'        => 501,                      // номер модуля
 *       'slug'      => 'vat',
 *       'title'     => 'Калькулятор НДС',
 *       'short'     => 'Выделить или начислить НДС по ставкам 2026',
 *       'icon'      => 'fas fa-percent',          // FA-класс
 *       'href'      => '/vat',
 *       'group'     => 'Финансы',                 // раздел в сайдбаре и шапке
 *       'sort'      => 501,                       // порядок (меньше — выше)
 *       'keywords'  => 'ндс налог успн калькулятор', // для быстрого поиска
 *       'api'       => ['/prx501/api/calc'],
 *       'card_view' => 'prx501/card',              // виджет главной (views/prx501/card.php)
 *   ];
 *
 * Из реестра собираются автоматически:
 *   * меню сайдбара (Service_Prx110_Catalog::menu_items) — контроллеры не дублируют;
 *   * карточки сервисов на главной (home_cards → card_view каждого модуля);
 *   * чипы раздела в шапке на страницах сервисов (header_nav);
 *   * подсказки иконок для /prx118_links (picker_icons + matching по href);
 *   * быстрый поиск по сервисам (search_json → палитра «Все сервисы» и 3D-карта);
 *   * страница каталога /services и хабы групп /services/<slug> (SSR, SEO).
 *
 * ЖИЗНЕННЫЙ ЦИКЛ (status) И АУДИТОРИЯ (audience) — единая точка видимости:
 *
 *   'status'   => draft|beta|stable|deprecated  (beta — фолбэк из badge)
 *   'audience' => public|auth|admin|agent      (auth — фолбэк для hidden)
 *
 * Рабочий каталог /services показывает только ОДОБРЕННЫЕ сервисы (status=stable).
 * beta/draft — это «в работе»: они не попадают на /services, в палитру и
 * сайдбар, но видны на /portal-plan и в режиме предпросмотра ?agent_preview=1
 * для агента/администратора. Отсутствие status больше НЕ означает публикацию:
 * без явного решения модуль считается черновиком (draft).
 *
 * Про эти поля подробнее — в Service_Prx110_Catalog::visible_modules() и
 * user_context(). Манифесты БЕЗ явного status подтягивают статус из prx218
 * (таблица prx_218_modules, system_name = имя папки модуля), где управление ведёт
 * жизненный цикл; при недоступности prx218 — тихий фолбэк на дефолты.
 */
class Service_Prx110_Catalog {

    /** @var array|NULL кеш манифестов (на запрос) */
    protected static $_modules = NULL;

    /** Семейства доменов: id => ['label' => …, 'base' => исходная группа главы]. */
    protected static $_families = NULL;

    /** Порядок разделов в сайдбаре */
    protected static $_group_order = [
        'Бухгалтерия'   => 10,
        'Финансы'       => 12,
        'Конвертеры'    => 20,
        'Единицы и быт' => 22,
        'Даты и время'  => 24,
        'Разработчику'  => 30,
        'Текст'         => 40,
        'Продажи'       => 45,
        'Карьера'       => 50,
        'Данные'        => 55,
        'Аккаунт'       => 85,
        'Сервисы'       => 90,
        'Платформа'     => 100,
    ];

    /** Значения status, которые считаем валидными. */
    protected static $_statuses = ['draft', 'beta', 'stable', 'deprecated'];

    /** Значения audience, которые считаем валидными. */
    protected static $_audiences = ['public', 'auth', 'admin', 'agent'];

    /**
     * Манифесты всех подключённых сервисных модулей, отсортированные по sort.
     */
    public static function modules()
    {
        if (self::$_modules !== NULL)
        {
            return self::$_modules;
        }

        $found = [];
        $all = Manifest::all();
        self::$_families = self::_families($all);

        // Манифесты собирает Manifest (application/classes/Manifest.php) — один
        // сборщик на платформу и один кэш. Раньше каталог сканировал модули
        // сам и включал 69 файлов на каждый HTTP-запрос.
        foreach ($all as $name => $data)
        {
            // без title/href модуль участвует в реестрах, но витрины не имеет
            if (empty($data['title']) OR empty($data['href']))
            {
                continue;
            }

            $data += [
                'slug'     => $name,
                'icon'     => 'fas fa-puzzle-piece',
                'group'    => 'Сервисы',
                'sort'     => isset($data['id']) ? (int) $data['id'] : 900,
                'keywords' => '',
                'short'    => '',
                'api'      => [],
                'family'   => NULL,   // NULL = модуль сам по себе, не часть домена
                'status'   => NULL,   // NULL = не задан явно (фолбэк brand/badge/prx218)
                'audience' => NULL,   // NULL = не задан явно (фолбэк hidden→auth)
            ];
            $data['module_name'] = $name;

            // Семейство домена важнее объявленной группы: четыре номера одного
            // домена показываются одним разделом, а не четырьмя строками меню.
            if ($data['family'] !== NULL AND isset(self::$_families[$data['family']]))
            {
                $data['group'] = self::$_families[$data['family']]['label'];
            }

            $found[] = $data;
        }

        usort($found, function ($a, $b) {
            return [$a['sort'], $a['title']] <=> [$b['sort'], $b['title']];
        });

        return self::$_modules = $found;
    }

    /**
     * Семейства доменов из манифестов.
     *
     * Глава семейства — модуль, у которого `slug` совпадает с id семейства
     * (`prx634_pdf` со slug `pdf` для семейства `pdf`). Название раздела берётся
     * у главы: отдельного реестра названий нет намеренно — иначе появился бы
     * седьмой реестр в чужом модуле, который мы только что вывели.
     *
     * @param  array $all манифесты (имя модуля => данные)
     * @return array      id семейства => ['label' => …, 'base' => …]
     */
    protected static function _families(array $all)
    {
        $heads = [];

        foreach ($all as $data)
        {
            $family = isset($data['family']) ? (string) $data['family'] : '';

            if ($family === '' OR empty($data['title']))
            {
                continue;
            }

            // глава — тот, чей slug совпал с именем семейства
            if (isset($data['slug']) AND (string) $data['slug'] === $family)
            {
                $heads[$family] = [
                    'label' => (string) $data['title'],
                    'base'  => isset($data['group']) ? (string) $data['group'] : '',
                ];
            }
        }

        return $heads;
    }

    /**
     * Место группы в общем порядке. Раздел семейства встаёт сразу за той
     * группой, в которой объявлена его глава, — чтобы PDF не уезжал в конец
     * списка только потому, что это новое название.
     *
     * @param  string $group название группы
     * @return float
     */
    protected static function _group_rank($group)
    {
        if (isset(self::$_group_order[$group]))
        {
            return (float) self::$_group_order[$group];
        }

        if (self::$_families === NULL)
        {
            self::modules();
        }

        foreach ((array) self::$_families as $f)
        {
            if ($f['label'] === $group AND isset(self::$_group_order[$f['base']]))
            {
                return self::$_group_order[$f['base']] + 0.5;
            }
        }

        return 80.0;
    }

    /**
     * Манифест по slug/модулю/id (или NULL).
     */
    public static function get($slug)
    {
        foreach (self::modules() as $m)
        {
            if ($m['slug'] === $slug OR (string) $m['id'] === (string) $slug OR $m['module_name'] === $slug)
            {
                return $m;
            }
        }

        return NULL;
    }

    /**
     * Порядок групп для витрин (чем меньше число — тем выше).
     * Единый источник: сайдбар и блок «Все сервисы» на главной.
     */
    public static function group_order()
    {
        return self::$_group_order;
    }

    /**
     * Стабильный ASCII-slug группы (для /services/<slug> и якорей).
     */
    public static function group_slug($group)
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
            'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
            'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts',
            'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        $slug = mb_strtolower(trim((string) $group));
        $slug = strtr($slug, $map);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-') !== '' ? trim($slug, '-') : 'services';
    }

    // -------------------------------------------------------------------------
    // Контекст пользователя и видимость
    // -------------------------------------------------------------------------

    /** Вошёл ли посетитель (без исключений, если Auth недоступен). */
    protected static function _logged_in()
    {
        try
        {
            return (bool) Auth::instance()->logged_in();
        }
        catch (Throwable $e)
        {
            return FALSE;
        }
    }

    /**
     * Контекст текущего пользователя для фильтров видимости.
     *
     * agent   — управление: администратор или роль 'agent' (audience='agent').
     * preview — дополнительный флаг: тот же агент/админ с ?agent_preview=1;
     *           только он показывает beta/draft («в работе») в рабочем каталоге.
     */
    public static function user_context()
    {
        static $ctx = NULL;

        if ($ctx !== NULL)
        {
            return $ctx;
        }

        $roles = [];
        $user_id = 0;

        try
        {
            if (Auth::instance()->logged_in())
            {
                $user = Auth::instance()->get_user();

                if ($user !== NULL)
                {
                    $user_id = (int) $user->id;
                    foreach ($user->roles->find_all() as $role)
                    {
                        $roles[] = (string) $role->name;
                    }
                }
            }
        }
        catch (Throwable $e)
        {
            // сбой Auth не должен ронять рендер навигации
        }

        $is_admin = in_array('admin', $roles, TRUE);
        $has_agent_role = in_array('agent', $roles, TRUE);
        // audience='agent' — управленческий взгляд: только админ или роль agent.
        $is_agent = ($is_admin OR $has_agent_role);

        return $ctx = [
            'guest'  => empty($roles),
            'logged' => ! empty($roles),
            'roles'  => $roles,
            'user_id' => $user_id,
            'admin'  => $is_admin,
            'agent'  => $is_agent,
            // Предпросмотр «в работе»: только агент/админ и явный флаг в query.
            // Обычный сотрудник не может открыть beta/draft одним параметром.
            'preview' => ($is_agent
                && isset($_GET['agent_preview'])
                && $_GET['agent_preview'] === '1'),
        ];
    }

    /**
     * Статус модуля: явный status → badge 'beta' → prx218 (system_name=папка)
     * → 'draft'.
     *
     * prx218 влияет на ВИДИМОСТЬ, только когда там явное решение:
     *   published=1 → маппим по тексту (alpha→draft, beta/stable, archived→deprecated);
     *   published=0 → archived/deprecated уходят в архив; другой не-alpha статус (beta,
     *   stable) — управление снял публикацию → draft. Автосозданные сканером записи
     *   (alpha + unpublished — дефолт) сигналом «скрыть» не считаются и попадают
     *   в дефолт 'draft': публикация — это явное решение, а не отсутствие записи.
     */
    public static function resolve_status(array $m)
    {
        if ( ! empty($m['status']) AND in_array((string) $m['status'], self::$_statuses, TRUE))
        {
            return (string) $m['status'];
        }

        // legacy: бейдж «beta» = статус beta
        if (isset($m['badge']) AND $m['badge'] === 'beta')
        {
            return 'beta';
        }

        $prx = self::_prx218_meta((string) ($m['module_name'] ?? $m['slug'] ?? ''));

        if ($prx !== NULL)
        {
            // published=1 — осознанное решение в prx218: маппим по тексту статуса.
            if ( ! empty($prx['published']))
            {
                switch ($prx['status'])
                {
                    case 'alpha':
                        return 'draft';
                    case 'beta':
                        return 'beta';
                    case 'stable':
                        return 'stable';
                    case 'archived':
                    case 'deprecated':
                        return 'deprecated';
                }

                return 'stable';
            }

            // published=0: уважаем только ЯВНЫЙ архив. Дефолтная запись сканера
            // (alpha) отдельным решением «скрыть» не считается и уходит в дефолт
            // 'draft': публикация всё равно требует явного stable.
            if (in_array((string) $prx['status'], ['archived', 'deprecated'], TRUE))
            {
                return 'deprecated';
            }

            // published=0 у НЕ-alpha модуля — управление осознанно снял публикацию:
            // такой сервис уходит публике (draft), но доступен агенту/админу.
            if ((string) $prx['status'] !== 'alpha')
            {
                return 'draft';
            }
        }

        // дефолт: без явного статуса модуль НЕ публикуется (одобрение — решение,
        // а не отсутствие поля). Так новый модуль не попадает на /services сам.
        return 'draft';
    }

    /**
     * Состояние сервиса для витрин: approved|wip|unpublished|archived.
     *
     *   approved    — stable и опубликован в рабочем каталоге (/services);
     *   wip         — beta/draft, «в работе» (/portal-plan, предпросмотр);
     *   unpublished — catalog:false: реализован, но публикация отложена;
     *   archived    — deprecated: выведен из работы, остаётся в архиве плана.
     */
    public static function resolve_state(array $m)
    {
        $state = self::resolve_manifest_state($m);
        if (in_array($state, ['wip', 'unpublished'], TRUE)
            && ! empty($m['module_name']) && class_exists('Service_Prx110_Publication')
            && Service_Prx110_Publication::eligible($m)
            && Service_Prx110_Publication::is_approved($m['module_name']))
        {
            return 'approved';
        }
        return $state;
    }

    public static function resolve_manifest_state(array $m)
    {
        $status = self::resolve_status($m);
        if ($status === 'deprecated')
        {
            return 'archived';
        }
        if (($m['catalog'] ?? TRUE) === FALSE)
        {
            return 'unpublished';
        }

        switch ($status)
        {
            case 'stable':
                return 'approved';
            case 'beta':
            case 'draft':
                return 'wip';
        }

        return 'wip';
    }

    /** Человеческая подпись состояния для /portal-plan и бейджей. */
    public static function state_label($state)
    {
        $labels = [
            'approved'    => 'Одобрен',
            'wip'         => 'В работе',
            'unpublished' => 'Не опубликован',
            'archived'    => 'Архив',
            'planned'     => 'Планируется',
        ];

        return $labels[$state] ?? (string) $state;
    }

    /**
     * Аудитория модуля: явная audience → hidden (legacy) → 'public'.
     */
    public static function resolve_audience(array $m)
    {
        if ( ! empty($m['audience']) AND in_array((string) $m['audience'], self::$_audiences, TRUE))
        {
            return (string) $m['audience'];
        }

        // legacy hidden: убрано из меню, но доступно авторизованным (аккаунт-страницы)
        if ( ! empty($m['hidden']))
        {
            return 'auth';
        }

        return 'public';
    }

    /** Кеш метаданных prx218 (system_name => [status, published]). */
    protected static $_prx218 = NULL;

    protected static function _prx218_meta($module_name)
    {
        if (self::$_prx218 === NULL)
        {
            self::$_prx218 = ['loaded' => FALSE, 'map' => []];

            try
            {
                foreach (ORM::factory('Prx218_Module')
                    ->where('is_deleted', '=', 0)
                    ->find_all() as $mod)
                {
                    self::$_prx218['map'][(string) $mod->system_name] = [
                        'status'    => (string) $mod->status,
                        'published' => (int) $mod->is_published === 1,
                    ];
                }

                self::$_prx218['loaded'] = TRUE;
            }
            catch (Throwable $e)
            {
                // prx218 не подключён или таблицы нет — живём на манифестах
            }
        }

        if (empty($module_name) OR ! isset(self::$_prx218['map'][$module_name]))
        {
            return NULL;
        }

        return self::$_prx218['map'][$module_name];
    }

    /**
     * Проходит ли сервис по аудитории и ACL манифеста (без статуса).
     * Используется и рабочим каталогом, и планом портала.
     */
    protected static function _audience_allows(array $m, array $ctx)
    {
        switch (self::resolve_audience($m))
        {
            case 'public':
                break;
            case 'auth':
                if (empty($ctx['logged'])) return FALSE;
                break;
            case 'admin':
                if (empty($ctx['admin'])) return FALSE;
                break;
            case 'agent':
                if (empty($ctx['agent'])) return FALSE;
                break;
        }

        if ( ! empty($m['acl']) && (empty($ctx['logged'])
            || ! Prx_Actor::fromArray($ctx)->can($m['acl'])))
        {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Предпросмотр «в работе»: ?agent_preview=1 доступен только агенту/админу.
     * Без него beta/draft не показываются даже администратору — иначе рабочий
     * каталог снова смешивал бы одобренные сервисы с незавершёнными.
     */
    protected static function _preview(array $ctx)
    {
        // Двойная проверка: флаг предпросмотра без роли агента/админа не действует.
        return ! empty($ctx['preview']) && ! empty($ctx['agent']);
    }

    /**
     * Может ли пользователь ВИДЕТЬ сервис (меню/поиск/каталог/карточки/чипы).
     * Рабочая витрина: публикация, аудитория, ACL и статус ТОЛЬКО stable —
     * «одобрен». beta/draft («в работе») видны лишь в предпросмотре.
     */
    public static function can_see(array $m, array $ctx)
    {
        if ( ! self::_audience_allows($m, $ctx))
        {
            return FALSE;
        }

        $state = self::resolve_state($m);

        if ($state === 'approved')
        {
            return TRUE;
        }

        return $state === 'wip' && self::_preview($ctx);
    }

    /**
     * Манифесты для /portal-plan: все состояния (approved/wip/unpublished/archived)
     * с учётом аудитории и ACL, в порядке каталога. План показывает и одобренные,
     * и «в работе», и отложенные публикацией; архив контроллер выводит отдельно.
     */
    public static function plan_modules($ctx = NULL)
    {
        $ctx = $ctx === NULL ? self::user_context() : $ctx;
        $out = [];

        foreach (self::modules() as $m)
        {
            if ( ! self::_audience_allows($m, $ctx))
            {
                continue;
            }

            $m['state'] = self::resolve_state($m);
            $out[] = $m;
        }

        return $out;
    }

    /**
     * Имена семи последних публичных сервисов по порядку sort.
     * Новые сервисы получают больший sort/id, поэтому бейдж обновляется
     * автоматически при добавлении сервиса; старые ручные badge='новое'
     * не остаются навсегда. «В работе» бейдж «Новое» не получает.
     */
    protected static function _recent_public_modules()
    {
        $candidates = [];

        foreach (self::modules() as $m)
        {
            if (self::resolve_audience($m) !== 'public'
                OR self::resolve_status($m) !== 'stable')
            {
                continue;
            }

            $candidates[] = $m;
        }

        usort($candidates, function ($a, $b) {
            return [$b['sort'], $b['title']] <=> [$a['sort'], $a['title']];
        });

        return array_fill_keys(array_column(array_slice($candidates, 0, 7), 'module_name'), TRUE);
    }

    /**
     * Манифесты, видимые текущему пользователю (по status+audience).
     * Бейдж «Новое» показывается только у семи последних публичных сервисов.
     */
    public static function visible_modules($ctx = NULL)
    {
        $ctx = $ctx === NULL ? self::user_context() : $ctx;
        $recent = self::_recent_public_modules();
        $out = [];

        foreach (self::modules() as $m)
        {
            if ( ! self::can_see($m, $ctx))
            {
                continue;
            }

            // Убираем устаревшие вручную проставленные бейджи, сохраняя beta
            // и любые другие осмысленные метки манифеста.
            if (isset($m['badge']) AND mb_strtolower(trim((string) $m['badge'])) === 'новое')
            {
                $m['badge'] = '';
            }

            if (isset($recent[$m['module_name']]))
            {
                $m['badge'] = 'Новое';
            }

            $out[] = $m;
        }

        return $out;
    }

    /**
     * JSON реестра для палитры «Все сервисы» и страницы /services.
     * Поиск работает по ВСЕМ видимым сервисам (не по пунктам меню).
     */
    public static function search_json($ctx = NULL)
    {
        $ctx = $ctx === NULL ? self::user_context() : $ctx;
        $items = [];

        foreach (self::visible_modules($ctx) as $m)
        {
            $items[] = [
                'title'    => (string) $m['title'],
                'short'    => (string) ($m['short'] ?? ''),
                'href'     => (string) $m['href'],
                'icon'     => (string) $m['icon'],
                'group'    => (string) $m['group'],
                'gslug'    => self::group_slug($m['group']),
                'keywords' => (string) ($m['keywords'] ?? ''),
                // Алиасы нужны для подсветки избранного на страницах объектов;
                // как и API ниже, отдаём только относительные локальные пути.
                'nav_paths' => array_values(array_filter(array_map(function ($path) {
                    return is_string($path) ? trim($path) : '';
                }, (array) ($m['nav_paths'] ?? [])), function ($path) {
                    return substr($path, 0, 1) === '/' AND substr($path, 0, 2) !== '//';
                })),
                // API-маршруты — часть публичного манифеста. Отдаём только
                // относительные URL: внешние или protocol-relative значения
                // не должны превращать каталог в источник непроверенных ссылок.
                'api'      => array_values(array_filter((array) ($m['api'] ?? []), function ($endpoint) {
                    $endpoint = trim((string) $endpoint);

                    return substr($endpoint, 0, 1) === '/' AND substr($endpoint, 0, 2) !== '//';
                })),
                'badge'    => (string) ($m['badge'] ?? ''),
                'status'   => self::resolve_status($m),
                'state'    => self::resolve_state($m),
                'audience' => self::resolve_audience($m),
                'hidden'   => ! empty($m['hidden']),
            ];
        }

        return [
            'groups' => self::groups_summary($ctx),
            'items'  => $items,
            'total'  => count($items),
        ];
    }

    /**
     * Группы видимых сервисов: slug → [title, icon, count], в порядке group_order.
     */
    public static function groups_summary($ctx = NULL)
    {
        $ctx = $ctx === NULL ? self::user_context() : $ctx;

        $by_group = [];

        foreach (self::visible_modules($ctx) as $m)
        {
            $g = (string) $m['group'];
            $by_group[$g]['title'] = $g;
            $by_group[$g]['count'] = isset($by_group[$g]['count']) ? $by_group[$g]['count'] + 1 : 1;
            $by_group[$g]['slug'] = self::group_slug($g);
            $by_group[$g]['icon'] = (string) $m['icon'];
        }

        uksort($by_group, function ($a, $b) {
            return [self::_group_rank($a), $a] <=> [self::_group_rank($b), $b];
        });

        $out = [];

        foreach ($by_group as $g => $info)
        {
            $info['slug'] = self::group_slug($g);

            // иконка группы — первая (по sort) иконка сервиса группы
            $out[] = [
                'slug'  => (string) $info['slug'],
                'title' => (string) $g,
                'count' => (int) $info['count'],
                'icon'  => (string) $info['icon'],
            ];
        }

        return $out;
    }

    /**
     * Модули, видимые текущему пользователю и принадлежащие группе (по slug).
     */
    public static function group_modules($slug, $ctx = NULL)
    {
        $ctx = $ctx === NULL ? self::user_context() : $ctx;
        $out = [];

        foreach (self::visible_modules($ctx) as $m)
        {
            if (self::group_slug($m['group']) === (string) $slug)
            {
                $out[] = $m;
            }
        }

        return $out;
    }

    /**
     * Модули с виджетом для главной (card_view), в порядке sort.
     * Фильтр по видимости; лимит опционален (разгрузка главной).
     * Если задан лимит — стабильные идут первыми (тяжёлый hero не пухнет).
     */
    public static function home_cards($ctx = NULL, $limit = NULL)
    {
        $ctx = $ctx === NULL ? self::user_context() : $ctx;
        $cards = [];

        foreach (self::visible_modules($ctx) as $m)
        {
            if ( ! empty($m['card_view']))
            {
                $cards[] = $m;
            }
        }

        if ($limit !== NULL)
        {
            // стабильные раньше beta/draft; внутри — по sort
            // 'home' => TRUE в манифесте ставит карточку выше обычного порядка sort;
            // стабильность остаётся важнее, чтобы beta/draft не вышли на витрину.
            usort($cards, function ($a, $b) {
                $sa = self::resolve_status($a) === 'stable' ? 0 : 1;
                $sb = self::resolve_status($b) === 'stable' ? 0 : 1;
                $ha = empty($a['home']) ? 1 : 0;
                $hb = empty($b['home']) ? 1 : 0;

                return [$sa, $ha, $a['sort'], $a['title']] <=> [$sb, $hb, $b['sort'], $b['title']];
            });

            if (count($cards) > $limit)
            {
                $cards = array_slice($cards, 0, (int) $limit);
            }

            // после усечения возвращаем каталоговый порядок (sort)
            usort($cards, function ($a, $b) {
                return [$a['sort'], $a['title']] <=> [$b['sort'], $b['title']];
            });
        }

        return $cards;
    }

    /**
     * Текущий путь со слэшем ('/vat'); в CLI — '/'.
     */
    protected static function _current_uri()
    {
        $request = Request::current();

        return '/'.($request !== NULL ? $request->uri() : '');
    }

    /**
     * Постоянный пункт панели — только каталог. Сервисы остаются в манифестах
     * и добавляются в персональное «Избранное», а не в общее меню автоматически.
     */
    public static function menu_items()
    {
        $uri = self::_current_uri();

        return [[
            'title' => 'Все сервисы', 'href' => '/services',
            'icon' => 'fas fa-grip', 'group' => 'Сервисы', 'sort' => 0,
            'active' => ($uri === '/services' OR strpos($uri, '/services/') === 0),
        ]];
    }

    /**
     * Инструменты платформы для страницы «Администрирование» (/admin).
     *
     * До 06.10.2026 этот список жил пунктами левой панели (группа «Платформа»)
     * и рос с каждым модулем; теперь панель — навигация по сервисам, а
     * инструменты собраны на одной странице. Каждый пункт показывается, только
     * если соответствующий модуль подключён.
     *
     * @return array ['title', 'href', 'icon', 'note', 'sort']
     */
    public static function admin_tools()
    {
        $tools = [
            ['llm', 'LLM', '/llm_admin', 'fas fa-robot', 'Провайдеры и модели', 36],
            ['prx111_migrations', 'Миграции', '/migrate_pg', 'fas fa-database',
                'Схема PostgreSQL: применить или откатить миграции модулей', 30],
            ['prx103_platform', 'Логи платформы', '/prx100/logs', 'fas fa-terminal',
                'Журнал сайта и php-лог Kohana: по дням, уровням и подстроке; где что пишется', 31],
            ['prx208_events', 'События', '/prx208_events', 'fas fa-bolt',
                'Журнал доменных событий и подписки: кто что опубликовал и кто слушает', 32],
            ['prx597_notifications', 'Уведомления', '/prx597/admin', 'fas fa-bell-concierge',
                'Очередь доставки, журнал, шаблоны и каналы центра уведомлений', 33],
            ['prx150_api_hub', 'API-доступ', '/prx150_api_hub', 'fas fa-key',
                'Ключи внешних потребителей, права на сервисы и журнал использования', 34],
            ['prx450_document_ocr', 'Распознавание документов', '/prx450_document_ocr', 'fas fa-file-lines',
                'PDF и сканы → Markdown через внешний OCR-шлюз; API для интеграций', 36],
            ['prx218_module_manager', 'Модули', '/prx218', 'fas fa-puzzle-piece',
                'Включение/выключение модулей платформы', 35],
            ['prx120_jobs', 'Воркеры · док', '/prx120/workers', 'fas fa-book',
                'Автодокументация воркеров: docstring и mermaid-схемы пайплайнов', 44],
            ['prx560_knowledge', 'Знания · управление', '/prx560', 'fas fa-brain',
                'База знаний платформы', 39],
            ['prx115_semantic', 'Семантика · поиск и векторы', '/prx115', 'fas fa-magnifying-glass-chart',
                'Коллекции векторов, индексация и проверка поиска', 41],
        ];

        $modules = Kohana::modules();
        $out = [];

        foreach ($tools as $tool)
        {
            if ( ! array_key_exists($tool[0], $modules))
            {
                continue;
            }

            $out[] = [
                'module' => $tool[0],
                'title'  => $tool[1],
                'href'   => $tool[2],
                'icon'   => $tool[3],
                'note'   => $tool[4],
                'sort'   => $tool[5],
            ];
        }

        usort($out, function ($a, $b) {
            return [$a['sort'], $a['title']] <=> [$b['sort'], $b['title']];
        });

        return $out;
    }

    /**
     * Раздел «Страницы» (о сервисе, условия, конфиденциальность, дизайн):
     * единый источник для чипов шапки (header_nav) и левой панели
     * «Навигация» (контроллер Pages кладёт их в set_menu с group=Страницы).
     */
    public static function pages_items()
    {
        return [
            ['title' => 'О сервисе',          'href' => '/about',                        'icon' => 'fas fa-circle-info'],
            ['title' => 'Условия',            'href' => '/terms',                        'icon' => 'fas fa-file-contract'],
            ['title' => 'Конфиденциальность', 'href' => '/privacy',                      'icon' => 'fas fa-shield-halved'],
            ['title' => 'Эталон дизайна',     'href' => '/pages/required_design_sample', 'icon' => 'fas fa-ruler-combined'],
        ];
    }

    public static function header_nav()
    {
        $modules = self::visible_modules();

        if ( ! $modules)
        {
            return NULL;
        }

        $uri = self::_current_uri();
        $current = NULL;
        $best_len = -1;

        $current_path = NULL;

        foreach ($modules as $m)
        {
            // Некоторые страницы объекта живут на коротком алиасе (например,
            // /p/<code> для опроса /poll). Манифест объявляет такие пути через
            // nav_paths, чтобы чип текущего раздела не пропадал на объекте.
            $paths = array_merge([(string) $m['href']], (array) ($m['nav_paths'] ?? []));

            foreach ($paths as $path)
            {
                $href = rtrim((string) $path, '/');

                if ($href !== '' AND ($uri === $href OR strpos($uri, $href.'/') === 0)
                    AND strlen($href) > $best_len)
                {
                    $current = $m;
                    $current_path = $href;
                    $best_len = strlen($href);
                }
            }
        }

        // страница может быть раздел управленияом модуля (/prx520/admin и т.п.)
        $admin_page = FALSE;

        // главный href совпал, но URI — внутри раздел управленияа ЭТОГО ЖЕ модуля:
        // тогда активен только чип управления (иначе на /orgs/admin «горит» всё)
        if ($current !== NULL AND ! empty($current['admin']['href']))
        {
            $ahref = rtrim($current['admin']['href'], '/');

            if ($uri === $ahref OR strpos($uri, $ahref.'/') === 0)
            {
                $admin_page = TRUE;
            }
        }

        if ($current === NULL)
        {
            foreach ($modules as $m)
            {
                if ( ! empty($m['admin']['href']))
                {
                    $ahref = rtrim($m['admin']['href'], '/');

                    if ($uri === $ahref OR strpos($uri, $ahref.'/') === 0)
                    {
                        $current = $m;
                        $admin_page = TRUE;
                        break;
                    }
                }
            }
        }

        // вне реестра модулей — раздел «Страницы»: свои чипы шапки
        if ($current === NULL)
        {
            $uri_noslash = rtrim($uri, '/');

            foreach (self::pages_items() as $pg)
            {
                $phref = rtrim($pg['href'], '/');

                if ($uri_noslash === $phref OR strpos($uri_noslash, $phref.'/') === 0)
                {
                    $items = [];

                    foreach (self::pages_items() as $pg2)
                    {
                        $p2 = rtrim($pg2['href'], '/');
                        $items[] = [
                            'title'  => $pg2['title'],
                            'href'   => $pg2['href'],
                            'icon'   => $pg2['icon'],
                            'active' => ($uri_noslash === $p2 OR strpos($uri_noslash, $p2.'/') === 0),
                        ];
                    }

                    return ['group' => 'Страницы', 'icon' => 'fas fa-file-lines', 'items' => $items];
                }
            }

            return NULL;
        }

        // Модуль сам отказался от чипа в шапке (header_chips = FALSE): навигация
        // по разделу живёт внутри страницы (например, лендинги /s и /s/<group>
        // показывают другие группы ссылками в теле). Чип тут давал бы уродливый
        // дубль заголовка и на малых экранах — горизонтальную прокрутку.
        if (isset($current['header_chips']) AND $current['header_chips'] === FALSE)
        {
            return NULL;
        }

        // Модуль ведёт чипы САМ (header_chips = 'own'): его собственный
        // `module_nav` — та же навигация по разделу, и дублировать её чипами
        // каталога значит показывать «Похожие картинки» и «Похожие картинки ·
        // журнал» рядом (жалоба владельца, 08.10.2026). Чипы каталога при этом
        // не рисуются вовсе: строку собирает шаблон из module_nav.
        if (isset($current['header_chips']) AND (string) $current['header_chips'] === 'own')
        {
            return NULL;
        }

        // ЧИПЫ ТЕКУЩЕГО МОДУЛЯ (не группы!): чужие модули в шапке не смешиваем —
        // иначе в /design прилипал чип /3d, а в /company — «Холодное письмо»
        $items = [];
        $icon = $current['icon'];

        $items[] = [
            'title'  => $current['title'],
            'href'   => $current['href'],
            'icon'   => $current['icon'],
            'active' => ($current_path !== NULL AND empty($admin_page)),
        ];

        // чип управления модуля — только администратору
        if ( ! empty($current['admin']['href'])
            AND Auth::instance()->logged_in('admin'))
        {
            $ahref = rtrim($current['admin']['href'], '/');

            $items[] = [
                'title'  => $current['admin']['title'],
                'href'   => $current['admin']['href'],
                'icon'   => ! empty($current['admin']['icon']) ? $current['admin']['icon'] : 'fas fa-shield-halved',
                'active' => ($uri === $ahref OR strpos($uri, $ahref.'/') === 0),
            ];
        }

        return [
            'group' => $current['group'],
            'icon'  => $icon,
            'items' => $items,
        ];
    }

    /**
     * Иконки для пикера /prx118_links: иконки модулей каталога + популярные FA.
     */
    public static function picker_icons()
    {
        $icons = [];

        foreach (self::modules() as $m)
        {
            if ( ! in_array($m['icon'], $icons, TRUE))
            {
                $icons[] = $m['icon'];
            }
        }

        foreach ([
            'fas fa-link', 'fas fa-globe', 'fas fa-star', 'fas fa-heart', 'fas fa-bookmark',
            'fas fa-folder', 'fas fa-envelope', 'fas fa-cloud', 'fas fa-code', 'fas fa-database',
            'fas fa-chart-line', 'fas fa-file-lines', 'fas fa-image', 'fas fa-video',
            'fas fa-cart-shopping', 'fas fa-credit-card', 'fas fa-briefcase',
            'fas fa-graduation-cap', 'fas fa-robot', 'fas fa-key', 'fas fa-shield-halved',
            'fas fa-gear', 'fas fa-calendar-days', 'fas fa-money-bill-wave',
        ] as $icon)
        {
            if ( ! in_array($icon, $icons, TRUE))
            {
                $icons[] = $icon;
            }
        }

        return $icons;
    }
}
