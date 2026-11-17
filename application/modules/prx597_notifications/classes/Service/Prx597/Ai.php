<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx597: ИИ-помощник по шаблонам уведомлений.
 *
 * Владелец (15.10.2026): «шаблоны сделай более удобными… добавь составление
 * шаблона с помощью ИИ на основании имеющегося сообщения».
 *
 * Задача сервиса — превратить уже написанный человеком текст (рассылку, письмо,
 * объявление) в готовый шаблон «событие × канал»: с правильной темой письма,
 * живым текстом, сохранёнными подстановками {{...}} и без разметки там, где
 * канал её не понимает.
 *
 * Модель берём из платформенного LLM (config/llm.php) — отдельного ключа модуль
 * не требует. Если ИИ не настроен или ответил мусором, сервис честно говорит
 * об этом: шаблон остаётся в редакторе, ничего не перезаписывается молча.
 *
 * @package    prx
 * @category   Prx597
 */
class Service_Prx597_Ai extends Service_Prx597_Base
{
	/** Потолки, чтобы модель не «разговорилась» в письме. */
	const SUBJECT_MAX = 120;
	const BODY_MAX    = 2000;

	/** Каналы, которые понимают HTML. */
	protected static $_html_channels = ['email'];

	/** Тон обращения → подсказка модели. */
	public static function tones()
	{
		return [
			'friendly' => ['Дружелюбно', 'тепло и по-человечески, на «вы», без канцелярита'],
			'neutral'  => ['Нейтрально', 'ровно и по делу, без эмоций и восклицаний'],
			'short'    => ['Коротко', 'максимально сжато: одна мысль и одна ссылка'],
			'official' => ['Официально', 'строго и вежливо, как сервисное уведомление'],
			'urgent'   => ['Срочно', 'подчёркнуто важно: что случилось и что сделать сейчас'],
		];
	}

	/**
	 * Доступен ли ИИ-помощник на этом сайте.
	 *
	 * @param string $why причина отказа для интерфейса
	 * @return bool
	 */
	public static function ready(& $why = NULL)
	{
		$why = '';

		if (self::setting('ai_template_enabled', '1') !== '1')
		{
			$why = 'ИИ-помощник выключен в настройках модуля';

			return FALSE;
		}

		try
		{
			$config = Kohana::$config->load('llm');
			$model  = (string) $config->get('default_model');

			if ($model === '')
			{
				$why = 'В config/llm.php не задана модель по умолчанию';

				return FALSE;
			}

			$provider  = (strpos($model, '::') === FALSE) ? $model : strstr($model, '::', TRUE);
			$providers = (array) $config->get('providers', []);
			$cfg       = isset($providers[$provider]) ? (array) $providers[$provider] : [];

			if (empty($cfg['base_url']))
			{
				$why = 'У провайдера «'.$provider.'» не задан адрес (TOOLFORGE_API_URL)';

				return FALSE;
			}
		}
		catch (Throwable $e)
		{
			$why = 'LLM недоступен: '.$e->getMessage();

			return FALSE;
		}

		return TRUE;
	}

	/** Модель для подсказки интерфейсу: что именно сочиняет текст. */
	public static function model_label()
	{
		try
		{
			return (string) Kohana::$config->load('llm')->get('default_model');
		}
		catch (Throwable $e)
		{
			return '';
		}
	}

