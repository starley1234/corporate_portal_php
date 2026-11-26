<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx113_favorites — «Избранное · управление»: страница /favorites.
 *
 * Логика закрепления (звёздочка, API set) живёт в prx110_catalog — владелец
 * таблицы prx_110_favorites. Здесь только управление: ручной порядок (sort),
 * категории для левой панели, переименование и заметки.
 */

return [
    'audience' => 'auth',
    'acl'      => 'prx113.view',
    'status'   => 'stable',
    'id'       => 113,
    'slug'     => 'prx113_favorites',
    'title'    => 'Избранное · управление',
    'short'    => 'Порядок, категории, переименование и заметки для закреплённых звёздочкой сервисов',
    'icon'     => 'fas fa-star-half-stroke',
    'href'     => '/favorites',
    'group'    => 'Аккаунт',
    'sort'     => 550,
    'keywords' => 'избранное закладки звёздочка закрепить порядок категория переименовать сортировка favorites',
    'api'      => [],
    'auth'     => TRUE,
];
