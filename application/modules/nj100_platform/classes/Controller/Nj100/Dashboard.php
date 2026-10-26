<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Личный кабинет /dashboard.
 *
 * Всем зарегистрированным — профиль и «Мои материалы» (артефакты всех
 * модулей пользователя). Администратору дополнительно — системный обзор:
 * очередь задач и живость воркеров, счётчики сервисов, ошибки, размер БД,
 * версия задеплоенного кода.
 */
class Controller_Nj100_Dashboard extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in())
        {
            HTTP::redirect('/auth/login', 302);
        }

        $this->set_title('Личный кабинет — N2J.ru');
    }

    public function action_index()
    {
        $user = Auth::instance()->get_user();

        $this->template->content = View::factory('nj100/dashboard', [
            'me'       => $user,
            'is_admin' => Auth::instance()->logged_in('admin'),
            'my'       => $this->_my_items(),
            'queue'    => $this->_queue(),
            'workers'  => $this->_workers(),
            'modules'  => $this->_modules(),
            'failed'   => $this->_recent_failed(),
            'events'   => $this->_recent_events(),
            'db_size'  => $this->_db_size(),
            'version'  => $this->_app_version(),
            'reco'     => $this->_reco(),
        ]);
    }

    /**
     * «Вам может пригодиться» — рекомендации nj600 (правила администратора,
     * похожие посетители, тот же раздел). Пусто — вьюха показывает статичный
     * «Быстрый старт». Любой сбой — пусто: кабинет не зависит от рекомендаций.
     */
    protected function _reco()
    {
        if ( ! class_exists('Service_Nj600_Reco'))
        {
            return '';
        }

        try
        {
            return (string) Service_Nj600_Reco::widget(4, 'Вам может пригодиться');
        }
        catch (Throwable $e)
        {
            return '';
        }
    }

    // ---------------------------------------------------------------------

    /** Мои артефакты по всем модулям (свежие сверху, всего до 12). */
    protected function _my_items()
    {
        $uid = (int) Auth::instance()->get_user()->id;
        $db  = Database::instance();
        $one = function ($sql) use ($db) {
            try
            {
                return $db->query(Database::SELECT, $sql, FALSE)->as_array();
            }
            catch (Throwable $e)
            {
                return [];
            }
        };

        // Ленту наполняют сами модули — секция 'recent' их манифестов (пятый
        // контракт, 28.09.2026). До этого дашборд держал шесть SQL по чужим
        // таблицам: новый модуль в «последнее» не попадал, пока кто-нибудь не
        // вспомнит дописать сюда запрос.
        $src = [];

        foreach (Manifest::recent() as $table => $spec)
        {
            $status = empty($spec['status']) ? 'NULL AS status' : (string) $spec['status'];

            // where — необязательное условие модуля (например, deleted_at IS NULL)
            $where = empty($spec['where']) ? '' : ' AND ('.$spec['where'].')';

            $src[] = $one("SELECT code, {$spec['title']} AS t, {$status}, created_at,
                                  '{$spec['base']}' AS base, '{$spec['icon']}' AS ico
                             FROM {$table} WHERE user_id = {$uid}{$where} ORDER BY id DESC LIMIT 6");
        }

        $all = [];

        foreach ($src as $rows)
        {
            foreach ($rows as $r)
            {
                $all[] = (array) $r;
            }
        }

        usort($all, function ($a, $b) {
            return strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']);
        });

        return array_slice($all, 0, 12);
    }



    protected function _queue()
    {
        try
        {
            return Service_Nj120_JobQueue::instance()->stats();
        }
        catch (Exception $e)
        {
            return ['pending' => '?', 'processing' => '?', 'done' => '?', 'failed' => '?'];
        }
    }

    /** Живость воркеров: свежий heartbeat по processing-задачам. */
    protected function _workers()
    {
        try
        {
            $db  = Database::instance();
            $row = $db->query(Database::SELECT,
                'SELECT max(heartbeat_at) AS hb, count(*) FILTER (WHERE status = \'processing\') AS busy,
                        count(DISTINCT claimed_by) FILTER (WHERE status = \'processing\') AS workers
                 FROM nj_120_jobs WHERE heartbeat_at IS NOT NULL', FALSE)->current();

            // Скобки обязательны: AND слабее присваивания — без них «онлайн»
            // показывался при любом ответе запроса, даже с пустым пульсом.
            $fresh = ($row AND $row['hb'] AND strtotime((string) $row['hb']) > time() - 300);

            return [
                'online'  => (bool) $fresh,
                'busy'    => (int) ($row['busy'] ?? 0),
                'workers' => (int) ($row['workers'] ?? 0),
                'hb'      => $row ? (string) $row['hb'] : '',
            ];
        }
        catch (Exception $e)
        {
            return ['online' => FALSE, 'busy' => 0, 'workers' => 0, 'hb' => ''];
        }
    }

    /** Счётчики модулей (каждый в своём try — таблицы могли не примениться). */
    protected function _modules()
    {
        $db  = Database::instance();
        $one = function ($sql) use ($db) {
            try
            {
                $row = $db->query(Database::SELECT, $sql, FALSE)->current();

                return $row ? (array) $row : [];
            }
            catch (Exception $e)
            {
                return [];
            }
        };

        return [
            'polls'   => $one('SELECT (SELECT COUNT(*) FROM nj_520_polls) AS polls, (SELECT COUNT(*) FROM nj_520_votes) AS votes'),
            'jump'    => $one('SELECT (SELECT COUNT(*) FROM nj_530_links) AS links, (SELECT COALESCE(SUM(clicks), 0) FROM nj_530_links) AS clicks'),
            'replies' => $one("SELECT COUNT(*) FILTER (WHERE status = 'new') AS new, COUNT(*) FILTER (WHERE status = 'done') AS done, COUNT(*) FILTER (WHERE status = 'error') AS error FROM nj_540_replies"),
            'jobs'    => $one("SELECT COUNT(*) FILTER (WHERE status = 'new') AS new, COUNT(*) FILTER (WHERE status = 'done') AS done, COUNT(*) FILTER (WHERE status = 'error') AS error FROM nj_550_searches"),
            'knowledge' => $one('SELECT COUNT(*) AS docs FROM nj_560_docs'),
        ];
    }

    /** Последние упавшие задачи (для внимания админа). */
    protected function _recent_failed()
    {
        try
        {
            $db = Database::instance();

            return $db->query(Database::SELECT,
                "SELECT id, channel, error, finished_at FROM nj_120_jobs
                 WHERE status = 'failed' ORDER BY id DESC LIMIT 5", FALSE)->as_array();
        }
        catch (Exception $e)
        {
            return [];
        }
    }

    /** Последние события очереди. */
    protected function _recent_events()
    {
        try
        {
            $db = Database::instance();

            return $db->query(Database::SELECT,
                'SELECT e.job_id, e.event, e.message, e.created_at
                 FROM nj_120_job_events e ORDER BY e.id DESC LIMIT 8', FALSE)->as_array();
        }
        catch (Exception $e)
        {
            return [];
        }
    }

    protected function _db_size()
    {
        try
        {
            $db  = Database::instance();
            $row = $db->query(Database::SELECT,
                'SELECT pg_size_pretty(pg_database_size(current_database())) AS size', FALSE)->current();

            return $row ? (string) $row['size'] : '?';
        }
        catch (Exception $e)
        {
            return '?';
        }
    }

    /** Версия задеплоенного кода (короткий хэш из .git — как в nj120 Api). */
    protected function _app_version()
    {
        $root = realpath(DOCROOT.'..');

        if ($root === FALSE)
        {
            return 'unknown';
        }

        $head = @file_get_contents($root.'/.git/HEAD');

        if ($head AND preg_match('~ref:\s*(\S+)~', $head, $m))
        {
            $sha = @file_get_contents($root.'/.git/'.$m[1]);

            if ( ! $sha)
            {
                $packed = @file_get_contents($root.'/.git/packed-refs');
                if ($packed AND preg_match('~^(\S+)\s+'.preg_quote($m[1], '~').'$~m', $packed, $pm))
                {
                    $sha = $pm[1];
                }
            }

            if ($sha)
            {
                return substr(trim($sha), 0, 7);
            }
        }

        return 'unknown';
    }
}
