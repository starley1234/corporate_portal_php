<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj674_McpGuard extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();
        $this->set_title('MCP Guard — статическая проверка инструментов MCP');
        $this->set_description('Безсетевой анализ JSON-описаний MCP tools: широкие полномочия, опасные операции, неоднозначные схемы и пропущенные аннотации.');
        $this->set_module_nav([
            ['title' => 'MCP Guard', 'href' => '/mcpguard', 'icon' => 'fas fa-shield-halved'],
            ['title' => 'Сканер ИИ', 'href' => '/aiscan', 'icon' => 'fas fa-shield-halved'],
        ]);
    }

    public function action_index()
    {
        if ( ! Gate::logged_in())
        {
            $this->template->content = Gate::wall('mcpguard', [
                'icon' => 'fas fa-shield-halved',
                'title' => 'Проверка описаний MCP-инструментов',
                'text' => 'Вставленный JSON может содержать внутренние названия инструментов. Войдите под администратором; исходные данные не сохраняются и никуда не отправляются.',
                'points' => ['Разбор выполняется только на этом сервере', 'MCP-эндпоинт не вызывается', 'Результат — эвристическая проверка, не сертификат безопасности'],
            ]);
            return;
        }

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'MCP Guard доступен только администратору');
        }

        $json = '';
        $result = NULL;
        if ($this->request->method() === HTTP_Request::POST)
        {
            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу и повторите.');
            }
            $json = (string) $this->request->post('tools_json');
            $result = Service_Nj674_McpGuard::analyze($json);
            // Не возвращаем присланные описания в HTML-ответе браузеру.
            $json = '';
        }

        $this->template->content = View::factory('674/index', [
            'json' => $json,
            'result' => $result,
            'csrf' => Security::token(),
        ]);
    }
}
