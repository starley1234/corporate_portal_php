<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj602_content («Контент-конвейер»).
 *
 * /content        — лента опубликованных статей (публично, SEO);
 * /content/<slug> — статья: текст, место под иллюстрацию, mermaid, граф;
 * /nj602/admin    — модерация (право nj602.admin);
 * /content/api/*  — API воркера и пиналки (токен воркера):
 *     create   — создать черновик + задачу (дёргает nj603 по расписанию);
 *     progress — признак жизни; item — приём статьи; fail — ошибка.
 */

Route::set('nj602_api', 'content/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj602_Api',
        'action'     => 'status',
    ]);

// Витрина «Рост знаний» — насколько платформа стала умнее (nj560+nj115+nj602)
Route::set('nj602_brain', 'nj602/brain')
    ->defaults([
        'controller' => 'Nj602_Brain',
        'action'     => 'index',
    ]);

Route::set('nj602_admin', 'nj602/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj602_Admin',
        'action'     => 'index',
    ]);

Route::set('nj602_view', 'content/<slug>', ['slug' => '[0-9a-z-]+'])
    ->defaults([
        'controller' => 'Nj602_Content',
        'action'     => 'view',
    ]);

Route::set('nj602_page', 'content')
    ->defaults([
        'controller' => 'Nj602_Content',
        'action'     => 'index',
    ]);

// SEO: карта сайта (статьи + публичные сервисы платформы)
Route::set('nj602_sitemap', 'sitemap.xml')
    ->defaults([
        'controller' => 'Nj602_Api',
        'action'     => 'sitemap',
    ]);
