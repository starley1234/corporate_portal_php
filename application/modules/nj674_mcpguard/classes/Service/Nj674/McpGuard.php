<?php defined('SYSPATH') or die('No direct script access.');

/** Безсетевой статический разбор описаний инструментов MCP. */
class Service_Nj674_McpGuard
{
    const MAX_BYTES = 65536;
    const MAX_TOOLS = 100;
    const MAX_NAME  = 128;
    const MAX_DESC  = 4000;

    /** Разобрать tools/list или прямой массив tools; исходный JSON не сохраняется. */
    public static function analyze($json)
    {
        $json = (string) $json;
        if (strlen($json) > self::MAX_BYTES)
        {
            return ['error' => 'JSON превышает лимит 64 КБ.'];
        }

        $data = json_decode($json, TRUE);
        if ( ! is_array($data) OR json_last_error() !== JSON_ERROR_NONE)
        {
            return ['error' => 'Не удалось разобрать JSON: '.json_last_error_msg()];
        }

        $tools = $data['tools'] ?? ($data['result']['tools'] ?? NULL);
        if ( ! is_array($tools) OR ! array_is_list($tools))
        {
            return ['error' => 'Ожидается массив tools или ответ MCP tools/list с полем result.tools.'];
        }
        if (count($tools) > self::MAX_TOOLS)
        {
            return ['error' => 'В одном документе поддерживается не больше 100 инструментов.'];
        }

        $seen = [];
        $items = [];
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($tools as $index => $raw)
        {
            if ( ! is_array($raw))
            {
                $items[] = ['name' => 'Инструмент '.($index + 1), 'findings' => [[
                    'severity' => 'high', 'code' => 'invalid_tool',
                    'message' => 'Элемент не является объектом JSON.',
                ]]];
                $counts['high']++;
                continue;
            }

            $name = trim((string) ($raw['name'] ?? ''));
            $description = trim((string) ($raw['description'] ?? ''));
            $schema = is_array($raw['inputSchema'] ?? NULL) ? $raw['inputSchema'] : [];
            $annotations = is_array($raw['annotations'] ?? NULL) ? $raw['annotations'] : [];
            $findings = [];

            if ($name === '' OR strlen($name) > self::MAX_NAME OR ! preg_match('/^[a-zA-Z0-9_.:-]+$/', $name))
            {
                $findings[] = self::_finding('high', 'invalid_name', 'Имя отсутствует или не соответствует ожидаемому формату.');
            }
            elseif (isset($seen[mb_strtolower($name)]))
            {
                $findings[] = self::_finding('high', 'duplicate_name', 'Имя инструмента повторяется: маршрутизация и аудит могут стать неоднозначными.');
            }
            else
            {
                $seen[mb_strtolower($name)] = TRUE;
            }

            if ($description === '')
            {
                $findings[] = self::_finding('medium', 'missing_description', 'Нет описания назначения и границ инструмента.');
            }
            elseif (strlen($description) > self::MAX_DESC)
            {
                $findings[] = self::_finding('low', 'long_description', 'Описание длиннее 4 КБ; проверьте, что туда не попали секреты или инструкции для модели.');
            }

            if (preg_match('/(?:shell|terminal|command|exec|execute|delete|remove|write|upload|send|payment|sql|database|filesystem|browser|http)/i', $name))
            {
                $findings[] = self::_finding('high', 'powerful_name', 'Имя похоже на инструмент с побочными эффектами или широким доступом; проверьте минимальные полномочия и подтверждение человеком.');
            }

            $read_only = ($annotations['readOnlyHint'] ?? NULL) === TRUE;
            $destructive = ($annotations['destructiveHint'] ?? NULL) === TRUE;
            $open_world = ($annotations['openWorldHint'] ?? NULL) === TRUE;
            if ($destructive AND $read_only)
            {
                $findings[] = self::_finding('high', 'contradictory_annotations', 'Инструмент помечен одновременно как изменяющий данные и только для чтения.');
            }
            if ($open_world)
            {
                $findings[] = self::_finding('medium', 'open_world', 'Инструмент помечен openWorldHint: ограничьте домены, ресурсы и объём действий.');
            }
            if ( ! $read_only AND ! $destructive)
            {
                $findings[] = self::_finding('low', 'side_effects_unspecified', 'Не указано, может ли вызов менять состояние; выставьте корректные аннотации MCP.');
            }

            if ($schema === [])
            {
                $findings[] = self::_finding('medium', 'missing_schema', 'Нет inputSchema: нельзя проверить границы и обязательность входных данных.');
            }
            else
            {
                if (($schema['type'] ?? '') !== 'object' OR ! is_array($schema['properties'] ?? NULL))
                {
                    $findings[] = self::_finding('medium', 'broad_schema', 'Схема должна ограничивать вход объектом с явными properties.');
                }
                if (($schema['additionalProperties'] ?? NULL) === TRUE)
                {
                    $findings[] = self::_finding('medium', 'extra_properties', 'Разрешены произвольные дополнительные поля. Запретите их, если это не требуется контрактом.');
                }
                $props = (array) ($schema['properties'] ?? []);
                if (count($props) > 40)
                {
                    $findings[] = self::_finding('low', 'wide_schema', 'Слишком много полей входа; сократите поверхность инструмента.');
                }
                foreach ($props as $field => $spec)
                {
                    if ( ! is_array($spec) OR empty($spec['description']))
                    {
                        $findings[] = self::_finding('low', 'field_without_description', 'Поле «'.mb_substr((string) $field, 0, 64).'» не объясняет допустимые значения.');
                        break;
                    }
                }
            }

            foreach ($findings as $finding)
            {
                $counts[$finding['severity']]++;
            }

            $items[] = [
                'name' => $name !== '' ? mb_substr($name, 0, self::MAX_NAME) : 'Инструмент '.($index + 1),
                'findings' => $findings,
            ];
        }

        $risk = $counts['high'] > 0 ? 'high' : ($counts['medium'] > 0 ? 'review' : 'low');

        return [
            'error' => '', 'tool_count' => count($items),
            'risk' => $risk, 'counts' => $counts, 'tools' => $items,
            'scope' => 'Статический разбор метаданных; серверы MCP не вызываются, поведение во время исполнения не проверяется.',
        ];
    }

    protected static function _finding($severity, $code, $message)
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message];
    }
}
