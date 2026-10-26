<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Быстрые опросы (NJ-520): /poll — создание, /p/<code> — голосование и результаты.
 *
 * Контроллер тонкий: токен CSRF, вызовы Service_Nj520_Poll, редиректы.
 *
 * Ключ голосующего берётся ДО вывода тела ответа (`voter_key()` лениво ставит
 * браузерную куку платформы): после первого байта тела куку уже не поставить.
 */
class Controller_Nj520_Poll extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Быстрый опрос онлайн — Ask 2 Vote');
        $this->set_description(
            'Создайте опрос за 10 секунд и поделитесь ссылкой: голосование без регистрации, живые результаты. Один голос с одного браузера. Создание опроса — по бесплатному аккаунту.');

        $this->set_module_nav([
            ['title' => 'Быстрый опрос', 'href' => '/poll', 'active_paths' => ['/p'], 'icon' => 'fas fa-chart-simple'],
            ['title' => 'Управление опросами', 'href' => '/nj520/admin', 'icon' => 'fas fa-shield-halved', 'roles' => ['admin']],
        ]);
    }

    /**
     * GET /poll — форма создания; POST /poll — создать и перейти на /p/<code>.
     *
     * Создание — только для зарегистрированных (решение владельца 06.10.2026:
     * анонимные опросы никто не читает, а БД и ленты забивают). Голосование
     * на /p/<code> остаётся публичным — в этом суть сервиса.
     */
    public function action_index()
    {
        $title = '';
        $options = '';
        $error = NULL;
        $guest = ! Auth::instance()->logged_in();

        if ($this->request->method() === Request::POST)
        {
            if ($guest)
            {
                // форма гостю не показывается — это обход UI; после входа
                // login_redirect вернёт сюда (Referer)
                HTTP::redirect('/auth/login', 302);
            }

            $title = Arr::get($_POST, 'title', '');
            $options = Arr::get($_POST, 'options', '');

            if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
            {
                throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
            }

            $code = Service_Nj520_Poll::create($title, preg_split('/\r\n|\r|\n/', $options));

            if ($code !== NULL)
            {
                HTTP::redirect('/p/'.$code.'?created=1');
            }

            $error = 'Нужны заголовок и минимум два разных варианта (до 10).';
        }

        $this->template->content = View::factory('520/index', [
            'title_val'   => $title,
            'options_val' => $options,
            'error'       => $error,
            'guest'       => $guest,
            'recent'      => Service_Nj520_Poll::recent_active(5),
        ]);
    }

    /**
     * GET /p/<code> — голосование/результаты; POST /p/<code> — голос.
     */
    public function action_view()
    {
        $code = $this->request->param('code');
        $poll = Service_Nj520_Poll::by_code($code);

        if ($poll === NULL)
        {
            throw HTTP_Exception::factory(404, 'Опрос не найден');
        }

        $voter_key = Service_Nj520_Poll::voter_key();

        if ($this->request->method() === Request::POST)
        {
            if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
            {
                throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
            }

            $status = Service_Nj520_Poll::vote((int) $poll['id'], (int) Arr::get($_POST, 'option', 0), $voter_key);

            $flag = ['ok' => '?voted=1', 'closed' => '?closed=1', 'already' => '?already=1'];

            HTTP::redirect('/p/'.$code.Arr::get($flag, $status, ''), 303);
        }

        $results = Service_Nj520_Poll::results($code);
        $my_vote = Service_Nj520_Poll::my_vote((int) $poll['id'], $voter_key);

        $this->set_title('Опрос: '.$poll['title'].' — результаты и голосование');
        $this->template->content = View::factory('520/poll', [
            'results'      => $results,
            'my_vote'      => $my_vote,
            'voted'        => $my_vote > 0,
            // Цифры — только проголосовавшему (или когда опрос уже закрыт).
            'show_results' => Service_Nj520_Poll::results_visible($poll, $my_vote > 0),
            'created'      => ! empty($_GET['created']),
            'already'      => ! empty($_GET['already']),
            'just_voted'   => ! empty($_GET['voted']),
            'closed_req'   => ! empty($_GET['closed']),
            'recent'       => Service_Nj520_Poll::recent_active(5, $code),
            'share_url'    => URL::base(TRUE).'p/'.$poll['code'],
            'csrf'         => Security::token(),
        ]);
    }
}
