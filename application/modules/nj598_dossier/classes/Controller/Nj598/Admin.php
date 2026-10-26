<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj598: админка /nj598/admin — все досье платформы: KPI, повтор разведки,
 * удаление (two-click). Право nj598.admin (config/acl.php).
 */
class Controller_Nj598_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj598.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }


        Service_Nj598_Dossier::ensure_tables();
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
            elseif ($code === '')
            {
                $flash = 'Не указано досье';
            }
            else
            {
                try
                {
                    if ($act === 'retry')
                    {
                        $res = Service_Nj598_Dossier::retry($code);
                        $flash = isset($res['error']) ? (string) $res['error'] : 'Разведка «'.$code.'» запущена повторно';
                    }
                    elseif ($act === 'delete')
                    {
                        $n = Database::instance()->query(Database::DELETE,
                            "DELETE FROM nj_598_dossiers WHERE code = ".Database::instance()->quote($code), FALSE);
                        $flash = 'Досье «'.$code.'» удалено'.((int) $n === 0 ? ' (не найдено)' : '');
                    }
                    else
                    {
                        $flash = 'Неизвестное действие';
                    }
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::WARNING, 'nj598 admin: :a :c: :e',
                        [':a' => $act, ':c' => $code, ':e' => $e->getMessage()]);
                    $flash = 'Не получилось: '.$e->getMessage();
                }
            }

            HTTP::redirect('/nj598/admin?flash='.urlencode($flash), 303);
        }

        $this->set_title('AI Досье · управление');

        $this->template->content = View::factory('598/admin', [
            'stats'    => Service_Nj598_Dossier::admin_stats(),
            'items'    => Service_Nj598_Dossier::admin_list(50),
            'flash'    => isset($_GET['flash']) ? (string) $_GET['flash'] : $flash,
            'csrf'     => Security::token(),
        ]);
    }
}
