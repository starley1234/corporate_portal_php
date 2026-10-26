<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-677 «Разговор с ИИ голосом» — JSON API для страницы /talk.
 *
 * Контракт рассчитан на то, что браузер зовёт эти точки по одной в нужный
 * момент, а не «одним большим запросом на весь разговор»:
 *
 *   POST /talk/api/hear   multipart: audio, csrf
 *        запись с микрофона → текст (распознавание речи).
 *        Отдельная точка, чтобы человек увидел свои слова сразу, ещё до
 *        ответа модели: разговор не должен выглядеть зависшим.
 *
 *   POST /talk/api/reply  JSON: csrf, talk (код, '' — новый), text, stt_ms
 *        текст → ответ модели. Возвращает reply (текст целиком) и parts —
 *        готовые части для озвучки: браузер заказывает их по одной и начинает
 *        играть первую, пока синтезируются остальные.
 *
 *   POST /talk/api/say    JSON: csrf, text, voice
 *        часть ответа → аудио (mp3) сырыми байтами. Именно байтами, а не
 *        base64: на одну фразу экономим треть трафика и разбор.
 *
 * Все три требуют аккаунт (Gate) и CSRF. Ошибки — JSON; 401 — тоже JSON,
 * чтобы страница увела человека на вход, а не показала HTML ошибки.
 */
class Controller_Nj677_Api extends Controller
{
    public $auto_render = FALSE;

    /** Тело запроса: JSON или обычный POST. */
    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
        $this->response->headers('Cache-Control', 'no-store');

        $raw = $this->request->body();
        $decoded = $raw ? json_decode($raw, TRUE) : NULL;

