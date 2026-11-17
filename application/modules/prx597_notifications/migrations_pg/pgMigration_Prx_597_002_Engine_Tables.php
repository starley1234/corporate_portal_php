<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRX-597/002: ядро центра уведомлений.
 *
 * До этой миграции модуль умел только «событие → колокольчик → письмо-сводка».
 * Здесь появляется полноценный контур: реестр типов событий, шаблоны,
 * привязки каналов пользователя, матрица настроек, очередь доставки (outbox),
 * журнал доставки, счётчики антиспама, быстрый счётчик непрочитанных и
 * состояние ботов.
 *
 * DDL не продублирован: операторы лежат в Service_Prx597_Schema — их же
 * выполняет Service_Prx597_Notify::ensure_tables(), поэтому модуль поднимается
 * и без наката миграций, а схема остаётся одной.
 *
 * Таблицы: prx_597_events, prx_597_templates, prx_597_channels, prx_597_subs,
 * prx_597_outbox, prx_597_log, prx_597_rate, prx_597_unread, prx_597_bots,
 * prx_597_settings.
 */
class pgMigration_Prx_597_002_Engine_Tables extends pgMigration
{
    public function up()
    {
        $statements = self::_schema_statements();

        foreach ($statements as $sql)
        {
            DB::query(NULL, $sql)->execute();
        }
    }

    public function down()
    {
        DB::query(NULL, 'DROP TRIGGER IF EXISTS "prx_597_notifications_unread_trg" ON "prx_597_notifications"')->execute();
        DB::query(NULL, 'DROP FUNCTION IF EXISTS prx_597_unread_sync()')->execute();

        foreach ([
            'prx_597_settings', 'prx_597_bots', 'prx_597_unread', 'prx_597_rate',
            'prx_597_log', 'prx_597_outbox', 'prx_597_subs', 'prx_597_channels',
            'prx_597_templates', 'prx_597_events',
        ] as $table)
        {
            DB::query(NULL, 'DROP TABLE IF EXISTS "'.$table.'"')->execute();
        }

        // Добавленные колонки не снимаем: это ломает работающий колокольчик
        // (откат «вниз» применяют на живой базе крайне редко).
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
