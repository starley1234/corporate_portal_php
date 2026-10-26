<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-597/002: ядро центра уведомлений.
 *
 * До этой миграции модуль умел только «событие → колокольчик → письмо-сводка».
 * Здесь появляется полноценный контур: реестр типов событий, шаблоны,
 * привязки каналов пользователя, матрица настроек, очередь доставки (outbox),
 * журнал доставки, счётчики антиспама, быстрый счётчик непрочитанных и
 * состояние ботов.
 *
 * DDL не продублирован: операторы лежат в Service_Nj597_Schema — их же
 * выполняет Service_Nj597_Notify::ensure_tables(), поэтому модуль поднимается
 * и без наката миграций, а схема остаётся одной.
 *
 * Таблицы: nj_597_events, nj_597_templates, nj_597_channels, nj_597_subs,
 * nj_597_outbox, nj_597_log, nj_597_rate, nj_597_unread, nj_597_bots,
 * nj_597_settings.
 */
class pgMigration_Nj_597_002_Engine_Tables extends pgMigration
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
        DB::query(NULL, 'DROP TRIGGER IF EXISTS "nj_597_notifications_unread_trg" ON "nj_597_notifications"')->execute();
        DB::query(NULL, 'DROP FUNCTION IF EXISTS nj_597_unread_sync()')->execute();

        foreach ([
            'nj_597_settings', 'nj_597_bots', 'nj_597_unread', 'nj_597_rate',
            'nj_597_log', 'nj_597_outbox', 'nj_597_subs', 'nj_597_channels',
            'nj_597_templates', 'nj_597_events',
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
        if ( ! class_exists('Service_Nj597_Schema'))
        {
            $dir = dirname(dirname(__FILE__)).'/classes/Service/Nj597/';

            if ( ! class_exists('Service_Nj597_Base'))
            {
                require $dir.'Base.php';
            }

            require $dir.'Schema.php';
        }

        return Service_Nj597_Schema::statements();
    }
}
