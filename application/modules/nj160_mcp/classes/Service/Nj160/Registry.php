<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Реестр MCP-инструментов: собирает декларации config/mcp.php всех
 * подключённых модулей (каскад по Kohana::modules()).
 */
class Service_Nj160_Registry {

    /** @var array|NULL кеш собранного реестра */
    protected static $_tools = NULL;

    /**
     * Все инструменты всех модулей (без фильтров доступа — служебное).
     *
     * @return  array  name => ['name','description','inputSchema','handler','module','access']
     */
    public static function tools_raw()
    {
        if (self::$_tools !== NULL)
        {
            return self::$_tools;
        }

        self::$_tools = [];

        foreach (Kohana::modules() as $module => $path)
        {
            $file = rtrim($path, '/').'/config/mcp.php';

            if ( ! is_file($file))
            {
                continue;
            }

            $decl = include $file;

            if ( ! is_array($decl) OR empty($decl['tools']))
            {
                continue;
            }

            foreach ((array) $decl['tools'] as $tool)
            {
                if (empty($tool['name']) OR empty($tool['handler']))
                {
                    continue;
                }

                $tool['module'] = $module;
                $tool['access'] = (string) ($tool['access'] ?? 'user');
                self::$_tools[$tool['name']] = $tool;
            }
        }

        return self::$_tools;
    }

    /**
     * Инструменты, видимые вызывающему: уровень доступа (admin/user),
     * категории из конфигурации хаба и точечный чёрный список.
     */
    public static function tools_for(bool $is_admin)
    {
        $allowed  = class_exists('Service_Nj160_HubConfig') ? Service_Nj160_HubConfig::allowed_categories() : NULL;
        $disabled = class_exists('Service_Nj160_HubConfig') ? Service_Nj160_HubConfig::disabled_tools() : [];

        $out = [];

        foreach (self::tools_raw() as $name => $tool)
        {
            if ((string) $tool['access'] === 'admin' AND ! $is_admin)
            {
                continue;
            }

            if (in_array((string) $name, $disabled, TRUE))
            {
                continue;
            }

            if ($allowed !== NULL)
            {
                $cat = (string) ($tool['category'] ?? $tool['module']);

                if ( ! in_array($cat, $allowed, TRUE))
                {
                    continue;
                }
            }

            $out[$name] = $tool;
        }

        return $out;
    }

    /** Описание инструментов в формате MCP (tools/list). */
    public static function mcp_list(bool $is_admin = FALSE)
    {
        $out = [];

        foreach (self::tools_for($is_admin) as $tool)
        {
            $out[] = [
                'name'        => (string) $tool['name'],
                'description' => (string) ($tool['description'] ?? ''),
                'inputSchema' => self::_schema_objects(
                    isset($tool['inputSchema']) ? (array) $tool['inputSchema'] : ['type' => 'object']
                ),
            ];
        }

        return $out;
    }

    /**
     * Пустые «карты» схемы — объектами, а не массивами.
     *
     * PHP кодирует пустой массив как `[]`, а JSON Schema требует объект для
     * `properties`. Конфиги модулей пишут `'properties' => []` для инструментов
     * без аргументов — и строгий клиент (OpenCode) отвергает ВЕСЬ `tools/list`
     * с сообщением «expected record, received array», после чего не работает
     * ни один инструмент. Держим схему в форме, которую понимает любой клиент.
     *
     * @param  array $schema
     * @return array
     */
    protected static function _schema_objects(array $schema)
    {
        $maps = ['properties', 'patternProperties', 'definitions', '$defs'];

        foreach ($maps as $key)
        {
            if ( ! array_key_exists($key, $schema))
            {
                continue;
            }

            if ($schema[$key] === [])
            {
                $schema[$key] = new stdClass();
                continue;
            }

            if (is_array($schema[$key]))
            {
                foreach ($schema[$key] as $name => $sub)
                {
                    if (is_array($sub))
                    {
                        $schema[$key][$name] = self::_schema_objects($sub);
                    }
                }
            }
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $key)
        {
            if (isset($schema[$key]) AND is_array($schema[$key]))
            {
                foreach ($schema[$key] as $index => $sub)
                {
                    if (is_array($sub))
                    {
                        $schema[$key][$index] = self::_schema_objects($sub);
                    }
                }
            }
        }

        foreach (['items', 'additionalProperties', 'not'] as $key)
        {
            if (isset($schema[$key]) AND is_array($schema[$key]))
            {
                $schema[$key] = self::_schema_objects($schema[$key]);
            }
        }

        return $schema;
    }

    /**
     * Вызвать инструмент.
     *
     * $user_id — владелец персонального ключа. Передаём его обработчику в
     * служебном ключе _user_id: иначе модульный инструмент не знает, в чьё
     * рабочее пространство писать, и вынужден угадывать админа. Значение
     * всегда перезаписывается здесь, поэтому подделать его из arguments
     * нельзя; у legacy-ключа (NULL) ключ вычищается.
     *
     * @param   string  $name
     * @param   array   $args
     * @param   bool    $is_admin
     * @param   int|NULL $user_id
     * @return  array   результат обработчика
     * @throws  Kohana_Exception   инструмент не найден / обработчик не вызывался
     */
    public static function call($name, array $args, bool $is_admin = FALSE, $user_id = NULL)
    {
        if ($user_id !== NULL) {
            $args['_user_id'] = (int) $user_id;
        } else {
            unset($args['_user_id']);
        }

        $tools = self::tools_for($is_admin);

        if ( ! isset($tools[$name]))
        {
            throw new Kohana_Exception('MCP-инструмент «:name» недоступен этому ключу (не найден или запрещён настройками хаба/уровнем доступа)', [':name' => $name]);
        }

        $handler = $tools[$name]['handler'];

        if (is_string($handler) AND strpos($handler, '::') !== FALSE)
        {
            $handler = explode('::', $handler, 2);
        }

        if ( ! is_callable($handler))
        {
            throw new Kohana_Exception('MCP-обработчик «:name» не вызываемый', [':name' => $name]);
        }

        $result = call_user_func($handler, $args);

        return is_array($result) ? $result : ['result' => $result];
    }
}
