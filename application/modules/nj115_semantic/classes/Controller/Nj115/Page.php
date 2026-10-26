<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj115: страница семантики /nj115 — «видно глазами, что модуль работает».
 *
 * До этой страницы у модуля был только JSON-API: понять, есть ли векторы,
 * идёт ли индексация, жив ли эмбеддинг-сервер, можно было только по логам и
 * по монитору воркеров. Владелец (28.09.2026): «займись модулем nj115, чтобы
 * он полноценно работал» — критерий «полноценно» проверяется именно здесь:
 *
 *   * коллекции и число векторов (что реально проиндексировано);
 *   * живой статус-ряд последней задачи semantic.index (идёт / Готово / Ошибка);
 *   * кнопка «Проверить» — живой вызов эмбеддинг-сервера (размерность и мс);
 *   * тестовый поиск: запрос → результаты с дистанциями;
 *   * очередь канала: последние задачи, этап, ошибка, «Повторить».
 *
 * Действия — через /nj115/api/* (ajax, без перезагрузки страницы). Доступ:
 * право `nj115.admin` (роль admin).
 */
class Controller_Nj115_Page extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj115.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Семантика · поиск и векторы');
        $this->set_description('Векторы платформы: коллекции, индексация через очередь, семантический поиск. Здесь видно, что проиндексировано, живой ли эмбеддинг-сервер и что делает последняя задача.');

        $this->set_module_nav([
            ['title' => 'Монитор воркеров', 'href' => '/nj120/monitor', 'icon' => 'fas fa-gauge-high', 'acl' => 'nj120.monitor'],
            ['title' => 'Воркеры · док', 'href' => '/nj120/workers', 'icon' => 'fas fa-book'],
        ]);
    }

    /** /nj115 — одна страница: коллекции, очередь, проверка, поиск. */
    public function action_index()
    {
        $panel = [];

        try
        {
            $panel = Service_Nj115_Semantic::panel();
        }
        catch (Throwable $e)
        {
            $panel = ['error' => $e->getMessage(), 'collections' => [], 'queue' => [], 'health' => [], 'totals' => []];
        }

        $this->template->content = View::factory('115/index', [
            'panel' => $panel,
            'csrf'  => Security::token(),
        ]);
    }
}
