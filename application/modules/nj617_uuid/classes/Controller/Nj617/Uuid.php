<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Генератор идентификаторов» (модуль NJ-617): /uuid
 */
class Controller_Nj617_Uuid extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Генератор UUID и ULID онлайн — v4, v7, ULID');
        $this->set_description(
            'Сгенерировать UUID v4 (случайный), UUID v7 (сортируемый по времени) или ULID одним кликом — пачками до 100 штук. Для ключей БД, распределённых систем и агентов.');
        $this->set_keywords('uuid v4 v7 ulid генератор онлайн идентификатор guid случайный');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $kind  = isset($query['k']) ? $query['k'] : 'uuid4';

        $this->template->content = View::factory('617/index', [
            'kind'  => (string) $kind,
            'kinds' => Service_Nj617_Uuid::kinds(),
        ]);
    }
}
