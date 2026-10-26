<?php defined('SYSPATH') or die('No direct script access.');

/** Каталог единиц: коэффициенты к базовой единице и примеры бытовых мер. */
return [
    'categories' => [
        'length' => [
            'title' => 'Длина', 'base' => 'm', 'default_from' => 'km', 'default_to' => 'm',
            'units' => [
                'mm' => ['title' => 'Миллиметр', 'symbol' => 'мм', 'factor' => 0.001],
                'cm' => ['title' => 'Сантиметр', 'symbol' => 'см', 'factor' => 0.01],
                'm' => ['title' => 'Метр', 'symbol' => 'м', 'factor' => 1],
                'km' => ['title' => 'Километр', 'symbol' => 'км', 'factor' => 1000],
                'inch' => ['title' => 'Дюйм', 'symbol' => 'дюйм', 'factor' => 0.0254],
                'foot' => ['title' => 'Фут', 'symbol' => 'фут', 'factor' => 0.3048],
                'yard' => ['title' => 'Ярд', 'symbol' => 'ярд', 'factor' => 0.9144],
                'mile' => ['title' => 'Миля', 'symbol' => 'миля', 'factor' => 1609.344],
                'vershok' => ['title' => 'Вершок', 'symbol' => 'верш.', 'factor' => 0.04445],
                'arshin' => ['title' => 'Аршин', 'symbol' => 'аршин', 'factor' => 0.7112],
                'sazhen' => ['title' => 'Сажень', 'symbol' => 'сажень', 'factor' => 2.1336],
                'verst' => ['title' => 'Верста', 'symbol' => 'верста', 'factor' => 1066.8],
            ],
            'examples' => [
                ['value' => 1, 'from' => 'verst', 'to' => 'm', 'label' => 'Верста → метры'],
                ['value' => 3, 'from' => 'sazhen', 'to' => 'm', 'label' => 'Сажени → метры'],
                ['value' => 12, 'from' => 'inch', 'to' => 'cm', 'label' => 'Дюймы → сантиметры'],
            ],
        ],
        'mass' => [
            'title' => 'Масса', 'base' => 'kg', 'default_from' => 'kg', 'default_to' => 'g',
            'units' => [
                'mg' => ['title' => 'Миллиграмм', 'symbol' => 'мг', 'factor' => 0.000001],
                'g' => ['title' => 'Грамм', 'symbol' => 'г', 'factor' => 0.001],
                'kg' => ['title' => 'Килограмм', 'symbol' => 'кг', 'factor' => 1],
                'tonne' => ['title' => 'Метрическая тонна', 'symbol' => 'т', 'factor' => 1000],
                'oz' => ['title' => 'Унция', 'symbol' => 'унция', 'factor' => 0.028349523125],
                'lb' => ['title' => 'Фунт', 'symbol' => 'фунт', 'factor' => 0.45359237],
                'pud' => ['title' => 'Пуд', 'symbol' => 'пуд', 'factor' => 16.3804964],
            ],
            'examples' => [
                ['value' => 1, 'from' => 'pud', 'to' => 'kg', 'label' => 'Пуд → килограммы'],
                ['value' => 2, 'from' => 'lb', 'to' => 'g', 'label' => 'Фунты → граммы'],
                ['value' => 500, 'from' => 'g', 'to' => 'oz', 'label' => 'Граммы → унции'],
            ],
        ],
        'volume' => [
            'title' => 'Объём', 'base' => 'l', 'default_from' => 'l', 'default_to' => 'ml',
            'units' => [
                'ml' => ['title' => 'Миллилитр', 'symbol' => 'мл', 'factor' => 0.001],
                'l' => ['title' => 'Литр', 'symbol' => 'л', 'factor' => 1],
                'm3' => ['title' => 'Кубический метр', 'symbol' => 'м³', 'factor' => 1000],
                'tsp' => ['title' => 'Чайная ложка', 'symbol' => 'ч. л.', 'factor' => 0.005],
                'tbsp' => ['title' => 'Столовая ложка', 'symbol' => 'ст. л.', 'factor' => 0.015],
                'cup200' => ['title' => 'Стакан 200 мл', 'symbol' => 'стакан 200 мл', 'factor' => 0.2],
                'cup250' => ['title' => 'Стакан 250 мл', 'symbol' => 'стакан 250 мл', 'factor' => 0.25],
                'bucket10' => ['title' => 'Ведро 10 л (условно)', 'symbol' => 'ведро 10 л', 'factor' => 10],
                'bucket123' => ['title' => 'Историческое ведро 12,3 л', 'symbol' => 'ведро 12,3 л', 'factor' => 12.3],
                'us_gal' => ['title' => 'Американский галлон', 'symbol' => 'US gal', 'factor' => 3.785411784],
            ],
            'examples' => [
                ['value' => 1, 'from' => 'bucket123', 'to' => 'l', 'label' => 'Историческое ведро → литры'],
                ['value' => 3, 'from' => 'tbsp', 'to' => 'ml', 'label' => 'Столовые ложки → миллилитры'],
                ['value' => 1, 'from' => 'us_gal', 'to' => 'l', 'label' => 'Галлон → литры'],
            ],
        ],
        'area' => [
            'title' => 'Площадь', 'base' => 'm2', 'default_from' => 'ha', 'default_to' => 'm2',
            'units' => [
                'cm2' => ['title' => 'Квадратный сантиметр', 'symbol' => 'см²', 'factor' => 0.0001],
                'm2' => ['title' => 'Квадратный метр', 'symbol' => 'м²', 'factor' => 1],
                'are' => ['title' => 'Ар (сотка)', 'symbol' => 'а', 'factor' => 100],
                'ha' => ['title' => 'Гектар', 'symbol' => 'га', 'factor' => 10000],
                'km2' => ['title' => 'Квадратный километр', 'symbol' => 'км²', 'factor' => 1000000],
                'ft2' => ['title' => 'Квадратный фут', 'symbol' => 'ft²', 'factor' => 0.09290304],
                'acre' => ['title' => 'Акр', 'symbol' => 'акр', 'factor' => 4046.8564224],
            ],
            'examples' => [
                ['value' => 1, 'from' => 'ha', 'to' => 'are', 'label' => 'Гектар → сотки'],
                ['value' => 6, 'from' => 'are', 'to' => 'm2', 'label' => 'Сотки → м²'],
                ['value' => 1, 'from' => 'acre', 'to' => 'm2', 'label' => 'Акр → м²'],
            ],
        ],
        'speed' => [
            'title' => 'Скорость', 'base' => 'mps', 'default_from' => 'kmh', 'default_to' => 'mps',
            'units' => [
                'mps' => ['title' => 'Метр в секунду', 'symbol' => 'м/с', 'factor' => 1],
                'kmh' => ['title' => 'Километр в час', 'symbol' => 'км/ч', 'factor' => 0.27777777777778],
                'mph' => ['title' => 'Миля в час', 'symbol' => 'mph', 'factor' => 0.44704],
                'knot' => ['title' => 'Узел', 'symbol' => 'узел', 'factor' => 0.51444444444444],
            ],
            'examples' => [
                ['value' => 90, 'from' => 'kmh', 'to' => 'mps', 'label' => '90 км/ч → м/с'],
                ['value' => 1, 'from' => 'knot', 'to' => 'kmh', 'label' => 'Узел → км/ч'],
            ],
        ],
        'temperature' => [
            'title' => 'Температура', 'base' => 'c', 'default_from' => 'c', 'default_to' => 'f',
            'units' => [
                'c' => ['title' => 'Градус Цельсия', 'symbol' => '°C', 'factor' => 1, 'offset' => 0],
                'f' => ['title' => 'Градус Фаренгейта', 'symbol' => '°F', 'factor' => 0.55555555555556, 'offset' => -17.777777777778],
                'k' => ['title' => 'Кельвин', 'symbol' => 'K', 'factor' => 1, 'offset' => -273.15],
            ],
            'examples' => [
                ['value' => 20, 'from' => 'c', 'to' => 'f', 'label' => 'Цельсий → Фаренгейт'],
                ['value' => 0, 'from' => 'c', 'to' => 'k', 'label' => 'Ноль °C → кельвины'],
                ['value' => 101.325, 'from' => 'k', 'to' => 'c', 'label' => 'Точка кипения воды, K → °C'],
            ],
        ],
        'pressure' => [
            'title' => 'Давление', 'base' => 'pa', 'default_from' => 'bar', 'default_to' => 'kpa',
            'units' => [
                'pa' => ['title' => 'Паскаль', 'symbol' => 'Па', 'factor' => 1],
                'kpa' => ['title' => 'Килопаскаль', 'symbol' => 'кПа', 'factor' => 1000],
                'bar' => ['title' => 'Бар', 'symbol' => 'бар', 'factor' => 100000],
                'atm' => ['title' => 'Физическая атмосфера', 'symbol' => 'атм', 'factor' => 101325],
                'mmhg' => ['title' => 'Миллиметр ртутного столба', 'symbol' => 'мм рт. ст.', 'factor' => 133.322387415],
                'psi' => ['title' => 'Фунт-сила на квадратный дюйм', 'symbol' => 'psi', 'factor' => 6894.757293168],
            ],
            'examples' => [
                ['value' => 2.2, 'from' => 'bar', 'to' => 'kpa', 'label' => 'Давление в шинах: бар → кПа'],
                ['value' => 1, 'from' => 'atm', 'to' => 'mmhg', 'label' => 'Атмосфера → мм рт. ст.'],
            ],
        ],
    ],
    'data_note' => 'Исторические меры приведены как справочные приближения: местные и эпохальные варианты могли отличаться. Для бытового ведра отдельно показаны условные 10 л и историческая мера 12,3 л.',
];
