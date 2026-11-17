<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Демо-страница VLM-разбора и её AJAX-эндпоинт.
 *
 * PDF не загружается на сервер: браузер рендерит страницы через PDF.js и
 * отправляет только JPEG/PNG. Здесь нет ни очереди, ни worker-токена.
 */
class Controller_Prl398_Vlm extends Controller_Base
{
    public function action_index()
    {
        $this->_require_ability('prl398.view');

        $this->set_title('VLM-разбор входящего письма');
        $this->set_description('Демонстрация vision-модели на PDF: страницы, печати, таблицы и реквизиты без OCR');
        $this->set_keywords('VLM, vision, PDF, входящая корреспонденция, демо');

        $this->template->content = View::factory('398/index', [
            'csrf' => Security::token(),
            'can_run' => $this->can('prl398.run'),
            'model' => (string) Service_Prl398_Vlm::config('model'),
            'max_pages' => (int) Service_Prl398_Vlm::config('max_pages', 3),
            'field_labels' => (array) Service_Prl398_Vlm::config('field_labels', []),
            'types' => (array) Service_Prl398_Vlm::config('types', []),
        ]);
    }

    public function action_analyze()
    {
        $this->_require_ability('prl398.run');

        if ($this->request->method() !== Request::POST)
        {
            return $this->_json_error('Нужен POST', 405);
        }

        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            return $this->_json_error('Сессия устарела, обновите страницу', 403);
        }

        if ( ! $this->_rate_limit())
        {
            return $this->_json_error('Слишком много запросов. Подождите несколько минут.', 429);
        }

        $timeout = (int) Service_Prl398_Vlm::config('timeout_sec', 180);
        if ($timeout > 0)
        {
            @set_time_limit($timeout);
        }

        try
        {
            $files = $this->_uploaded_pages();
            $result = Service_Prl398_Vlm::analyze($files);

            return $this->_json_response([
                'ok' => TRUE,
                'result' => $result['result'],
                'model' => $result['model'],
                'requested_model' => $result['requested_model'],
                'prompt_version' => $result['prompt_version'],
                'duration_ms' => (int) $result['duration_ms'],
                'usage' => $result['usage'],
                'pages' => (int) $result['pages'],
                'images' => $result['images'],
                'raw' => $result['raw'],
            ], 200);
        }
        catch (InvalidArgumentException $e)
        {
            return $this->_json_error($e->getMessage(), 422);
        }
        catch (RuntimeException $e)
        {
            Log_Plain::event(Log::ERROR, 'prl398: VLM-разбор не удался: :e', [':e' => $e->getMessage()]);

            return $this->_json_error($e->getMessage(), 502);
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prl398: неожиданная ошибка: :e', [':e' => $e->getMessage()]);

            return $this->_json_error('Внутренняя ошибка демо. Подробности — в журнале сайта.', 500);
        }
    }

    protected function _require_ability($ability)
    {
        if ( ! $this->can($ability))
        {
            throw HTTP_Exception::factory(403, 'Недостаточно прав: '.$ability);
        }
    }

    protected function _json_error($message, $code)
    {
        return $this->_json_response([
            'ok' => FALSE,
            'error' => (string) $message,
        ], (int) $code);
    }

    /**
     * Нормализует $_FILES['pages'] (pages[] из FormData) в список путей.
     */
    protected function _uploaded_pages()
    {
        if (empty($_FILES['pages']))
        {
            throw new InvalidArgumentException('Выберите PDF или изображение');
        }

        $raw = $_FILES['pages'];
        $names = (isset($raw['name']) AND is_array($raw['name'])) ? $raw['name'] : [$raw['name'] ?? ''];
        $tmp_names = (isset($raw['tmp_name']) AND is_array($raw['tmp_name'])) ? $raw['tmp_name'] : [$raw['tmp_name'] ?? ''];
        $errors = (isset($raw['error']) AND is_array($raw['error'])) ? $raw['error'] : [$raw['error'] ?? UPLOAD_ERR_NO_FILE];
        $sizes = (isset($raw['size']) AND is_array($raw['size'])) ? $raw['size'] : [$raw['size'] ?? 0];

        $max_pages = (int) Service_Prl398_Vlm::config('max_pages', 3);
        $files = [];

        foreach ($names as $index => $name)
        {
            $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE)
            {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK)
            {
                throw new InvalidArgumentException($this->_upload_error($error));
            }

            $tmp = (string) ($tmp_names[$index] ?? '');
            if ($tmp === '' OR ! is_uploaded_file($tmp))
            {
                throw new InvalidArgumentException('Загрузка страницы не подтверждена сервером');
            }

            if (count($files) >= $max_pages)
            {
                throw new InvalidArgumentException('Можно отправить не больше '.$max_pages.' страниц');
            }

            $safe_name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename((string) $name));

            $files[] = [
                'path' => $tmp,
                'name' => $safe_name !== '' ? $safe_name : 'page-'.(count($files) + 1).'.jpg',
                'size' => (int) ($sizes[$index] ?? 0),
            ];
        }

        if ( ! $files)
        {
            throw new InvalidArgumentException('Выберите PDF или изображение');
        }

        return $files;
    }

    protected function _upload_error($code)
    {
        switch ((int) $code)
        {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Файл слишком большой для настроек PHP (upload_max_filesize/post_max_size)';
            case UPLOAD_ERR_PARTIAL:
                return 'Файл загрузился не полностью, попробуйте ещё раз';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'На сервере нет временного каталога для загрузки';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Не удалось записать временный файл';
            case UPLOAD_ERR_EXTENSION:
                return 'Загрузку остановило расширение PHP';
            default:
                return 'Ошибка загрузки файла (код '.$code.')';
        }
    }

    /**
     * Простое ограничение на пользователя/сессию. Для демо достаточно сессии;
     * это не production-лимитер.
     */
    protected function _rate_limit()
    {
        $max = (int) Service_Prl398_Vlm::config('max_runs_per_hour', 30);
        if ($max < 1)
        {
            return TRUE;
        }

        $now = time();
        $calls = Session::instance()->get('prl398_calls', []);
        $calls = is_array($calls) ? $calls : [];

        $fresh = [];
        foreach ($calls as $timestamp)
        {
            $timestamp = (int) $timestamp;
            if ($timestamp > $now - 3600)
            {
                $fresh[] = $timestamp;
            }
        }

        if (count($fresh) >= $max)
        {
            return FALSE;
        }

        $fresh[] = $now;
        Session::instance()->set('prl398_calls', $fresh);

        return TRUE;
    }
}
