<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Временные (публичные) ссылки на файл: выпуск, список, отзыв.
 *
 * Ссылка открывает файл без входа, поэтому выпускать/видеть/отзывать её может
 * только тот, кто вправе раздавать файл: владелец, пользователь с правом
 * записи на файл или роль с prx212_storage.edit_all_files (админ — всегда).
 * До 28.11.2026 контроллер наследовал голый Controller: вход, CSRF и права не
 * проверялись — аноним мог выпустить ссылку на любой file_id и читать токены.
 * Теперь Controller_Base: вход обязателен, POST — через Prx_PostGuard (CSRF).
 * Выпуск и отзыв пишутся в журнал администратора (категория prx212), токен — нет.
 */
class Controller_Prx212_Link extends Controller_Base {

    /** Срок жизни ссылки, секунды: от минуты до 7 суток. */
    const TTL_MIN = 60;
    const TTL_MAX = 604800;
    const TTL_DEFAULT = 3600;

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->headers('Cache-Control', 'no-store');
    }

    /** POST file_id, ttl? → {success, data:{url, expires_in}}. */
    public function action_create()
    {
        if ($this->request->method() !== Request::POST)
        {
            $this->_fail(405, 'Используйте POST');
            return;
        }

        $file = $this->_shareable_file((int) $this->request->post('file_id'));
        if ($file === NULL)
        {
            return;
        }

        $ttl = self::ttl($this->request->post('ttl'));

        try
        {
            $url = Service_Prx212_Storage::instance()->generateUrl((int) $file->id, $ttl);
        }
        catch (Exception $e)
        {
            Kohana_Exception::log($e);
            $this->_fail(404, 'Файл не найден');
            return;
        }

        Audit::log('prx212', 'share_link_created', (int) $file->id, ['file' => (string) $file->filename, 'ttl' => $ttl]);

        $this->_ok(['url' => $url, 'expires_in' => $ttl]);
    }

    /** GET ?file_id= → активные ссылки файла. */
    public function action_list()
    {
        $file = $this->_shareable_file((int) $this->request->query('file_id'));
        if ($file === NULL)
        {
            return;
        }

        $links = ORM::factory('Prx212_Link')
            ->where('file_id', '=', (int) $file->id)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->order_by('id', 'DESC')
            ->find_all();

        $data = [];
        foreach ($links as $link)
        {
            $data[] = [
                'id' => (int) $link->id,
                'token' => $link->token,
                'expires_at' => $link->expires_at,
                'click_count' => (int) $link->click_count,
                'remaining_ttl' => $link->get_remaining_ttl(),
            ];
        }

        $this->_ok($data);
    }

    /** POST/DELETE /prx212_link/delete/<id> — отозвать ссылку. */
    public function action_delete()
    {
        if ( ! in_array($this->request->method(), [Request::POST, Request::DELETE], TRUE))
        {
            $this->_fail(405, 'Используйте POST или DELETE');
            return;
        }

        $link = ORM::factory('Prx212_Link', (int) $this->request->param('id'));
        if ( ! $link->loaded())
        {
            $this->_fail(404, 'Ссылка не найдена');
            return;
        }

        $file = $this->_shareable_file((int) $link->file_id);
        if ($file === NULL)
        {
            return;
        }

        $link_id = (int) $link->id;
        $link->delete();
        Audit::log('prx212', 'share_link_revoked', (int) $file->id, ['file' => (string) $file->filename, 'link_id' => $link_id]);

        $this->_ok(['id' => $link_id], 'Ссылка отозвана');
    }

    /** TTL из запроса: пусто/мусор — по умолчанию, иначе в [TTL_MIN, TTL_MAX]. */
    public static function ttl($raw): int
    {
        $ttl = is_scalar($raw) && ctype_digit(trim((string) $raw)) ? (int) $raw : self::TTL_DEFAULT;

        return max(self::TTL_MIN, min(self::TTL_MAX, $ttl));
    }

    /**
     * Файл, которым текущий пользователь вправе делиться, или NULL (ответ уже отдан).
     * Чужой файл без прав — 404, а не 403: не подтверждаем, что такой id есть.
     */
    private function _shareable_file(int $file_id)
    {
        if ($file_id <= 0)
        {
            $this->_fail(400, 'Параметр file_id обязателен');
            return NULL;
        }

        $file = ORM::factory('Prx212_File', $file_id);
        $user_id = (int) $this->_current_user->id;

        if ( ! $file->loaded() OR $file->deleted_at !== NULL
            OR ( ! $file->can_write($user_id) AND ! $this->can('prx212_storage.edit_all_files')))
        {
            $this->_fail(404, 'Файл не найден');
            return NULL;
        }

        return $file;
    }

    private function _ok($data, string $message = 'OK'): void
    {
        $this->response->body(json_encode(['success' => TRUE, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE));
    }

    private function _fail(int $status, string $error): void
    {
        $this->response->status($status);
        $this->response->body(json_encode(['success' => FALSE, 'error' => $error], JSON_UNESCAPED_UNICODE));
    }
}
