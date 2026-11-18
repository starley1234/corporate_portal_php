<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Общая база JSON-API документооборота prx390–prx394.
 *
 * Здесь один раз описан контракт воркера:
 *   * X-Worker-Token (`<PRX###>_SITE_TOKEN` → `WORKER_SITE_TOKEN` →
 *     `WORKER_API_TOKEN`), а в development пустой токен допустим;
 *   * `before()` отключает рендер и выставляет `_halt` при отказе;
 *   * `_json_body()` читает JSON-тело, а при пустом/битом — обычный POST.
 *
 * Наследник задаёт только префикс модуля в `_token_prefix()`; всё остальное —
 * бизнес-логика в action-методах. Ошибка авторизации не раскрывает секретов.
 */
abstract class Controller_Prx39x_Api extends Controller_Prx39x_Base
{
    protected $auth_required = FALSE;
    protected $public_actions = ['health'];
    protected $_halt = FALSE;

    /** Префикс модуля для site-токена: PRX390, PRX392, PRX393, PRX394. */
    abstract protected function _token_prefix();

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;

        $token = $this->_worker_token();
        if ($token === '')
        {
            if (Kohana::$environment === Kohana::DEVELOPMENT)
            {
                return;
            }

            $this->_halt = TRUE;

            return $this->_json([
                'ok' => FALSE,
                'error' => $this->_token_prefix().'_SITE_TOKEN не задан — API закрыт',
            ], 403);
        }

        $given = (string) $this->request->headers('x-worker-token');
        if ( ! hash_equals($token, $given))
        {
            $this->_halt = TRUE;

            return $this->_json(['ok' => FALSE, 'error' => 'Недействительный токен воркера'], 403);
        }
    }

    /**
     * Токен воркера по приоритету: модульный → общий site → общий API.
     * Пустая строка означает «не настроено», а не «доступ разрешён».
     */
    protected function _worker_token()
    {
        foreach ([$this->_token_prefix().'_SITE_TOKEN', 'WORKER_SITE_TOKEN', 'WORKER_API_TOKEN'] as $key)
        {
            $value = trim((string) getenv($key));
            if ($value !== '')
            {
                return $value;
            }
        }

        return '';
    }

    /**
     * JSON-тело POST как массив. Если тело не JSON или пустое — обычный POST.
     * Так один контракт работает и для httpx, и для HTML-формы отладки.
     */
    protected function _json_body()
    {
        $raw = $this->request->body();
        $body = $raw ? json_decode($raw, TRUE) : NULL;

        return is_array($body) ? $body : (array) $this->request->post();
    }
}
