<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Тестер регулярных выражений (модуль NJ-622) — безопасный офлайн-прогон паттерна.
 *
 * Одна точка истины для страницы (/regex), API (/nj622/api/*) и агентов.
 * Только preg_* на сервере: паттерн прогоняется как серверный анализ (не чистый
 * браузер), отдаются совпадения с позициями и группами, замена, флаги.
 */
class Service_Nj622_Regex {

    /**
     * Прогон паттерна по тексту.
     *
     * @param  string $pattern /паттерн/флаги
     * @param  string $test    тестируемый текст
     * @param  string $replace строка замены (необязательно)
     * @param  string $flags   флаги из UI (i,m,s,u,x)
     * @return array
     */
    public static function run($pattern, $test, $replace = '', $flags = '')
    {
        $pattern = (string) $pattern;
        $test    = (string) $test;
        $replace = (string) $replace;
        $flags   = (string) $flags;

        if ($pattern === '')
        {
            return ['ok' => FALSE, 'error' => 'Введите регулярное выражение.'];
        }

        $delimiter = $pattern[0];

        if ( ! in_array($delimiter, ['/', '#', '~', '%', '!', '@'], TRUE))
        {
            return ['ok' => FALSE, 'error' => 'Паттерн должен быть обёрнут в разделитель: /паттерн/флаги или #паттерн#.'];
        }

        // найти закрывающий разделитель, учитывая экранирование и классы символов
        $end = self::find_delimiter_end($pattern, $delimiter);

        if ($end === NULL)
        {
            return ['ok' => FALSE, 'error' => 'Не найден закрывающий разделитель «'.$delimiter.'».'];
        }

        $inline_flags = substr($pattern, $end + 1);

        // итоговые модификаторы: inline + флаги UI, уникальные, только известные
        $mods = implode('', array_unique(str_split(preg_replace('/[^imsxU]/', '', $inline_flags.$flags))));

        $body     = substr($pattern, 1, $end - 1);
        $compiled = $delimiter.$body.$delimiter.$mods;

        // 1) проверка компиляции
        set_error_handler(function ($severity, $message) { throw new ErrorException($message, 0, $severity); });

        try
        {
            preg_match($compiled, '');
            restore_error_handler();
        }
        catch (Throwable $e)
        {
            restore_error_handler();
            $msg = $e->getMessage();

            if (preg_match('/preg_match\(\):\s*(.+)$/', $msg, $mm))
            {
                $msg = $mm[1];
            }

            return ['ok' => FALSE, 'error' => 'Ошибка компиляции: '.$msg];
        }

        // 2) совпадения
        $count = preg_match_all($compiled, $test, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if ($count === FALSE)
        {
            return ['ok' => FALSE, 'error' => 'Ошибка выполнения регулярного выражения.'];
        }

        $list = [];

        foreach ($matches as $i => $set)
        {
            $groups = [];

            foreach ($set as $gi => $cap)
            {
                if ($gi === 0)
                {
                    continue;
                }

                $groups[] = [
                    'index' => $gi,
                    'text'  => $cap[1] === -1 ? NULL : $cap[0],
                    'pos'   => $cap[1],
                ];
            }

            $list[] = [
                'n'      => $i + 1,
                'text'   => $set[0][0],
                'pos'    => $set[0][1],
                'groups' => $groups,
            ];
        }

        // 3) замена (только когда строка замены задана)
        $replaced      = NULL;
        $replace_err   = '';
        $replace_count = 0;

        if ($replace !== '')
        {
            $try = @preg_replace($compiled, $replace, $test, -1, $replace_count);

            if ($try === NULL)
            {
                $replace_err = 'Не удалось выполнить замену (проверьте $1, $2… и экранирование).';
            }
            else
            {
                $replaced = $try;
            }
        }

        return [
            'ok'           => TRUE,
            'error'        => '',
            'compiled'     => $compiled,
            'flags'        => $mods,
            'match_count'  => (int) $count,
            'matches'      => $list,
            'replaced'     => $replaced,
            'replace_count'=> (int) $replace_count,
            'replace_err'  => $replace_err,
        ];
    }

    /** Найти индекс закрывающего разделителя (учитывая \ и [...] классы). */
    protected static function find_delimiter_end($pattern, $delimiter)
    {
        $len      = strlen($pattern);
        $escaped  = FALSE;
        $in_class = FALSE;

        for ($i = 1; $i < $len; $i++)
        {
            $c = $pattern[$i];

            if ($escaped)
            {
                $escaped = FALSE;
                continue;
            }

            if ($c === '\\')
            {
                $escaped = TRUE;
                continue;
            }

            if ($c === '[')
            {
                $in_class = TRUE;
                continue;
            }

            if ($c === ']' && $in_class)
            {
                $in_class = FALSE;
                continue;
            }

            if ($c === $delimiter && ! $in_class)
            {
                return $i;
            }
        }

        return NULL;
    }
}
