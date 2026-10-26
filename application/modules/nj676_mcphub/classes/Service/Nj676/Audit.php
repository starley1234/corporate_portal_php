<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Полевое исследование внешних открытых MCP-серверов воркером-агентом (`nj676.audit`).
 *
 * Воркер-агент подключается к доступным открытым MCP-серверам каталога,
 * проверяет `tools/list`, анализирует схемы и безопасность (инъекции в описаниях,
 * опасные мутации, утечки секретов), делает безопасный пробный вызов `tools/call`
 * read-only инструмента и составляет краткий экспертный отчёт:
 *   - utility_score (0..100) — реальная практическая польза для ИИ-агента;
 *   - safety_score (0..100)  — безопасность схем, описаний и ответов;
 *   - verdict                — gem («алмаз»), useful, niche, questionable, broken;
 *   - is_gem                 — отметка «алмаз каталога» (рекомендован к использованию);
 *   - summary                — краткий отчёт по итогам живого исследования;
 *   - use_cases              — реальные сценарии применения для агентов;
 *   - safety_notes           — замечания по безопасности;
 *   - observations           — интересные и важные наблюдения из живого вызова;
 *   - tested_tools           — результаты пробных вызовов инструментов.
 *
 * Отчёт сохраняется в `nj_676_audits` и в карточку `nj_676_servers`, индексируется
 * в полнотекстовом (`search_text`) и векторном (`Service_Nj676_Semantic`) поиске
 * и участвует в ранжировании каталога и MCP-шлюза.
 */
class Service_Nj676_Audit
{
    const CHANNEL = 'nj676.audit';
    const DEFAULT_BATCH = 6;
    const MAX_BATCH = 12;
    const STALE_DAYS = 14;

    const VERDICTS = [
        'gem' => ['Алмаз каталога', 'emerald'],
        'useful' => ['Полезен на практике', 'blue'],
        'niche' => ['Узкоспециальный', 'gray'],
        'questionable' => ['Сомнительная польза', 'amber'],
        'broken' => ['Не работает вживую', 'rose'],
    ];

