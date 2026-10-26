<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Калькулятор НДС» (модуль NJ-501): /vat
 *
 * Контроллер тонкий: прочитал параметры URL (?a=&r=&m=), отдал их сервису,
 * отрендерил вьюху. Вся интерактивность — инлайн-скрипт во вьюхе
 * (views/501/script.php), мгновенный пересчёт без обращений к серверу
 * и без отдельных HTTP-запросов за статикой.
 */
class Controller_Nj501_Vat extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Калькулятор НДС онлайн 2026 — выделить и начислить');
        $this->set_description(
            'Бесплатный калькулятор НДС: выделить НДС из суммы или начислить сверху. Актуальные ставки 2026 года — 22%, 20%, 10%, пониженные 7% и 5% для УСН. Формулы и примеры.');

        // Меню — из каталога nj-модулей (один источник для сайдбара на всех страницах)
    }

    public function action_index()
    {
        $query = $this->request->query();

        // Параметры из URL (share-ссылки): ?a=10000&r=22&m=extract
        $amount = isset($query['a']) ? $query['a'] : '';
        $rate   = isset($query['r']) ? $query['r'] : 22;
        $mode   = isset($query['m']) && $query['m'] === Service_Nj501_Vat::MODE_ADD
                ? Service_Nj501_Vat::MODE_ADD
                : Service_Nj501_Vat::MODE_EXTRACT;

        $result = Service_Nj501_Vat::calculate($amount, $rate, $mode);

        // Если параметры некорректны — тихо показываем чистую форму с дефолтами
        if ($result === NULL)
        {
            $amount = '';
            $rate = 22;
            $mode = Service_Nj501_Vat::MODE_EXTRACT;
            $result = NULL;
        }

        $this->template->content = View::factory('501/index', [
            'amount'    => is_string($amount) ? $amount : (string) $amount,
            'rate'      => Service_Nj501_Vat::normalize_rate($rate) ?: 22,
            'mode'      => $mode,
            'rates'     => Service_Nj501_Vat::rates(),
            'result'    => $result, // NULL = форма ещё пустая
        ]);
    }
}
