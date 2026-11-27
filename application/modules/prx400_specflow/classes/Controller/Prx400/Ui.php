<?php defined('SYSPATH') or die('No direct script access.');

/**
 * UI prx400_specflow. Просмотр — prx400.view, действия — prx400.run
 * (Policy_Prx400_Spec; ключи — application/config/acl.php). CSRF всех
 * POST проверяет Prx_PostGuard глобально; GET ничего не пишет.
 * Доменные ошибки: действия — flash и PRG-редирект, страницы — HTTP-статус.
 */
class Controller_Prx400_Ui extends Controller_Base
{
    private const FLASH = 'prx400_flash';

    public function before()
    {
        parent::before();
        if ( ! $this->can(Policy_Prx400_Spec::VIEW_ACL))
        {
            throw HTTP_Exception::factory(403, 'Оцифровка спецификаций доступна администратору');
        }
        $this->response->headers('Cache-Control', 'no-store');
        $this->set_title('Оцифровка спецификаций');
        if ( ! in_array($this->request->action(), ['file', 'export'], TRUE))
        {
            $this->set_module_nav([
                ['title' => 'Спецификации', 'href' => '/prx400_specflow', 'icon' => 'fas fa-file-pdf'],
                ['title' => 'Справочник НСИ', 'href' => '/prx400_specflow/catalog', 'icon' => 'fas fa-book'],
                ['title' => 'Шаблоны', 'href' => '/prx400_specflow/templates', 'icon' => 'fas fa-layer-group'],
                ['title' => 'API', 'href' => '/prx400_specflow/api-docs', 'icon' => 'fas fa-plug'],
            ]);
        }
    }

    public function after()
    {
        if (in_array($this->request->action(), ['file', 'export'], TRUE)) return; // бинарный ответ — без layout
        parent::after();
    }

    /** Список спецификаций + загрузка PDF (POST). */
    public function action_index()
    {
        $this->allow(Request::GET, Request::POST);
        $service = new Service_Prx400_Specs;

        if ($this->request->method() === Request::POST)
        {
            $spec_id = $this->act('/prx400_specflow', function () use ($service): int {
                Service_Prx400_Limits::assert_request_size((int) $this->request->headers('Content-Length'));
                return $service->submit($this->actor(), self::request_key((string) $this->request->post('request_key')),
                    $_FILES['pdf'] ?? NULL, (array) $this->request->post());
            });
            $this->done('/prx400_specflow/spec/'.$spec_id, 'Спецификация загружена — разбор идёт в очереди воркеров.');
            return;
        }

        $this->template->content = View::factory('prx400/index', [
            'specs' => $this->page(fn () => $service->listing($this->actor())),
            'templates' => Service_Prx400_Templates::options(),
            'catalog_count' => Model_Prx400_Catalog::count(),
            'max_pdf' => Service_Prx400_Limits::pdf_label(),
            'can_edit' => $this->can(Policy_Prx400_Spec::EDIT_ACL),
            'request_key' => bin2hex(random_bytes(16)),
            'flash' => $this->flash(),
            'csrf' => Security::token(),
        ]);
    }

    /** Карточка спецификации: таблица, цвета качества, верификация, экспорт. */
    public function action_spec()
    {
        $this->allow(Request::GET);
        $id = (int) $this->request->param('id');
        $data = $this->page(fn () => (new Service_Prx400_Specs)->card($this->actor(), $id));

        $this->template->content = View::factory('prx400/spec', $data + [
            'csrf' => Security::token(),
            'flash' => $this->flash(),
        ]);
    }

    /** Исходный PDF (сверка side-by-side). */
    public function action_file()
    {
        $this->allow(Request::GET);
        $pdf = $this->page(fn () => (new Service_Prx400_Specs)->pdf($this->actor(), (int) $this->request->param('id')));
        $this->response->headers('Content-Type', 'application/pdf');
        $this->response->headers('Content-Length', (string) strlen($pdf));
        $this->response->headers('Content-Disposition', 'inline; filename="spec-'.(int) $this->request->param('id').'.pdf"');
        $this->response->headers('X-Content-Type-Options', 'nosniff');
        $this->response->body($pdf);
    }

