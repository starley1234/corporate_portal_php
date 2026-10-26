<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Шаблоны и подстановка данных (nj597).
 *
 * Разделение данных и представления — главное правило центра: модуль передаёт
 * только код события и набор значений (payload), а как это будет выглядеть в
 * письме, в Telegram и в MAX — решает шаблон, который правит администратор
 * в /nj597/admin/templates. Следовательно:
 *   * шаблон не знает про PHP и не может выполнить код: только {{подстановки}};
 *   * текст из payload всегда экранируется — в HTML по полной программе,
 *     в мессенджерах вырезаются теги (там разметка своя, и чужая ломает вёрстку);
 *   * нет шаблона — не беда: берём заголовок и текст из самого события.
 *
 * Методы чистые (БД не touching), поэтому их легко проверить стендом.
 */
class Service_Nj597_Render extends Service_Nj597_Base
{
	/** Что всегда доступно в шаблоне помимо данных события. */
	const GLOBALS = ['site_url', 'site_name', 'inbox_url', 'settings_url', 'user_name', 'user_email', 'date', 'time', 'year'];

	// ---------------------------------------------------------------- рендер

	/**
	 * Подставить значения в текст шаблона.
	 *
	 * @param  string $text  шаблон с {{ключами}}
	 * @param  array  $data  значения (скаляры; массивы превращаются в строку)
	 * @param  string $mode  html — экранировать по-HTML; text — вырезать теги;
	 *                       md — экранировать служебные символы разметки
	 * @return string
	 */
	public static function render($text, array $data = [], $mode = 'text')
	{
		$text = (string) $text;

		if ($text === '')
		{
			return '';
		}

		$replace = [];

		foreach ($data as $key => $value)
		{
			$replace['{{'.$key.'}}'] = self::value($value, $mode);
		}

		if ( ! $replace)
		{
			return $text;
		}

		// strtr, а не str_replace: не пересканирует уже подставленное, поэтому
		// значение с «{{что-то}}» внутри не ломает остальные подстановки.
		return strtr($text, $replace);
	}

