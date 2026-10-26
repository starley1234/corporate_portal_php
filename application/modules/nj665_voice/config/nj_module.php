<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Озвучивание текста» (nj665_voice) для nj110_catalog.
 *
 * Публичная страница /voice: текст → аудиофайл (mp3/wav/opus/flac/aac),
 * плеер в браузере, скачивание и история озвучек. Сам синтез требует аккаунта:
 * внешний API считает время GPU, поэтому гость видит плашку входа.
 * Работает на OmniVoice API v1 (POST /api/v1/audio/speech).
 */
return [
	'id'        => 665,
	'slug'      => 'voice',
	'title'     => 'Озвучивание текста',
	'short'     => 'Текст в голос: выбор модели, голоса, скорости и формата, инструкция «как говорить», плеер и скачивание файла',
	'icon'      => 'fas fa-volume-high',
	'href'      => '/voice',
	'group'     => 'Текст',
	'sort'      => 665,
	'pin'       => TRUE,
	'auth'      => FALSE,
	'keywords'  => 'озвучить текст text to speech tts голос речь mp3 wav opus синтез речи аудио из текста OmniVoice',
	'api'       => ['/voice/api/voices', '/voice/api/synthesize', '/voice/api/history'],
	'card_view' => 'nj665/card',

	// --- Пятый контракт: чистка данных (/nj100/cleanup) -----------------------
	// Строки журнала старше 30 дней. Сами файлы (storage/nj665) убирает
	// Service_Nj665_Voice::sweep() — он же стирает файлы без строки журнала.
	'cleanup' => [
		'nj_665_speeches' => [
			'label'      => 'nj665 · журнал озвучек текста',
			'note'       => 'Записи старше 30 дней. Аудиофайлы в storage/nj665 выметает sweep() модуля.',
			'days'       => 30,
			'count_sql'  => 'SELECT count(*) FROM nj_665_speeches WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_665_speeches WHERE id IN (
								SELECT id FROM nj_665_speeches WHERE created_at < :old LIMIT :limit)',
		],
	],
];
