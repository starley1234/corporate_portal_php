<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис NJ-665 «Озвучивание текста» (OmniVoice API v1).
 *
 * Внешний контракт — только эндпоинты v1 из OpenAPI (владелец, 09.10.2026):
 *
 *   POST /api/v1/audio/speech      — синтез (Authorization: Bearer <ключ>);
 *   GET  /api/v1/models?output_modalities=speech — каталог моделей, голосов
 *                                    и форматов (ключ не нужен);
 *   GET  /api/v1/key               — состояние ключа (лимиты/баланс).
 *
 * Старые /tts/v1/synthesize и /voices не используются: они оставлены в спеке
 * «для совместимости» и не знают ни моделей, ни инструкций голосу.
 *
 * Две вещи, из-за которых сервис написан именно так:
 *
 *  1. Формат ответа синтеза в v1 НЕ зафиксирован: сервер отдаёт то поток
 *     байт, то JSON с base64, то JSON со ссылкой на файл. Поэтому ответ
 *     разбирает audio_from_response(): сначала пробует JSON, потом берёт
 *     сырые байты, а формат уточняет по «магическим» байтам файла
 *     (sniff_format) — иначе mp3, пришедший как pcm, не проиграется.
 *
 *  2. Каталог голосов живёт на стороне API и без него форма не соберётся.
 *     Чтобы страница не падала и не ждала сеть на каждый показ, ответ
 *     /api/v1/models кешируется в nj_665_catalog и при недоступности API
 *     отдаётся последний известный каталог.
 */
class Service_Nj665_Voice
{
	const VERSION = '1.0.0';

	/** Ключи JSON, под которыми сервер может прислать base64-аудио. */
	protected static $_b64_keys = [
		'audio_base64', 'audio', 'data', 'content', 'audio_content', 'wav',
		'mp3', 'file_bytes', 'bytes', 'base64', 'b64', 'result', 'output',
		'speech', 'audio_data',
	];

	/** Ключи JSON, под которыми сервер может прислать ссылку на файл. */
	protected static $_url_keys = [
		'url', 'audio_url', 'file_url', 'link', 'download_url', 'href', 'file',
	];

	// ---------------------------------------------------------------- конфиг

	public static function config()
	{
		$config = Kohana::$config->load('nj665_voice');

		return $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;
	}

	/** Ветка конфига (api, limits, rate …) или значение по умолчанию. */
	public static function branch($name, $default = [])
	{
		return (array) Arr::get(self::config(), $name, $default);
	}

	public static function api($key = NULL, $default = NULL)
	{
		$api = self::branch('api');

		return $key === NULL ? $api : Arr::get($api, $key, $default);
	}

	public static function limits($key = NULL, $default = NULL)
	{
		$limits = self::branch('limits');

		return $key === NULL ? $limits : Arr::get($limits, $key, $default);
	}

	/**
	 * Включён ли сервис: нужен и адрес, и ключ.
	 *
	 * Without этого страница честно говорит «не настроен», вместо того чтобы
	 * ловить 401 от внешнего API.
	 */
	public static function enabled()
	{
		return self::api_base() !== '' AND self::api_key() !== '';
	}

	public static function api_base()
	{
		return rtrim((string) self::api('base_url', ''), '/');
	}

	public static function api_key()
	{
		return trim((string) self::api('key', ''));
	}

	public static function timeout($short = FALSE)
	{
		return max(5, (int) self::api($short ? 'short_timeout' : 'timeout', $short ? 15 : 120));
	}

	/** Каталог форматов: ключ формата => [ext, mime, playable, title]. */
	public static function format_map()
	{
		return (array) Arr::get(self::config(), 'formats', []);
	}

	public static function languages()
	{
		return (array) Arr::get(self::config(), 'languages', []);
	}

	// ------------------------------------------------------- каталог моделей

	/**
	 * Модели из /api/v1/models?output_modalities=speech (с кешем в nj_665_catalog).
	 *
	 * @param  bool $refresh TRUE — спросить API, игнорируя кеш
	 * @return array список ['id'=>, 'voices'=>[], 'formats'=>[], 'speech'=>bool]
	 */
	public static function models($refresh = FALSE)
	{
		$cached = self::_catalog_get('models');

		if ( ! $refresh AND $cached !== NULL)
		{
			return (array) Arr::get($cached, 'models', []);
		}

		$res = self::_http('GET', '/api/v1/models?output_modalities=speech', NULL, [
			'short'    => TRUE,
			'with_key' => self::api_key() !== '',
		]);

		if ($res['error'] !== '' OR $res['status'] < 200 OR $res['status'] >= 300)
		{
			self::_log(Log::WARNING, 'каталог моделей недоступен: :error', [
				':error' => $res['error'] !== '' ? $res['error'] : 'HTTP '.$res['status'],
			]);

			return (array) Arr::get((array) $cached, 'models', []);
		}

		$models = self::parse_models(json_decode($res['body'], TRUE));

		if ( ! $models)
		{
			self::_log(Log::WARNING, 'каталог моделей пуст: :body', [':body' => UTF8::substr($res['body'], 0, 200)]);

			return (array) Arr::get((array) $cached, 'models', []);
		}

		self::_catalog_set('models', ['models' => $models]);

		return $models;
	}

	/** Только те модели, которые умеют речь (есть голоса). */
	public static function speech_models($refresh = FALSE)
	{
		$out = [];

		foreach (self::models($refresh) as $model)
		{
			if (Arr::get($model, 'speech'))
			{
				$out[] = $model;
			}
		}

		return $out;
	}

	/**
	 * Разбор ответа /api/v1/models в плоский список.
	 *
	 * Чистая функция (ни БД, ни сети) — её и проверяют тесты: форма ответа
	 * в v1 документирована как пустой объект {}, поэтому приходится
	 * угадывать по полям, не падая ни на одном из вариантов.
	 */
	public static function parse_models($decoded)
	{
		$rows = [];

		if ( ! is_array($decoded))
		{
			return $rows;
		}

		$data = NULL;

		if (isset($decoded['data']) AND is_array($decoded['data']))
		{
			$data = $decoded['data'];
		}
		elseif (isset($decoded[0]))
		{
			$data = $decoded;
		}
		elseif (isset($decoded['id']))
		{
			$data = [$decoded];
		}

		if ( ! is_array($data))
		{
			return $rows;
		}

		foreach ($data as $item)
		{
			if ( ! is_array($item))
			{
				continue;
			}

			$id = trim((string) (Arr::get($item, 'id') !== NULL
				? Arr::get($item, 'id')
				: Arr::get($item, 'name', '')));

			if ($id === '')
			{
				continue;
			}

			$voices = [];

			foreach ((array) Arr::get($item, 'supported_voices', []) as $voice)
			{
				if (is_array($voice))
				{
					$voice = Arr::get($voice, 'id') !== NULL
						? Arr::get($voice, 'id')
						: Arr::get($voice, 'name', '');
				}

				$voice = trim((string) $voice);

				if ($voice !== '')
				{
					$voices[] = $voice;
				}
			}

			$formats = [];

			foreach ((array) Arr::get($item, 'supported_formats', []) as $format)
			{
				$format = strtolower(trim((string) (is_array($format) ? Arr::get($format, 'id', '') : $format)));

				if ($format !== '' AND ! in_array($format, $formats))
				{
					$formats[] = $format;
				}
			}

			$modalities = array_map('strtolower',
				(array) Arr::get($item, 'output_modalities', Arr::get($item, 'modalities', [])));

			$rows[] = [
				'id'      => $id,
				'title'   => trim((string) Arr::get($item, 'name', $id)),
				'voices'  => $voices,
				'formats' => $formats,
				'speech'  => $voices !== [] OR in_array('speech', $modalities),
			];
		}

		return $rows;
	}

