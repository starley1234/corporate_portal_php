<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120: «Задача» /prx120/task/<id> — страница одной задачи очереди.
 *
 * Для администратора: статус, параметры (payload), итог (result), журнал
 * событий-прогресса prx_120_job_events (в т.ч. рапорты ctx.progress() от
 * воркеров). Лёгкий спутник монитора: из строки /prx120/monitor клик по «#N»
 * ведёт сюда.
 */
class Controller_Prx120_Task extends Controller_Prx102_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('prx120.monitor'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }


        $this->set_module_nav([
            ['title' => 'Монитор очереди', 'href' => '/prx120/monitor', 'icon' => 'fas fa-gauge-high'],
        ]);
    }

    public function action_index()
    {
        $queue  = Service_Prx120_JobQueue::instance();
        $job_id = (int) $this->request->param('id');

        // Открыли карточку задачи — заодно разбираем зависшие. Иначе владелец
        // видел «Выполняется» и не понимал, что делать: авто-разбор висел
        // только на главной монитора (владелец, 26.09: «процесс висит»).
        try
        {
            $queue->requeue_stale();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'prx120: карточка задачи — разбор зависших: :e',
                [':e' => $e->getMessage()]);
        }

        $job = $job_id > 0 ? $queue->get($job_id) : NULL;

        if ($job === NULL)
        {
            throw HTTP_Exception::factory(404, 'Задача не найдена');
        }

        $channels_ui = Manifest::channels_ui();

        $this->set_title('Задача #'.$job_id.' · Монитор воркеров');

        $this->template->content = View::factory('120/task', [
            'job'       => $job,
            'job_id'    => $job_id,
            'label'     => (string) ($channels_ui[$job['channel']]['label'] ?? $job['channel']),
            'events'    => $queue->events($job_id),
            // «Почему висит и что дальше»: возраст пульса и прогресса, какое
            // правило уборки сработает и через сколько. Без этого владелец по
            // карточке #969 (semantic.index, 3/3, «Выполняется») не понимал,
            // ждать ему или чинить воркер (28.09).
            'diagnosis' => $queue->diagnose($job_id),
        ]);
    }
}
