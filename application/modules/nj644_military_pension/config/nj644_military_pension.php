<?php defined('SYSPATH') or die('No direct script access.');

/** Нормативные значения для справочного калькулятора пенсии за выслугу лет. */
return [
    'reviewed_on' => '2026-10-06',
    'defaults' => [
        // ФЗ №433-ФЗ от 28.11.2025: действующая доля на 2026 год.
        // 97,34% с 01.10.2026 упоминается в законопроекте №619-9, но на дату
        // проверки не принята — поэтому не ставится значением по умолчанию.
        'coefficient_percent' => 93.59,
        'coefficient_from' => '2026-01-01',
        'coefficient_to' => '2026-12-31',

        // Расчётный размер пенсии с 01.04.2026 (социальная пенсия по старости).
        'rpp_rub' => 9424.12,

        // Статья 14, пункт «а»: 20 лет — 50%, +3 п.п. за год, максимум 85%.
        'service_min_years' => 20,
        'service_base_percent' => 50,
        'service_percent_per_year' => 3,
        'service_max_percent' => 85,

        // Статья 13, пункт «б», и статья 14, пункт «б».
        'mixed_min_total_years' => 25,
        'mixed_min_service_months' => 150,
        'mixed_min_age' => 45,
        'mixed_base_percent' => 50,
        'mixed_percent_per_year' => 1,
    ],
    'periods' => [
        '2026-current' => [
            'label' => '2026: действующая доля 93,59%',
            'coefficient_percent' => 93.59,
            'from' => '2026-01-01',
            'to' => '2026-12-31',
            'status' => 'official',
        ],
        '2026-proposal' => [
            'label' => 'с 01.10.2026: 97,34% — сценарий законопроекта',
            'coefficient_percent' => 97.34,
            'from' => '2026-10-01',
            'to' => '2026-12-31',
            'status' => 'proposal',
        ],
    ],
    'allowances' => [
        'service_percent' => [
            ['value' => 0, 'label' => 'Нет / ввести свой сценарий'],
            ['value' => 15, 'label' => 'от 5 до 10 лет — 15% ОДС'],
            ['value' => 20, 'label' => 'от 10 до 15 лет — 20% ОДС'],
            ['value' => 25, 'label' => 'от 15 до 20 лет — 25% ОДС'],
            ['value' => 30, 'label' => 'от 20 до 25 лет — 30% ОДС'],
            ['value' => 40, 'label' => '25 лет и более — 40% ОДС'],
        ],
        'qualification_percent' => [
            ['value' => 0, 'label' => 'Нет квалификационной категории'],
            ['value' => 10, 'label' => 'Бортовой специалист 2 класса — 10% ОВД'],
            ['value' => 15, 'label' => 'Лётчик/штурман 2 класса или специалист 1 класса — 15% ОВД'],
            ['value' => 20, 'label' => 'Лётчик/штурман 1 класса или специалист-мастер — 20% ОВД'],
            ['value' => 30, 'label' => 'Лётчик/штурман-снайпер — 30% ОВД'],
        ],
        'district_coefficients' => [1, 1.15, 1.2, 1.25, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 2],
    ],
    'limits' => [
        'max_monthly_rub' => 100000000,
        'max_years' => 100,
        'max_age' => 120,
    ],
    'sources' => [
        'article_13' => [
            'title' => 'Статья 13 Закона РФ №4468-1 — условия права на пенсию за выслугу лет',
            'url' => 'https://www.consultant.ru/document/cons_doc_LAW_4436/caa901849880c84df1947f5b06c1f45790974037/',
        ],
        'article_14' => [
            'title' => 'Статья 14 Закона РФ №4468-1 — размеры пенсии за выслугу лет',
            'url' => 'https://www.consultant.ru/document/cons_doc_LAW_4436/02f562220f06f4aab2c4f8c59d0b1b4f46cb77f6/',
        ],
        'article_43' => [
            'title' => 'Статья 43 Закона РФ №4468-1 — денежное довольствие для исчисления пенсии',
            'url' => 'https://www.consultant.ru/document/cons_doc_LAW_4436/c253b230820591b16e65ae16f1a1f668ddd7255c/',
        ],
        'reference' => [
            'title' => 'Справочные поля и значения: калькулятор «Военного суда»',
            'url' => 'https://voensud-mo.ru/cal/pension_01.10.2020',
        ],
        'coefficient' => [
            'title' => 'Федеральный закон №433-ФЗ от 28.11.2025 — доля довольствия на 2026 год',
            'url' => 'https://www.consultant.ru/document/cons_doc_LAW_520036/3d0cac60971a511280cbba229d9b6329c07731f7/',
        ],
    ],
];
