<?php defined('SYSPATH') or die('No direct script access.');

abstract class Model_Nj111_Migration {

    /**
     * @var string Группа БД. Мы используем protected с подчеркиванием, 
     * как это принято в Kohana/Koseven.
     */
    protected $_db_group;

    public function __construct()
    {
        // Если в дочернем классе группа не определена (равна NULL)
        if ($this->_db_group === NULL)
        {
            $this->_db_group = Kohana::$config->load('nj111_migrations.db_group');
        }
        
        // Если и в конфиге пусто — дефолт
        if (empty($this->_db_group))
        {
            $this->_db_group = Database::$default;
        }
    }

    /**
     * Группа подключения, в которой работает миграция.
     *
     * @return string
     */
    public function db_group()
    {
        return $this->_db_group;
    }

    abstract public function up();
    abstract public function down();

    /**
     * Универсальный метод для выполнения запросов.
     * Мы передаем $type по умолчанию как Database::UPDATE для DDL/DML запросов.
     */
    protected function run_query($sql, $type = Database::UPDATE)
    {
        return DB::query($type, $sql)->execute($this->_db_group);
    }

    /**
     * Выполнить сценарий из нескольких операторов (разделитель «;»).
     *
     * Почему не explode(';'): точка с запятой может стоять ВНУТРИ строкового
     * литерала — например, в COMMENT ON … IS '…; …'. Так упала миграция
     * nj661/001 на проде (06.10.2026, «незавершённая строка в кавычках»):
     * разрез по «;» превращал один COMMENT в три обломка. Поэтому разрез
     * учитывает кавычки: «;» разделяет операторы только ВНЕ '…' (внутри
     * строки кавычка удваивается — '…''…'), а строчные комментарии «-- …»
     * пропускаются целиком. Доллар-кавычки ($$…$$) не поддерживаются —
     * в миграциях репозитория их нет.
     *
     * @param  string $sql сценарий (heredoc миграции или дамп .sql)
     * @return int    сколько операторов выполнено
     */
    protected function run_script($sql)
    {
        $done = 0;

        foreach ($this->split_script($sql) as $statement)
        {
            $this->run_query($statement);
            $done++;
        }

        return $done;
    }

    /**
     * Разрезать SQL-сценарий на операторы, не заходя внутрь '…' и «-- …».
     *
     * @param  string $sql сценарий
     * @return array  операторы, каждый завершён «;»
     */
    protected function split_script($sql)
    {
        $out       = [];
        $buf       = '';
        $in_string = FALSE;
        $len       = strlen($sql);

        for ($i = 0; $i < $len; $i++)
        {
            $ch = $sql[$i];

            if ($in_string)
            {
                $buf .= $ch;

                if ($ch === "'")
                {
                    if (($sql[$i + 1] ?? '') === "'")
                    {
                        // экранированная кавычка внутри строки: ''
                        $buf .= $sql[++$i];
                    }
                    else
                    {
                        $in_string = FALSE;
                    }
                }

                continue;
            }

            if ($ch === "'")
            {
                $in_string = TRUE;
                $buf .= $ch;
                continue;
            }

            if ($ch === '-' AND ($sql[$i + 1] ?? '') === '-')
            {
                // строчный комментарий: пропускаем до конца строки
                while ($i < $len AND $sql[$i] !== "\n")
                {
                    $i++;
                }

                continue;
            }

            if ($ch === ';')
            {
                if (trim($buf) !== '')
                {
                    $out[] = rtrim(trim($buf)).';';
                }

                $buf = '';
                continue;
            }

            $buf .= $ch;
        }

        // хвост без завершающего «;» — тоже оператор
        if (trim($buf) !== '')
        {
            $out[] = rtrim(trim($buf)).';';
        }

        return $out;
    }

    // Вспомогательный метод для SELECT, если понадобится
    protected function select_query($sql)
    {
        return DB::query(Database::SELECT, $sql)->execute($this->_db_group);
    }
	
    /**
     * Создает таблицу в базе данных.
     * Адаптировано под PostgreSQL (убраны специфичные для MySQL обратные кавычки и ENGINE)
     */
    protected function create_table($table_name, array $columns, array $options = array())
    {
        $sql_columns = array();
        $primary_keys = array();

        foreach ($columns as $column_name => $column_def)
        {
            $column_name_quoted = '"' . $column_name . '"';
            $type = strtoupper($column_def['type']);
            
            // Обработка автоинкремента для PostgreSQL
            if (isset($column_def['auto_increment']) && $column_def['auto_increment'])
            {
                $type = ($type === 'BIGINT') ? 'BIGSERIAL' : 'SERIAL';
            }

            $sql = $column_name_quoted . " " . $type;

            if (isset($column_def['length']) && !isset($column_def['auto_increment']))
            {
                $sql .= "(" . $column_def['length'] . ")";
            }

            if (isset($column_def['null']) && $column_def['null'] === FALSE)
            {
                $sql .= " NOT NULL";
            }

            if (isset($column_def['default']))
            {
                if ($column_def['default'] instanceof Database_Expression)
                {
                    $sql .= " DEFAULT " . $column_def['default']->__toString();
                }
                else
                {
                    $sql .= " DEFAULT " . DB::quote($column_def['default']);
                }
            }

            $sql_columns[] = $sql;

            if (isset($column_def['primary_key']) && $column_def['primary_key'])
            {
                $primary_keys[] = $column_name_quoted;
            }
        }

        if (!empty($primary_keys))
        {
            $sql_columns[] = "PRIMARY KEY (" . implode(', ', $primary_keys) . ")";
        }

        $sql = 'CREATE TABLE "' . $table_name . '" (' . implode(", ", $sql_columns) . ');';

        $this->run_query($sql);
    }

    /**
     * Удаляет таблицу из базы данных.
     */
    protected function drop_table($table_name)
    {
        $this->run_query('DROP TABLE IF EXISTS "' . $table_name . '" CASCADE');
    }
}