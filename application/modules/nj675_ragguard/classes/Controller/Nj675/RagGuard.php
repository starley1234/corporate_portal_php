<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj675_RagGuard extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();
        $this->set_title('RAG Guard — проверка извлечённого контекста');
        $this->set_description('Проверьте извлечённые RAG-документы на смешение tenant, prompt injection и признаки секретов или персональных данных до передачи модели.');
        $this->set_module_nav([
            ['title' => 'RAG Guard', 'href' => '/ragguard', 'icon' => 'fas fa-database'],
            ['title' => 'Сканер ИИ', 'href' => '/aiscan', 'icon' => 'fas fa-shield-halved'],
        ]);
    }

    public function action_index()
    {
        if ( ! Gate::logged_in())
        {
            $this->template->content = Gate::wall('ragguard', [
                'icon' => 'fas fa-database',
                'title' => 'Проверка извлечённого RAG-контекста',
                'text' => 'Контекст может содержать внутренние сведения. Войдите под администратором; введённые тексты анализируются без сохранения и не отправляются модели.',
                'points' => ['Проверяется только вставленный набор документов', 'Источник и текст документов не отображаются в результате', 'Статические сигналы не доказывают безопасность всей RAG-системы'],
            ]);
            return;
        }

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'RAG Guard доступен только администратору');
        }

        $json = '';
        $result = NULL;
        if ($this->request->method() === HTTP_Request::POST)
        {
            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу и повторите.');
            }
            $json = (string) $this->request->post('context_json');
            $result = Service_Nj675_RagGuard::analyze($json);
            // Контекст не отражаем назад в HTML и не сохраняем.
            $json = '';
        }

        $this->template->content = View::factory('675/index', [
            'json' => $json,
            'result' => $result,
            'csrf' => Security::token(),
        ]);
    }
}