	/**
	 * Собрать шаблон из имеющегося сообщения.
	 *
	 * @param string $alias     событие реестра (prx597.*)
	 * @param string $channel   канал доставки
	 * @param string $source    имеющееся сообщение — основа для переписывания
	 * @param string $tone      тон из tones()
	 * @param string $channel_note свободная подсказка «о чём уведомляем»
	 * @param int    $user_id   кто просит (для журнала)
	 * @return array ['ok','error','subject','body','format','notes','ms','model','tokens']
	 */
	public static function compose($alias, $channel, $source, $tone = 'friendly', $channel_note = '', $user_id = 0)
	{
		$out = [
			// Адрес карточки, в которую интерфейс подставит результат.
			'event_alias' => (string) $alias,
			'channel'     => (string) $channel,
			'ok'      => FALSE,
			'error'   => '',
			'subject' => '',
			'body'    => '',
			'format'  => in_array($channel, self::$_html_channels, TRUE) ? 'html' : 'text',
			'notes'   => '',
			'ms'      => 0,
			'model'   => self::model_label(),
		];

		$why = '';

		if ( ! self::ready($why))
		{
			$out['error'] = $why;

			return $out;
		}

		$event = Service_Prx597_Registry::event($alias);

		if ($event === NULL)
		{
			$out['error'] = 'Событие «'.$alias.'» не найдено в реестре';

			return $out;
		}

		$source = trim((string) $source);

		if (mb_strlen($source) < 10)
		{
			$out['error'] = 'Вставьте имеющееся сообщение — хотя бы пару строк, из которых собрать шаблон';

			return $out;
		}

		$source = mb_substr($source, 0, 4000);

		$labels  = self::channel_labels();
		$label   = isset($labels[$channel]) ? $labels[$channel] : Service_Prx597_Registry::channel_label($channel);
		$tones   = self::tones();
		$tone_hint = isset($tones[$tone]) ? $tones[$tone][1] : $tones['friendly'][1];
		$sample  = Service_Prx597_Render::sample_data($event);
		$keys    = self::_csv(Arr::get($event, 'placeholders', ''));

		// Подстановки даём модели с готовыми примерами: так она ставит {{title}},
		// а не придумывает «{{название_задачи}}».
		$vars = [];

		foreach ($keys as $key)
		{
			$vars[] = '{{'.$key.'}} = '.Arr::get($sample, $key, '');
		}

		// Имена подстановок — ровно те, что подставляет Render::with_globals():
		// точки в {{user.name}} не заменяются никогда.
		foreach (self::_globals() as $key => $sample_value)
		{
			$vars[] = '{{'.$key.'}} = '.$sample_value;
		}

		$html_ok = in_array($channel, self::$_html_channels, TRUE);

		$system = "Ты — редактор уведомлений корпоративного портала PRX. "
			."Ты превращаешь уже написанное человеком сообщение в готовый шаблон уведомления.\n\n"
			."Правила:\n"
			."1. Пиши по-русски, обращение на «вы».\n"
			."2. Сохраняй смысл и факты исходного сообщения — ничего не выдумывай.\n"
			."3. Используй ТОЛЬКО такие подстановки (они подставятся автоматически): ".implode('; ', $vars).".\n"
			."4. Подстановки пиши ровно так: {{title}}, {{user.name}}. Никаких других.\n"
			.($html_ok
				? "5. Канал — письмо: допустимы простые абзацы через \\n, ссылки полным адресом, без <html>, <head>, стилей и картинок.\n"
				: "5. Канал не понимает HTML: только обычный текст и переносы строк, без тегов и Markdown.\n")
			."6. Без вступлений вроде «Уважаемый пользователь!», без подписи в конце — сервис подставляет её сам.\n"
			."7. Тема письма — до ".self::SUBJECT_MAX." символов, текст — до ".self::BODY_MAX." символов, короче лучше.\n"
			."8. Тон: ".$tone_hint.".\n\n"
			."Ответ верни строго JSON без пояснений и без разметки:\n"
			."{\"subject\": \"тема\", \"body\": \"текст\", \"notes\": \"одна фраза, что учтено\"}\n"
			."Для каналов без темы (колокольчик, Telegram, MAX) поле «subject» оставь пустым.";

		$user = "Событие: ".Arr::get($event, 'title', '')." (".$alias.")\n"
			."Категория: ".Arr::get($event, 'category', 'system')."\n"
			."Канал: ".$label.($html_ok ? ' (понимает простой HTML)' : ' (только обычный текст)')."\n"
			."Место подстановок: ".implode(', ', $keys ?: ['title'])."\n";

		if (trim($channel_note) !== '')
		{
			$user .= "О чём уведомляем: ".mb_substr(trim($channel_note), 0, 400)."\n";
		}

		$user .= "\nИмеющееся сообщение:\n---\n".$source."\n---\n\nСобери из него шаблон уведомления.";

		$options = [
			'max_tokens'    => 900,
			'temperature'   => 0.4,
		];

		$model = (string) self::setting('ai_template_model', '');

		if ($model !== '')
		{
			$options['model'] = $model;
			$out['model'] = $model;
		}

		$start = microtime(TRUE);

		try
		{
			$response = Llm::chat([
				['role' => 'system', 'content' => $system],
				['role' => 'user',   'content' => $user],
			], $options);

			$answer = isset($response['choices'][0]['message']['content'])
				? (string) $response['choices'][0]['message']['content']
				: '';
		}
		catch (Throwable $e)
		{
			$answer = '';
			$out['error'] = 'ИИ не ответил: '.mb_substr($e->getMessage(), 0, 200);
		}

		$out['ms'] = (int) round((microtime(TRUE) - $start) * 1000);

		if (trim($answer) === '')
		{
			if ($out['error'] === '')
			{
				$out['error'] = 'Модель вернула пустой ответ — попробуйте ещё раз';
			}

			return $out;
		}

		$parsed = self::parse_answer($answer);
		$body   = trim((string) Arr::get($parsed, 'body', ''));

		if ( ! $parsed OR mb_strlen($body) < 10)
		{
			// Модель ответила прозой вместо JSON: в шаблон такое подставлять нельзя
			// (получится письмо с извинениями модели). Показываем ответ как есть,
			// чтобы администратор увидел, что именно пришло.
			$out['error'] = 'Модель ответила не в формате шаблона — попробуйте ещё раз';
			$out['raw']   = self::cut_text(self::strip_fences($answer), 300);

			return $out;
		}

		$out['ok']      = TRUE;
		$out['subject'] = self::cut_text(trim((string) Arr::get($parsed, 'subject', '')), self::SUBJECT_MAX);
		$out['body']    = self::cut_text($body, self::BODY_MAX);

		if ($html_ok AND stripos($out['body'], '<') === FALSE)
		{
			// Письму без разметки не страшно; формат оставляем текстовый.
			$out['format'] = 'text';
		}

		$out['notes'] = self::cut_text(trim((string) Arr::get($parsed, 'notes', '')), 200);

		// Служебное: какие подстановки реально попали в шаблон и не осталось ли
		// выдуманных — об этом честно пишем в интерфейсе.
		$out['used']    = self::used_vars($out['subject'].' '.$out['body']);
		// _globals() отдаёт «имя → образец», поэтому сравнивать надо с именами:
		// с массивом значений глобальные подстановки попадали в «незнакомые».
		$out['unknown'] = array_values(array_diff($out['used'], array_merge($keys, array_keys(self::_globals()))));

		if ($out['unknown'])
		{
			$out['notes'] = trim($out['notes'].' Незнакомые подстановки: '.implode(', ', $out['unknown']));
		}

		// Оставляем след в системном журнале: кто и из чего собрал шаблон.
		// Отдельной таблицы не заводим — это редкое действие администратора.
		Kohana::$log->add(Log::INFO, 'prx597: ИИ собрал шаблон :event/:channel за :ms мс (запрос #:user)', [
			':event'  => $alias,
			':channel'=> $channel,
			':ms'     => $out['ms'],
			':user'   => (int) $user_id,
		]);

		return $out;
	}

