<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prl398_vlm_demo — демонстрация VLM на входящем PDF.
 *
 * Это витрина, а не production-сервис: нет таблиц, миграций, уведомлений и
 * worker-каналов. Страницы PDF рендерит браузер, PHP синхронно вызывает
 * LLM-модуль. Модуль можно удалить целиком, не затрагивая prl390–prl394.
 */
return [
    'id' => 398,
    'slug' => 'prl398_vlm_demo',
    'title' => 'VLM-разбор письма (демо)',
    'short' => 'PDF → страницы → vision-модель: печати, таблицы и бланки без OCR',
    'icon' => 'fas fa-eye',
    'href' => '/vlm-demo',
    'group' => 'Документооборот',
    'sort' => 398,
    'status' => 'beta',
    'audience' => 'auth',
    'acl' => 'prl398.view',
    'keywords' => 'vlm vision демо pdf письмо изображение печать подпись разбор',
    'api' => ['/vlm-demo/analyze'],
];