    /** Экспорт JSON для ERP (?format=csv — быстрая ручная выгрузка). */
    public function action_export()
    {
        $this->allow(Request::GET);
        $id = (int) $this->request->param('id');
        [$spec, $rows] = $this->page(fn () => (new Service_Prx400_Specs)->export($this->actor(), $id));

        if ((string) $this->request->query('format') === 'csv')
        {
            $this->response->headers('Content-Type', 'text/csv; charset=utf-8');
            $this->response->headers('Content-Disposition', 'attachment; filename="spec-'.$id.'.csv"');
            $this->response->body(Service_Prx400_Export::csv($spec, $rows));
            return;
        }

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->headers('Content-Disposition', 'attachment; filename="spec-'.$id.'.json"');
        $this->response->body(json_encode(Service_Prx400_Export::json($spec, $rows),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** Повторный запуск разбора (режим/шаблон из формы). */
    public function action_launch()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow/spec/'.$id, fn () => (new Service_Prx400_Specs)->launch($this->actor(), $id, (array) $this->request->post()));
        $this->done('/prx400_specflow/spec/'.$id, 'Разбор поставлен в очередь — статус обновится автоматически.');
    }

    /** Перезалить PDF существующей спецификации и перезапустить разбор. */
    public function action_reupload()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow/spec/'.$id, function () use ($id): void {
            Service_Prx400_Limits::assert_request_size((int) $this->request->headers('Content-Length'));
            (new Service_Prx400_Specs)->reupload($this->actor(), $id, $_FILES['pdf'] ?? NULL, (array) $this->request->post());
        });
        $this->done('/prx400_specflow/spec/'.$id, 'Файл перезалит — разбор запущен.');
    }

    /** Подтвердить строку (переключатель) или назначить код НСИ (поле code). */
    public function action_verify()
    {
        $row_id = $this->post_id();
        $back = (string) $this->request->post('back_spec');
        $spec_id = $this->act('/prx400_specflow/spec/'.(int) $back, fn () => (new Service_Prx400_Specs)
            ->verify_row($this->actor(), $row_id, (string) $this->request->post('code')));
        $this->done('/prx400_specflow/spec/'.$spec_id.'#row-'.$row_id, NULL);
    }

    /** Правка полей строки — журнал в prx_400_edits, пересчёт статуса и матчинга. */
    public function action_edit()
    {
        $row_id = $this->post_id();
        $back = (string) $this->request->post('back_spec');
        $spec_id = $this->act('/prx400_specflow/spec/'.(int) $back, fn () => (new Service_Prx400_Specs)
            ->row_edit($this->actor(), $row_id, (array) $this->request->post()));
        $this->done('/prx400_specflow/spec/'.$spec_id.'#row-'.$row_id, 'Строка исправлена — правка в журнале, статус пересчитан.');
    }

    /** Завершить проверку: review → done. */
    public function action_finish()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow/spec/'.$id, fn () => (new Service_Prx400_Specs)
            ->finish($this->actor(), $id, (string) $this->request->post('accept_issues') === '1'));
        $this->done('/prx400_specflow/spec/'.$id, 'Проверка завершена — спецификация готова к выгрузке.');
    }

    /** Удалить спецификацию (позиции и правки каскадом, файл — из хранилища). */
    public function action_delete()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow', fn () => (new Service_Prx400_Specs)->delete($this->actor(), $id));
        $this->done('/prx400_specflow', 'Спецификация удалена: позиции и файл убраны.');
    }

    /** Справочник НСИ: список + добавление (POST). */
    public function action_catalog()
    {
        $this->allow(Request::GET, Request::POST);
        if ($this->request->method() === Request::POST)
        {
            $this->act('/prx400_specflow/catalog', fn () => (new Service_Prx400_Specs)->catalog_add($this->actor(), (array) $this->request->post()));
            $this->done('/prx400_specflow/catalog', 'Позиция сохранена в справочнике НСИ — матчинг применится на следующем разборе.');
            return;
        }

        $this->template->content = View::factory('prx400/catalog', [
            'catalog' => Model_Prx400_Catalog::all(),
            'can_edit' => $this->can(Policy_Prx400_Spec::EDIT_ACL),
            'flash' => $this->flash(),
            'csrf' => Security::token(),
        ]);
    }

    public function action_catalog_delete()
    {
        $id = $this->post_id();
        $this->act('/prx400_specflow/catalog', fn () => (new Service_Prx400_Specs)->catalog_delete($this->actor(), $id));
        $this->done('/prx400_specflow/catalog', 'Позиция убрана из справочника.');
    }

    /** Шаблоны: реестр + загрузка пользовательского JSON (POST). */
    public function action_templates()
    {
        $this->allow(Request::GET, Request::POST);
        if ($this->request->method() === Request::POST)
        {
            $title = $this->act('/prx400_specflow/templates', fn () => Service_Prx400_Templates::upload($this->actor(), $_FILES['tpl'] ?? NULL));
            $this->done('/prx400_specflow/templates', 'Шаблон «'.$title.'» добавлен — применяется с новой задачи разбора.');
            return;
        }

        $this->template->content = View::factory('prx400/templates', [
            'registry' => Service_Prx400_Templates::registry(),
            'can_edit' => $this->can(Policy_Prx400_Spec::EDIT_ACL),
            'flash' => $this->flash(),
            'csrf' => Security::token(),
        ]);
    }

    public function action_template_enable()
    {
        $this->allow(Request::POST);
        $enable = (string) $this->request->post('enable') === '1';
        $this->act('/prx400_specflow/templates', fn () => Service_Prx400_Templates::set_enabled(
            $this->actor(), (string) $this->request->post('code'), $enable));
        $this->done('/prx400_specflow/templates', 'Шаблон '.($enable ? 'включён' : 'отключён').' — применяется с новой задачи разбора.');
    }

    public function action_template_delete()
    {
        $this->allow(Request::POST);
        $this->act('/prx400_specflow/templates', fn () => Service_Prx400_Templates::delete($this->actor(), (string) $this->request->post('code')));
        $this->done('/prx400_specflow/templates', 'Пользовательский шаблон удалён.');
    }

    /** «API для интеграторов»: контракт prx150 (ключ + scope). */
    public function action_api_docs()
    {
        $this->allow(Request::GET);
        $this->template->content = View::factory('prx400/api_docs', [
            'scope' => Service_Prx400_Specs::SCOPE,
            'parse_modes' => Service_Prx400_Limits::parse_modes(),
            'templates' => Service_Prx400_Templates::options(),
            'max_pdf' => Service_Prx400_Limits::pdf_label(),
            'can_keys' => $this->can('prx150.admin'),
        ]);
    }

    // ------------------------------------------------------------------

    /** Разрешённые методы действия; прочие — 405 с Allow. */
    private function allow(string ...$methods): void
    {
        if ( ! in_array($this->request->method(), $methods, TRUE))
        {
            throw (HTTP_Exception::factory(405))->allowed($methods);
        }
    }

    /** ID из маршрута для POST-действия. */
    private function post_id(): int
    {
        $this->allow(Request::POST);

        return (int) $this->request->param('id');
    }

    /** GET-страница: доменная ошибка → HTTP-статус с понятным текстом. */
    private function page(callable $read)
    {
        try {
            return $read();
        } catch (Prx400_Error $e) {
            throw Prx400_Messages::http($e);
        }
    }

    /** POST-действие: доменная ошибка → flash и PRG-редирект на $back. */
    private function act(string $back, callable $operation)
    {
        try {
            return $operation();
        } catch (Prx400_Error $e) {
            $text = Prx400_Messages::text($e->getMessage());
            if (isset($e->details()['issues'])) $text .= ' Осталось: '.(int) $e->details()['issues'].'.';
            Session::instance()->set(self::FLASH, ['type' => 'err', 'text' => $text]);
            HTTP::redirect($back, 303);
        }
    }

    private function done(string $to, ?string $text): void
    {
        if ($text !== NULL) Session::instance()->set(self::FLASH, ['type' => 'ok', 'text' => $text]);
        HTTP::redirect($to, 303);
    }

    private function flash(): array
    {
        return (array) Session::instance()->get_once(self::FLASH, ['text' => '']);
    }

    /** Ключ идемпотентности формы загрузки: из скрытого поля, иначе новый. */
    private static function request_key(string $posted): string
    {
        return preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', $posted) ? 'ui:'.substr($posted, 0, 120) : 'ui:'.bin2hex(random_bytes(16));
    }
}
