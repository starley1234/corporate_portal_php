<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-677 «Разговор с ИИ голосом» — страница.
 *
 *   GET /talk — режим разговора: одна кнопка «Начать», дальше микрофон
 *               включается и выключается сам (см. views/677/script.php).
 *
 * Доступ по аккаунту: разговор тратит распознавание, модель и синтез, а история
 * реплик — личная. Гость видит описание сервиса с плашкой входа (Gate::wall),
 * как /voice и /veclab.
 *
 * JSON-часть для браузера — Controller_Nj677_Api (/talk/api/*).
 */
class Controller_Nj677_Talk extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        $this->set_title('Разговор с ИИ голосом: говорите — он отвечает вслух');
        $this->set_description('Голосовой разговор с ИИ без кнопок: включили режим — говорите. Речь распознаётся на лету, ответ модели читается вслух, перебить можно голосом.');
        $this->set_keywords('голосовой чат с ии разговор голосом искусственный интеллект речь микрофон без кнопок ассистент вслух');
        $this->set_module_nav([
            ['title' => 'Разговор голосом', 'href' => '/talk', 'icon' => 'fas fa-microphone-lines'],
            ['title' => 'Озвучивание текста', 'href' => '/voice', 'icon' => 'fas fa-volume-high'],
        ]);
    }

    /** GET /talk — рабочая страница или плашка входа. */
    public function action_index()
    {
        if ( ! Gate::logged_in())
        {
            $this->template->content = Gate::wall('talk', [
                'icon'   => 'fas fa-microphone-lines',
                'title'  => 'Разговор с ИИ голосом — по аккаунту',
                'text'   => 'Разговор тратит распознавание речи, модель и синтез, а история реплик — личная. Войдите, чтобы включить микрофон.',
                'points' => [
                    'Кнопка одна: включили разговор — дальше микрофон работает сам',
                    'Ответ читается вслух по частям: первая фраза звучит, пока готовятся остальные',
                    'Перебить можно голосом — микрофон останавливает ответ',
                    'История разговоров хранится 30 дней и видна только вам',
                ],
            ]);

            return;
        }

        $user = $this->_current_user;
        $user_id = $user ? (int) $user->id : 0;
        $enabled = Service_Nj677_Talk::enabled();

        // ?c=код — вернуться к разговору: страницу можно перезагрузить, история
        // не должна от этого пропадать. Чужой код не открывает ничего (см. talk()).
        $code = (string) $this->request->query('c');
        $talk = $enabled ? Service_Nj677_Talk::talk($code, $user_id) : NULL;
        $messages = $talk ? Service_Nj677_Talk::history((int) $talk['id']) : [];

        $this->template->content = View::factory('677/index', [
            'enabled'    => $enabled,
            'missing'    => Service_Nj677_Talk::missing(),
            'voices'     => $enabled ? Service_Nj677_Talk::voices() : [],
            'voice'      => $talk ? (string) $talk['voice'] : Service_Nj677_Talk::default_voice(),
            'rate_text'  => Service_Nj677_Talk::rate_text(),
            'recent'     => $enabled ? Service_Nj677_Talk::recent($user_id) : [],
            'limits'     => Service_Nj677_Talk::limits(),
            'csrf'       => Security::token(),
            'talk'       => $talk,
            'messages'   => $messages,
        ]);
    }
}