	/**
	 * Значение подстановки в нужной безопасности.
	 * Массивы и объекты — в компактный JSON (в уведомлении это исключение,
	 * но лучше прочитать структуру, чем потерять событие из-за «Array»).
	 */
	public static function value($value, $mode = 'text')
	{
		if ($value === NULL)
		{
			return '';
		}

		if (is_bool($value))
		{
			return $value ? 'да' : 'нет';
		}

		if (is_array($value) OR is_object($value))
		{
			$value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		$value = (string) $value;

		switch ($mode)
		{
			case 'html':
				return HTML::chars($value, TRUE);

			case 'md':
				// Экранируем только служебные символы разметки, не трогая текст.
				return strtr($value, [
					'\\' => '\\\\', '`' => '\\`', '*' => '\\*', '_' => '\\_',
					'[' => '\\[', ']' => '\\]', '(' => '\\(', ')' => '\\)',
					'#' => '\\#', '+' => '\\+', '-' => '\\-', '.' => '\\.',
					'!' => '\\!', '>' => '\\>', '|' => '\\|', '{' => '\\{', '}' => '\\}',
				]);

			default:
				// Мессенджеры: никаких чужих тегов, никаких двойных переводов.
				$value = strip_tags($value);
				$value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

				return trim((string) preg_replace("/\n{3,}/", "\n\n", $value));
		}
	}

	/**
	 * Собрать сообщение для канала: шаблон + данные → subject/body/format.
	 * Шаблона нет — берём готовые заголовок и текст из события.
	 *
	 * @param  array $event   запись реестра (alias, title, …)
	 * @param  string $channel
	 * @param  array $data    подстановки
	 * @param  array $fallback ['title' => …, 'body' => …, 'href' => …]
	 * @return array ['subject' => string, 'body' => string, 'format' => string, 'from_template' => bool]
	 */
	public static function message(array $event, $channel, array $data = [], array $fallback = [])
	{
		$data = self::with_globals($data, (int) Arr::get($fallback, 'user_id', 0));

		$template = Service_Nj597_Registry::template((string) Arr::get($event, 'alias', ''), (string) $channel);

		if ($template !== NULL)
		{
			$format = $template['format'] !== '' ? $template['format'] : 'text';
			$mode   = $format === 'html' ? 'html' : ($format === 'md' ? 'md' : 'text');

			$subject = self::render($template['subject'], $data, $mode);
			$body    = self::render($template['body'], $data, $mode);

			// Пустой результат подстановки — это не «нет шаблона», а «шаблон
			// вернул пустоту»: лучше показать заголовок события, чем молчать.
			if (trim($subject) === '')
			{
				$subject = (string) Arr::get($fallback, 'title', Arr::get($event, 'title', ''));
			}

			return [
				'subject'       => trim($subject),
				'body'          => $body,
				'format'        => $format,
				'from_template' => TRUE,
			];
		}

		$mode = $channel === 'email' ? 'html' : 'text';

		return [
			'subject'       => (string) Arr::get($fallback, 'title', Arr::get($event, 'title', '')),
			'body'          => (string) Arr::get($fallback, 'body', ''),
			'format'        => $mode === 'html' ? 'html' : 'text',
			'from_template' => FALSE,
		];
	}

	/**
	 * Добавить подстановки, которыеCentre подставляет сам: адрес сайта, имя
	 * пользователя, дата, ссылка на центр уведомлений.
	 */
	public static function with_globals(array $data, $user_id = 0)
	{
		$base = self::base_url();

		if ( ! isset($data['site_url']))
		{
			$data['site_url'] = $base;
		}

		if ( ! isset($data['inbox_url']))
		{
			$data['inbox_url'] = $base.'/nj597';
		}

		if ( ! isset($data['settings_url']))
		{
			$data['settings_url'] = $base.'/nj597?tab=settings';
		}

		$data['site_name'] = (string) Arr::get($data, 'site_name', self::_site_name());
		$data['date']      = date('d.m.Y');
		$data['time']      = date('H:i');
		$data['year']      = date('Y');

		if ($user_id > 0)
		{
			if ( ! isset($data['user_name']))
			{
				$data['user_name'] = self::_user_name($user_id);
			}

			if ( ! isset($data['user_email']))
			{
				$data['user_email'] = self::_user_email($user_id);
			}
		}

		return $data;
	}

	/** Ключи, встречающиеся в шаблоне: ['user_name', 'amount', …]. */
	public static function placeholders($text)
	{
		$found = [];

		if (preg_match_all('/\{\{\s*([a-z0-9_.-]+)\s*\}\}/i', (string) $text, $m))
		{
			$found = array_values(array_unique($m[1]));
		}

		return $found;
	}

	/** Пример данных для предпросмотра шаблона (админка). */
	public static function sample_data(array $event = [])
	{
		$keys = self::_csv(Arr::get($event, 'placeholders', ''));

		if ( ! $keys)
		{
			$keys = ['title', 'code', 'amount'];
		}

		$data = self::with_globals([], 0);

		foreach ($keys as $key)
		{
			$data[$key] = '['.$key.']';
		}

		return $data;
	}

	// -------------------------------------------------------------- приведение

	/** Обычный текст → аккуратный HTML для письма (переводы строк сохраняем). */
	public static function text_to_html($text)
	{
		$text = (string) $text;

		if ($text === '')
		{
			return '';
		}

		$html = HTML::chars($text, TRUE);

		return nl2br($html);
	}

	/** HTML → плоский текст для мессенджеров (письмо в Telegram не вставишь). */
	public static function html_to_text($html)
	{
		$text = (string) $html;

		// Конец абзаца и <br> — в перевод строки, списки — в дефисы.
		$text = (string) preg_replace('~<\s*(br|/p|/div|/li|/h[1-6])\s*/?\s*>~i', "\n", $text);
		$text = (string) preg_replace('~<li[^>]*>~i', "\n• ", $text);
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
		$text = (string) preg_replace('~[ \t]+~', ' ', $text);
		$text = (string) preg_replace("~\n{3,}~", "\n\n", $text);

		return trim($text);
	}

	/** Обрезать длинное сообщение под лимит канала (Telegram — 4096 символов). */
	public static function clip($text, $limit = 4000)
	{
		$text = (string) $text;

		if (mb_strlen($text) <= $limit)
		{
			return $text;
		}

		return mb_substr($text, 0, max(0, $limit - 1)).'…';
	}

	/**
	 * Тема уведомления по правилу «одна мысль» (владелец, 16.10.2026).
	 *
	 * Тема — не отчёт и не служебная строка: убираем «Уведомление:», ломаем
	 * переводы строк, сжимаем пробелы и режем до 60 знаков (столько видно
	 * в списке писем и в пуш-превью), не оставляя обрубков вида «Обзор гото…».
	 *
	 * @param  string $text
	 * @param  int    $limit сколько знаков показывать
	 * @return string
	 */
	public static function subject_line($text, $limit = 60)
	{
		$text = (string) $text;

		// Служебные приставки: и «Уведомление:», и «N2J.ru:» — письмо и так
		// подписано отправителем, повторять имя площадки в теме незачем.
		$text = preg_replace('~^\s*(уведомление|notification|событие|n2j\.?ru)\s*[:—-]\s*~iu', '', $text);
		$text = preg_replace('~\s+~u', ' ', $text);

		return self::clip(trim((string) $text), max(10, (int) $limit));
	}

	// ------------------------------------------------------------------ помощники

	protected static function _site_name()
	{
		try
		{
			return (string) Kohana::$config->load('site_config.site_name');
		}
		catch (Throwable $e)
		{
			return 'N2J.ru';
		}
	}

	protected static function _user_name($user_id)
	{
		try
		{
			$user = ORM::factory('User', (int) $user_id);

			if ( ! $user->loaded())
			{
				return '';
			}

			$profile = $user->profile;

			if ($profile AND $profile->loaded() AND trim((string) $profile->full_name) !== '')
			{
				return trim((string) $profile->full_name);
			}

			return (string) $user->username;
		}
		catch (Throwable $e)
		{
			return '';
		}
	}

	protected static function _user_email($user_id)
	{
		try
		{
			$user = ORM::factory('User', (int) $user_id);

			return $user->loaded() ? (string) $user->email : '';
		}
		catch (Throwable $e)
		{
			return '';
		}
	}
}
