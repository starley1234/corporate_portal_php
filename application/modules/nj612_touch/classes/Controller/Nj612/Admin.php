<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj612 admin: /nj612/admin — все серии платформы (кто владелец, статус,
 * шаги), удаление зависших. Право nj612.admin (только admin).
 */
class Controller_Nj612_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj612.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Серии касаний · управление');
    }

    public function action_index()
    {
        $flash = '';

        if ($this->request->method() === Request::POST)
        {
            $act  = (string) $this->request->post('act');
            $code = (string) $this->request->post('code');

            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            elseif ($act === 'delete' AND $code !== '')
            {
                // управление удаляет без привязки к владельцу
                $db = Database::instance();
                $row = $db->query(Database::SELECT,
                    'SELECT id FROM nj_612_sequences WHERE code = '.$db->quote($code).' LIMIT 1', FALSE)->current();

                if ($row)
                {
                    $db->query(Database::DELETE, 'DELETE FROM nj_612_steps WHERE sequence_id = '.(int) $row['id'], FALSE);
                    $db->query(Database::DELETE, 'DELETE FROM nj_612_sequences WHERE id = '.(int) $row['id'], FALSE);
                    $flash = 'Серия '.$code.' удалена';
                }
                else
                {
                    $flash = 'Серия не найдена';
                }
            }
        }

        $this->template->content = View::factory('612/admin', [
            'rows'  => Service_Nj612_Touch::admin_overview(),
            'flash' => $flash,
            'csrf'  => Security::token(),
            'autosend' => Service_Nj612_Touch::autosend(),
        ]);
    }

}
