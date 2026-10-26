<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Слияние каталога с официальным реестром MCP (nj676_mcphub).
 *
 * Реестр — registry.modelcontextprotocol.io — отдаёт структурированные
 * server.json: имя вида io.github.owner/repo, версию, репозиторий, пакеты
 * (npm/PyPI/OCI) и удалённые адреса. Это единственный источник, которому можно
 * верить без разбора README, поэтому карточки из него создаются автоматически
 * и обновляются повторным импортом.
 *
 * Правила, без которых каталог превращается в свалку:
 *   * одна запись реестра — одна карточка (уникальный registry_name);
 *   * карточку ищём ещё и по репозиторию и по удалённому адресу: реестр любит
 *     публиковать один и тот же проект под разными именами;
 *   * текст, написанный человеком (origin = manual), импорт не перезаписывает;
 *   * is_published берётся из NJ676_AUTO_PUBLISH — владелец может сначала
 *     копить карточки, а публиковать выборочно.
 *
 * Импорт идёт пачками от воркера (канал nj676.registry): сайт не делает
 * длинных HTTP-выборок в ответе на запрос пользователя.
 */
class Service_Nj676_Registry
{
    const MAX_BATCH = 100;

    /** Ключевые слова категорий — те же, что у воркера и у разбора README. */
    const CATEGORY_HINTS = [
        'development' => ['git', 'github', 'gitlab', 'code', 'docker', 'kubernetes', 'devops', 'ide', 'lint', 'test', 'build', 'repository', 'npm', 'pypi', 'sdk', 'compiler', 'debug'],
        'web' => ['web', 'search', 'browser', 'scrap', 'crawl', 'http', 'fetch', 'url', 'seo', 'playwright', 'http request', 'browsing'],
        'data' => ['database', 'postgres', 'mysql', 'sqlite', 'mongo', 'sql', 'vector', 'etl', 'bigquery', 'snowflake', 'duckdb', 'redis', 'kafka', 'dataset', 'analytics'],
        'ai' => ['llm', 'openai', 'anthropic', 'claude', 'model', 'embedding', 'rag', 'agent', 'inference', 'gemini', 'mistral', 'huggingface', 'prompt'],
        'files' => ['file', 'filesystem', 'pdf', 'docx', 'office', 'excel', 'csv', 'document', 'storage', 'drive', 's3'],
        'productivity' => ['notion', 'todo', 'calendar', 'task', 'productivity', 'notes', 'asana', 'trello', 'linear', 'jira', 'slack', 'workspace'],
        'cloud' => ['aws', 'azure', 'gcp', 'cloudflare', 'kubernetes', 'docker', 'terraform', 'serverless', 'cloud', 'lambda'],
        'communication' => ['email', 'mail', 'gmail', 'smtp', 'imap', 'slack', 'telegram', 'discord', 'whatsapp', 'teams', 'messenger', 'sms'],
        'finance' => ['finance', 'payment', 'stripe', 'bank', 'invoice', 'crypto', 'trading', 'accounting', 'billing', 'paypal'],
        'security' => ['security', 'vulnerability', 'cve', 'audit', 'secret', 'pentest', 'malware', 'sentry', 'scanner', 'owasp'],
        'media' => ['image', 'video', 'audio', 'youtube', 'music', 'photo', 'media', 'transcri', 'ffmpeg', 'podcast'],
        'research' => ['research', 'arxiv', 'paper', 'scholar', 'science', 'wikipedia', 'weather', 'knowledge', 'news', 'academic'],
    ];

    /**
     * Приёмка пачки карточек от воркера.
     *
     * @return array{added:int,updated:int,skipped:int,total:int}
     */
    public static function apply_batch(array $items, $run_id = 0)
    {
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];
        $touched_slugs = [];

