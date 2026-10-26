<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Полный отчёт» (nj635_scout): одна страница собирает всё о компании и
 * показывает результат блоками — без переходов по сервисам.
 *
 * Что делает:
 *   1. `start()` — запускает разведку ЧЕРЕЗ ВЛАДЕЛЬЦЕВ данных: у залогиненного
 *      это сквозной маршрут nj635 (справочник → наблюдение → профиль сайта →
 *      досье), у гостя — только AI-досье nj598 (оно единственное умеет анонимов).
 *      Сами данные не собираем и не дублируем: у каждого шага свой владелец,
 *      свои лимиты, списания и воркер.
 *   2. `state()` — «что сейчас с каждым шагом»: очередь/идёт/готово/ошибка,
 *      этап, секунды, тишина. Политику авто-остановки (нет пульса ~4 мин,
 *      прогресс застыл 10 мин) берём из общего JobQueue — тем же способом,
 *      что страницы владельцев.
 *   3. `blocks()` — готовые HTML-блоки (виды 635/parts/*): реквизиты и сигналы
 *      карточки, вакансии, профиль сайта, досье, риски. Их же отдаёт
 *      /check/api/status, поэтому страница и опрос показывают одно и то же.
 *
 * Безопасность: чужое не показываем — доступ к шагу проверяется по владельцу
 * (user_id), куке посетителя (uid) или связке «гость → логин» (ubid), как на
 * страницах самих сервисов.
 */
class Service_Nj635_Report
{
    /** Шаги отчёта в порядке показа. */
    public static function steps()
    {
        return [
            'dossier' => [
                'label'   => 'AI-досье на работодателя',
                'hint'    => 'Отзывы сотрудников, реальный стек, красные флаги, вопросы к собеседованию',
                'icon'    => 'fas fa-user-secret',
                'svc'     => 'nj598',
                'page'    => '/dossier/',
                'login'   => FALSE,   // умеет анонимов (кука)
            ],
            'profile' => [
                'label'   => 'Сайт компании',
                'hint'    => 'Сфера, масштаб, контакты, ЛПР, стек — из открытых страниц',
                'icon'    => 'fas fa-building',
                'svc'     => 'nj591',
                'page'    => '/company/',
                'login'   => TRUE,
            ],
            'watch'   => [
                'label'   => 'Наблюдение за рисками',
                'hint'    => 'Суды, банкротство, изменения ЕГРЮЛ — проверка сразу и раз в неделю',
                'icon'    => 'fas fa-shield-halved',
                'svc'     => 'nj609',
                'page'    => '/watchdog',
                'login'   => TRUE,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Запуск
    // -------------------------------------------------------------------------

    /**
     * Запустить полный отчёт по строке ввода.
     *
     * @param  int    $user_id     0/NULL — гость
     * @param  string $visitor_uid кука посетителя (для гостя)
     * @param  string $query       что ввёл человек
     * @return array  ['project'=>code] | ['dossier'=>code] | ['error'=>…]
     */
    public static function start($user_id, $visitor_uid, $query)
    {
        $query = trim((string) $query);

        if ($query === '')
        {
            return ['error' => 'Укажите компанию, ИНН/ОГРН, сайт или ссылку на вакансию.'];
        }

        // Залогиненный: сквозной маршрут nj635 сам поднимет справочник,
        // наблюдение, профиль сайта и досье (каждый — у своего владельца).
        if ((int) $user_id > 0 AND class_exists('Service_Nj635_Scout'))
        {
            $res = Service_Nj635_Scout::create((int) $user_id, $query);

            if (isset($res['error']))
            {
                return ['error' => (string) $res['error']];
            }

            if ( ! empty($res['code']))
            {
                return ['project' => (string) $res['code'], 'query' => $query];
            }

            return ['error' => 'Не удалось создать разведку — попробуйте ещё раз.'];
        }

        // Гость: единственный шаг, доступный без входа — AI-досье (nj598).
        if ( ! class_exists('Service_Nj598_Dossier'))
        {
            return ['error' => 'Сервис досье сейчас недоступен — войдите и попробуйте ещё раз.'];
        }

        $res = Service_Nj598_Dossier::create(NULL, (string) $visitor_uid, $query);

        if (isset($res['error']))
        {
            return ['error' => (string) $res['error']];
        }

        if (isset($res['exists']))
        {
            return ['dossier' => (string) $res['exists'], 'query' => $query, 'exists' => TRUE];
        }

        return ['dossier' => (string) $res['code'], 'query' => $query];
    }

    /** Ссылки отчёта из параметров запроса (p — маршрут nj635, d/pr/w — шаги). */
    public static function ref(array $src)
    {
        $get = function ($k) use ($src) {
            $v = isset($src[$k]) ? (string) $src[$k] : '';

            return (string) preg_replace('~[^0-9a-z_]~i', '', $v);
        };

        return [
            'project' => $get('p'),
            'dossier' => $get('d'),
            'profile' => $get('pr'),
            'watch'   => $get('w'),
            'query'   => isset($src['q']) ? trim((string) $src['q']) : '',
        ];
    }

    /** Есть ли что показывать в отчёте (хотя бы одна ссылка). */
    public static function has_ref(array $ref)
    {
        return ($ref['project'] !== '' OR $ref['dossier'] !== ''
            OR $ref['profile'] !== '' OR $ref['watch'] !== '');
    }

    /** Ссылки отчёта для адреса страницы (без пустых). */
    public static function query(array $ref)
    {
        $out = [];

        if ($ref['query'] !== '')
        {
            $out['q'] = $ref['query'];
        }

        foreach (['project' => 'p', 'dossier' => 'd', 'profile' => 'pr', 'watch' => 'w'] as $k => $q)
        {
            if ( ! empty($ref[$k]))
            {
                $out[$q] = (string) $ref[$k];
            }
        }

        return http_build_query($out);
    }

    // -------------------------------------------------------------------------
    // Состояние шагов
    // -------------------------------------------------------------------------

    /**
     * Состояние всех шагов отчёта.
     *
     * @param  array $ref ['project'|'dossier'|'profile'|'watch' => code]
     * @param  array $ctx ['user_id' => int|NULL, 'uid' => string, 'ubid' => string|NULL]
     * @return array      ['steps' => [...], 'done' => bool, 'query' => string]
     */
    public static function state(array $ref, array $ctx)
    {
        // маршрут nj635 хранит коды всех шагов — сам ничего не собирает
        if ($ref['project'] !== '' AND class_exists('Service_Nj635_Scout'))
        {
            $proj = Service_Nj635_Scout::by_code($ref['project']);

            if ($proj AND self::mine($ctx, (int) $proj['user_id'], '', ''))
            {
                $ref['dossier'] = $ref['dossier'] !== '' ? $ref['dossier'] : (string) $proj['dossier_code'];
                $ref['profile'] = $ref['profile'] !== '' ? $ref['profile'] : (string) $proj['profile_code'];
                $ref['watch']   = $ref['watch'] !== '' ? $ref['watch'] : (string) $proj['subject_code'];

                if ($ref['query'] === '')
                {
                    $ref['query'] = (string) ($proj['company'] !== '' ? $proj['company'] : $proj['query']);
                }
            }
        }

        $out = ['steps' => [], 'done' => TRUE, 'query' => (string) $ref['query']];

        foreach (self::steps() as $key => $meta)
        {
            $step = self::_step($key, $meta, $ref, $ctx);
            $out['steps'][$key] = $step;

            if ( ! in_array($step['status'], ['done', 'failed', 'cancelled', 'locked', 'missing'], TRUE))
            {
                $out['done'] = FALSE;
            }
        }

        return $out;
    }

    /** Один шаг: состояние + данные. */
    protected static function _step($key, array $meta, array $ref, array $ctx)
    {
        $step = [
            'key'         => $key,
            'label'       => (string) $meta['label'],
            'hint'        => (string) $meta['hint'],
            'icon'        => (string) $meta['icon'],
            'page'        => '',
            'code'        => '',
            'status'      => 'missing',
            'stage'       => '',
            'elapsed_sec' => 0,
            'silent_sec'  => NULL,
            'error'       => '',
            'needs_login' => FALSE,
            'data'        => [],
            'events'      => [],
            'rev'         => '',
        ];

        try
        {
            if ($key === 'dossier')
            {
                self::_dossier($step, $ref, $ctx);
            }
            elseif ($key === 'profile')
            {
                self::_profile($step, $ref, $ctx);
            }
            elseif ($key === 'watch')
            {
                self::_watch($step, $ref, $ctx);
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj635: отчёт :k: :e', [':k' => $key, ':e' => $e->getMessage()]);
            $step['status'] = 'failed';
            $step['error']  = 'Не удалось получить состояние шага';
        }

        // Отпечаток состояния: по нему страница решает, менять ли блок.
        // Секунды сюда НЕ входят — иначе идущий шаг перерисовывался бы на каждом
        // опросе, и локальный таймер дёргался бы назад. Тикает он сам, а данные
        // и этапы обновляются по отпечатку.
        $step['rev'] = md5(implode('|', [
            $step['status'], $step['stage'], $step['error'], $step['code'],
            (string) ($step['needs_login'] ? 1 : 0),
            (string) (isset($step['data']['_rev']) ? $step['data']['_rev'] : ''),
            (string) count($step['events']),
        ]));

        return $step;
    }

    /** AI-досье (nj598): состояние, этап и результат. */
    protected static function _dossier(array &$step, array $ref, array $ctx)
    {
        if ($ref['dossier'] === '' OR ! class_exists('Service_Nj598_Dossier'))
        {
            $step['status'] = $ref['dossier'] === '' ? 'missing' : 'failed';
            $step['needs_login'] = $ref['dossier'] === '';

            return;
        }

        $row = Service_Nj598_Dossier::by_code($ref['dossier']);

        if ( ! $row)
        {
            $step['status'] = 'missing';

            return;
        }

        $step['code'] = (string) $row['code'];

        if ( ! self::mine($ctx, (int) $row['user_id'], (string) $row['visitor_id'], 'g:'))
        {
            $step['status'] = 'locked';

            return;
        }

        $row = Service_Nj598_Dossier::reconcile($row);

        self::_live($step, ! empty($row['job_id']) ? (int) $row['job_id'] : 0);

        $step['stage'] = (string) ($row['progress'] !== '' ? $row['progress'] : $step['stage']);
        $step['status'] = self::map_status((string) $row['status']);

        if ($step['status'] === 'failed')
        {
            $step['error'] = (string) $row['error'];
        }

        if ($step['status'] === 'done')
        {
            $data = Service_Nj598_Dossier::result_of($row);
            $data['_rev'] = (string) ($row['finished_at'] ?? $row['updated_at']);
            $step['data'] = $data;
            $step['page'] = '/dossier/'.$step['code'];
        }
    }

    /** Профиль сайта (nj591). */
    protected static function _profile(array &$step, array $ref, array $ctx)
    {
        if ($ref['profile'] === '' OR ! class_exists('Service_Nj591_Company'))
        {
            $step['status'] = 'missing';
            $step['needs_login'] = empty($ctx['user_id']);

            return;
        }

        $row = Service_Nj591_Company::by_code($ref['profile']);

        if ( ! $row)
        {
            $step['status'] = 'missing';

            return;
        }

        $step['code'] = (string) $row['code'];

        if ( ! self::mine($ctx, (int) $row['user_id'], '', ''))
        {
            $step['status'] = 'locked';

            return;
        }

        // 'new' в nj591 — «ещё не запускался»: приводим к общей шкале
        $row = Service_Nj591_Company::reconcile($row);

        self::_live($step, ! empty($row['job_id']) ? (int) $row['job_id'] : 0);

        $step['stage'] = (string) ($row['progress'] !== '' ? $row['progress'] : $step['stage']);
        $step['status'] = self::map_status((string) $row['status']);

        if ($step['status'] === 'failed')
        {
            $step['error'] = (string) $row['error'];
        }

        if ($step['status'] === 'done')
        {
            $data = Service_Nj591_Company::result_of($row);
            $data['_rev'] = (string) ($row['finished_at'] ?? $row['updated_at']);
            $step['data'] = $data;
            $step['page'] = '/company/'.$step['code'];
        }
    }

    /** Наблюдение за рисками (nj609): живой обход + найденные события. */
    protected static function _watch(array &$step, array $ref, array $ctx)
    {
        if ($ref['watch'] === '' OR ! class_exists('Service_Nj609_Watchdog'))
        {
            $step['status'] = 'missing';
            $step['needs_login'] = empty($ctx['user_id']);

            return;
        }

        $user_id = ! empty($ctx['user_id']) ? (int) $ctx['user_id'] : NULL;
        $subject = Service_Nj609_Watchdog::subject_by_code($ref['watch'], $user_id, FALSE);

        if ( ! $subject)
        {
            $step['status'] = 'locked';

            return;
        }

        $step['code']  = (string) $subject['code'];
        $step['page']  = '/watchdog#'.$step['code'];
        $step['stage'] = trim((string) $subject['company'].((string) $subject['inn'] !== '' ? ' · ИНН '.$subject['inn'] : ''));

        // Наблюдение — не разовая работа: событий может не быть вовсе.
        $step['events'] = Service_Nj609_Watchdog::events_of_subject((int) $subject['id'], $user_id, 8);

        if (class_exists('Service_Nj120_JobQueue'))
        {
            $live = Service_Nj609_Watchdog::live_for($ref['watch'], $user_id, FALSE);

            if (is_array($live) AND ! empty($live['found']))
            {
                $step['elapsed_sec'] = (int) ($live['elapsed_sec'] ?? 0);
                $step['silent_sec']  = $live['silent_sec'] ?? NULL;

                $live_status = (string) ($live['status'] ?? '');

                if ($live_status === 'running')
                {
                    $step['status'] = 'running';
                    $step['stage']  = trim((string) ($live['stage'] ?? '')) !== ''
                        ? (string) $live['stage']
                        : 'проверяем суды, банкротство и изменения реестра';
                }
                elseif ($live_status === 'failed')
                {
                    // наблюдение остаётся включённым, но честно говорим про сбой
                    $step['stage'] = 'последняя проверка не удалась — попробуем в следующий обход';
                }
            }
        }

        // Наблюдение всегда «включено», даже когда обход закончился: показываем
        // его как готовое состояние, если прямо сейчас ничего не выполняется.
        if ($step['status'] === 'missing')
        {
            $step['status'] = 'watching';
        }
    }

    /**
     * Живой статус задачи очереди: этап, секунды, тишина. Если пульса нет дольше
     * общего порога (~4 мин) или прогресс застыл (10 мин) — останавливаем задачу
     * так же, как страницы владельцев: иначе пользователь смотрит на спиннер
     * бесконечно.
     */
    protected static function _live(array &$step, $job_id)
    {
        if ((int) $job_id <= 0 OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return;
        }

        try
        {
            $queue = Service_Nj120_JobQueue::instance();
            $live  = $queue->status_of((int) $job_id);

            if (empty($live['found']))
            {
                return;
            }

            $step['elapsed_sec'] = (int) ($live['elapsed_sec'] ?? 0);
            $step['silent_sec']  = $live['silent_sec'] ?? NULL;

            if ((string) ($live['status'] ?? '') !== 'running')
            {
                return;
            }

            $step['stage'] = (string) ($live['stage'] ?? $step['stage']);

            $stopped = FALSE;

            if ($live['silent_sec'] !== NULL AND (int) $live['silent_sec'] > $queue->silent_stop_sec())
            {
                $stopped = $queue->auto_stop_silent((int) $job_id) > 0;
            }

            if ( ! $stopped AND $queue->auto_stop_stalled((int) $job_id) > 0)
            {
                $stopped = TRUE;
            }

            if ($stopped)
            {
                $step['stage']  = 'Работа остановлена — воркер не отвечал';
                $step['status'] = 'failed';
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj635: живой статус задачи: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Внутренний статус владельца → общая шкала отчёта (тестируется стендом). */
    public static function map_status($status)
    {
        $map = [
            'new'      => 'queued',
            'queued'   => 'queued',
            'running'  => 'running',
            'done'     => 'done',
            'error'    => 'failed',
            'failed'   => 'failed',
            'cancel'   => 'cancelled',
            'cancelled'=> 'cancelled',
        ];

        return $map[(string) $status] ?? 'running';
    }

    /** Принадлежность артефакта: пользователь, кука посетителя или «гость→логин». */
    public static function mine(array $ctx, $user_id, $visitor_id, $guest_prefix = '')
    {
        if ( ! empty($ctx['user_id']) AND (int) $ctx['user_id'] > 0 AND (int) $ctx['user_id'] === (int) $user_id)
        {
            return TRUE;
        }

        $uid = (string) ($ctx['uid'] ?? '');

        if ($uid !== '' AND (string) $visitor_id === $uid)
        {
            return TRUE;
        }

        $ubid = (string) ($ctx['ubid'] ?? '');

        if ($ubid !== '' AND $guest_prefix !== '' AND (string) $visitor_id === $guest_prefix.$ubid)
        {
            return TRUE;
        }

        return FALSE;
    }

    // -------------------------------------------------------------------------
    // Блоки (SSR и опрос отдают одно и то же)
    // -------------------------------------------------------------------------

    /** HTML-блок шага. */
    public static function block($key, array $step, array $ref)
    {
        $view = '635/parts/'.$key;

        if ( ! Kohana::find_file('views', $view))
        {
            return '';
        }

        return View::factory($view, [
            'step'  => $step,
            'ref'   => $ref,
            'csrf'  => Security::token(),
        ])->render();
    }

    /** Шаги с готовым HTML — ответ /check/api/status. */
    public static function steps_with_html(array $ref, array $ctx)
    {
        $state = self::state($ref, $ctx);

        foreach ($state['steps'] as $key => $step)
        {
            $state['steps'][$key]['html'] = self::block($key, $step, $ref);
        }

        return $state;
    }
}
