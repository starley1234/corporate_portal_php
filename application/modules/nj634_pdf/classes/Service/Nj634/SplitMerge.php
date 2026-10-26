<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Разделение и объединение PDF (модуль NJ-634) на чистом PHP.
 *
 * Подход «репагинация объектов»: парсер находит объекты в разыменованном виде,
 * сборщик переносит выбранные страницы вместе с их прямыми зависимостями
 * (потоки содержимого, ресурсы, шрифты) в новый файл с новой таблицей xref.
 * Работает с классическим PDF (без xref-потоков и шифрования — их отвергает
 * Service_Nj634_Parser).
 */
class Service_Nj634_SplitMerge
{
    /**
     * Публичная обёртка над _pages() для сервисов-надстроек (nj636/nj637):
     * разобрать «объекты + страницы» существующего PDF.
     */
    public static function pages(string $bytes): array
    {
        return self::_pages($bytes);
    }

    /**
     * Публичная обёртка над _emit() для сервисов-надстроек: пересобрать PDF из
     * набора объектов (с уже внесёнными правками словарей) и выбранных страниц.
     */
    public static function emit(array $objects, array $wanted, int $total): array
    {
        return self::_emit($objects, $wanted, $total);
    }

    /** Выбрать страницы [start..end] (нумерация с 1). */
    public static function extract(string $bytes, int $start, int $end): array
    {
        $start = max(1, $start);
        $end   = max($start, $end);

        $parsed = self::_pages($bytes);

        if ( ! $parsed['ok'])
        {
            return $parsed;
        }

        $total = count($parsed['pages']);

        if ($start > $total)
        {
            return ['ok' => FALSE, 'error' => 'В файле '.$total.' страниц — диапазон начинается за пределами.'];
        }

        $end = min($end, $total);
        $wanted = [];

        for ($i = $start; $i <= $end; $i++)
        {
            $wanted[] = $parsed['pages'][$i - 1];
        }

        return self::_emit($parsed['objects'], $wanted, $total);
    }

    /** Перекомпоновать один файл: страницы в любом порядке и с повторами. */
    public static function merge_select(string $bytes, array $page_nums): array
    {
        $page_nums = array_values(array_filter(array_map('intval', $page_nums), function ($n) {
            return $n > 0;
        }));

        if ( ! $page_nums)
        {
            return ['ok' => FALSE, 'error' => 'Не выбрано ни одной страницы.'];
        }

        if (count($page_nums) > 200)
        {
            return ['ok' => FALSE, 'error' => 'Слишком много страниц (максимум 200).'];
        }

        $parsed = self::_pages($bytes);

        if ( ! $parsed['ok'])
        {
            return $parsed;
        }

        $total = count($parsed['pages']);

        foreach ($page_nums as $n)
        {
            if ($n > $total)
            {
                return ['ok' => FALSE, 'error' => 'Страница '.$n.' отсутствует (в файле '.$total.' стр.).'];
            }
        }

        $wanted = [];

        foreach ($page_nums as $n)
        {
            $wanted[] = $parsed['pages'][$n - 1];
        }

        return self::_emit($parsed['objects'], $wanted, $total);
    }

