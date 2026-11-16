<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Controller_Prl218_Widget
 * 
 * HMVC контроллер для отрисовки виджетов статистики реестра модулей.
 * Реализует принцип "Просто, Единообразно, Хорошо".
 * 
 * @package Prl218
 * @version 1.0.0
 */
class Controller_Prl218_Widget extends Controller_Base {

    /**
     * Виджет сводной статистики для дашборда.
     * Используется через Request::factory('prl218_widget/summary')->execute();
     */
    public function action_summary()
    {
        // 1. Проверка прав доступа (чтение реестра)
        if ( ! $this->can('module_manager.view'))
        {
            $this->response->body('');
            return;
        }

        // 2. Доступ к сервису
        $service = Service_Prl218_Scanner::instance();

        // 3. Получение данных через сервис (бизнес-логика инкапсулирована)
        // В данном случае получаем агрегированные данные по модулям
        $stats = [
            'total'     => (int) ORM::factory('Prl218_Module')->where('is_deleted', '=', 0)->count_all(),
            'active'    => (int) ORM::factory('Prl218_Module')->where('is_published', '=', 1)->where('is_deleted', '=', 0)->count_all(),
            'stable'    => (int) OR_factory('Prl218_Module')->where('status', '=', 'stable')->where('is_deleted', '=', 0)->count_all(),
            'critical'  => (int) $service->get_dependency_errors_count(), // Пример метода в сервисе
        ];

        // 4. Вывод представления
        $this->response->body(View::factory('prl218/widgets/summary')
            ->set('stats', $stats)
            ->render());
    }

    /**
     * Виджет статуса LLM анализа.
     */
    public function action_llm_status()
    {
        if ( ! $this->can('module_manager.view'))
        {
            $this->response->body('');
            return;
        }

        $stats = [
            'pending'   => (int) ORM::factory('Prl218_Llm')->where('analysis_status', '=', 'pending')->count_all(),
            'completed' => (int) ORM::factory('Prl218_Llm')->where('analysis_status', '=', 'completed')->count_all(),
            'failed'    => (int) ORM::factory('Prl218_Llm')->where('analysis_status', '=', 'failed')->count_all(),
        ];

        $this->response->body(View::factory('prl218/widgets/llm_status')
            ->set('stats', $stats)
            ->render());
    }

    /**
     * API метод для получения данных виджета в формате JSON (например, для динамического обновления)
     */
    public function action_get_json_stats()
    {
        if ( ! $this->can('module_manager.view'))
        {
            $this->_json_response(['error' => 'Access Denied'], 403);
            return;
        }

        $total = (int) ORM::factory('Prl218_Module')->where('is_deleted', '=', 0)->count_all();
        $published = (int) ORM::factory('Prl218_Module')->where('is_published', '=', 1)->where('is_deleted', '=', 0)->count_all();

        $this->_json_response([
            'status' => 'ok',
            'data'   => [
                'total' => $total,
                'published' => $published,
                'percent' => $total > 0 ? round(($published / $total) * 100, 2) : 0
            ]
        ]);
    }
}