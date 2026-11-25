<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * Сервис для управления модулями системы Prx218.
 * Инкапсулирует бизнес-логику работы с модулями, их статусами, метаданными и зависимостями.
 *
 * @package Prx218
 * @version 1.0.1
 */
class Service_Prx218_ModuleManager {

    /**
     * @var Service_Prx218_ModuleManager
     */
    protected static $_instance;

    /**
     * Singleton instance
     *
     * @return Service_Prx218_ModuleManager
     */
    public static function instance()
    {
        if (self::$_instance === NULL)
        {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Приватный конструктор для предотвращения прямого создания экземпляров.
     */
    protected function __construct() {}

   

    /**
     * Возвращает общее количество модулей с учетом фильтров.
     *
     * @param string|null $status Статус модуля для фильтрации
     * @param string|null $search Поисковая строка
     * @return int
     */
    public function count_modules($status = NULL, $search = NULL)
    {
        $query = ORM::factory('Prx218_Module')
            ->where('is_deleted', '=', 0);

        if ($status !== NULL)
        {
            $query->where('status', '=', $status);
        }

        if ($search !== NULL)
        {
            $query->and_where_open()
                ->where('system_name', 'LIKE', '%' . $search . '%')
                ->or_where('display_name', 'LIKE', '%' . $search . '%')
                ->and_where_close();
        }

        return $query->count_all();
    }

    /**
     * Обновляет метаданные модуля.
     *
     * @param Model_Prx218_Module $module Объект модуля
     * @param array $data Данные для обновления (display_name, description, developer_notes, sort_order)
     * @param int|null $actor_id Кто меняет (инициатор события)
     * @return Model_Prx218_Module
     * @throws Kohana_Exception
     */
    public function update_module_metadata(Model_Prx218_Module $module, array $data, ?int $actor_id = NULL)
    {
        try
        {
            Prx_Db::transaction(function () use ($module, $data) {
                $module->values($data);
                $module->save();
            });

            Audit::log('module_manager', 'metadata_updated', $module->id, $data);
            Event::publish('prx218.module.metadata_updated', [
                'module_id'   => (int) $module->id,
                'system_name' => (string) $module->system_name,
                'fields'      => array_keys($data),
            ], $actor_id);

            return $module;
        }
        catch (Exception $e)
        {
            Kohana_Exception::log($e);
            throw new Kohana_Exception('Failed to update module metadata: :error', [':error' => $e->getMessage()]);
        }
    }

    /**
     * Обновляет статус модуля.
     *
     * @param int $module_id ID модуля
     * @param string $new_status Новый статус (alpha, beta, stable, archived)
     * @param int|null $actor_id Кто меняет (инициатор события)
     * @return Model_Prx218_Module
     * @throws Kohana_Exception
     */
    public function update_status($module_id, $new_status, ?int $actor_id = NULL)
    {
        $module = ORM::factory('Prx218_Module', $module_id);

        if ( ! $module->loaded())
        {
            throw new Kohana_Exception('Module with ID :id not found', [':id' => $module_id]);
        }

        if ( ! in_array($new_status, ['alpha', 'beta', 'stable', 'archived']))
        {
            throw new Kohana_Exception('Invalid module status: :status', [':status' => $new_status]);
        }

        $old_status = $module->status;

        try
        {
            Prx_Db::transaction(function () use ($module, $new_status) {
                $module->status = $new_status;
                $module->save();
            });

            Audit::log('module_manager', 'status_changed', $module->id, ['old_status' => $old_status, 'new_status' => $new_status]);
            Event::publish('prx218.module.status_changed', [
                'module_id'   => (int) $module->id,
                'system_name' => (string) $module->system_name,
                'old_status'  => (string) $old_status,
                'new_status'  => (string) $new_status,
            ], $actor_id);

            return $module;
        }
        catch (Exception $e)
        {
            Kohana_Exception::log($e);
            throw new Kohana_Exception('Failed to update module status: :error', [':error' => $e->getMessage()]);
        }
    }

    /**
     * Добавляет новую запись в историю версий модуля.
     *
     * @param int $module_id ID модуля
     * @param string $version Новая версия
     * @param string $change_log Описание изменений
     * @param int $user_id ID пользователя, добавившего запись
     * @param string|null $release_date Дата релиза (YYYY-MM-DD)
     * @return Model_Prx218_History
     * @throws Kohana_Exception
     */
    public function add_version_history($module_id, $version, $change_log, $user_id, $release_date = NULL)
    {
        $module = ORM::factory('Prx218_Module', $module_id);

        if ( ! $module->loaded())
        {
            throw new Kohana_Exception('Module with ID :id not found', [':id' => $module_id]);
        }

        try
        {
            $history = Prx_Db::transaction(function () use ($module_id, $version, $change_log, $release_date, $user_id, $module) {
                $history = ORM::factory('Prx218_History');
                $history->module_id = $module_id;
                $history->version = $version;
                $history->change_log = $change_log;
                $history->release_date = $release_date ?: date('Y-m-d');
                $history->created_by = $user_id;
                $history->save();

                // Обновляем текущую версию модуля
                $module->version = $version;
                $module->save();

                return $history;
            });

            Audit::log('module_manager', 'version_history_added', $module->id, ['version' => $version, 'change_log' => $change_log]);
            Event::publish('prx218.module.version_history_added', [
                'module_id'   => (int) $module->id,
                'system_name' => (string) $module->system_name,
                'history_id'  => (int) $history->id,
                'version'     => (string) $version,
                'release_date' => (string) $history->release_date,
            ], (int) $user_id ?: NULL);

            return $history;
        }
        catch (Exception $e)
        {
            Kohana_Exception::log($e);
            throw new Kohana_Exception('Failed to add version history: :error', [':error' => $e->getMessage()]);
        }
    }

    /**
     * Удаляет модуль (мягкое удаление).
     *
     * @param int $module_id ID модуля
     * @param int|null $actor_id Кто удаляет (инициатор события)
     * @return bool
     * @throws Kohana_Exception
     */
    public function delete_module($module_id, ?int $actor_id = NULL)
    {
        $module = ORM::factory('Prx218_Module', $module_id);

        if ( ! $module->loaded())
        {
            throw new Kohana_Exception('Module with ID :id not found', [':id' => $module_id]);
        }

        try
        {
            Prx_Db::transaction(function () use ($module, $module_id) {
                $module->is_deleted = 1;
                $module->save();

                // Также мягко удаляем связанные зависимости, историю и LLM-анализ
                DB::update('prx_218_dependencies')->set(['is_deleted' => 1])->where('module_id', '=', $module_id)->execute();
                DB::update('prx_218_dependencies')->set(['is_deleted' => 1])->where('required_module_id', '=', $module_id)->execute();
                DB::update('prx_218_version_history')->set(['is_deleted' => 1])->where('module_id', '=', $module_id)->execute();
                DB::update('prx_218_llm_analysis')->set(['is_deleted' => 1])->where('module_id', '=', $module_id)->execute();
            });

            Audit::log('module_manager', 'module_deleted', $module->id);
            Event::publish('prx218.module.deleted', [
                'module_id'   => (int) $module->id,
                'system_name' => (string) $module->system_name,
            ], $actor_id);

            return TRUE;
        }
        catch (Exception $e)
        {
            Kohana_Exception::log($e);
            throw new Kohana_Exception('Failed to delete module: :error', [':error' => $e->getMessage()]);
        }
    }

    /**
     * Восстанавливает модуль после мягкого удаления.
     *
     * @param int $module_id ID модуля
     * @param int|null $actor_id Кто восстанавливает (инициатор события)
     * @return bool
     * @throws Kohana_Exception
     */
    public function restore_module($module_id, ?int $actor_id = NULL)
    {
        $module = ORM::factory('Prx218_Module', $module_id);

        if ( ! $module->loaded())
        {
            throw new Kohana_Exception('Module with ID :id not found', [':id' => $module_id]);
        }

        try
        {
            Prx_Db::transaction(function () use ($module, $module_id) {
                $module->is_deleted = 0;
                $module->save();

                // Восстанавливаем связанные записи
                DB::update('prx_218_dependencies')->set(['is_deleted' => 0])->where('module_id', '=', $module_id)->execute();
                DB::update('prx_218_version_history')->set(['is_deleted' => 0])->where('module_id', '=', $module_id)->execute();
                DB::update('prx_218_llm_analysis')->set(['is_deleted' => 0])->where('module_id', '=', $module_id)->execute();
            });

            Audit::log('module_manager', 'module_restored', $module->id);
            Event::publish('prx218.module.restored', [
                'module_id'   => (int) $module->id,
                'system_name' => (string) $module->system_name,
            ], $actor_id);

            return TRUE;
        }
        catch (Exception $e)
        {
            Kohana_Exception::log($e);
            throw new Kohana_Exception('Failed to restore module: :error', [':error' => $e->getMessage()]);
        }
    }
}