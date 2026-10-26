<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prl120: «Документация воркеров» /prl120/workers — для администратора.
 *
 * Автособираемая справка: для каждого обработчика из workers/prl_workers/
 * handlers — человекопонятное описание (docstring), каналы очереди, шаги
 * пайплайна с mermaid-диаграммой, переменные окружения. Источник — сам код,
 * поэтому документация не устаревает. Право prl120.monitor (только admin).
 */
class Controller_Prl120_Workers extends Controller_Prl100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('prl120.monitor'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Документация воркеров — что делает каждый обработчик фоновых задач');
        $this->set_description('Автособираемая справка по воркерам PRL: описание на человеческом языке, каналы очереди, mermaid-схемы пайплайнов и переменные окружения.');

        // «Монитор» рядом: страница-спутник монитора воркеров
        $this->set_module_nav([
            ['title' => 'Монитор очереди', 'href' => '/prl120/monitor', 'icon' => 'fas fa-gauge-high'],
        ]);
    }

    public function action_index()
    {
        $this->template->content = View::factory('120/workers', [
            'workers' => Service_Prl120_Workerdocs::all(),
            'dir'     => Service_Prl120_Workerdocs::handlers_dir(),
        ]);
    }

}
