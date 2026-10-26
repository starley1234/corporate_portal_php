<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Сетевые инструменты» (модуль NJ-633): /net
 *
 * Серверный слой выполняет проверки (DNS/WHOIS/TCP) с SSRF-защитой и
 * rate limit; JS в views/633/script.php — клиентское зеркало интерфейса,
 * xодит на /net/api/*. Сетевой I/O — только на сервере.
 */
class Controller_Nj633_Net extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Сетевые инструменты: DNS, WHOIS, проверка доступности порта онлайн');
        $this->set_description(
            'Бесплатные сетевые инструменты: DNS-записи (A, AAAA, MX, TXT, NS), WHOIS домена, проверка доступности хоста и порта. С защитой сервера от SSRF и ограничением частоты запросов.');
        $this->set_keywords('dns записи whois проверка доступность порта host сетевая диагностика ssrf mx txt ns');

    }

    public function action_index()
    {
        $recent = class_exists('Service_Nj633_Nettools')
            ? Service_Nj633_Nettools::recent_checks(6)
            : [];

        $this->template->content = View::factory('633/index', [
            'recent'    => $recent,
            'rate_text' => class_exists('Service_Nj633_Nettools')
                ? Service_Nj633_Nettools::rate_limit_text()
                : '5 проверок в минуту',
            'llm_ready' => class_exists('Llm'),
        ]);
    }
}
