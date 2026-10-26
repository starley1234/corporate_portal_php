<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Админка «Лаборатории векторов» (NJ-653): /veclab/admin.
 *
 * Зачем отдельная страница. Коллекция на /veclab личная: посетитель видит и
 * чистит только своё, по одному элементу. Владельцу сайта нужно обратное —
 * видеть ВСЁ, отбирать по посетителю, виду и модели и убирать пачками:
 * мусорные прогоны, чужие тесты, копии одной и той же картинки.
 *
 * Доступ — только роль admin. Любое изменение строго POST + CSRF, после него
 * редирект 303 с flash-сообщением: GET на этом сайте ничего не меняет.
 */
class Controller_Nj653_Admin extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Лаборатория векторов · админ');
        $this->set_module_nav([
            ['title' => 'Лаборатория', 'href' => '/veclab', 'icon' => 'fas fa-vector-square'],
            ['title' => 'Карта сходства', 'href' => '/veclab/map', 'icon' => 'fas fa-diagram-project'],
            ['title' => 'Модуль · админ', 'href' => '/veclab/admin', 'icon' => 'fas fa-shield-halved', 'roles' => ['admin']],
        ]);
    }

    public function action_index()
    {
        $filter = $this->_filter();

        $this->template->content = View::factory('653/admin', [
            'stats'     => Service_Nj653_Store::stats(),
            'filter'    => $filter,
            'items'     => Service_Nj653_Store::items_all($filter, 60),
            'total'     => Service_Nj653_Store::items_total($filter),
            'visitors'  => Service_Nj653_Store::visitors(30),
            'models'    => Service_Nj653_Store::models(),
            'dupes'     => Service_Nj653_Store::duplicate_pairs(
                (float) $this->_eps(), 40),
            'eps'       => $this->_eps(),
            'kinds'     => Service_Nj653_Veclab::KINDS,
            'flash'     => mb_substr((string) $this->request->query('flash'), 0, 300),
            'csrf'      => Security::token(),
        ]);
    }

    /** Убрать отмеченные элементы. */
    public function action_delete()
    {
        $this->_guard();

        $ids = (array) Arr::get($_POST, 'ids', []);

        $gone = Service_Nj653_Store::delete_ids($ids);

        $this->_back($gone > 0
            ? 'Убрано элементов: '.$gone.'.'
            : 'Ничего не отмечено — коллекция не изменилась.');
    }

    /** Убрать всё, что попадает под текущий отбор. */
    public function action_delete_filtered()
    {
        $this->_guard();

        $filter = $this->_filter($_POST);

        if ( ! array_filter($filter, function ($v) { return (string) $v !== ''; }))
        {
            $this->_back('Отбор пустой: чтобы стереть коллекцию целиком, есть отдельная кнопка.');
        }

        $gone = Service_Nj653_Store::delete_where($filter);

        $this->_back('По отбору убрано элементов: '.$gone.'.');
    }

    /** Убрать копии: из каждой группы похожих остаётся самый ранний. */
    public function action_delete_duplicates()
    {
        $this->_guard();

        $gone = Service_Nj653_Store::prune_duplicates($this->_eps($_POST));

        $this->_back($gone > 0
            ? 'Копий убрано: '.$gone.' (в каждой группе остался первый по времени).'
            : 'Копий не нашлось — коллекция уже чистая.');
    }

    /** Стереть коллекцию целиком. */
    public function action_truncate()
    {
        $this->_guard();

        if ((string) Arr::get($_POST, 'confirm', '') !== 'да')
        {
            $this->_back('Очистка отменена: в поле подтверждения нужно слово «да».');
        }

        $gone = Service_Nj653_Store::truncate_items();

        $this->_back('Коллекция очищена, элементов было: '.$gone.'.');
    }

    // ----------------------------------------------------------- помощники

    /** Отбор из запроса: что показываем и над чем работают кнопки. */
    protected function _filter(?array $source = NULL)
    {
        $src = $source === NULL ? (array) $this->request->query() : $source;

        return [
            'visitor'    => mb_substr(trim((string) ($src['visitor'] ?? '')), 0, 64),
            'kind'       => (string) ($src['kind'] ?? ''),
            'model'      => mb_substr(trim((string) ($src['model'] ?? '')), 0, 160),
            'search'     => mb_substr(trim((string) ($src['search'] ?? '')), 0, 120),
            'older_days' => max(0, min(3650, (int) ($src['older_days'] ?? 0))),
        ];
    }

    /** Порог «это копия»: косинусная дистанция, по умолчанию 0,02. */
    protected function _eps(?array $source = NULL)
    {
        $src = $source === NULL ? (array) $this->request->query() : $source;
        $eps = (float) ($src['eps'] ?? 0.02);

        return max(0.001, min(0.2, $eps ?: 0.02));
    }

    /** Защита от чужой формы: только POST и только со своим токеном. */
    protected function _guard()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }
    }

    /** Вернуться к списку, сохранив отбор, и сказать, что произошло. */
    protected function _back($message)
    {
        $filter = array_filter($this->_filter($_POST), function ($v) {
            return (string) $v !== '' AND (string) $v !== '0';
        });

        $filter['flash'] = (string) $message;

        HTTP::redirect('/veclab/admin?'.http_build_query($filter), 303);
    }
}
