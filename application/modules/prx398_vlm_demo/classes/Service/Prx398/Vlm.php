<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Синхронный VLM-разбор страниц письма.
 *
 * Никаких записей в БД и очереди: вызывающий контроллер передаёт временные
 * файлы загруженных изображений, сервис проверяет их, кодирует в data URI и
 * отправляет в vision-модель через общий Llm-модуль. Результат — предложение
 * для человека, а не изменение карточки.
 */
class Service_Prx398_Vlm
{
    protected static $_config;

    public static function config($key = NULL, $default = NULL)
    {
        if (self::$_config === NULL)
        {
            self::$_config = Kohana::$config->load('prx398_vlm')->as_array();
        }

        if ($key === NULL)
        {
            return self::$_config;
        }

        return array_key_exists($key, self::$_config) ? self::$_config[$key] : $default;
    }

    public static function field_names()
    {
        return array_keys((array) self::config('field_labels', []));
    }

    /**
     * Провайдер должен быть разрешён явно: модель нельзя передать из браузера.
     */
    public static function provider_for_model($model)
    {
        $model = trim((string) $model);
        $pos = strpos($model, '::');
        $provider = $pos === FALSE ? '' : substr($model, 0, $pos);
        $allowed = (array) self::config('allowed_providers', ['local']);

        if ($provider === '' OR ! in_array($provider, $allowed, TRUE))
        {
            throw new RuntimeException(
                'Провайдер модели "'.$provider.'" не разрешён для демо (только '.implode(', ', $allowed).')'
            );
        }

        return $provider;
    }

