<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj612_touch: «Серии касаний» — вежливые follow-up письма по компании.
 *
 * Сценарий: пользователь создаёт серию (компания, контакт, оффер, тон,
 * число шагов) → воркер пишет черновик каждого шага → отправка (ручная
 * по кнопке или авто по NJ612_TOUCH_AUTOSEND) → пауза ~4 дня → следующий
 * шаг. Ответ контакта (находим в разобранных входящих nj610 по адресу)
 * останавливает серию со статусом answered.
 *
 * Отправка — Service_Nj597_Mail::send_direct (SMTP сайта). Черновики —
 * воркер nj612.touch (LLM). Heavy-операций на сайте нет.
 */
class Service_Nj612_Touch {

    /** Пауза между шагами, дней. */
    const GAP_DAYS = 4;

    /** Максимум серий на пользователя. */
    const LIMIT_PER_USER = 30;

    /** Максимум шагов в серии. */
    const MAX_STEPS = 4;

    // ----------------------------------------------------------------- sql

    protected static function _db()
    {
        return Database::instance();
    }

    public static function ensure_tables()
    {
        // Таблицы создаёт миграция nj_612_001; здесь только дешёвая проверка
        // существования, чтобы виды не падали до /migrate_pg.
        try
        {
            self::_db()->query(Database::SELECT,
                'SELECT 1 FROM nj_612_sequences LIMIT 1', FALSE);
        }
        catch (Throwable $e)
        {
            // нет таблиц — модуль ещё не мигрирован
        }
    }

    // ----------------------------------------------------------------- api

    /** Мои серии (лента). */
    public static function sequences_of($user_id, $limit = 40)
    {
        return self::_db()->query(Database::SELECT,
            "SELECT s.*,
                    (SELECT COUNT(*) FROM nj_612_steps st
                      WHERE st.sequence_id = s.id AND st.status = 'ready') AS ready
             FROM nj_612_sequences s
             WHERE s.user_id = ".(int) $user_id."
             ORDER BY s.id DESC LIMIT ".(int) $limit, FALSE)->as_array();
    }

    /** Одна серия + шаги (для /touches/<code>). */
    public static function by_code($user_id, string $code)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_612_sequences
             WHERE user_id = '.(int) $user_id.' AND code = '.self::_db()->quote($code).' LIMIT 1', FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        $row = (array) $row;
        $row['steps'] = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_612_steps WHERE sequence_id = '.(int) $row['id'].' ORDER BY seq', FALSE)->as_array();

        return $row;
    }