	/**
	 * Голоса с характеристиками: GET /api/v1/voices (фильтры gender/age)
	 * и GET /api/v1/voices/{id}. Кеш — в nj_665_catalog.
	 *
	 * Зачем отдельный справочник, если у модели уже есть supported_voices:
	 * многоголосой озвучке (nj666) нужен ПОДБОР голоса под персонажа — пол,
	 * возраст и описание характера. По одному имени голоса модель не угадает,
	 * что «morfius» — мужской и взрослый.
	 *
	 * @param  bool  $refresh
	 * @param  array $filter ['gender' => 'male'|'female', 'age' => 'child|young|adult|elderly']
	 * @return array список ['id','name','gender','age','description','model']
	 */
	public static function voices_catalog($refresh = FALSE, array $filter = [])
	{
		$key    = 'voices'.($filter ? ':'.md5(json_encode($filter)) : '');
		$cached = self::_catalog_get($key);

		if ( ! $refresh AND $cached !== NULL)
		{
			return (array) Arr::get($cached, 'voices', []);
		}

		$query = [];

		foreach (['gender', 'age'] as $field)
		{
			$value = trim((string) Arr::get($filter, $field, ''));

			if ($value !== '')
			{
				$query[$field] = $value;
			}
		}

		$res = self::_http('GET', '/api/v1/voices'.($query ? '?'.http_build_query($query) : ''), NULL, [
			'short'    => TRUE,
			'with_key' => self::api_key() !== '',
		]);

		if ($res['error'] !== '' OR $res['status'] < 200 OR $res['status'] >= 300)
		{
			self::_log(Log::WARNING, 'каталог голосов недоступен: :error', [
				':error' => $res['error'] !== '' ? $res['error'] : 'HTTP '.$res['status'],
			]);

			return (array) Arr::get((array) $cached, 'voices', []);
		}

		$voices = self::parse_voices(json_decode($res['body'], TRUE));

		if ( ! $voices)
		{
			self::_log(Log::WARNING, 'каталог голосов пуст: :body', [':body' => UTF8::substr($res['body'], 0, 200)]);

			return (array) Arr::get((array) $cached, 'voices', []);
		}

		self::_catalog_set($key, ['voices' => $voices]);

		return $voices;
	}

	/** Один голос по id (из каталога или прямым запросом /api/v1/voices/{id}). */
	public static function voice_info($id, $refresh = FALSE)
	{
		$id = trim((string) $id);

		if ($id === '')
		{
			return NULL;
		}

		foreach (self::voices_catalog($refresh) as $voice)
		{
			if ((string) Arr::get($voice, 'id') === $id)
			{
				return $voice;
			}
		}

		if ( ! self::enabled())
		{
			return NULL;
		}

		$res = self::_http('GET', '/api/v1/voices/'.rawurlencode($id), NULL, [
			'short'    => TRUE,
			'with_key' => TRUE,
		]);

		if ($res['error'] !== '' OR $res['status'] < 200 OR $res['status'] >= 300)
		{
			return NULL;
		}

		$voices = self::parse_voices(json_decode($res['body'], TRUE));

		return $voices ? $voices[0] : NULL;
	}

	/**
	 * Разбор ответа /api/v1/voices в плоский список.
	 *
	 * Чистая функция: ответ в спеке описан как пустой {}, а на живом сервере
	 * голоса приходят и под `data`, и списком, и по одному (GET /voices/{id}).
	 * Поляgender/age/description опциональны: у старых голосов их просто нет.
	 */
	public static function parse_voices($decoded)
	{
		$out = [];

		if ( ! is_array($decoded))
		{
			return $out;
		}

		$data = NULL;

		if (isset($decoded['data']))
		{
			$data = is_array($decoded['data']) ? $decoded['data'] : [$decoded['data']];
		}
		elseif (isset($decoded[0]))
		{
			$data = $decoded;
		}
		elseif (isset($decoded['id']))
		{
			$data = [$decoded];
		}

		if ( ! is_array($data))
		{
			return $out;
		}

		foreach ($data as $item)
		{
			if ( ! is_array($item))
			{
				// Список голосов может быть списком строк (старый вид /voices).
				$item = is_string($item) ? ['id' => $item] : NULL;
			}

			if ( ! is_array($item))
			{
				continue;
			}

			$id = trim((string) (Arr::get($item, 'id') !== NULL
				? Arr::get($item, 'id')
				: Arr::get($item, 'name', '')));

			if ($id === '')
			{
				continue;
			}

			$out[] = [
				'id'          => $id,
				'name'        => trim((string) (Arr::get($item, 'name') ?: $id)),
				'gender'      => strtolower(trim((string) Arr::get($item, 'gender', ''))),
				'age'         => strtolower(trim((string) Arr::get($item, 'age', ''))),
				'description' => trim((string) Arr::get($item, 'description', '')),
				'model'       => trim((string) Arr::get($item, 'model', '')),
			];
		}

		return $out;
	}

	/** Модель по id (или NULL, если её нет в каталоге). */
	public static function model($id, $refresh = FALSE)
	{
		foreach (self::models($refresh) as $model)
		{
			if ($model['id'] === (string) $id)
			{
				return $model;
			}
		}

		return NULL;
	}

	/** Голоса модели: пусто, если модели нет или каталог недоступен. */
	public static function voices($model_id = NULL)
	{
		$model = $model_id === NULL ? self::default_model_row() : self::model($model_id);
		$voices = (array) Arr::get((array) $model, 'voices', []);

		return $voices;
	}

	/** Форматы модели; если каталог недоступен — все известные. */
	public static function formats($model_id = NULL)
	{
		$model = $model_id === NULL ? self::default_model_row() : self::model($model_id);
		$formats = (array) Arr::get((array) $model, 'formats', []);

		if ( ! $formats)
		{
			$formats = array_keys(self::format_map());
		}

		$out = [];

		foreach ($formats as $format)
		{
			if (isset(self::format_map()[$format]))
			{
				$out[] = $format;
			}
		}

		return $out;
	}

	/** Модель по умолчанию: из .env, иначе первая речевая из каталога. */
	public static function default_model()
	{
		$want = trim((string) Arr::get(self::config(), 'model', ''));

		if ($want !== '' AND self::model($want) !== NULL)
		{
			return $want;
		}

		$models = self::speech_models();

		if ($models)
		{
			return (string) $models[0]['id'];
		}

		return (string) Arr::get(self::config(), 'model_fallback', 'k2-fsa/omnivoice');
	}

	public static function default_model_row()
	{
		return self::model(self::default_model());
	}

	/** Голос по умолчанию: из .env, иначе первый у модели. */
	public static function default_voice($model_id = NULL)
	{
		$voices = self::voices($model_id);
		$want   = trim((string) Arr::get(self::config(), 'default_voice', ''));

		if ($want !== '' AND in_array($want, $voices))
		{
			return $want;
		}

		return $voices ? (string) $voices[0] : '';
	}

	/**
	 * Формат по умолчанию — mp3, но только если модель его умеет: в v1
	 * response_format по умолчанию pcm (сырой поток), который браузер
	 * не проигрывает, а скачанный «файл» без контейнера ничем не открыть.
	 */
	public static function default_format($model_id = NULL)
	{
		$allowed = self::formats($model_id);
		$want    = strtolower(trim((string) Arr::get(self::config(), 'default_format', 'mp3')));

		return self::pick_format($want, $allowed);
	}

	// ------------------------------------------------------------- состояние

	/** GET /api/v1/key — лимиты/состояние ключа (для страницы администратора). */
	public static function key_info($refresh = FALSE)
	{
		if ( ! self::enabled())
		{
			return ['ok' => FALSE, 'error' => 'Сервис не настроен: не заданы NJ665_API_URL или NJ665_API_KEY.'];
		}

		$cached = self::_catalog_get('key', 10);

		if ( ! $refresh AND $cached !== NULL)
		{
			return ['ok' => TRUE, 'data' => Arr::get($cached, 'data', [])];
		}

		$res = self::_http('GET', '/api/v1/key', NULL, ['short' => TRUE, 'with_key' => TRUE]);

		if ($res['error'] !== '')
		{
			return ['ok' => FALSE, 'error' => $res['error']];
		}

		if ($res['status'] < 200 OR $res['status'] >= 300)
		{
			return ['ok' => FALSE, 'error' => self::_error_text($res, 'ключ недоступен')];
		}

		$data = json_decode($res['body'], TRUE);

		if ( ! is_array($data))
		{
			$data = ['raw' => UTF8::substr($res['body'], 0, 500)];
		}

		self::_catalog_set('key', ['data' => $data]);

		return ['ok' => TRUE, 'data' => $data];
	}

