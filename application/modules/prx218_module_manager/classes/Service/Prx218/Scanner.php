<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * Сервис для рекурсивного обхода папок, чтения README.md и парсинга метаданных модулей.
 * 
 * @package Prx218
 * @version 1.0.0
 */
class Service_Prx218_Scanner {

    /**
     * @var Service_Prx218_Scanner
     */
    protected static $_instance;

    /**
     * Singleton instance
     * 
     * @return Service_Prx218_Scanner
     */
    public static function instance()
    {
        if (self::$_instance === NULL)
        {
            self::$_instance = new self;
        }
        return self::$_instance;
    }

    protected function __construct() {}

    /**
     * Запуск процесса сканирования директории модулей и синхронизация с БД.
     *
     * Каждый запуск пишется в prx_218_scan_runs (время, кто, счётчики, итог),
     * а у записей реестра обновляются dir_found/last_seen_at: модуль, чьей
     * папки на диске больше нет, помечается «папка не найдена» (missing), но
     * не удаляется — ручные поля и история остаются.
     *
     * @param int $user_id ID пользователя, инициировавшего сканирование
     * @return array found, created, updated, skipped, missing, synced, no_agents, run_id
     * @throws Exception
     */
    public function scan_directory($user_id = 0)
    {
        $run_id = (int) (Prx_Db::one('INSERT INTO prx_218_scan_runs (actor_id) VALUES (:a) RETURNING id',
            [':a' => (int) $user_id > 0 ? (int) $user_id : NULL])['id'] ?? 0);

        try
        {
            $stats = $this->_scan((int) $user_id);
        }
        catch (Throwable $e)
        {
            Prx_Db::execute("UPDATE prx_218_scan_runs SET status = 'error', finished_at = now(), error = :e WHERE id = :id",
                [':e' => mb_substr($e->getMessage(), 0, 1000), ':id' => $run_id]);

            throw $e;
        }

        Prx_Db::execute('UPDATE prx_218_scan_runs SET status = :s, finished_at = now(),
                found = :f, created = :c, updated = :u, skipped = :k, missing = :m,
                synced = :sy, no_agents = :na WHERE id = :id', [
            ':sy' => $stats['synced'],
            ':na' => $stats['no_agents'],
            ':s'  => $stats['skipped'] > 0 ? 'partial' : 'ok',
            ':f'  => $stats['found'],
            ':c'  => $stats['created'],
            ':u'  => $stats['updated'],
            ':k'  => $stats['skipped'],
            ':m'  => $stats['missing'],
            ':id' => $run_id,
        ]);

        $stats['run_id'] = $run_id;

