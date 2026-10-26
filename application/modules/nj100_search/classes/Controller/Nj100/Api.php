<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Глобальный поиск (nj100_search): GET /search/api?q=… — JSON-выдача для
 * выпадашки в шапке. Ошибки — ЦЕЛИКОМ в json {error} с причиной.
 */
class Controller_Nj100_Api extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;
    }

    /** GET /search/api?q=текст&types=org,profile&per_source=10. */
    public function action_search()
    {
        $t0 = microtime(TRUE);

        try
        {
            $q   = (string) $this->request->query('q');
            $per = (int) $this->request->query('per_source');

            // допускаем только безопасные типы (RFC: q в заголовок не попадает)
            $types = NULL;

            if ($this->request->query('types') !== NULL)
            {
                $types = array_filter(array_map('trim', explode(',',
                    (string) $this->request->query('types'))), 'strlen');
            }

            // nj600: фраза копится в аккумуляторе предпочтений (сбой не мешает поиску)
            if (class_exists('Service_Nj600_Prefs'))
            {
                try { Service_Nj600_Prefs::track_search($q); } catch (Throwable $e) {}
            }

            $results = Service_Nj100_Search::search(
                $q,
                $this->_current_user ? (int) $this->_current_user->id : NULL,
                $per > 0 ? $per : NULL,
                $types
            );

            $this->_json([
                'q'        => (string) $this->request->query('q'),
                'results'  => $results,
                'total'    => count($results),
                'sources'  => Service_Nj100_Search::sources(),
                'took_ms'  => (int) round((microtime(TRUE) - $t0) * 1000),
            ]);
        }
        catch (Throwable $e)
        {
            $ref = new ReflectionClass($this);
            $this->_json([
                'error' => 'Поиск временно недоступен: '.$e->getMessage()
                    .' @ '.basename($e->getFile()).':'.$e->getLine(),
            ], 400);
        }
    }

    protected function _json(array $data, $status = 200)
    {
        $this->response->status($status);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
