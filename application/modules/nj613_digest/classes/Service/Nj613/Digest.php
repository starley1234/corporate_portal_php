<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj613_digest: «Дайджест руководителю» — недельная сводка по вашим данным.
 *
 * Пиналка раз в день вызывает /digest/api/tick: для созревших подписок сайт
 * собирает счётчики недели (радар, лиды, входящие, статьи) и ставит задачу
 * nj613.digest — воркер LLM-ом пишет человеческое резюме и возвращает на
 * /digest/api/item. Сайт сохраняет дайджест, уведомляет (nj597) и шлёт
 * письмо владельцу (SMTP), если у пользователя указан email.
 */
class Service_Nj613_Digest {

    /** Дней между дайджестами. */
    const PERIOD_DAYS = 7;

    protected static function _db()
    {
        return Database::instance();
    }

    /** Подписка пользователя (создаётся лениво при первом заходе). */
    public static function sub($user_id): array
    {
        $db = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_613_subs WHERE user_id = '.(int) $user_id.' LIMIT 1', FALSE)->current();

        if ($row)
        {
            return (array) $row;
        }

        $db->query(Database::INSERT,
            'INSERT INTO nj_613_subs (user_id) VALUES ('.(int) $user_id.')', FALSE);

        return ['user_id' => (int) $user_id, 'weekday' => 1, 'enabled' => TRUE, 'last_sent' => NULL];
    }

