<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100: «Логи» /prx100/logs — чтение журналов платформы для администратора.
 *
 * Два источника (Service_Prx100_Logs): плоский журнал сайта (Log_Plain) и
 * php-лог Kohana по дням. Фильтры — GET (уровень «и выше», подстрока), чтобы
 * ссылкой можно было поделиться. Страница ничего не пишет и не удаляет:
 * ротация и чистка — дело писателей и /prx100/cleanup.
 */
class Controller_Prx100_Logs extends Controller_Prx100_Base {

    /** Сколько строк показывать по умолчанию и максимум. */
    const LIMIT = 200;
    const LIMIT_MAX = 1000;

    public function before()
    {
        parent::before();

        if ( ! $this->can('prx100.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Логи платформы — администрирование');
        $this->set_module_nav([
            ['title' => 'Администрирование', 'href' => '/admin', 'icon' => 'fas fa-gear'],
            ['title' => 'Хвост ошибок', 'href' => '/prx100/errors', 'icon' => 'fas fa-heart-crack'],
            ['title' => 'События', 'href' => '/prx208_events', 'icon' => 'fas fa-bolt'],
            ['title' => 'Очередь', 'href' => '/prx120/monitor', 'icon' => 'fas fa-list-check'],
        ]);
    }

    public function action_index()
    {
        // Источник — ссылка-переключатель; в форме фильтра день есть только у php-лога,
        // поэтому его наличие тоже означает kohana.
        $source = ($this->request->query('source') === 'kohana' OR $this->request->query('day') !== NULL) ? 'kohana' : 'site';
        $threshold = (string) $this->request->query('level');
        $threshold = array_key_exists($threshold, Service_Prx100_Logs::THRESHOLDS) ? $threshold : '';
        $q = trim((string) $this->request->query('q'));
        $limit = min(self::LIMIT_MAX, max(1, (int) $this->request->query('limit') ?: self::LIMIT));

        $days = Service_Prx100_Logs::kohana_days();
        $day = (string) $this->request->query('day');

        if ($source === 'kohana')
        {
            $valid = array_column($days, 'day');

            if ( ! in_array($day, $valid, TRUE))
            {
                $day = $days ? $days[0]['day'] : date('Y-m-d');
            }

            $result = Service_Prx100_Logs::read_kohana($day, $threshold, $q, $limit);
        }
        else
        {
            $day = '';
            $result = Service_Prx100_Logs::read_site($threshold, $q, $limit);
        }

        $this->template->content = View::factory('prx100/logs', [
            'source'    => $source,
            'result'    => $result,
            'days'      => $days,
            'day'       => $day,
            'threshold' => $threshold,
            'q'         => $q,
            'limit'     => $limit,
            'sources'   => Service_Prx100_Logs::sources(),
            'now'       => date('d.m.Y H:i'),
        ]);
    }
}