        foreach (array_slice($items, 0, self::MAX_BATCH) as $item)
        {
            if ( ! is_array($item))
            {
                $stats['skipped']++;
                continue;
            }

            $stats['total']++;

            try
            {
                $result = self::apply($item, (int) $run_id, FALSE);

                if ($result['status'] === 'added')
                {
                    $stats['added']++;
                    if ( ! empty($result['slug']))
                    {
                        $touched_slugs[] = (string) $result['slug'];
                    }
                }
                elseif ($result['status'] === 'updated')
                {
                    $stats['updated']++;
                    if ( ! empty($result['slug']))
                    {
                        $touched_slugs[] = (string) $result['slug'];
                    }
                }
                else
                {
                    $stats['skipped']++;
                }
            }
            catch (Throwable $e)
            {
                $stats['skipped']++;
                Log_Plain::event(Log::WARNING, 'nj676: карточка реестра не принята: :e',
                    [':e' => $e->getMessage()]);
            }
        }

        if ($touched_slugs !== [])
        {
            if (class_exists('Service_Nj676_McpHub'))
            {
                Service_Nj676_McpHub::reset_ready_cache();
            }
            if (class_exists('Service_Nj676_Semantic'))
            {
                try
                {
                    Service_Nj676_Semantic::index_slugs(array_values(array_unique($touched_slugs)));
                }
                catch (Throwable $e) {}
            }
        }

