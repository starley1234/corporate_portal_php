<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120: «Каналы очереди» /prx120/channels — по каналу: ожидают, в работе,
 * упали за сутки, возраст самой старой ожидающей, лимиты (одновременно /
 * интервал) и «Повторить упавшие» (за 7 дней, до 100 за раз, каждую один
 * раз). Право prx120.monitor (только admin).
 */
class Controller_Prx120_Channels extends Controller_Prx102_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('prx120.monitor'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_module_nav([
            ['title' => 'Монитор очереди', 'href' => '/prx120/monitor', 'icon' => 'fas fa-gauge-high'],
            ['title' => 'Каналы и лимиты', 'href' => '/prx120/channels', 'icon' => 'fas fa-sliders'],
            ['title' => 'Ресурсы и стоимость', 'href' => '/prx120/usage', 'icon' => 'fas fa-coins'],
        ]);
    }

    public function action_index()
    {
        if ($this->request->method() === Request::POST)
        {
            HTTP::redirect('/prx120/channels?flash='.urlencode($this->_post()), 303);
        }

        $this->set_title('Каналы очереди · управление');

        $this->template->content = View::factory('prx120/channels', [
            'channels'  => Service_Prx120_Channels::overview(),
            'flash'     => (string) $this->request->query('flash'),
            'csrf'      => Security::token(),
            'retry_days' => Service_Prx120_Channels::RETRY_DAYS,
            'retry_cap'  => Service_Prx120_Channels::RETRY_CAP,
        ]);
    }

    /** @return string текст для flash */
    protected function _post(): string
    {
        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            return 'Сессия устарела — повторите';
        }

        $act     = (string) $this->request->post('act');
        $channel = trim((string) $this->request->post('channel'));

        try
        {
            switch ($act)
            {
                case 'limits':
                    $max = (int) $this->request->post('max_concurrent');
                    $gap = (int) $this->request->post('min_interval_sec');
                    Service_Prx120_Channels::set_limit($channel, $max, $gap, (int) $this->_current_user->id);

                    return ($max === 0 AND $gap === 0)
                        ? 'Лимиты канала '.$channel.' сняты'
                        : 'Лимиты канала '.$channel.' сохранены: одновременно '.($max ?: '∞')
                            .', интервал '.($gap > 0 ? $gap.' с' : 'нет');

                case 'retry_failed':
                    $ids = Service_Prx120_Channels::retry_failed($channel);

                    return $ids
                        ? 'Канал '.$channel.': поставлено заново '.count($ids).' (#'.implode(', #', array_slice($ids, 0, 10)).(count($ids) > 10 ? '…' : '').')'
                        : 'Канал '.$channel.': повторять нечего';
            }

            return 'Неизвестное действие';
        }
        catch (InvalidArgumentException $e)
        {
            return $e->getMessage();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'prx120 channels: :a :c: :e',
                [':a' => $act, ':c' => $channel, ':e' => $e->getMessage()]);

            return 'Не получилось: '.$e->getMessage();
        }
    }
}
