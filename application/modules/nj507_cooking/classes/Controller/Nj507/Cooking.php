<?php defined('SYSPATH') or die('No direct script access.');

/** Кулинарный справочник: общая страница и индексируемые страницы продуктов. */
class Controller_Nj507_Cooking extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->set_title('Сколько грамм в стакане и ложке: кулинарный конвертер продуктов');
        $this->set_description('Переведите стаканы и ложки в граммы для 35 продуктов. Два объёма стакана, диапазоны плотности, масштабирование порций и пересчёт размера формы для выпечки.');
        $this->set_keywords('сколько грамм в стакане муки сахара соли сколько в ложке граммы стаканы кулинарный калькулятор');
    }

    public function action_index()
    {
        $query = $this->request->query();
        $product = isset($query['p']) && is_string($query['p']) ? $query['p'] : 'wheat-flour';
        $this->_render_page($product, FALSE);
    }

    public function action_product()
    {
        $product = (string) $this->request->param('product');
        $this->_render_page($product, TRUE);
    }

    protected function _render_page($product, $product_page)
    {
        $products = Service_Nj507_Cooking::products();
        if ( ! isset($products[$product]))
        {
            if ($product_page) throw HTTP_Exception::factory(404, 'Продукт не найден');
            $product = 'wheat-flour';
        }
        $item = $products[$product];
        if ($product_page)
        {
            $this->set_title('Сколько грамм '.$item['title'].' в стакане и ложке — расчёт в N2J');
            $this->set_description('Узнайте, сколько грамм '.$item['title'].' в стакане 200 или 250 мл, столовой и чайной ложке. Показан честный диапазон, а не одна условная цифра; есть обратный пересчёт граммов в меру.');
        }

        $query = $this->request->query();
        $measure = isset($query['u']) && is_string($query['u']) ? $query['u'] : 'cup200';
        if ( ! isset(Service_Nj507_Cooking::measures()[$measure])) $measure = 'cup200';
        $amount = isset($query['n']) && is_scalar($query['n']) ? mb_substr((string) $query['n'], 0, 30) : '1';
        $direction = isset($query['d']) && $query['d'] === 'to_measure' ? 'to_measure' : 'to_mass';
        $from_servings = isset($query['from']) && is_scalar($query['from']) ? mb_substr((string) $query['from'], 0, 8) : '1';
        $to_servings = isset($query['to']) && is_scalar($query['to']) ? mb_substr((string) $query['to'], 0, 8) : '1';
        $result = Service_Nj507_Cooking::calculate($product, $measure, $amount, $direction, $from_servings, $to_servings);

        $this->template->content = View::factory('507/index', [
            'products' => $products,
            'measures' => Service_Nj507_Cooking::measures(),
            'product' => $product,
            'product_item' => $item,
            'measure' => $measure,
            'amount' => $amount,
            'direction' => $direction,
            'from_servings' => $from_servings,
            'to_servings' => $to_servings,
            'result' => $result,
            'product_page' => (bool) $product_page,
            'data_note' => Service_Nj507_Cooking::data_note(),
            'sources' => Service_Nj507_Cooking::sources(),
        ]);
    }
}
