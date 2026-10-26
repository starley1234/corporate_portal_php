<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prl100: «Чистка данных» /prl100/cleanup — реестр чисток (config/prl100_cleanup.php):
 * оценка объёма → удаление ПОРЦИЯМИ по клику (two-click). Пока только под
 * присмотром человека; централизованный запуск — отдельное решение владельца.
 * Доступ — право prl100.cleanup.admin (config/acl.php).
 */
class Controller_Prl100_Cleanup extends Controller_Prl100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('prl100.cleanup.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

    }

    public function action_index()
    {
        // Чистки объявляют владельцы таблиц в своих манифестах (пятый контракт)
        $items  = Manifest::cleanups();
        $db     = Database::instance();
        $flash  = '';
        $state  = [];

        if ($this->request->method() === Request::POST)
        {
            $act  = (string) $this->request->post('act');
            $item = (string) $this->request->post('item');

            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            elseif ($act !== 'run' OR ! isset($items[$item]))
            {
                $flash = 'Неизвестное действие';
            }
            else
            {
                try
                {
                    $sql = str_replace(
                        [':old', ':limit'],
                        [$db->quote("interval '".$this->_days($items[$item])." days'"), 5000],
                        (string) $items[$item]['delete_sql']);

                    $n = $db->query(Database::DELETE, $sql, FALSE);
                    $flash = $items[$item]['label'].' — удалено строк: '.(int) $n
                        .'. Если строк было больше — нажмите ещё раз (порция 5000).';
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::WARNING, 'prl100 cleanup :i: :e', [':i' => $item, ':e' => $e->getMessage()]);
                    $flash = 'Не получилось: '.$e->getMessage();
                }
            }

            HTTP::redirect('/prl100/cleanup?flash='.urlencode($flash), 303);
        }

        foreach ($items as $key => $conf)
        {
            try
            {
                $sql = str_replace(':old', $db->quote("interval '".$this->_days($conf)." days'"),
                    (string) $conf['count_sql']);

                $r = $db->query(Database::SELECT, $sql, FALSE)->current();
                $state[$key] = $r ? (int) array_values((array) $r)[0] : 0;
            }
            catch (Throwable $e)
            {
                $state[$key] = NULL; // таблицы ещё нет (миграция не применена)
            }
        }

        $this->set_title('Чистка данных · управление');

        $this->template->content = View::factory('prl100/cleanup', [
            'items' => $items,
            'state' => $state,
            'flash' => isset($_GET['flash']) ? (string) $_GET['flash'] : $flash,
            'csrf'  => Security::token(),
        ]);
    }

    protected function _days(array $conf)
    {
        return max(1, (int) ($conf['days'] ?? 30));
    }
}
