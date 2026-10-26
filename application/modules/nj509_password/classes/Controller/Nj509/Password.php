<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Генератор паролей» (модуль NJ-509): /password
 *
 * SSR генерирует стартовый пароль (каждая загрузка страницы — новый),
 * дальше всё пересчитывается инлайн-скриптом views/509/script.php на
 * crypto.getRandomValues. Пароли не сохраняются нигде.
 */
class Controller_Nj509_Password extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Генератор паролей онлайн — надёжный пароль за секунду');
        $this->set_description(
            'Генератор надёжных паролей онлайн: длина до 96 символов, буквы, цифры, спецсимволы, исключение похожих символов. Криптостойкий ГСЧ, пароли не сохраняются и не передаются.');

        // Меню — из каталога nj-модулей (один источник для сайдбара на всех страницах)
    }

    public function action_index()
    {
        $query = $this->request->query();

        $opts = [
            'length'  => isset($query['l']) ? (int) $query['l'] : 16,
            'upper'   => ! isset($query['u']) OR $query['u'] !== '0',
            'lower'   => ! isset($query['l2']) OR $query['l2'] !== '0',
            'digits'  => ! isset($query['d']) OR $query['d'] !== '0',
            'symbols' => isset($query['s']) AND $query['s'] === '1',
            'exclude_ambiguous' => isset($query['x']) AND $query['x'] === '1',
        ];

        $generated = Service_Nj509_Password::generate($opts);

        if ($generated === NULL)
        {
            $generated = Service_Nj509_Password::generate([]);
            $opts = ['length' => 16, 'upper' => TRUE, 'lower' => TRUE, 'digits' => TRUE, 'symbols' => FALSE, 'exclude_ambiguous' => FALSE];
        }

        $this->template->content = View::factory('509/index', [
            'opts'      => $opts,
            'generated' => $generated,
            'sets_info' => Service_Nj509_Password::sets_info(),
            'min_len'   => Service_Nj509_Password::MIN_LENGTH,
            'max_len'   => Service_Nj509_Password::MAX_LENGTH,
        ]);
    }
}
