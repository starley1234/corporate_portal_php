<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100: «Журнал администратора» /prx100/audit — кто, когда, что изменил.
 *
 * Только чтение, право admin. Пишут модули через фасад Audit::log(); записи
 * не правятся (триггер), чистятся по сроку 365 дней (/prx100/cleanup).
 */
class Controller_Prx103_Audit extends Controller_Prx102_Base {

    const PAGE = 100;

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Журнал администратора');
    }

    public function action_index()
    {
        $days = (int) $this->request->query('days');
        $filter = [
            'days'      => in_array($days, [1, 7, 30, 90, 365], TRUE) ? $days : 30,
            'category'  => trim((string) $this->request->query('category')),
            'actor'     => trim((string) $this->request->query('actor')),
            'object_id' => trim((string) $this->request->query('object')),
            'q'         => trim((string) $this->request->query('q')),
            'before_id' => (int) $this->request->query('before'),
        ];

        try
        {
            $rows   = Service_Prx103_Audit::search($filter, self::PAGE + 1);
            $facets = Service_Prx103_Audit::facets(365);
            $error  = '';
        }
        catch (Throwable $e)
        {
            $rows   = [];
            $facets = ['categories' => [], 'actors' => []];
            $error  = 'Журнал недоступен: '.$e->getMessage();
        }

        $more = count($rows) > self::PAGE;

        $this->template->content = View::factory('prx103/audit', [
            'rows'   => array_slice($rows, 0, self::PAGE),
            'more'   => $more,
            'filter' => $filter,
            'facets' => $facets,
            'error'  => $error,
        ]);
    }
}
