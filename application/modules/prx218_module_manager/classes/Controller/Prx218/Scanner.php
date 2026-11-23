<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Controller_Prx218_Scanner
 * 
 * Контроллер управления процессом сканирования директории modules 
 * и запуска LLM анализа для модуля Module Manager (Registry).
 * 
 * @package Prx218
 * @version 1.0.0
 */
class Controller_Prx218_Scanner extends Controller_Prx218_Main {

    /**
     * Главная страница сканера
     */
    public function action_index()
    {
        if ( ! $this->can('module_manager.scan'))
        {
            throw new HTTP_Exception_403('Access Denied');
        }

        $this->set_title(__('Сканирование системы'));
        
        $config = Kohana::$config->load('prx218_module_manager');
        
        $this->template->content = View::factory('prx218/scanner/index')
            ->set('scan_path', $config['scan_path'] ?? 'application/modules/')
            ->set('last_scan', Model_Prx218_Module::get_last_scan_date());
    }

    /**
     * Запуск процесса поиска новых модулей в файловой системе
     */
    public function action_run()
    {
        if ( ! $this->can('module_manager.scan'))
        {
            $this->_json_response(['status' => 'error', 'message' => __('Forbidden')], 403);
            return;
        }

        $service = Service_Prx218_Scanner::instance();
        
        // Выполняем сканирование директорий
        $result = $service->scan_directory($this->_actor_id() ?: 0);

        // Оповещаем систему об обновлении реестра
        Event::publish('prx218.scanner.finished', [
            'found'   => (int) Arr::get($result, 'found', 0),
            'created' => (int) Arr::get($result, 'created', 0),
            'updated' => (int) Arr::get($result, 'updated', 0),
            'skipped' => (int) Arr::get($result, 'skipped', 0),
        ], $this->_actor_id());

        $this->_json_response([
            'status' => 'ok',
            'found'  => (int) Arr::get($result, 'found', 0),
            'added'  => (int) Arr::get($result, 'created', 0), // Изменено с 'added' на 'created'
            'message' => __('Сканирование завершено успешно')
        ]);
    }

    /**
     * Запуск LLM анализа для конкретного модуля
     */
    public function action_analyze()
    {
        $id = (int) $this->request->param('id');
        $module = ORM::factory('Prx218_Module', $id);

        if ( ! $module->loaded())
        {
            $this->_json_response(['status' => 'error', 'message' => __('Module not found')], 404);
            return;
        }

        if ( ! $this->can('module_manager.ai_analyze', $module))
        {
            $this->_json_response(['status' => 'error', 'message' => __('Access Denied')], 403);
            return;
        }

        $service = Service_Prx218_LlmProcessor::instance();
        
        // Запуск процесса анализа через LLM
        $analysis = $service->run_ai_analysis($module->id, $this->_actor_id() ?: 0);

        if ($analysis)
        {
            Event::publish('prx218.module.ai_analyzed', [
                'module_id'   => (int) $module->id,
                'system_name' => (string) $module->system_name,
                'analysis_id' => (int) $analysis->id,
            ], $this->_actor_id());
            
            $this->_json_response([
                'status' => 'ok',
                'summary' => $analysis->ai_summary
            ]);
        }
        else
        {
            $this->_json_response(['status' => 'error', 'message' => __('Ошибка при работе с LLM')], 500);
        }
    }

    /**
     * Массовый запуск анализа для всех необработанных модулей
     */
    public function action_batch_analyze()
    {
        if ( ! $this->can('module_manager.ai_analyze'))
        {
            $this->_json_response(['status' => 'error', 'message' => __('Access Denied')], 403);
            return;
        }

        $service = Service_Prx218_LlmProcessor::instance();
        $count = $service->process_pending_modules();

        $this->_json_response([
            'status' => 'ok',
            'processed' => (int) $count
        ]);
    }
}