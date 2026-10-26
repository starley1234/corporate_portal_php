<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Тестер регулярных выражений» (модуль NJ-622): /regex — офлайн-режим.
 */
class Controller_Nj622_Regex extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Тест регулярного выражения онлайн — офлайн, без утечки');
        $this->set_description(
            'Проверить регулярное выражение: совпадения, группы, флаги, замена — серверный прогон без интернета. JSON API для интеграций и ИИ-агентов.');
        $this->set_keywords('regex регулярное выражение тест тестер совпадения группы замена pcre онлайн');

    }

    public function action_index()
    {
        $query   = $this->request->query();
        $pattern = isset($query['p']) ? $query['p'] : '';
        $test    = isset($query['t']) ? $query['t'] : '';
        $flags   = isset($query['f']) ? $query['f'] : '';

        $result = $pattern !== '' ? Service_Nj622_Regex::run($pattern, $test, '', $flags) : NULL;

        $this->template->content = View::factory('622/index', [
            'pattern' => (string) $pattern,
            'test'    => (string) $test,
            'flags'   => (string) $flags,
            'result'  => $result,
        ]);
    }
}
