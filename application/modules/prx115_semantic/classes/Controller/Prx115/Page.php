<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx115: страница семантики /prx115 — «видно глазами, что модуль работает».
 *
 * До этой страницы у модуля был только JSON-API: понять, есть ли векторы,
 * идёт ли индексация, жив ли эмбеддинг-сервер, можно было только по логам и
 * по монитору воркеров. Владелец (28.09.2026): «займись модулем prx115, чтобы
 * он полноценно работал» — критерий «полноценно» проверяется именно здесь:
 *
 *   * коллекции и число векторов (что реально проиндексировано);
 *   * живой статус-ряд последней задачи semantic.index (идёт / Готово / Ошибка);
 *   * кнопка «Проверить» — живой вызов эмбеддинг-сервера (размерность и мс);
 *   * тестовый поиск: запрос → результаты с дистанциями;
 *   * очередь канала: последние задачи, этап, ошибка, «Повторить».
 *
 * Действия — через /prx115/api/* (ajax, без перезагрузки страницы). Доступ:
 * право `prx115.admin` (роль admin).
 */
class Controller_Prx115_Page extends Controller_Prx102_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('prx115.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Семантика · поиск и векторы');
        $this->set_description('Векторы платформы: коллекции, индексация через очередь, семантический поиск. Здесь видно, что проиндексировано, живой ли эмбеддинг-сервер и что делает последняя задача.');

        $this->set_module_nav([
            ['title' => 'Монитор воркеров', 'href' => '/prx120/monitor', 'icon' => 'fas fa-gauge-high', 'acl' => 'prx120.monitor'],
            ['title' => 'Воркеры · док', 'href' => '/prx120/workers', 'icon' => 'fas fa-book'],
        ]);
    }

    /** /prx115 — одна страница: коллекции, очередь, проверка, поиск. */
    public function action_index()
    {
        $panel = [];

        try
        {
            $panel = Service_Prx115_Semantic::panel();
        }
        catch (Throwable $e)
        {
            $panel = ['error' => $e->getMessage(), 'collections' => [], 'queue' => [], 'health' => [], 'totals' => []];
        }

        // Карточку «Индексация» показываем только при ЖИВОЙ задаче: queued
        // старше 30 минут или running без пульса 30 минут — это «воркер не
        // работает», а не «ждём свободного» (жалоба владельца: «крутится
        // безтолку, я ничего не запускал»). Готово/ошибка — 15 минут после
        // завершения, чтобы ряд успел показать итог и перезагрузить страницу.
        $live = FALSE;
        try
        {
            $status = class_exists('Service_Prx120_JobQueue')
                ? Service_Prx120_JobQueue::instance()->last_status('semantic.index') : [];
            if ( ! empty($status['found']))
            {
                $state = (string) $status['status'];
                $silent = $status['silent_sec'] ?? NULL;
                $elapsed = (int) ($status['elapsed_sec'] ?? 0);
                $finished = isset($status['finished_at']) && $status['finished_at']
                    ? strtotime((string) $status['finished_at']) : NULL;
                $live = ($state === 'queued' AND $elapsed < 1800)
                    OR ($state === 'running' AND ($silent === NULL OR $silent < 1800))
                    OR (in_array($state, ['done', 'failed', 'cancelled'], TRUE)
                        AND $finished !== NULL AND (time() - $finished) < 900);
            }
        }
        catch (Throwable $e)
        {
            $live = FALSE; // очередь недоступна — ряд не показываем
        }

        $this->template->content = View::factory('prx115/index', [
            'panel' => $panel,
            'live'  => $live,
            'csrf'  => Security::token(),
        ]);
    }
}