    /**
     * Объединить НЕСКОЛЬКО файлов: все страницы первого, потом второго и т.д.
     * Каждый файл — строка байтов PDF; ограничение 12 файлов / 400 страниц.
     */
    public static function merge_files(array $files): array
    {
        if ( ! $files OR count($files) > 12)
        {
            return ['ok' => FALSE, 'error' => 'Нужно от 1 до 12 файлов.'];
        }

        // нумеруем объекты со сдвигом, сразу переписывая ссылки внутри словарей,
        // чтобы номера разных файлов не пересекались.
        $pool       = [];
        $wanted     = [];
        $next       = 1;
        $total_pages = 0;

        foreach ($files as $bytes)
        {
            $parsed = self::_pages($bytes);

            if ( ! $parsed['ok'])
            {
                return $parsed;
            }

            $objects = $parsed['objects'];

            // 1) назначить каждому объекту файла новый виртуальный номер
            $remap = [];

            foreach (array_keys($objects) as $old_num)
            {
                $remap[(int) $old_num] = $next++;
            }

            // 2) страницы файла в итоговом порядке
            foreach ($parsed['pages'] as $old_page)
            {
                $wanted[] = $remap[$old_page];
                $total_pages++;
            }

            // 3) перенос объектов: словарь с переписанными ссылками
            $old_strs = array_keys($remap);
            usort($old_strs, function ($a, $b) { return strlen((string) $b) - strlen((string) $a); });
            $ref_re = '/\b(?:'.implode('|', array_map('strval', $old_strs)).')\s+0\s+R\b/';

            foreach ($objects as $old_num => $obj)
            {
                $dict = $obj['dict'];

                // отвязать /Parent у страниц (корень станет общим)
                if (preg_match('~/Type\s*/Page\b~', $dict))
                {
                    $dict = preg_replace('~/Parent\s+\d+\s+\d+\s+R\b~', ' ', $dict);
                }

                $dict = preg_replace_callback($ref_re, function ($m) use ($remap) {
                    $n = (int) $m[0];

                    return isset($remap[$n]) ? $remap[$n].' 0 R' : $m[0];
                }, $dict);

                $pool[$remap[(int) $old_num]] = [
                    'dict'   => $dict,
                    'stream' => $obj['stream'],
                ];
            }
        }

        if ($total_pages > 400)
        {
            return ['ok' => FALSE, 'error' => 'Слишком много страниц суммарно (максимум 400).'];
        }

        // собираем зависимости выбранных страниц
        $keep = [];
        $seen = [];

        $walk = function ($num) use (&$walk, &$keep, &$seen, $pool) {
            $num = (int) $num;

            if ($num <= 0 OR isset($seen[$num]) OR ! isset($pool[$num]))
            {
                return;
            }

            $seen[$num] = TRUE;
            $keep[$num] = $pool[$num];
            $dict = $pool[$num]['dict'];

            if (preg_match_all('~/(\w+)\s+(\d+)\s+\d+\s+R\b~', $dict, $m))
            {
                foreach ($m[2] as $ref)
                {
                    $walk($ref);
                }
            }

            if (preg_match_all('~\[\s*((?:\d+\s+\d+\s+R\s*)*)\]~', $dict, $arrs))
            {
                foreach ($arrs[1] as $block)
                {
                    if (preg_match_all('~(\d+)\s+\d+\s+R~', $block, $inner))
                    {
                        foreach ($inner[1] as $ref)
                        {
                            $walk($ref);
                        }
                    }
                }
            }
        };

        foreach ($wanted as $pn)
        {
            $walk($pn);
        }

        return self::_emit($keep, $wanted, $total_pages);
    }

    // --------------------------------------------------------------- ядро

    protected static function _pages(string $bytes): array
    {
        if (substr($bytes, 0, 5) !== '%PDF-')
        {
            return ['ok' => FALSE, 'error' => 'Это не PDF: файл должен начинаться с %PDF-.'];
        }

        if (preg_match('~/Encrypt\b~', $bytes))
        {
            return ['ok' => FALSE, 'error' => 'PDF зашифрован — операции недоступны.'];
        }

        $objects = Service_Nj634_Parser::objects($bytes);

        if ( ! $objects)
        {
            return ['ok' => FALSE, 'error' => Service_Nj634_Parser::unsupported_reason($bytes)
                ?: 'Не удалось разобрать PDF (возможно, xref-поток).'];
        }

        $tree = Service_Nj634_Parser::tree($objects);

        if ($tree === NULL OR ! $tree['pages'])
        {
            return ['ok' => FALSE, 'error' => 'Не найдены страницы (/Pages).'];
        }

        return ['ok' => TRUE, 'objects' => $objects, 'pages' => $tree['pages']];
    }

