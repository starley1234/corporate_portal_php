<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сериализатор PDF (модуль NJ-634) — собирает файл из готовых объектов:
 * заголовок, объекты, таблица xref, trailer. Числа объектов выставляем сами,
 * писатель только склеивает байты и считает смещения.
 */
class Service_Nj634_Writer
{
    /**
     * $objects — ассоциативный массив «номер объекта => тело» (тело без обёртки
     * «N 0 obj / endobj», но МОЖЕТ содержать stream/endstream).
     * $root_ref — объект /Catalog. $extra_trailer — доп. ключи для trailer.
     */
    public static function build(array $objects, int $root_ref, array $extra_trailer = []): string
    {
        $objects = array_filter($objects, function ($body) {
            return $body !== NULL;
        });

        if ( ! $objects)
        {
            return '';
        }

        ksort($objects, SORT_NUMERIC);
        $max = max(array_keys($objects));

        $out  = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $num => $body)
        {
            $offsets[$num] = strlen($out);
            $out .= $num." 0 obj\n".$body."\nendobj\n";
        }

        $xref_pos = strlen($out);
        $out .= "xref\n";
        $out .= "0 ".($max + 1)."\n";
        $out .= "0000000000 65535 f \n";

        for ($i = 1; $i <= $max; $i++)
        {
            if (isset($offsets[$i]))
            {
                $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
            }
            else
            {
                $out .= "0000000000 65535 f \n";
            }
        }

        $trailer = "/Size ".($max + 1)."\n/Root ".$root_ref." 0 R\n";

        foreach ($extra_trailer as $k => $v)
        {
            $trailer .= $k." ".$v."\n";
        }

        $out .= "trailer\n<< ".$trailer." >>\nstartxref\n".$xref_pos."\n%%EOF\n";

        return $out;
    }

    /** Объект-поток: dict + stream. $dict уже без «<< >>». */
    public static function stream(int $num, string $dict, string $data): string
    {
        return "<< ".$dict." /Length ".strlen($data)." >>\nstream\n".$data."\nendstream";
    }

    /** Словарь-объект без потока. */
    public static function dict(int $num, string $dict): string
    {
        return "<< ".$dict." >>";
    }
}
