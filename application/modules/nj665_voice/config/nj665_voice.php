<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг NJ-665 «Озвучивание текста» (nj665_voice).
 *
 * Секреты и адрес внешнего API — только через .env (NJ665_*): ключ не должен
 * лежать в репозитории. Здесь то, что читает сайт: таймауты, лимиты, значения
 * по умолчанию и справочник форматов.
 *
 * Значения по умолчанию подобраны под OmniVoice v1 (POST /api/v1/audio/speech):
 * «сырой» pcm браузер не играет, поэтому по умолчанию просим mp3, если модель
 * его объявляет (см. Service_Nj665_Voice::default_format()).
 */
return [

	// Внешний API OmniVoice.
	'api' => [
		// Базовый адрес без слэша в конце: https://voice.example.ru
		'base_url' => (string) getenv('NJ665_API_URL'),
		// Ключ идёт в заголовке Authorization: Bearer … Пусто — сервис выключен.
		'key'      => (string) getenv('NJ665_API_KEY'),
		// Секунд на один запрос синтеза и на служебные запросы (модели, ключ).
		'timeout'  => (int) (getenv('NJ665_TIMEOUT') ?: 120),
		'short_timeout' => (int) (getenv('NJ665_SHORT_TIMEOUT') ?: 15),
	],

	// Модель по умолчанию. Пусто — берётся первая модель из /api/v1/models,
	// у которой есть supported_voices (или падает на default_model_fallback).
	'model'                 => (string) getenv('NJ665_MODEL'),
	'model_fallback'        => 'k2-fsa/omnivoice',

	// Распознавание речи (POST /api/v1/audio/transcriptions). Нужно голосовому
	// разговору (/talk, nj677): браузер присылает запись, сайт просит текст.
	// Модель и таймаут живут здесь, потому что клиент OmniVoice один на проект.
	'asr_model'             => ((string) getenv('NJ665_ASR_MODEL') !== '' ? (string) getenv('NJ665_ASR_MODEL') : 'whisper-1'),
	'asr_timeout'           => (int) (getenv('NJ665_ASR_TIMEOUT') ?: 60),
	'asr_path'              => ((string) getenv('NJ665_ASR_PATH') !== '' ? (string) getenv('NJ665_ASR_PATH') : '/api/v1/audio/transcriptions'),

	// Голос по умолчанию. Пусто — первый голос модели.
	'default_voice'         => (string) getenv('NJ665_VOICE'),

	// Формат по умолчанию (mp3, wav, opus, flac, aac, pcm).
	'default_format'        => ((string) getenv('NJ665_FORMAT') !== '' ? (string) getenv('NJ665_FORMAT') : 'mp3'),

	// Скорость по умолчанию (диапазон OmniVoice: больше 0.25 и не больше 4.0).
	'default_speed'         => (float) (getenv('NJ665_SPEED') ?: 1.0),

	// Язык по умолчанию. Пусто — модель определяет сама.
	'default_language'      => (string) getenv('NJ665_LANGUAGE') ?: '',

	// Лимиты на один запрос.
	'limits' => [
		// Символов текста в одном запросе (дальше — режем с предупреждением).
		'max_chars'         => (int) (getenv('NJ665_MAX_CHARS') ?: 4000),
		// «Как говорить» — инструкции голосу.
		'max_instructions'  => (int) (getenv('NJ665_MAX_INSTRUCTIONS') ?: 500),
		'speed_min'         => 0.5,
		'speed_max'         => 2.0,
		// Сколько ссылок на образцы голоса (input_references) можно приложить.
		'max_references'    => 3,
	],

	// Частотный limiter: синтез дорогой (время GPU), поэтому лимит ниже,
	// чем у «бумажных» сервисов. Считается по IP в таблице nj_665_speeches.
	'rate' => [
		'per_minute' => (int) (getenv('NJ665_RATE_PER_MINUTE') ?: 5),
		'per_hour'   => (int) (getenv('NJ665_RATE_PER_HOUR') ?: 30),
	],

	// Хранение: сколько дней держать файлы и записи журнала.
	'ttl_days'     => (int) (getenv('NJ665_TTL_DAYS') ?: 30),
	'sweep_limit'  => (int) (getenv('NJ665_SWEEP_LIMIT') ?: 20),

	// Сколько минут держать в памяти каталог моделей/голосов (/api/v1/models).
	'catalog_ttl'  => (int) (getenv('NJ665_CATALOG_TTL') ?: 60),

	// Справочник форматов: расширение, MIME, играет ли браузер.
	// Ключ — как в supported_formats модели.
	'formats' => [
		'mp3'  => ['ext' => 'mp3',  'mime' => 'audio/mpeg', 'playable' => TRUE,  'title' => 'MP3 — универсальный'],
		'wav'  => ['ext' => 'wav',  'mime' => 'audio/wav',  'playable' => TRUE,  'title' => 'WAV — без потерь'],
		'opus' => ['ext' => 'opus', 'mime' => 'audio/ogg',  'playable' => TRUE,  'title' => 'Opus — компактный'],
		'flac' => ['ext' => 'flac', 'mime' => 'audio/flac', 'playable' => TRUE,  'title' => 'FLAC — сжатие без потерь'],
		'aac'  => ['ext' => 'm4a',  'mime' => 'audio/aac',  'playable' => TRUE,  'title' => 'AAC — для мобильных'],
		'pcm'  => ['ext' => 'pcm',  'mime' => 'application/octet-stream', 'playable' => FALSE, 'title' => 'PCM — сырой поток'],
	],

	// Языки для подсказки модели (код => подпись). Ключи должны совпадать с
	// кодами, которые принимает модель; список можно расширять.
	'languages' => [
		''    => 'Авто (по тексту)',
		'ru'  => 'Русский',
		'en'  => 'Английский',
		'de'  => 'Немецкий',
		'fr'  => 'Французский',
		'es'  => 'Испанский',
		'it'  => 'Итальянский',
		'zh'  => 'Китайский',
		'ja'  => 'Японский',
	],

	// Сколько последних озвучек показывать в истории на странице.
	'history_limit' => 20,
];
