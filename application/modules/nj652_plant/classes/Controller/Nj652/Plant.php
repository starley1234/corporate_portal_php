<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страницы «Растения по фото» (модуль NJ-652).
 *
 *   /plant       — снимок растения и последние разборы;
 *   /plant/<id>  — карточка: вид, что не так, план ухода и календарь полива.
 *
 * Календарь считает `Service_Nj652_Plant::schedule()` из чисел ухода и даты:
 * зимой интервал полива растягивается сам, а подкормки в зимние месяцы не
 * назначаются вовсе.
 */
class Controller_Nj652_Plant extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Растение по фото: что это и что с ним не так');
        $this->set_description(
            'Сфотографируйте растение — сервис определит вид, назовёт возможную причину пятен или увядания и составит план ухода: свет, полив, подкормки и пересадка. Создание разбора — по бесплатному аккаунту.');
        $this->set_keywords(
            'определить растение по фото болезни листьев уход за комнатными растениями полив подкормка вредители');
    }

    public function action_index()
    {
        $this->template->content = View::factory('652/index', [
            'ready'  => Vision::ready(),
            'limits' => (array) Kohana::$config->load('nj652_plant.limits'),
            'mine'   => $this->_mine(),
            // Гость видит плашку входа вместо съёмки (решение владельца
            // 06.10.2026, «как опросы»). Разборы занимают очередь и время
            // vision-модели — анониму они не положены. Гейт в API — 401.
            'guest'  => ! Auth::instance()->logged_in(),
        ]);
    }

    public function action_run()
    {
        $id      = (int) $this->request->param('id');
        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $user_id = class_exists('Visitor') ? Visitor::user_id() : NULL;
        $initial = $id > 0 ? Vision::run_get('nj_652_runs', $id) : NULL;

        // Владение проверяем до обслуживания задачи очереди.
        if ( ! $initial OR ! Vision::run_owned($initial, $visitor, $user_id))
        {
            throw HTTP_Exception::factory(404, 'Разбор растения не найден');
        }

        $watch = Vision::run_watch('nj_652_runs', $id, 'nj652.plant');
        $run   = $watch['run'];

        if ( ! $run)
        {
            throw HTTP_Exception::factory(404, 'Разбор растения не найден');
        }

        $data  = Service_Nj652_Plant::shape((array) $run['result']);
        $weeks = (int) (Kohana::$config->load('nj652_plant.limits.weeks') ?: 4);

        $this->set_title($data['plant']['name_ru'].': уход и возможные проблемы');

        $this->template->content = View::factory('652/run', [
            'run'      => $run,
            'data'     => $data,
            'live'     => Vision::run_live($run),
            'hint'     => (string) $watch['hint'],
            'schedule' => Service_Nj652_Plant::schedule($data['care'], '', $weeks),
            'weeks'    => $weeks,
        ]);
    }

    /**
     * Последние разборы посетителя: по куке, а для вошедших — ещё и по
     * аккаунту (история следует за человеком, а не за браузером).
     */
    protected function _mine()
    {
        if ( ! Vision::table_exists('nj_652_runs'))
        {
            return [];
        }

        return Vision::runs_mine('nj_652_runs',
            class_exists('Visitor') ? (string) Visitor::uid() : '', 6,
            class_exists('Visitor') ? Visitor::user_id() : NULL);
    }
}
