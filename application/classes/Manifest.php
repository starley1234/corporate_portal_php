<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Пятый контракт: модуль объявляет себя сам.
 *
 * Первые четыре контракта (docs/ARCHITECTURE.md §3) описывают ВЕРТИКАЛЬНЫЕ
 * связи — «A зовёт B». Ни один из них не отвечает на поперечные вопросы
 * платформы: «покажи задачи всех модулей», «что пора чистить», «что сломалось
 * везде», «найди по всему». Поэтому каждый агрегатор, которому это
 * понадобилось, завёл у себя список чужих модулей — и добавление нового
 * модуля стало правкой семи чужих файлов.
 *
 * Манифест `config/nj_module.php` уже решил ровно эту задачу для МЕНЮ: модуль
 * описывает свою карточку сам, а `Service_Nj110_Catalog` собирает их. Этот
 * класс распространяет тот же приём на ДАННЫЕ и служит единственной точкой
 * сборки.
 *
 * Разница с `Service_Nj110_Catalog::modules()`: каталог отбирает только
 * карточки для витрины (обязательны `title` и `href`). Здесь отбора нет —
 * манифест может состоять из одной секции `worker`, и модуль, которому нечего
 * показывать пользователю, всё равно участвует в реестрах.
 *
 * Секции (добавляются по мере переноса реестров, docs/PLAN-2026-09-28.md §3):
 *
 *     return [
 *         'id'     => 602,
 *         'slug'   => 'nj602_content',
 *         'worker' => [
 *             'channels' => [
 *                 'nj602.article' => [
 *                     'label'     => 'Статья в журнал',
 *                     'url'       => '/nj602/admin',
 *                     'reconcile' => "SELECT 1 FROM nj_602_articles a WHERE …",
 *                 ],
 *             ],
 *         ],
 *     ];
 *
 * Кэш. Сборка включает `include` 63 файлов на каждый HTTP-запрос, поэтому
 * результат запоминается в статике (в пределах запроса) и в файловом кэше
 * Kohana, когда кэширование включено (боевой режим). В development кэша нет
 * намеренно: правка манифеста должна быть видна сразу.
 *
 * @see docs/ARCHITECTURE.md §3
 * @see docs/PLAN-2026-09-28.md этап 3
 */
class Manifest {

    /** Ключ файлового кэша Kohana. */
    const CACHE_KEY = 'nj.manifest.v1';

    /** Время жизни файлового кэша, секунд. */
    const CACHE_TTL = 3600;

    /** Собранные манифесты: имя модуля => данные. NULL — ещё не собирали. */
    protected static $_all = NULL;

    /**
     * Манифесты всех подключённых модулей.
     *
     * @return array имя модуля => массив манифеста
     */
    public static function all()
    {
        if (self::$_all !== NULL)
        {
            return self::$_all;
        }

        if (Kohana::$caching)
        {
            $cached = Kohana::cache(self::CACHE_KEY, NULL, self::CACHE_TTL);

            if (is_array($cached))
            {
                return self::$_all = $cached;
            }
        }

        $found = [];

        foreach (Kohana::modules() as $name => $path)
        {
            $file = $path.'config'.DIRECTORY_SEPARATOR.'nj_module.php';

            if ( ! is_file($file))
            {
                continue;
            }

            $data = include $file;

            if ( ! is_array($data))
            {
                continue;
            }

            $data['module_name'] = $name;
            $found[$name] = $data;
        }

        if (Kohana::$caching)
        {
            Kohana::cache(self::CACHE_KEY, $found, self::CACHE_TTL);
        }

        return self::$_all = $found;
    }

    /**
     * Манифест одного модуля.
     *
     * @param  string $module имя модуля, как в bootstrap.php
     * @return array          пустой массив, если манифеста нет
     */
    public static function get($module)
    {
        $all = self::all();

        return isset($all[$module]) ? $all[$module] : [];
    }

    /**
     * Срез по секции: только модули, которые её объявили.
     *
     * @param  string $key имя секции ('worker', 'cleanup', 'schedule', …)
     * @return array       имя модуля => значение секции
     */
    public static function section($key)
    {
        $out = [];

        foreach (self::all() as $name => $data)
        {
            if (isset($data[$key]) AND $data[$key] !== [] AND $data[$key] !== NULL)
            {
                $out[$name] = $data[$key];
            }
        }

        return $out;
    }

    /**
     * Все объявленные каналы очереди: имя канала => описание.
     *
     * К описанию добавляется `module` — имя объявившего модуля: по нему видно
     * владельца канала без обратного поиска по конфигам.
     *
     * Дубликат имени канала — ошибка владения (два модуля считают канал
     * своим). Такое не «сливается молча»: побеждает первый, а второй пишется
     * в лог, чтобы конфликт нашёлся, а не тянулся годами.
     *
     * @return array имя канала => ['module' => …, 'label' => …, 'url' => …, 'reconcile' => …]
     */
    public static function channels()
    {
        $out = self::_flatten('worker', 'канал', 'channels');

        ksort($out);

        return $out;
    }

