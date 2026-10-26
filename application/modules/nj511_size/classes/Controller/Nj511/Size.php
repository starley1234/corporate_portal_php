<?php defined('SYSPATH') or die('No direct script access.');

/** Страница подбора размера по мерке. */
class Controller_Nj511_Size extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->set_title('Подбор размера одежды, обуви, перчаток и кольца по меркам');
        $this->set_description('Введите обхват груди, рост, длину стопы, ладони или диаметр кольца — получите ближайший размер RU/EU/US и подсказки по замеру. Таблицы справочные: бренды отличаются.');
        $this->set_keywords('таблица размеров одежды обуви перчаток колец подобрать размер по меркам RU EU US обхват груди длина стопы');
    }

    public function action_index()
    {
        $categories = Service_Nj511_Size::categories();
        $query = $this->request->query();
        $category = isset($query['type']) && is_string($query['type']) ? $query['type'] : 'women';
        if ( ! isset($categories[$category])) $category = 'women';
        $measure = isset($query['v']) && is_scalar($query['v']) ? mb_substr((string) $query['v'], 0, 32) : '';
        $result = $measure !== '' ? Service_Nj511_Size::calculate($category, $measure) : NULL;
        if ($measure !== '' AND $result === NULL) $measure = '';

        $this->template->content = View::factory('511/index', [
            'categories' => $categories,
            'category' => $category,
            'measure' => $measure,
            'result' => $result,
            'sources' => Service_Nj511_Size::sources(),
            'notice' => Service_Nj511_Size::notice(),
        ]);
    }
}
