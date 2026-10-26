<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj611_landing: лендинги групп сервисов.
 *   /s        — индекс групп;
 *   /s/<slug> — посадочная страница группы с быстрым стартом.
 *
 * Два отдельных роута: в общем виде 's(/<slug>)' с action по умолчанию
 * «index» слаг не диспетчерится (action_group недостижим) — /s/<slug>
 * рисовало индекс вместо лендинга.
 */

Route::set('nj611_group', 's/<slug>', ['slug' => '[a-z0-9-]+'])
    ->defaults([
        'controller' => 'Nj611_Landing',
        'action'     => 'group',
    ]);

Route::set('nj611_index', 's')
    ->defaults([
        'controller' => 'Nj611_Landing',
        'action'     => 'index',
    ]);
