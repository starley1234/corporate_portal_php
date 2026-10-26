<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj646_label («Разбор состава»).
 */

Route::set('nj646_page', 'label')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'index',
    ]);

// Страница одного разбора и «разобрать заново» (POST). Отдельные маршруты —
// до api/`: путь `label/analysis/<id>` не должен попадать в api-контроллер.
Route::set('nj646_analysis', 'label/analysis(/<id>)')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'analysis',
    ]);

// Товар и производитель с этикетки: публичные карточки накопленных данных.
// Ссылки ведут сюда со страницы разбора, из ответа и из админки — поэтому это
// отдельные страницы, а не блок на /label (на них приходят из поиска/онтологии).
Route::set('nj646_product', 'label/product(/<id>)')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'product',
    ]);

Route::set('nj646_producer', 'label/producer(/<id>)')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'producer',
    ]);

// Сравнение двух товаров: /label/compare/<a>/<b>. Без пары (или если у одного из
// товаров нет разбора) страница превращается в выбор «с кем сравнить» ссылками —
// адрес пары сохраняется, JavaScript не нужен.
Route::set('nj646_compare', 'label/compare(/<a>(/<b>))')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'compare',
    ]);

// Публичная страница вещества: /label/ingredient/<slug> — адрес для ссылки
// «что это за E211». Параметров у него нет. В sitemap.xml страницы веществ НЕ
// отдаются: владелец, 03.10.2026 — «Из карты сайта ingredientы убери!».
Route::set('nj646_ingredient', 'label/ingredient(/<slug>)')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'ingredient',
    ]);

// Страницы модуля: база знаний, свои разборы и профиль — отдельно от /label.
// Владелец (03.10.2026): «/label перегружена функционалом… база знаний, мои
// разборы, мой профиль — свои страницы». Вкладок внутри одной страницы нет.
Route::set('nj646_knowledge', 'label/knowledge')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'knowledge',
    ]);

// «Мои разборы»: владелец назвал адрес /label/my_analysis. Прежний
// /label/history остаётся маршрутом-переадресацией (301) — ссылки на него уже
// разошлись по страницам и закладкам.
Route::set('nj646_my_analysis', 'label/my_analysis')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'my_analysis',
    ]);

Route::set('nj646_history', 'label/history')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'history',
    ]);

Route::set('nj646_profile', 'label/profile')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'profile',
    ]);

Route::set('nj646_replay', 'label/replay(/<id>)')
    ->defaults([
        'controller' => 'Nj646_Label',
        'action'     => 'replay',
    ]);

Route::set('nj646_api', 'label/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj646_Api',
        'action'     => 'analyze',
    ]);

Route::set('nj646_admin', 'nj646/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj646_Admin',
        'action'     => 'index',
    ]);

// Таблицы: nj_646_* (миграция 001). Каналы очереди: nj646.label (фото
// этикетки), nj646.study (ИИ-изучение неизвестного ингредиента) —
// обработчик workers/n2j_workers/handlers/nj646_label.py.
