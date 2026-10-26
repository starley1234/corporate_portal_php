<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Канал «На сайте» (nj597) — внутреннее уведомление, «колокольчик».
 *
 * Особенность канала: доставка мгновенная, потому что «доставить» здесь
 * значит «показать строку в ленте». emit() создаёт строку сам (пользователь
 * должен увидеть событие сразу, а не после следующего прохода движка), а
 * send() используется когда сообщение пришлось отложить — например, его
 * склеили в дайджест или вернули из очереди после сбоя.
 *
 * Счётчик непрочитанных держит триггер nj_597_notifications_unread_trg —
 * иначе колокольчик считал бы COUNT(*) на каждом запросе страницы.
 */
class Service_Nj597_Channel_Inapp extends Service_Nj597_Channel
{
	/** Внутренний канал включён всегда — это лицо центра уведомлений. */
	public static function enabled()
	{
		return TRUE;
	}

	/** Адрес доставки — сам пользователь (строка в его ленте). */
	public static function destination($user_id)
	{
		return 'user:'.((int) $user_id);
	}

	/**
	 * «Отправить» — значит показать строку в ленте.
	 *
	 * @param  array $message
	 * @return array ['ok' => TRUE, 'id' => id строки] | ['error' => string]
	 */
	public static function send(array $message)
	{
		$user_id = (int) Arr::get($message, 'user_id', 0);

		if ($user_id <= 0)
		{
			return ['error' => 'Не указан получатель внутреннего уведомления'];
		}

		$id = Service_Nj597_Notify::inbox_add($user_id, [
			'event_alias' => (string) Arr::get($message, 'event_alias', 'system'),
			'title'       => self::_subject($message),
			'body'        => (string) Arr::get($message, 'body', ''),
			'href'        => (string) Arr::get($message, 'href', ''),
			'priority'    => (int) Arr::get($message, 'priority', 5),
			'data'        => (array) Arr::get($message, 'data', []),
		]);

		if ($id <= 0)
		{
			return ['error' => 'Не удалось создать внутреннее уведомление'];
		}

		return ['ok' => TRUE, 'id' => (string) $id];
	}

	/** Тест: строка в своей ленте. */
	public static function test($destination, $text = NULL)
	{
		$user_id = (int) $destination;

		if ($user_id <= 0)
		{
			return ['error' => 'Для внутреннего канала нужен id пользователя'];
		}

		return self::send([
			'user_id'     => $user_id,
			'destination' => (string) $user_id,
			'subject'     => 'Проверка внутренних уведомлений',
			'body'        => $text !== NULL ? (string) $text : 'Канал «на сайте» работает: строка попала в вашу ленту.',
			'format'      => 'text',
			'href'        => '/nj597',
			'event_alias' => 'test',
			'priority'    => 5,
		]);
	}
}
