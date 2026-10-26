<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj665_voice («Озвучивание текста»).
 *
 *   GET  /voice                 страница сервиса (форма, плеер, история);
 *   POST /voice                 то же без JS;
 *   GET  /voice/f/<code>        аудиофайл озвучки;
 *   GET  /voice/h/<code>        страница одной озвучки (для ссылки);
 *   POST /voice/api/synthesize  синтез (JSON);
 *   GET  /voice/api/voices      справочник моделей, голосов и форматов;
 *   GET  /voice/api/history     последние озвучки посетителя;
 *   GET  /voice/api/key         состояние ключа внешнего API (администратор);
 *   POST /voice/api/delete      удалить свою озвучку (мягко + файл с диска).
 *
 * Внешний контракт — OmniVoice API v1: POST /api/v1/audio/speech,
 * GET /api/v1/models?output_modalities=speech, GET /api/v1/key. Старые
 * /tts/v1/synthesize и /voices не используются (владелец, 09.10.2026).
 *
 * Код озвучки — только строчные буквы и цифры без 0/1 (алфавит code_new()),
 * поэтому маршрутам задан свой regex: сегмент Kohana по умолчанию
 * [^/.,;?\n]++ и точки в имени файла не принимает, но здесь это не мешает —
 * расширение к строке журнала добавляет сервис, а не браузер.
 */

Route::set('nj665_page', 'voice')
	->defaults([
		'controller' => 'Nj665_Voice',
		'action'     => 'index',
	]);

Route::set('nj665_file', 'voice/f/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults([
		'controller' => 'Nj665_Voice',
		'action'     => 'f',
	]);

Route::set('nj665_one', 'voice/h/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults([
		'controller' => 'Nj665_Voice',
		'action'     => 'h',
	]);

Route::set('nj665_api_synthesize', 'voice/api/synthesize')
	->defaults([
		'controller' => 'Nj665_Api',
		'action'     => 'synthesize',
	]);

Route::set('nj665_api_voices', 'voice/api/voices')
	->defaults([
		'controller' => 'Nj665_Api',
		'action'     => 'voices',
	]);

Route::set('nj665_api_history', 'voice/api/history')
	->defaults([
		'controller' => 'Nj665_Api',
		'action'     => 'history',
	]);

Route::set('nj665_api_delete', 'voice/api/delete')
	->defaults([
		'controller' => 'Nj665_Api',
		'action'     => 'delete',
	]);

Route::set('nj665_api_key', 'voice/api/key')
	->defaults([
		'controller' => 'Nj665_Api',
		'action'     => 'key',
	]);

// --- Зависимости --------------------------------------------------------------
// erp100_base (шаблон страницы, Gate), nj100_platform (rate limit, Visitor,
// чистка), nj110_catalog (манифест), nj111_migrations. Очередь и воркеры
// не нужны: синтез — синхронный POST во внешний API, ответ сразу файлом.
