<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-662: список LLM для диалога — из строки конфига и «своя модель» от человека.
 *
 * Слово владельца (08.10.2026): «список LLM в конфиге, вида
 * openrouter::модель; TOOLFORGE::модель» — и тут же возможность вписать свою.
 * Поэтому здесь ровно две работы:
 *
 *   1. ПРОЧИТАТЬ строку config('models') — «провайдер::модель» через «;» — и
 *      отдать готовые варианты для выпадающего списка. Строку правит владелец,
 *      код о списке ничего не знает;
 *   2. ПРОВЕРИТЬ выбор из формы: либо спецификация из списка, либо «своя»
 *      (тоже «провайдер::модель», либо просто имя модели — тогда провайдера
 *      выберет воркер). Пустая строка — это «модель воркера по умолчанию»,
 *      а не ошибка.
 *
 * Зачем отдельный класс: разбор строки и разбор формы — чистая логика без базы
 * и модели, её проверяет tests/Unit/Nj662ModelsTest.php. В воркере та же строка
 * читается как есть: воркер и сам разбирает «провайдер::модель» (llm.py), так
 * что сайт обязан отдавать спецификацию БЕЗ изменений.
 */
class Service_Nj662_Models {

	/** @var array|NULL кэш разобранного списка (на запрос) */
	protected static $_offers = NULL;

	/**
	 * Строка конфига → список вариантов.
	 *
	 * Разделители — «;», запятая, перевод строки и «|»: владелец может оформить
	 * список как угодно, лишь бы записи читались. Мусор (пустое имя модели,
	 * повторы) отбрасываем: битая строка в списке выглядит как «модель есть, а
	 * она не работает».
	 *
	 * @param  string $raw строка из config('models')
	 * @return array  [['spec','provider','model','label'], …]
	 */
	public static function parse($raw)
	{
		$offers = [];
		$seen   = [];

		foreach (preg_split('/[;,\n|]+/u', (string) $raw) as $part)
		{
			$spec = trim((string) $part);

			if ($spec === '')
			{
				continue;
			}

			$provider = '';
			$model    = $spec;

			if (strpos($spec, '::') !== FALSE)
			{
				list($provider, $model) = explode('::', $spec, 2);
				$provider = trim($provider);
				$model    = trim($model);

				// «::модель» — запись без провайдера (его выберет воркер):
				// приводим к короткому виду, чтобы спецификация не уезжала
				// в базу с пустым провайдером в виде «::модель».
				if ($provider === '')
				{
					$spec = $model;
				}
			}

			// Модели без имени не существует: «openrouter::» — это опечатка.
			if ($model === '' OR isset($seen[$spec]))
			{
				continue;
			}

			$seen[$spec] = TRUE;
			$offers[] = [
				'spec'     => $spec,
				'provider' => $provider,
				'model'    => $model,
				// В списке показываем только имя модели и провайдера: полная
				// спецификация в тесной строке читается плохо.
				'label'    => $model.($provider !== '' ? ' ('.$provider.')' : ''),
			];
		}

		return $offers;
	}

	/** Варианты из конфига модуля (кэш на запрос). */
	public static function offers()
	{
		if (self::$_offers !== NULL)
		{
			return self::$_offers;
		}

		$cfg = (array) Kohana::$config->load('nj662_dialog');

		return self::$_offers = self::parse(Arr::get($cfg, 'models', ''));
	}

	/** Спецификации списка — то, что сравнивают с выбором из формы. */
	public static function specs()
	{
		$specs = [];

		foreach (self::offers() as $o)
		{
			$specs[] = $o['spec'];
		}

		return $specs;
	}

	/** Модель по умолчанию: первая в списке (её берёт новый диалог). */
	public static function default_spec()
	{
		$offers = self::offers();

		return $offers ? (string) $offers[0]['spec'] : '';
	}