        return $stats;
    }

    /**
     * Что о модуле говорит диск: title/short манифеста (`config/prx_module.php`),
     * есть ли манифест и памятка AGENTS.md. Битый манифест — как отсутствующий.
     *
     * @return array{title:?string, short:?string, has_manifest:bool, has_agents:bool}
     */
    public static function manifest_meta(string $path): array
    {
        $file = $path.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'prx_module.php';
        $data = [];

        if (is_file($file))
        {
            try
            {
                $data = (static fn (string $f) => include $f)($file);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'prx218: manifest :f unreadable: :e', [':f' => $file, ':e' => $e->getMessage()]);
                $data = [];
            }
        }

        $data = is_array($data) ? $data : [];
        $title = trim((string) ($data['title'] ?? ''));
        $short = trim(strip_tags((string) ($data['short'] ?? '')));

        return [
            'title'        => $title !== '' ? mb_substr($title, 0, 255) : NULL,
            'short'        => $short !== '' ? $short : NULL,
            'has_manifest' => $data !== [],
            'has_agents'   => is_file($path.DIRECTORY_SEPARATOR.'AGENTS.md'),
        ];
    }

    /**
     * Какие поля скан вправе обновить из манифеста. Значение считается
     * автоматическим (и обновляется), если оно пусто, равно системному имени,
     * прошлому манифестному или — до первой сверки — тому, что взято из
     * README. Иначе это правка человека (или LLM-описание) — не трогаем.
     *
     * @param  array $prev display_name, description, manifest_title, manifest_short
     * @return array только изменяемые поля: display_name?, description?
     */
    public static function sync_values(array $prev, array $manifest, array $readme, string $system_name): array
    {
        $out = [];
        $name = (string) ($prev['display_name'] ?? '');
        $descr = (string) ($prev['description'] ?? '');

        $auto_names = array_filter([$system_name, $prev['manifest_title'] ?? NULL,
            ($prev['manifest_title'] ?? NULL) === NULL ? ($readme['name'] ?? NULL) : NULL]);
        if ($manifest['title'] !== NULL AND $name !== $manifest['title']
            AND ($name === '' OR in_array($name, $auto_names, TRUE)))
        {
            $out['display_name'] = $manifest['title'];
        }

        $auto_descr = array_filter([$prev['manifest_short'] ?? NULL,
            ($prev['manifest_short'] ?? NULL) === NULL ? ($readme['description'] ?? NULL) : NULL]);
        if ($manifest['short'] !== NULL AND $descr !== $manifest['short']
            AND ($descr === '' OR in_array($descr, $auto_descr, TRUE)))
        {
            $out['description'] = $manifest['short'];
        }

        return $out;
    }

    /**
     * Последние запуски сканера — для страницы сканирования.
     *
     * @return array[] id, started_at, finished_at, actor, status, found, created, updated, skipped, missing, error
     */
    public static function runs(int $limit = 20): array
    {
        return Prx_Db::select('SELECT r.*, u.username AS actor
            FROM prx_218_scan_runs r LEFT JOIN users u ON u.id = r.actor_id
            ORDER BY r.id DESC LIMIT '.max(1, min(200, $limit)));
    }

    /**
     * Три независимых признака модуля — не выводим один из другого:
     *  dir         — папка найдена на диске при последнем скане (реестр);
     *  loaded      — модуль подключён в bootstrap (Kohana::modules(), сейчас);
     *  publication — состояние в каталоге prx110 (approved/wip/archived/…)
     *                или NULL, если у модуля нет манифеста каталога.
     */
    public static function flags(string $system_name, bool $dir_found): array
    {
        $publication = NULL;

        if (class_exists('Service_Prx110_Catalog'))
        {
            try
            {
                $manifest    = Service_Prx110_Catalog::get($system_name);
                $publication = $manifest === NULL ? NULL : (string) Service_Prx110_Catalog::resolve_state($manifest);
            }
            catch (Throwable $e)
            {
                $publication = NULL;
            }
        }

        return [
            'dir'         => $dir_found,
            'loaded'      => array_key_exists($system_name, Kohana::modules()),
            'publication' => $publication,
        ];
    }

    protected function _scan(int $user_id): array
    {
        $config = Kohana::$config->load('prx218_module_manager');
        $modules_path = $config->get('scanner')['path'];
        $excluded = $config->get('excluded_directories');

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'found' => 0, 'missing' => 0, 'synced' => 0, 'no_agents' => 0];
        $seen  = [];

        if ( ! is_dir($modules_path))
        {
            throw new Kohana_Exception('Directory :path not found', [':path' => $modules_path]);
        }

        $directories = new DirectoryIterator($modules_path);

        foreach ($directories as $fileinfo)
        {
            if ($fileinfo->isDot() || ! $fileinfo->isDir())
                continue;

            $system_name = $fileinfo->getFilename();

            if (in_array($system_name, $excluded))
                continue;

            $stats['found']++;
            $seen[] = $system_name;

            $module_path = $fileinfo->getRealPath();
            $metadata = $this->_extract_metadata($module_path);
            $manifest = self::manifest_meta($module_path);
            if ( ! $manifest['has_agents'])
            {
                $stats['no_agents']++;
            }

            try
            {
                [$module, $is_new, $synced] = Prx_Db::transaction(function () use ($system_name, $module_path, $metadata, $manifest, $user_id) {
                    $module = ORM::factory('Prx218_Module')
                        ->where('system_name', '=', $system_name)
                        ->find();

                    $is_new = ! $module->loaded();

                    $data = [
                        'system_name'  => $system_name,
                        'display_name' => mb_substr(($manifest['title'] ?? '') ?: (($metadata['name'] ?? '') ?: $system_name), 0, 255),
                        'description'  => ($manifest['short'] ?? '') ?: ($metadata['description'] ?? NULL),
                        'version'      => Arr::get($metadata, 'version', '1.0.0'),
                        'path'         => $module_path,
                        'created_by'   => $user_id,
                    ];

                    if ($is_new)
                    {
                        $module->values($data)->save();

                        // Создаем запись для LLM анализа
                        $this->_init_llm_analysis($module->id, Arr::get($metadata, 'raw_readme'), $user_id);
                    }
                    else
                    {
                        // Путь — всегда; название/описание — см. sync_values() (ручные не трогаем).
                        $module->path = $module_path;
                        $module->save();
                    }

                    $prev = Prx_Db::one('SELECT display_name, description, manifest_title, manifest_short
                        FROM prx_218_modules WHERE id = :id', [':id' => (int) $module->id]);
                    $sync = $is_new ? [] : self::sync_values($prev, $manifest, $metadata, $system_name);

                    Prx_Db::execute('UPDATE prx_218_modules SET dir_found = TRUE, last_seen_at = now(),
                            display_name = :name, description = :descr,
                            manifest_title = :mt, manifest_short = :ms, has_manifest = :hm, has_agents = :ha
                        WHERE id = :id', [
                        ':name'  => $sync['display_name'] ?? $prev['display_name'],
                        ':descr' => array_key_exists('description', $sync) ? $sync['description'] : $prev['description'],
                        ':mt'    => $manifest['title'],
                        ':ms'    => $manifest['short'],
                        ':hm'    => $manifest['has_manifest'] ? 'true' : 'false',
                        ':ha'    => $manifest['has_agents'] ? 'true' : 'false',
                        ':id'    => (int) $module->id,
                    ]);

                    return [$module, $is_new, $sync !== []];
                });
                $stats[$is_new ? 'created' : 'updated']++;
                if ($synced)
                {
                    $stats['synced']++;
                }

                // После коммита: подписчик читает уже сохранённый модуль.
                if ($is_new)
                {
                    Event::publish('prx218.scanner.module_discovered', [
                        'module_id'   => (int) $module->id,
                        'system_name' => $system_name,
                        'path'        => (string) $module_path,
                    ], $user_id ?: NULL);
                }
            }
            catch (Exception $e)
            {
                Kohana_Exception::log($e);
                $stats['skipped']++;
            }
        }

        // Записи, чьей папки на диске нет: отдельный признак, не удаление.
        // Только записи из сканируемого каталога (по префиксу пути).
        $root   = rtrim((string) (realpath($modules_path) ?: $modules_path), '/').'/';
        $params = [':root' => $root];
        $in     = [];

        foreach (array_values($seen) as $i => $name)
        {
            $in[] = ':n'.$i;
            $params[':n'.$i] = $name;
        }

        $stats['missing'] = Prx_Db::execute('UPDATE prx_218_modules SET dir_found = FALSE
            WHERE dir_found AND is_deleted = 0 AND left(path, length(:root)) = :root'.($in ? ' AND system_name NOT IN ('.implode(', ', $in).')' : ''), $params);

        Audit::log('module_manager', 'scan_completed', NULL, $stats);

        return $stats;
    }

    /**
     * Извлечение метаданных из файлов модуля (README.md и др.)
     * 
     * @param string $path Путь к папке модуля
     * @return array
     */
    protected function _extract_metadata($path)
    {
        $metadata = [
            'name'        => NULL,
            'description' => NULL,
            'version'     => '1.0.0',
            'raw_readme'  => '',
        ];

        $readme_path = $path . DIRECTORY_SEPARATOR . 'README.md';
        
        if (file_exists($readme_path))
        {
            $content = file_get_contents($readme_path);
            $metadata['raw_readme'] = $content;

            // Простейший парсинг заголовка H1 как названия
            if (preg_match('/^#\s+(.+)$/m', $content, $matches))
            {
                $metadata['name'] = trim($matches[1]);
            }

            // Простейший парсинг версии
            if (preg_match('/Version:\s*([0-9\.]+)/i', $content, $matches))
            {
                $metadata['version'] = trim($matches[1]);
            }

            // Описание (первый абзац после заголовка)
            $lines = explode("\n", $content);
            foreach ($lines as $line)
            {
                $line = trim($line);
                if ( ! empty($line) && $line[0] !== '#')
                {
                    $metadata['description'] = Text::limit_chars($line, 250);
                    break;
                }
            }
        }

        return $metadata;
    }

    /**
     * Инициализация записи для последующего LLM анализа
     * 
     * @param int $module_id
     * @param string $content
     * @param int $user_id
     */
    protected function _init_llm_analysis($module_id, $content, $user_id)
    {
        if (empty($content)) return;

        ORM::factory('Prx218_Llm')
            ->values([
                'module_id'       => $module_id,
                'raw_content'     => $content,
                'analysis_status' => 'pending',
                'created_by'      => $user_id
            ])
            ->save();
    }

    /**
     * Регистрация зависимости между модулями
     * 
     * @param array $data [module_id, required_module_id, min_version]
     * @param int $user_id
     * @return Model_Prx218_Dependency
     * @throws Validation_Exception
     */
    public function add_dependency(array $data, $user_id)
    {
        $validation = Validation::factory($data)
            ->rule('module_id', 'not_empty')
            ->rule('required_module_id', 'not_empty')
            ->rule('module_id', 'digit')
            ->rule('required_module_id', 'digit');

        if ( ! $validation->check())
        {
            throw new Validation_Exception($validation);
        }

        try
        {
            $dependency = Prx_Db::transaction(function () use ($data, $user_id) {
                $dependency = ORM::factory('Prx218_Dependency');
                $dependency_values = [
                    'module_id'          => $data['module_id'],
                    'required_module_id' => $data['required_module_id'],
                    'min_version'        => Arr::get($data, 'min_version'),
                    'created_by'         => $user_id
                ];
                $dependency->values($dependency_values)->save();

                return $dependency;
            });

            Event::publish('prx218.dependency.added', [
                'dependency_id'      => (int) $dependency->id,
                'module_id'          => (int) $data['module_id'],
                'required_module_id' => (int) $data['required_module_id'],
                'min_version'        => (string) Arr::get($data, 'min_version', ''),
            ], (int) $user_id ?: NULL);
            
            return $dependency;
        }
        catch (Exception $e)
        {
            throw $e;
        }
    }

    /**
     * Обновление статуса модуля.
     *
     * @param int $module_id ID модуля
     * @param string $status Новый статус (alpha, beta, stable, archived)
     * @return bool
     */
    public function update_status($module_id, $status)
    {
        $module = ORM::factory('Prx218_Module', $module_id);

        if ( ! $module->loaded())
        {
            return FALSE;
        }

        // Проверка на допустимые статусы
        $allowed_statuses = ['alpha', 'beta', 'stable', 'archived'];
        if ( ! in_array($status, $allowed_statuses))
        {
            Kohana_Exception::log(new Kohana_Exception('Invalid module status: :status', [':status' => $status]));
            return FALSE;
        }

        $module->status = $status;
        try
        {
            $module->save();
            return TRUE;
        }
        catch (Exception $e)
        {
            Kohana_Exception::log($e);
            return FALSE;
        }
    }

    /**
     * Запуск сканирования директории модулей.
     * Этот метод может быть вызван извне, например, из контроллера API.
     *
     * @param int $user_id ID пользователя, инициировавшего сканирование
     * @return array Статистика сканирования
     */
    public function scan_modules_directory($user_id = 0)
    {
        return $this->scan_directory($user_id);
    }
}