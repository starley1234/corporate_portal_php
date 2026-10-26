<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страницы «Чека по фото» (модуль NJ-650).
 *
 *   /receipt       — снимок чека и последние разборы посетителя;
 *   /receipt/<id>  — разбор одного чека: позиции, сверка итога, категории,
 *                    дележ счёта (параметры дележа — в адресе, поэтому
 *                    ссылку можно переслать тем, с кем делите).
 *
 * Контроллер тонкий: разбор ответа модели и вся арифметика — в
 * `Service_Nj650_Receipt`, работа с прогонами — в общем фасаде `Vision`.
 */
class Controller_Nj650_Receipt extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Чек по фото: распознать позиции и разделить счёт');
        $this->set_description(
            'Сфотографируйте чек — сервис прочитает позиции и цены, сверит сумму с итогом, разложит траты по категориям и честно поделит счёт на всех, включая чаевые. Создание разбора — по бесплатному аккаунту.');
        $this->set_keywords(
            'чек по фото распознать чек разделить счёт поделить счёт на компанию траты по категориям расходы');
    }

    public function action_index()
    {
        $this->template->content = View::factory('650/index', [
            'ready'  => Vision::ready(),
            'limits' => (array) Kohana::$config->load('nj650_receipt.limits'),
            'mine'   => $this->_mine(),
            // Гость видит плашку входа вместо съёмки (решение владельца
            // 06.10.2026, «как опросы»): разбор занимает очередь и время
            // vision-модели, анониму он не положен. Гейт в API — 401.
            'guest'  => ! Auth::instance()->logged_in(),
        ]);
    }

    /** Разбор одного чека. Параметры дележа читаются из адреса. */
    public function action_run()
    {
        $id      = (int) $this->request->param('id');
        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $user_id = class_exists('Visitor') ? Visitor::user_id() : NULL;
        $initial = $id > 0 ? Vision::run_get('nj_650_runs', $id) : NULL;

        // Чужой чек не показываем: проверяем владельца до run_watch.
        if ( ! $initial OR ! Vision::run_owned($initial, $visitor, $user_id))
        {
            throw HTTP_Exception::factory(404, 'Разбор чека не найден');
        }

        $watch = Vision::run_watch('nj_650_runs', $id, 'nj650.receipt');
        $run   = $watch['run'];

        if ( ! $run)
        {
            throw HTTP_Exception::factory(404, 'Разбор чека не найден');
        }

        $query  = (array) $this->request->query();
        $data   = Service_Nj650_Receipt::shape((array) $run['result']);
        $people = isset($query['people']) ? (int) $query['people'] : 1;
        $tip    = isset($query['tip']) ? (float) str_replace(',', '.', (string) $query['tip']) : 0.0;

        $this->set_title(($data['merchant'] !== '' ? $data['merchant'].': ' : '').'разбор чека');

        $this->template->content = View::factory('650/run', [
            'run'    => $run,
            'data'   => $data,
            'live'   => Vision::run_live($run),
            'hint'   => (string) $watch['hint'],
            'people' => max(1, min((int) (Kohana::$config->load('nj650_receipt.limits.people') ?: 20), $people)),
            'tip'    => max(0.0, min(50.0, $tip)),
            'split'  => Service_Nj650_Receipt::split($data['items'], $people, [], $tip),
        ]);
    }

    /**
     * Последние разборы посетителя: по куке, а для вошедших — ещё и по
     * аккаунту (история следует за человеком, а не за браузером).
     */
    protected function _mine()
    {
        if ( ! Vision::table_exists('nj_650_runs'))
        {
            return [];
        }

        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $user_id = class_exists('Visitor') ? Visitor::user_id() : NULL;

        return Vision::runs_mine('nj_650_runs', $visitor, 6, $user_id);
    }
}