	/** Подпись модели для ленты и карточек: пусто → «модель воркера». */
	public static function label($spec)
	{
		$spec = trim((string) $spec);

		if ($spec === '')
		{
			return 'модель воркера';
		}

		foreach (self::offers() as $o)
		{
			if ($o['spec'] === $spec)
			{
				return $o['label'];
			}
		}

		return $spec;
	}

	/**
	 * «Своя модель» из формы: разбор и проверка.
	 *
	 * Пустая строка — это не ошибка, а «модель воркера по умолчанию»: человек
	 * выбрал «своя», но не написал — значит, доверяет настройкам воркера.
	 *
	 * @return array ['spec' => '…', 'error' => '']
	 */
	public static function clean_custom($raw)
	{
		$spec = trim(preg_replace('/\s+/u', ' ', (string) $raw));
		$spec = str_replace(' :: ', '::', $spec);

		if ($spec === '')
		{
			return ['spec' => '', 'error' => ''];
		}

		$limit = (int) Arr::path((array) Kohana::$config->load('nj662_dialog'), 'limits.model_max', 160);

		if (mb_strlen($spec) > $limit)
		{
			return ['spec' => '', 'error' => 'Название модели длиннее '.$limit.' знаков — такую не разберёт ни один провайдер'];
		}

		if (strpos($spec, '::') !== FALSE)
		{
			list($provider, $model) = explode('::', $spec, 2);

			$provider = trim($provider);
			$model    = trim($model);

			if ($provider === '' OR $model === '')
			{
				return ['spec' => '', 'error' => 'Своя модель пишется как «провайдер::модель», например openrouter::google/gemini-2.5-flash-lite'];
			}

			if ( ! preg_match('/^[A-Za-z0-9_.\-]+$/', $provider))
			{
				return ['spec' => '', 'error' => 'В имени провайдера допустимы только буквы, цифры, «_», «-» и «.»'];
			}

			if (preg_match('/\s/', $model))
			{
				return ['spec' => '', 'error' => 'В имени модели не должно быть пробелов'];
			}
		}

		return ['spec' => $spec, 'error' => ''];
	}

	/**
	 * Выбор модели из формы настроек → спецификация для базы.
	 *
	 * Поля формы: <prefix>model (значение из списка, либо 'custom') и
	 * <prefix>model_custom (строка «провайдер::модель»). Префикс нужен потому,
	 * что в форме диалога две модели (участники 1 и 2). Значение не из списка и
	 * не 'custom' читаем как спецификацию: так форма переживает правку
	 * config('models') — старый диалог сохранит свою модель, а не потеряет её.
	 *
	 * @return array ['spec' => '…', 'error' => '']
	 */
	public static function from_form(array $post, $prefix = '')
	{
		$prefix = (string) $prefix;
		$choice = trim((string) Arr::get($post, $prefix.'model', ''));

		if ($choice === 'custom')
		{
			return self::clean_custom(Arr::get($post, $prefix.'model_custom', ''));
		}

		if ($choice === '')
		{
			return ['spec' => '', 'error' => ''];
		}

		$clean = self::clean_custom($choice);

		return $clean['spec'] !== '' ? $clean : self::clean_custom('');
	}

	/**
	 * Подсказка для поля «своя модель».
	 *
	 * Формулировка нейтральная: проверить провайдера по-настоящему может только
	 * воркер (у него в workers/.env адреса и ключи; сайт их не видит). Обещать
	 * «провайдер существует» сайт не имеет права.
	 */
	public static function provider_hint($provider = '')
	{
		$provider = trim((string) $provider);

		if ($provider === '')
		{
			return 'провайдер по умолчанию воркера';
		}

		if (class_exists('Kohana') AND is_object(Kohana::$config))
		{
			$providers = (array) Kohana::$config->load('llm.providers');

			if (isset($providers[$provider]) OR isset($providers[mb_strtolower($provider)]))
			{
				return 'провайдер объявлен на сайте';
			}
		}

		return 'провайдера нет среди конфигов сайта — проверит воркер';
	}
}
