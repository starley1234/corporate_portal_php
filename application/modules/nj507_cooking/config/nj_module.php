<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 507,
    'slug' => 'cooking',
    'title' => 'Кулинарный конвертер',
    'short' => 'Сколько граммов в стакане и ложке: диапазоны для 35 продуктов, пересчёт порций и формы',
    'icon' => 'fas fa-utensils',
    'href' => '/cooking',
    'group' => 'Единицы и быт',
    'sort' => 507,
    'keywords' => 'сколько грамм в стакане муки сахара соли ложке чайной столовой граммы в миллилитры конвертер продуктов рецепт форма торта',
    'api' => ['/nj507/api/convert', '/nj507/api/form', '/nj507/api/products'],
    'card_view' => 'nj507/card',
    'sitemap' => [
        '/cooking/wheat-flour', '/cooking/rye-flour', '/cooking/whole-wheat-flour', '/cooking/corn-flour',
        '/cooking/granulated-sugar', '/cooking/powdered-sugar', '/cooking/brown-sugar', '/cooking/honey', '/cooking/jam',
        '/cooking/water', '/cooking/milk', '/cooking/kefir', '/cooking/lemon-juice', '/cooking/vegetable-oil', '/cooking/olive-oil',
        '/cooking/melted-butter', '/cooking/sour-cream', '/cooking/mayonnaise', '/cooking/rice', '/cooking/buckwheat',
        '/cooking/semolina', '/cooking/oats', '/cooking/millet', '/cooking/pearl-barley', '/cooking/pasta', '/cooking/cocoa',
        '/cooking/cornstarch', '/cooking/baking-soda', '/cooking/dry-yeast', '/cooking/poppy-seed', '/cooking/sesame',
        '/cooking/walnuts', '/cooking/almonds', '/cooking/raisins', '/cooking/tomato-paste',
    ],
];
