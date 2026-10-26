<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Календарь и даты» (модуль NJ-508): /calendar, /calendar/<год>
 *
 * Пять вкладок: рабочие дни между датами, дни до даты, возраст/стаж,
 * прибавить дни, календарь года. Календарь года рендерится на сервере
 * (поисковики и сохранение «как есть»), остальные вкладки считает API
 * (Service_Nj508_Calendar — единственная истина).
 */
class Controller_Nj508_Calendar extends Controller_Erp100_Base {

    /** @var string[] вкладки */
    protected static $_tabs = ['year', 'between', 'until', 'age', 'add'];

    public function before()
    {
        parent::before();

        $this->set_title('Производственный календарь РФ, рабочие дни между датами и точный возраст');
        $this->set_description(
            'Производственный календарь с переносами выходных и нормой часов, сколько рабочих дней между датами, сколько дней до даты, точный возраст и стаж, прибавить рабочие дни к дате. Бесплатно.');
        $this->set_keywords('производственный календарь 2026 рабочие дни между датами сколько дней до даты возраст по дате рождения стаж норма рабочего времени');
    }

    public function action_index()
    {
        $q = $this->request->query();
        $route_year = $this->request->param('year');

        $tab = isset($q['m']) && in_array($q['m'], self::$_tabs, TRUE) ? $q['m'] : 'year';
        $year = $route_year ? (int) $route_year : (int) date('Y');

        if ($route_year)
        {
            $tab = 'year';
        }

        $known = Service_Nj508_Calendar::known_years();

        $table = Service_Nj508_Calendar::year_table($year);
        $missing_year = NULL;

        if ($table === NULL)
        {
            if ($route_year)
            {
                // Адрес года без данных не должен отдавать «пустую» страницу под видом настоящей.
                throw HTTP_Exception::factory(404, 'Производственного календаря на '.$year.' год нет');
            }

            // Текущий год ещё не заполнен — честно говорим и показываем ближайший известный.
            $missing_year = $year;
            $fallback = $known ? ($year > max($known) ? max($known) : min($known)) : NULL;
            $table = $fallback !== NULL ? Service_Nj508_Calendar::year_table($fallback) : NULL;
            $year  = $fallback ?: $year;
            Kohana::$log->add(Log::WARNING, 'nj508: на :y год нет производственного календаря — добавьте его в config/nj508_calendar.php', [':y' => $missing_year]);
        }

        if ($route_year)
        {
            $this->set_title('Производственный календарь '.$year.' в России: праздники, переносы, нормы часов');
            $this->set_description(
                'Производственный календарь на '.$year.' год: выходные и праздничные дни, переносы, сокращённые дни, норма рабочего времени по месяцам при 40-, 36- и 24-часовой неделе.');
        }

        $this->template->content = View::factory('508/index', [
            'tab'          => $tab,
            'year'         => $year,
            'table'        => $table,
            'known_years'  => $known,
            'missing_year' => $missing_year,
            'today'        => date('Y-m-d'),
            'q'            => [
                'from'      => $this->_s($q, 'from'),
                'to'        => $this->_s($q, 'to'),
                'target'    => $this->_s($q, 'target'),
                'birth'     => $this->_s($q, 'birth'),
                'on'        => $this->_s($q, 'on'),
                'n'         => $this->_s($q, 'n'),
                'kind'      => $this->_s($q, 'kind') === 'work' ? 'work' : 'calendar',
                'inclusive' => $this->_s($q, 'inclusive'),
            ],
        ]);
    }

    protected function _s(array $q, $key)
    {
        return isset($q[$key]) && is_scalar($q[$key]) ? mb_substr((string) $q[$key], 0, 32) : '';
    }
}