        return $stats;
    }

    /**
     * Одна карточка реестра: обновить существующую или создать.
     *
     * @return array{status:string,slug:string,message:string}
     */
    public static function apply(array $item, $run_id = 0, $queue_index = TRUE)
    {
        $registry_name = self::_str($item['registry_name'] ?? '', 200);

        if ($registry_name === '' AND self::_str($item['repo_url'] ?? '') === ''
            AND self::_str($item['remote_url'] ?? '') === '')
        {
            return ['status' => 'skipped', 'slug' => '', 'message' => 'Нет ни имени, ни адреса'];
        }

        $repo_url = self::_url($item['repo_url'] ?? '');
        $remote_url = self::_url($item['remote_url'] ?? '');

        // Приёмка адреса: https + публичный хост (то же правило, что при вызове).
        // Иначе в каталоге появляется карточка «вызов сразу», которая падает
        // UPSTREAM_ERROR на первом же обращении (тест 24.10.2026).
        [$url_valid, $url_note] = Service_Nj676_McpHub::endpoint_check($remote_url);
        $url_bad = $url_valid === FALSE ? 1 : 0;
        $homepage = self::_url($item['homepage_url'] ?? '');
        $name = self::_str($item['name'] ?? '', 160);
        $description = self::_str($item['description'] ?? '', 2000);

        if ($name === '')
        {
            $name = self::_name_from($registry_name, $repo_url, $remote_url);
        }

        if ($description === '')
        {
            $description = 'MCP-сервер «'.$name.'» из официального реестра. Описание готовится.';
        }

        $transport = self::_enum($item['transport'] ?? '', array_keys(Service_Nj676_McpHub::TRANSPORTS), 'unknown');
        $auth_mode = self::_enum($item['auth_mode'] ?? '', array_keys(Service_Nj676_McpHub::AUTH), 'unknown');
        $categories = self::categories($item, $name.' '.$description);

        $packages = [];
        foreach ((array) ($item['packages'] ?? []) as $package)
        {
            if ( ! is_array($package))
            {
                continue;
            }

            $manager = self::_enum($package['manager'] ?? $package['registryType'] ?? '', ['npm', 'pypi', 'oci'], '');
            $identifier = self::_str($package['identifier'] ?? $package['name'] ?? '', 200);

            if ($manager === '' OR $identifier === '')
            {
                continue;
            }

            $packages[] = [
                'manager' => $manager,
                'identifier' => $identifier,
                'version' => self::_str($package['version'] ?? '', 60),
                'transport' => 'stdio',
            ];

            if (count($packages) >= 4)
            {
                break;
            }
        }

        if ($transport === 'unknown' AND $remote_url !== '')
        {
            $transport = 'streamable_http';
        }
        if ($transport === 'unknown' AND $packages !== [])
        {
            $transport = 'stdio';
        }

        $env_keys = [];
        foreach ((array) ($item['env_keys'] ?? []) as $key)
        {
            $key = self::_str($key, 80);
            if ($key !== '' AND preg_match('/^[A-Z][A-Z0-9_]{2,79}$/', $key))
            {
                $env_keys[] = $key;
            }
        }
        $env_keys = array_slice(array_values(array_unique($env_keys)), 0, 12);

        if ($auth_mode === 'unknown')
        {
            $auth_mode = $env_keys !== [] ? 'api_key' : 'none';
        }

        $raw_trust = isset($item['trust']) && (int) $item['trust'] > 0 ? (int) $item['trust'] : NULL;
        $default_trust = 40 + ($remote_url !== '' && $auth_mode === 'none' ? 10 : 0);

        $record = [
            'registry_name' => $registry_name,
            'registry_version' => self::_str($item['version'] ?? '', 60),
            'name' => $name,
            'description' => $description,
            'repo_url' => $repo_url,
            'homepage_url' => $homepage !== '' ? $homepage : $remote_url,
            'transport' => $transport,
            'auth_mode' => $auth_mode,
            'categories' => $categories,
            'packages' => $packages,
            'env_keys' => $env_keys,
            'remote_url' => $remote_url,
            'remote_kind' => $remote_url !== '' ? 'streamable_http' : '',
            'source' => strpos($repo_url, 'https://github.com/') === 0 ? 'github' : ($remote_url !== '' ? 'native' : 'manual'),
            'trust' => self::_int($raw_trust, $default_trust, 0, 100),
            'stars' => self::_int($item['stars'] ?? 0, 0, 0, 10000000),
            'origin' => 'registry',
            'run_id' => (int) $run_id,
        ];

        // 1. По имени в реестре — самый точный ключ.
        $row = NULL;
        if ($registry_name !== '')
        {
            $row = self::_first(
                'SELECT id, slug, origin FROM nj_676_servers WHERE registry_name = :rn LIMIT 1',
                [':rn' => $registry_name]
            );
        }

        // 2. По репозиторию: реестр и наш автосбор часто описывают один проект.
        if ( ! $row AND $repo_url !== '')
        {
            $row = self::_first(
                'SELECT id, slug, origin FROM nj_676_servers WHERE lower(repo_url) = :repo LIMIT 1',
                [':repo' => mb_strtolower(rtrim($repo_url, '/'))]
            );
        }

        // 3. По удалённому адресу: у сервера может не быть публичного репозитория.
        if ( ! $row AND $remote_url !== '')
        {
            $row = self::_first(
                'SELECT id, slug, origin FROM nj_676_servers WHERE lower(remote_url) = :remote LIMIT 1',
                [':remote' => mb_strtolower(rtrim($remote_url, '/'))]
            );
        }

        $slug = $row ? (string) $row['slug'] : self::slug_for($registry_name, $repo_url, $remote_url);
        $record['slug'] = $slug;
        $record['search_text'] = self::search_text($record);

        $published = (string) getenv('NJ676_AUTO_PUBLISH') !== '0';

        if ($row)
        {
            $sql = 'UPDATE nj_676_servers SET
                        registry_name = CAST(:rn AS varchar),
                        registry_version = :rv,
                        synced_at = now(),
                        remote_url = COALESCE(NULLIF(CAST(:remote AS text), \'\'), remote_url),
                        remote_kind = CASE WHEN CAST(:remote AS text) <> \'\'
                                           THEN \'streamable_http\' ELSE remote_kind END,
                        transport = CASE WHEN CAST(:transport AS text) <> \'unknown\'
                                         THEN CAST(:transport AS text) ELSE transport END,
                        auth_mode = CASE WHEN CAST(:auth AS text) <> \'unknown\'
                                         THEN CAST(:auth AS text) ELSE auth_mode END,
                        repo_url = CASE WHEN repo_url = \'\' THEN CAST(:repo AS text) ELSE repo_url END,
                        homepage_url = COALESCE(NULLIF(homepage_url, \'\'), CAST(:home AS text)),
                        packages = CASE WHEN jsonb_array_length(CASE WHEN jsonb_typeof(packages) = \'array\' THEN packages ELSE \'[]\'::jsonb END) = 0
                                        THEN CAST(:packages AS jsonb) ELSE packages END,
                        env_keys = CASE WHEN jsonb_array_length(CASE WHEN jsonb_typeof(env_keys) = \'array\' THEN env_keys ELSE \'[]\'::jsonb END) = 0
                                        THEN CAST(:env_keys AS jsonb) ELSE env_keys END,
                        categories = CASE WHEN jsonb_array_length(CASE WHEN jsonb_typeof(categories) = \'array\' THEN categories ELSE \'[]\'::jsonb END) = 0
                                          THEN CAST(:categories AS jsonb) ELSE categories END,
                        name = CASE WHEN origin = \'manual\' OR name <> \'\' THEN name
                                    ELSE CAST(:name AS varchar) END,
                        description = CASE WHEN origin = \'manual\' OR length(description) > 30
                                           THEN description ELSE CAST(:description AS text) END,
                        url_valid = CASE WHEN CAST(:url_bad AS integer) = 1 THEN FALSE ELSE url_valid END,
                        url_note = CASE WHEN CAST(:url_bad AS integer) = 1
                                        THEN CAST(:url_note AS varchar) ELSE url_note END,
                        health_status = CASE WHEN CAST(:url_bad AS integer) = 1 THEN \'offline\'
                                             ELSE health_status END,
                        health_note = CASE WHEN CAST(:url_bad AS integer) = 1
                                           THEN CAST(:url_note AS varchar) ELSE health_note END,
                        stars = GREATEST(stars, CAST(:stars AS integer)),
                        search_text = CASE
                            WHEN name_ru <> \'\' OR description_ru <> \'\' OR jsonb_array_length(CASE WHEN jsonb_typeof(tools) = \'array\' THEN tools ELSE \'[]\'::jsonb END) > 0
                            THEN CASE WHEN position(lower(slug) in lower(coalesce(search_text, \'\'))) > 0
                                      THEN search_text
                                      ELSE trim(slug || \' \' || replace(slug, \'-\', \' \') || \' \' || coalesce(:rn, \'\') || \' \' || coalesce(search_text, \'\'))
                                 END
                            ELSE :search_text
                        END,
                        is_published = (is_published OR :published),
                        updated_at = now()
                      WHERE id = :id';

            self::_query($sql, [
                ':rn' => $registry_name,
                ':rv' => $record['registry_version'],
                ':remote' => $remote_url,
                ':transport' => $record['transport'],
                ':auth' => $record['auth_mode'],
                ':repo' => $repo_url,
                ':home' => $record['homepage_url'],
                ':packages' => json_encode($packages, JSON_UNESCAPED_UNICODE),
                ':env_keys' => json_encode($env_keys, JSON_UNESCAPED_UNICODE),
                ':categories' => json_encode($categories, JSON_UNESCAPED_UNICODE),
                ':name' => $name,
                ':description' => $description,
                ':stars' => $record['stars'],
                ':search_text' => $record['search_text'],
                ':published' => $published,
                ':url_bad' => $url_bad,
                ':url_note' => mb_substr((string) $url_note, 0, 200),
                ':id' => (int) $row['id'],
            ]);

            if ($queue_index AND class_exists('Service_Nj676_Semantic'))
            {
                try
                {
                    Service_Nj676_Semantic::index_slugs([(string) $row['slug']]);
                }
                catch (Throwable $e) {}
            }

            return ['status' => 'updated', 'slug' => (string) $row['slug'], 'message' => ''];
        }

        $insert = 'INSERT INTO nj_676_servers
                (slug, registry_name, registry_version, synced_at, name, description,
                 repo_url, homepage_url, source, transport, auth_mode, categories,
                 health_status, health_note, packages, env_keys, remote_url, remote_kind,
                 url_valid, url_note,
                 stars, run_id, search_text, origin, trust, is_published, updated_at)
                VALUES
                (:slug, :rn, :rv, now(), :name, :description, :repo, :home, :source,
                 :transport, :auth, CAST(:categories AS jsonb),
                 CASE WHEN CAST(:url_bad AS integer) = 1 THEN \'offline\' ELSE \'unverified\' END,
                 CASE WHEN CAST(:url_bad AS integer) = 1 THEN CAST(:url_note AS varchar)
                      ELSE \'Импорт из официального реестра\' END,
                 CAST(:packages AS jsonb),
                 CAST(:env_keys AS jsonb), :remote, :remote_kind,
                 CASE WHEN CAST(:url_bad AS integer) = 1 THEN FALSE ELSE NULL END,
                 CAST(:url_note AS varchar), :stars, :run_id,
                 :search_text, \'registry\', :trust, :published, now())
                ON CONFLICT (slug) DO NOTHING
                RETURNING id, slug';

        $created = self::_first($insert, [
            ':slug' => $slug,
            ':rn' => $registry_name,
            ':rv' => $record['registry_version'],
            ':name' => $name,
            ':description' => $description,
            ':repo' => $repo_url,
            ':home' => $record['homepage_url'],
            ':source' => $record['source'],
            ':transport' => $record['transport'],
            ':auth' => $record['auth_mode'],
            ':categories' => json_encode($categories, JSON_UNESCAPED_UNICODE),
            ':packages' => json_encode($packages, JSON_UNESCAPED_UNICODE),
            ':env_keys' => json_encode($env_keys, JSON_UNESCAPED_UNICODE),
            ':remote' => $remote_url,
            ':remote_kind' => $record['remote_kind'],
            ':url_bad' => $url_bad,
            ':url_note' => mb_substr((string) $url_note, 0, 200),
            ':stars' => $record['stars'],
            ':run_id' => (int) $run_id,
            ':search_text' => $record['search_text'],
            ':trust' => $record['trust'],
            ':published' => $published,
        ]);

        if ( ! $created)
        {
            return ['status' => 'skipped', 'slug' => $slug, 'message' => 'Слаг уже занят другой карточкой'];
        }

        if ($queue_index AND class_exists('Service_Nj676_Semantic'))
        {
            try
            {
                Service_Nj676_Semantic::index_slugs([(string) $created['slug']]);
            }
            catch (Throwable $e) {}
        }

        return ['status' => 'added', 'slug' => (string) $created['slug'], 'message' => ''];
    }

    /** Категории: сначала из реестра, иначе по словам названия и описания. */
    public static function categories(array $item, $text)
    {
        $allowed = array_keys(Service_Nj676_McpHub::CATEGORIES);
        $out = [];

        foreach ((array) ($item['categories'] ?? []) as $category)
        {
            $category = mb_strtolower(self::_str($category, 40));
            if (in_array($category, $allowed, TRUE))
            {
                $out[] = $category;
            }
        }

        if ($out === [])
        {
            $haystack = mb_strtolower((string) $text);

            foreach (self::CATEGORY_HINTS as $category => $words)
            {
                foreach ($words as $word)
                {
                    if (mb_strpos($haystack, $word) !== FALSE)
                    {
                        $out[] = $category;
                        break;
                    }
                }

                if (count($out) >= 3)
                {
                    break;
                }
            }
        }

        if ($out === [])
        {
            $out[] = 'development';
        }

        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    /** Слаг: имя реестра → owner-repo, без «io.github.» префикса. */
    public static function slug_for($registry_name, $repo_url, $remote_url = '')
    {
        $base = '';

        if ($registry_name !== '')
        {
            $base = preg_replace('~^[a-z]{2}\.[a-z]+\.~i', '', (string) $registry_name);
            $base = str_replace(['/', '@'], '-', (string) $base);
        }

        if ($base === '' OR mb_strlen($base) < 3)
        {
            $base = Service_Nj676_McpHub::slug_from_repo($repo_url !== '' ? $repo_url : (string) $remote_url);
        }

        if ($base === '' OR mb_strlen($base) < 3)
        {
            $base = 'mcp-server';
        }

        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($base)), '-');
        $base = mb_substr($base, 0, 90);

        foreach ([$base, $base.'-2', $base.'-3', $base.'-4'] as $candidate)
        {
            $row = self::_first('SELECT 1 AS x FROM nj_676_servers WHERE slug = :slug LIMIT 1',
                [':slug' => $candidate]);

            if ( ! $row)
            {
                return $candidate;
            }
        }

        return $base.'-'.substr(sha1($registry_name.$remote_url.$repo_url), 0, 6);
    }

    /** Статистика импорта: сколько карточек из реестра и когда был последний. */
    public static function stats()
    {
        $row = self::_first(
            "SELECT count(*) AS total,
                    count(*) FILTER (WHERE origin = 'registry') AS from_registry,
                    count(*) FILTER (WHERE remote_url <> '') AS remote,
                    count(*) FILTER (WHERE featured) AS featured,
                    max(synced_at) AS last_sync
               FROM nj_676_servers WHERE is_published = TRUE"
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'from_registry' => (int) ($row['from_registry'] ?? 0),
            'remote' => (int) ($row['remote'] ?? 0),
            'featured' => (int) ($row['featured'] ?? 0),
            'last_sync' => (string) ($row['last_sync'] ?? ''),
        ];
    }

    protected static function search_text(array $record)
    {
        $categories_ru = [];
        foreach ((array) ($record['categories'] ?? []) as $cat)
        {
            if (isset(Service_Nj676_McpHub::CATEGORIES[$cat]))
            {
                $categories_ru[] = Service_Nj676_McpHub::CATEGORIES[$cat];
            }
        }

        $slug = (string) ($record['slug'] ?? '');
        $registry_name = (string) ($record['registry_name'] ?? '');

        $text = implode(' ', [
            $slug,
            str_replace(['-', '_'], ' ', $slug),
            (string) ($record['name'] ?? ''),
            (string) ($record['name_ru'] ?? ''),
            (string) ($record['description'] ?? ''),
            (string) ($record['description_ru'] ?? ''),
            $registry_name,
            str_replace(['/', '.', '-', '_'], ' ', $registry_name),
            implode(' ', (array) ($record['categories'] ?? [])),
            implode(' ', $categories_ru),
            implode(' ', array_map(static function ($package) {
                return (string) ($package['name'] ?? $package['identifier'] ?? '');
            }, (array) ($record['packages'] ?? []))),
        ]);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 4000);
    }

    // ------------------------------------------------------------------
    // Мелкие помощники: всё, что пришло от воркера, — недоверенные данные.
    // ------------------------------------------------------------------

    protected static function _query($sql, array $params = [])
    {
        $query = DB::query(Database::SELECT, $sql);
        foreach ($params as $key => $value)
        {
            $query->param($key, $value);
        }

        return $query->execute();
    }

    protected static function _first($sql, array $params = [])
    {
        $row = self::_query($sql, $params)->current();

        return $row ? (array) $row : NULL;
    }

    protected static function _str($value, $cap = 0)
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return $cap > 0 ? mb_substr($value, 0, $cap) : $value;
    }

    /**
     * Человеческое имя для записи реестра, у которой нет title.
     *
     * Реестр отдаёт имена вида `io.github.owner/cool-server`; показывать это
     * в каталоге нельзя, а падать на пустом имени — тем более (была живая
     * 500-ка: метод вызывался, но не существовал).
     */
    protected static function _name_from($registry_name, $repo_url = '', $remote_url = '')
    {
        $registry_name = trim((string) $registry_name);

        if ($registry_name !== '')
        {
            $tail = $registry_name;
            if (strpos($tail, '/') !== FALSE)
            {
                $tail = substr($tail, strrpos($tail, '/') + 1);
            }
            // `io.github.owner.cool-server` (точки в реверс-DNS имени) — берём
            // последний осмысленный сегмент, а не весь идентификатор.
            $tail = preg_replace('/^[a-z]{2}\.[a-z.]+?\./i', '', $tail);
            $tail = str_replace(['-', '_'], ' ', (string) $tail);

            if (trim($tail) !== '')
            {
                return mb_substr(ucfirst(trim($tail)), 0, 160, 'UTF-8');
            }
        }

        foreach (['repo_url' => (string) $repo_url, 'remote_url' => (string) $remote_url] as $url)
        {
            if ($url === '')
            {
                continue;
            }

            $path = (string) parse_url($url, PHP_URL_PATH);
            $segments = array_values(array_filter(explode('/', $path), 'strlen'));

            if ($segments !== [])
            {
                return mb_substr(str_replace(['-', '_'], ' ', end($segments)), 0, 160, 'UTF-8');
            }

            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host !== '')
            {
                return mb_substr($host, 0, 160, 'UTF-8');
            }
        }

        return 'MCP-сервер';
    }

    protected static function _url($value)
    {
        $value = self::_str($value);

        if ($value === '' OR filter_var($value, FILTER_VALIDATE_URL) === FALSE)
        {
            return '';
        }

        return strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https' ? $value : '';
    }

    protected static function _enum($value, array $allowed, $default)
    {
        $value = self::_str($value);

        return in_array($value, $allowed, TRUE) ? $value : $default;
    }

    protected static function _int($value, $default, $min, $max)
    {
        $value = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $value));
    }
}
