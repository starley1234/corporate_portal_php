<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Почтовый транспорт prx597: единственная точка, откуда проект шлёт письма.
 *
 * Исторически им был `Service_Prx217_Email::send_direct()` — но это была
 * обёртка в две строки над `Service_Prx597_Notify::send_email()` внутри
 * модуля, который удалён (prx217 снесён 09.10.2026 как дубль prx597).
 * Чтобы не тащить «единый транспорт» из мёртвого модуля, транспорт переехал
 * сюда — туда, где он фактически и жил.
 *
 * Кто зовёт: prx612 (серии касаний), prx613 (дайджест), prx661 (аи radar),
 * канал Email этого модуля и тестовая кнопка в управлении.
 *
 * Отличие от `Service_Prx597_Channel_Email`: канал работает ТОЛЬКО через
 * очередь и лимиты центра уведомлений, а `Mail::send()` —Immediate-отправка
 * одного письма без очереди, подписок и антиспама. Для писем «по кнопке»
 * и писем сервисов, у которых нет своего события в реестре.
 */
class Service_Prx597_Mail
{
	/**
	 * Отправить одно письмо немедленно, в обход очереди центра уведомлений.
	 *
	 * @param  string      $to        адрес получателя
	 * @param  string      $subject   тема
	 * @param  string      $body      HTML или обычный текст
	 * @param  bool        $is_html   TRUE — в $body HTML (добавим text/plain)
	 * @param  string|null $alt_text  готовая текстовая альтернатива
	 * @return array       ['ok' => TRUE] или ['error' => 'причина']
	 */
	public static function send($to, $subject, $body, $is_html = FALSE, $alt_text = NULL)
	{
		$to = trim((string) $to);

		if ($to === '' OR ! Valid::email($to))
		{
			return ['error' => 'Некорректный адрес получателя: '.($to === '' ? '(пусто)' : $to)];
		}

		if (trim((string) $subject) === '')
		{
			return ['error' => 'Не указана тема письма'];
		}

		if ( ! class_exists('Service_Prx597_Notify'))
		{
			return ['error' => 'Модуль уведомлений prx597 не подключён — почта недоступна'];
		}

		// Текстовая альтернатива: её просят не все почтовики, но письмо без
		// неё чаще уезжает в спам, а у получателей с plaintext-клиентом
		// вместо письма была бы простыня тегов.
		if ($is_html AND $alt_text === NULL)
		{
			$alt_text = Service_Prx597_Render::html_to_text((string) $body);
		}

		$args = [$to, $subject, (string) $body, (bool) $is_html];

		if ($is_html)
		{
			$args[] = (string) $alt_text;
		}

		$result = call_user_func_array(['Service_Prx597_Notify', 'send_email'], $args);

		if (is_array($result))
		{
			return $result;
		}

		// send_email всегда отдаёт массив; не массив — значит транспорт
		// повёл себя неожиданно, но письмо, скорее всего, ушло.
		return ['ok' => TRUE];
	}

	/**
	 * Отправить письмо и получить простой ответ «да/нет» — для сервисов,
	 * у которых нет своего события в реестре (по кнопке, из воркера).
	 *
	 * @param  string $to
	 * @param  string $subject
	 * @param  string $body
	 * @return bool
	 */
	public static function send_direct($to, $subject, $body)
	{
		Audit::log('prx597_mail_direct', ['to' => $to]);

		$result = self::send($to, $subject, $body, TRUE);

		if (isset($result['error']))
		{
			Kohana::$log->add(Log::ERROR, 'prx597: письмо не ушло: :e', [':e' => $result['error']]);

			return FALSE;
		}

		return TRUE;
	}
}
