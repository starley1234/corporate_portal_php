<?php defined('SYSPATH') or die('No direct script access.');

/** Общие пути локальных хранилищ проекта. */
class Storage_Path
{
    /**
     * Старый public_html/storage переехал в storage рядом с public_html.
     * Перенаправляем и относительные, и абсолютные пути, в том числе через
     * alias корня проекта. Данные из старого каталога переносит оператор:
     * смена пути в конфиге сама по себе файлы не копирует.
     */
    public static function relocate_legacy($path)
    {
        if ( ! is_string($path) OR $path === '')
        {
            return $path;
        }

        $normalized = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', dirname(APPPATH)), '/');
        $relative = preg_replace('~^(?:\./)+~', '', $normalized);
        $prefix = 'public_html/storage';
        $suffix = NULL;

        if ($relative === $prefix OR strpos($relative, $prefix.'/') === 0)
        {
            $suffix = substr($relative, strlen($prefix));
        }
        else
        {
            $marker = '/public_html/storage';
            $actual_docroot = realpath($root.'/public_html');
            $offset = 0;
            while (($pos = strpos($normalized, $marker, $offset)) !== FALSE)
            {
                $tail = substr($normalized, $pos + strlen($marker));
                $candidate_docroot = realpath(substr($normalized, 0, $pos).'/public_html');

                if (($tail === '' OR $tail[0] === '/') AND $actual_docroot !== FALSE
                    AND $candidate_docroot !== FALSE AND $candidate_docroot === $actual_docroot)
                {
                    $suffix = $tail;
                    break;
                }
                $offset = $pos + 1;
            }
        }

        if ($suffix === NULL)
        {
            return $path;
        }

        if (preg_match('~(?:^|/)\.\.(?:/|$)~', $suffix))
        {
            throw new InvalidArgumentException('Недопустимый путь старого хранилища: '.$path);
        }

        return $root.'/storage'.$suffix;
    }

    /** Относительные пути локального хранилища считаются от корня проекта. */
    public static function absolute($path)
    {
        if ( ! is_string($path) OR trim($path) === '' OR strpos($path, "\0") !== FALSE)
        {
            throw new InvalidArgumentException('Не задан путь локального хранилища');
        }

        $path = self::relocate_legacy($path);
        if ($path[0] !== '/' AND ! preg_match('~^[A-Za-z]:[\\\\/]~', $path))
        {
            $path = rtrim(dirname(APPPATH), '/\\').DIRECTORY_SEPARATOR.$path;
        }

        // При первом обращении действуют те же ограничения для штатного
        // storage, что и в setup: ни корень, ни вложенные каталоги не ссылки.
        self::_check_project_storage($path);

        // Не разрешаем явно настроить новый локальный backend в docroot.
        // Проверяем и существующих родителей, чтобы заметить переход по symlink,
        // даже если конечной папки пока нет.
        $docroot = @realpath(rtrim(dirname(APPPATH), '/\\').DIRECTORY_SEPARATOR.'public_html');
        if ($docroot !== FALSE)
        {
            $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
            $resolved = rtrim(str_replace('\\', '/', self::_resolved_for_check($path)), '/');
            if ($resolved === $docroot OR strpos($resolved, $docroot.'/') === 0)
            {
                throw new InvalidArgumentException('Локальное хранилище не может находиться в public_html: '.$path);
            }
        }

        return $path;
    }

    /** Не следовать ссылкам внутри штатного project/storage, включая его корень. */
    private static function _check_project_storage($path)
    {
        $root = rtrim(str_replace('\\', '/', dirname(APPPATH)), '/');
        $normalized = preg_replace('~/+~', '/', str_replace('\\', '/', $path));
        $normalized = preg_replace('~/(?:\./)+~', '/', $normalized);
        $prefix = $root.'/storage';
        if ($normalized !== $prefix AND strpos($normalized, $prefix.'/') !== 0)
        {
            return;
        }

        $current = $prefix;
        $parts = explode('/', ltrim(substr($normalized, strlen($prefix)), '/'));
        array_unshift($parts, '');
        foreach ($parts as $part)
        {
            if ($part === '..')
            {
                throw new InvalidArgumentException('Недопустимый путь с .. внутри storage: '.$path);
            }
            if ($part !== '') { $current .= '/'.$part; }
            clearstatcache(TRUE, $current);
            if (is_link($current))
            {
                throw new InvalidArgumentException('Symlink внутри project/storage запрещён: '.$current);
            }
        }
    }

    /** Аналог realpath для ещё не созданной конечной папки. */
    private static function _resolved_for_check($path)
    {
        $probe = rtrim($path, '/\\') ?: DIRECTORY_SEPARATOR;
        $tail = [];
        while (($resolved = @realpath($probe)) === FALSE)
        {
            $parent = dirname($probe);
            if ($parent === $probe)
            {
                return $path;
            }
            $tail[] = basename($probe);
            $probe = $parent;
        }

        foreach (array_reverse($tail) as $part)
        {
            if ($part === '' OR $part === '.') { continue; }
            if ($part === '..') { $resolved = dirname($resolved); continue; }
            $resolved = rtrim($resolved, '/\\').DIRECTORY_SEPARATOR.$part;
        }

        return $resolved;
    }
}
