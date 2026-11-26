<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100: «Использование модулей» /prx100/usage — лента «кто что делал и чем
 * закончилось» для администратора. Источники — существующие таблицы
 * (события prx208, задачи prx120, внешние вызовы prx150); страница ничего
 * не пишет. Фильтры GET: период, модуль, результат — ссылкой можно поделиться.
 */
class Controller_Prx103_Usage extends Controller_Prx102_Base {

    const LIMIT = 200;
    const LIMIT_MAX = 500;

    public function before()
    {
        parent::before();

        if ( ! $this->can('prx100.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Использование модулей — администрирование');
        $this->set_module_nav([
            ['title' => 'Администрирование', 'href' => '/admin', 'icon' => 'fas fa-gear'],
            ['title' => 'События', 'href' => '/prx208_events', 'icon' => 'fas fa-bolt'],
            ['title' => 'Очередь', 'href' => '/prx120/monitor', 'icon' => 'fas fa-list-check'],
            ['title' => 'API-вызовы', 'href' => '/prx150_api_hub?tab=usage', 'icon' => 'fas fa-key'],
        ]);
    }

    public function action_index()
    {
        $window = (string) $this->request->query('window');
        if ( ! isset(Service_Prx103_Usage::WINDOWS[$window])) $window = '24h';

        $module = (string) $this->request->query('module');
        $modules = Service_Prx103_Usage::modules();
        if ($module !== '' AND ! isset($modules[$module])) $module = '';

        $result = (string) $this->request->query('result');
        if ( ! in_array($result, ['ok', 'err'], TRUE)) $result = '';

        $limit = min(self::LIMIT_MAX, max(1, (int) $this->request->query('limit') ?: self::LIMIT));

        $feed = Service_Prx103_Usage::feed([
            'window' => $window, 'module' => $module, 'result' => $result, 'limit' => $limit,
        ]);

        $this->template->content = View::factory('prx103/usage', [
            'rows'      => $feed['rows'],
            'note'      => $feed['note'],
            'modules'   => $modules,
            'window'    => $window,
            'module'    => $module,
            'result'    => $result,
            'limit'     => $limit,
        ]);
    }
}
