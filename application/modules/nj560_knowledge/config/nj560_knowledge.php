<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Настройки централизованной базы знаний (NJ-560).
 */

return [
    // Коллекция векторов в nj115_semantic (комбинированный RAG)
    'collection' => 'nj560_knowledge',

    // Сколько дней считать документ свежим (для точного дедупа и очистки)
    'fresh_days'   => 14,
    'retention_days' => 180,

    // Сколько повторов показывать как «часто встречается» в админке
    'hot_hits' => 3,
];
