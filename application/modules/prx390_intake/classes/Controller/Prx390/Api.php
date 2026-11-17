<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API prx390 для python-воркера `doc.normalize`.
 *
 * Аутентификация — X-Worker-Token (`PRX390_SITE_TOKEN` → `WORKER_SITE_TOKEN`
 * → `WORKER_API_TOKEN`). Пользовательская сессия здесь не нужна и не должна
 * использоваться: воркер работает от служебной учётной записи.
 */
class Controller_Prx390_Api extends Controller_Prx39x_Base
{
    protected $auth_required = FALSE;
    protected $public_actions = ['health', 'file', 'text'];
    protected $_halt = FALSE;

    public function before()
    {
        parent::before();

        $this->auto_render = FALSE;
        $token = $this->_token();
        if ($token === '')
        {
            if (Kohana::$environment === Kohana::DEVELOPMENT)
            {
                return;
            }

            $this->_halt = TRUE;

            return $this->_json(['ok' => FALSE, 'error' => 'PRX390_SITE_TOKEN не задан — API закрыт'], 403);
        }

        $given = (string) $this->request->headers('x-worker-token');
        if ( ! hash_equals($token, $given))
        {
            $this->_halt = TRUE;

            return $this->_json(['ok' => FALSE, 'error' => 'Недействительный токен воркера'], 403);
        }
    }

    public function action_health()
    {
        if ($this->_halt) return;

        return $this->_json([
            'ok' => TRUE,
            'module' => 'prx390',
            'inbox_dir' => Service_Prx390_Intake::path('inbox_dir'),
            'inbox_files' => count(Service_Prx390_Intake::inbox_files()),
            'stats' => Service_Prx390_Intake::stats(),
        ]);
    }

    public function action_file()
    {
        if ($this->_halt) return;

        $id = (int) $this->request->param('id');
        $file = Service_Prx390_Intake::file($id);
        if ( ! $file)
        {
            return $this->_json(['ok' => FALSE, 'error' => 'Файл не найден'], 404);
        }

        $path = Service_Prx212_Storage::instance()->getFilePath((int) $file['file_id']);
        if ( ! $path OR ! is_file($path))
        {
            return $this->_json(['ok' => FALSE, 'error' => 'Физический файл не найден'], 404);
        }

        $this->response
            ->status(200)
            ->headers('Content-Type', $file['mime'] ?: 'application/pdf')
            ->headers('Content-Disposition', 'inline; filename="'.basename($file['filename']).'"')
            ->headers('Content-Length', (string) filesize($path))
            ->body(file_get_contents($path));

        return $this->response;
    }

    public function action_text()
    {
        if ($this->_halt) return;

        $id = (int) $this->request->param('id');

        // POST — воркер присылает распознанный текст. Контракт:
        // file_id, text, pages, engine, confidence.
        if ($this->request->method() === Request::POST)
        {
            $text = trim((string) $this->request->post('text'));
            $file_id = (int) $this->request->post('file_id');
            if ($text === '')
            {
                return $this->_json(['ok' => FALSE, 'error' => 'Текст пуст'], 422);
            }
            if ($file_id < 1)
            {
                return $this->_json(['ok' => FALSE, 'error' => 'file_id обязателен'], 422);
            }

            try
            {
                $saved = Service_Prx390_Intake::store_text($id, [
                    'file_id' => $file_id,
                    'text' => $text,
                    'pages' => $this->request->post('pages'),
                    'engine' => substr((string) ($this->request->post('engine') ?: 'pypdf'), 0, 32),
                    'confidence' => $this->request->post('confidence'),
                ]);
            }
            catch (InvalidArgumentException $e)
            {
                return $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 422);
            }
            catch (Exception $e)
            {
                Log::instance()->add(Log::ERROR, 'prx390: не удалось сохранить текст: :e', [
                    ':e' => $e->getMessage(),
                ]);

                return $this->_json(['ok' => FALSE, 'error' => 'Не удалось сохранить текст'], 500);
            }

            return $this->_json(['ok' => TRUE] + $saved, 200);
        }

        $message = Service_Prx390_Intake::message($id);
        if ( ! $message)
        {
            return $this->_json(['ok' => FALSE, 'error' => 'Входящий не найден'], 404);
        }

        $text = $message['text'];

        return $this->_json([
            'ok' => TRUE,
            'message_id' => (int) $message['id'],
            'subject' => $message['subject'],
            'status' => $message['status'],
            'document_id' => $message['document_id'] ? (int) $message['document_id'] : NULL,
            'files' => array_map(function ($f) {
                return [
                    'file_id' => (int) $f['file_id'],
                    'filename' => $f['filename'],
                    'mime' => $f['mime'],
                    'size_bytes' => (int) $f['size_bytes'],
                ];
            }, $message['files']),
            'text' => $text ? $text['text'] : '',
            'chars' => $text ? (int) $text['chars'] : 0,
            'pages' => $text ? $text['pages'] : NULL,
            'engine' => $text ? $text['engine'] : NULL,
        ]);
    }

    protected function _token()
    {
        foreach (['PRX390_SITE_TOKEN', 'WORKER_SITE_TOKEN', 'WORKER_API_TOKEN'] as $key)
        {
            $value = trim((string) getenv($key));
            if ($value !== '')
            {
                return $value;
            }
        }

        return '';
    }
}
