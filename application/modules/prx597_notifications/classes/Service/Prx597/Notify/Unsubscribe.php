<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Отписка prx597: подпись ссылки, проверка токена и запись выбора.
 *
 * Трейт Service_Prx597_Notify; ссылка из письма сама себе пропуск — HMAC без
 * хранения состояния. Ожидает от класса _db(), _bool(), _log_unsub() и
 * таблицы prx_597_*; побочный эффект — запись в prefs и журнал отписок.
 */
trait Service_Prx597_Notify_Unsubscribe
{
    // ------------------------------------------------------------- отписка

    /**
     * Секрет подписи ссылок «больше не присылать».
     *
     * Ссылка живёт в чужом почтовом ящике, поэтому она сама себе пропуск:
     * подпись считается HMAC, ничего не храним. Ключ — отдельная переменная
     * окружения, а если её нет, годится уже имеющийся серверный секрет;
     * в крайнем случае — адрес площадки (тогда подпись хотя бы не пустая).
     */
    public static function unsub_secret()
    {
        foreach (['PRX597_NOTIFICATIONS_UNSUB_SECRET', 'WORKER_API_TOKEN', 'COOKIE_SALT'] as $key)
        {
            $value = trim((string) getenv($key));

            if ($value !== '')
            {
                return 'prx597|'.$value;
            }
        }

        throw new RuntimeException('Не настроен секрет подписи уведомлений');
    }

    /**
     * Подпись ссылки отказа.
     *
     * @param  int    $user_id
     * @param  string $channel канал, из которого пришло письмо
     * @param  string $alias   код события
     * @param  string $scope   'channel' — не присылать этим каналом; 'event' — не присылать это событие
     * @return string
     */
    public static function unsub_token($user_id, $channel, $alias, $scope)
    {
        $payload = implode('|', ['v1', (int) $user_id, self::_cut($channel, 20), self::_cut($alias, 120), $scope]);

        return substr(hash_hmac('sha256', $payload, self::unsub_secret()), 0, 40);
    }

    /** Проверить подпись ссылки отказа (сравнение постоянного времени). */
    public static function unsub_verify($user_id, $channel, $alias, $scope, $token)
    {
        if ( ! in_array((string) $scope, ['channel', 'event'], TRUE))
        {
            return FALSE;
        }

        $want = self::unsub_token($user_id, $channel, $alias, $scope);

        return $token !== '' AND hash_equals($want, (string) $token);
    }

    /**
     * Ссылка отказа для подвала письма и заголовка List-Unsubscribe.
     *
     * @param  int    $user_id
     * @param  string $channel
     * @param  string $alias
     * @param  string $scope 'channel' | 'event'
     * @return string
     */
    public static function unsub_url($user_id, $channel, $alias, $scope = 'event')
    {
        $url = self::_base_url().'/prx597/unsub'
            .'?u='.(int) $user_id
            .'&c='.urlencode(self::_cut($channel, 20))
            .'&e='.urlencode(self::_cut($alias, 120))
            .'&s='.urlencode((string) $scope)
            .'&t='.self::unsub_token($user_id, $channel, $alias, $scope);

        return $url;
    }

    /**
     * Выполнить отказ по подписанной ссылке.
     *
     * Ничего не удаляем и не выключаем «всё»: либо канал, либо одно событие.
     * Колокольчик на сайте остаётся (владелец, 16.10.2026) — отказ касается
     * только того, что уходит наружу.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function unsub_apply($user_id, $channel, $alias, $scope)
    {
        $user_id = (int) $user_id;
        $channel = self::_cut(trim((string) $channel), 20);
        $alias   = self::_cut(trim((string) $alias), 120);
        $scope   = (string) $scope;

        if ($user_id <= 0)
        {
            return ['ok' => FALSE, 'message' => 'Ссылка не подходит: не указан получатель.'];
        }

        if ($scope === 'channel')
        {
            if ($channel === '' OR $channel === 'in_app')
            {
                return ['ok' => FALSE, 'message' => 'Колокольчик на сайте выключается только в настройках уведомлений.'];
            }

            $gate = $channel === 'email' ? 'email_enabled' : $channel.'_enabled';

            try
            {
                self::save_prefs($user_id, [$gate => FALSE]);
            }
            catch (Throwable $e)
            {
                return ['ok' => FALSE, 'message' => 'Не удалось сохранить отказ, попробуйте в настройках уведомлений.'];
            }

            self::_log_unsub($user_id, $channel, 'все события', 'channel');

            return ['ok' => TRUE, 'message' => 'Готово: '.Service_Prx597_Registry::channel_label($channel).' больше не побеспокоит. Колокольчик на сайте остаётся.'];
        }

        if ($alias === '')
        {
            return ['ok' => FALSE, 'message' => 'Ссылка не подходит: не указано событие.'];
        }

        $event = Service_Prx597_Registry::event($alias);

        if ($event === NULL)
        {
            return ['ok' => FALSE, 'message' => 'Такого события уже нет — отключать нечего.'];
        }

        if ( ! empty($event['mandatory']))
        {
            return ['ok' => FALSE, 'message' => 'Это событие обязательное ('.self::_esc((string) $event['title']).') — его нельзя отключить. Оно важное для безопасности аккаунта.'];
        }

        // Отключаем только те внешние каналы, которые событие вообще
        // объявляет: отказ должен жить дольше сегодняшней настройки SMTP,
        // поэтому не смотрим на то, настроен ли транспорт прямо сейчас.
        $declared = (array) Arr::get($event, 'channels', []);

        foreach (['email', 'telegram', 'max'] as $one)
        {
            if (in_array($one, $declared, TRUE))
            {
                Service_Prx597_Registry::save_sub($user_id, $alias, $one, FALSE);
            }
        }

        self::_log_unsub($user_id, $channel, (string) $event['title'], 'event');

        return ['ok' => TRUE, 'message' => 'Готово: «'.self::_esc((string) $event['title']).'» больше не приходит письмом или в мессенджер. В колокольчике на сайте событие остаётся.'];
    }

    /** Отказ виден в журнале: администратор понимает, почему «тихо». */
    protected static function _log_unsub($user_id, $channel, $what, $scope)
    {
        try
        {
            Kohana::$log->add(Log::INFO, 'prx597: отказ от уведомлений (ссылка из письма): пользователь :u, канал :c, :w, вид :s',
                [':u' => (int) $user_id, ':c' => (string) $channel, ':w' => (string) $what, ':s' => (string) $scope]);
        }
        catch (Throwable $e)
        {
            // журнал не должен ломать отказ
        }
    }

    /**
     * Заголовки письма для почтовых правил (RFC 8058): одной ссылки в подвале
     * мало — почтовые клиенты умеют показывать свою кнопку «отписаться»,
     * и без этих заголовков такие письма чаще уезжают в спам.
     */
    public static function unsub_headers($url)
    {
        $url = trim((string) $url);

        // Перевода строки в заголовке быть не может: это уже не ссылка,
        // а попытка дописать письму свои заголовки.
        if ($url === '' OR strpos($url, "\n") !== FALSE OR strpos($url, "\r") !== FALSE)
        {
            return [];
        }

        return [
            'List-Unsubscribe'      => '<'.$url.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }
}
