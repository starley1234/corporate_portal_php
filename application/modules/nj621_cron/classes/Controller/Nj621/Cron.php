<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Cron-человечизатор» (модуль NJ-621): /cron
 */
class Controller_Nj621_Cron extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Cron на человеческом языке — расшифровать выражение');
        $this->set_description(
            'Перевести cron-выражение (минута час день месяц день_недели) на человеческий язык и узнать следующие запуски. Для админов, DevOps и ИИ-агентов.');
        $this->set_keywords('cron crontab выражение расписание запуск расшифровать человекочитаемый онлайн');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $expr  = isset($query['e']) ? $query['e'] : '';

        $result = $expr !== '' ? Service_Nj621_Cron::explain($expr) : NULL;

        $this->template->content = View::factory('621/index', [
            'expr'   => (string) $expr,
            'result' => $result,
        ]);
    }
}
