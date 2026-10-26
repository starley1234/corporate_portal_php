<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация nj604_news («Новости сайта»).
 *
 * /news            — лента новостей (публично; релизы дочитываются из git);
 * /news/rss.xml    — RSS (ВЫШЕ /news/<любое>, иначе «rss.xml» съедается);
 * /nj604/admin     — админка (право nj604.admin): ручные новости, скрытие.
 */

Route::set('nj604_rss', 'news/rss.xml')
    ->defaults([
        'controller' => 'Nj604_News',
        'action'     => 'rss',
    ]);

Route::set('nj604_admin', 'nj604/admin')
    ->defaults([
        'controller' => 'Nj604_Admin',
        'action'     => 'index',
    ]);

Route::set('nj604_page', 'news')
    ->defaults([
        'controller' => 'Nj604_News',
        'action'     => 'index',
    ]);
