<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Форма входа API/UI prx400: поля верхнего уровня — скаляры.
 *
 * JSON {"code": {}} или ?q[]=x дают массив там, где сервис ждёт строку;
 * (string) от массива — предупреждение PHP, а Kohana превращает его в 500.
 * Здесь такой вход — 400 invalid_field. Списки допустимы только в полях
 * из $lists (quantities, events) и только из скаляров.
 */
final class Prx400_Input
{
    /** @return array те же данные, если форма допустима */
    public static function scalars(array $data, array $lists = []): array
    {
        foreach ($data as $key => $value)
        {
            if (is_array($value))
            {
                if ( ! in_array((string) $key, $lists, TRUE)) throw new Prx400_Error('invalid_field', 400);
                foreach ($value as $item)
                {
                    if ($item !== NULL AND ! is_scalar($item)) throw new Prx400_Error('invalid_field', 400);
                }
            }
            elseif ($value !== NULL AND ! is_scalar($value))
            {
                throw new Prx400_Error('invalid_field', 400);
            }
        }

        return $data;
    }

    /** Скаляр → строка; NULL → ''; массив/объект → 400 invalid_field. */
    public static function text($value): string
    {
        if ($value === NULL) return '';
        if ( ! is_scalar($value)) throw new Prx400_Error('invalid_field', 400);

        return (string) $value;
    }
}
