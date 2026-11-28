<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Антивирусная проверка файла перед записью в хранилище (clamd, INSTREAM).
 *
 * Вызывается из Service_Prx212_Storage::save() — значит, проверяется всё, что
 * модули кладут через фасад Storage (загрузки PDF, вложения, результаты).
 * Режим и адрес — config/prx212_storage.php `antivirus` (env PRX_CLAMAV_MODE,
 * PRX_CLAMAV_ADDRESS). Заражённый файл не записывается; событие уходит в
 * журнал администратора (Audit, категория security).
 */
class Service_Prx212_Antivirus
{
    public const MODES = ['off', 'soft', 'required'];

    /**
     * Проверить файл. Ничего не возвращает: чисто/проверка выключена/clamd
     * недоступен в режиме soft — тихо проходит.
     *
     * @throws Prx212_Infected_Exception     найдена сигнатура (во всех режимах, кроме off)
     * @throws Prx212_Antivirus_Exception    clamd недоступен в режиме required
     */
    public static function check(string $path, string $filename = '', array $context = []): void
    {
        $config = (array) Kohana::$config->load('prx212_storage')->get('antivirus', []);
        $mode   = in_array($config['mode'] ?? 'off', self::MODES, TRUE) ? $config['mode'] : 'off';

        if ($mode === 'off')
        {
            return;
        }

        try
        {
            $verdict = self::scan_file($path, (string) ($config['address'] ?? ''), (int) ($config['timeout'] ?? 30), (int) ($config['chunk'] ?? 65536));
        }
        catch (Prx212_Antivirus_Exception $e)
        {
            Kohana::$log->add(Log::WARNING, 'prx212 antivirus unavailable (:mode): :e', [':mode' => $mode, ':e' => $e->getMessage()]);

            if ($mode === 'required')
            {
                Audit::log('security', 'file_scan_unavailable', NULL, ['file' => $filename, 'error' => $e->getMessage()] + $context);
                throw $e;
            }
            return;
        }

        if ($verdict['infected'])
        {
            Audit::log('security', 'file_infected', NULL, ['file' => $filename, 'signature' => $verdict['signature']] + $context);
            throw new Prx212_Infected_Exception('Файл отклонён антивирусом: '.$verdict['signature']);
        }
    }

    /**
     * Отправить файл в clamd и разобрать ответ.
     *
     * @return array{infected:bool, signature:?string, raw:string}
     * @throws Prx212_Antivirus_Exception нет соединения, таймаут, ошибка clamd
     */
    public static function scan_file(string $path, string $address, int $timeout = 30, int $chunk = 65536): array
    {
        $in = @fopen($path, 'rb');
        if ($in === FALSE)
        {
            throw new Prx212_Antivirus_Exception('не удалось открыть файл для проверки');
        }

        $sock = @stream_socket_client($address, $errno, $errstr, max(1, $timeout));
        if ($sock === FALSE)
        {
            fclose($in);
            throw new Prx212_Antivirus_Exception('clamd недоступен ('.$address.'): '.$errstr);
        }

        try
        {
            stream_set_timeout($sock, max(1, $timeout));
            self::send($sock, "zINSTREAM\0");

            while ( ! feof($in))
            {
                $data = fread($in, max(1024, $chunk));
                if ($data === FALSE)
                {
                    throw new Prx212_Antivirus_Exception('ошибка чтения файла');
                }
                if ($data === '')
                {
                    break;
                }
                self::send($sock, pack('N', strlen($data)).$data);
            }
            self::send($sock, pack('N', 0));

            $reply = '';
            while ( ! feof($sock))
            {
                $part = fread($sock, 4096);
                if ($part === FALSE OR ($part === '' AND stream_get_meta_data($sock)['timed_out']))
                {
                    throw new Prx212_Antivirus_Exception('clamd не ответил за '.$timeout.' с');
                }
                $reply .= $part;
                if (str_contains($reply, "\0"))
                {
                    break;
                }
            }
        }
        finally
        {
            fclose($in);
            fclose($sock);
        }

        return self::parse(rtrim($reply, "\0\n"));
    }

    /**
     * Ответ clamd → вердикт. «stream: OK», «stream: <Сигнатура> FOUND»,
     * «INSTREAM size limit exceeded. ERROR» и прочие ERROR — исключение.
     *
     * @return array{infected:bool, signature:?string, raw:string}
     */
    public static function parse(string $reply): array
    {
        if (preg_match('/:\s*OK\z/', $reply))
        {
            return ['infected' => FALSE, 'signature' => NULL, 'raw' => $reply];
        }
        if (preg_match('/:\s*(.+?)\s+FOUND\z/', $reply, $m))
        {
            return ['infected' => TRUE, 'signature' => mb_substr($m[1], 0, 200), 'raw' => $reply];
        }

        throw new Prx212_Antivirus_Exception('clamd: '.($reply === '' ? 'пустой ответ' : mb_substr($reply, 0, 200)));
    }

    private static function send($sock, string $bytes): void
    {
        $len = strlen($bytes);
        for ($done = 0; $done < $len; )
        {
            $n = @fwrite($sock, substr($bytes, $done));
            if ($n === FALSE OR $n === 0)
            {
                throw new Prx212_Antivirus_Exception('clamd оборвал соединение');
            }
            $done += $n;
        }
    }
}
