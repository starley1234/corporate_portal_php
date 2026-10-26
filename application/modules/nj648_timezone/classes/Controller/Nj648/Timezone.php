<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Созвон по часовым поясам» (модуль NJ-648): /timezones
 *
 * Расчёт серверный, форма — обычный GET: работает без JavaScript, а ссылку с
 * составом участников и датой можно переслать тем, кого зовёшь на созвон.
 */
class Controller_Nj648_Timezone extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Время по городам и планировщик созвона — часовые пояса онлайн');
        $this->set_description(
            'Подберите час, удобный всем: сетка суток с местным временем участников из разных часовых поясов, рабочие окна и текущее время городов России и мира.');
        $this->set_keywords(
            'часовые пояса разница во времени планировщик созвона время в городах сейчас мировое время совещание');
    }

    public function action_index()
    {
        $query = (array) $this->request->query();

        $people = Service_Nj648_Timezone::people(Arr::get($query, 'p', []));

        $grid = Service_Nj648_Timezone::grid(
            $people,
            Arr::get($query, 'date', ''),
            Arr::get($query, 'from', NULL),
            Arr::get($query, 'to', NULL));

        $now = [];

        foreach ($people as $person)
        {
            $now[] = Service_Nj648_Timezone::now($person);
        }

        $this->template->content = View::factory('648/index', [
            'grid'    => $grid,
            'now'     => $now,
            'grouped' => Service_Nj648_Timezone::grouped(),
            'max'     => Service_Nj648_Timezone::max_people(),
        ]);
    }
}
