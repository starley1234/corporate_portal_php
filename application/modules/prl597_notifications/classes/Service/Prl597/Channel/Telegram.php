<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Канал «Telegram» (prl597).
 *
 * Bot API: POST https://api.telegram.org/bot<TOKEN>/sendMessage.
 * Токен — только из окружения (TELEGRAM_BOT_TOKEN), в базу не пишем.
 *
 * Получатель — chat_id из привязки (prl_597_channels). Привязывает пользователь
 * сам: в центре уведомлений жмёт «привязать», получает ссылку на бота и код,
 * пишет боту /start <код>; бот зовёт наш вебхук, и связка подтверждается
 * (Service_Prl597_Bind).
 *
 * Формат: HTML (parse_mode). Всё, что пришло от модуля в виде HTML,
 * превращается в плоский текст с сохранением ссылок: Telegram понимает
 * <a>, <b>, <i>, <code>, <pre> и всё остальное вырежет с ошибкой 400.
 */
class Service_Prl597_Channel_Telegram extends Service_Prl597_Channel
{
	/** Канал работает, если задан токен бота. */
	public static function enabled()
	{
		if ( ! parent::enabled())
		{
			return FALSE;
		}

		return self::env('TELEGRAM_BOT_TOKEN') !== '';
	}

	/**
	 * Отправить сообщение в Telegram.
	 *
	 * @param  array $message
	 * @return array ['ok' => TRUE, 'id' => message_id] | ['error' => string]
	 */
	public static function send(array $message)
	{
		$token = self::env('TELEGRAM_BOT_TOKEN');

		if ($token === '')
		{
			return ['error' => 'TELEGRAM_BOT_TOKEN не задан в окружении'];
		}

		$chat = trim((string) Arr::get($message, 'destination', ''));

		if ($chat === '')
		{
			return ['error' => 'Пользователь не привязал Telegram'];
		}

		$text = self::_text($message);

		if ($text === '')
		{
			return ['error' => 'Пустое сообщение — отправлять нечего'];
		}

		$api  = rtrim((string) self::cfg('telegram.api_url', 'https://api.telegram.org/bot'), '/');
		$url  = $api.$token.'/sendMessage';

		$res = self::_http($url, 'POST', [
			'chat_id'                  => $chat,
			'text'                     => $text,
			'parse_mode'               => (string) self::cfg('telegram.parse_mode', 'HTML'),
			'disable_web_page_preview' => TRUE,
		], [], (int) self::cfg('telegram.timeout', 10), FALSE);

		if ($res['error'] !== '')
		{
			return ['error' => $res['error']];
		}

		$data = self::_json($res['body']);

		if ($data === NULL)
		{
			return ['error' => 'Telegram вернул не JSON (HTTP '.$res['code'].'): '.mb_substr($res['body'], 0, 200)];
		}

		if (empty($data['ok']))
		{
			$description = (string) Arr::get($data, 'description', 'без описания');

			// Код 403 — пользователь заблокировал бота: повторять бессмысленно,
			// помечаем это отдельным текстом, чтобы движок снял привязку.
			if ((int) Arr::get($data, 'error_code', 0) === 403)
			{
				return ['error' => 'Бот заблокирован пользователем: '.$description, 'blocked' => TRUE];
			}

			return ['error' => 'Telegram: '.$description];
		}

		return ['ok' => TRUE, 'id' => (string) Arr::get(Arr::get($data, 'result', []), 'message_id', '')];
	}

	/** Тест: короткое сообщение администратору. */
	public static function test($destination, $text = NULL)
	{
		$text = $text !== NULL ? (string) $text
			: "Проверка канала Telegram.\n\nЕсли сообщение дошло — бот привязан верно.";

		return self::send([
			'user_id'     => 0,
			'destination' => (string) $destination,
			'subject'     => 'Проверка Telegram',
			'body'        => $text,
			'format'      => 'text',
			'href'        => '',
			'event_alias' => 'test',
			'priority'    => 1,
		]);
	}