    /** Создать серию; первый шаг сразу в очередь воркера. */
    public static function create($user_id, array $post): array
    {
        $company = trim((string) Arr::get($post, 'company'));
        $email   = trim((string) Arr::get($post, 'email'));
        $offer   = trim((string) Arr::get($post, 'offer'));
        $name    = trim((string) Arr::get($post, 'contact_name'));
        $tone    = (string) Arr::get($post, 'tone', 'business');
        $steps   = (int) Arr::get($post, 'steps', 3);

        if ($company === '' OR $offer === '')
        {
            return ['error' => 'Укажите компанию и что вы предлагаете'];
        }

        if ($email !== '' AND ! Valid::email($email))
        {
            return ['error' => 'Адрес почты контакта не похож на email'];
        }

        $tone = in_array($tone, ['business', 'friendly', 'bold'], TRUE) ? $tone : 'business';
        $steps = max(1, min(self::MAX_STEPS, $steps));

        $cnt = self::_db()->query(Database::SELECT,
            'SELECT COUNT(*) AS c FROM nj_612_sequences WHERE user_id = '.(int) $user_id, FALSE)->current();
        $cnt = $cnt ? (int) $cnt['c'] : 0;

        if ($cnt >= self::LIMIT_PER_USER)
        {
            return ['error' => 'Достигнут лимит серий ('.self::LIMIT_PER_USER.') — завершите или удалите старые'];
        }

        $code = 'sq'.bin2hex(random_bytes(5));
        $db = self::_db();

        list($insert_id) = $db->query(Database::INSERT,
            'INSERT INTO nj_612_sequences
                (user_id, code, company, contact_email, contact_name, offer, tone, steps_total, status, current_step, next_run_at)
             VALUES ('.(int) $user_id.', '.$db->quote($code).', '.$db->quote($company).',
                      '.$db->quote($email).', '.$db->quote($name).', '.$db->quote($offer).',
                      '.$db->quote($tone).', '.$steps.", 'active', 0, now())
             RETURNING id", FALSE);

        // шаг 1: черновик сразу (create → мгновенно в очередь)
        self::enqueue_draft($code, (int) $insert_id, 1);

        return ['ok' => TRUE, 'code' => $code, 'id' => (int) $insert_id];
    }

    /** Поставить задачу воркера: черновик шага $seq серии $code. */
    public static function enqueue_draft(string $code, int $sequence_id, int $seq)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_612_sequences WHERE id = '.(int) $sequence_id.' LIMIT 1', FALSE)->current();

        if ( ! $row)
        {
            return;
        }

        $row = (array) $row;

        // уже существующий черновик этого шага — перезапишем новым
        self::_db()->query(Database::DELETE,
            'DELETE FROM nj_612_steps WHERE sequence_id = '.(int) $sequence_id.' AND seq = '.(int) $seq." AND status IN ('drafting','ready')", FALSE);

        self::_db()->query(Database::INSERT,
            'INSERT INTO nj_612_steps (sequence_id, seq, status) VALUES
                ('.(int) $sequence_id.', '.(int) $seq.", 'drafting')", FALSE);

        // предыдущие шаги — в payload, чтобы follow-up ссылался на прошлое письмо
        $prev = [];
        foreach (self::_db()->query(Database::SELECT,
            'SELECT seq, subject, body FROM nj_612_steps
             WHERE sequence_id = '.(int) $sequence_id." AND status = 'sent' ORDER BY seq", FALSE)->as_array() as $p)
        {
            $p = (array) $p;
            $prev[] = ['seq' => (int) $p['seq'], 'subject' => (string) $p['subject'],
                       'body' => mb_substr((string) $p['body'], 0, 1200)];
        }

        $payload = [
            'code'    => $code,
            'seq'     => $seq,
            'company' => $row['company'],
            'name'    => $row['contact_name'],
            'offer'   => (string) $row['offer'],
            'tone'    => $row['tone'],
            'total'   => (int) $row['steps_total'],
            'prev'    => $prev,
        ];

        if (class_exists('Service_Nj120_JobQueue'))
        {
            Service_Nj120_JobQueue::push('nj612.touch', $payload, [
                'module'       => 'nj612',
                'visitor_id'   => 'u:'.$row['user_id'],
                'created_by'   => (int) $row['user_id'],
                'max_attempts' => 3,
                'timeout_sec'  => 300,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Живой статус-ряд (общий механизм partials/live_status, эталон /trends)
    // ------------------------------------------------------------------

    const CHANNEL = 'nj612.touch';

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

    /** Живая задача серии (payload задачи несёт code). */
    public static function live_for($user_id, string $code)
    {
        if (self::by_code($user_id, $code) === NULL)
        {
            return NULL;
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return self::_empty_live();
        }

        return Service_Nj120_JobQueue::instance()->live_status(self::CHANNEL, 'code', $code);
    }

    /** Последняя задача серии — включая завершённую (плашка «Готово»/«Ошибка»). */
    public static function last_for($user_id, string $code)
    {
        if (self::by_code($user_id, $code) === NULL OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        $st = Service_Nj120_JobQueue::instance()->last_status(self::CHANNEL, 'code', $code);

        return ($st AND ! empty($st['found'])) ? $st : NULL;
    }

    /** Остановить постановку черновика (серия остаётся у владельца). */
    public static function cancel_run($user_id, string $code)
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'Очередь задач недоступна'];
        }

        if (self::by_code($user_id, $code) === NULL)
        {
            return ['error' => 'Серия не найдена'];
        }

        $queue  = Service_Nj120_JobQueue::instance();
        $job_id = method_exists($queue, 'find_active')
            ? $queue->find_active(self::CHANNEL, 'code', $code)
            : $queue->find_for_delivery(self::CHANNEL, 'code', $code);

        if ($job_id <= 0)
        {
            return ['error' => 'Активной задачи нет — останавливать нечего'];
        }

        try
        {
            $queue->cancel($job_id, TRUE);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось остановить задачу'];
        }

        return ['ok' => TRUE, 'job_id' => $job_id];
    }

    /** Приём черновика от воркера (POST /touches/api/step). */
    public static function save_step(array $payload): array
    {
        $code = (string) Arr::get($payload, 'code');
        $seq  = (int) Arr::get($payload, 'seq');
        $subj = trim((string) Arr::get($payload, 'subject'));
        $body = trim((string) Arr::get($payload, 'body'));

        $db0 = self::_db();
        $row = $db0->query(Database::SELECT,
            'SELECT id, user_id FROM nj_612_sequences WHERE code = '.$db0->quote($code).' LIMIT 1', FALSE)->current();

        if ( ! $row OR $seq < 1)
        {
            return ['error' => 'серия или шаг не найдены'];
        }

        if ($subj === '' OR $body === '')
        {
            return ['error' => 'воркер вернул письмо без темы или тела'];
        }

        $db = self::_db();
        $db->query(Database::UPDATE,
            'UPDATE nj_612_steps SET subject = '.$db->quote(mb_substr($subj, 0, 500)).',
                    body = '.$db->quote($body).", status = 'ready', error = NULL
             WHERE sequence_id = ".(int) $row['id'].' AND seq = '.$seq, FALSE);

        return ['ok' => TRUE];
    }

    /** Отправить готовый шаг (кнопка или автосенд пиналки). */
    public static function send_step($user_id, string $code): array
    {
        $s = self::by_code($user_id, $code);

        if ($s === NULL)
        {
            return ['error' => 'Серия не найдена'];
        }

        if ($s['status'] !== 'active')
        {
            return ['error' => 'Серия не активна'];
        }

        if ((string) $s['contact_email'] === '')
        {
            return ['error' => 'Не указан адрес контакта — добавьте его на странице серии'];
        }

        $ready = NULL;

        foreach ($s['steps'] as $st)
        {
            if ((int) $st['seq'] === (int) $s['current_step'] + 1 AND (string) $st['status'] === 'ready')
            {
                $ready = (array) $st;
                break;
            }
        }

        if ($ready === NULL)
        {
            return ['error' => 'Готового черновика следующего шага нет — запросите «Черновик заново»'];
        }

        $greeting = trim((string) $s['contact_name']) !== ''
            ? 'Здравствуйте, '.HTML::chars($s['contact_name']).'!'
            : 'Здравствуйте!';
        $html = '<p>'.$greeting.'</p>'.nl2br(HTML::chars((string) $ready['body']))
              .'<p style="color:#888;font-size:12px">Письмо отправлено через N2J.ru — сервис вежливых касаний.</p>';

        try
        {
            // Почта — транспорт nj597: erp217 снесён, send_direct() переехал.
            $ok = Service_Nj597_Mail::send_direct((string) $s['contact_email'], (string) $ready['subject'], $html);
        }
        catch (Throwable $e)
        {
            $ok = FALSE;
        }

        $db = self::_db();

        if ( ! $ok)
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_612_steps SET error = '.$db->quote('SMTP: отправка не удалась — проверьте настройки почты').'
                 WHERE id = '.(int) $ready['id'], FALSE);

            return ['error' => 'Почтовый сервер не принял письмо — попробуйте позже'];
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_612_steps SET status = '.$db->quote('sent').', sent_at = now(), error = NULL
             WHERE id = '.(int) $ready['id'], FALSE);

        $next = (int) $s['current_step'] + 1;
        $is_last = $next >= (int) $s['steps_total'];

        $db->query(Database::UPDATE,
            'UPDATE nj_612_sequences SET
                current_step = '.$next.',
                status = '.($is_last ? $db->quote('done') : $db->quote('active')).',
                next_run_at = '.($is_last ? 'NULL' : "now() + interval '".self::GAP_DAYS." days'").',
                updated_at = now()
             WHERE id = '.(int) $s['id'], FALSE);

        if ( ! $is_last)
        {
            self::enqueue_draft($code, (int) $s['id'], $next + 1);
        }

        return ['ok' => TRUE, 'done' => $is_last];
    }

    /** Пауза/продолжение/стоп/«ответил» — управление серией. */
    public static function set_status($user_id, string $code, string $act): array
    {
        $map = [
            'pause'   => ['paused', NULL],
            'resume'  => ['active', "now() + interval '1 hour'"],
            'stop'    => ['stopped', NULL],
            'answered'=> ['answered', 'now()'],
        ];

        if ( ! isset($map[$act]))
        {
            return ['error' => 'Неизвестное действие'];
        }

        // next_run_at: raw SQL «now() + …» либо NULL — пустая строка PHP при
        // конкатенации давала битый «next_run_at = ,» (баг, пойман тестом).
        $next_run = ($map[$act][1] === NULL) ? 'NULL' : $map[$act][1];

        $db = self::_db();
        $db->query(Database::UPDATE,
            'UPDATE nj_612_sequences SET status = '.$db->quote($map[$act][0]).',
                next_run_at = '.$next_run.',
                answered_at = '.($act === 'answered' ? 'now()' : 'answered_at').',
                updated_at = now()
             WHERE user_id = '.(int) $user_id.' AND code = '.$db->quote($code), FALSE);

        return ['ok' => TRUE];
    }

    /** Обновить контакт серии. */
    public static function update_contact($user_id, string $code, string $email, string $name): array
    {
        $email = trim($email);

        if ($email !== '' AND ! Valid::email($email))
        {
            return ['error' => 'Адрес не похож на email'];
        }

        $db = self::_db();
        $db->query(Database::UPDATE,
            'UPDATE nj_612_sequences SET contact_email = '.$db->quote($email).',
                contact_name = '.$db->quote(trim($name)).', updated_at = now()
             WHERE user_id = '.(int) $user_id.' AND code = '.$db->quote($code), FALSE);

        return ['ok' => TRUE];
    }

    /** Удалить серию вместе с шагами. */
    public static function delete($user_id, string $code)
    {
        $db = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT id FROM nj_612_sequences WHERE user_id = '.(int) $user_id.' AND code = '.$db->quote($code).' LIMIT 1', FALSE)->current();

        if ($row)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_612_steps WHERE sequence_id = '.(int) $row['id'], FALSE);
            $db->query(Database::DELETE, 'DELETE FROM nj_612_sequences WHERE id = '.(int) $row['id'], FALSE);
        }

        return TRUE;
    }

    /** Тик пиналки: автоотправка созревших шагов + детект ответов. */
    public static function tick(): array
    {
        $touched = ['sent' => 0, 'answered' => 0];

        // 1) детект ответов: активные серии, чей контакт писал нам после последней отправки
        $rows = self::_db()->query(Database::SELECT,
            "SELECT s.id, s.code, s.user_id, s.contact_email,
                    (SELECT max(st.sent_at) FROM nj_612_steps st
                      WHERE st.sequence_id = s.id AND st.sent_at IS NOT NULL) AS last_sent
             FROM nj_612_sequences s
             WHERE s.status = 'active' AND s.contact_email <> ''", FALSE)->as_array();

        foreach ($rows as $r)
        {
            $r = (array) $r;

            if (empty($r['last_sent']))
            {
                continue;
            }

            $db1 = self::_db();
            $hit = $db1->query(Database::SELECT,
                'SELECT id FROM nj_610_messages
                 WHERE from_addr ILIKE '.$db1->quote('%'.(string) $r['contact_email'].'%').'
                   AND received_at > '.$db1->quote((string) $r['last_sent']).' LIMIT 1', FALSE)->current();

            if ($hit)
            {
                self::_db()->query(Database::UPDATE,
                    "UPDATE nj_612_sequences SET status = 'answered', answered_at = now(), next_run_at = NULL,
                        updated_at = now() WHERE id = ".(int) $r['id'], FALSE);
                $touched['answered']++;

                if (class_exists('Service_Nj597_Notify'))
                {
                    Service_Nj597_Notify::notify((int) $r['user_id'], 'nj612_answered',
                        'Ответ по серии: '.$r['code'],
                        'Контакт '.$r['contact_email'].' ответил — серия остановлена.',
                        '/touches/'.$r['code']);
                }
            }
        }

        // 2) автоотправка созревших (NJ612_TOUCH_AUTOSEND=1)
        if (self::autosend())
        {
            $due = self::_db()->query(Database::SELECT,
                "SELECT code, user_id FROM nj_612_sequences
                 WHERE status = 'active' AND next_run_at IS NOT NULL AND next_run_at <= now()
                 ORDER BY id LIMIT 20", FALSE)->as_array();

            foreach ($due as $r)
            {
                $r = (array) $r;
                $res = self::send_step((int) $r['user_id'], (string) $r['code']);

                if (isset($res['ok']))
                {
                    $touched['sent']++;
                }
            }
        }

        return $touched;
    }

    /** Автоотправка включена? (env NJ612_TOUCH_AUTOSEND, по умолчанию выкл). */
    public static function autosend(): bool
    {
        return trim((string) getenv('NJ612_TOUCH_AUTOSEND')) === '1';
    }

    /** Управление: обзор всех серий. */
    public static function admin_overview($limit = 100)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT s.*, u.username AS owner_name,
                    (SELECT COUNT(*) FROM nj_612_steps st WHERE st.sequence_id = s.id) AS steps_made
             FROM nj_612_sequences s LEFT JOIN users u ON u.id = s.user_id
             ORDER BY s.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

}
