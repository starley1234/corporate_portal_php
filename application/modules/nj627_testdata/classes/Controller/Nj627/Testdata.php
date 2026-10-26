<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Тестовые данные» (модуль NJ-627): /testdata
 */
class Controller_Nj627_Testdata extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Генератор тестовых данных: ФИО, телефоны и реквизиты с корректными контрольными цифрами');
        $this->set_description(
            'Сгенерировать тестовые ФИО, телефоны, e-mail и реквизиты организаций — ИНН, КПП, ОГРН и СНИЛС с корректными контрольными цифрами для QA и интеграций.');
        $this->set_keywords('генератор тестовых данных фейк фио телефон email инн кпп огрн снилс контрольная цифра qa');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $type  = isset($query['t']) ? (string) $query['t'] : 'fio';

        $this->template->content = View::factory('627/index', [
            'type' => $type,
        ]);
    }
}
