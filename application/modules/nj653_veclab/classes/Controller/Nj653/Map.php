<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Карта сходства коллекции (NJ-653): /veclab/map.
 *
 * Страница отвечает на вопрос, которого нет ни в поиске, ни в сравнении
 * пары: «как устроена вся коллекция целиком». Похожее стягивается в кучки,
 * чужое разлетается по углам, а связи показывают, какие пары модель считает
 * про одно и то же.
 *
 * Своё видно всем, чужое — только администратору: на карточках лежат
 * картинки посетителей (?visitor=… работает лишь для роли admin).
 */
class Controller_Nj653_Map extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        $this->set_title('Карта сходства коллекции: векторы смысла на плоскости');
        $this->set_description(
            'Вся коллекция мультимодальных эмбеддингов одной картинкой: похожие элементы стоят рядом, чужие по смыслу разлетаются. Видно, какие пары модель считает про одно и то же.');
        $this->set_keywords('карта эмбеддингов визуализация векторов косинусная близость MDS кластеры смыслов');

        $this->set_module_nav([
            ['title' => 'Лаборатория', 'href' => '/veclab', 'icon' => 'fas fa-vector-square'],
            ['title' => 'Карта сходства', 'href' => '/veclab/map', 'icon' => 'fas fa-diagram-project'],
            ['title' => 'Модуль · админ', 'href' => '/veclab/admin', 'icon' => 'fas fa-shield-halved', 'roles' => ['admin']],
        ]);
    }

    public function action_index()
    {
        // Замок: карта — часть «Лаборатории векторов», а она работает только
        // по бесплатному аккаунту (владелец, 08.10.2026). Гостя уводим на
        // страницу сервиса: там его встречает плашка входа.
        if ( ! Gate::logged_in())
        {
            HTTP::redirect('/veclab', 303);
        }

        $mine  = class_exists('Visitor') ? (string) Visitor::uid() : '';
        $admin = Auth::instance()->logged_in('admin');
        $asked = mb_substr(trim((string) $this->request->query('visitor')), 0, 64);

        // Чужую коллекцию показываем только админу — на ней чужие картинки.
        $visitor = ($admin AND $asked !== '') ? $asked : $mine;

        $link  = (float) $this->request->query('link');
        $link  = ($link >= -1 AND $link <= 1 AND $link != 0) ? $link : 0.6;
        $items = Service_Nj653_Store::items_with_vectors($visitor, 80);
        $map   = Service_Nj653_Map::build($items, $link);

        $this->template->content = View::factory('653/map', [
            'map'     => $map,
            'link'    => $link,
            'visitor' => $visitor,
            'mine'    => $visitor === $mine,
            'admin'   => $admin,
            'stats'   => Service_Nj653_Store::stats(),
            'count'   => count($items),
        ]);
    }
}