	/** Подписи каналов — для подсказок и промпта. */
	public static function channel_labels()
	{
		$out = [];

		foreach (['in_app', 'email', 'telegram', 'max'] as $one)
		{
			$out[$one] = Service_Prx597_Registry::channel_label($one);
		}

		return $out;
	}

	/**
	 * Глобальные подстановки, доступные любому шаблону: имя — пример значения.
	 * Модель видит их с примерами и не выдумывает «{{название_задачи}}».
	 */
	protected static function _globals()
	{
		return [
			'user_name'    => 'Иван Петров',
			'site_url'     => self::base_url(),
			'site_name'    => 'PRX.ru',
			'href'         => 'ссылка на объект уведомления (например /trends/abc123)',
			'inbox_url'    => self::base_url().'/prx597',
			'settings_url' => self::base_url().'/prx597?tab=settings',
		];
	}

	/** Найти в тексте все {{подстановки}} — их потом сверяем с реестром. */
	public static function used_vars($text)
	{
		$out = [];

		if (preg_match_all('~\{\{\s*([a-z0-9_.]+)\s*\}\}~i', (string) $text, $m))
		{
			foreach ($m[1] as $one)
			{
				$out[strtolower($one)] = TRUE;
			}
		}

		return array_keys($out);
	}

	/**
	 * Разобрать ответ модели в JSON.
	 *
	 * Модели любят обернуть ответ в ```json … ``` или добавить фразу до и после:
	 * берём самый внешний объект в фигурных скобках. Если объекта нет — пустой
	 * массив, вызывающий сам решит, что делать.
	 */
	public static function parse_answer($answer)
	{
		$text = self::strip_fences($answer);

		$start = strpos($text, '{');
		$end   = strrpos($text, '}');

		if ($start !== FALSE AND $end !== FALSE AND $end > $start)
		{
			$json = json_decode(substr($text, $start, $end - $start + 1), TRUE);

			if (is_array($json))
			{
				return $json;
			}
		}

		return [];
	}

	/** Убрать ```json … ``` вокруг ответа. */
	public static function strip_fences($text)
	{
		$text = trim((string) $text);
		$text = preg_replace('~^```[a-zA-Z]*\s*~', '', $text);
		$text = preg_replace('~\s*```$~', '', $text);

		return trim((string) $text);
	}

	/** Обрезка по символам (не по байтам: русский текст). */
	public static function cut_text($text, $limit)
	{
		$text = trim(preg_replace('~[ \t]+~u', ' ', (string) $text));

		if (mb_strlen($text) <= $limit)
		{
			return $text;
		}

		return rtrim(mb_substr($text, 0, $limit - 1)).'…';
	}
}
