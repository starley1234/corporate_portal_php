<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Сумма прописью» (модуль NJ-502): /summa-propisyu
 *
 * Контроллер тонкий: прочитал ?a=&u=&k=, отдал сервису, отрендерил вьюху.
 * Вся интерактивность — инлайн-скрипт views/502/script.php.
 */
class Controller_Nj502_Words extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Сумма прописью онлайн — рубли и копейки словами');
        $this->set_description(
            'Сумма прописью для договоров, платёжек и счетов: перевести число в прописи онлайн. Руб словами, копейки цифрами или словами. Формулировка «Двадцать две тысячи рублей 00 копеек» — мгновенно.');

        // Меню — из каталога nj-модулей (один источник для сайдбара на всех страницах)
    }

    public function action_index()
    {
        $query = $this->request->query();

        // ?a=22122,00&u=1&k=1 — share-ссылки
        $amount  = isset($query['a']) ? $query['a'] : '';
        $ucfirst = ! empty($query['u']);
        $kopecks = ! empty($query['k'])
            ? Service_Nj502_Words::KOPECKS_WORDS
            : Service_Nj502_Words::KOPECKS_DIGITS;

        $result = $amount !== '' || isset($query['a'])
            ? Service_Nj502_Words::convert($amount, $ucfirst, $kopecks)
            : NULL;

        $this->template->content = View::factory('502/index', [
            'amount'  => is_string($amount) ? $amount : (string) $amount,
            'ucfirst' => $ucfirst,
            'kopecks' => $kopecks,
            'result'  => $result,
        ]);
    }
}
