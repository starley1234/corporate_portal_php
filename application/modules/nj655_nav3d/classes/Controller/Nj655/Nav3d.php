<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-655 — отдельный 3D-вход в каталог сервисов.
 *
 * Страница намеренно не собирает манифесты сама: браузер получает ровно тот
 * список сервисов, который API nj110_catalog уже отфильтровал по аудитории и
 * правам текущего посетителя. Обычный каталог и меню при этом не затрагиваются.
 */
class Controller_Nj655_Nav3d extends Controller_Erp100_Base
{
    /**
     * Карта — самостоятельный режим исследования, а не страница внутри
     * обычного сайдбарного каркаса. Поэтому не наследуем template_tailwindcss.
     */
    public $template = '655/template_orbit';

    public function before()
    {
        parent::before();

        $this->set_title('3D-карта сервисов N2J — интерактивная навигация');
        $this->set_description('Интерактивная 3D-карта сервисов N2J: выберите раздел, найдите инструмент по задаче и перейдите к нему. Карта получает актуальный каталог через API.');
        $this->set_keywords('3D карта сервисов интерактивная навигация каталог инструментов Three.js N2J');

        // Только локальные ассеты: Three.js уже лежит в репозитории и не тянется
        // с CDN. defer сохраняет порядок: сначала THREE, затем код карты.
        $this->add_style('assets/nj655/nav3d.css');
        $this->add_script('assets/vendor/three/three.min.js', TRUE);
        $this->add_script('assets/nj655/nav3d.js', TRUE);

        // Верхняя панель карты рендерится её собственным шаблоном. Не добавляем
        // module_nav в общий шаблон и не меняем старую навигацию сайта.
    }

    public function action_index()
    {
        $this->template->content = View::factory('655/index', [
            // Контракт nj110: API уже отдаёт только те сервисы, которые можно
            // показать текущему посетителю (guest/auth/admin/agent).
            'catalog_api' => '/nj110/api/catalog',
        ]);
    }
}
