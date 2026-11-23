<?php defined('SYSPATH') or die('No direct script access.');

// Внешний машинный контракт: потребитель → ключ prx150 → этот маршрут.
Route::set('prx450_document_ocr_api', 'api/v1/document-ocr/parse')
    ->defaults(['controller' => 'Prx450_Api', 'action' => 'parse']);

// Админская страница проверки: тот же сервис, но сессия и CSRF.
Route::set('prx450_document_ocr', 'prx450_document_ocr')
    ->defaults(['controller' => 'Prx450_Page', 'action' => 'index']);
