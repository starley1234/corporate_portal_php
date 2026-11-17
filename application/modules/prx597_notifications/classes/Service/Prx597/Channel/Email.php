<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Канал «Электронная почта» (prx597).
 *
 * Отправка — symfony/mailer (EMAIL_DSN), тем же путём, каким модуль шлёт
 * сводки: Service_Prx597_Notify::send_email(). Канал добавляет вокруг этого
 * всё остальное: откуда взять адрес, как сверстать письмо и что считать
 * успехом.
 *
 * Адрес получателя: привязка пользователя в prx_597_channels (если он указал
 * отдельный ящик), иначе — email учётной записи.
 */
class Service_Prx597_Channel_Email extends Service_Prx597_Channel
{
	/** Почтовый ящик пользователя: привязка или email учётной записи. */
	public static function destination($user_id)
	{
		$bound = parent::destination($user_id);

		if ($bound !== '')
		{
			return $bound;
		}

		try
		{
			$user = ORM::factory('User', (int) $user_id);

			return $user->loaded() ? trim((string) $user->email) : '';
		}
		catch (Throwable $e)
		{
			return '';
		}
	}

	/** Почта включена при реальном DSN и отправителе, если не выключена флагом. */
	public static function enabled()
	{
		if ( ! parent::enabled())
		{
			return FALSE;
		}

		if (trim((string) getenv('PRX597_EMAIL_ENABLED')) === '0')
		{
			return FALSE;
		}

		$dsn = trim((string) getenv('EMAIL_DSN'));
		return $dsn !== '' AND stripos($dsn, 'null:') !== 0
			AND trim((string) getenv('EMAIL_FROM')) !== '';
	}

	/**
	 * Отправить письмо.
	 *
	 * @param  array $message
	 * @return array ['ok' => TRUE] | ['error' => string]
	 */
	public static function send(array $message)
	{
		$to = trim((string) Arr::get($message, 'destination', ''));

		if ($to === '' OR strpos($to, '@') === FALSE)
		{
			return ['error' => 'Некорректный адрес получателя'];
		}

		$subject = self::_subject($message);
		$body    = (string) Arr::get($message, 'body', '');
		$is_html = (string) Arr::get($message, 'format', 'text') === 'html';

		if ( ! $is_html)
		{
			$body = Service_Prx597_Render::text_to_html($body);
		}
		else
		{
			$body = self::_complete_html($body);
		}

		$user_id = (int) Arr::get($message, 'user_id', 0);
		$alias   = (string) Arr::get($message, 'event_alias', '');

		$html  = Service_Prx597_Notify::email_shell($subject, $body, [
			'user_id'     => $user_id,
			'channel'     => 'email',
			'event_alias' => $alias,
		]);
		$plain = Service_Prx597_Render::html_to_text($html);

		// Заголовок «отписаться в один клик» (RFC 8058) — по каналу: одно
		// нажатие в почтовом клиенте, и письма прекращаются.
		$headers = $user_id > 0
			? Service_Prx597_Notify::unsub_headers(Service_Prx597_Notify::unsub_url($user_id, 'email', $alias, 'channel'))
			: [];

		$res = Service_Prx597_Notify::send_email($to, $subject, $html, TRUE, $plain, $headers);

		if ( ! empty($res['ok']))
		{
			return ['ok' => TRUE, 'id' => ''];
		}

		return ['error' => (string) Arr::get($res, 'error', 'неизвестная ошибка почты')];
	}

	/** Тестовое письмо: подставляем понятный текст. */
	public static function test($destination, $text = NULL)
	{
		$text = $text !== NULL ? (string) $text
			: "Проверка почтового канала центра уведомлений.\n\nЕсли письмо дошло — EMAIL_DSN настроен верно.";

		return self::send([
			'user_id'     => 0,
			'destination' => (string) $destination,
			'subject'     => 'PRX.ru: проверка почты',
			'body'        => $text,
			'format'      => 'text',
			'href'        => '/prx597',
			'event_alias' => 'test',
			'priority'    => 1,
		]);
	}

	/**
	 * Достроить HTML-тело: если модуль прислал кусок без обёртки, письмо всё
	 * равно должно выглядеть целым. Признак — отсутствие блочных тегов.
	 */
	protected static function _complete_html($html)
	{
		if (trim((string) $html) === '')
		{
			return '<p style="margin:0 0 12px;">(пустое сообщение)</p>';
		}

		if (preg_match('~<\s*(p|div|table|ul|ol|h[1-6]|br)\b~i', (string) $html))
		{
			return (string) $html;
		}

		return '<p style="margin:0 0 12px;">'.nl2br(HTML::chars((string) $html, TRUE)).'</p>';
	}
}
