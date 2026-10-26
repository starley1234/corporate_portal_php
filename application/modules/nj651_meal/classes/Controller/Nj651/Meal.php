<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страницы «Калорий по фото тарелки» (модуль NJ-651).
 *
 *   /meal       — снимок тарелки, профиль и последние разборы;
 *   /meal/<id>  — разбор одной тарелки: блюда, КБЖУ, доля дневной нормы.
 *
 * Профиль (пол, вес, рост, возраст, активность, цель) живёт в адресе
 * страницы: ничего не сохраняем, а ссылку с нормой можно открыть на другом
 * устройстве. Вся арифметика — в `Service_Nj651_Meal`.
 */
class Controller_Nj651_Meal extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Калории по фото тарелки: КБЖУ и доля дневной нормы');
        $this->set_description(
            'Сфотографируйте тарелку — сервис назовёт блюда, оценит вес порций и посчитает калории, белки, жиры и углеводы. Покажет долю от вашей суточной нормы по формуле Миффлина — Сан-Жеора. Создание разбора — по бесплатному аккаунту.');
        $this->set_keywords(
            'калории по фото подсчёт калорий кбжу бжу норма калорий в день тарелка еда питание');
    }

    public function action_index()
    {
        Service_Nj651_Meal::preview_gc(90);
        $profile = Service_Nj651_Meal::profile((array) $this->request->query());

        $this->template->content = View::factory('651/index', [
            'ready'         => Vision::ready(),
            'limits'        => (array) Kohana::$config->load('nj651_meal.limits'),
            'profile'       => $profile,
            'profile_query' => http_build_query($profile),
            'norm'          => Service_Nj651_Meal::norm($profile),
            'mine'          => $this->_mine(),
            'csrf'          => Security::token(),
            'flash'         => (string) Session::instance()->get_once('nj651_flash', ''),
            // Гость видит плашку входа вместо съёмки (решение владельца
            // 06.10.2026, «как опросы»). Профиль и норма остаются публичными.
            'guest'         => ! Auth::instance()->logged_in(),
        ]);
    }

    public function action_run()
    {
        $id      = (int) $this->request->param('id');
        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $user_id = class_exists('Visitor') ? Visitor::user_id() : NULL;
        $initial = $id > 0 ? Vision::run_get('nj_651_runs', $id) : NULL;

        // Проверяем владельца ДО run_watch: чужой id не должен даже дёргать
        // обслуживание задачи очереди.
        if ( ! $initial OR ! Vision::run_owned($initial, $visitor, $user_id))
        {
            throw HTTP_Exception::factory(404, 'Разбор тарелки не найден');
        }

        $watch = Vision::run_watch('nj_651_runs', $id, 'nj651.meal');
        $run   = $watch['run'];

        if ( ! $run)
        {
            throw HTTP_Exception::factory(404, 'Разбор тарелки не найден');
        }

        $data    = Service_Nj651_Meal::shape((array) $run['result']);
        $profile = Service_Nj651_Meal::profile((array) $this->request->query());
        $norm    = Service_Nj651_Meal::norm($profile);

        $this->set_title(($data['dishes'] ? $data['dishes'][0]['name'].': ' : '').'калории и БЖУ по фото');

        $this->template->content = View::factory('651/run', [
            'run'         => $run,
            'data'        => $data,
            'live'        => Vision::run_live($run),
            'hint'        => (string) $watch['hint'],
            'profile'     => $profile,
            'norm'        => $norm,
            'preview_url' => Service_Nj651_Meal::preview_exists($id) ? '/meal/api/preview?id='.$id : '',
            'share'       => Service_Nj651_Meal::share($data['total']['kcal'], $norm['kcal']),
            'verdict'     => Service_Nj651_Meal::verdict($data['total'], $norm),
        ]);
    }

    /** POST /meal/<id>/delete — удалить только свой готовый разбор. */
    public function action_delete()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела — обновите страницу');
        }

        if ((string) $this->request->post('confirm') !== '1')
        {
            Session::instance()->set('nj651_flash', 'Нужно подтвердить удаление второй кнопкой.');
            HTTP::redirect('/meal', 303);
        }

        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $user_id = class_exists('Visitor') ? Visitor::user_id() : NULL;
        $id      = (int) $this->request->param('id');
        $run     = $id > 0 ? Vision::run_get('nj_651_runs', $id) : NULL;

        if ( ! $run OR ! Vision::run_owned($run, $visitor, $user_id))
        {
            throw HTTP_Exception::factory(404, 'Разбор не найден');
        }

        if (in_array((string) $run['status'], ['queued', 'running'], TRUE))
        {
            Session::instance()->set('nj651_flash', 'Тарелка ещё анализируется — удалите её после завершения.');
        }
        elseif (Service_Nj651_Meal::run_delete($id, $visitor, $user_id))
        {
            Session::instance()->set('nj651_flash', 'Разбор и его миниатюра удалены.');
        }
        else
        {
            Session::instance()->set('nj651_flash', 'Не получилось удалить разбор — обновите список и попробуйте ещё раз.');
        }

        HTTP::redirect('/meal', 303);
    }

    /**
     * Последние разборы посетителя: по куке, а для вошедших — ещё и по
     * аккаунту (история следует за человеком, а не за браузером).
     */
    protected function _mine()
    {
        if ( ! Vision::table_exists('nj_651_runs'))
        {
            return [];
        }

        $mine = Vision::runs_mine('nj_651_runs',
            class_exists('Visitor') ? (string) Visitor::uid() : '', 6,
            class_exists('Visitor') ? Visitor::user_id() : NULL);

        foreach ($mine as &$run)
        {
            $id = (int) ($run['id'] ?? 0);
            $run['meal'] = Service_Nj651_Meal::shape((array) ($run['result'] ?? []));
            $run['preview_url'] = ($id > 0 AND Service_Nj651_Meal::preview_exists($id))
                ? '/meal/api/preview?id='.$id
                : '';
        }
        unset($run);

        return $mine;
    }
}