	// ------------------------------------------------------- распознавание

	/**
	 * POST /api/v1/audio/transcriptions — распознать короткую запись.
	 *
	 * Клиент OmniVoice в проекте один — этот сервис. /voice и /multivoice
	 * синтезируют, /talk (nj677) разговаривает: слушает запись с микрофона и
	 * озвучивает ответ. Поэтому сетевой вызов распознавания живёт здесь, а не
	 * копией во втором модуле — иначе адрес, ключ, таймауты и разбор ответа
	 * разъедутся, как это уже было с провайдерами LLM.
	 *
	 * Ответ в спеке не описан (в OpenAPI пустая схема), поэтому разбор
	 * терпимый: JSON с text/data.text/segments, OpenAI-совместимый verbose_json
	 * и просто текст. Чистая часть — parse_transcription(), её проверяют тесты.
	 *
	 * @param  string $bytes    содержимое записи
	 * @param  string $filename имя файла (расширение подсказывает контейнер)
	 * @param  string $language код языка ('' — определить самому)
	 * @param  string $model    модель ('' — NJ665_ASR_MODEL или whisper-1)
	 * @return array ok, text, error, ms, model, language
	 */
	public static function transcribe($bytes, $filename = 'speech.webm', $language = '', $model = '')
	{
		$out = [
			'ok'       => FALSE,
			'text'     => '',
			'error'    => '',
			'ms'       => 0,
			'model'    => '',
			'language' => '',
		];

		if ( ! self::enabled())
		{
			$out['error'] = 'Сервис не настроен: задайте NJ665_API_URL и NJ665_API_KEY в .env.';

			return $out;
		}

		$bytes = (string) $bytes;

		if ($bytes === '')
		{
			$out['error'] = 'Пустая запись.';

			return $out;
		}

		$model = trim((string) $model) ?: (string) Arr::get(self::config(), 'asr_model', 'whisper-1');
		$model = $model !== '' ? $model : 'whisper-1';

		$language = trim((string) $language);
		$out['model'] = $model;
		$out['language'] = $language;

		if ( ! function_exists('curl_init'))
		{
			$out['error'] = 'на сервере нет curl';

			return $out;
		}

		$filename = trim((string) $filename);

		if ($filename === '')
		{
			$filename = 'speech.webm';
		}

		$path = tempnam(sys_get_temp_dir(), 'nj665asr');

		if ($path === FALSE)
		{
			$out['error'] = 'не удалось подготовить файл для отправки';

			return $out;
		}

		file_put_contents($path, $bytes);

		$fields = [
			'file'  => new CURLFile($path, 'application/octet-stream', $filename),
			'model' => $model,
		];

		if ($language !== '')
		{
			$fields['language'] = $language;
		}

		$timeout = max(5, (int) Arr::get(self::config(), 'asr_timeout', 60));
		$start = microtime(TRUE);

		// Путь вынесен в конфиг: распознавание есть не у каждого шлюза OmniVoice,
		// и тогда администратор подставляет свой адрес, не правя код.
		$path_asr = (string) Arr::get(self::config(), 'asr_path', '/api/v1/audio/transcriptions');
		$path_asr = '/'.ltrim($path_asr !== '' ? $path_asr : '/api/v1/audio/transcriptions', '/');

		$ch = curl_init(self::api_base().$path_asr);

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_USERAGENT      => 'n2j.ru/voice (nj665 asr)',
			CURLOPT_POST           => TRUE,
			CURLOPT_POSTFIELDS     => $fields,
			CURLOPT_HTTPHEADER     => array_filter([
				'Accept: application/json',
				self::api_key() !== '' ? 'Authorization: Bearer '.self::api_key() : '',
			]),
		]);

		$response     = curl_exec($ch);
		$errno        = curl_errno($ch);
		$error        = curl_error($ch);
		$status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$content_type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

		curl_close($ch);
		@unlink($path);

		$out['ms'] = (int) round((microtime(TRUE) - $start) * 1000);

		if ($errno)
		{
			$out['error'] = $error !== '' ? $error : 'ошибка curl #'.$errno;
			self::_log(Log::WARNING, 'распознавание: :error', [':error' => $out['error']]);

			return $out;
		}

		if ($status < 200 OR $status >= 300)
		{
			$out['error'] = self::_error_text([
				'status' => $status,
				'body'   => (string) $response,
				'error'  => '',
			], 'распознавание недоступно');

			self::_log(Log::WARNING, 'распознавание: HTTP :status', [':status' => $status]);

			return $out;
		}

		$out['text'] = self::parse_transcription((string) $response, $content_type);

		if ($out['text'] === '')
		{
			$out['error'] = 'Речь не распознана — попробуйте сказать ещё раз.';

			return $out;
		}

		$out['ok'] = TRUE;

		return $out;
	}

	/**
	 * Разбор ответа /api/v1/audio/transcriptions.
	 *
	 * Чистая функция (ни сети, ни БД): форма ответа в OpenAPI не описана, а в
	 * живых сервисах встречаются все варианты сразу — `{text}`, `{data:{text}}`,
	 * `{results:[{text}]}`, `{segments:[…]}` и обычный `text/plain`.
	 */
	public static function parse_transcription($body, $content_type = '')
	{
		$body = trim((string) $body);

		if ($body === '')
		{
			return '';
		}

		$content_type = strtolower(trim((string) $content_type));
		$decoded = NULL;

		if ($body[0] === '{' OR $body[0] === '[')
		{
			$decoded = json_decode($body, TRUE);
		}

		if (is_string($decoded))
		{
			return self::_asr_text($decoded);
		}

		if (is_array($decoded))
		{
			foreach (['text', 'transcription', 'output_text', 'result'] as $key)
			{
				if (isset($decoded[$key]) AND is_string($decoded[$key]))
				{
					return self::_asr_text($decoded[$key]);
				}
			}

			if (isset($decoded['data']))
			{
				$text = self::parse_transcription(is_string($decoded['data'])
					? $decoded['data']
					: json_encode($decoded['data'], JSON_UNESCAPED_UNICODE), $content_type);

				if ($text !== '')
				{
					return $text;
				}
			}

			foreach (['results', 'segments'] as $key)
			{
				if ( ! empty($decoded[$key]) AND is_array($decoded[$key]))
				{
					$pieces = [];

					foreach ($decoded[$key] as $row)
					{
						$piece = is_array($row) ? (string) Arr::get($row, 'text', '') : (string) $row;

						if (trim($piece) !== '')
						{
							$pieces[] = trim($piece);
						}
					}

					if ($pieces)
					{
						return self::_asr_text(implode(' ', $pieces));
					}
				}
			}
		}

		if (strpos($content_type, 'json') !== FALSE)
		{
			return '';
		}

		// Не JSON — сервер ответил текстом (так умеют whisper-совместимые шлюзы).
		return self::_asr_text($body);
	}

	/** Текст распознавания: пробелы в строку, управляющие символы прочь, кап. */
	protected static function _asr_text($text)
	{
		$text = str_replace("\0", '', (string) $text);
		$text = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $text);
		$text = preg_replace('~\s+~u', ' ', $text);
		$text = trim($text);

		return UTF8::substr($text, 0, 20000);
	}

	// ---------------------------------------------------------------- синтез

	/**
	 * Один синтез: запрос к /api/v1/audio/speech, сохранение файла и запись
	 * в журнал.
	 *
	 * @param  int   $user_id 0 — гость (тогда владелец записи — visitor_uid)
	 * @param  array $opt     text, model, voice, format, speed, language,
	 *                        instructions, references
	 * @return array ['ok'=>TRUE, 'code'=>…, 'url'=>…, 'bytes'=>…]
	 *               | ['ok'=>FALSE, 'error'=>…]
	 */
	/**
	 * Синтез без журнала и без файла: только запрос к внешнему API и байты.
	 *
	 * Отдельно от synthesize() потому, что байты нужны не всегда: многоголосая
	 * озвучка (nj666) собирает блок репликами, кладёт файл в свой каталог и
	 * пишет свой журнал. А разбор ответа (поток / JSON base64 / ссылка) и
	 * проверка формата по содержимому обязаны быть одни на всех — иначе в одном
	 * месте заиграет, в другом нет.
	 *
	 * @param  array $opt text | turns, model, voice, format, speed, language,
	 *                    instructions, references
	 * @return array ['ok'=>TRUE,'bytes'=>,'format'=>,'model'=>,'voice'=>,'chars'=>…]
	 *               | ['ok'=>FALSE,'error'=>…] (поля разбора сохранены и при отказе)
	 */
	public static function synth_bytes(array $opt)
	{
		$out = [
			'ok'           => FALSE,
			'error'        => '',
			'model'        => '',
			'voice'        => '',
			'format'       => '',
			'language'     => '',
			'instructions' => '',
			'speed'        => 1.0,
			'chars'        => 0,
			'text'         => '',
		];

		if ( ! self::enabled())
		{
			$out['error'] = 'Сервис не настроен: задайте NJ665_API_URL и NJ665_API_KEY в .env.';

			return $out;
		}

		$model  = trim((string) Arr::get($opt, 'model', '')) ?: self::default_model();

		if (self::model($model) === NULL)
		{
			$model = self::default_model();
		}

		$voice  = trim((string) Arr::get($opt, 'voice', ''));
		$voices = self::voices($model);
		$voice  = self::match_voice($voice, $voices, self::default_voice($model));

		$format = self::pick_format((string) Arr::get($opt, 'format', ''), self::formats($model));

		$language = trim((string) Arr::get($opt, 'language', ''));

		if ($language !== '' AND Arr::get(self::languages(), $language) === NULL)
		{
			$language = '';
		}

		$instructions = trim((string) Arr::get($opt, 'instructions', ''));

		if ($instructions !== '')
		{
			$instructions = UTF8::substr($instructions, 0, (int) self::limits('max_instructions', 500));
		}

		$speed = self::clamp_speed((float) Arr::get($opt, 'speed',
			Arr::get(self::config(), 'default_speed', 1.0)));

		$max_chars = max(1, (int) self::limits('max_chars', 4000));

		// Многоголосый блок: input — массив реплик, у каждой свой голос
		// (Turn{text, voice, instructions} из SpeechRequest). Одна реплика —
		// обычная строка, как и было.
		$turns = self::clean_turns((array) Arr::get($opt, 'turns', []), $voices, $voice, $max_chars);

		$out['model']        = $model;
		$out['voice']        = $voice;
		$out['format']       = $format;
		$out['language']     = $language;
		$out['instructions'] = $instructions;
		$out['speed']        = $speed;

		$payload = [
			'model'           => $model,
			'response_format' => $format,
			'speed'           => $speed,
		];

		if ($turns)
		{
			$payload['input'] = $turns['turns'];
			$out['chars']     = $turns['chars'];
			$out['text']      = $turns['text'];
		}
		else
		{
			$text = self::clean_text((string) Arr::get($opt, 'text', ''));

			if ($text === '')
			{
				$out['error'] = 'Введите текст для озвучивания.';

				return $out;
			}

			if (UTF8::strlen($text) > $max_chars)
			{
				$text = UTF8::substr($text, 0, $max_chars);
			}

			$payload['input'] = $text;
			$out['chars']     = UTF8::strlen($text);
			$out['text']      = $text;
		}

		// Голос верхнего уровня — и для одной строки, и как умолчание
		// реплик без voice: иначе OmniVoice сам выбирает голос на абзац
		// (владелец, 11.10.2026).
		if ($voice !== '')
		{
			$payload['voice'] = $voice;
		}

		if ($language !== '')
		{
			$payload['language'] = $language;
		}

		if ($instructions !== '')
		{
			$payload['instructions'] = $instructions;
		}

		$references = self::clean_references((array) Arr::get($opt, 'references', []));

		if ($references)
		{
			$payload['input_references'] = $references;
		}

		$res = self::_http('POST', '/api/v1/audio/speech', $payload, ['with_key' => TRUE]);

		if ($res['error'] !== '')
		{
			$out['error'] = 'Сервис озвучивания недоступен: '.$res['error'];

			return $out;
		}

		if ($res['status'] < 200 OR $res['status'] >= 300)
		{
			$out['error'] = self::_error_text($res, 'синтез не удался');

			return $out;
		}

		$audio = self::audio_from_response($res['body'], $res['content_type'], $format);

		if ($audio['url'] !== '')
		{
			$fetched = self::fetch_url($audio['url']);

			if ($fetched['error'] !== '')
			{
				$out['error'] = 'Сервис вернул ссылку на файл, но скачать его не удалось: '.$fetched['error'];

				return $out;
			}

			$audio['bytes']  = $fetched['body'];
			$audio['format'] = self::sniff_format($fetched['body']) ?: $audio['format'];
		}

		if ($audio['bytes'] === '')
		{
			$out['error'] = $audio['error'] !== '' ? $audio['error'] : 'Сервис вернул пустой ответ.';

			return $out;
		}

		$real = self::sniff_format($audio['bytes']);

		if ($real !== NULL AND $real !== $format)
		{
			// Формат в ответе расходится с запрошенным (сервер игнорирует
			// response_format): отдавать файл надо по факту, иначе браузер
			// получит mp3 с заголовком audio/wav и не проиграет его.
			$format = $real;
		}

		$out['ok']     = TRUE;
		$out['bytes']  = $audio['bytes'];
		$out['format'] = $format;
		$out['mime']   = self::media_type($format);

		return $out;
	}

	/**
	 * Голос реплики → id из каталога модели.
	 *
	 * Пустой и «heroic_leader_1.wav» вместо heroic_leader_1 иначе уходят
	 * в синтез без voice — OmniVoice тогда сам ставит случайный голос
	 * на абзац (владелец, 11.10.2026).
	 */
	public static function match_voice($voice, array $allowed = [], $fallback = '')
	{
		$voice    = trim((string) $voice);
		$fallback = trim((string) $fallback);

		if ($voice === '')
		{
			return $fallback;
		}

		if ( ! $allowed)
		{
			return $voice;
		}

		if (in_array($voice, $allowed, TRUE) OR in_array($voice, $allowed))
		{
			return $voice;
		}

		$stem = preg_replace('~\.(wav|mp3|ogg|flac|opus|m4a)$~i', '', $voice);
		$want = strtolower($stem);

		foreach ($allowed as $one)
		{
			$id = (string) $one;

			if ($id === $stem OR strtolower($id) === $want)
			{
				return $id;
			}

			$other = preg_replace('~\.(wav|mp3|ogg|flac|opus|m4a)$~i', '', $id);

			if (strtolower((string) $other) === $want)
			{
				return $id;
			}
		}

		return $fallback;
	}

	/**
	 * Реплики многоголосого блока: [{text, voice?, instructions?}].
	 *
	 * Чистая функция. Голос реплики проверяем по голосам модели: чужой голос
	 * сервер отвергнет весь запрос (422), поэтому лучше подменить на голос
	 * книги, чем потерять блок целиком.
	 */
	public static function clean_turns(array $turns, array $allowed = [], $fallback_voice = '', $max_chars = 4000)
	{
		$out   = [];
		$chars = 0;
		$texts = [];

		foreach ($turns as $turn)
		{
			if (is_string($turn))
			{
				$turn = ['text' => $turn];
			}

			if ( ! is_array($turn))
			{
				continue;
			}

			$piece = self::clean_text((string) Arr::get($turn, 'text', ''));

			if ($piece === '')
			{
				continue;
			}

		$voice = self::match_voice((string) Arr::get($turn, 'voice', ''),
			$allowed, $fallback_voice);

			$note = trim((string) Arr::get($turn, 'instructions', ''));

			$item = ['text' => $piece];

			if ($voice !== '')
			{
				$item['voice'] = $voice;
			}

			if ($note !== '')
			{
				$item['instructions'] = UTF8::substr($note, 0, (int) self::limits('max_instructions', 500));
			}

			$out[]   = $item;
			$texts[] = $piece;
			$chars  += UTF8::strlen($piece);

			// Жёсткий потолок реплик: один запрос не должен уйти в бесконечность
			// (блок книги — это минуты речи, не весь роман).
			if ($chars >= $max_chars OR count($out) >= 300)
			{
				break;
			}
		}

		return $out ? ['turns' => $out, 'chars' => $chars, 'text' => implode("\n\n", $texts)] : NULL;
	}

	/**
	 * Один синтез: запрос к /api/v1/audio/speech, сохранение файла и запись
	 * в журнал.
	 *
	 * @param  int   $user_id 0 — гость (тогда владелец записи — visitor_uid)
	 * @param  array $opt     text | turns, model, voice, format, speed, language,
	 *                        instructions, references
	 * @return array ['ok'=>TRUE, 'code'=>…, 'url'=>…, 'bytes'=>…]
	 *               | ['ok'=>FALSE, 'error'=>…]
	 */
	public static function synthesize($user_id, array $opt)
	{
		if ( ! self::enabled())
		{
			return ['ok' => FALSE, 'error' => 'Сервис не настроен: задайте NJ665_API_URL и NJ665_API_KEY в .env.'];
		}

		$audio = self::synth_bytes($opt);

		if ( ! $audio['ok'])
		{
			return self::_fail($opt,
				(string) Arr::get($audio, 'model', ''),
				(string) Arr::get($audio, 'voice', ''),
				(string) Arr::get($audio, 'format', ''),
				(string) Arr::get($audio, 'language', ''),
				(string) Arr::get($audio, 'instructions', ''),
				(string) Arr::get($audio, 'text', ''),
				(string) Arr::get($audio, 'error', 'Не удалось озвучить текст.'));
		}

		$text   = (string) Arr::get($audio, 'text', '');
		$bytes  = strlen((string) Arr::get($audio, 'bytes', ''));
		$format = (string) Arr::get($audio, 'format', 'mp3');
		$model  = (string) Arr::get($audio, 'model', '');
		$voice  = (string) Arr::get($audio, 'voice', '');

		$code = self::code_new();
		$dir  = self::storage_dir();
		$file = $dir.DIRECTORY_SEPARATOR.$code.'.'.self::format_ext($format);

		if ( ! is_dir($dir) AND ! @mkdir($dir, 0755, TRUE) AND ! is_dir($dir))
		{
			return self::_fail($opt, $model, $voice, $format,
				(string) Arr::get($audio, 'language', ''),
				(string) Arr::get($audio, 'instructions', ''), $text,
				'Не удалось создать каталог хранения.');
		}

		if (@file_put_contents($file, (string) Arr::get($audio, 'bytes', '')) === FALSE)
		{
			return self::_fail($opt, $model, $voice, $format,
				(string) Arr::get($audio, 'language', ''),
				(string) Arr::get($audio, 'instructions', ''), $text,
				'Не удалось сохранить аудиофайл.');
		}

		$uid = class_exists('Visitor') ? (string) Visitor::uid() : '';
		$ip  = class_exists('Service_Nj100_Ratelimit') ? (string) Service_Nj100_Ratelimit::client_ip() : '';

		try
		{
			self::_exec('INSERT INTO nj_665_speeches
					(code, user_id, visitor_uid, client_ip, model, voice, format, speed, language,
					 instructions, chars, bytes, title, excerpt, status)
				VALUES ('
				.self::_q($code).', '
				.(int) $user_id.', '
				.self::_q(mb_substr($uid, 0, 40)).', '
				.self::_q(mb_substr($ip, 0, 64)).', '
				.self::_q(mb_substr($model, 0, 120)).', '
				.self::_q(mb_substr($voice, 0, 80)).', '
				.self::_q(mb_substr($format, 0, 8)).', '
				.(float) Arr::get($audio, 'speed', 1.0).', '
				.self::_q(mb_substr((string) Arr::get($audio, 'language', ''), 0, 16)).', '
				.self::_q(mb_substr((string) Arr::get($audio, 'instructions', ''), 0, 500)).', '
				.(int) Arr::get($audio, 'chars', 0).', '
				.(int) $bytes.', '
				.self::_q(mb_substr(self::title_from_text($text), 0, 200)).', '
				.self::_q(mb_substr(self::excerpt($text), 0, 400)).', '
				.self::_q('done').')');
		}
		catch (Exception $e)
		{
			@unlink($file);

			return self::_fail($opt, $model, $voice, $format,
				(string) Arr::get($audio, 'language', ''),
				(string) Arr::get($audio, 'instructions', ''), $text,
				'Не удалось записать озвучку в журнал: '.$e->getMessage());
		}

		self::sweep();

		return [
			'ok'     => TRUE,
			'code'   => $code,
			'url'    => '/voice/f/'.$code,
			'format' => $format,
			'mime'   => self::media_type($format),
			'bytes'  => $bytes,
			'chars'  => (int) Arr::get($audio, 'chars', 0),
			'model'  => $model,
			'voice'  => $voice,
			'title'  => self::title_from_text($text),
		];
	}

	// ------------------------------------------------------------- разборщики

	/**
	 * Достать аудио из ответа синтеза: JSON с base64, JSON со ссылкой
	 * или «сырой» поток байт.
	 *
	 * Чистая функция — никакой сети и БД, чтобы её можно было проверить
	 * тестом на всех трёх вариантах.
	 *
	 * @return array ['bytes'=>string, 'url'=>string, 'format'=>string, 'error'=>string]
	 */
	public static function audio_from_response($body, $content_type = '', $format = 'mp3')
	{
		$body = (string) $body;

		$out = ['bytes' => '', 'url' => '', 'format' => (string) $format, 'error' => ''];

		if ($body === '')
		{
			$out['error'] = 'Сервис вернул пустой ответ.';

			return $out;
		}

		$head = substr(ltrim($body), 0, 1);

		if ($head === '{' OR $head === '[')
		{
			$data = json_decode($body, TRUE);

			if (is_array($data))
			{
				$found = self::audio_from_json($data);

				if ($found['bytes'] !== '')
				{
					$out['bytes']  = $found['bytes'];
					$out['format'] = self::sniff_format($found['bytes']) ?: $out['format'];

					return $out;
				}

				if ($found['url'] !== '')
				{
					$out['url']    = $found['url'];
					$out['format'] = self::format_from_url($found['url']) ?: $out['format'];

					return $out;
				}

				$out['error'] = 'Ответ сервиса не похож на аудио: '.UTF8::substr($body, 0, 120);

				return $out;
			}
			// Не JSON (например, HTML-заглушка) — идём как с сырыми байтами.
		}

		$sniffed = self::sniff_format($body);

		if ($sniffed === NULL AND strpos(strtolower((string) $content_type), 'application/json') !== FALSE)
		{
			$out['error'] = 'Сервис вернул JSON, но без аудио.';

			return $out;
		}

		$out['bytes']  = $body;
		$out['format'] = $sniffed ?: $out['format'];

		return $out;
	}

	/** Поиск base64 или ссылки внутри JSON ответа (обход в ширину, до 4 уровней). */
	public static function audio_from_json(array $data)
	{
		$out   = ['bytes' => '', 'url' => ''];
		$level = [$data];

		for ($depth = 0; $depth < 4 AND $level; $depth++)
		{
			$next = [];

			foreach ($level as $node)
			{
				foreach ((array) $node as $key => $value)
				{
					if (is_array($value))
					{
						$next[] = $value;

						continue;
					}

					if ( ! is_string($value))
					{
						continue;
					}

					$key   = strtolower((string) $key);
					$value = trim($value);

					if ($value === '')
					{
						continue;
					}

					if ($out['url'] === ''
						AND preg_match('~^https?://~i', $value)
						AND (in_array($key, self::$_url_keys) OR stripos($value, '/audio/') !== FALSE))
					{
						$out['url'] = $value;

						continue;
					}

					if ($out['bytes'] === '' AND self::looks_like_base64($value)
						AND (in_array($key, self::$_b64_keys) OR $depth > 0))
					{
						$bytes = self::base64_decode($value);

						if ($bytes !== FALSE AND $bytes !== '')
						{
							$out['bytes'] = $bytes;
						}
					}
				}
			}

			if ($out['bytes'] !== '' OR $out['url'] !== '')
			{
				break;
			}

			$level = $next;
		}

		return $out;
	}

	/** Похоже ли на base64 (не короче 64 знаков и только алфавит base64). */
	public static function looks_like_base64($value)
	{
		$value = trim((string) $value);

		if (strlen($value) < 64)
		{
			return FALSE;
		}

		$value = preg_replace('~\s+~', '', $value);
		$value = preg_replace('~^data:[^;]*;base64,~i', '', $value);

		return (bool) preg_match('~^[A-Za-z0-9+/]+={0,2}$~', $value);
	}

	/** base64 с допуском data:audio/mpeg;base64,… и переносов строк. */
	public static function base64_decode($value)
	{
		$value = trim((string) $value);
		$value = preg_replace('~^data:[^;]*;base64,~i', '', $value);
		$value = preg_replace('~\s+~', '', $value);

		if ($value === '')
		{
			return FALSE;
		}

		return base64_decode($value, TRUE);
	}

	/** Формат по «магическим» байтам файла: mp3, wav, flac, ogg, aac или NULL. */
	public static function sniff_format($bytes)
	{
		$bytes = (string) $bytes;
		$head  = substr($bytes, 0, 12);

		if ($head === '' OR strlen($bytes) < 4)
		{
			return NULL;
		}

		if (substr($head, 0, 3) === 'ID3')
		{
			return 'mp3';
		}

		if (substr($head, 0, 4) === 'RIFF' AND substr($head, 8, 4) === 'WAVE')
		{
			return 'wav';
		}

		if (substr($head, 0, 4) === 'fLaC')
		{
			return 'flac';
		}

		if (substr($head, 0, 4) === 'OggS')
		{
			// Ogg — контейнер; внутри обычно Opus, для проигрывания всё равно.
			return strpos(substr($bytes, 0, 512), 'OpusHead') !== FALSE ? 'opus' : 'ogg';
		}

		if (substr($head, 4, 4) === 'ftyp')
		{
			return 'aac';
		}

		$byte = ord($head[0]);

		if ($byte === 0xFF AND (ord($head[1]) & 0xE0) === 0xE0)
		{
			return 'mp3';
		}

		return NULL;
	}

	/** Формат из расширения в ссылке на файл (или пусто). */
	public static function format_from_url($url)
	{
		$path = (string) parse_url((string) $url, PHP_URL_PATH);
		$ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

		foreach (self::format_map() as $format => $info)
		{
			if ($ext === (string) Arr::get($info, 'ext') OR $ext === $format)
			{
				return $format;
			}
		}

		return '';
	}

	// ---------------------------------------------------------------- тексты

	/** Текст в запрос: переводы строк и переносы сохраняем, мусор убираем. */
	public static function clean_text($text)
	{
		$text = (string) $text;
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = preg_replace('~[ \t]+~', ' ', $text);
		$text = preg_replace('~\n{3,}~', "\n\n", $text);
		$text = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $text);

		return trim($text);
	}

	/** Первая строка текста — подпись в журнале. */
	public static function title_from_text($text, $len = 60)
	{
		$text = trim((string) $text);
		$text = preg_replace('~[#*`>_\-\[\]]+~', ' ', $text);
		$text = preg_replace('~\s+~', ' ', $text);

		return UTF8::substr(trim($text), 0, $len);
	}

	/** Начало текста для журнала (одной строкой). */
	public static function excerpt($text, $len = 180)
	{
		$text = preg_replace('~\s+~', ' ', self::clean_text($text));

		return UTF8::substr($text, 0, $len);
	}

	/** Скорость в границах конфига (по умолчанию 0.5–2.0). */
	public static function clamp_speed($speed)
	{
		$min = (float) self::limits('speed_min', 0.5);
		$max = (float) self::limits('speed_max', 2.0);
		$val = (float) $speed;

		if ($val < $min) $val = $min;
		if ($val > $max) $val = $max;

		return round($val, 2);
	}

	/** Формат: только из разрешённых моделью (иначе mp3/первый известный). */
	public static function pick_format($format, array $allowed = NULL)
	{
		$format  = strtolower(trim((string) $format));
		$allowed = $allowed === NULL ? array_keys(self::format_map()) : array_values($allowed);

		if ($format !== '' AND in_array($format, $allowed) AND isset(self::format_map()[$format]))
		{
			return $format;
		}

		foreach (['mp3', 'wav', 'opus', 'flac', 'aac', 'pcm'] as $fallback)
		{
			if (in_array($fallback, $allowed))
			{
				return $fallback;
			}
		}

		return 'mp3';
	}

	/** Расширение файла для формата. */
	public static function format_ext($format)
	{
		return (string) Arr::get(Arr::get(self::format_map(), $format, []), 'ext', (string) $format);
	}

	/** MIME для формата (для заголовка Content-Type при отдаче файла). */
	public static function media_type($format)
	{
		$info = Arr::get(self::format_map(), $format, []);

		return (string) Arr::get($info, 'mime', 'application/octet-stream');
	}

	/**
	 * Значение Content-Disposition для файла озвучки.
	 *
	 * Имя из текста может быть русским, поэтому отдаём два варианта:
	 * латиницей (старые браузеры и почтовые клиенты) и filename* (RFC 5987).
	 */
	public static function content_disposition(array $row, $download = FALSE)
	{
		$ext  = self::format_ext((string) Arr::get($row, 'format', 'mp3'));
		$code = (string) Arr::get($row, 'code', 'voice');
		$base = self::title_from_text((string) Arr::get($row, 'title', ''), 40);

		$ascii = UTF8::transliterate_to_ascii($base, -1);
		$ascii = strtolower(trim(preg_replace('~[^A-Za-z0-9]+~', '-', $ascii), '-'));

		if ($ascii === '')
		{
			$ascii = 'voice-'.$code;
		}

		return ($download ? 'attachment' : 'inline')
			.'; filename="'.UTF8::substr($ascii, 0, 60).'.'.$ext.'"'
			."; filename*=UTF-8''".rawurlencode($base.'.'.$ext);
	}

	/** Играет ли формат в браузере (pcm — нет, там нет контейнера). */
	public static function is_playable($format)
	{
		return (bool) Arr::get(Arr::get(self::format_map(), $format, []), 'playable', FALSE);
	}

	/**
	 * Ссылки на образцы голоса (input_references).
	 *
	 * v1 принимает type=input_audio|text|image_url; с сайта пока ходят только
	 * текстовые подсказки и готовые аудиоссылки — файлы мы не загружаем,
	 * чтобы не тащить multipart через внешний API.
	 */
	public static function clean_references(array $references)
	{
		$out  = [];
		$max  = max(0, (int) self::limits('max_references', 3));

		foreach ($references as $item)
		{
			if (count($out) >= $max)
			{
				break;
			}

			if (is_string($item))
			{
				// Ссылку на файл отдаём как образец звука, остальное — текстом.
				$item = preg_match('~^https?://~i', $item)
					? ['url' => $item]
					: ['type' => 'text', 'text' => $item];
			}

			if ( ! is_array($item))
			{
				continue;
			}

			$url = trim((string) Arr::get($item, 'url', ''));

			if ($url !== '' AND preg_match('~^https?://~i', $url))
			{
				$out[] = [
					'type'        => 'input_audio',
					'input_audio' => ['url' => $url, 'format' => strtolower(trim((string) Arr::get($item, 'format', 'mp3')))],
				];

				continue;
			}

			$text = trim((string) Arr::get($item, 'text', ''));

			if ($text !== '')
			{
				$out[] = ['type' => 'text', 'text' => UTF8::substr($text, 0, 500)];
			}
		}

		return $out;
	}

	// --------------------------------------------------------------- журнал

	/** Одна озвучка по коду. */
	public static function speech($code)
	{
		$code = trim((string) $code);

		if ($code === '' OR preg_match('~^[A-Za-z0-9]{8,24}$~', $code) !== 1)
		{
			return NULL;
		}

		// deleted_at IS NULL: удалённая озвучка перестаёт открываться по коду
		// (и файла на диске к этому моменту уже нет).
		return self::_one('SELECT * FROM nj_665_speeches
			WHERE code = '.self::_q($code).' AND deleted_at IS NULL');
	}

	/** Путь к файлу озвучки (по строке журнала). */
	public static function file_path(array $row)
	{
		return self::storage_dir().DIRECTORY_SEPARATOR
			.basename((string) Arr::get($row, 'code')).'.'.self::format_ext((string) Arr::get($row, 'format', 'mp3'));
	}

	/** Последние озвучки пользователя (или этого браузера, если он гость). */
	public static function for_user($user_id, $uid = '', $limit = NULL)
	{
		$limit = (int) ($limit !== NULL ? $limit : Arr::get(self::config(), 'history_limit', 20));
		$limit = min(50, max(1, $limit));

		$where = [];

		if ((int) $user_id > 0)
		{
			$where[] = 'user_id = '.(int) $user_id;
		}

		$uid = trim((string) $uid);

		if ($uid !== '')
		{
			$where[] = "visitor_uid = ".self::_q(mb_substr($uid, 0, 40));
		}

		if ( ! $where)
		{
			return [];
		}

		return self::_all('SELECT code, model, voice, format, speed, language, instructions,
				chars, bytes, title, excerpt, status, error, plays, downloads, created_at
			FROM nj_665_speeches
			WHERE ('.implode(' OR ', $where).') AND deleted_at IS NULL
			ORDER BY id DESC
			LIMIT '.$limit);
	}

	/**
	 * Принадлежит ли озвучка этому посетителю.
	 *
	 * Чистая функция (ни БД, ни сети) — удаление проверяет ею право, поэтому
	 * проверка обязана быть тестируемой: чужие коды угадывать не надо, но
	 * список в истории собирается по visitor_uid, а значит по коду можно
	 * попробовать удалить чужое.
	 *
	 * Своё — это либо строка с этим user_id, либо записанная на этот браузер
	 * (visitor_uid), когда синтез делал гость.
	 */
	public static function owns(array $row, $user_id, $uid)
	{
		$user_id = (int) $user_id;
		$uid     = trim((string) $uid);

		if ($user_id > 0 AND (int) Arr::get($row, 'user_id', 0) === $user_id)
		{
			return TRUE;
		}

		return $uid !== '' AND (string) Arr::get($row, 'visitor_uid', '') === $uid;
	}

	/**
	 * Удалить озвучку: строку помечаем (deleted_at), файл стираем с диска.
	 *
	 * Почему так, а не DELETE: помеченная строка остаётся в журнале — по ней
	 * считаем расход и видно, что человек озвучивал (ровно как удаление
	 * детали в nj643). А вот файл держать нечего: место он занимает, а
	 * восстановить озвучку без внешнего API всё равно нельзя.
	 *
	 * @return array ['ok'=>TRUE, 'code'=>…, 'file'=>'gone|absent|failed'] | ['ok'=>FALSE, 'error'=>…]
	 */
	public static function delete($code, $user_id, $uid)
	{
		try
		{
			$row = self::speech($code);
		}
		catch (Exception $e)
		{
			// База недоступна — это не повод ронять страницу 500-й: человек
			// должен увидеть сообщение, а не «что-то пошло не так».
			self::_log(Log::ERROR, 'удаление: журнал недоступен: :error', [':error' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Журнал озвучек недоступен, попробуйте позже.'];
		}

		if ($row === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Озвучка не найдена или уже удалена.'];
		}

		if ( ! self::owns($row, $user_id, $uid))
		{
			return ['ok' => FALSE, 'error' => 'Это не ваша озвучка.'];
		}

		try
		{
			// Помечаем первой: если обновить строку не выйдет, файл останется
			// цел и озвучка продолжит играть, а не превратится в битую ссылку.
			self::_exec('UPDATE nj_665_speeches
				SET deleted_at = now()
				WHERE id = '.(int) Arr::get($row, 'id', 0).' AND deleted_at IS NULL');
		}
		catch (Exception $e)
		{
			self::_log(Log::ERROR, 'удаление не записано: :error', [':error' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Не удалось удалить озвучку, попробуйте ещё раз.'];
		}

		$file = self::file_path($row);
		$gone = 'absent';

		if (is_file($file))
		{
			$gone = @unlink($file) ? 'gone' : 'failed';

			if ($gone === 'failed')
			{
				self::_log(Log::WARNING, 'файл озвучки не удалился: :file', [':file' => $file]);
			}
		}

		return [
			'ok'   => TRUE,
			'code' => (string) Arr::get($row, 'code'),
			'file' => $gone,
		];
	}

	/** Отметить проигрывание или скачивание (счётчики в журнале). */
	public static function touch($code, $field = 'plays')
	{
		$field = $field === 'downloads' ? 'downloads' : 'plays';

		try
		{
			self::_exec('UPDATE nj_665_speeches
				SET '.$field.' = '.$field.' + 1, last_access_at = now()
				WHERE code = '.self::_q(trim((string) $code)));
		}
		catch (Exception $e)
		{
			// Счётчик — не повод ронять отдачу файла.
		}

		return TRUE;
	}

	/**
	 * Уборка: файлы и записи старше ttl_days.
	 *
	 * Дешёвая и ограниченная (sweep_limit за раз) — вызывается на каждом
	 * синтезе и из nj100/cleanup по контракту манифеста.
	 */
	public static function sweep($limit = NULL)
	{
		$ttl   = max(1, (int) Arr::get(self::config(), 'ttl_days', 30));
		$limit = (int) ($limit !== NULL ? $limit : Arr::get(self::config(), 'sweep_limit', 20));

		if ($limit < 1)
		{
			return 0;
		}

		// Удалённые вручную строки не ждут общий срок: файла у них нет, а в
		// истории они не показываются — держим их сутки (след для разбора),
		// потом стираем совсем.
		$rows = self::_all('SELECT id, code, format FROM nj_665_speeches
			WHERE (deleted_at IS NOT NULL AND deleted_at < now() - interval \'1 day\')
				OR created_at < now() - interval \''.$ttl.' days\'
			ORDER BY id ASC LIMIT '.$limit);

		$n = 0;

		foreach ($rows as $row)
		{
			$file = self::file_path($row);

			if (is_file($file))
			{
				@unlink($file);
			}

			self::_exec('DELETE FROM nj_665_speeches WHERE id = '.(int) Arr::get($row, 'id', 0));

			$n++;
		}

		return $n;
	}

	// ------------------------------------------------------------- limiter

	public static function rate_ok($ip = NULL)
	{
		if ( ! class_exists('Service_Nj100_Ratelimit'))
		{
			return TRUE;
		}

		$rate = (array) Arr::get(self::config(), 'rate', []);
		$ip   = $ip !== NULL ? (string) $ip : Service_Nj100_Ratelimit::client_ip();

		$counts = Service_Nj100_Ratelimit::window_counts('nj_665_speeches', $ip, 'client_ip');

		return Service_Nj100_Ratelimit::allowed($counts,
			(int) Arr::get($rate, 'per_minute', 5),
			(int) Arr::get($rate, 'per_hour', 30));
	}

	public static function rate_text()
	{
		$rate = (array) Arr::get(self::config(), 'rate', []);

		return class_exists('Service_Nj100_Ratelimit')
			? Service_Nj100_Ratelimit::limit_text((int) Arr::get($rate, 'per_minute', 5),
				(int) Arr::get($rate, 'per_hour', 30), 'озвучек')
			: '';
	}

	// --------------------------------------------------------------- коды

	/** Код озвучки: короткий, без похожих друг на друга знаков (0/O, 1/l/I). */
	public static function code_new($len = 12)
	{
		$alphabet = '23456789abcdefghijkmnpqrstuvwxyz';
		$code     = '';

		for ($i = 0; $i < (int) $len; $i++)
		{
			$code .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
		}

		return $code;
	}

	/** Каталог хранения файлов: public_html/storage/nj665. */
	public static function storage_dir()
	{
		return DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj665';
	}

	// ------------------------------------------------------------------ сеть

	/**
	 * Один запрос к внешнему API.
	 *
	 * @return array ['status'=>int, 'body'=>string, 'content_type'=>string, 'error'=>string]
	 */
	protected static function _http($method, $path, $body = NULL, array $opt = [])
	{
		$out = ['status' => 0, 'body' => '', 'content_type' => '', 'error' => ''];

		if ( ! function_exists('curl_init'))
		{
			$out['error'] = 'на сервере нет curl';

			return $out;
		}

		$base = self::api_base();

		if ($base === '')
		{
			$out['error'] = 'не задан NJ665_API_URL';

			return $out;
		}

		$url     = $base.'/'.ltrim((string) $path, '/');
		$key     = self::api_key();
		$timeout = self::timeout((bool) Arr::get($opt, 'short', FALSE));

		$headers = ['Accept: */*'];

		if (Arr::get($opt, 'with_key', TRUE) AND $key !== '')
		{
			$headers[] = 'Authorization: Bearer '.$key;
		}

		$payload = '';

		if ($body !== NULL)
		{
			$payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			if ($payload === FALSE)
			{
				$out['error'] = 'не удалось собрать запрос';

				return $out;
			}

			$headers[] = 'Content-Type: application/json';
			$headers[] = 'Content-Length: '.strlen($payload);
		}

		$ch = curl_init($url);

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_FOLLOWLOCATION => TRUE,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_USERAGENT      => 'n2j.ru/voice (nj665)',
			CURLOPT_HTTPHEADER     => $headers,
		]);

		if ($body !== NULL)
		{
			curl_setopt($ch, CURLOPT_POST, TRUE);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		}

		$response     = curl_exec($ch);
		$errno        = curl_errno($ch);
		$error        = curl_error($ch);
		$status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$content_type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

		curl_close($ch);

		if ($errno)
		{
			$out['error'] = $error !== '' ? $error : 'ошибка curl #'.$errno;

			return $out;
		}

		$out['status']       = $status;
		$out['body']         = (string) $response;
		$out['content_type'] = strtolower(trim((string) preg_replace('~;.*$~', '', $content_type)));

		return $out;
	}

	/** Скачать файл по ссылке из ответа (только http/https, короткий таймаут). */
	public static function fetch_url($url)
	{
		$out = ['body' => '', 'error' => ''];

		if ( ! preg_match('~^https?://~i', (string) $url))
		{
			$out['error'] = 'ссылка не на http(s)';

			return $out;
		}

		if ( ! function_exists('curl_init'))
		{
			$out['error'] = 'на сервере нет curl';

			return $out;
		}

		$ch = curl_init((string) $url);

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT        => max(10, self::timeout()),
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_FOLLOWLOCATION => TRUE,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_USERAGENT      => 'n2j.ru/voice (nj665)',
		]);

		$body  = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if ($errno)
		{
			$out['error'] = $error !== '' ? $error : 'ошибка curl #'.$errno;

			return $out;
		}

		if ($code < 200 OR $code >= 300)
		{
			$out['error'] = 'HTTP '.$code;

			return $out;
		}

		$out['body'] = (string) $body;

		return $out;
	}

	/** Текст ошибки из ответа API: detail/message/error, иначе «код + обрубок». */
	protected static function _error_text(array $res, $what)
	{
		$data = json_decode((string) Arr::get($res, 'body', ''), TRUE);
		$text = '';

		if (is_array($data))
		{
			foreach (['detail', 'message', 'error', 'msg'] as $key)
			{
				$value = Arr::get($data, $key);

				if (is_string($value) AND trim($value) !== '')
				{
					$text = trim($value);

					break;
				}

				if (is_array($value))
				{
					$text = UTF8::substr(json_encode($value, JSON_UNESCAPED_UNICODE), 0, 200);

					break;
				}
			}
		}

		if ($text === '')
		{
			$text = UTF8::substr(trim((string) Arr::get($res, 'body', '')), 0, 160);
		}

		if ($text === '')
		{
			$text = 'HTTP '.(int) Arr::get($res, 'status', 0);
		}

		return 'Сервис озвучивания: '.$what.' ('.$text.')';
	}

	/** Записать неудачу в журнал и в лог, вернуть ответ об ошибке. */
	protected static function _fail(array $opt, $model, $voice, $format, $language,
		$instructions, $text, $error)
	{
		self::_log(Log::ERROR, 'синтез не удался: :error', [':error' => $error]);

		try
		{
			$uid = class_exists('Visitor') ? (string) Visitor::uid() : '';
			$ip  = class_exists('Service_Nj100_Ratelimit') ? (string) Service_Nj100_Ratelimit::client_ip() : '';

			self::_exec('INSERT INTO nj_665_speeches
					(code, user_id, visitor_uid, client_ip, model, voice, format, speed, language,
					 instructions, chars, excerpt, status, error)
				VALUES ('
				.self::_q(self::code_new()).', '
				.(int) Arr::get($opt, 'user_id', 0).', '
				.self::_q(mb_substr($uid, 0, 40)).', '
				.self::_q(mb_substr($ip, 0, 64)).', '
				.self::_q(mb_substr((string) $model, 0, 120)).', '
				.self::_q(mb_substr((string) $voice, 0, 80)).', '
				.self::_q(mb_substr((string) $format, 0, 8)).', '
				.self::clamp_speed((float) Arr::get($opt, 'speed', 1.0)).', '
				.self::_q(mb_substr((string) $language, 0, 16)).', '
				.self::_q(mb_substr((string) $instructions, 0, 500)).', '
				.UTF8::strlen($text).', '
				.self::_q(mb_substr(self::excerpt($text), 0, 400)).', '
				.self::_q('error').', '
				.self::_q(mb_substr($error, 0, 500)).')');
		}
		catch (Exception $e)
		{
			// Журнал неудач — справочный: если БД недоступна, ошибку всё
			// равно показали пользователю и записали в лог.
		}

		return ['ok' => FALSE, 'error' => $error];
	}

	protected static function _log($level, $message, array $params = [])
	{
		if ( ! class_exists('Log_Plain') OR ! is_object(Kohana::$log))
		{
			return;
		}

		Log_Plain::event($level, 'NJ665 '.$message, $params);
	}

	// ------------------------------------------------------------------- БД

	/** Кеш ответов внешнего API: NULL, если пусто или устарело. */
	protected static function _catalog_get($key, $ttl_min = NULL)
	{
		try
		{
			$row = self::_one('SELECT payload, updated_at FROM nj_665_catalog WHERE key = '.self::_q((string) $key));
		}
		catch (Exception $e)
		{
			return NULL;
		}

		if ( ! $row)
		{
			return NULL;
		}

		$ttl = $ttl_min === NULL ? (int) Arr::get(self::config(), 'catalog_ttl', 60) : (int) $ttl_min;

		if ($ttl > 0)
		{
			$updated = strtotime((string) Arr::get($row, 'updated_at', ''));

			if ($updated > 0 AND time() - $updated > $ttl * 60)
			{
				return NULL;
			}
		}

		$payload = json_decode((string) Arr::get($row, 'payload', ''), TRUE);

		return is_array($payload) ? $payload : NULL;
	}

	protected static function _catalog_set($key, array $payload)
	{
		try
		{
			self::_exec('INSERT INTO nj_665_catalog (key, payload, updated_at)
				VALUES ('.self::_q((string) $key).', '
				.self::_q(json_encode($payload, JSON_UNESCAPED_UNICODE)).'::jsonb, now())
				ON CONFLICT (key) DO UPDATE SET payload = EXCLUDED.payload, updated_at = now()');
		}
		catch (Exception $e)
		{
			// Кеш — не обязательная часть: если таблицы нет (миграция не
			// накатилась) или БД недоступна, работаем без кеша.
		}

		return TRUE;
	}

	protected static function _db()
	{
		return Database::instance();
	}

	protected static function _q($value)
	{
		return self::_db()->quote($value);
	}

	protected static function _one($sql)
	{
		$row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	protected static function _all($sql)
	{
		$rows = self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();

		return $rows ? array_map(function ($row) { return (array) $row; }, $rows) : [];
	}

	protected static function _exec($sql)
	{
		return self::_db()->query(Database::INSERT, $sql, FALSE);
	}
}