	/** Проверить бота: кто мы такой (для админки). */
	public static function whoami()
	{
		$token = self::env('TELEGRAM_BOT_TOKEN');

		if ($token === '')
		{
			return ['error' => 'TELEGRAM_BOT_TOKEN не задан'];
		}

		$api = rtrim((string) self::cfg('telegram.api_url', 'https://api.telegram.org/bot'), '/');
		$res = self::_http($api.$token.'/getMe', 'GET', NULL, [], 10, FALSE);

		if ($res['error'] !== '')
		{
			return ['error' => $res['error']];
		}

		$data = self::_json($res['body']);

		if ($data === NULL OR empty($data['ok']))
		{
			return ['error' => 'Telegram не подтвердил бота: '.mb_substr($res['body'], 0, 200)];
		}

		$me = (array) Arr::get($data, 'result', []);

		return ['ok' => TRUE, 'username' => (string) Arr::get($me, 'username', ''), 'name' => (string) Arr::get($me, 'first_name', '')];
	}

	/**
	 * Текст сообщения: тема жирным, затем тело, ссылка в конце.
	 * HTML из шаблона превращаем в поддерживаемый Telegram минимум.
	 */
	protected static function _text(array $message)
	{
		$subject = trim((string) Arr::get($message, 'subject', ''));
		$body    = trim((string) Arr::get($message, 'body', ''));

		if ((string) Arr::get($message, 'format', 'text') === 'html')
		{
			$body = self::_html_to_tg($body);
		}
		else
		{
			$body = HTML::chars($body, TRUE);
		}

		$out = '';

		if ($subject !== '')
		{
			$out .= '<b>'.HTML::chars($subject, TRUE).'</b>';

			if ($body !== '')
			{
				$out .= "\n\n";
			}
		}

		$out .= $body;

		$href = Service_Prl597_Notify::safe_href((string) Arr::get($message, 'href', ''));

		if ($href !== '/prl597' AND $href !== '')
		{
			$url = self::base_url().$href;
			$out .= "\n\n".'<a href="'.HTML::chars($url, TRUE).'">Открыть</a>';
		}

		return Service_Prl597_Render::clip(trim($out), 4000);
	}

	/** HTML → подмножество разметки Telegram: b, i, code, pre, a. */
	protected static function _html_to_tg($html)
	{
		$text = (string) $html;

		$text = (string) preg_replace('~<\s*(/?)\s*(strong|b)\s*/?\s*>~i', '[$1b]', $text);
		$text = (string) preg_replace('~<\s*(/?)\s*(em|i)\s*/?\s*>~i', '[$1i]', $text);
		$text = (string) preg_replace('~<\s*(/?)\s*(code)\s*/?\s*>~i', '[$1code]', $text);
		$text = (string) preg_replace('~<\s*(/?)\s*(pre)\s*/?\s*>~i', '[$1pre]', $text);
		$text = (string) preg_replace('~<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', '<a href="$1">$2</a>', $text);
		$text = (string) preg_replace('~<\s*br\s*/?\s*>~i', "\n", $text);
		$text = (string) preg_replace('~<\s*/(p|div|tr|li)\s*>\s*<\s*(p|div|tr|li)\s*[^>]*>~i', "\n", $text);
		$text = (string) preg_replace('~<\s*li[^>]*>~i', "• ", $text);

		// Всё, что осталось от тегов, вырезаем: Telegram не прощает чужой разметки.
		$text = strip_tags($text);

		// Плейсхолдеры обратно в теги (после strip_tags они уцелели как текст).
		$text = strtr($text, [
			'[/b]' => '</b>', '[/i]' => '</i>', '[/code]' => '</code>', '[/pre]' => '</pre>',
		]);
		$text = (string) preg_replace('~\[(b|i|code|pre)\]~i', '<$1>', $text);

		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
		$text = (string) preg_replace("~\n{3,}~", "\n\n", $text);

		return trim($text);
	}
}
