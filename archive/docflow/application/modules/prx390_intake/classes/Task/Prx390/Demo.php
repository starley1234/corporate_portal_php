<?php defined('SYSPATH') or die('No direct script access.');

/** Только для демо: bin/console prx390:demo. Реальные файлы не перезаписывает. */
class Task_Prx390_Demo extends Minion_Task
{
    protected function _execute(array $params)
    {
        Service_Prx390_Intake::ensure_dirs();
        $inbox = Service_Prx390_Intake::path('inbox_dir');
        $sources = glob(APPPATH.'modules/prx390_intake/demo/*.pdf') ?: [];
        if ( ! $sources)
        {
            throw new RuntimeException('Демонстрационные PDF не найдены');
        }

        foreach ($sources as $source)
        {
            $destination = $inbox.'/'.basename($source);
            // Не затираем документ с тем же именем; новый файл приватен
            // с момента создания, даже если у процесса широкая umask.
            $mask = umask(0077);
            try
            {
                $out = @fopen($destination, 'xb');
            }
            finally
            {
                umask($mask);
            }
            if ($out === FALSE)
            {
                if (is_file($destination))
                {
                    Minion_CLI::write('Уже существует: '.basename($destination));
                    continue;
                }
                throw new RuntimeException('Не удалось создать демонстрационный файл: '.$destination);
            }

            $identity = fstat($out);
            try
            {
                $in = @fopen($source, 'rb');
                if ($in === FALSE)
                {
                    throw new RuntimeException('Не удалось прочитать демонстрационный PDF: '.$source);
                }
                try
                {
                    $source_stat = fstat($in);
                    $copied = stream_copy_to_stream($in, $out);
                    if ($source_stat === FALSE OR $copied === FALSE OR $copied !== $source_stat['size'] OR ! fflush($out))
                    {
                        throw new RuntimeException('Не удалось скопировать демонстрационный PDF: '.$destination);
                    }
                }
                finally
                {
                    fclose($in);
                }
            }
            catch (Throwable $e)
            {
                fclose($out);
                clearstatcache(TRUE, $destination);
                $current = @lstat($destination);
                if ($identity !== FALSE AND $current !== FALSE
                    AND $current['dev'] === $identity['dev'] AND $current['ino'] === $identity['ino'])
                {
                    @unlink($destination);
                }
                throw $e;
            }
            fclose($out);
            @chmod($destination, 0600);
            Minion_CLI::write('Добавлен: '.basename($destination));
        }

        Minion_CLI::write('Готово: откройте /inbox и нажмите «Сканировать папку».');
    }
}
