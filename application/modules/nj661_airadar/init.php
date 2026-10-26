<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj661_airadar («ИИ-радар»).
 *
 * Админские страницы (сессия + право nj661.airadar, у admin — всегда):
 *   /airadar            — дашборд: последний отчёт, живой ход прогона, кнопки;
 *   /airadar/catalog    — каталог ОПУБЛИКОВАННЫХ отчётов: список, поиск, пагинация;
 *   /airadar/report/<id>/publish — снять отчёт с публикации или вернуть (POST, CSRF);
 *   /airadar/items|facts|ideas — материалы, база знаний, борд идей;
 *   /airadar/report/<id> — полный отчёт.
 *
 * API воркера и cron (токен, как nj613/nj605):
 *   /airadar/api/tick         — пиналка расписания (её дёргает cron);
 *   /airadar/api/items        — приём кандидатов (дедуп) + контекст базы;
 *   /airadar/api/analysis     — результаты LLM: факты и идеи в базу знаний;
 *   /airadar/api/report_input — данные для финального LLM-свода;
 *   /airadar/api/report       — сохранение отчёта (+ решение о письме);
 *   /airadar/api/progress     — живой этап прогона;
 *   /airadar/api/finish       — завершение прогона.
 */
Route::set('nj661_api', 'airadar/api(/<action>)')
	->defaults([
		'controller' => 'Nj661_Api',
		'action'     => 'tick',
	]);

// publish — ВЫШЕ report/<id>, иначе «publish» съедается как <id> (тот же
// порядок, что у удаления обзора в трендах)
Route::set('nj661_publish', 'airadar/report/<id>/publish', ['id' => '\d+'])
	->defaults([
		'controller' => 'Nj661_Airadar',
		'action'     => 'publish',
	]);

Route::set('nj661_report', 'airadar/report/<id>', ['id' => '\d+'])
	->defaults([
		'controller' => 'Nj661_Airadar',
		'action'     => 'report',
	]);

Route::set('nj661_page', 'airadar(/<action>)', ['action' => 'index|catalog|items|facts|ideas|progress|run|mail|tick|idea|star'])
	->defaults([
		'controller' => 'Nj661_Airadar',
		'action'     => 'index',
	]);
