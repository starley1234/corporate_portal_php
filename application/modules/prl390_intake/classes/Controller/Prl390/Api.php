<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API prl390 для python-воркера `doc.normalize`.
 *
 * Аутентификация — X-Worker-Token (`PRL390_SITE_TOKEN` → `WORKER_SITE_TOKEN`
 * → `WORKER_API_TOKEN`). Пользовательская сессия здесь не нужна и не должна
 * использоваться: воркер работает от служебной учётной записи.
 */
class Controller_Prl390_Api extends Controller_Prl39x_Base
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

            return $this->_json(['ok' => FALSE, 'error' => 'PRL390_SITE_TOKEN не задан — API закрыт'], 403);
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
            'module' => 'prl390',
            'inbox_dir' => Service_Prl390_Intake::path('inbox_dir'),
            'inbox_files' => count(Service_Prl390_Intake::inbox_files()),
            'stats' => Service_Prl390_Intake::stats(),
        ]);
    }

    public function action_file()
    {
        if ($this->_halt) return;

        $id = (int) $this->request->param('id');
        $file = Service_Prl390_Intake::file($id);
        if ( ! $file)
        {
            return $this->_json(['ok' => FALSE, 'error' => 'Файл не найден'], 404);
        }

        $path = Service_Prl212_Storage::instance()->getFilePath((int) $file['file_id']);
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
        $message = Service_Prl390_Intake::message($id);
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
        foreach (['PRL390_SITE_TOKEN', 'WORKER_SITE_TOKEN', 'WORKER_API_TOKEN'] as $key)
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
