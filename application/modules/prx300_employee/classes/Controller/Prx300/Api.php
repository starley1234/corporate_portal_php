<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API личного профиля и справочника prx300.
 * Guest получает 401 JSON; права и организацию повторно проверяет сервис.
 */
class Controller_Prx300_Api extends Controller_Base
{
    public $auto_render = FALSE;
    protected $auth_required = FALSE;
    protected $_halt = FALSE;

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;
        if ( ! $this->_current_user)
        {
            $this->_json_response(['ok' => FALSE, 'error' => 'Требуется авторизация'], 401);
            $this->_halt = TRUE;
        }
    }

    public function action_me()
    {
        if ($this->_halt)
        {
            return;
        }
        $profile = Service_Prx300_Directory::profile_for_user((int) $this->_current_user->id);
        if ($profile === NULL)
        {
            $this->_json_response(['ok' => FALSE, 'error' => 'Аккаунт не сопоставлен с сотрудником'], 404);
            return;
        }
        $this->_json_response(['ok' => TRUE, 'employee' => $profile]);
    }

    public function action_directory()
    {
        if ($this->_halt)
        {
            return;
        }
        try
        {
            $result = Service_Prx300_Directory::directory($this->actor(), [
                'q' => trim((string) $this->request->query('q')),
                'organization_id' => (int) $this->request->query('organization_id'),
                'department_id' => (int) $this->request->query('department_id'),
                'site_id' => (int) $this->request->query('site_id'),
                'page' => max(1, (int) $this->request->query('page')),
                'per_page' => max(1, min(100, (int) ($this->request->query('per_page') ?: 50))),
            ]);
            $this->_json_response(['ok' => TRUE] + $result);
        }
        catch (DomainException $e)
        {
            $this->_json_response(['ok' => FALSE, 'error' => $e->getMessage()], 403);
        }
    }

    public function action_colleague()
    {
        if ($this->_halt)
        {
            return;
        }
        try
        {
            $employee = Service_Prx300_Directory::colleague_card($this->actor(), (int) $this->request->param('id'));
            if ($employee === NULL)
            {
                $this->_json_response(['ok' => FALSE, 'error' => 'Сотрудник не найден'], 404);
                return;
            }
            $this->_json_response(['ok' => TRUE, 'employee' => $employee]);
        }
        catch (DomainException $e)
        {
            $this->_json_response(['ok' => FALSE, 'error' => $e->getMessage()], 403);
        }
    }

}
