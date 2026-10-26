<?php defined('SYSPATH') or die('No direct script access.');

/** Ограничения и privacy-политика публичной проверки ИНН (nj517_inn). */
return [
    'max_input_length' => 32,
    // Поиск по открытой базе ФНС разрешён только для 10 цифр юрлица.
    // 12-значный ИНН физлица/ИП остаётся локальной проверкой в браузере.
    'remote_lookup_digits' => 10,
];
