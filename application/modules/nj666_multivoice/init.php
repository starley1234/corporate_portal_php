<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj666_multivoice («Многоголосая озвучка»).
 *
 *   GET  /multivoice                     мои книги и форма новой;
 *   POST /multivoice                     создать книгу (без JS);
 *   GET  /multivoice/b/<code>            рабочее место книги;
 *   GET  /multivoice/b/<code>/k/<no>     просмотр и правка блока;
 *   GET  /multivoice/f/<code>/<no>       файл блока;
 *   GET  /multivoice/p/<hash>            превью голоса (кэш);
 *   GET  /multivoice/zip/<code>          архив: project/ и production/;
 *   POST /multivoice/api/*               JSON API страницы (аккаунт + CSRF);
 *   POST /multivoice/w/*                 приёмная python-воркера (токен).
 *
 * Разбор книги и озвучка идут в воркере: вызов модели на книгу — десятки
 * минут, веб-процесс столько ждать не может. Сайт держит данные и файлы;
 * воркер — модель, поиск, порядок блоков и длинный синтез OmniVoice.
 *
 * Код книги — только строчные буквы и цифры без 0/1 (алфавит code_new()):
 * сегмент Kohana по умолчанию [^/.,;?\n]++ всё равно задаём своим regex,
 * чтобы «..» в адресе не дошёл до файловой системы.
 */

Route::set('nj666_page', 'multivoice')
	->defaults([
		'controller' => 'Nj666_Multivoice',
		'action'     => 'index',
	]);

Route::set('nj666_block', 'multivoice/b/<code>/k/<no>', [
	'code' => '[23456789a-z]{8,24}',
	'no'   => '\d{1,4}',
])
	->defaults([
		'controller' => 'Nj666_Multivoice',
		'action'     => 'block',
	]);

Route::set('nj666_book', 'multivoice/b/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults([
		'controller' => 'Nj666_Multivoice',
		'action'     => 'book',
	]);

Route::set('nj666_preview', 'multivoice/p/<hash>', ['hash' => '[a-f0-9]{40}'])
	->defaults([
		'controller' => 'Nj666_Multivoice',
		'action'     => 'preview',
	]);

Route::set('nj666_file', 'multivoice/f/<code>/<no>', [
	'code' => '[23456789a-z]{8,24}',
	'no'   => '\d{1,4}',
])
	->defaults([
		'controller' => 'Nj666_Multivoice',
		'action'     => 'file',
	]);

Route::set('nj666_zip', 'multivoice/zip/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults([
		'controller' => 'Nj666_Multivoice',
		'action'     => 'zip',
	]);

// --- JSON API страницы ---------------------------------------------------------
Route::set('nj666_api_create', 'multivoice/api/create')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'create']);

Route::set('nj666_api_plan', 'multivoice/api/plan')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'plan']);

Route::set('nj666_api_voice', 'multivoice/api/voice')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'voice']);

Route::set('nj666_api_settings', 'multivoice/api/settings')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'settings']);

Route::set('nj666_api_character', 'multivoice/api/character')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'character']);

Route::set('nj666_api_character_del', 'multivoice/api/character_del')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'character_del']);

Route::set('nj666_api_voice_preview', 'multivoice/api/voice_preview')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'voice_preview']);

Route::set('nj666_api_block', 'multivoice/api/block')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'block']);

Route::set('nj666_api_block_voice', 'multivoice/api/block_voice')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'block_voice']);

Route::set('nj666_api_status', 'multivoice/api/status')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'status']);

Route::set('nj666_api_voices', 'multivoice/api/voices')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'voices']);

Route::set('nj666_api_delete', 'multivoice/api/delete')
	->defaults(['controller' => 'Nj666_Api', 'action' => 'delete']);

// --- Приёмная воркера (токен X-Worker-Token) ------------------------------------
Route::set('nj666_w_context', 'multivoice/w/context')
	->defaults(['controller' => 'Nj666_Worker', 'action' => 'context']);

Route::set('nj666_w_characters', 'multivoice/w/characters')
	->defaults(['controller' => 'Nj666_Worker', 'action' => 'characters']);

Route::set('nj666_w_blocks', 'multivoice/w/blocks')
	->defaults(['controller' => 'Nj666_Worker', 'action' => 'blocks']);

Route::set('nj666_w_block', 'multivoice/w/block')
	->defaults(['controller' => 'Nj666_Worker', 'action' => 'block']);

Route::set('nj666_w_audio', 'multivoice/w/audio')
	->defaults(['controller' => 'Nj666_Worker', 'action' => 'audio']);

Route::set('nj666_w_synth', 'multivoice/w/synth')
	->defaults(['controller' => 'Nj666_Worker', 'action' => 'synth']);

Route::set('nj666_w_stage', 'multivoice/w/stage')
	->defaults(['controller' => 'Nj666_Worker', 'action' => 'stage']);

Route::set('nj666_w_done', 'multivoice/w/done')
	->defaults(['controller' => 'Nj666_Worker', 'action' => 'done']);

Route::set('nj666_w_fail', 'multivoice/w/fail')
	->defaults(['controller' => 'Nj666_Worker', 'action' => 'fail']);

Route::set('nj666_w_tick', 'multivoice/w/tick')
	->defaults(['controller' => 'Nj666_Worker', 'action' => 'tick']);

// --- Зависимости -----------------------------------------------------------------
// erp100_base (шаблон страницы, Gate), nj120_jobs (очередь и приёмная воркера),
// nj100_platform (Visitor, limiter, чистка), nj110_catalog (манифест),
// nj111_migrations, nj665_voice (каталог голосов, превью, разбор ответа).
// Длинный синтез — воркер (NJ665_*). Своих таблиц три: книги, персонажи, блоки.