        $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
    }

    /** POST /talk/api/hear — распознать запись с микрофона. */
    public function action_hear()
    {
        if (($guard = $this->_guard('POST')) !== NULL)
        {
            return $guard;
        }

        $user_id = $this->_user_id();

        if ( ! Service_Nj677_Talk::enabled())
        {
            return $this->_respond(['ok' => FALSE, 'error' => Service_Nj677_Talk::missing()], 503);
        }

        if ( ! Service_Nj677_Talk::rate_ok($user_id))
        {
            Service_Nj677_Talk::log_event('nj677: лимит распознавания исчерпан', [
                'user' => (int) $user_id,
            ]);

            return $this->_respond([
                'ok'    => FALSE,
                'error' => 'Слишком часто. Лимит разговора: '.Service_Nj677_Talk::rate_text().'.',
            ], 429);
        }

        $file = Arr::get((array) $_FILES, 'audio', []);

        if ( ! is_array($file) OR (int) Arr::get($file, 'error', UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Запись не доехала до сервера.'], 400);
        }

        $tmp = (string) Arr::get($file, 'tmp_name', '');

        if ($tmp === '' OR ! is_uploaded_file($tmp))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Файл записи не принят сервером.'], 400);
        }

        $size = (int) Arr::get($file, 'size', 0);
        $mime = (string) Arr::get($file, 'type', '');

        $check = Service_Nj677_Talk::audio_ok($size, $mime);

        if ( ! $check['ok'])
        {
            Service_Nj677_Talk::log_event('nj677: запись с микрофона отклонена', [
                'user' => (int) $user_id,
                'kb'   => (int) round($size / 1024),
                'mime' => mb_substr($mime, 0, 60),
                'why'  => $check['error'],
            ]);

            return $this->_respond(['ok' => FALSE, 'error' => $check['error']], 400);
        }

        $bytes = (string) file_get_contents($tmp);

        if ($bytes === '')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Пустая запись.'], 400);
        }

        $stt = Service_Nj665_Voice::transcribe(
            $bytes,
            'speech.'.$check['ext'],
            Service_Nj677_Talk::language()
        );

        if ( ! $stt['ok'])
        {
            Service_Nj677_Talk::log_event('nj677: распознавание речи не удалось', [
                'user'  => (int) $user_id,
                'model' => (string) $stt['model'],
                'error' => mb_substr((string) $stt['error'], 0, 300),
            ]);

            return $this->_respond([
                'ok'    => FALSE,
                'error' => $stt['error'] !== '' ? $stt['error'] : 'Речь не распознана — скажите ещё раз.',
            ], 502);
        }

        // Распознаватель на тишине «дописывает» титры обучающих видео
        // («Редактор субтитров А.Семкин…»). Такую реплику в разговор не пускаем:
        // человек молчал, а модель уже собиралась отвечать неизвестно на что.
        $voiced_ms = max(0, min(120000, (int) Arr::get($this->_body, 'voiced_ms', 0)));
        $accept = Service_Nj677_Talk::accept_speech($stt['text'], $voiced_ms);

        if ( ! $accept['ok'])
        {
            Service_Nj677_Talk::log_event('nj677: реплика отклонена как шум', [
                'user'   => (int) $user_id,
                'voiced' => $voiced_ms,
                'ms'     => (int) $stt['ms'],
                'text'   => mb_substr((string) $stt['text'], 0, 120),
            ]);

            return $this->_respond([
                'ok'    => FALSE,
                'error' => $accept['why'],
                'noise' => TRUE,
            ], 422);
        }

        return $this->_respond([
            'ok'   => TRUE,
            'text' => $accept['text'],
            'ms'   => (int) $stt['ms'],
        ]);
    }

    /** POST /talk/api/reply — ответ модели на реплику пользователя. */
    public function action_reply()
    {
        if (($guard = $this->_guard('POST')) !== NULL)
        {
            return $guard;
        }

        $user_id = $this->_user_id();
        $text = (string) Arr::get($this->_body, 'text', '');
        $code = (string) Arr::get($this->_body, 'talk', '');
        $stt_ms = max(0, min(120000, (int) Arr::get($this->_body, 'stt_ms', 0)));

        $out = Service_Nj677_Talk::turn($user_id, $code, $text, $stt_ms);

        if ( ! $out['ok'])
        {
            $status = (strpos($out['error'], 'Слишком часто') === 0) ? 429 : 400;

            return $this->_respond([
                'ok'    => FALSE,
                'error' => $out['error'],
                'talk'  => $out['talk'],
            ], $status);
        }

        return $this->_respond([
            'ok'     => TRUE,
            'talk'   => $out['talk'],
            'reply'  => $out['reply'],
            'parts'  => $out['parts'],
            'model'  => $out['model'],
            'llm_ms' => (int) $out['llm_ms'],
        ]);
    }

    /** POST /talk/api/say — озвучить часть ответа (сырые байты mp3). */
    public function action_say()
    {
        if (($guard = $this->_guard('POST')) !== NULL)
        {
            return $guard;
        }

        if ( ! Service_Nj677_Talk::enabled())
        {
            return $this->_respond(['ok' => FALSE, 'error' => Service_Nj677_Talk::missing()], 503);
        }

        $text = Service_Nj677_Talk::clean_speech((string) Arr::get($this->_body, 'text', ''));
        $max  = (int) Service_Nj677_Talk::limits('tts_chars', 200);

        if ($text === '')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Нечего озвучивать.'], 400);
        }

        if (mb_strlen($text) > $max * 3)
        {
            return $this->_respond([
                'ok'    => FALSE,
                'error' => 'Слишком длинная часть озвучки.',
            ], 413);
        }

        $voice = (string) Arr::get($this->_body, 'voice', '');
        $voice = Service_Nj665_Voice::match_voice(
            $voice,
            (array) Service_Nj665_Voice::voices(),
            Service_Nj677_Talk::default_voice()
        );

        $synth = Service_Nj665_Voice::synth_bytes([
            'text'     => $text,
            'voice'    => $voice,
            'format'   => Service_Nj677_Talk::speech_format(),
            'speed'    => Service_Nj677_Talk::speed(),
            'language' => Service_Nj677_Talk::language(),
        ]);

        if ( ! $synth['ok'] OR empty($synth['bytes']))
        {
            Service_Nj677_Talk::log_event('nj677: синтез части ответа не удался', [
                'user'  => (int) $this->_user_id(),
                'chars' => mb_strlen($text),
                'voice' => mb_substr((string) $voice, 0, 60),
                'error' => mb_substr((string) $synth['error'], 0, 300),
            ]);

            return $this->_respond([
                'ok'    => FALSE,
                'error' => $synth['error'] !== '' ? $synth['error'] : 'Синтез не удался.',
            ], 502);
        }

        $format = (string) $synth['format'];

        $this->response->headers('Content-Type', Service_Nj665_Voice::media_type($format));
        $this->response->headers('Content-Length', (string) strlen($synth['bytes']));
        $this->response->headers('X-N2J-Audio-Format', $format);
        $this->response->body($synth['bytes']);

        return $this->response;
    }

    // ------------------------------------------------------------------ защита

    /**
     * Общая проверка точки: метод, вход, CSRF.
     *
     * Возвращает готовый Response (когда дальше идти нельзя) или NULL.
     */
    protected function _guard($method)
    {
        if ($this->request->method() !== $method)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Только '.$method.'.'], 405);
        }

        if ( ! Gate::logged_in())
        {
            return $this->_respond([
                'ok'    => FALSE,
                'error' => 'auth',
                'login' => '/auth/login?return_url=/talk',
            ], 401);
        }

        $csrf = (string) Arr::get($this->_body, 'csrf',
            (string) $this->request->headers('X-CSRF-Token'));

        if ( ! Security::check($csrf))
        {
            return $this->_respond([
                'ok'    => FALSE,
                'error' => 'Сессия устарела, обновите страницу.',
            ], 403);
        }

        return NULL;
    }

    protected function _user_id()
    {
        $user = Gate::logged_in() ? Auth::instance()->get_user() : NULL;

        return $user ? (int) $user->id : 0;
    }

    protected function _respond(array $payload, $status = 200)
    {
        $this->response->status($status);
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
        $this->response->body(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->response;
    }
}
