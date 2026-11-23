<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120: «Ресурсы и потребление» /prx120/usage — отчёты воркеров о ресурсах:
 * токены по моделям/модулям/пользователям, время, ошибки для анализа.
 * Доступ — право prx120.usage.admin (config/acl.php).
 */
class Controller_Prx120_Usage extends Controller_Prx102_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('prx120.usage.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

    }

    public function action_index()
    {
        $periods = ['24h' => '24 часа', '7d' => '7 дней', '30d' => '30 дней', 'all' => 'всё время'];
        $p = (string) $this->request->query('p');

        if ( ! isset($periods[$p]))
        {
            $p = '7d';
        }

        // интервал выборки (whitelist выше — SQL-инъекция исключена)
        $since = '';
        if ($p === '24h') { $since = " AND u.created_at > now() - interval '24 hours'"; }
        if ($p === '7d')  { $since = " AND u.created_at > now() - interval '7 days'"; }
        if ($p === '30d') { $since = " AND u.created_at > now() - interval '30 days'"; }

        $db = Database::instance();
        $q = function ($sql) use ($db) {
            try { return $db->query(Database::SELECT, $sql, FALSE)->as_array(); }
            catch (Throwable $e) { Kohana::$log->add(Log::WARNING, 'prx120 usage: :e', [':e' => $e->getMessage()]); return []; }
        };

        $total = function ($sql) use ($db) {
            try { $r = $db->query(Database::SELECT, $sql, FALSE)->current(); return $r ? (int) array_values((array) $r)[0] : 0; }
            catch (Throwable $e) { return 0; }
        };

        $this->set_title('Ресурсы и потребление · управление');

        $this->template->content = View::factory('120/usage', [
            'p'          => $p,
            'periods'    => $periods,
            'jobs'       => $total("SELECT count(*) FROM prx_120_usage u WHERE 1=1 $since"),
            'tokens'     => $total("SELECT coalesce(sum(prompt_tokens+completion_tokens),0) FROM prx_120_usage u WHERE 1=1 $since"),
            'llm_calls'  => $total("SELECT coalesce(sum(llm_calls),0) FROM prx_120_usage u WHERE 1=1 $since"),
            'fetches'    => $total("SELECT coalesce(sum(web_fetches+search_queries),0) FROM prx_120_usage u WHERE 1=1 $since"),
            'elapsed_h'  => round($total("SELECT coalesce(sum(elapsed_ms),0) FROM prx_120_usage u WHERE 1=1 $since") / 3600000, 1),
            'by_channel' => $q("SELECT channel, count(*) AS jobs, sum(llm_calls) AS calls,
                    sum(prompt_tokens+completion_tokens) AS tokens, avg(elapsed_ms)::int AS ms
                FROM prx_120_usage u WHERE 1=1 $since
                GROUP BY channel ORDER BY tokens DESC LIMIT 15"),
            'by_model'   => $q("SELECT m->>'provider' AS provider, m->>'model' AS model,
                    sum((m->>'calls')::int) AS calls,
                    sum((m->>'prompt_tokens')::bigint) AS ptokens,
                    sum((m->>'completion_tokens')::bigint) AS ctokens
                FROM prx_120_usage u, jsonb_array_elements(u.models) m
                WHERE 1=1 $since
                GROUP BY 1, 2 ORDER BY sum((m->>'prompt_tokens')::bigint+(m->>'completion_tokens')::bigint) DESC LIMIT 15"),
            'by_user'    => $q("SELECT u.visitor_id, coalesce(us.username, u.visitor_id) AS name,
                    count(*) AS jobs, sum(u.prompt_tokens+u.completion_tokens) AS tokens,
                    sum(u.had_error) AS errs
                FROM prx_120_usage u
                LEFT JOIN users us ON us.id = NULLIF(split_part(u.visitor_id, ':', 2), '')::bigint
                WHERE 1=1 $since
                GROUP BY u.visitor_id, us.username ORDER BY tokens DESC LIMIT 15"),
            'errors'     => $q("SELECT u.job_id, u.channel, coalesce(us.username, u.visitor_id) AS name,
                    u.errors, u.created_at
                FROM prx_120_usage u
                LEFT JOIN users us ON us.id = NULLIF(split_part(u.visitor_id, ':', 2), '')::bigint
                WHERE u.had_error = 1
                ORDER BY u.job_id DESC LIMIT 12"),
        ]);
    }
}