    /**
     * @param array $files [['path' => '/tmp/...', 'name' => 'page-1.jpg', 'size' => 123], …]
     * @param array $actor не используется для прав; оставлен для единого контракта
     * @return array
     */
    public static function analyze(array $files, array $actor = [])
    {
        $model = (string) self::config('model');
        self::provider_for_model($model);

        if ( ! class_exists('Llm'))
        {
            throw new RuntimeException('LLM-модуль не подключён');
        }

        $max_pages = (int) self::config('max_pages', 3);
        if ($max_pages < 1)
        {
            throw new RuntimeException('В конфигурации не задан max_pages');
        }

        $files = array_values(array_slice($files, 0, $max_pages));
        if ( ! $files)
        {
            throw new InvalidArgumentException('Добавьте хотя бы одну страницу письма');
        }

        $images = [];
        foreach ($files as $index => $file)
        {
            $path = (string) ($file['path'] ?? '');
            $name = (string) ($file['name'] ?? ('page-'.($index + 1).'.jpg'));
            $info = self::_validate_image($path, $name);

            $bytes = @file_get_contents($path);
            if ($bytes === FALSE OR $bytes === '')
            {
                throw new InvalidArgumentException('Не удалось прочитать изображение «'.$name.'»');
            }

            $images[] = [
                'name' => $name,
                'mime' => $info['mime'],
                'width' => $info['width'],
                'height' => $info['height'],
                'data_uri' => 'data:'.$info['mime'].';base64,'.base64_encode($bytes),
            ];
        }

        $types = [];
        foreach ((array) self::config('types', []) as $code => $title)
        {
            $types[] = $code.' — '.$title;
        }

        $user_prompt = strtr((string) self::config('user'), [
            '{{types}}' => implode("\n", $types),
        ]);

        $content = [['type' => 'text', 'text' => $user_prompt]];
        foreach ($images as $image)
        {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $image['data_uri']],
            ];
        }

        $messages = [
            ['role' => 'system', 'content' => (string) self::config('system')],
            ['role' => 'user', 'content' => $content],
        ];

        $options = [
            'temperature' => (float) self::config('temperature', 0.1),
            'max_tokens'  => (int) self::config('max_tokens', 3000),
        ];

        $started = microtime(TRUE);

        try
        {
            $response = Llm::model($model)->chat($messages, $options);
        }
        catch (Exception $e)
        {
            throw new RuntimeException('Модель недоступна: '.$e->getMessage(), 0, $e);
        }

        $duration_ms = (int) round((microtime(TRUE) - $started) * 1000);
        $text = self::_content_text($response['choices'][0]['message']['content'] ?? '');
        if ($text === '')
        {
            throw new RuntimeException('Модель вернула пустой ответ');
        }

        $raw = self::parse_json_reply($text);

        return [
            'model' => (string) ($response['model'] ?? $model),
            'requested_model' => $model,
            'prompt_version' => (string) self::config('prompt_version'),
            'duration_ms' => $duration_ms,
            'usage' => is_array($response['usage'] ?? NULL) ? $response['usage'] : [],
            'pages' => count($images),
            'images' => array_map(function ($image) {
                return [
                    'name' => $image['name'],
                    'mime' => $image['mime'],
                    'width' => $image['width'],
                    'height' => $image['height'],
                ];
            }, $images),
            'result' => self::normalize_result($raw),
            'raw' => $raw,
            'raw_text' => mb_substr($text, 0, 50000),
        ];
    }

    /**
     * Проверка одного изображения: MIME, размер, реальные пиксели.
     */
    protected static function _validate_image($path, $name)
    {
        if ($path === '' OR ! is_file($path) OR ! is_readable($path))
        {
            throw new InvalidArgumentException('Файл «'.$name.'» не найден');
        }

        $max_bytes = (int) self::config('max_page_bytes', 2 * 1024 * 1024);
        $size = (int) @filesize($path);
        if ($size < 1 OR $size > $max_bytes)
        {
            throw new InvalidArgumentException(
                'Страница «'.$name.'» больше '.round($max_bytes / 1048576, 1).' МБ'
            );
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);
        if ( ! in_array($mime, ['image/jpeg', 'image/png'], TRUE))
        {
            throw new InvalidArgumentException('Поддерживаются только JPEG и PNG');
        }

        $dimensions = @getimagesize($path);
        if ( ! is_array($dimensions) OR empty($dimensions[0]) OR empty($dimensions[1]))
        {
            throw new InvalidArgumentException('Не удалось определить размер изображения «'.$name.'»');
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $max_width = (int) self::config('max_image_width', 2200);
        $max_height = (int) self::config('max_image_height', 3400);

        if ($width > $max_width OR $height > $max_height)
        {
            throw new InvalidArgumentException(
                'Страница «'.$name.'» слишком большая: '.$width.'×'.$height
                .', максимум '.$max_width.'×'.$max_height
            );
        }

        return [
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Content может быть строкой или массивом частей (некоторые провайдеры).
     */
    protected static function _content_text($content)
    {
        if (is_string($content))
        {
            return trim($content);
        }

        if ( ! is_array($content))
        {
            return '';
        }

        $parts = [];
        foreach ($content as $part)
        {
            if (is_string($part))
            {
                $parts[] = $part;
                continue;
            }
            if (is_array($part) AND isset($part['text']) AND is_scalar($part['text']))
            {
                $parts[] = (string) $part['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * Снимает markdown-обёртку и выделяет первый JSON-объект.
     */
    public static function parse_json_reply($text)
    {
        $text = trim((string) $text);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/i', '', $text);
        $text = trim($text);

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === FALSE OR $end === FALSE OR $end <= $start)
        {
            throw new RuntimeException('Модель вернула не JSON');
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), TRUE);
        if ( ! is_array($decoded))
        {
            throw new RuntimeException('Модель вернула невалидный JSON: '.json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Оставляет только известные поля и приводит типы к безопасному виду.
     */
    public static function normalize_result(array $raw)
    {
        $types = (array) self::config('types', []);
        $type = self::_string($raw['type'] ?? '', 40);
        if ($type === '' OR ! array_key_exists($type, $types))
        {
            $type = 'other';
        }

        $raw_fields = (isset($raw['fields']) AND is_array($raw['fields'])) ? $raw['fields'] : [];
        $fields = [];
        foreach (self::field_names() as $name)
        {
            $item = $raw_fields[$name] ?? NULL;

            // Некоторые модели «сплющивают» поля до верхнего уровня.
            if ($item === NULL AND array_key_exists($name, $raw))
            {
                $item = $raw[$name];
            }

            if (is_scalar($item))
            {
                $item = ['value' => $item];
            }
            if ( ! is_array($item))
            {
                continue;
            }

            $value = self::_string($item['value'] ?? '', 500);
            $quote = self::_string($item['quote'] ?? '', 300);
            $page = (int) ($item['page'] ?? 0);
            if ($page < 0 OR $page > (int) self::config('max_pages', 3))
            {
                $page = 0;
            }

            $unit = self::_string($item['value'] ?? '', 20);
            if ($name === 'deadline_hint_unit' AND ! in_array($unit, ['calendar', 'business'], TRUE))
            {
                $value = '';
            }

            if ($name === 'sender_inn')
            {
                $value = preg_replace('/\D+/', '', $value);
                if (strlen($value) !== 10 AND strlen($value) !== 12)
                {
                    $value = '';
                }
            }

            $fields[$name] = [
                'value' => $value,
                'quote' => $quote,
                'confidence' => self::_confidence($item['confidence'] ?? 0),
                'page' => $page,
            ];
        }

        $risk = (isset($raw['risk']) AND is_array($raw['risk'])) ? $raw['risk'] : [];
        $level = self::_string($risk['level'] ?? 'unknown', 12);
        if ( ! in_array($level, ['low', 'medium', 'high', 'unknown'], TRUE))
        {
            $level = 'unknown';
        }

        $reasons = [];
        foreach ((array) ($risk['reasons'] ?? []) as $reason)
        {
            $reason = self::_string($reason, 300);
            if ($reason !== '')
            {
                $reasons[] = $reason;
            }
            if (count($reasons) >= 10)
            {
                break;
            }
        }

        $findings = [];
        foreach ((array) ($raw['findings'] ?? []) as $finding)
        {
            if (is_string($finding))
            {
                $finding = ['kind' => 'other', 'text' => $finding];
            }
            if ( ! is_array($finding))
            {
                continue;
            }
            $finding_text = self::_string($finding['text'] ?? '', 500);
            if ($finding_text === '')
            {
                continue;
            }
            $findings[] = [
                'kind' => self::_string($finding['kind'] ?? 'other', 32),
                'text' => $finding_text,
            ];
            if (count($findings) >= 20)
            {
                break;
            }
        }

        $visual_marks = [];
        foreach ((array) ($raw['visual_marks'] ?? []) as $mark)
        {
            $mark = self::_string($mark, 120);
            if ($mark !== '')
            {
                $visual_marks[] = $mark;
            }
            if (count($visual_marks) >= 10)
            {
                break;
            }
        }

        return [
            'type' => $type,
            'type_title' => $types[$type] ?? 'Прочее',
            'type_confidence' => self::_confidence($raw['type_confidence'] ?? 0),
            'fields' => $fields,
            'summary' => self::_string($raw['summary'] ?? '', 3000),
            'risk' => [
                'level' => $level,
                'reasons' => $reasons,
            ],
            'findings' => $findings,
            'visual_marks' => $visual_marks,
            'confidence' => self::_confidence($raw['confidence'] ?? 0),
        ];
    }

    protected static function _string($value, $max)
    {
        if (is_array($value) OR is_object($value) OR $value === NULL)
        {
            return '';
        }

        $value = trim((string) $value);

        return mb_substr($value, 0, max(1, (int) $max));
    }

    protected static function _confidence($value)
    {
        if ( ! is_numeric($value))
        {
            return 0.0;
        }

        $value = (float) $value;
        if ($value > 2.0 AND $value <= 100.0)
        {
            // Модели иногда отдают проценты (95), а не долю (0.95).
            $value = $value / 100.0;
        }

        // 1.1…2.0 — уже не доля и ещё не проценты; безопаснее ограничить.
        return max(0.0, min(1.0, $value));
    }
}
