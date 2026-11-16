<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRL-597/002: ядро центра уведомлений.
 *
 * До этой миграции модуль умел только «событие → колокольчик → письмо-сводка».
 * Здесь появляется полноценный контур: реестр типов событий, шаблоны,
 * привязки каналов пользователя, матрица настроек, очередь доставки (outbox),
 * журнал доставки, счётчики антиспама, быстрый счётчик непрочитанных и
 * состояние ботов.
 *
 * DDL не продублирован: операторы лежат в Service_Prl597_Schema — их же
 * выполняет Service_Prl597_Notify::ensure_tables(), поэтому модуль поднимается
 * и без наката миграций, а схема остаётся одной.
 *
 * Таблицы: prl_597_events, prl_597_templates, prl_597_channels, prl_597_subs,
 * prl_597_outbox, prl_597_log, prl_597_rate, prl_597_unread, prl_597_bots,
 * prl_597_settings.
 */
class pgMigration_Prl_597_002_Engine_Tables extends pgMigration
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
        DB::query(NULL, 'DROP TRIGGER IF EXISTS "prl_597_notifications_unread_trg" ON "prl_597_notifications"')->execute();
        DB::query(NULL, 'DROP FUNCTION IF EXISTS prl_597_unread_sync()')->execute();

        foreach ([
            'prl_597_settings', 'prl_597_bots', 'prl_597_unread', 'prl_597_rate',
            'prl_597_log', 'prl_597_outbox', 'prl_597_subs', 'prl_597_channels',
            'prl_597_templates', 'prl_597_events',
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
        if ( ! class_exists('Service_Prl597_Schema'))
        {
            $dir = dirname(dirname(__FILE__)).'/classes/Service/Prl597/';

            if ( ! class_exists('Service_Prl597_Base'))
            {
                require $dir.'Base.php';
            }

            require $dir.'Schema.php';
        }

        return Service_Prl597_Schema::statements();
    }
}
