<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRX-597/004: присутствие человека на сайте.
 *
 * Зачем таблица: владелец (16.10.2026) — «запустил обзор, он сделался, я его
 * смотрю — и мне приходит внутреннее уведомление! Зачем?» Чтобы это правило
 * работало, центру нужно знать, что человек прямо сейчас на сайте. Отдельного
 * «онлайн» в системе не было: последний вход (users.last_login) обновляется раз
 * в сутки и для этой задачи бесполезен.
 *
 * Что здесь: одна таблица prx_597_presence («user_id → когда видели»). Метку
 * ставит колокольчик в шапке — он и так опрашивает сервер раз в 45 секунд, и
 * вкладка добавляет ?visible=1, когда она открыта на виду (свёрнутая или чужая
 * вкладка молчит). Строки живут минуты, старые убирает Notify::presence_gc()
 * в проходе движка.
 *
 * DDL не продублирован: операторы лежат в Service_Prx597_Schema — их же
 * выполняет Service_Prx597_Notify::ensure_tables(), поэтому таблица появляется
 * и без наката миграций, а схема остаётся одной.
 *
 * Таблицы: prx_597_presence.
 */
class pgMigration_Prx_597_004_Presence extends pgMigration
{
    public function up()
    {
        foreach (self::_schema_statements() as $sql)
        {
            DB::query(NULL, $sql)->execute();
        }
    }

    public function down()
    {
        // Только своя таблица: остальные создаёт миграция 002.
        DB::query(NULL, 'DROP TABLE IF EXISTS "prx_597_presence"')->execute();
    }

    /**
     * Операторы схемы. Класс мог не загрузиться автолоадером (миграции иногда
     * накатывают до регистрации модуля) — тогда подключаем файлы руками.
     */
    protected static function _schema_statements()
    {
        if ( ! class_exists('Service_Prx597_Schema'))
        {
            $dir = dirname(dirname(__FILE__)).'/classes/Service/Prx597/';

            if ( ! class_exists('Service_Prx597_Base'))
            {
                require $dir.'Base.php';
            }

            require $dir.'Schema.php';
        }

        return Service_Prx597_Schema::statements();
    }
}
