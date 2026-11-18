<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API prx390 для python-воркера `doc.normalize`.
 *
 * Аутентификация — X-Worker-Token (`PRX390_SITE_TOKEN` → `WORKER_SITE_TOKEN`
 * → `WORKER_API_TOKEN`). Пользовательская сессия здесь не нужна и не должна
 * использоваться: воркер работает от служебной учётной записи.
 */
class Controller_Prx390_Api extends Controller_Prx39x_Api
{
    protected $public_actions = ['health', 'file', 'text'];

    protected function _token_prefix()
    {
        return 'PRX390';
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
        try
        {
            $file = Service_Prx390_Intake::file($id);
            if ( ! $file)
            {
                Log_Plain::event(Log::WARNING, 'prx390_diag stage=file_get result=not_found file_id=:id', [':id' => $id]);
                return $this->_json(['ok' => FALSE, 'error' => 'Файл не найден'], 404);
            }

            $path = Service_Prx212_Storage::instance()->getFilePath((int) $file['file_id']);
            if ( ! $path OR ! is_file($path))
            {
                Log_Plain::event(Log::WARNING, 'prx390_diag stage=file_get result=storage_missing file_id=:id', [':id' => $id]);
                return $this->_json(['ok' => FALSE, 'error' => 'Физический файл не найден'], 404);
            }

            $content = @file_get_contents($path);
            if ($content === FALSE)
            {
                Log_Plain::event(Log::ERROR, 'prx390_diag stage=file_get result=read_error file_id=:id', [':id' => $id]);
                return $this->_json(['ok' => FALSE, 'error' => 'Не удалось прочитать файл'], 500);
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx390_diag stage=file_get result=error file_id=:id error_type=:type', [
                ':id' => $id, ':type' => get_class($e),
            ]);
            return $this->_json(['ok' => FALSE, 'error' => 'Не удалось получить файл'], 500);
        }

        Log_Plain::event(Log::INFO, 'prx390_diag stage=file_get result=ok file_id=:id bytes=:size', [
            ':id' => $id, ':size' => strlen($content),
        ]);
        $this->response
            ->status(200)
            ->headers('Content-Type', $file['mime'] ?: 'application/pdf')
            ->headers('Content-Disposition', 'inline; filename="'.basename($file['filename']).'"')
            ->headers('Content-Length', (string) strlen($content))
            ->body($content);

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
            if ($text === '' OR $file_id < 1)
            {
                Log_Plain::event(Log::WARNING, 'prx390_diag stage=text_post result=invalid message_id=:id file_id=:file', [
                    ':id' => $id, ':file' => $file_id,
                ]);
                return $this->_json(['ok' => FALSE, 'error' => $text === '' ? 'Текст пуст' : 'file_id обязателен'], 422);
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
                Log_Plain::event(Log::WARNING, 'prx390_diag stage=text_post result=rejected message_id=:id file_id=:file error_type=:type', [
                    ':id' => $id, ':file' => $file_id, ':type' => get_class($e),
                ]);
                return $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 422);
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::ERROR, 'prx390_diag stage=text_post result=error message_id=:id file_id=:file error_type=:type', [
                    ':id' => $id, ':file' => $file_id, ':type' => get_class($e),
                ]);
                return $this->_json(['ok' => FALSE, 'error' => 'Не удалось сохранить текст'], 500);
            }

            Log_Plain::event(Log::INFO, 'prx390_diag stage=text_post result=ok message_id=:id file_id=:file', [
                ':id' => $id, ':file' => $file_id,
            ]);
            return $this->_json(['ok' => TRUE] + $saved, 200);
        }

        try
        {
            $message = Service_Prx390_Intake::message($id);
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx390_diag stage=envelope_get result=error message_id=:id error_type=:type', [
                ':id' => $id, ':type' => get_class($e),
            ]);
            return $this->_json(['ok' => FALSE, 'error' => 'Не удалось получить входящий'], 500);
        }
        if ( ! $message)
        {
            Log_Plain::event(Log::WARNING, 'prx390_diag stage=envelope_get result=not_found message_id=:id', [':id' => $id]);
            return $this->_json(['ok' => FALSE, 'error' => 'Входящий не найден'], 404);
        }

        Log_Plain::event(Log::INFO, 'prx390_diag stage=envelope_get result=ok message_id=:id files=:files', [
            ':id' => $id, ':files' => count($message['files']),
        ]);
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

}
