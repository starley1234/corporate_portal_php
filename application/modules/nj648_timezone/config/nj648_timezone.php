<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Справочник городов для планировщика созвонов (nj648_timezone).
 *
 * Ключ — короткий код для адреса страницы (`?p[]=moscow`), `tz` — зона из базы
 * IANA. Смещения здесь НЕ хранятся: их считает PHP по актуальной базе часовых
 * поясов, иначе каждое решение правительства о переводе стрелок приходилось бы
 * догонять руками (так уже было с Казахстаном в 2024-м и Волгоградом в 2020-м).
 *
 * Один и тот же `tz` у нескольких городов — это нормально: человеку понятнее
 * «Санкт-Петербург», чем «Europe/Moscow».
 */
return [
    'cities' => [
        // --- Россия -------------------------------------------------------
        'kaliningrad'  => ['city' => 'Калининград',  'tz' => 'Europe/Kaliningrad', 'region' => 'Россия'],
        'moscow'       => ['city' => 'Москва',       'tz' => 'Europe/Moscow',      'region' => 'Россия'],
        'spb'          => ['city' => 'Санкт-Петербург', 'tz' => 'Europe/Moscow',   'region' => 'Россия'],
        'sochi'        => ['city' => 'Сочи',         'tz' => 'Europe/Moscow',      'region' => 'Россия'],
        'samara'       => ['city' => 'Самара',       'tz' => 'Europe/Samara',      'region' => 'Россия'],
        'yekaterinburg' => ['city' => 'Екатеринбург', 'tz' => 'Asia/Yekaterinburg', 'region' => 'Россия'],
        'ufa'          => ['city' => 'Уфа',          'tz' => 'Asia/Yekaterinburg', 'region' => 'Россия'],
        'omsk'         => ['city' => 'Омск',         'tz' => 'Asia/Omsk',          'region' => 'Россия'],
        'novosibirsk'  => ['city' => 'Новосибирск',  'tz' => 'Asia/Novosibirsk',   'region' => 'Россия'],
        'krasnoyarsk'  => ['city' => 'Красноярск',   'tz' => 'Asia/Krasnoyarsk',   'region' => 'Россия'],
        'irkutsk'      => ['city' => 'Иркутск',      'tz' => 'Asia/Irkutsk',       'region' => 'Россия'],
        'yakutsk'      => ['city' => 'Якутск',       'tz' => 'Asia/Yakutsk',       'region' => 'Россия'],
        'vladivostok'  => ['city' => 'Владивосток',  'tz' => 'Asia/Vladivostok',   'region' => 'Россия'],
        'magadan'      => ['city' => 'Магадан',      'tz' => 'Asia/Magadan',       'region' => 'Россия'],
        'kamchatka'    => ['city' => 'Петропавловск-Камчатский', 'tz' => 'Asia/Kamchatka', 'region' => 'Россия'],

        // --- Соседи -------------------------------------------------------
        'minsk'        => ['city' => 'Минск',        'tz' => 'Europe/Minsk',       'region' => 'Соседние страны'],
        'kyiv'         => ['city' => 'Киев',         'tz' => 'Europe/Kyiv',        'region' => 'Соседние страны'],
        'chisinau'     => ['city' => 'Кишинёв',      'tz' => 'Europe/Chisinau',    'region' => 'Соседние страны'],
        'tbilisi'      => ['city' => 'Тбилиси',      'tz' => 'Asia/Tbilisi',       'region' => 'Соседние страны'],
        'yerevan'      => ['city' => 'Ереван',       'tz' => 'Asia/Yerevan',       'region' => 'Соседние страны'],
        'baku'         => ['city' => 'Баку',         'tz' => 'Asia/Baku',          'region' => 'Соседние страны'],
        'astana'       => ['city' => 'Астана',       'tz' => 'Asia/Almaty',        'region' => 'Соседние страны'],
        'almaty'       => ['city' => 'Алматы',       'tz' => 'Asia/Almaty',        'region' => 'Соседние страны'],
        'tashkent'     => ['city' => 'Ташкент',      'tz' => 'Asia/Tashkent',      'region' => 'Соседние страны'],
        'bishkek'      => ['city' => 'Бишкек',       'tz' => 'Asia/Bishkek',       'region' => 'Соседние страны'],
        'dushanbe'     => ['city' => 'Душанбе',      'tz' => 'Asia/Dushanbe',      'region' => 'Соседние страны'],

        // --- Европа -------------------------------------------------------
        'lisbon'       => ['city' => 'Лиссабон',     'tz' => 'Europe/Lisbon',      'region' => 'Европа'],
        'london'       => ['city' => 'Лондон',       'tz' => 'Europe/London',      'region' => 'Европа'],
        'berlin'       => ['city' => 'Берлин',       'tz' => 'Europe/Berlin',      'region' => 'Европа'],
        'prague'       => ['city' => 'Прага',        'tz' => 'Europe/Prague',      'region' => 'Европа'],
        'warsaw'       => ['city' => 'Варшава',      'tz' => 'Europe/Warsaw',      'region' => 'Европа'],
        'paris'        => ['city' => 'Париж',        'tz' => 'Europe/Paris',       'region' => 'Европа'],
        'madrid'       => ['city' => 'Мадрид',       'tz' => 'Europe/Madrid',      'region' => 'Европа'],
        'rome'         => ['city' => 'Рим',          'tz' => 'Europe/Rome',        'region' => 'Европа'],
        'belgrade'     => ['city' => 'Белград',      'tz' => 'Europe/Belgrade',    'region' => 'Европа'],
        'helsinki'     => ['city' => 'Хельсинки',    'tz' => 'Europe/Helsinki',    'region' => 'Европа'],
        'istanbul'     => ['city' => 'Стамбул',      'tz' => 'Europe/Istanbul',    'region' => 'Европа'],

        // --- Азия и Ближний Восток ----------------------------------------
        'dubai'        => ['city' => 'Дубай',        'tz' => 'Asia/Dubai',         'region' => 'Азия и Ближний Восток'],
        'telaviv'      => ['city' => 'Тель-Авив',    'tz' => 'Asia/Jerusalem',     'region' => 'Азия и Ближний Восток'],
        'delhi'        => ['city' => 'Дели',         'tz' => 'Asia/Kolkata',       'region' => 'Азия и Ближний Восток'],
        'bangkok'      => ['city' => 'Бангкок',      'tz' => 'Asia/Bangkok',       'region' => 'Азия и Ближний Восток'],
        'beijing'      => ['city' => 'Пекин',        'tz' => 'Asia/Shanghai',      'region' => 'Азия и Ближний Восток'],
        'hongkong'     => ['city' => 'Гонконг',      'tz' => 'Asia/Hong_Kong',     'region' => 'Азия и Ближний Восток'],
        'singapore'    => ['city' => 'Сингапур',     'tz' => 'Asia/Singapore',     'region' => 'Азия и Ближний Восток'],
        'seoul'        => ['city' => 'Сеул',         'tz' => 'Asia/Seoul',         'region' => 'Азия и Ближний Восток'],
        'tokyo'        => ['city' => 'Токио',        'tz' => 'Asia/Tokyo',         'region' => 'Азия и Ближний Восток'],

        // --- Америка и Океания --------------------------------------------
        'newyork'      => ['city' => 'Нью-Йорк',     'tz' => 'America/New_York',   'region' => 'Америка и Океания'],
        'toronto'      => ['city' => 'Торонто',      'tz' => 'America/Toronto',    'region' => 'Америка и Океания'],
        'chicago'      => ['city' => 'Чикаго',       'tz' => 'America/Chicago',    'region' => 'Америка и Океания'],
        'denver'       => ['city' => 'Денвер',       'tz' => 'America/Denver',     'region' => 'Америка и Океания'],
        'losangeles'   => ['city' => 'Лос-Анджелес', 'tz' => 'America/Los_Angeles', 'region' => 'Америка и Океания'],
        'mexico'       => ['city' => 'Мехико',       'tz' => 'America/Mexico_City', 'region' => 'Америка и Океания'],
        'saopaulo'     => ['city' => 'Сан-Паулу',    'tz' => 'America/Sao_Paulo',  'region' => 'Америка и Океания'],
        'buenosaires'  => ['city' => 'Буэнос-Айрес', 'tz' => 'America/Argentina/Buenos_Aires', 'region' => 'Америка и Океания'],
        'sydney'       => ['city' => 'Сидней',       'tz' => 'Australia/Sydney',   'region' => 'Америка и Океания'],
        'auckland'     => ['city' => 'Окленд',       'tz' => 'Pacific/Auckland',   'region' => 'Америка и Океания'],
    ],

    /** Кого показываем, если участники не выбраны */
    'default' => ['moscow', 'yekaterinburg', 'novosibirsk', 'vladivostok'],

    /** Рабочий день по умолчанию (часы местного времени участника) */
    'work' => ['from' => 9, 'to' => 18],

    /** Больше участников в одну сетку не помещается по-человечески */
    'max_people' => 8,
];