    /**
     * Выбор открытых удалённых MCP-серверов для полевого исследования.
     *
     * Приоритет:
     *   1. Явно запрошенный slug (если передан);
     *   2. Ни разу не исследованные (`audited_at IS NULL`) открытые рабочие серверы;
     *   3. Давно не исследованные (`audited_at < now() - 14 days`), по убыванию trust и stars.
     *
     * @param  int    $limit
     * @param  string $slug
     * @param  array  $exclude
     * @return array<int,array<string,mixed>>
     */
    public static function targets($limit = self::DEFAULT_BATCH, $slug = '', array $exclude = [])
    {
        $limit = max(1, min(self::MAX_BATCH, (int) $limit));
        $slug = trim((string) $slug);

        try
        {
            if ($slug !== '')
            {
                $rows = DB::query(Database::SELECT,
                    "SELECT slug, name, description, name_ru, description_ru,
                            remote_url, auth_mode, categories, tools, stars, trust
                       FROM nj_676_servers
                      WHERE slug = :slug
                        AND is_published = TRUE
                        AND remote_url <> ''
                      LIMIT 1")
                    ->param(':slug', $slug)
                    ->execute()
                    ->as_array();
            }
            else
            {
                $params = [
                    ':stale_days' => self::STALE_DAYS,
                    ':limit' => $limit,
                ];
                $excl_sql = '';
                $clean_excl = [];
                foreach (array_slice($exclude, 0, 120) as $idx => $ex_slug)
                {
                    $ex_slug = trim((string) $ex_slug);
                    if ($ex_slug !== '' AND preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $ex_slug))
                    {
                        $k = ':ex'.$idx;
                        $clean_excl[] = $k;
                        $params[$k] = $ex_slug;
                    }
                }
                if ($clean_excl !== [])
                {
                    $excl_sql = ' AND slug NOT IN ('.implode(', ', $clean_excl).')';
                }

                $query = DB::query(Database::SELECT,
                    "SELECT slug, name, description, name_ru, description_ru,
                            remote_url, auth_mode, categories, tools, stars, trust
                       FROM nj_676_servers
                      WHERE is_published = TRUE
                        AND remote_url <> ''
                        AND COALESCE(url_valid, TRUE) = TRUE
                        AND auth_mode IN ('none', 'unknown')
                        AND health_status IN ('operational', 'unverified')
                        AND COALESCE(probe_failures, 0) < 10
                        AND (audited_at IS NULL OR audited_at < now() - make_interval(days => :stale_days))"
                        .$excl_sql."
                      ORDER BY (audited_at IS NULL) DESC,
                               (health_status = 'operational') DESC,
                               audited_at ASC NULLS FIRST,
                               trust DESC,
                               stars DESC,
                               id ASC
                      LIMIT :limit");

                foreach ($params as $k => $v)
                {
                    $query->param($k, $v);
                }

                $rows = $query->execute()->as_array();
            }
        }
        catch (Throwable $e)
        {
            return [];
        }

        $out = [];
        foreach ($rows as $row)
        {
            $url = trim((string) ($row['remote_url'] ?? ''));
            [$valid] = Service_Nj676_McpHub::endpoint_check($url);
            if ($valid !== TRUE)
            {
                continue;
            }

            $tools = $row['tools'] ?? [];
            if (is_string($tools))
            {
                $decoded = json_decode($tools, TRUE);
                $tools = is_array($decoded) ? $decoded : [];
            }

            $categories = $row['categories'] ?? [];
            if (is_string($categories))
            {
                $decoded = json_decode($categories, TRUE);
                $categories = is_array($decoded) ? $decoded : [];
            }

            $out[] = [
                'slug' => (string) ($row['slug'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'name_ru' => (string) ($row['name_ru'] ?? ''),
                'description_ru' => (string) ($row['description_ru'] ?? ''),
                'url' => $url,
                'auth_mode' => (string) ($row['auth_mode'] ?? 'none'),
                'categories' => is_array($categories) ? array_values($categories) : [],
                'tools' => is_array($tools) ? array_slice(array_values($tools), 0, 30) : [],
                'stars' => (int) ($row['stars'] ?? 0),
                'trust' => (int) ($row['trust'] ?? 50),
            ];
        }

        return $out;
    }

    /**
     * Сводка охвата полевыми исследованиями и найденных «алмазов».
     *
     * @return array<string,int|float>
     */
    public static function backlog()
    {
        $defaults = [
            'open_total' => 0,
            'audited' => 0,
            'unaudited' => 0,
            'stale' => 0,
            'gems' => 0,
            'avg_utility' => 0,
            'avg_safety' => 0,
            ' stale_days' => self::STALE_DAYS,
            'stale_days' => self::STALE_DAYS,
        ];

        try
        {
            $row = DB::query(Database::SELECT,
                "SELECT count(*) FILTER (
                            WHERE is_published = TRUE
                              AND remote_url <> ''
                              AND COALESCE(url_valid, TRUE) = TRUE
                              AND auth_mode IN ('none', 'unknown')
                              AND health_status IN ('operational', 'unverified')
                        ) AS open_total,
                        count(*) FILTER (WHERE audited_at IS NOT NULL) AS audited,
                        count(*) FILTER (
                            WHERE is_published = TRUE
                              AND remote_url <> ''
                              AND COALESCE(url_valid, TRUE) = TRUE
                              AND auth_mode IN ('none', 'unknown')
                              AND health_status IN ('operational', 'unverified')
                              AND audited_at IS NULL
                        ) AS unaudited,
                        count(*) FILTER (
                            WHERE is_published = TRUE
                              AND remote_url <> ''
                              AND COALESCE(url_valid, TRUE) = TRUE
                              AND auth_mode IN ('none', 'unknown')
                              AND health_status IN ('operational', 'unverified')
                              AND audited_at IS NOT NULL
                              AND audited_at < now() - make_interval(days => :stale_days)
                        ) AS stale,
                        count(*) FILTER (WHERE is_published = TRUE AND is_gem = TRUE) AS gems,
                        COALESCE(round(avg(audit_score) FILTER (WHERE audited_at IS NOT NULL AND audit_score > 0)), 0) AS avg_utility,
                        COALESCE(round(avg(safety_score) FILTER (WHERE audited_at IS NOT NULL AND safety_score > 0)), 0) AS avg_safety
                   FROM nj_676_servers")
                ->param(':stale_days', self::STALE_DAYS)
                ->execute()
                ->current();

            if ( ! is_array($row))
            {
                return $defaults;
            }

            return [
                'open_total' => (int) ($row['open_total'] ?? 0),
                'audited' => (int) ($row['audited'] ?? 0),
                'unaudited' => (int) ($row['unaudited'] ?? 0),
                'stale' => (int) ($row['stale'] ?? 0),
                'gems' => (int) ($row['gems'] ?? 0),
                'avg_utility' => (int) ($row['avg_utility'] ?? 0),
                'avg_safety' => (int) ($row['avg_safety'] ?? 0),
                'stale_days' => self::STALE_DAYS,
            ];
        }
        catch (Throwable $e)
        {
            return $defaults;
        }
    }

    /**
     * Поставить в очередь полевое исследование открытых MCP-серверов воркером-агентом.
     *
     * @param  int    $limit
     * @param  string $slug    конкретный сервер или пусто (пачка по очереди)
     * @param  int    $user_id
     * @return array{queued:int,run_id:int,job_id:int,busy:bool,targets:array}
     */
    public static function queue($limit = self::DEFAULT_BATCH, $slug = '', $user_id = 0)
    {
        $slug = trim((string) $slug);
        $limit = max(1, min(self::MAX_BATCH, (int) $limit));

        if ($slug === '')
        {
            $running = Service_Nj676_McpHub::latest_run_of_kind('audit');
            if ($running AND ($running['status'] ?? '') === 'running'
                AND strtotime((string) ($running['started_at'] ?? '')) > time() - 1800)
            {
                return [
                    'queued' => 0,
                    'run_id' => (int) ($running['id'] ?? 0),
                    'job_id' => (int) ($running['job_id'] ?? 0),
                    'busy' => TRUE,
                    'targets' => [],
                ];
            }
        }

        $targets = self::targets($limit, $slug);
        if ($targets === [])
        {
            return [
                'queued' => 0,
                'run_id' => 0,
                'job_id' => 0,
                'busy' => FALSE,
                'targets' => [],
            ];
        }

        $label = $slug !== ''
            ? 'исследование MCP: '.$slug
            : 'исследование открытых MCP ('.count($targets).')';
        $run = Service_Nj676_McpHub::create_run($label, count($targets), (int) $user_id, 'audit');
        $run_id = (int) ($run['id'] ?? 0);

        $job_id = 0;
        if (class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                $job_id = (int) Service_Nj120_JobQueue::push(self::CHANNEL, [
                    'run_id' => $run_id,
                    'targets' => $targets,
                ], [
                    'module' => 'nj676',
                    'timeout_sec' => 900,
                    'created_by' => (int) $user_id,
                    'priority' => $slug !== '' ? 92 : 75,
                ]);
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::WARNING, 'nj676.audit: очередь недоступна: :e', [':e' => $e->getMessage()]);
            }
        }

        if ($run_id > 0 AND $job_id > 0)
        {
            Service_Nj676_McpHub::set_run_job($run_id, $job_id);
        }

        return [
            'queued' => count($targets),
            'run_id' => $run_id,
            'job_id' => $job_id,
            'busy' => FALSE,
            'targets' => array_column($targets, 'slug'),
        ];
    }

    /**
     * Нормализовать и очистить отчёт воркера-агента перед записью в БД.
     *
     * @param  array $raw
     * @return array<string,mixed>
     */
    public static function normalize_report(array $raw)
    {
        $utility = max(0, min(100, (int) ($raw['utility_score'] ?? $raw['audit_score'] ?? 0)));
        $safety = max(0, min(100, (int) ($raw['safety_score'] ?? 0)));
        $status = in_array((string) ($raw['status'] ?? 'done'), ['done', 'fail'], TRUE)
            ? (string) $raw['status'] : 'done';

        $verdict = strtolower(trim((string) ($raw['verdict'] ?? '')));
        if ( ! isset(self::VERDICTS[$verdict]))
        {
            if ($status === 'fail' OR $utility <= 15)
            {
                $verdict = 'broken';
            }
            elseif ($utility >= 80 AND $safety >= 75)
            {
                $verdict = 'gem';
            }
            elseif ($utility >= 55 AND $safety >= 50)
            {
                $verdict = 'useful';
            }
            elseif ($utility >= 35)
            {
                $verdict = 'niche';
            }
            else
            {
                $verdict = 'questionable';
            }
        }

        $is_gem = ($verdict === 'gem' || ! empty($raw['is_gem']))
            && $utility >= 75
            && $safety >= 70
            && $status !== 'fail'
            && $verdict !== 'broken';
        if ($is_gem)
        {
            $verdict = 'gem';
        }
        elseif ($verdict === 'gem')
        {
            $verdict = $safety < 50 ? 'questionable' : ($utility >= 55 ? 'useful' : 'niche');
        }

        $summary = self::_clean_text($raw['summary'] ?? '', 8000);
        $safety_notes = self::_clean_text($raw['safety_notes'] ?? '', 4000);
        $observations = self::_clean_text($raw['observations'] ?? '', 8000);

        $use_cases = [];
        if (isset($raw['use_cases']) AND is_array($raw['use_cases']))
        {
            foreach (array_slice($raw['use_cases'], 0, 8) as $uc)
            {
                $uc = self::_clean_text($uc, 2000);
                if ($uc !== '')
                {
                    $use_cases[] = $uc;
                }
            }
        }

        $tested_tools = [];
        if (isset($raw['tested_tools']) AND is_array($raw['tested_tools']))
        {
            foreach (array_slice($raw['tested_tools'], 0, 6) as $tt)
            {
                if ( ! is_array($tt))
                {
                    continue;
                }
                $tool_name = self::_clean_text($tt['tool'] ?? ($tt['name'] ?? ''), 120);
                if ($tool_name === '')
                {
                    continue;
                }
                $tested_tools[] = [
                    'tool' => $tool_name,
                    'ok' => ! empty($tt['ok']),
                    'latency_ms' => max(0, min(60000, (int) ($tt['latency_ms'] ?? 0))),
                    'sample' => self::_clean_text($tt['sample'] ?? '', 2000),
                    'note' => self::_clean_text($tt['note'] ?? '', 600),
                ];
            }
        }

        return [
            'status' => $status,
            'utility_score' => $utility,
            'safety_score' => $safety,
            'verdict' => $verdict,
            'verdict_label' => self::VERDICTS[$verdict][0] ?? 'Проверен',
            'is_gem' => (bool) $is_gem,
            'summary' => $summary,
            'use_cases' => $use_cases,
            'safety_notes' => $safety_notes,
            'observations' => $observations,
            'tested_tools' => $tested_tools,
            'latency_ms' => max(0, min(60000, (int) ($raw['latency_ms'] ?? 0))),
            'model' => self::_clean_text($raw['model'] ?? '', 120),
            'auth_required' => ! empty($raw['auth_required']),
        ];
    }

    /**
     * Принять отчёт полевого исследования одного сервера от воркера.
     *
     * @param  array $payload
     * @return array{ok:bool,status:string,slug:string,verdict:string,utility_score:int,safety_score:int,is_gem:bool}
     */
    public static function apply_item(array $payload)
    {
        $slug = trim((string) ($payload['slug'] ?? ''));
        $run_id = (int) ($payload['run_id'] ?? 0);

        if ($slug === '')
        {
            return [
                'ok' => FALSE, 'status' => 'skipped', 'slug' => '',
                'verdict' => 'broken', 'utility_score' => 0, 'safety_score' => 0, 'is_gem' => FALSE,
            ];
        }

        $server = Service_Nj676_McpHub::get($slug, TRUE);
        if ( ! $server)
        {
            return [
                'ok' => FALSE, 'status' => 'skipped', 'slug' => $slug,
                'verdict' => 'broken', 'utility_score' => 0, 'safety_score' => 0, 'is_gem' => FALSE,
            ];
        }

        $norm = self::normalize_report($payload);

        // Если живой пробный вызов показал, что сервер требует авторизацию —
        // помечаем карточку, чтобы она не числилась «без ключа».
        if ( ! empty($norm['auth_required']))
        {
            Service_Nj676_McpHub::mark_auth_required($slug, 'полевой агент получил запрос авторизации при пробном вызове');
        }

        // 1. Записываем в журнал истории исследований nj_676_audits.
        try
        {
            DB::query(Database::INSERT,
                'INSERT INTO nj_676_audits
                    (server_slug, run_id, status, utility_score, safety_score, verdict, is_gem,
                     summary, use_cases, safety_notes, observations, tested_tools, latency_ms, model)
                 VALUES
                    (:slug, :run_id, :status, :utility, :safety, :verdict, :is_gem,
                     :summary, CAST(:use_cases AS jsonb), :safety_notes, :observations,
                     CAST(:tested_tools AS jsonb), :latency, :model)')
                ->param(':slug', $slug)
                ->param(':run_id', $run_id > 0 ? $run_id : NULL)
                ->param(':status', $norm['status'])
                ->param(':utility', $norm['utility_score'])
                ->param(':safety', $norm['safety_score'])
                ->param(':verdict', $norm['verdict'])
                ->param(':is_gem', $norm['is_gem'])
                ->param(':summary', $norm['summary'])
                ->param(':use_cases', json_encode($norm['use_cases'], JSON_UNESCAPED_UNICODE))
                ->param(':safety_notes', $norm['safety_notes'])
                ->param(':observations', $norm['observations'])
                ->param(':tested_tools', json_encode($norm['tested_tools'], JSON_UNESCAPED_UNICODE))
                ->param(':latency', $norm['latency_ms'])
                ->param(':model', $norm['model'])
                ->execute();
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'nj676.audit: запись в nj_676_audits не удалась: :e', [':e' => $e->getMessage()]);
        }

        // 2. Обновляем карточку сервера, пересчитываем search_text и корректируем trust.
        $report_json = [
            'utility_score' => $norm['utility_score'],
            'safety_score' => $norm['safety_score'],
            'verdict' => $norm['verdict'],
            'verdict_label' => $norm['verdict_label'],
            'is_gem' => $norm['is_gem'],
            'summary' => $norm['summary'],
            'use_cases' => $norm['use_cases'],
            'safety_notes' => $norm['safety_notes'],
            'observations' => $norm['observations'],
            'tested_tools' => $norm['tested_tools'],
            'latency_ms' => $norm['latency_ms'],
            'model' => $norm['model'],
            'audited_at' => date('c'),
        ];

        $merged_use_cases = $norm['use_cases'] !== [] ? $norm['use_cases'] : (array) ($server['use_cases'] ?? []);
        $server_for_search = $server;
        $server_for_search['audit_summary'] = $norm['summary'];
        $server_for_search['audit_verdict'] = $norm['verdict'];
        $server_for_search['is_gem'] = $norm['is_gem'];
        $server_for_search['use_cases'] = $merged_use_cases;
        $server_for_search['audit_report'] = $report_json;
        $new_search_text = Service_Nj676_McpHub::search_text($server_for_search);

        $trust_delta = 0;
        if ($norm['is_gem'])
        {
            $trust_delta = 8;
        }
        elseif ($norm['verdict'] === 'useful')
        {
            $trust_delta = 4;
        }
        elseif ($norm['verdict'] === 'questionable')
        {
            $trust_delta = -8;
        }
        elseif ($norm['verdict'] === 'broken')
        {
            $trust_delta = -15;
        }

        try
        {
            DB::query(Database::UPDATE,
                'UPDATE nj_676_servers
                    SET audit_score = :utility,
                        safety_score = :safety,
                        audit_verdict = :verdict,
                        is_gem = :is_gem,
                        audit_summary = :summary,
                        audit_report = CAST(:report AS jsonb),
                        audited_at = now(),
                        use_cases = CASE WHEN :has_uc THEN CAST(:use_cases AS jsonb) ELSE use_cases END,
                        trust = LEAST(100, GREATEST(5, (CASE WHEN trust <= 0 THEN 50 ELSE trust END) + :trust_delta)),
                        search_text = :search_text,
                        updated_at = now()
                  WHERE slug = :slug')
                ->param(':utility', $norm['utility_score'])
                ->param(':safety', $norm['safety_score'])
                ->param(':verdict', $norm['verdict'])
                ->param(':is_gem', $norm['is_gem'])
                ->param(':summary', $norm['summary'])
                ->param(':report', json_encode($report_json, JSON_UNESCAPED_UNICODE))
                ->param(':has_uc', $norm['use_cases'] !== [])
                ->param(':use_cases', json_encode($merged_use_cases, JSON_UNESCAPED_UNICODE))
                ->param(':trust_delta', $trust_delta)
                ->param(':search_text', $new_search_text)
                ->param(':slug', $slug)
                ->execute();
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'nj676.audit: обновление карточки :s не удалось: :e',
                [':s' => $slug, ':e' => $e->getMessage()]);
        }

        if ($run_id > 0)
        {
            $note = $norm['verdict_label'].' · польза '.$norm['utility_score'].'/100 · безопасность '.$norm['safety_score'].'/100';
            if ($norm['summary'] !== '')
            {
                $note .= ' — '.$norm['summary'];
            }
            Service_Nj676_McpHub::record_run_item($run_id, [
                'slug' => $slug,
                'name' => (string) ($server['display_name'] ?? $server['name'] ?? $slug),
                'repo_url' => (string) ($server['repo_url'] ?? $server['remote_url'] ?? ''),
                'stars' => (int) ($server['stars'] ?? 0),
            ], $norm['status'] === 'done' ? 'updated' : 'skipped', $note);
        }

        Service_Nj676_McpHub::reset_ready_cache();
        if (class_exists('Service_Nj676_Semantic'))
        {
            try
            {
                Service_Nj676_Semantic::index_slugs([$slug]);
            }
            catch (Throwable $e) {}
        }

        return [
            'ok' => TRUE,
            'status' => 'updated',
            'slug' => $slug,
            'verdict' => $norm['verdict'],
            'utility_score' => $norm['utility_score'],
            'safety_score' => $norm['safety_score'],
            'is_gem' => $norm['is_gem'],
        ];
    }

    /**
     * Лучшие серверы-«алмазы» по итогам полевых исследований агента.
     *
     * @param  int $limit
     * @return array<int,array<string,mixed>>
     */
    public static function gems($limit = 6)
    {
        $limit = max(1, min(24, (int) $limit));

        try
        {
            $rows = DB::query(Database::SELECT,
                "SELECT *
                   FROM nj_676_servers
                  WHERE is_published = TRUE
                    AND (is_gem = TRUE OR audit_score >= 75)
                    AND health_status <> 'offline'
                  ORDER BY is_gem DESC,
                           audit_score DESC,
                           safety_score DESC,
                           agent_rating DESC,
                           trust DESC,
                           stars DESC
                  LIMIT :limit")
                ->param(':limit', $limit)
                ->execute()
                ->as_array();

            return array_map(['Service_Nj676_McpHub', 'shape'], $rows);
        }
        catch (Throwable $e)
        {
            return [];
        }
    }

    /**
     * История полевых исследований конкретного сервера.
     *
     * @param  string $slug
     * @param  int    $limit
     * @return array<int,array<string,mixed>>
     */
    public static function for_server($slug, $limit = 5)
    {
        $slug = trim((string) $slug);
        $limit = max(1, min(20, (int) $limit));
        if ($slug === '')
        {
            return [];
        }

        try
        {
            $rows = DB::query(Database::SELECT,
                'SELECT id, server_slug, run_id, status, utility_score, safety_score,
                        verdict, is_gem, summary, use_cases, safety_notes, observations,
                        tested_tools, latency_ms, model, created_at
                   FROM nj_676_audits
                  WHERE server_slug = :slug
                  ORDER BY created_at DESC
                  LIMIT :limit')
                ->param(':slug', $slug)
                ->param(':limit', $limit)
                ->execute()
                ->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }

        $out = [];
        foreach ($rows as $row)
        {
            foreach (['use_cases', 'tested_tools'] as $json_col)
            {
                if (isset($row[$json_col]) AND is_string($row[$json_col]))
                {
                    $decoded = json_decode($row[$json_col], TRUE);
                    $row[$json_col] = is_array($decoded) ? $decoded : [];
                }
                elseif ( ! is_array($row[$json_col] ?? NULL))
                {
                    $row[$json_col] = [];
                }
            }

            $verdict = (string) ($row['verdict'] ?? 'useful');
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'server_slug' => (string) ($row['server_slug'] ?? ''),
                'run_id' => $row['run_id'] !== NULL ? (int) $row['run_id'] : NULL,
                'status' => (string) ($row['status'] ?? 'done'),
                'utility_score' => (int) ($row['utility_score'] ?? 0),
                'safety_score' => (int) ($row['safety_score'] ?? 0),
                'verdict' => $verdict,
                'verdict_label' => self::VERDICTS[$verdict][0] ?? 'Проверен',
                'verdict_tone' => self::VERDICTS[$verdict][1] ?? 'gray',
                'is_gem' => Service_Nj676_McpHub::as_bool($row['is_gem'] ?? FALSE) === TRUE,
                'summary' => (string) ($row['summary'] ?? ''),
                'use_cases' => $row['use_cases'],
                'safety_notes' => (string) ($row['safety_notes'] ?? ''),
                'observations' => (string) ($row['observations'] ?? ''),
                'tested_tools' => $row['tested_tools'],
                'latency_ms' => (int) ($row['latency_ms'] ?? 0),
                'model' => (string) ($row['model'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    protected static function _clean_text($value, $max = 600)
    {
        if ( ! is_scalar($value) AND $value !== NULL)
        {
            return '';
        }
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)));

        return mb_substr($text, 0, (int) $max, 'UTF-8');
    }
}
