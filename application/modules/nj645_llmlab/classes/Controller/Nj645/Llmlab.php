<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Лаборатория LLM» (NJ-645): /llmlab — интерактивные демонстрации того,
 * как устроена языковая модель, и накопление знаний о ней.
 *
 * Страница одна, вкладки внутри: токены, следующий токен, семантика, промпты,
 * знания. Данные вкладок считаются здесь и один раз передаются во вьюху;
 * интерактив (токенизация, сэмплирование, квиз) ходит в /llmlab/api/*.
 */
class Controller_Nj645_Llmlab extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        $this->set_title('Лаборатория LLM: как языковая модель видит и выбирает текст');
        $this->set_description(
            'Интерактивные демонстрации работы языковой модели: токенизация и стоимость, вероятность следующего токена, температура и top-p, эмбеддинги и семантическая близость, разбор промпта и квиз по терминам. Знания копятся в базе: корпус, статистика ответов, библиотека промптов.');
        $this->set_keywords('как работают языковые модели llm токены токенизация температура top-p сэмплирование эмбеддинги промпт квиз обучение');

        $this->set_module_nav([
            ['title' => 'Лаборатория', 'href' => '/llmlab', 'icon' => 'fas fa-flask'],
            ['title' => 'Модуль · админ', 'href' => '/nj645/admin', 'icon' => 'fas fa-shield-halved', 'roles' => ['admin']],
        ]);
    }

    public function action_index()
    {
        Service_Nj645_Seed::ensure();

        $visitor = class_exists('Visitor') ? (string) Visitor::uid() : '';

        $models = (array) Service_Nj645_Store::cfg('models', []);
        $limits = (array) Service_Nj645_Store::cfg('limits', []);

        $this->template->content = View::factory('645/index', [
            'stats'      => Service_Nj645_Store::stats(),
            'models'     => $models,
            'rates'      => (array) Service_Nj645_Store::cfg('chars_per_token', []),
            'answer_tokens' => (int) Service_Nj645_Store::cfg('answer_tokens', 400),
            'limits'     => $limits,
            'terms'      => Service_Nj645_Store::terms_all(),
            'prompts'    => Service_Nj645_Store::prompts_list(),
            'corpus'     => Service_Nj645_Store::corpus_titles(8),
            'confusions' => Service_Nj645_Store::quiz_confusions(6),
            'guess'      => Service_Nj645_Store::guess_stats(),
            'hardest'    => Service_Nj645_Store::guess_hardest(5),
            'public_runs' => Service_Nj645_Store::runs_public(6),
            'my_runs'    => $visitor !== '' ? Service_Nj645_Store::runs_mine($visitor, 6) : [],
            'vectors'    => Service_Nj645_Store::vectors_map(40),
            'visitor'    => $visitor,
            'llm_ready'  => Service_Nj645_Lab::llm_available(),
            'models_updated' => (string) Service_Nj645_Store::cfg('models_updated', ''),
        ]);
    }
}