    protected static function _emit(array $objects, array $wanted, int $total): array
    {
        // 1) собираем объекты-зависимости выбранных страниц
        $keep = [];
        $seen = [];

        $walk = function ($num) use (&$walk, &$keep, &$seen, $objects) {
            $num = (int) $num;

            if ($num <= 0 OR isset($seen[$num]) OR ! isset($objects[$num]))
            {
                return;
            }

            $seen[$num] = TRUE;
            $keep[$num] = $objects[$num];
            $dict = $objects[$num]['dict'];

            if (preg_match_all('~/(\w+)\s+(\d+)\s+\d+\s+R\b~', $dict, $m))
            {
                foreach ($m[2] as $ref)
                {
                    $walk($ref);
                }
            }

            // массивные ссылки (например /Kids [1 0 R 2 0 R])
            if (preg_match_all('~\[\s*((?:\d+\s+\d+\s+R\s*)*)\]~', $dict, $arrs))
            {
                foreach ($arrs[1] as $block)
                {
                    if (preg_match_all('~(\d+)\s+\d+\s+R~', $block, $inner))
                    {
                        foreach ($inner[1] as $ref)
                        {
                            $walk($ref);
                        }
                    }
                }
            }
        };

        foreach ($wanted as $pn)
        {
            $walk($pn);
        }

        // 2) перенумерация (сохраняем возрастающий порядок старых номеров)
        $old_refs = array_keys($keep);
        sort($old_refs, SORT_NUMERIC);

        $mapping = [];
        $next = 1;

        foreach ($old_refs as $old)
        {
            $mapping[$old] = $next++;
        }

        $root_num = $next++;
        $cat_num  = $next;

        // 2.1) единый проход замены «N 0 R» → «newN 0 R» (без повторной перезамены)
        $old_strs = array_map('strval', array_keys($mapping));
        usort($old_strs, function ($a, $b) { return strlen($b) - strlen($a); });
        $ref_re = '/\b(?:'.implode('|', $old_strs).')\s+0\s+R\b/';

        $map_fn = function ($m) use ($mapping) {
            $n = (int) $m[0];

            return isset($mapping[$n]) ? $mapping[$n].' 0 R' : $m[0];
        };

        $final = [];

        foreach ($old_refs as $old)
        {
            $obj = $keep[$old];
            $dict = $obj['dict'];

            // страница: отвязываем старый /Parent — станет наш корень
            if (preg_match('~/Type\s*/Page\b~', $dict))
            {
                $dict = preg_replace('~/Parent\s+\d+\s+\d+\s+R\b~', ' ', $dict);
            }

            // ремап ссылок «N 0 R» — ТОЛЬКО в словаре, не в потоке:
            // бинарные байты шрифта/контента могли бы случайно совпасть
            $dict = preg_replace_callback($ref_re, $map_fn, $dict);

            if ($obj['stream'] === NULL)
            {
                $body = $dict;
            }
            else
            {
                $body = $dict."\nstream\n".$obj['stream']."\nendstream";
            }

            $final[$mapping[$old]] = $body;
        }

        // корень и каталог (всегда в собственной словарной обёртке!)
        $page_refs = array_map(function ($pn) use ($mapping) {
            return $mapping[$pn];
        }, $wanted);

        $kids = implode(' ', array_map(function ($r) { return $r.' 0 R'; }, $page_refs));

        $final[$root_num] = '<< /Type /Pages /Kids ['.$kids.'] /Count '.count($wanted).' >>';
        $final[$cat_num]  = '<< /Type /Catalog /Pages '.$root_num.' 0 R >>';

        $pdf = Service_Nj634_Writer::build($final, $cat_num);

        if ($pdf === '')
        {
            return ['ok' => FALSE, 'error' => 'Не удалось собрать результирующий PDF.'];
        }

        return ['ok' => TRUE, 'pdf' => $pdf, 'pages' => count($wanted),
                'source_pages' => $total, 'size' => strlen($pdf)];
    }
}
