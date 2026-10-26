<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты «Сканера безопасности ИИ-моделей» (nj672_aiscan).
 *
 * Порядок важен: `aiscan/api/...` объявлен ДО `aiscan/result/<id>`, иначе
 * слово «api» уехало бы в страницу результата как id (грабля nj671).
 *
 *   GET  /aiscan                    форма: цель, наборы атак, стресс-профиль
 *   GET  /aiscan/simple             простой режим: цель + одна кнопка (умолчания)
 *   GET  /aiscan/guide              справочник: что проверяем, как должно быть, как чинить
 *   POST /aiscan/run                поставить аудит в очередь → на результат
 *   GET  /aiscan/result/<id>        интерактивный отчёт: баллы, OWASP, находки
 *   GET  /aiscan/report/<id>        печатный отчёт для руководителя (PDF из браузера)
 *   GET  /aiscan/api/status?id=N    состояние прогона (живой статус-ряд)
 *   GET  /aiscan/api/export?id=N    машинный отчёт JSON (для CI/архива)
 *   POST /aiscan/api/worker_result  приёмка отчёта от воркера
 */

Route::set('nj672_api', 'aiscan/api(/<action>)')
    ->defaults([
        'controller' => 'Nj672_Api',
        'action'     => 'status',
    ]);

Route::set('nj672_result', 'aiscan/result/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj672_Scan',
        'action'     => 'result',
    ]);

Route::set('nj672_report', 'aiscan/report/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj672_Scan',
        'action'     => 'report',
    ]);

Route::set('nj672_page', 'aiscan(/<action>)')
    ->defaults([
        'controller' => 'Nj672_Scan',
        'action'     => 'index',
    ]);

// Таблица: nj_672_runs (миграция 001). Канал очереди: nj672.scan —
// обработчик workers/n2j_workers/handlers/nj672_aiscan.py, движок —
// workers/n2j_workers/aiscan.py (+ батарея aiscan_battery.py).
