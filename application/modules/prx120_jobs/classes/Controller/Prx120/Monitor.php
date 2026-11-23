<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120: «Монитор воркеров» /prx120/monitor — живое состояние очереди и
 * воркерного контура: что висит, что исполняется, последние N задач, пульс
 * воркера, ошибки, автор задачи, параметры payload. Действия: отменить/прервать
 * (two-click), удалить завершённую (two-click). Право prx120.monitor (только admin).
 */
class Controller_Prx120_Monitor extends Controller_Prx102_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('prx120.monitor'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

    }

    public function action_index()
    {
        $queue = Service_Prx120_JobQueue::instance();
        $p     = (string) $this->request->query('p');

        if ( ! in_array($p, ['pending', 'processing', 'done', 'failed', 'cancelled'], TRUE))
        {
            $p = '';
        }

        // Самолечение при каждом заходе: зависшие processing (мёртвый воркер)
        // разбираются сами — исчерпанные в failed, остальные обратно в очередь.
        try
        {
            $queue->requeue_stale();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'prx120 monitor: sweep: :e', [':e' => $e->getMessage()]);
        }

        $flash = '';

        if ($this->request->method() === Request::POST)
        {
            $act    = (string) $this->request->post('act');
            $job_id = (int) $this->request->post('job_id');

            // Общие операции и новая проверка не требуют job_id.
            $needs_id = ! in_array($act, ['kill_stale', 'clear', 'probe'], TRUE);

            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            elseif ($job_id <= 0 AND $needs_id)
            {
                $flash = 'Не указана задача';
            }
            else
            {
                try
                {
                    switch ($act)
                    {
                        case 'probe':
                            [$id] = Service_Prx120_Probe::submit();
                            $flash = 'PHP поставил проверку #'.$id.' (echo). Дождитесь результата Python в списке задач.';
                            break;

                        case 'clear':
                            $n = $queue->clear_all();
                            $flash = $n > 0 ? 'Очищено записей: '.$n : 'Очищать нечего (очередь пуста)';
                            break;

                        case 'kill_stale':
                            // Массовое прерывание зависших (без пульса > 30 мин)
                            $n = $queue->kill_stale(1800);
                            $flash = $n > 0 ? 'Прервано зависших задач: '.$n : 'Зависших задач нет';
                            break;

                        case 'cancel':
                            // Отмена исполняющейся задачи = прерывание: не путаем
                            // администратора ответом «отменять нечего».
                            $job = $job_id > 0 ? $queue->get($job_id) : NULL;

                            if ( ! $job)
                            {
                                $flash = 'Задача не найдена';
                            }
                            elseif ($job['status'] === 'processing')
                            {
                                $queue->cancel($job_id, TRUE);
                                $flash = 'Задача #'.$job_id.' прервана (исполняющаяся)';
                            }
                            elseif ($job['status'] !== 'pending')
                            {
                                $flash = 'Задача #'.$job_id.' уже «'.$job['status'].'» — отменять нечего';
                            }
                            else
                            {
                                $queue->cancel($job_id);
                                $flash = 'Задача #'.$job_id.' отменена';
                            }
                            break;

                        case 'kill':
                            $job = $job_id > 0 ? $queue->get($job_id) : NULL;

                            if ( ! $job)
                            {
                                $flash = 'Задача не найдена';
                            }
                            elseif ($job['status'] !== 'processing')
                            {
                                $flash = 'Задача #'.$job_id.' не исполняется (статус «'.$job['status'].'») — прерывать нечего';
                            }
                            else
                            {
                                $queue->cancel($job_id, TRUE);
                                $flash = 'Задача #'.$job_id.' прервана (исполняющаяся)';
                            }
                            break;

                        case 'delete':
                            $queue->delete_job($job_id);
                            $flash = 'Задача #'.$job_id.' удалена';
                            break;

                        default:
                            $flash = 'Неизвестное действие';
                    }
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::WARNING, 'prx120 monitor: :a :i: :e',
                        [':a' => $act, ':i' => $job_id, ':e' => $e->getMessage()]);
                    $flash = 'Не получилось: '.$e->getMessage();
                }
            }

            // первый query-параметр — всегда '?': при пустой пагинации получали
            // /prx120/monitor&flash=… (404 после удаления, найдено 21.09)
            // AJAX-ветка: таблица большая, перезагрузка страницы после
            // отмены/удаления строки раздражает — отвечаем JSON.
            if ($this->request->post('ajax') === '1'
                OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest')
            {
                $this->auto_render = FALSE;
                $this->response->headers('Content-Type', 'application/json; charset=utf-8')
                    ->body(json_encode([
                        'ok'    => ! preg_match('~^(Не получилось|Сессия|Не указана|Неизвестное)~u', $flash),
                        'flash' => $flash,
                    ], JSON_UNESCAPED_UNICODE));

                return;
            }

            HTTP::redirect('/prx120/monitor'.($p !== '' ? '?p='.$p.'&' : '?')
                .'flash='.urlencode($flash), 303);
        }

        $this->set_title('Монитор воркеров · управление');

        $channels_ui = Manifest::channels_ui();

        $jobs = [];

        foreach ($queue->monitor_jobs($p, 50) as $r)
        {
            $r['payload_arr'] = json_decode((string) $r['payload'], TRUE) ?: [];
            $r['title']       = Service_Prx120_JobQueue::job_title_of($r['payload_arr']);
            $r['label']       = (string) ($channels_ui[$r['channel']]['label'] ?? $r['channel']);
            $r['url_tpl']     = (string) ($channels_ui[$r['channel']]['url'] ?? '');

            // прямая ссылка на страницу результата (если в payload есть код)
            $r['url'] = '';

            foreach (['code', 'search_id', 'id'] as $key)
            {
                if ($r['url_tpl'] !== '' AND ! empty($r['payload_arr'][$key]))
                {
                    $r['url'] = str_replace('<code>', urlencode((string) $r['payload_arr'][$key]), $r['url_tpl']);
                    break;
                }
            }

            $jobs[] = $r;
        }

        $this->template->content = View::factory('120/monitor', [
            'job_id'   => (int) $this->request->query('job'),
            'p'        => $p,
            'health'   => $queue->monitor_health(),
            'stale'    => $queue->stale_count(1800),
            // Порог «воркер молчит» — из сервиса (единое место), чтобы в
            // таблице показать обратный отсчёт авто-остановки, а не только
            // «пульс N мин»: владелец видел зависшую строку и не понимал,
            // что с ней произойдёт дальше.
            'silent_stop' => $queue->silent_stop_sec(),
            'jobs'     => $jobs,
            'flash'    => isset($_GET['flash']) ? (string) $_GET['flash'] : $flash,
            'csrf'     => Security::token(),
        ]);
    }
}
