<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «JSON-инструмент» (модуль NJ-614): /json
 *
 * Форматтер/валидатор/минификатор с подсветкой. Контроллер тонкий: прочитал
 * ?q=&indent=&sort= → отдал сервису → отрендерил вьюху. Живой пересчёт —
 * инлайн-скрипт views/614/script.php (клиентское зеркало Service_Nj614_Json).
 */
class Controller_Nj614_Json extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('JSON-инструмент онлайн: форматирование, валидация, минификация');
        $this->set_description(
            'Бесплатный JSON-инструмент: отформатировать, проверить на ошибки, сжать в одну строку, отсортировать ключи. Подсветка синтаксиса, статистика структуры и JSON API для интеграций.');
        $this->set_keywords('json форматировать валидатор онлайн минифицировать beautify сжать сортировка ключей');

    }

    public function action_index()
    {
        $query = $this->request->query();

        $text   = isset($query['q']) ? $query['q'] : '';
        $indent = isset($query['indent']) ? (int) $query['indent'] : 2;
        $sort   = ! empty($query['sort']);

        $result = NULL;

        if ($text !== '')
        {
            $result = Service_Nj614_Json::format($text, $indent, $sort);
        }

        $this->template->content = View::factory('614/index', [
            'text'   => (string) $text,
            'indent' => $indent >= 0 ? $indent : 2,
            'sort'   => $sort,
            'result' => $result, // NULL = форма ещё пустая
        ]);
    }
}
