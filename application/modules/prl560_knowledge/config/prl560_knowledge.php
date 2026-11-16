<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Настройки централизованной базы знаний (PRL-560).
 */

return [
    // Коллекция векторов в prl115_semantic (комбинированный RAG)
    'collection' => 'prl560_knowledge',

    // Сколько дней считать документ свежим (для точного дедупа и очистки)
    'fresh_days'   => 14,
    'retention_days' => 180,

    // Сколько повторов показывать как «часто встречается» в админке
    'hot_hits' => 3,
];
