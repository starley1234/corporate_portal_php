<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Личный журнал показаний: обычные POST-формы, CSRF и редиректы.
 * Расчёты/БД живут только в Service_Nj656_Meters.
 */
class Controller_Nj656_Meters extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        // Личный журнал — только для зарегистрированных (решение владельца
        // 06.10.2026): показания — персональные данные, а владение по куке
        // owner_key терялось вместе с браузером. Кука остаётся: устройства,
        // заведённые анонимно, доступны и после входа из того же браузера.
        if ( ! Auth::instance()->logged_in())
        {
            HTTP::redirect('/auth/login', 302);
        }

        $this->set_title('Показания счётчиков: расход, стоимость и прогноз');
        $this->set_description('Ведите историю показаний воды, электричества и тепла: сервис считает расход между реальными датами, стоимость по тарифу, прогноз месяца и замечает скачки.');
        $this->set_keywords('показания счетчиков вода электричество расход тариф стоимость прогноз коммунальные услуги журнал показаний');
    }

    public function action_index()
    {
        // Ставим browser cookie до начала рендера: данные не привязаны к IP.
        $owner = Service_Nj656_Meters::owner_key();
        $error = NULL;
        $form = ['title' => '', 'unit' => 'm3', 'tariff' => '', 'budget_rub' => ''];

        if ($this->request->method() === Request::POST)
        {
            if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
            {
                throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
            }

            foreach ($form as $key => $default)
            {
                $form[$key] = trim((string) Arr::get($_POST, $key, $default));
            }

            $created = Service_Nj656_Meters::create($owner, $form);
            if ($created['ok']) HTTP::redirect('/meters/'.$created['code'].'?created=1', 303);
            $error = Service_Nj656_Meters::error_label($created['error']);
        }

        $this->template->content = View::factory('656/index', [
            'owner_ok' => $owner !== '',
            'devices' => Service_Nj656_Meters::mine($owner),
            'units' => Service_Nj656_Meters::units(),
            'form' => $form,
            'error' => $error,
            'deleted' => ! empty($this->request->query('deleted')),
            'csrf' => Security::token(),
        ]);
    }

    public function action_view()
    {
        $owner = Service_Nj656_Meters::owner_key();
        $meter = $this->_meter($owner);
        $readings = Service_Nj656_Meters::readings((int) $meter['id']);
        $dashboard = Service_Nj656_Meters::dashboard($meter, $readings);

        $this->set_title($meter['title'].': показания, расход и прогноз');
        $this->template->content = View::factory('656/meter', [
            'meter' => $meter,
            'readings' => $readings,
            'dashboard' => $dashboard,
            'insight' => Service_Nj656_Meters::insight($dashboard),
            'notice' => $this->_notice(),
            'error' => $this->_error(),
            'created' => ! empty($this->request->query('created')),
            'csrf' => Security::token(),
        ]);
    }

    /** POST /meters/<code>/reading */
    public function action_reading()
    {
        $owner = Service_Nj656_Meters::owner_key();
        $meter = $this->_meter($owner);
        $this->_csrf_post();
        $saved = Service_Nj656_Meters::add_reading($meter, $_POST);
        $suffix = $saved['ok'] ? '?notice=reading' : '?error='.rawurlencode($saved['error']);
        HTTP::redirect('/meters/'.$meter['code'].$suffix, 303);
    }

    /** POST /meters/<code>/settings */
    public function action_settings()
    {
        $owner = Service_Nj656_Meters::owner_key();
        $meter = $this->_meter($owner);
        $this->_csrf_post();
        $saved = Service_Nj656_Meters::update_settings($meter, $_POST);
        $suffix = $saved['ok'] ? '?notice=settings' : '?error='.rawurlencode($saved['error']);
        HTTP::redirect('/meters/'.$meter['code'].$suffix, 303);
    }

    /** POST /meters/<code>/delete — история удаляется каскадом. */
    public function action_delete()
    {
        $owner = Service_Nj656_Meters::owner_key();
        $meter = $this->_meter($owner);
        $this->_csrf_post();
        Service_Nj656_Meters::delete($meter, $owner);
        HTTP::redirect('/meters?deleted=1', 303);
    }

    /** GET /meters/<code>/export — пользователь забирает только свою историю. */
    public function action_export()
    {
        $owner = Service_Nj656_Meters::owner_key();
        $meter = $this->_meter($owner);
        $readings = Service_Nj656_Meters::readings((int) $meter['id']);

        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'text/csv; charset=UTF-8');
        $this->response->headers('Content-Disposition', 'attachment; filename="meter-'.$meter['code'].'.csv"');
        $this->response->body(Service_Nj656_Meters::csv($meter, $readings));
    }

    protected function _meter($owner)
    {
        $meter = Service_Nj656_Meters::mine_one((string) $this->request->param('code'), $owner);
        if ($meter === NULL) throw HTTP_Exception::factory(404, 'Счётчик не найден');

        return $meter;
    }

    protected function _csrf_post()
    {
        if ($this->request->method() !== Request::POST OR ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }
    }

    protected function _notice()
    {
        $map = ['reading' => 'Показание сохранено. Расход и прогноз пересчитаны.', 'settings' => 'Настройки счётчика сохранены.'];
        return $map[(string) $this->request->query('notice')] ?? '';
    }

    protected function _error()
    {
        $code = (string) $this->request->query('error');
        return $code === '' ? '' : Service_Nj656_Meters::error_label($code);
    }
}