    /**
     * Подписи и ссылки каналов для страниц задач (бывший `nj120_jobs.channels_ui`).
     *
     * @return array имя канала => ['label' => …, 'url' => …]
     */
    public static function channels_ui()
    {
        $out = [];

        foreach (self::channels() as $channel => $spec)
        {
            if (empty($spec['label']))
            {
                continue;
            }

            $out[$channel] = [
                'label' => (string) $spec['label'],
                'url'   => isset($spec['url']) ? (string) $spec['url'] : '',
            ];
        }

        return $out;
    }

    /**
     * Условия «результат уже виден на сайте» (бывший `nj120_jobs.reconcile_hooks`).
     *
     * @return array имя канала => SQL-условие
     */
    public static function reconcile_hooks()
    {
        $out = [];

        foreach (self::channels() as $channel => $spec)
        {
            if ( ! empty($spec['reconcile']))
            {
                $out[$channel] = (string) $spec['reconcile'];
            }
        }

        return $out;
    }

    /**
     * Расписания пинков (бывший `nj603_kicker.schedules`).
     *
     * Ключ расписания — часть состояния в БД (последний запуск, ошибки),
     * поэтому имена ключей менять нельзя: они пережили переезд дословно.
     *
     * @return array ключ расписания => описание + `module`
     */
    public static function schedules()
    {
        return self::_flatten('schedule', 'расписание');
    }

    /**
     * Чистки данных (бывший конфиг `nj100_cleanup`).
     *
     * Ключ чистки виден на /nj100/cleanup и запоминается между запусками,
     * поэтому имена ключей сохранены дословно с момента переезда.
     *
     * @return array ключ чистки => описание + `module`
     */
    public static function cleanups()
    {
        return self::_flatten('cleanup', 'чистка');
    }

    /**
     * Источники «хвоста ошибок» (бывший массив `$sources` в Nj100/Errors).
     *
     * @return array имя таблицы => ['label' => …, 'code' => …, 'href' => …]
     */
    public static function health()
    {
        return self::_flatten('health', 'источник ошибок');
    }

    /**
     * Источники ленты «последнее» на дашборде (бывший `$src` в Nj100/Dashboard).
     *
     * ВНИМАНИЕ: `title` — это SQL-выражение, а не данные. Манифест лежит в
     * репозитории и является кодом; пользовательский ввод сюда не попадает
     * и попадать не должен.
     *
     * @return array имя таблицы => ['title' => …, 'status' => …, 'base' => …, 'icon' => …]
     */
    public static function recent()
    {
        return self::_flatten('recent', 'источник ленты');
    }

    /**
     * Источники глобального поиска (бывший `$queries` в Service_Nj100_Search).
     *
     * Порядок — по `sort`: он задаёт порядок групп на странице /search.
     *
     * ВНИМАНИЕ: `sql` — это код, лежащий в репозитории, а не данные. Запрос
     * пользователя попадает в него только через плейсхолдеры, которые
     * подставляет агрегатор, пропустив значение через `Database::quote()`.
     *
     * @return array тип => ['label' => …, 'icon' => …, 'sort' => …,
     *                       'scope' => 'user'|'all', 'sql' => …]
     */
    public static function search()
    {
        $out = self::_flatten('search', 'источник поиска');

        uasort($out, function ($a, $b) {
            return (int) Arr::get($a, 'sort', 0) <=> (int) Arr::get($b, 'sort', 0);
        });

        return $out;
    }

    /**
     * Свести одноимённую секцию всех модулей в один плоский список.
     *
     * Дубликат ключа — ошибка владения: два модуля считают запись своей.
     * Побеждает первый, второй уходит в лог, чтобы конфликт нашёлся.
     *
     * @param  string      $section имя секции манифеста
     * @param  string      $what    как называть запись в сообщении лога
     * @param  string|NULL $subkey  вложенный ключ, если записи лежат глубже
     *                              (например, worker.channels)
     * @return array                ключ => значение + `module`
     */
    protected static function _flatten($section, $what, $subkey = NULL)
    {
        $out = [];

        foreach (self::section($section) as $module => $list)
        {
            if ($subkey !== NULL)
            {
                $list = isset($list[$subkey]) ? $list[$subkey] : NULL;
            }

            if ( ! is_array($list))
            {
                continue;
            }

            foreach ($list as $key => $spec)
            {
                if ( ! is_array($spec))
                {
                    continue;
                }

                if (isset($out[$key]))
                {
                    Kohana::$log->add(Log::WARNING, 'Manifest: :what :key объявлен дважды (:a и :b) — взят первый', [
                        ':what' => $what,
                        ':key'  => $key,
                        ':a'    => $out[$key]['module'],
                        ':b'    => $module,
                    ]);

                    continue;
                }

                $spec['module'] = $module;
                $out[$key]      = $spec;
            }
        }

        return $out;
    }

    /**
     * Сбросить собранное (тесты, CLI-задачи после правки манифестов).
     *
     * @return void
     */
    public static function reset()
    {
        self::$_all = NULL;

        if (Kohana::$caching)
        {
            Kohana::cache(self::CACHE_KEY, NULL, -1);
        }
    }
}
