<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prl100: «Хвост ошибок» /prl100/errors — единая лента неисправностей платформы.
 *
 * Источники:
 *  1. БД: свежие строки с error по всем рабочим таблицам модулей
 *     (очередь и манифесты сохранённых модулей);
 *  2. Системный лог Kohana (application/logs/ГГГГ/ММ/ДД.php): WARNING и выше
 *     за сегодня и вчера, со свёрнутым стеком.
 *
 * Страница только для чтения (право admin) — лечить всё равно руками,
 * зато «комар не подточит»: любая ошибка видна в одном месте.
 */
class Controller_Prl100_Errors extends Controller_Prl100_Base {

    /** Сколько строк каждого источника показываем. */
    const LIMIT_DB = 25;
    const LIMIT_LOG = 30;

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Хвост ошибок — администрирование');
    }

    public function action_index()
    {
        $this->template->content = View::factory('prl100/errors', [
            'db'    => $this->_db_errors(),
            'log'   => $this->_log_tail(),
            'stats' => $this->_stats(),
            'now'   => date('d.m.Y H:i'),
        ]);
    }

    // ---------------------------------------------------------------------

    /**
     * Свежие ошибочные строки по модулям. Каждая таблица — в своём try:
     * таблица могла не примениться, это не повод хоронить всю ленту.
     *
     * @return array[] {src, href, code, status, error, ts}
     */
    protected function _db_errors()
    {
        $db  = Database::instance();
        $out = [];

        // Источники объявляют сами модули — секция 'health' их манифестов
        // (пятый контракт, 28.09.2026). До этого «хвост ошибок» держал список
        // десяти чужих таблиц у себя: модуль со своей таблицей статусов о нём
        // не знал, и его ошибки в общий хвост просто не попадали.
        $sources = [];

        foreach (Manifest::health() as $table => $spec)
        {
            $sources[$table] = [
                (string) $spec['label'],
                (string) $spec['code'],
                isset($spec['href']) ? (string) $spec['href'] : '',
            ];
        }

        foreach ($sources as $table => [$label, $code_expr, $href])
        {
            try
            {
                // Health-источники не обязаны иметь все три временные колонки:
                // COALESCE(finished_at, ...) в PostgreSQL падает ещё на этапе
                // разбора, если первой колонки нет. Выбираем существующую.
                $column_rows = $db->query(Database::SELECT,
                    'SELECT column_name FROM information_schema.columns
                     WHERE table_schema = current_schema()
                       AND table_name = '.$db->quote($table)."
                       AND column_name IN ('finished_at', 'updated_at', 'created_at')", FALSE)->as_array();
                $available = [];

                foreach ($column_rows as $column_row)
                {
                    $column_row = (array) $column_row;
                    $available[(string) $column_row['column_name']] = TRUE;
                }

                $ts_expr = 'NULL::timestamptz';

                foreach (['finished_at', 'updated_at', 'created_at'] as $candidate)
                {
                    if (isset($available[$candidate]))
                    {
                        $ts_expr = '"'.$candidate.'"';
                        break;
                    }
                }

                $quoted_table = '"'.str_replace('"', '""', $table).'"';
                $rows = $db->query(Database::SELECT,
                    "SELECT {$code_expr} AS code, status, error,
                            {$ts_expr} AS ts
                     FROM {$quoted_table}
                     WHERE error IS NOT NULL AND error <> ''
                     ORDER BY ts DESC NULLS LAST LIMIT ".self::LIMIT_DB, FALSE)->as_array();

                foreach ($rows as $r)
                {
                    $r = (array) $r;
                    $out[] = [
                        'src'    => $label,
                        'href'   => ($href AND ! empty($r['code'])) ? $href.rawurlencode((string) $r['code']) : '',
                        'code'   => (string) $r['code'],
                        'status' => (string) $r['status'],
                        'error'  => mb_substr((string) $r['error'], 0, 400),
                        'ts'     => (string) $r['ts'],
                    ];
                }
            }
            catch (Exception $e)
            {
                continue; // таблицы нет — модуль не установлен/миграции не применены
            }
        }

        usort($out, function ($a, $b) {
            return strtotime((string) $b['ts']) <=> strtotime((string) $a['ts']);
        });

        return array_slice($out, 0, self::LIMIT_DB);
    }

    /**
     * Хвост системного лога: WARNING и выше, сегодня + вчера.
     * Формат Log_File: «ГГГГ-ММ-ДД ЧЧ:ММ:СС --- УРОВЕНЬ: текст» + трейс строками.
     *
     * @return array[] {level, time, msg, trace}
     */
    protected function _log_tail()
    {
        $levels_ok = ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
        $entries   = [];

        foreach ([0, 1] as $days_ago) // сегодня, вчера
        {
            $file = APPPATH.'logs/'.date('Y/m', time() - $days_ago * 86400).'/'
                   .date('d', time() - $days_ago * 86400).'.php';

            if ( ! is_file($file))
            {
                continue;
            }

            $head  = preg_replace('~^<\?php.*?-->\s*~s', '', (string) file_get_contents($file));
            $cur   = NULL;

            foreach (preg_split('/\r?\n/', $head) as $line)
            {
                if (preg_match('~^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) --- ([A-Z]+): (.*)$~', $line, $m))
                {
                    if ($cur AND in_array($cur['level'], $levels_ok, TRUE))
                    {
                        $entries[] = $cur;
                    }

                    $cur = [
                        'level' => $m[2],
                        'time'  => $m[1],
                        'msg'   => mb_substr($m[3], 0, 500),
                        'trace' => [],
                    ];
                }
                elseif ($cur !== NULL AND $line !== '' AND count($cur['trace']) < 5)
                {
                    $cur['trace'][] = mb_substr($line, 0, 240);
                }
            }

            if ($cur AND in_array($cur['level'], $levels_ok, TRUE))
            {
                $entries[] = $cur;
            }
        }

        return array_slice(array_reverse($entries), 0, self::LIMIT_LOG);
    }

    /** Счётчики для шапки: проваленные задачи и статьи в ошибке. */
    protected function _stats()
    {
        $db = Database::instance();
        $one = function (string $sql) use ($db) {
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
            'jobs_failed' => (int) ($one("SELECT COUNT(*) AS c FROM prl_120_jobs WHERE status = 'failed'")['c'] ?? 0),
            'jobs_stuck'  => (int) ($one("SELECT COUNT(*) AS c FROM prl_120_jobs WHERE status = 'processing'
                                          AND heartbeat_at < now() - interval '15 minutes'")['c'] ?? 0),
        ];
    }
}
