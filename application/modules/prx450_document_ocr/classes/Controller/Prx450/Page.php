<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prx450_document_ocr — админская проверка сервиса.
 *
 * Та же услуга, что и в API, но через сессию и CSRF. Страница полезна при
 * подключении шлюза: видно адрес, лимиты, ответ и код ошибки без curl.
 */
class Controller_Prx450_Page extends Controller_Base
{
    public function before()
    {
        $this->response->headers('Cache-Control', 'no-store');
        $this->response->headers('X-Request-Id', bin2hex(random_bytes(8)));
        if (!in_array($this->request->method(), [Request::GET, Request::POST], TRUE)) {
            $this->response->headers('Allow', 'GET, POST');
            throw HTTP_Exception::factory(405, 'Нужен GET или POST');
        }
        try { Service_Prx450_Ocr::assert_request_size((int) ($_SERVER['CONTENT_LENGTH'] ?? 0), (string) ini_get('post_max_size')); }
        catch (Prx450_Error $e) { throw HTTP_Exception::factory(413, 'Документ превышает лимит загрузки PHP. Выберите файл меньшего размера.'); }
        parent::before();

        if ( ! $this->can('prx450.view'))
        {
            throw HTTP_Exception::factory(403, 'Распознавание документов доступно администратору');
        }

        $this->set_title('Распознавание документов');
        $this->set_module_nav([
            ['title' => 'Проверка', 'href' => '/prx450_document_ocr', 'icon' => 'fas fa-file-lines'],
        ]);
    }

    public function action_index()
    {
        $result = NULL;
        $error = NULL;

        if ($this->request->method() === Request::POST)
        {
            try
            {
                $result = Service_Prx450_Ocr::parse(
                    $_FILES['file'] ?? NULL,
                    $this->request->post(),
                    (int) $this->_current_user->id
                );
            }
            catch (Prx450_Error $e)
            {
                $error = ['code' => $e->getMessage(), 'status' => $e->getCode(), 'details' => $e->details()];
                $this->response->status($e->getCode() >= 400 ? $e->getCode() : 503);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::ERROR, 'prx450 ui error_type=:type', [':type' => get_class($e)]);
                $error = ['code' => 'service_unavailable', 'status' => 503, 'details' => []];
                $this->response->status(503);
            }
        }

        if (in_array($this->response->status(), [503, 504], TRUE)) $this->response->headers('Retry-After', '5');
        $old = array_filter((array) $this->request->post(), 'is_scalar');
        $this->template->content = View::factory('prx450/index', [
            'old' => $old,
            'config' => Service_Prx450_Ocr::summary(),
            'result' => $result,
            'error'  => $error,
            'csrf'   => Security::token(),
        ]);
    }
}
