<?php defined('SYSPATH') or die('No direct script access.');

/**
 * UI prx400_specflow. Права: просмотр prx400.view, действия prx400.run
 * (deny by default, ключи — application/config/acl.php). POST — CSRF.
 */
class Controller_Prx400_Ui extends Controller_Base {

    public function before()
    {
        parent::before();
        if ( ! $this->can('prx400.view'))
        {
            throw HTTP_Exception::factory(403, 'Оцифровка спецификаций доступна администратору');
        }
        $this->response->headers('Cache-Control', 'no-store');
        $this->set_title('Оцифровка спецификаций');
    }

    public function after()
    {
        if ($this->request->action() === 'file')
        {
            // бинарный ответ — без layout
            return;
        }
        parent::after();
    }

    /** Список спецификаций + загрузка PDF. */
    public function action_index()
    {
        $service = new Service_Prx400_Specs;
        $flash = Session::instance()->get_once('prx400_flash', ['text' => '']);

        if ($this->request->method() === Request::POST)
        {
            $this->_guard_csrf();
            $spec = $service->create($this->actor(), $_FILES);
            Session::instance()->set('prx400_flash', ['type' => 'ok', 'text' => 'Спецификация загружена — разбор идёт в очереди воркеров.']);
            HTTP::redirect('/prx400_specflow/spec/'.$spec['id'], 303);
        }

        $this->template->content = View::factory('prx400/index', [
            'specs' => $service->list($this->actor()),
            'templates' => Service_Prx400_Templates::options(),
            'flash' => $flash,
            'csrf' => Security::token(),
        ]);
    }

    /** Карточка спецификации: таблица, цвета качества, верификация, экспорт. */
    public function action_spec()
    {
        $id = (int) $this->request->param('id');
        $data = (new Service_Prx400_Specs)->spec($this->actor(), $id);

        $this->template->content = View::factory('prx400/spec', $data + [
            'csrf' => Security::token(),
            'flash' => Session::instance()->get_once('prx400_flash', ['text' => '']),
        ]);
    }

    /** Подтвердить строку (или назначить код НСИ вручную: ?code=). */
    public function action_verify()
    {
        $id = (int) $this->request->param('id');
        $this->_guard_csrf();
        if ( ! $this->can('prx400.run'))
        {
            throw HTTP_Exception::factory(403, 'Действие доступно с правом prx400.run');
        }

        $spec_id = 0;
        try
        {
            (new Service_Prx400_Specs)->verify_row($this->actor(), $id, (string) $this->request->post('code'));
            $row = Model_Prx400_Row::get($id);
            $spec_id = (int) ($row['spec_id'] ?? 0);
        }
        catch (Prx400_Error $e)
        {
            Session::instance()->set('prx400_flash', ['type' => 'err', 'text' => 'Не удалось подтвердить: '.$e->getMessage()]);
        }

        HTTP::redirect('/prx400_specflow/spec/'.$spec_id, 303);
    }

    /** Завершить проверку: review → done. */
    public function action_finish()
    {
        $id = (int) $this->request->param('id');
        $this->_guard_csrf();
        if ( ! $this->can('prx400.run'))
        {
            throw HTTP_Exception::factory(403, 'Действие доступно с правом prx400.run');
        }

        try
        {
            (new Service_Prx400_Specs)->finish($this->actor(), $id);
        }
        catch (Prx400_Error $e)
        {
            Session::instance()->set('prx400_flash', ['type' => 'err', 'text' => 'Не удалось завершить: '.$e->getMessage()]);
        }

        HTTP::redirect('/prx400_specflow/spec/'.$id, 303);
    }

    /** Повторный запуск разбора. */
    public function action_launch()
    {
        $id = (int) $this->request->param('id');
        $this->_guard_csrf();
        if ( ! $this->can('prx400.run'))
        {
            throw HTTP_Exception::factory(403, 'Действие доступно с правом prx400.run');
        }

        try
        {
            (new Service_Prx400_Specs)->launch($this->actor(), $id);
        }
        catch (Prx400_Error $e)
        {
            Session::instance()->set('prx400_flash', ['type' => 'err', 'text' => 'Не удалось запустить: '.$e->getMessage()]);
        }

        HTTP::redirect('/prx400_specflow/spec/'.$id, 303);
    }

    /** Исходный PDF (скачивание для сверки side-by-side). */
    public function action_file()
    {
        $pdf = (new Service_Prx400_Specs)->read_pdf((int) $this->request->param('id'));
        $this->response->headers('Content-Type', 'application/pdf');
        $this->response->headers('Content-Length', (string) strlen($pdf));
        $this->response->headers('Content-Disposition', 'inline');
        $this->response->body($pdf);
    }

    /** Экспорт JSON для ERP (?format=csv — быстрая ручная выгрузка). */
    public function action_export()
    {
        $id = (int) $this->request->param('id');
        $data = (new Service_Prx400_Specs)->spec($this->actor(), $id);
        $format = (string) $this->request->query('format');

        if ($format === 'csv')
        {
            $body = Service_Prx400_Export::csv($data['spec'], $data['rows']);
            $this->response->headers('Content-Type', 'text/csv; charset=utf-8');
            $this->response->headers('Content-Disposition', 'attachment; filename="spec-'.$id.'.csv"');
            $this->response->body($body);

            return;
        }

        $body = json_encode(Service_Prx400_Export::json($data['spec'], $data['rows']),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->headers('Content-Disposition', 'attachment; filename="spec-'.$id.'.json"');
        $this->response->body($body);
    }

    private function _guard_csrf()
    {
        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Сессия формы устарела');
        }
    }
}
