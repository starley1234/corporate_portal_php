<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Исполнитель расписаний манифестов: bin/console schedule:run (раз в минуту
 * из cron или сервиса `scheduler` в docker-compose).
 *
 * Расписания объявляют модули (`schedule` в config/prx_module.php), здесь —
 * только поведение исполнителя.
 */
return [
    // Потолок одного запуска, секунд (запись манифеста может задать свой `timeout`).
    'timeout' => (int) (getenv('PRX103_SCHEDULE_TIMEOUT') ?: 600),

    // Сколько последних байт вывода хранить в prx_103_schedule_runs.output.
    'output_bytes' => 4000,

    // Отключить отдельные ключи без правки манифеста: PRX103_SCHEDULE_DISABLED=a,b
    'disabled' => array_values(array_filter(array_map('trim', explode(',', (string) getenv('PRX103_SCHEDULE_DISABLED'))))),

    // dev:doctor: «застряло», если последний запуск старше every × stale_factor.
    'stale_factor' => 3,
];