    /** Сохранить настройки подписки. */
    public static function save_sub($user_id, int $weekday, bool $enabled)
    {
        self::sub($user_id);
        self::_db()->query(Database::UPDATE,
            'UPDATE nj_613_subs SET weekday = '.max(0, min(6, $weekday)).',
                enabled = '.($enabled ? 'TRUE' : 'FALSE').' WHERE user_id = '.(int) $user_id, FALSE);
    }

    /** Мои дайджесты (свежие первыми). */
    public static function mine($user_id, $limit = 12)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_613_digests WHERE user_id = '.(int) $user_id.'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /**
     * Счётчики недели по модулям-источникам. Таблицы чужих модулей читаем
     * аккуратно: таблицы могут ещё не существовать (модуль отключён) —
     * каждая цифра считается в отдельной попытке.
     */
    public static function week_stats($user_id, string $from, string $to): array
    {
        $db   = self::_db();
        $uid  = (int) $user_id;
        $cond = ' WHERE user_id = '.$uid.' AND created_at >= '.$db->quote($from).' AND created_at < '.$db->quote($to);

        $one = static function (string $sql) use ($db): int {
            try
            {
                $r = $db->query(Database::SELECT, $sql, FALSE)->current();

                return $r ? (int) $r['c'] : 0;
            }
            catch (Throwable $e)
            {
                return 0; // модуль не установлен/таблицы нет — не наша беда
            }
        };

        return [
            'radar_finds' => $one('SELECT COUNT(*) AS c FROM nj_605_items'.$cond),
            'leads'       => $one('SELECT COUNT(*) AS c FROM nj_606_leads'.$cond),
            'inbox_new'   => $one("SELECT COUNT(*) AS c FROM nj_610_messages WHERE status = 'new'
                                    AND received_at >= ".$db->quote($from).' AND received_at < '.$db->quote($to)),
            'articles'    => $one("SELECT COUNT(*) AS c FROM nj_602_articles WHERE status = 'published'
                                    AND created_at >= ".$db->quote($from).' AND created_at < '.$db->quote($to)),
            'touches_sent'=> $one("SELECT COUNT(*) AS c FROM nj_612_steps st
                                    JOIN nj_612_sequences s ON s.id = st.sequence_id
                                    WHERE s.user_id = ".$uid." AND st.status = 'sent'
                                    AND st.sent_at >= ".$db->quote($from).' AND st.sent_at < '.$db->quote($to)),
        ];
    }

    /** Тик пиналки: собрать созревшие подписки и поставить задачи воркеру. */
    public static function tick(): array
    {
        $made = 0;

        $rows = self::_db()->query(Database::SELECT,
            "SELECT s.user_id, u.email
             FROM nj_613_subs s JOIN users u ON u.id = s.user_id
             WHERE s.enabled = TRUE
               AND (s.last_sent IS NULL OR s.last_sent < now() - interval '".self::PERIOD_DAYS." days')", FALSE)->as_array();

        foreach ($rows as $r)
        {
            $r = (array) $r;
            $to   = date('Y-m-d H:i:s');
            $from = date('Y-m-d H:i:s', strtotime($to.' -'.self::PERIOD_DAYS.' days'));
            $stats = self::week_stats($r['user_id'], $from, $to);

            // неделя без событий — дайджест не ставим, просто переносим срок
            if (array_sum($stats) === 0)
            {
                self::_db()->query(Database::UPDATE,
                    'UPDATE nj_613_subs SET last_sent = now() WHERE user_id = '.(int) $r['user_id'], FALSE);

                continue;
            }

            $db = self::_db();
            $db->query(Database::INSERT,
                'INSERT INTO nj_613_digests (user_id, date_from, date_to, stats, status)
                 VALUES ('.(int) $r['user_id'].', '.$db->quote($from).', '.$db->quote($to).", 'new')", FALSE);

            if (class_exists('Service_Nj120_JobQueue'))
            {
                Service_Nj120_JobQueue::push('nj613.digest', [
                    'user_id' => (int) $r['user_id'],
                    'from'    => $from,
                    'to'      => $to,
                    'stats'   => $stats,
                    'email'   => (string) ($r['email'] ?? ''),
                ], [
                    'module'       => 'nj613',
                    'visitor_id'   => 'u:'.$r['user_id'],
                    'created_by'   => (int) $r['user_id'],
                    'max_attempts' => 3,
                    'timeout_sec'  => 240,
                ]);
            }

            $made++;
        }

        return ['ok' => TRUE, 'queued' => $made];
    }

    // ------------------------------------------------------------------
    // Живой статус-ряд (общий механизм partials/live_status, эталон /trends)
    // ------------------------------------------------------------------

    const CHANNEL = 'nj613.digest';

    /** Контракт «задачи нет» — тот же набор полей, что у JobQueue. */
    public static function _empty_live()
    {
        return [
            'found'       => FALSE,
            'job_id'      => 0,
            'status'      => 'none',
            'stage'       => '',
            'stages'      => [],
            'elapsed_sec' => 0,
            'started_at'  => NULL,
            'finished_at' => NULL,
            'silent_sec'  => NULL,
            'error'       => '',
            'note'        => '',
        ];
    }

    /**
     * Живая задача сбора дайджеста ЭТОГО пользователя: задача канала несёт
     * payload user_id, поэтому ряд видит только свой дайджест.
     */
    public static function live_for($user_id)
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return self::_empty_live();
        }

        return Service_Nj120_JobQueue::instance()->live_status(self::CHANNEL, 'user_id', (int) $user_id);
    }

    /** Последняя задача пользователя — включая завершённую (плашка итога). */
    public static function last_for($user_id)
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        $st = Service_Nj120_JobQueue::instance()->last_status(self::CHANNEL, 'user_id', (int) $user_id);

        return ($st AND ! empty($st['found'])) ? $st : NULL;
    }

    /** Приём готового резюме от воркера. */
    public static function save_item(array $payload): array
    {
        $uid  = (int) Arr::get($payload, 'user_id');
        $from = (string) Arr::get($payload, 'from');
        $to   = (string) Arr::get($payload, 'to');
        $text = trim((string) Arr::get($payload, 'summary'));

        if ($uid <= 0 OR $text === '')
        {
            return ['error' => 'нет пользователя или текста резюме'];
        }

        $db = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT id FROM nj_613_digests WHERE user_id = '.$uid."
                AND status = 'new' ORDER BY id DESC LIMIT 1", FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'нет новой сводки для этого пользователя'];
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_613_digests SET summary = '.$db->quote(mb_substr($text, 0, 20000)).",
                status = 'ready' WHERE id = ".(int) $row['id'], FALSE);

        $db->query(Database::UPDATE, 'UPDATE nj_613_subs SET last_sent = now() WHERE user_id = '.$uid, FALSE);

        // уведомление + письмо
        $title = 'Ваш дайджест недели готов';

        if (class_exists('Service_Nj597_Notify'))
        {
            Service_Nj597_Notify::notify($uid, 'nj613_digest', $title,
                'Сводка по радарам, лидам и входящим — коротко и по-человечески.', '/digest');
        }

        $email = trim((string) Arr::get($payload, 'email'));

        if ($email !== '' AND class_exists('Service_Nj597_Mail'))
        {
            try
            {
                Service_Nj597_Mail::send_direct($email, $title,
                    '<p>Ваш недельный дайджест N2J.ru:</p><p>'.nl2br(HTML::chars($text)).'</p>'
                    .'<p style="color:#888;font-size:12px">Сводка собирается по вашим данным раз в неделю. Настройки — на /digest.</p>');
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj613: письмо дайджеста не ушло: :e', [':e' => $e->getMessage()]);
            }
        }

        return ['ok' => TRUE];
    }

    /** Управление: последние сводки и подписки. */
    public static function admin_overview($limit = 60)
    {
        $db = self::_db();

        return [
            'digests' => $db->query(Database::SELECT,
                'SELECT d.*, u.username AS owner_name FROM nj_613_digests d
                 LEFT JOIN users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT '.(int) $limit, FALSE)->as_array(),
            'subs' => $db->query(Database::SELECT,
                'SELECT COUNT(*) FILTER (WHERE enabled) AS on_cnt, COUNT(*) AS all_cnt FROM nj_613_subs', FALSE)->current(),
        ];
    }

}
