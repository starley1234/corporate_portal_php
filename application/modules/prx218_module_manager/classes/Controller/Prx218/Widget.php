<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Controller_Prx218_Widget
 * 
 * HMVC контроллер для отрисовки виджетов статистики реестра модулей.
 * Реализует принцип "Просто, Единообразно, Хорошо".
 * 
 * @package Prx218
 * @version 1.0.0
 */
class Controller_Prx218_Widget extends Controller_Base {

    /**
     * Виджет сводной статистики для дашборда.
     * Используется через Request::factory('prx218_widget/summary')->execute();
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
        $service = Service_Prx218_Scanner::instance();

        // 3. Получение данных через сервис (бизнес-логика инкапсулирована)
        // В данном случае получаем агрегированные данные по модулям
        $stats = [
            'total'     => (int) ORM::factory('Prx218_Module')->where('is_deleted', '=', 0)->count_all(),
            // active — число учтённых в реестре, не опубликованных сервисов.
            'active'    => (int) ORM::factory('Prx218_Module')->where('is_published', '=', 1)->where('is_deleted', '=', 0)->count_all(),
            'stable'    => (int) ORM::factory('Prx218_Module')->where('status', '=', 'stable')->where('is_deleted', '=', 0)->count_all(),
            'critical'  => (int) $service->get_dependency_errors_count(), // Пример метода в сервисе
        ];

        // 4. Вывод представления
        $this->response->body(View::factory('prx218/widgets/summary')
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
            'pending'   => (int) ORM::factory('Prx218_Llm')->where('analysis_status', '=', 'pending')->count_all(),
            'completed' => (int) ORM::factory('Prx218_Llm')->where('analysis_status', '=', 'completed')->count_all(),
            'failed'    => (int) ORM::factory('Prx218_Llm')->where('analysis_status', '=', 'failed')->count_all(),
        ];

        $this->response->body(View::factory('prx218/widgets/llm_status')
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

        $total = (int) ORM::factory('Prx218_Module')->where('is_deleted', '=', 0)->count_all();
        $included = (int) ORM::factory('Prx218_Module')->where('is_published', '=', 1)->where('is_deleted', '=', 0)->count_all();

        $this->_json_response([
            'status' => 'ok',
            'data'   => [
                'total' => $total,
                'published' => $included,
                'percent' => $total > 0 ? round(($included / $total) * 100, 2) : 0
            ]
        ]);
    }
}